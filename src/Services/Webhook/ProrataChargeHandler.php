<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

use Carbon\Carbon;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\PaymentSucceeded;
use GraystackIT\MollieBilling\Events\PlanChangeFailed;
use GraystackIT\MollieBilling\Jobs\RetrySubscriptionPatchJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
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
use GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;
use GraystackIT\MollieBilling\Support\ProrataLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class ProrataChargeHandler
{
    use MutatesBillingMeta;

    /**
     * The steps of {@see paid()} a re-delivery must not repeat, in the order they run.
     * Recorded under `pending_prorata_change.progress.done` as each one completes, so a
     * run that died is resumed after the last one that finished. A run that dies *inside*
     * a step repeats that step; each is as short as the work it guards allows.
     */
    protected const STEP_INVOICED = 'invoiced';

    protected const STEP_DOCUMENT = 'document';

    protected const STEP_DOCUMENT_ANNOUNCED = 'document_announced';

    protected const STEP_ANNOUNCED = 'announced';

    protected const STEP_TRIAL_OVERAGE = 'trial_overage';

    protected const STEP_WALLET = 'wallet';

    protected const STEP_WALLET_OVERAGE = 'wallet_overage';

    protected const STEP_SPANNED_RENEWAL = 'spanned_renewal';

    protected const STEP_REFUNDS = 'refunds';

    protected const STEP_SWITCHED = 'switched';

    protected const STEP_EVENTS = 'events';

    protected const STEP_COUPONS = 'coupons';

    protected const STEP_PATCHED = 'patched';

    /** Why a run that holds its invoice is not carried on — {@see reportInterruptedRun()}. */
    protected const INTERRUPTED_RENEWAL_BOOKED = 'renewal_booked';

    protected const INTERRUPTED_NO_PROGRESS = 'no_progress';

    protected const INTERRUPTED_SUBSCRIPTION_ENDED = 'subscription_ended';

    protected const INTERRUPTED_SHORTFALL_UNSETTLED = 'shortfall_unsettled';

    protected const INTERRUPTED_REFUNDS_UNKNOWN = 'refunds_unknown';

    protected const INTERRUPTED_OVERAGE_UNKNOWN = 'overage_unknown';

    /**
     * Subscription states a deferred prorata change may still be applied to.
     *
     * Trial and PastDue are included because Phase 2 promotes both to Active — a
     * mid-trial change converts the trial, and a past-due reset is what the charge
     * was for. Cancelled, Expired and a `None` source are not: the subscription the
     * change was meant to modify no longer runs.
     */
    protected const APPLICABLE_STATES = [
        SubscriptionStatus::Active,
        SubscriptionStatus::Trial,
        SubscriptionStatus::PastDue,
    ];

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
     * A re-delivery for a payment whose invoice exists while its change still waits
     * resumes the run after the last completed step — see the `STEP_*` constants.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function paid(object $payment, Billable $billable, array $metadata): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        // The separate payment for an undercollected period is not a plan change.
        if (\GraystackIT\MollieBilling\Services\Billing\UndercollectionCharge::owns($metadata)) {
            app(\GraystackIT\MollieBilling\Services\Billing\UndercollectionCharge::class)->paid($payment, $billable, $metadata);

            return;
        }

        // A payment this handler already returned as unwanted: pick the refund up again if
        // it is not through, announce nothing twice.
        if (app(UnwantedPaymentRefund::class)->resume($payment, $billable)) {
            return;
        }

        $paymentId = (string) ($payment->id ?? '');

        // The invoice is the first thing this handler writes, and "it exists" used to be
        // read as "done". It is not: a run that dies after it — a fatal, PHP's time limit
        // (hit once rendering the e-invoice) — leaves the change itself unapplied, and
        // every re-delivery then skipped it. Paid for, never carried out. While the change
        // still waits for this payment, a re-delivery picks the run up where it stopped;
        // the steps that are done are recorded under `pending_prorata_change.progress`,
        // because several of them move money and must not run twice.
        $chargeInvoice = $this->chargeInvoiceFor($paymentId);

        if ($chargeInvoice !== null && ! $this->resumable($billable, $paymentId, $chargeInvoice)) {
            $waiting = $billable->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null;

            // The change still waits for this payment, but the run that wrote the invoice
            // recorded nothing to resume from — it ran before the steps were recorded. Run
            // again from the start it would credit the wallets and pay the refund lines out
            // a second time; skipped in silence, the paid change is lost. Told instead.
            if (is_array($waiting) && $this->paymentOwnsPending($payment, $waiting)
                && $chargeInvoice->invoice_kind === InvoiceKind::Subscription
                && $this->mayBeThisPaymentsChange($billable, $waiting, $paymentId, $chargeInvoice)
            ) {
                $this->reportInterruptedRun(
                    $billable,
                    $paymentId,
                    self::INTERRUPTED_NO_PROGRESS,
                    [],
                    is_array($waiting['progress'] ?? null) ? $waiting['progress'] : [],
                    $billable->getBillingSubscriptionMeta()['pending_plan_change'] ?? null,
                );

                return;
            }

            Log::info('Webhook re-delivery: invoice exists for prorata charge, skipping', [
                'payment_id' => $payment->id ?? null,
                'billable_id' => $billable->getKey(),
            ]);
            return;
        }

        if ($chargeInvoice === null) {
            // A payment whose creation went unanswered (a timeout, a failed write): nothing
            // points at it yet, but the note written before Mollie was asked names its token.
            // Taken over here, it carries its change out like any other — instead of being
            // returned and charged a second time by the next attempt.
            $this->adoptChargeInCreation($payment, $billable, $metadata);
        }

        $pending = $billable->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null;
        if (empty($pending) || ! $this->paymentOwnsPending($payment, $pending)) {
            $this->reportOrphanedCharge($payment, $billable);
            return;
        }

        // A waiting change that names no payment is accepted as this one's — and from here
        // on it names it. Everything that records or resumes the run keys on the payment id;
        // left empty, no step was recorded and a run that died could never be resumed.
        if ($chargeInvoice === null && (string) ($pending['charge_payment_id'] ?? '') === '' && $paymentId !== '') {
            $this->mutateBillingMeta($billable, static function (array $meta) use ($paymentId): ?array {
                if (! is_array($meta['pending_prorata_change'] ?? null)
                    || (string) ($meta['pending_prorata_change']['charge_payment_id'] ?? '') !== ''
                ) {
                    return null;
                }

                $meta['pending_prorata_change']['charge_payment_id'] = $paymentId;

                return $meta;
            });

            $pending = $billable->getBillingSubscriptionMeta()['pending_prorata_change'];
        }

        // A run that died before its invoice did nothing that counts: it starts over and
        // reads everything afresh, including what such a run may have noted down.
        $progress = $chargeInvoice !== null && is_array($pending['progress'] ?? null) ? $pending['progress'] : [];
        $done = array_values(array_map('strval', (array) ($progress['done'] ?? [])));

        // The invoice is in the books whether or not its step was recorded — the run may
        // have died between the two. Recorded now, so every report lists it.
        if ($chargeInvoice !== null && ! in_array(self::STEP_INVOICED, $done, true)) {
            $this->markStep($billable, $paymentId, $done, self::STEP_INVOICED);
        }

        if ($chargeInvoice !== null) {
            Log::warning('Resuming a prorata charge a previous delivery left unfinished', [
                'payment_id' => $paymentId,
                'billable_id' => $billable->getKey(),
                'completed_steps' => $done,
            ]);
        }

        // A change may not be applied to a subscription that no longer runs.
        // `CancelSubscription` clears `scheduled_change` but not the deferred-prorata
        // pendings, so a SEPA charge started before a cancellation settles days later —
        // after the grace period ended and the expiry pass wiped the plan fields. This
        // handler then force-filled plan, interval and addons back onto an Expired row,
        // resurrecting exactly the plan-scoped fields that wipe exists to remove, so
        // `@planFeature` and the `billing.feature` middleware granted access again to a
        // subscription that had ended. `ScheduleSubscriptionChange::apply()` states the
        // rule this path was missing: a change for a subscription that no longer runs
        // must be dropped, not applied.
        //
        // The money is real, so it is booked and reported rather than ignored — an
        // operator has to refund it.
        //
        // Once the change is in force, the status is its result and no reason to stop.
        if (! in_array(self::STEP_SWITCHED, $done, true)
            && ! in_array($billable->getBillingSubscriptionStatus(), self::APPLICABLE_STATES, true)
        ) {
            if ($chargeInvoice !== null) {
                // Not returned in full the way reportUnapplicableCharge() does it: the steps
                // this run finished may already have paid refund lines out or credited the
                // wallets, and a full refund on top would return more than was taken. What
                // is owed depends on which of them ran, so the operators are told — now,
                // with the completed steps — instead of a day later by the clean-up.
                $this->reportInterruptedRun(
                    $billable,
                    $paymentId,
                    self::INTERRUPTED_SUBSCRIPTION_ENDED,
                    $done,
                    $progress,
                    is_array($progress['pending_plan_change'] ?? null) ? $progress['pending_plan_change'] : null,
                );

                return;
            }

            $this->reportUnapplicableCharge($payment, $billable, $pending);

            return;
        }

        // Captured up front: the plan-change forceFill below drops
        // `pending_plan_change` from the meta, and the coupon codes to redeem
        // (Phase 1 deliberately deferred them) live in there.
        // On a resumed run it comes from the progress: the switch removes it.
        $pendingPlanChange = array_key_exists('pending_plan_change', $progress)
            ? (is_array($progress['pending_plan_change']) ? $progress['pending_plan_change'] : null)
            : ($billable->getBillingSubscriptionMeta()['pending_plan_change'] ?? null);

        $intentData = is_array($pending['intent'] ?? null) ? $pending['intent'] : null;
        $oldPlan = $intentData !== null ? (string) ($intentData['current_plan'] ?? '') : '';
        $oldInterval = $intentData !== null ? (string) ($intentData['current_interval'] ?? '') : '';
        $newPlanCode = $intentData !== null
            ? (string) ($intentData['new_plan'] ?? $billable->getBillingSubscriptionPlanCode())
            : (string) ($billable->getBillingSubscriptionPlanCode() ?? '');
        $newIntervalCode = $intentData !== null
            ? (string) ($intentData['new_interval'] ?? $billable->getBillingSubscriptionInterval())
            : (string) ($billable->getBillingSubscriptionInterval() ?? '');
        // A same-interval plan change does NOT open a new billing window. The composer
        // prices it with the remaining-window factor — `new × daysLeft / periodDays`,
        // covering only up to the existing period end — and the patcher leaves Mollie's
        // cadence alone unless the interval changed. Re-anchoring the period here
        // therefore claimed the customer had paid for a full period from `paidAt`:
        // `nextBillingDate()` reported a date Mollie does not charge on, the overage
        // pre-pass missed the real renewal, a graceful cancel granted grace it was not
        // paid for, and a seat added afterwards was prorated against the phantom window
        // while the real renewal billed the same days again.
        //
        // Interval changes genuinely start a new window (a yearly period cannot
        // continue a monthly one), and so does the past-due reset, where the patcher
        // moves Mollie's schedule to `now + 1 interval`.
        $forcesResetStartDate = (bool) ($intentData['force_reset_start_date'] ?? false);

        // A converted trial starts a new window too, and it has to be decided HERE — the
        // period anchor is written from `$startsNewPeriod` further down, while the trial
        // override that moves Mollie's cadence sits after it. Read from the stored intent
        // alone, `force_reset_start_date` is false for a trial (it is set for the past-due
        // reset only), so a same-interval switch mid-trial moved Mollie's first charge a
        // full interval out while the local anchor stayed at the trial start. From then on
        // `nextBillingDate()` named a day Mollie does not charge on, and everything keyed
        // off it was wrong with it: the overage pre-pass fired against the wrong day, a
        // graceful cancel granted grace nobody paid for, and a later seat change prorated
        // against a window that had already elapsed.
        // A resumed run reads it from the progress: after the switch the trial is over.
        $wasTrial = array_key_exists('was_trial', $progress)
            ? (bool) $progress['was_trial']
            : $billable->getBillingSubscriptionStatus() === SubscriptionStatus::Trial;

        $startsNewPeriod = ($oldInterval !== '' && $oldInterval !== $newIntervalCode)
            || $forcesResetStartDate
            || $wasTrial;

        // Kept separate from the period question: wallet quotas differ per (plan,
        // interval), so the adjuster has to run for a plain plan change too — even
        // though that change keeps the period boundary where it is.
        $planOrIntervalChanged = $oldPlan !== '' && ($oldPlan !== $newPlanCode || $oldInterval !== $newIntervalCode);

        $paidAt = $payment->paidAt ?? null;
        $newPeriodStart = $paidAt ? Carbon::parse((string) $paidAt)->setTimezone('UTC') : BillingTime::nowUtc();
        $newPeriodEnd = $newIntervalCode === 'yearly'
            ? BillingTime::addInterval($newPeriodStart, 'yearly')
            : BillingTime::addInterval($newPeriodStart, 'monthly');

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

        if ($chargeInvoice !== null && ! in_array(self::STEP_SWITCHED, $done, true)
            && $this->periodMovedForRun($billable, $progress)
        ) {
            $this->reportInterruptedRun($billable, $paymentId, self::INTERRUPTED_RENEWAL_BOOKED, $done, $progress, $pendingPlanChange);

            return;
        }

        if ($chargeInvoice === null) {
            // Noted before the invoice, so that every run which finds the invoice can rely
            // on it: what decides the run and cannot be read off the billable once the
            // change is in force, and the period it was decided in.
            $progress = [
                'was_trial' => $wasTrial,
                'pending_plan_change' => $pendingPlanChange,
                'anchor' => $billable->getBillingPeriodStartsAt()?->toIso8601String(),
                // CleanupStalePendingProrataChangeJob leaves a run alone while Mollie may
                // still re-deliver it — counted from here, not from the change's creation.
                'started_at' => BillingTime::nowUtc()->toIso8601String(),
                'done' => [],
            ];
            // This run reads it too: the anchor check before the switch compares against it.
            $this->rememberRunContext($billable, $paymentId, $progress);

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
                    // The document is a step of its own below, so that a run which dies
                    // rendering it is resumed with the invoice already in the books.
                    deferDocument: true,
                );
            } catch (\Throwable $e) {
                Log::error('Failed to persist prorata_charge invoice', [
                    'billable' => $billable->getKey(),
                    'payment_id' => (string) $payment->id,
                    'error' => $e->getMessage(),
                ]);

                // Rethrown: the webhook answers 500 and Mollie delivers again, and the next
                // run starts over. Answered 200, nothing re-delivered the payment and the
                // paid change waited for the clean-up to drop it.
                throw $e;
            }

            $this->markStep($billable, $paymentId, $done, self::STEP_INVOICED);
        }

        // The document and its announcement are two steps: a run that dies between storing
        // the PDF and dispatching InvoiceCreated is resumed with the PDF there, and the
        // app's listeners (mailing, archiving the invoice) still hear of it.
        if (! in_array(self::STEP_DOCUMENT, $done, true)) {
            if (! $chargeInvoice->hasPdf()) {
                $this->support->safely(
                    // With the billable this run holds — resolved scope-free, as the
                    // webhook has no tenant context (see storeInvoiceDocument()).
                    fn () => $this->invoiceService->storeInvoiceDocument($chargeInvoice, $billable),
                    'prorata_charge.invoice_document',
                    $billable,
                    ['payment_id' => $paymentId],
                );
                $chargeInvoice->refresh();
            }

            // Recorded only with a document: a render that failed is tried again by the
            // next delivery, should the run need one. It does not hold the change up — the
            // download controller renders a missing document on request.
            if ($chargeInvoice->hasPdf()) {
                $this->markStep($billable, $paymentId, $done, self::STEP_DOCUMENT);
            }
        }

        if (! in_array(self::STEP_DOCUMENT_ANNOUNCED, $done, true)) {
            $this->support->safely(
                fn () => event(new \GraystackIT\MollieBilling\Events\InvoiceCreated($billable, $chargeInvoice)),
                'prorata_charge.invoice_created_event',
                $billable,
                ['payment_id' => $paymentId],
            );

            $this->markStep($billable, $paymentId, $done, self::STEP_DOCUMENT_ANNOUNCED);
        }

        // Announced outside the try, and each one isolated.
        //
        // Inside it, a throwing `PaymentSucceeded` listener — events are the documented
        // extension seam, so an app listener is the likeliest thrower there is — logged
        // "Failed to persist prorata_charge invoice" (which had in fact succeeded) and
        // returned before the plan change was applied. Mollie's re-delivery could not
        // repair it either: `paid()` opens with the invoice guard and no-ops. The customer
        // had paid the prorata charge for a plan switch that then simply never happened.
        if (! in_array(self::STEP_ANNOUNCED, $done, true)) {
            $this->support->safely(
                fn () => event(new PaymentSucceeded($billable, $chargeInvoice)),
                'prorata_charge.payment_succeeded_event',
                $billable,
                ['payment_id' => (string) $payment->id],
            );

            $this->support->safely(
                fn () => $this->support->notifyInvoiceAvailable($billable, $chargeInvoice),
                'prorata_charge.invoice_notification',
                $billable,
                ['payment_id' => (string) $payment->id],
            );

            $this->markStep($billable, $paymentId, $done, self::STEP_ANNOUNCED);
        }

        // The first step that moves money. A renewal booked while the invoice was rendered
        // or announced moved the period this run was decided in; nothing has moved yet, so
        // the run stops here rather than credit or charge for a period that has ended.
        $billable->refresh();
        if (! in_array(self::STEP_SWITCHED, $done, true) && $this->periodMovedForRun($billable, $progress)) {
            $this->reportInterruptedRun($billable, $paymentId, self::INTERRUPTED_RENEWAL_BOOKED, $done, $progress, $pendingPlanChange);

            return;
        }

        if ($wasTrial && ! in_array(self::STEP_WALLET, $done, true) && ! in_array(self::STEP_TRIAL_OVERAGE, $done, true)) {
            // The trial's own overage is collected first, exactly as the ordinary renewal
            // does it — and while the billable is still on the plan it was consumed on, so
            // it is priced there. Carried uncharged instead, it was paid out of the new
            // plan's quota or billed later at the new plan's price. A Mollie call, so a step
            // of its own and outside the transaction below.
            $billable->refresh();
            $this->support->collectOutstandingOverage($billable);
            $this->markStep($billable, $paymentId, $done, self::STEP_TRIAL_OVERAGE);
        }

        // The wallet credits and the record that they are done commit together: a run that
        // dies after a credit but before its record cannot add the same quota again on the
        // next delivery — a rollover wallet adds on top of its balance. What the rebalance
        // leaves to charge is a Mollie call; it is noted in the same commit and charged as
        // the next step, outside it (the shape of UpdateSubscription's phase 3).
        if (! in_array(self::STEP_WALLET, $done, true)) {
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

            DB::transaction(function () use (
                $billable, $paymentId, &$done, &$progress, $wasTrial, $planOrIntervalChanged,
                $oldPlan, $oldInterval, $newPlanCode, $newIntervalCode, $oldPeriodStart, $oldPeriodEnd,
            ): void {
                $deferredOverage = [];

                if ($wasTrial) {
                    // The trial ends here and a full period has just been paid for, so the
                    // wallets get that period's quota — the same refill the first ordinary
                    // renewal does.
                    //
                    // Not the plan-change adjuster: its premise is a period that was paid for
                    // and partly consumed, and a trial's wallet holds only the prorated share
                    // it was granted. It also only ran when plan or interval moved, so a seat
                    // change mid-trial converted the trial, started a new period, charged for
                    // it — and left the wallet on the trial's fraction for the whole month
                    // that followed.
                    $this->support->creditPeriodQuota($billable, $newPlanCode, $newIntervalCode, 'subscription_trial_conversion');
                } elseif ($planOrIntervalChanged) {
                    // Not caught: a rebalance that throws part-way holds what it could not
                    // resolve only in memory. Caught and recorded as done, the wallets it had
                    // reset stayed reset and that overage was never charged. Thrown, the
                    // transaction takes the partial rebalance back with the record, and the
                    // next delivery tries again (or, past its window, the operators are told).
                    $deferredOverage = $this->walletAdjuster->adjust(
                        $billable,
                        $oldPlan,
                        $oldInterval,
                        $newPlanCode,
                        $newIntervalCode,
                        $oldPeriodStart,
                        $oldPeriodEnd,
                        deferOverageCharge: true,
                    );
                }

                $this->markStep($billable, $paymentId, $done, self::STEP_WALLET, ['deferred_overage' => $deferredOverage]);
                $progress['deferred_overage'] = $deferredOverage;
            });
        }

        if (! in_array(self::STEP_WALLET_OVERAGE, $done, true)) {
            $deferred = array_values((array) ($progress['deferred_overage'] ?? []));

            // An earlier attempt asked Mollie for this overage and died before recording it.
            // Asked again, the units are either restored onto the wallet while that payment
            // is in flight (and charged again at the period end) or charged a second time
            // once it has settled. Not asked again — the operators look it up.
            if ($deferred !== [] && ! empty($progress['wallet_overage_started_at'])) {
                $this->reportInterruptedRun($billable, $paymentId, self::INTERRUPTED_OVERAGE_UNKNOWN, $done, $progress, $pendingPlanChange);

                return;
            }

            if ($deferred !== []) {
                $this->noteProgress($billable, $paymentId, ['wallet_overage_started_at' => BillingTime::nowUtc()->toIso8601String()]);
                $this->walletAdjuster->chargeDeferredOverage($billable, $deferred);
            }

            $this->markStep($billable, $paymentId, $done, self::STEP_WALLET_OVERAGE);
        }

        // A plan change inside one interval whose charge was created in one period and
        // settles in the next. Locally and at Mollie the subscription was still on the old
        // plan when the period renewed — the two agree — so that renewal was collected
        // and booked for the old plan. The change takes effect now, in the middle of the
        // period that renewal paid for, and the Phase-1 lines settle only the period that
        // has since ended.
        //
        // Ahead of the refund lines below, which it used to follow: the two never meet —
        // this runs only when the change opens no new period, the recomposition below only
        // when it does — and each moves money, so each is a step of its own.
        if (! in_array(self::STEP_SPANNED_RENEWAL, $done, true)) {
            // UndercollectionCharge claims a case before it asks Mollie, so a run that died in
            // between left a claim with no outcome, and settle() now answers "already handled"
            // for a balance nobody charged. Asking Mollie again is only safe within the hour
            // its idempotency key is kept; the operators settle it instead.
            // An earlier attempt started settling the period this change landed in and died
            // before recording it. Neither side is safe to run again: the balance it may have
            // claimed (UndercollectionCharge claims before it asks Mollie, and its key lasts
            // an hour) or the renewal it may have refunded (SupersededRenewalRefund holds no
            // record of its own). The operators settle it.
            if (! empty($progress['spanned_renewal_attempted_at'])) {
                $this->reportInterruptedRun($billable, $paymentId, self::INTERRUPTED_SHORTFALL_UNSETTLED, $done, $progress, $pendingPlanChange);

                return;
            }

            if (! $startsNewPeriod && $planOrIntervalChanged && $this->periodMovedSince($billable, $pending)) {
                // Noted first, so a later attempt knows a claim of this kind is this run's.
                $this->noteProgress($billable, $paymentId, ['spanned_renewal_attempted_at' => BillingTime::nowUtc()->toIso8601String()]);
                $progress['spanned_renewal_attempted_at'] = true;

                // Not inside safely(): settle() claims its case before it asks Mollie and
                // records an outcome after. A throw in between, swallowed and recorded as
                // done, left the claim standing and the balance uncharged with nobody told.
                // Thrown, the step stays open and the next delivery finds the claim above.
                $this->settlePeriodTheChangeLandsIn($billable, $pending, $oldPlan);
            }

            $this->markStep($billable, $paymentId, $done, self::STEP_SPANNED_RENEWAL);
        }

        if (! in_array(self::STEP_REFUNDS, $done, true)) {
            $refundLines = $this->refundLinesForRun(
                $billable,
                $paymentId,
                $pending,
                $progress,
                $startsNewPeriod && ! $wasTrial && ! $forcesResetStartDate,
            );

            // A line whose token is on a credit note under a booking that stands — or queued
            // for a retry — was paid (or is being paid) by the attempt that died; it is not
            // paid again. One whose booking a reversal took back (the refund failed or was
            // canceled at Mollie) is owed again and comes back in `$reopened`.
            $reopened = [];
            $refundLines = $this->withoutSettledRefundLines($billable, $refundLines, $reopened);

            // An earlier attempt asked Mollie and died before it could book the answer:
            // whether the lines left over were paid is not known here. Asked again, the
            // idempotency key protects them only within the hour Mollie keeps it, and not
            // at all once a clamped amount changes the key. Not asked again — the
            // operators look it up. A re-opened line is not unknown: its refund was booked
            // and then reported dead. createRefund() posts it under a moved key, after
            // asking Mollie for a live refund carrying its token, as it does for a retry.
            $unknown = array_filter(
                $refundLines,
                static fn (ProrataLine $l): bool => $l->refundToken === null || ! isset($reopened[$l->refundToken]),
            );

            if ($unknown !== [] && ! empty($progress['refunds_started_at'])) {
                $this->reportInterruptedRun($billable, $paymentId, self::INTERRUPTED_REFUNDS_UNKNOWN, $done, $progress, $pendingPlanChange);

                return;
            }

            if (! empty($refundLines)) {
                $this->noteProgress($billable, $paymentId, ['refunds_started_at' => BillingTime::nowUtc()->toIso8601String()]);

                try {
                    $this->invoiceService->createRefund($billable, $refundLines, 'Plan change refund');
                } catch (\Throwable $e) {
                    Log::error('Failed to create refund invoice in Phase 2', [
                        'billable' => $billable->getKey(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->markStep($billable, $paymentId, $done, self::STEP_REFUNDS);
        }

        if (! in_array(self::STEP_SWITCHED, $done, true)) {
            $billable->refresh();

            // Asked again right before the switch writes the period: a renewal booked while
            // this run was paying out moves the anchor, and the switch would write this
            // payment's earlier period over the one the renewal booked. None of this run's
            // own steps moves it. (It narrows the window; the renewal handler takes no lock
            // this check could share.)
            if ($this->periodMovedForRun($billable, $progress)) {
                $this->reportInterruptedRun($billable, $paymentId, self::INTERRUPTED_RENEWAL_BOOKED, $done, $progress, $pendingPlanChange);

                return;
            }

            // And the subscription has to still run: a cancellation while this run was under
            // way must not get the new plan written onto it.
            if (! in_array($billable->getBillingSubscriptionStatus(), self::APPLICABLE_STATES, true)) {
                $this->reportInterruptedRun($billable, $paymentId, self::INTERRUPTED_SUBSCRIPTION_ENDED, $done, $progress, $pendingPlanChange);

                return;
            }

            // `$wasTrial` was resolved before the period decision above — same status, one
            // source of truth for both the anchor and the Mollie cadence.
            $wasPastDue = $billable->getBillingSubscriptionStatus() === SubscriptionStatus::PastDue;

            // What the billable looked like before the switch — the change is announced
            // from the difference, further down. Stored with the switch itself, so a run
            // resumed after it still knows.
            $before = [
                'plan' => $billable->getBillingSubscriptionPlanCode(),
                'interval' => $billable->getBillingSubscriptionInterval(),
                'seats' => $billable->getBillingSeatCount(),
                'addons' => array_values($billable->getActiveBillingAddonCodes()),
            ];

            if ($intentData !== null) {
                $meta = $billable->getBillingSubscriptionMeta();

                // The intent first: it is the change that was validated, and what the PATCH
                // below prices Mollie from. The charge invoice only *suggests* a count —
                // included seats plus the quantity of its `seats` line — and that line is left
                // off whenever it rounds to nothing (a change on the last day of a period, a
                // low seat price, a zero-decimal currency) or the new plan does not price its
                // seats. Read first, it collapsed the count to the plan's included seats: the
                // customer lost seats they hold while Mollie went on charging for all of them.
                $derivedFromCharge = $chargeInvoice->deriveSeatCount();
                $meta['seat_count'] = isset($intentData['new_seats'])
                    ? (int) $intentData['new_seats']
                    : ($derivedFromCharge ?? $billable->getBillingSeatCount());

                unset($meta['pending_plan_change'], $meta['prorata_pending_payment_id']);

                // See SubscriptionPaymentHandler: the conversion nulls `trial_ends_at`, so the
                // moment it happened has to be recorded or the trial-conversion KPI has no
                // cohort to count.
                if ($wasTrial) {
                    $meta['trial_converted_at'] = $newPeriodStart->toIso8601String();

                    // The grant record describes a trial that no longer runs. Status alone
                    // already keeps `grantedBillingQuota()` off it, but leaving it behind
                    // invites the next reader to trust it.
                    unset($meta['trial_quota']);
                }

                if ($wasPastDue) {
                    unset($meta['payment_failure'], $meta['past_due_since']);
                }

                // In the same write as the switch: a run cannot die between the two.
                if (is_array($meta['pending_prorata_change'] ?? null)) {
                    $meta['pending_prorata_change']['progress'] = self::progressWith(
                        $meta['pending_prorata_change'],
                        self::STEP_SWITCHED,
                        [
                            'before' => $before,
                            // What the switch made of it — announced from these, not from the
                            // row at announcement time, which an expiry may have emptied.
                            'after' => [
                                'seats' => (int) $meta['seat_count'],
                                'addons' => array_values(array_map('strval', array_keys((array) ($intentData['new_addons'] ?? [])))),
                            ],
                        ],
                    );
                }

                $billable->forceFill([
                    'subscription_plan_code' => $newPlanCode,
                    'subscription_interval' => $newIntervalCode,
                    'active_addon_codes' => array_keys((array) ($intentData['new_addons'] ?? [])),
                    'subscription_meta' => $meta,
                    ...($startsNewPeriod ? ['subscription_period_starts_at' => $newPeriodStart] : []),
                    ...($wasPastDue || $wasTrial ? [
                        // Not `Active` outright: an overage debt that stands keeps its lock.
                        'subscription_status' => \GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly::statusAfterRecovery($meta, SubscriptionStatus::Active),
                        'subscription_ends_at' => null,
                    ] : []),
                    ...($wasTrial ? ['trial_ends_at' => null] : []),
                ])->save();

                $done[] = self::STEP_SWITCHED;
            } else {
                $this->markStep($billable, $paymentId, $done, self::STEP_SWITCHED, ['before' => $before]);
            }

            $progress = (array) ($billable->refresh()->getBillingSubscriptionMeta()['pending_prorata_change']['progress'] ?? $progress);
        }

        if ($intentData !== null && ! in_array(self::STEP_EVENTS, $done, true)) {
            $before = is_array($progress['before'] ?? null) ? $progress['before'] : [];
            $seatsBefore = (int) ($before['seats'] ?? $billable->getBillingSeatCount());
            $addonsBefore = array_values((array) ($before['addons'] ?? []));
            $planBefore = $before['plan'] ?? null;
            $intervalBefore = $before['interval'] ?? null;
            $changeEvents = [];

            // The change is in force from here, so this is where it is announced. It was
            // not: `update()` dispatches PlanChanged & co. for a change it applies itself,
            // and a change that waits for its charge was applied by this handler in
            // silence — no event for the app's listeners (the documented extension seam),
            // no `plan_changed` row in the audit trail, for every paid upgrade.
            $after = is_array($progress['after'] ?? null) ? $progress['after'] : [];
            $seatsAfter = (int) ($after['seats'] ?? $billable->getBillingSeatCount());
            $addonsAfter = array_values((array) ($after['addons'] ?? $billable->getActiveBillingAddonCodes()));
            $addonsAdded = array_values(array_diff($addonsAfter, $addonsBefore));
            $addonsRemoved = array_values(array_diff($addonsBefore, $addonsAfter));
            $planMoved = (string) $planBefore !== $newPlanCode;
            $intervalMoved = (string) $intervalBefore !== $newIntervalCode;

            if ($planMoved || $intervalMoved) {
                $changeEvents[] = new \GraystackIT\MollieBilling\Events\PlanChanged($billable, $planBefore ?: null, $newPlanCode, $newIntervalCode);

                // A balance still open for the configuration that has just been left is
                // owed only for the days it ran; a declined one is closed.
                app(\GraystackIT\MollieBilling\Services\Billing\UndercollectionCharge::class)->configurationLeft($billable, (string) $planBefore);
            }

            if ($seatsAfter !== $seatsBefore) {
                $changeEvents[] = new \GraystackIT\MollieBilling\Events\SeatsChanged($billable, $seatsBefore, $seatsAfter);
            }

            foreach ($addonsAdded as $code) {
                $changeEvents[] = new \GraystackIT\MollieBilling\Events\AddonEnabled($billable, (string) $code);
            }

            foreach ($addonsRemoved as $code) {
                $changeEvents[] = new \GraystackIT\MollieBilling\Events\AddonDisabled($billable, (string) $code);
            }

            $changeEvents[] = new \GraystackIT\MollieBilling\Events\SubscriptionUpdated(
                $billable,
                [
                    'plan_code' => $newPlanCode,
                    'interval' => $newIntervalCode,
                    'seats' => $seatsAfter,
                    'addons' => $addonsAfter,
                ],
                [
                    'planChanged' => $planMoved,
                    'intervalChanged' => $intervalMoved,
                    'seatsChanged' => $seatsAfter !== $seatsBefore,
                    'addonsAdded' => $addonsAdded,
                    'addonsRemoved' => $addonsRemoved,
                ],
            );

            // Each one isolated: a listener that throws must not stop the PATCH below.
            foreach ($changeEvents as $changeEvent) {
                $this->support->safely(
                    fn () => event($changeEvent),
                    'prorata_charge.change_event',
                    $billable,
                    ['payment_id' => (string) ($payment->id ?? ''), 'event' => $changeEvent::class],
                );
            }

            $this->markStep($billable, $paymentId, $done, self::STEP_EVENTS);
        }

        // Redeem AFTER the local switch (the recurring marker's lifetime is
        // derived from the billable's interval) and BEFORE the PATCH below, which
        // reads the marker to keep the promised discount on the new amount.
        if (! in_array(self::STEP_COUPONS, $done, true)) {
            $billable->refresh();
            $this->redeemPendingCoupons(
                $billable,
                $pendingPlanChange,
                (array) ($pending['charge_lines'] ?? []),
                $newPlanCode,
                $newIntervalCode,
                $chargeInvoice,
            );

            $this->markStep($billable, $paymentId, $done, self::STEP_COUPONS);
        }

        // A malformed stored intent cannot be patched or retried — retrying a bug does
        // not fix it, and letting it escape would 500 the webhook so Mollie re-delivers
        // forever. The local change is already applied at this point, so log loudly,
        // leave the pending state to be cleared below, and stop.
        try {
            $intent = PlanChangeIntent::fromArray($pending['intent']);
        } catch (\Throwable $e) {
            Log::error('Prorata Phase 2: stored plan-change intent is unusable — Mollie was not patched', [
                'billable' => $billable->getKey(),
                'payment_id' => (string) ($payment->id ?? ''),
                'error' => $e->getMessage(),
            ]);

            $this->clearPendingState($billable);

            return;
        }

        // The charge that just cleared opened a new period, so the next recurring charge
        // is a full interval away — and Mollie collects whatever it is PATCHed to below.
        // A recurring coupon that is live today but over by then must not be in that
        // amount. The renewal handler makes the same judgement at every renewal
        // (`markerExpired($billable, $newPeriodEnd)`); a change that opens a period is
        // the one other place a period begins, and it had no such check: a monthly
        // subscription with two coupon months left that switched to yearly was PATCHed
        // to the discounted yearly amount, and the renewal a year later was collected
        // with a discount that had ended ten months earlier.
        //
        // A plan waiver is left alone: what its end means (`on_expiry`) is the renewal
        // handler's decision, not something to settle in passing here.
        if ($startsNewPeriod) {
            $this->support->safely(function () use ($billable, $newPeriodEnd): void {
                $marker = $this->couponService->getActiveRecurringCouponMarker($billable);

                if (is_array($marker)
                    && ! \GraystackIT\MollieBilling\Services\Billing\PlanWaiver::isWaiver($marker)
                    && $this->couponService->markerExpired($billable, $newPeriodEnd)
                ) {
                    $this->couponService->clearActiveRecurringCouponMarker($billable);
                    $billable->refresh();
                }
            }, 'prorata_charge.marker_expiry', $billable, ['payment_id' => (string) ($payment->id ?? '')]);
        }

        // A converted trial needs Mollie's schedule moved, even on a same-interval
        // change. Mollie's `startDate` is the trial end (CreateSubscription sets it
        // there), and the composer priced this charge as "the rest of the local period"
        // — days 5→30 of a period whose trial ended on day 14. An amount-only PATCH
        // left the first recurring charge on day 14, so Mollie collected a full month
        // for days the customer had just paid for: roughly 1.8× the monthly price in
        // their first ten days. `forceResetStartDate` is what tells the patcher to move
        // the cadence to one full period out; it was set for the past-due reset only.
        if ($wasTrial && ! $intent->forceResetStartDate) {
            $intent = new PlanChangeIntent(
                billable: $intent->billable,
                currentPlan: $intent->currentPlan,
                newPlan: $intent->newPlan,
                currentInterval: $intent->currentInterval,
                newInterval: $intent->newInterval,
                currentSeats: $intent->currentSeats,
                newSeats: $intent->newSeats,
                currentAddons: $intent->currentAddons,
                newAddons: $intent->newAddons,
                forceResetStartDate: true,
            );
        }

        if (! in_array(self::STEP_PATCHED, $done, true)) {
            $this->safelyPatch($billable, $intent);

            $this->markStep($billable, $paymentId, $done, self::STEP_PATCHED);
        }

        $this->clearPendingState($billable);

        // The PATCH above set the amount of the configuration that just became current.
        // A change scheduled for the period end had its own amount announced to Mollie
        // already — see ScheduleSubscriptionChange::realignScheduled().
        app(\GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange::class)->realignScheduled($billable);
    }

    /**
     * What the plan change costs for the rest of the period it actually lands in.
     *
     * Composed as the same change would be composed if it were made now, while the
     * billable still describes the old plan: the new configuration for the days that are
     * left, less the unused share of what the renewal collected.
     *
     * - **More** than was collected (an upgrade): the difference is collected as its own
     *   payment with its own invoice, exactly once, and the customer is told the amount
     *   and the reason — {@see \GraystackIT\MollieBilling\Services\Billing\UndercollectionCharge}.
     * - **Less** (a downgrade): the difference is given back against the renewal, the
     *   same way a renewal collected at a superseded price is.
     *
     * @param  array<string, mixed>  $pending
     */
    protected function settlePeriodTheChangeLandsIn(Billable $billable, array $pending, string $oldPlan): void
    {
        $lines = app(\GraystackIT\MollieBilling\Services\Billing\ProrataComposer::class)
            ->compose(PlanChangeIntent::fromArray((array) ($pending['intent'] ?? [])));

        $net = 0;
        foreach ($lines as $line) {
            $net += $line->amountNet;
        }

        $candidates = \GraystackIT\MollieBilling\Models\BillingInvoice::currentPeriodLines($billable, 'plan', $oldPlan);
        $renewal = $candidates[0]['invoice'] ?? null;

        if ($net > 0) {
            $collected = (int) ($renewal?->amount_net ?? 0);

            app(\GraystackIT\MollieBilling\Services\Billing\UndercollectionCharge::class)->settle(
                $billable,
                $renewal,
                $collected + $net,
                $collected,
                \GraystackIT\MollieBilling\Events\RenewalUndercollected::REASON_PLAN_CHANGE_SPANNED_RENEWAL,
                (string) ($pending['intent']['new_plan'] ?? '') ?: null,
            );

            return;
        }

        if ($net < 0 && $renewal !== null) {
            app(\GraystackIT\MollieBilling\Services\Billing\SupersededRenewalRefund::class)->refundOverpaid(
                $billable,
                $renewal,
                -$net,
                'Plan change took effect in a period that had renewed at the previous plan\'s price',
            );
        }
    }

    /**
     * Whether a renewal moved the billing period after the pending change was created.
     *
     * @param  array<string, mixed>  $pending
     */
    protected function periodMovedSince(Billable $billable, array $pending): bool
    {
        $createdAt = $pending['created_at'] ?? null;
        $anchor = $billable->getBillingPeriodStartsAt();

        if (! is_string($createdAt) || $createdAt === '' || $anchor === null) {
            return false;
        }

        return $anchor->greaterThan(Carbon::parse($createdAt)->setTimezone('UTC'));
    }

    /**
     * Refund lines for the period that is running NOW, for a stored intent.
     *
     * Falls back to the stored lines when the intent cannot be rebuilt or the composer
     * finds nothing — crediting what Phase 1 promised is never wrong, only incomplete.
     *
     * @param  array<string, mixed>  $pending
     * @param  list<ProrataLine>  $stored
     * @return list<ProrataLine>
     */
    protected function recomposeRefundLines(Billable $billable, array $pending, array $stored): array
    {
        try {
            $lines = app(\GraystackIT\MollieBilling\Services\Billing\ProrataComposer::class)
                ->compose(PlanChangeIntent::fromArray((array) ($pending['intent'] ?? [])));
        } catch (\Throwable $e) {
            Log::warning('Prorata Phase 2: could not recompose the refund lines for the current period', [
                'billable' => $billable instanceof Model ? $billable->getKey() : null,
                'error' => $e->getMessage(),
            ]);

            return $stored;
        }

        $fresh = array_values(array_filter($lines, fn (ProrataLine $l): bool => $l->direction === 'refund'));

        // Instead of the stored lines, not on top of them: those credit the remainder of
        // the period Phase 1 saw, and that period has since run to its end on the old
        // configuration — it was used in full. What is unused now is the renewal's period.
        return $fresh === [] ? $stored : $fresh;
    }

    /** The invoice this payment was booked with, if a run got that far. */
    protected function chargeInvoiceFor(string $paymentId): ?BillingInvoice
    {
        if ($paymentId === '') {
            return null;
        }

        return BillingInvoice::query()->where('mollie_payment_id', $paymentId)->first();
    }

    /**
     * Whether a run for this payment died after its invoice and before it was done: the
     * invoice is the charge's own, the change still waits for exactly this payment, and
     * the run recorded what it had done.
     * Anything else that holds an invoice for it — a finished run, a payment that was
     * returned — is a re-delivery to swallow.
     */
    protected function resumable(Billable $billable, string $paymentId, BillingInvoice $invoice): bool
    {
        $pending = $billable->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null;

        return $paymentId !== ''
            && $invoice->invoice_kind === InvoiceKind::Subscription
            && is_array($pending)
            && (string) ($pending['charge_payment_id'] ?? '') === $paymentId
            // Noted before the invoice by every run that records its steps. Without it the
            // run predates that, and nothing says which of its steps are done.
            && is_array($pending['progress'] ?? null)
            && array_key_exists('anchor', $pending['progress']);
    }

    /**
     * Record a completed step of {@see paid()}, under the row lock, and only while the
     * change still waits for this payment.
     *
     * @param  list<string>  $done
     * @param  array<string, mixed>  $extra
     */
    protected function markStep(Billable $billable, string $paymentId, array &$done, string $step, array $extra = []): void
    {
        $done[] = $step;

        if (! ($billable instanceof Model)) {
            return;
        }

        $recorded = false;

        $this->mutateBillingMeta($billable, static function (array $meta) use ($paymentId, $step, $extra, &$recorded): ?array {
            $pending = $meta['pending_prorata_change'] ?? null;

            if (! is_array($pending) || (string) ($pending['charge_payment_id'] ?? '') !== $paymentId) {
                return null;
            }

            $meta['pending_prorata_change']['progress'] = self::progressWith($pending, $step, $extra);
            $recorded = true;

            return $meta;
        });

        // The waiting change is gone from under this run — CleanupStalePendingProrataChangeJob
        // gave it up, or it was withdrawn. Carrying on would move money with nothing to
        // record it in; the run stops (and, through the controller, answers 500).
        if (! $recorded) {
            throw new \RuntimeException("Prorata run for payment {$paymentId} lost its waiting change at step {$step} — stopped");
        }
    }

    /**
     * Whether a waiting change can be the one this payment's invoice was booked for.
     *
     * A change that names this payment is. One that names none — written before the id
     * was recorded on it — may be a run of this payment that predates the recorded steps,
     * or a later change nobody has paid for yet. The later one is told apart by being
     * younger than the invoice, or by its top-level marker naming another payment.
     *
     * @param  array<string, mixed>  $waiting
     */
    protected function mayBeThisPaymentsChange(Billable $billable, array $waiting, string $paymentId, BillingInvoice $invoice): bool
    {
        $owner = (string) ($waiting['charge_payment_id'] ?? '');

        if ($owner !== '') {
            return $owner === $paymentId;
        }

        $marker = (string) ($billable->getBillingSubscriptionMeta()['prorata_pending_payment_id'] ?? '');

        if ($marker !== '' && $marker !== $paymentId) {
            return false;
        }

        $createdAt = is_string($waiting['created_at'] ?? null) ? Carbon::parse($waiting['created_at']) : null;

        return $createdAt === null || $invoice->created_at === null || $createdAt->lessThanOrEqualTo($invoice->created_at);
    }

    /** Whether the period this run was decided in has ended since — a renewal moved it. */
    protected function periodMovedForRun(Billable $billable, array $progress): bool
    {
        return array_key_exists('anchor', $progress)
            && $progress['anchor'] !== $billable->getBillingPeriodStartsAt()?->toIso8601String();
    }

    /**
     * Shortfall cases this run claimed (since it started) without an outcome recorded.
     *
     * @param  array<string, mixed>  $progress
     * @return list<string>
     */
    protected function unsettledShortfallClaimsOfRun(Billable $billable, array $progress): array
    {
        $startedAt = is_string($progress['started_at'] ?? null) ? $progress['started_at'] : null;

        if ($startedAt === null) {
            return [];
        }

        $since = Carbon::parse($startedAt);
        $keys = [];

        foreach ((array) ($billable->getBillingSubscriptionMeta()[\GraystackIT\MollieBilling\Services\Billing\UndercollectionCharge::META_KEY] ?? []) as $key => $case) {
            if (! is_array($case) || isset($case['outcome']) || ! is_string($case['claimed_at'] ?? null)
                || ! str_starts_with((string) $key, \GraystackIT\MollieBilling\Events\RenewalUndercollected::REASON_PLAN_CHANGE_SPANNED_RENEWAL.':')
            ) {
                continue;
            }

            if (Carbon::parse($case['claimed_at'])->greaterThanOrEqualTo($since)) {
                $keys[] = (string) $key;
            }
        }

        return $keys;
    }

    /**
     * Note something in the progress without recording a step.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function noteProgress(Billable $billable, string $paymentId, array $extra): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        $this->mutateBillingMeta($billable, static function (array $meta) use ($paymentId, $extra): ?array {
            $pending = $meta['pending_prorata_change'] ?? null;

            if (! is_array($pending) || (string) ($pending['charge_payment_id'] ?? '') !== $paymentId) {
                return null;
            }

            $progress = is_array($pending['progress'] ?? null) ? $pending['progress'] : [];
            $meta['pending_prorata_change']['progress'] = array_replace($progress, $extra);

            return $meta;
        });
    }

    /**
     * The refund lines this run pays out, each with its refund token.
     *
     * Decided once: the first attempt takes the lines Phase 1 stored — composed again for
     * the period running now when a renewal was booked since — mints a token for each and
     * stores them before Mollie is asked. Every later attempt takes exactly those. The
     * token is part of each refund's Mollie idempotency key; createRefund() mints one when
     * a line has none, but only in memory, so a run that died after a refund went out sent
     * a fresh key on the next delivery, and Mollie paid the same amount a second time.
     *
     * @param  array<string, mixed>  $pending
     * @param  array<string, mixed>  $progress
     * @return list<ProrataLine>
     */
    protected function refundLinesForRun(Billable $billable, string $paymentId, array $pending, array $progress, bool $mayRecompose): array
    {
        if (is_array($progress['refund_lines'] ?? null)) {
            return array_map(
                static fn (array $data): ProrataLine => ProrataLine::fromArray($data),
                array_values($progress['refund_lines']),
            );
        }

        $lines = array_map(
            static fn (array $data): ProrataLine => ProrataLine::fromArray($data),
            array_values((array) ($pending['refund_lines'] ?? [])),
        );

        // An interval change whose charge was created in one period and settles in the
        // next. The stored refund lines were composed in Phase 1, against the period that
        // was running then — but a renewal of the OLD interval has been booked since
        // (Mollie is only moved to the new cadence later, and with SEPA the charge takes
        // days). The charge that just cleared pays for a full new interval from now, so
        // what that renewal bought is unused from here on and nothing in the stored list
        // credits it: the customer had paid a month and a year for the same days.
        // Composed again here, while the billable still describes the old configuration.
        if ($mayRecompose && $this->periodMovedSince($billable, $pending)) {
            $lines = $this->recomposeRefundLines($billable, $pending, $lines);
        }

        foreach ($lines as $line) {
            $line->refundToken ??= bin2hex(random_bytes(8));
        }

        $this->noteProgress($billable, $paymentId, [
            'refund_lines' => array_map(static fn (ProrataLine $l): array => $l->toArray(), $lines),
        ]);

        return $lines;
    }

    /**
     * The refund lines no earlier attempt of this run has settled: neither on one of the
     * billable's credit notes under a booking that still stands (createRefund() writes each
     * line, token included, onto it) nor queued under `pending_refund_retries` for
     * RetryRefundLineJob.
     *
     * A token whose only bookings a reversal took back — Mollie reported that refund
     * `failed` or `canceled`, RefundHandler booked the mirror line — settles nothing: the
     * money never left. Such a line is kept, and its token is added to `$reopened`.
     *
     * @param  list<ProrataLine>  $lines
     * @param  array<string, true>  $reopened  out: the tokens of the kept lines a reversal re-opened
     * @return list<ProrataLine>
     */
    protected function withoutSettledRefundLines(Billable $billable, array $lines, array &$reopened = []): array
    {
        if (! ($billable instanceof Model) || $lines === []) {
            return $lines;
        }

        $settled = [];

        // One read of the credit notes for every token, through the same reading
        // createRefund() settles a line on — a reversed booking counts for neither.
        $bookings = $this->invoiceService->refundTokenBookings(
            $billable,
            array_values(array_filter(array_map(static fn (ProrataLine $l): ?string => $l->refundToken, $lines))),
        );

        foreach ($bookings as $token => $state) {
            if ($state === 'standing') {
                $settled[$token] = true;
            }
        }

        foreach ((array) ($billable->getBillingSubscriptionMeta()['pending_refund_retries'] ?? []) as $retry) {
            $token = is_array($retry) ? ($retry['line']['refund_token'] ?? null) : null;

            if (is_string($token) && $token !== '') {
                $settled[$token] = true;
            }
        }

        foreach ($bookings as $token => $state) {
            if ($state === 'reversed' && ! isset($settled[$token])) {
                $reopened[$token] = true;
            }
        }

        return array_values(array_filter(
            $lines,
            // A line createRefund() skips (coupon-covered, or nothing to return) never
            // reaches a credit note; it is settled by being nothing to pay.
            static fn (ProrataLine $l): bool => ! $l->isCouponCovered && $l->amountGross < 0
                && ($l->refundToken === null || ! isset($settled[$l->refundToken])),
        ));
    }

    /**
     * Start the progress of a fresh run: its context, and no step done.
     *
     * @param  array<string, mixed>  $context
     */
    protected function rememberRunContext(Billable $billable, string $paymentId, array $context): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        $this->mutateBillingMeta($billable, static function (array $meta) use ($paymentId, $context): ?array {
            $pending = $meta['pending_prorata_change'] ?? null;

            if (! is_array($pending) || (string) ($pending['charge_payment_id'] ?? '') !== $paymentId) {
                return null;
            }

            $meta['pending_prorata_change']['progress'] = $context + ['done' => []];

            return $meta;
        });
    }

    /**
     * A run that holds its invoice and is not carried on — the operators are told, once.
     *
     * - `renewal_booked`: the period it was decided in has ended since — a renewal of the
     *   old configuration, or the charge that ends the trial, was booked in between. What
     *   the finished steps did (the wallets rebalanced, the refund lines paid out, the
     *   trial treated as running) was right for that period and is not for this one, and
     *   carrying on would book on top of it.
     * - `no_progress`: the run predates the recorded steps, so nothing says which of them
     *   are done; run again from the start it would move the money a second time.
     * - `subscription_ended`: the subscription no longer runs, and what is owed back
     *   depends on which steps had already paid out.
     *
     * Nothing is guessed: the change stays waiting, so nothing is lost. The report is noted
     * in the progress (`interrupted_reported_at`), which a later delivery of the payment and
     * CleanupStalePendingProrataChangeJob read so as not to tell the operators again.
     *
     * @param  list<string>  $done
     * @param  array<string, mixed>  $progress
     * @param  array<string, mixed>|null  $pendingPlanChange
     */
    protected function reportInterruptedRun(Billable $billable, string $paymentId, string $why, array $done, array $progress, ?array $pendingPlanChange): void
    {
        $context = [
            'payment_id' => $paymentId,
            'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
            'why' => $why,
            'completed_steps' => $done,
            'period_decided_in' => $progress['anchor'] ?? null,
            'period_now' => $billable->getBillingPeriodStartsAt()?->toIso8601String(),
            'subscription_status' => $billable->getBillingSubscriptionStatus()->value,
            'intended_plan_change' => $pendingPlanChange,
            // What the operator needs to settle it by hand — and the only copy, once the
            // clean-up has dropped the waiting change.
            'refund_lines' => $progress['refund_lines'] ?? null,
            'refunds_started_at' => $progress['refunds_started_at'] ?? null,
            'deferred_overage' => $progress['deferred_overage'] ?? null,
            'wallet_overage_started_at' => $progress['wallet_overage_started_at'] ?? null,
            'spanned_renewal_attempted_at' => $progress['spanned_renewal_attempted_at'] ?? null,
            'unsettled_claims' => $this->unsettledShortfallClaimsOfRun($billable, $progress),
        ];

        [$log, $reason] = match ($why) {
            self::INTERRUPTED_NO_PROGRESS => [
                'Prorata charge not resumed — its run died before steps were recorded, reconcile it by hand',
                'A paid plan change was interrupted after its invoice was written, by a version that did not record its steps, so it cannot be resumed safely — the payment is booked; check what was done (wallet, refunds, plan) and finish or refund it by hand',
            ],
            self::INTERRUPTED_REFUNDS_UNKNOWN => [
                'Prorata charge not resumed — refunds were sent to Mollie but their outcome was never booked, reconcile it by hand',
                'A paid plan change was interrupted while its refunds were being paid out; whether Mollie paid the lines not on a credit note is unknown — the change payment is booked; check the refunds in Mollie, then finish the change by hand',
            ],
            self::INTERRUPTED_SHORTFALL_UNSETTLED => [
                'Prorata charge not resumed — the period it landed in was being settled when its run died, reconcile it by hand',
                'A paid plan change was interrupted while the billing period it landed in was being settled (a balance charged, or the renewal partly refunded); whether that reached Mollie is unknown — the change payment is booked; check Mollie for the balance payment or the renewal refund (see unsettled_claims), then finish the change by hand',
            ],
            self::INTERRUPTED_OVERAGE_UNKNOWN => [
                'Prorata charge not resumed — the overage its rebalance left was being charged when its run died, reconcile it by hand',
                'A paid plan change was interrupted while the usage overage it left was being charged; whether Mollie created that payment is unknown — the change payment is booked; check Mollie and the wallets (see deferred_overage), then finish the change by hand',
            ],
            self::INTERRUPTED_SUBSCRIPTION_ENDED => [
                'Prorata charge not resumed — the subscription ended after its run died, reconcile it by hand',
                'A paid plan change was interrupted and the subscription has ended since, so it was not carried out — the payment is booked; check the completed steps (refund lines may already be paid out) and refund what is still owed by hand',
            ],
            default => [
                'Prorata charge not resumed — a renewal was booked after its run died, reconcile it by hand',
                'A paid plan change was interrupted and a renewal was booked before it could be resumed, so it was not carried out automatically — the payment is booked, check the completed steps and finish or refund it by hand',
            ],
        };

        Log::error($log, $context);

        if (! empty($progress['interrupted_reported_at'])) {
            return;
        }

        // Sent first, noted after. Noted first, a resolver or a send that threw left the
        // note behind: the re-delivery and the clean-up both read it as "told" and kept
        // quiet for good. A failure here propagates instead — the webhook answers 500 and
        // Mollie's next delivery tries again.
        $admins = MollieBilling::notifyAdmin();
        $admins = is_array($admins) ? $admins : iterator_to_array($admins);

        // Nobody to tell yet: no note either, so an operator configured later still hears
        // of it from the next delivery or the clean-up.
        if ($admins === []) {
            return;
        }

        // The note records that the report was handed over (to the queue, where the
        // notification queues) — not that it was read.
        Notification::send($admins, MollieBilling::resolveNotification(
            AdminPlanChangeFailedNotification::class,
            $reason,
            $context,
        ));

        if ($billable instanceof Model) {
            $this->mutateBillingMeta($billable, static function (array $meta) use ($paymentId): ?array {
                $owner = (string) ($meta['pending_prorata_change']['charge_payment_id'] ?? '');

                if (! is_array($meta['pending_prorata_change'] ?? null) || ($owner !== '' && $owner !== $paymentId)) {
                    return null;
                }

                $progress = is_array($meta['pending_prorata_change']['progress'] ?? null)
                    ? $meta['pending_prorata_change']['progress']
                    : [];
                $progress['interrupted_reported_at'] = BillingTime::nowUtc()->toIso8601String();
                $meta['pending_prorata_change']['progress'] = $progress;

                return $meta;
            });
        }
    }

    /**
     * @param  array<string, mixed>  $pending
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected static function progressWith(array $pending, string $step, array $extra = []): array
    {
        $progress = is_array($pending['progress'] ?? null) ? $pending['progress'] : [];
        $done = array_values(array_map('strval', (array) ($progress['done'] ?? [])));

        if (! in_array($step, $done, true)) {
            $done[] = $step;
        }

        return array_replace($progress, $extra, ['done' => $done]);
    }

    /**
     * Drop the deferred-change markers. Re-read first: `safelyPatch()` may have just
     * written `pending_subscription_patch`, and a stale copy of the meta would erase it
     * — leaving nothing for the sweep to retry.
     */
    protected function clearPendingState(Billable $billable): void
    {
        if (! ($billable instanceof Model)) {
            return;
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

        // Only what Phase 1 actually deferred. `UpdateSubscription` defers its redemption
        // exclusively for a plan or interval change; a seat sync or an addon change redeems
        // straight away *and* creates a deferred Mollie charge. Falling back to the codes
        // found in the charge lines therefore redeemed those a second time — and that
        // fallback carries no `new_net`, so `orderAmountNet` was 0 and `redeem()` rewrote
        // `active_recurring_coupon` with `base_amount_net = 0`. From then on
        // `computeMarkerDiscount()` returned 0 for good and the PATCH that follows set Mollie
        // back to the full price: a 50%/12-month campaign quietly stopped discounting after
        // one seat change, and the customer had burned two campaign slots for it.
        if ($pendingPlanChange === null) {
            return;
        }

        $codes = (array) ($pendingPlanChange['coupon_codes'] ?? []);
        if ($codes === [] && ! empty($pendingPlanChange['coupon_code'])) {
            $codes = [(string) $pendingPlanChange['coupon_code']];
        }

        if ($codes === []) {
            return;
        }

        // The recurring net the marker has to be anchored to. Zero would silence the
        // discount permanently, so a payload without it is not something to guess at.
        $orderAmountNet = (int) ($pendingPlanChange['new_net'] ?? 0);

        if ($orderAmountNet <= 0) {
            Log::warning('Prorata phase 2 skipped the coupon redemption — the deferred change carries no recurring net', [
                'billable' => $billable instanceof Model ? $billable->getKey() : null,
                'codes' => $codes,
            ]);

            return;
        }

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

                // Each code commits on its own, and the step is recorded after the last: a
                // run that died between two codes redeemed the first again on the next
                // delivery — a second campaign slot used, the coupon twice in the audit.
                $alreadyRedeemed = \GraystackIT\MollieBilling\Models\CouponRedemption::query()
                    ->where('coupon_id', $coupon->getKey())
                    ->where('billable_type', $billable instanceof Model ? $billable->getMorphClass() : '')
                    ->where('billable_id', $billable instanceof Model ? $billable->getKey() : null)
                    ->where('invoice_id', (int) $chargeInvoice->id)
                    ->exists();

                if ($alreadyRedeemed) {
                    continue;
                }
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
    /**
     * Whether this payment is the one the pending change is waiting for.
     *
     * `pending_prorata_change` is a single slot. When a change is cancelled while its
     * payment cannot be (SEPA in flight) and the customer then starts another, the slot
     * holds the *newer* change by the time the older payment reports — and without this
     * check the older payment applied, or discarded, the newer change. A pending state
     * without a recorded id (written before the id was stored) is still accepted.
     *
     * @param  array<string, mixed>  $pending
     */
    protected function paymentOwnsPending(object $payment, array $pending): bool
    {
        $expected = (string) ($pending['charge_payment_id'] ?? '');

        return $expected === '' || $expected === (string) ($payment->id ?? '');
    }

    /**
     * Take a payment over whose creation went unanswered — see
     * `InvoiceService::CHARGE_IN_CREATION_KEY`. Only when its token is the one noted and
     * no other change holds the slot; a note withdrawn with its change is gone, so a late
     * payment for it is returned instead.
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function adoptChargeInCreation(object $payment, Billable $billable, array $metadata): void
    {
        $token = (string) ($metadata['charge_token'] ?? '');

        if ($token === '' || ! ($billable instanceof Model)) {
            return;
        }

        $meta = $billable->getBillingSubscriptionMeta();
        $marker = $meta[\GraystackIT\MollieBilling\Services\Billing\InvoiceService::CHARGE_IN_CREATION_KEY] ?? null;

        if (! is_array($marker) || (string) ($marker['token'] ?? '') !== $token || ! empty($meta['pending_prorata_change'])) {
            return;
        }

        app(\GraystackIT\MollieBilling\Services\Billing\InvoiceService::class)
            ->adoptChargeInCreation($billable, $token, (string) ($payment->id ?? ''));
        $billable->refresh();
    }

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

        // The customer cancelled this change; the money for it arrived anyway. It is not
        // applied — and it is not left for someone to refund by hand either: booked,
        // credited and returned in full, the customer told. See UnwantedPaymentRefund.
        Log::info('Prorata payment settled for a cancelled plan change — returning it', [
            'payment_id' => $paymentId,
            'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
            'amount_cents' => $amountCents,
            'cancel_reason' => $orphan['reason'] ?? null,
            'cancelled_at' => $orphan['cancelled_at'] ?? null,
        ]);

        $intended = (string) ($orphan['pending_plan_change']['plan_code'] ?? '');

        app(UnwantedPaymentRefund::class)->settle(
            $payment,
            $billable,
            UnwantedPaymentRefund::REASON_CHANGE_CANCELLED,
            'Subscription change'.($intended !== '' ? ' to '.$intended : '').' — cancelled before the payment arrived, not carried out',
        );
    }

    /**
     * PATCH Mollie, and queue a retry if it fails.
     *
     * This was the fourth marker site the "a failed PATCH actually dispatches
     * RetrySubscriptionPatchJob" fix missed. A bare log meant nothing retried: the
     * hourly sweep only reads `pending_subscription_patch`, which was never written,
     * and the pending state is cleared right after — so Mollie kept charging the old
     * amount for the rest of the subscription's life while the customer had the new
     * plan. The renewal handler then reconciles each invoice down to the amount that
     * actually cleared, by design, so it never self-heals: Pro access at the Basic
     * price, silently, forever.
     */
    protected function safelyPatch(Billable $billable, PlanChangeIntent $intent): void
    {
        try {
            $this->patcher->updateForIntent($billable, $intent);

            return;
        } catch (\Throwable $e) {
            Log::warning('Mollie subscription PATCH failed in Phase 2 — queued for retry', [
                'billable' => $billable instanceof Model ? $billable->getKey() : null,
                'error' => $e->getMessage(),
            ]);
        }

        if (! ($billable instanceof Model)) {
            return;
        }

        // `billable_type` normalised to the configured FQCN: PlanChangeIntent::fromArray()
        // and RetrySubscriptionPatchJob both resolve the row by class name, which a
        // morph-map alias would not satisfy.
        $intentData = [
            ...$intent->toArray(),
            'billable_type' => (string) config('mollie-billing.billable_model'),
            'billable_id' => $billable->getKey(),
        ];
        $firstAttemptAt = BillingTime::nowUtc()->toIso8601String();

        $meta = $billable->getBillingSubscriptionMeta();
        $meta['pending_subscription_patch'] = [
            'intent' => $intentData,
            'first_attempt_at' => $firstAttemptAt,
            'last_error' => 'Phase 2 PATCH failed',
        ];
        $billable->forceFill(['subscription_meta' => $meta])->save();

        RetrySubscriptionPatchJob::dispatch($intentData, $firstAttemptAt);
    }

    /**
     * PATCH Mollie back to the configuration a declined seat/addon change started from.
     *
     * @param  array<string, mixed>  $intent  the stored `pending_prorata_change.intent`
     */
    protected function revertRecurringAmount(Billable $billable, array $intent): void
    {
        foreach (['current_plan', 'new_plan', 'current_interval', 'new_interval', 'current_seats', 'new_seats'] as $key) {
            if (! array_key_exists($key, $intent)) {
                return;
            }
        }

        if ((string) $intent['current_plan'] !== (string) $intent['new_plan']
            || (string) $intent['current_interval'] !== (string) $intent['new_interval']
        ) {
            return;
        }

        if (! in_array($billable->getBillingSubscriptionStatus(), self::APPLICABLE_STATES, true)) {
            return;
        }

        $this->safelyPatch($billable, new PlanChangeIntent(
            billable: $billable,
            currentPlan: (string) $intent['new_plan'],
            newPlan: (string) $intent['current_plan'],
            currentInterval: (string) $intent['new_interval'],
            newInterval: (string) $intent['current_interval'],
            currentSeats: (int) $intent['new_seats'],
            newSeats: (int) $intent['current_seats'],
            currentAddons: (array) ($intent['new_addons'] ?? []),
            newAddons: (array) ($intent['current_addons'] ?? []),
        ));
    }

    /**
     * A prorata charge settled for a subscription that has since been cancelled or
     * expired.
     *
     * The pending state is cleared so the next delivery does not try again, and the
     * plan is deliberately NOT applied: force-filling it would resurrect the
     * plan-scoped fields the expiry pass wipes, handing feature access back to an
     * ended subscription.
     *
     * No invoice is written, and that is a decision rather than an omission — this
     * docblock used to claim one. The Phase-1 charge lines describe a plan change that is
     * not happening, so a document built from them would state something untrue about what
     * the customer bought. What the operator needs in order to refund it is the payment id
     * and the amount, which the event and the admin notification carry. The same shape
     * `reportOrphanedCharge()` uses for its sibling case.
     *
     * @param  array<string, mixed>  $pending
     */
    protected function reportUnapplicableCharge(object $payment, Billable $billable, array $pending): void
    {
        $paymentId = (string) ($payment->id ?? '');
        $amountCents = $this->support->amountFromMolliePayment($payment);
        $status = $billable->getBillingSubscriptionStatus();

        if ($billable instanceof Model) {
            $meta = $billable->getBillingSubscriptionMeta();
            unset(
                $meta['pending_prorata_change'],
                $meta['pending_plan_change'],
                $meta['prorata_pending_payment_id'],
            );
            $billable->forceFill(['subscription_meta' => $meta])->save();

            // The money goes back in full below, so what a seat or addon change already
            // wrote goes back too — otherwise a subscription cancelled while its charge was
            // settling kept the seats and the addon's features through its grace period,
            // and over a resubscribe, for nothing. Only while it is Cancelled: the expiry
            // pass has wiped seats and addons from an Expired one, and restoring them would
            // hand them back to a subscription that has ended. No Mollie PATCH either way —
            // revertRecurringAmount() leaves a subscription that no longer runs alone.
            if ($status === SubscriptionStatus::Cancelled) {
                $this->restoreUnpaidChange($billable, (array) ($pending['intent'] ?? []));
            }
        }

        Log::info('Prorata charge settled for a subscription that no longer runs — not applied, returning it', [
            'payment_id' => $paymentId,
            'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
            'amount_cents' => $amountCents,
            'subscription_status' => $status->value,
        ]);

        event(new PlanChangeFailed(
            $billable,
            (array) ($pending['intent'] ?? []),
            $paymentId,
            'Subscription no longer runs ('.$status->value.') — prorata charge not applied',
        ));

        // The money is returned automatically rather than reported for a manual refund.
        $intended = (string) ($pending['intent']['new_plan'] ?? '');

        app(UnwantedPaymentRefund::class)->settle(
            $payment,
            $billable,
            UnwantedPaymentRefund::REASON_SUBSCRIPTION_ENDED,
            'Subscription change'.($intended !== '' ? ' to '.$intended : '').' — the subscription had ended before the payment arrived, not carried out',
        );
    }

    /**
     * Put back what a change committed before it was paid for — locally and at Mollie.
     *
     * A plan or interval switch defers its local write until its charge clears, but a
     * seat or addon change does not: `UpdateSubscription`'s phase 3 writes `seat_count`
     * and `active_addon_codes` straight away and reports success. When the charge is
     * then declined — with SEPA, days later — nothing undid it: the customer kept
     * three seats they had not paid for, `FeatureAccess` kept granting the add-on's
     * features, and the next renewal rebuilt its lines from the inflated count.
     *
     * The pre-change state is exactly what the stored intent holds. The validator
     * refuses a second change while one is pending, so nothing legitimate can have
     * moved these in between.
     *
     * Mollie is put back with it. A seat or addon change PATCHes the recurring amount in
     * the same call that writes the local state (UpdateSubscription, phase 2c), so that
     * the two never disagree across a renewal. Restoring only the local half would leave
     * Mollie collecting for seats the customer no longer has. A plan or interval change
     * never patched before its charge cleared — there is nothing to take back.
     *
     * Called for a declined charge ({@see failed()}), for a waiting change that is
     * withdrawn with its scheduled change
     * (`UpdateSubscription::withdrawPendingChangeForPayment()`) or by the customer or a
     * cancellation (`UpdateSubscription::tryCancelPendingPlanChange()`), and for a charge
     * that settled after the subscription was cancelled ({@see reportUnapplicableCharge()}).
     * Reaches Mollie while the subscription still runs.
     *
     * @param  array<string, mixed>  $intent  the stored `pending_prorata_change.intent`
     */
    public function restoreUnpaidChange(Billable $billable, array $intent): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        $attributes = [];

        if (array_key_exists('current_seats', $intent)) {
            $meta = $billable->getBillingSubscriptionMeta();
            $meta['seat_count'] = (int) $intent['current_seats'];
            $attributes['subscription_meta'] = $meta;
        }

        if (array_key_exists('current_addons', $intent)) {
            $attributes['active_addon_codes'] = array_values(array_keys((array) $intent['current_addons']));
        }

        if ($attributes !== []) {
            $billable->forceFill($attributes)->save();
        }

        $this->revertRecurringAmount($billable, $intent);
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

        // The separate payment for an undercollected period: an unpaid recurring amount,
        // handled by the ordinary dunning.
        if (\GraystackIT\MollieBilling\Services\Billing\UndercollectionCharge::owns($metadata)) {
            app(\GraystackIT\MollieBilling\Services\Billing\UndercollectionCharge::class)->failed($payment, $billable, $metadata);

            return;
        }

        // A payment whose creation went unanswered and that failed: nothing to take over.
        $token = (string) ($metadata['charge_token'] ?? '');
        if ($token !== '') {
            app(\GraystackIT\MollieBilling\Services\Billing\InvoiceService::class)->forgetChargeInCreation($billable, $token);
            $billable->refresh();
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

        // A payment for a change that was cancelled and replaced: the slot now belongs to
        // the newer change, whose own payment is still in flight. Wiping it here rolled
        // back the newer change's seats and addons and orphaned its payment.
        if (! $this->paymentOwnsPending($payment, (array) $pending)) {
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

        $intent = (array) ($pending['intent'] ?? []);

        $this->restoreUnpaidChange($billable, $intent);
        app(\GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange::class)->realignScheduled($billable);

        event(new PlanChangeFailed($billable, $pending, (string) $payment->id, $reason));

        $recipients = MollieBilling::notifyBillingAdmins($billable);
        if (! empty($recipients)) {
            Notification::send($recipients, MollieBilling::resolveNotification(PlanChangeFailedNotification::class, $billable, (string) $payment->id));
        }
    }
}
