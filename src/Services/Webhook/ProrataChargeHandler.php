<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

use Carbon\Carbon;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\PaymentSucceeded;
use GraystackIT\MollieBilling\Events\PlanChangeFailed;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Notifications\PlanChangeFailedNotification;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\PlanChangeIntent;
use GraystackIT\MollieBilling\Services\Wallet\WalletPlanChangeAdjuster;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ProrataLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class ProrataChargeHandler
{
    public function __construct(
        protected readonly WebhookSupport $support,
        protected readonly InvoiceService $invoiceService,
        protected readonly MollieSubscriptionPatcher $patcher,
        protected readonly WalletPlanChangeAdjuster $walletAdjuster,
        protected readonly CouponService $couponService,
    ) {
    }

    /**
     * Phase-2 trigger for the plan-change charge flow (multi-VAT collected charges).
     *
     * 1. Persist the charge invoice via InvoiceService::createInvoice.
     * 2. Rebalance the wallets — against the OLD period boundaries, i.e. before
     *    the local plan/period switch below.
     * 3. Apply the plan change locally.
     * 4. Redeem the coupons Phase 1 deferred (this also writes the
     *    `active_recurring_coupon` marker the PATCH has to see).
     * 5. PATCH the Mollie subscription via MollieSubscriptionPatcher::updateForIntent.
     * 6. Execute scheduled refunds (from the pending state) via InvoiceService::createRefund.
     * 7. Clear the pending state.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function paid(object $payment, Billable $billable, array $metadata): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        if ($this->support->invoiceAlreadyExistsForPayment($payment)) {
            Log::info('Webhook re-delivery: invoice exists for prorata charge, skipping', [
                'payment_id' => $payment->id ?? null,
                'billable_id' => $billable->getKey(),
            ]);
            return;
        }

        $pending = $billable->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null;
        if (empty($pending)) {
            $this->reportOrphanedCharge($payment, $billable);
            return;
        }

        // Captured up front: the plan-change forceFill below drops
        // `pending_plan_change` from the meta, and the coupon codes to redeem
        // (Phase 1 deliberately deferred them) live in there.
        $pendingPlanChange = $billable->getBillingSubscriptionMeta()['pending_plan_change'] ?? null;

        $intentData = is_array($pending['intent'] ?? null) ? $pending['intent'] : null;
        $oldPlan = $intentData !== null ? (string) ($intentData['current_plan'] ?? '') : '';
        $oldInterval = $intentData !== null ? (string) ($intentData['current_interval'] ?? '') : '';
        $newPlanCode = $intentData !== null
            ? (string) ($intentData['new_plan'] ?? $billable->getBillingSubscriptionPlanCode())
            : (string) ($billable->getBillingSubscriptionPlanCode() ?? '');
        $newIntervalCode = $intentData !== null
            ? (string) ($intentData['new_interval'] ?? $billable->getBillingSubscriptionInterval())
            : (string) ($billable->getBillingSubscriptionInterval() ?? '');
        $startsNewPeriod = $oldPlan !== '' && ($oldPlan !== $newPlanCode || $oldInterval !== $newIntervalCode);

        $paidAt = $payment->paidAt ?? null;
        $newPeriodStart = $paidAt ? Carbon::parse((string) $paidAt)->setTimezone('UTC') : BillingTime::nowUtc();
        $newPeriodEnd = $newIntervalCode === 'yearly'
            ? $newPeriodStart->copy()->addYear()
            : $newPeriodStart->copy()->addMonth();

        $invoicePeriodStart = $startsNewPeriod ? $newPeriodStart : $billable->getBillingPeriodStartsAt();
        $invoicePeriodEnd = $startsNewPeriod ? $newPeriodEnd : $billable->nextBillingDate();

        $lineItems = (array) ($pending['charge_lines'] ?? $metadata['line_items'] ?? []);

        if ($startsNewPeriod && $invoicePeriodStart !== null && $invoicePeriodEnd !== null) {
            $startIso = $invoicePeriodStart->toIso8601String();
            $endIso = $invoicePeriodEnd->toIso8601String();
            $lineItems = array_map(static function (array $line) use ($startIso, $endIso): array {
                $line['period_start'] = $startIso;
                $line['period_end'] = $endIso;
                return $line;
            }, $lineItems);
        }

        try {
            $chargeInvoice = $this->invoiceService->createInvoice(
                billable: $billable,
                kind: InvoiceKind::Subscription,
                molliePaymentId: (string) $payment->id,
                mollieSubscriptionId: $payment->subscriptionId ?? null,
                lineItems: $lineItems,
                periodStart: $invoicePeriodStart,
                periodEnd: $invoicePeriodEnd,
                // Mollie's own record of what it collected. The charge was created
                // earlier, so today's BILLING_CURRENCY may no longer match it.
                currency: $payment->amount->currency ?? null,
            );
            event(new PaymentSucceeded($billable, $chargeInvoice));
            $this->support->notifyInvoiceAvailable($billable, $chargeInvoice);
        } catch (\Throwable $e) {
            Log::error('Failed to persist prorata_charge invoice', [
                'billable' => $billable->getKey(),
                'payment_id' => (string) $payment->id,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        // The prorated old-plan entitlement is measured over the OLD period, so the
        // boundaries are captured here and passed explicitly. Running the adjuster
        // after the switch below (or letting it re-derive them) made the elapsed
        // fraction ~0, the prorated old quota 0 and therefore every unit consumed
        // in the old period "excess" — which the preview, validateWalletUsage and
        // the synchronous path never showed, and which can even trigger an
        // unannounced overage charge.
        $billable->refresh();
        $oldPeriodStart = $billable->getBillingPeriodStartsAt();
        $oldPeriodEnd = $billable->nextBillingDate();

        if ($startsNewPeriod) {
            try {
                $this->walletAdjuster->adjust(
                    $billable,
                    $oldPlan,
                    $oldInterval,
                    $newPlanCode,
                    $newIntervalCode,
                    $oldPeriodStart,
                    $oldPeriodEnd,
                );
            } catch (\Throwable $e) {
                Log::warning('Wallet adjust during prorata charge phase 2 failed', [
                    'billable' => $billable->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $refundLinesData = (array) ($pending['refund_lines'] ?? []);
        if (! empty($refundLinesData)) {
            $refundLines = array_map(
                fn (array $data) => ProrataLine::fromArray($data),
                $refundLinesData,
            );
            try {
                $this->invoiceService->createRefund($billable, $refundLines, 'Plan change refund');
            } catch (\Throwable $e) {
                Log::error('Failed to create refund invoice in Phase 2', [
                    'billable' => $billable->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $billable->refresh();
        $wasPastDue = $billable->getBillingSubscriptionStatus() === SubscriptionStatus::PastDue;
        $wasTrial = $billable->getBillingSubscriptionStatus() === SubscriptionStatus::Trial;
        if ($intentData !== null) {
            $meta = $billable->getBillingSubscriptionMeta();

            $derivedFromCharge = isset($chargeInvoice) ? $chargeInvoice->deriveSeatCount() : null;
            $meta['seat_count'] = $derivedFromCharge ?? (int) ($intentData['new_seats'] ?? $billable->getBillingSeatCount());

            unset($meta['pending_plan_change'], $meta['prorata_pending_payment_id']);

            if ($wasPastDue) {
                unset($meta['payment_failure'], $meta['past_due_since']);
            }

            $billable->forceFill([
                'subscription_plan_code' => $newPlanCode,
                'subscription_interval' => $newIntervalCode,
                'active_addon_codes' => array_keys((array) ($intentData['new_addons'] ?? [])),
                'subscription_meta' => $meta,
                ...($startsNewPeriod ? ['subscription_period_starts_at' => $newPeriodStart] : []),
                ...($wasPastDue || $wasTrial ? [
                    'subscription_status' => SubscriptionStatus::Active,
                    'subscription_ends_at' => null,
                ] : []),
                ...($wasTrial ? ['trial_ends_at' => null] : []),
            ])->save();
        }

        // Redeem AFTER the local switch (the recurring marker's lifetime is
        // derived from the billable's interval) and BEFORE the PATCH below, which
        // reads the marker to keep the promised discount on the new amount.
        $billable->refresh();
        $this->redeemPendingCoupons(
            $billable,
            $pendingPlanChange,
            (array) ($pending['charge_lines'] ?? []),
            $newPlanCode,
            $newIntervalCode,
            $chargeInvoice,
        );

        try {
            $intent = PlanChangeIntent::fromArray($pending['intent']);
            $this->patcher->updateForIntent($billable, $intent);
        } catch (\Throwable $e) {
            Log::warning('Mollie subscription PATCH failed in Phase 2 — will be inconsistent until next recurring run', [
                'billable' => $billable->getKey(),
                'error' => $e->getMessage(),
            ]);
        }

        $billable->refresh();
        $meta = $billable->getBillingSubscriptionMeta();
        unset(
            $meta['pending_prorata_change'],
            $meta['pending_plan_change'],
            $meta['prorata_pending_payment_id'],
        );
        $billable->forceFill(['subscription_meta' => $meta])->save();
    }

    /**
     * Redeem the coupons Phase 1 (`UpdateSubscription::update()`) deliberately
     * left unredeemed: the deferred charge only knows what it will bill once this
     * webhook confirms it. Without this there is no CouponRedemption row (the
     * coupon stays reusable forever and per-billable limits go unenforced) and —
     * for a Recurring coupon — no `active_recurring_coupon` marker, so the PATCH
     * right after would set the FULL price for every future renewal even though
     * the customer was promised the discount.
     *
     * Coupons are resolved by code only, deliberately not re-validated: the
     * mutable rules (expired, globally_exhausted, recurring_already_active …) can
     * flip between Phase 1 and this webhook, and Mollie has already collected the
     * discounted amount. Mirrors UpdateSubscription::applyPendingPlanChange().
     *
     * @param  array<string, mixed>|null  $pendingPlanChange
     * @param  array<int, array<string, mixed>>  $chargeLines
     */
    protected function redeemPendingCoupons(
        Billable $billable,
        ?array $pendingPlanChange,
        array $chargeLines,
        string $planCode,
        string $interval,
        ?\GraystackIT\MollieBilling\Models\BillingInvoice $chargeInvoice,
    ): void {
        // Discount actually billed per coupon, from the Phase-1 charge snapshot.
        $billedDiscountByCode = [];
        foreach ($chargeLines as $line) {
            if (($line['kind'] ?? null) !== 'coupon') {
                continue;
            }
            $code = strtoupper((string) ($line['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $billedDiscountByCode[$code] = abs((int) ($line['amount_net'] ?? 0));
        }

        $codes = (array) ($pendingPlanChange['coupon_codes'] ?? []);
        if ($codes === [] && ! empty($pendingPlanChange['coupon_code'])) {
            $codes = [(string) $pendingPlanChange['coupon_code']];
        }
        if ($codes === [] && $billedDiscountByCode !== []) {
            $codes = array_keys($billedDiscountByCode);
        }

        if ($codes === []) {
            return;
        }

        $orderAmountNet = (int) ($pendingPlanChange['new_net'] ?? 0);

        foreach ($codes as $code) {
            $codeUpper = strtoupper((string) $code);
            $coupon = Coupon::query()->whereRaw('UPPER(code) = ?', [$codeUpper])->first();

            if ($coupon === null) {
                Log::warning('Coupon redemption skipped in prorata phase 2 — coupon no longer exists', [
                    'billable' => $billable instanceof Model ? $billable->getKey() : null,
                    'coupon' => $code,
                ]);
                continue;
            }

            $context = [
                'planCode' => $planCode,
                'interval' => $interval,
                'orderAmountNet' => $orderAmountNet,
                'discount_amount_net' => $billedDiscountByCode[$codeUpper] ?? 0,
            ];
            if ($chargeInvoice !== null) {
                $context['invoice_id'] = (int) $chargeInvoice->id;
            }

            try {
                $this->couponService->redeem($coupon, $billable, $context);
            } catch (\Throwable $e) {
                // The customer was already charged the discounted amount, so the
                // missing audit row needs a human — a hard limit reached between
                // Phase 1 and here cannot be resolved automatically.
                Log::error('Coupon redemption failed in prorata phase 2 after the charge settled', [
                    'billable' => $billable instanceof Model ? $billable->getKey() : null,
                    'coupon' => $code,
                    'discount_billed_net' => $billedDiscountByCode[$codeUpper] ?? 0,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * The charge settled but the local pending state is gone —
     * `UpdateSubscription::cancelPendingPlanChange()` clears it even when the
     * Mollie payment could not be cancelled (not cancelable, or the lookup
     * threw). We must not apply a plan change the user cancelled, but the money
     * DID clear, so this cannot be dropped silently: without surfacing it there
     * would be no invoice, no refund and no notification, and the reservation is
     * finalized so no re-delivery can recover it.
     */
    protected function reportOrphanedCharge(object $payment, Billable $billable): void
    {
        $paymentId = (string) ($payment->id ?? '');
        $amountCents = $this->support->amountFromMolliePayment($payment);

        // UpdateSubscription::cancelPendingPlanChange() records the payment it could
        // not cancel under this key, together with the plan change and charge lines it
        // was for. Reading it here is what turns "some money arrived for nothing" into
        // an actionable report — and consuming it means the marker does not linger as a
        // permanent orphan record once the admins have been told.
        $orphan = null;

        if ($billable instanceof Model) {
            $meta = $billable->getBillingSubscriptionMeta();
            $candidate = $meta['orphaned_prorata_payment'] ?? null;

            if (is_array($candidate) && (string) ($candidate['payment_id'] ?? '') === $paymentId) {
                $orphan = $candidate;

                unset($meta['orphaned_prorata_payment']);
                $billable->forceFill(['subscription_meta' => $meta])->save();
            }
        }

        Log::error('Paid prorata charge without pending plan change — money collected for a cancelled plan change', [
            'payment_id' => $paymentId,
            'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
            'amount_cents' => $amountCents,
            'cancel_reason' => $orphan['reason'] ?? null,
            'cancelled_at' => $orphan['cancelled_at'] ?? null,
        ]);

        $admins = MollieBilling::notifyAdmin();
        $admins = is_array($admins) ? $admins : iterator_to_array($admins);

        if ($admins === []) {
            return;
        }

        Notification::send(
            $admins,
            MollieBilling::resolveNotification(
                AdminPlanChangeFailedNotification::class,
                'Prorata charge was paid after the pending plan change had been cancelled — the amount needs to be refunded manually',
                [
                    'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
                    'payment_id' => $paymentId,
                    'amount_cents' => $amountCents,
                    'currency' => isset($payment->amount->currency) ? (string) $payment->amount->currency : null,
                    'cancel_reason' => $orphan['reason'] ?? null,
                    'cancelled_at' => $orphan['cancelled_at'] ?? null,
                    'intended_plan_change' => $orphan['pending_plan_change'] ?? null,
                    'charge_lines' => $orphan['prorata_charge_lines'] ?? null,
                ],
            ),
        );
    }

    /**
     * Charge webhook failed: clear the pending state. No PATCH rollback is needed because the PATCH has not run yet.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function failed(object $payment, Billable $billable, array $metadata): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        $meta = $billable->getBillingSubscriptionMeta();
        $pending = $meta['pending_prorata_change'] ?? null;

        // If the local pending state is already gone (user cancelled the pending
        // change before Mollie reported the final state), the failed webhook is
        // a no-op — don't set plan_change_failed_at, don't notify, don't fire
        // PlanChangeFailed. The user already decided this charge should not
        // apply; we shouldn't surface a "plan change failed" toast for it.
        if ($pending === null) {
            return;
        }

        unset(
            $meta['pending_prorata_change'],
            $meta['pending_plan_change'],
            $meta['prorata_pending_payment_id'],
        );

        $reason = (string) ($payment->details?->failureReason ?? $payment->status ?? 'unknown');
        $meta['plan_change_failed_at'] = BillingTime::nowUtc()->toIso8601String();
        $meta['plan_change_failed_reason'] = $reason;
        $billable->forceFill(['subscription_meta' => $meta])->save();

        event(new PlanChangeFailed($billable, $pending, (string) $payment->id, $reason));

        $recipients = MollieBilling::notifyBillingAdmins($billable);
        if (! empty($recipients)) {
            Notification::send($recipients, MollieBilling::resolveNotification(PlanChangeFailedNotification::class, $billable, (string) $payment->id));
        }
    }
}
