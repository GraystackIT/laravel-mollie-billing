<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Gives back what a renewal collected for a configuration the customer is no longer on.
 *
 * Mollie creates a recurring payment with the amount — and the metadata — the
 * subscription holds at that moment, and a SEPA debit then travels for days. A change
 * applied in between (a scheduled downgrade at the period end, a downgrade to the free
 * plan, an interval switch) cannot reach that payment any more: it settles at the OLD
 * amount for a period in which the NEW configuration applies. The renewal handler books
 * such a payment to what was paid, with an `adjustment` line and a
 * `PaymentAmountMismatch` event — and the customer simply kept paying the old price for
 * one more period.
 *
 * The payment's own metadata is what makes this decidable. It names the plan, interval,
 * seats and addons the payment was created FOR; when that differs from what the billable
 * is on and more was collected than the current configuration costs, the surplus was
 * charged for something the customer does not have.
 *
 * Deliberately one-sided. A renewal that collected LESS than the current configuration
 * costs is not topped up here — charging a customer from inside a webhook is a different
 * decision from giving money back — and a mismatch whose metadata agrees with the local
 * state is not a superseded renewal at all (a failed PATCH, a coupon), so it stays with
 * the existing event.
 */
class SupersededRenewalRefund
{
    public function __construct(
        private readonly RefundInvoiceService $refunds,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata  the Mollie payment's metadata
     * @return bool true when the payment bought nothing at all — the caller must not
     *              book it as the start of a new period
     */
    public function settle(Billable $billable, BillingInvoice $invoice, array $metadata): bool
    {
        if (! ($billable instanceof Model)) {
            return false;
        }

        $createdForPlan = (string) ($metadata['plan_code'] ?? '');
        $createdForInterval = (string) ($metadata['interval'] ?? '');

        // Without the payment's own description of what it was created for there is
        // nothing to compare against, and guessing would refund legitimate renewals.
        if ($createdForPlan === '' || $createdForInterval === '') {
            return false;
        }

        $plan = (string) ($billable->getBillingSubscriptionPlanCode() ?? '');
        $interval = (string) ($billable->getBillingSubscriptionInterval() ?? '');

        $componentsNet = 0;
        $surplusNet = 0;

        foreach ((array) ($invoice->line_items ?? []) as $line) {
            $net = (int) ($line['amount_net'] ?? $line['total_net'] ?? 0);

            if (($line['kind'] ?? null) === 'adjustment') {
                $surplusNet += $net;
            } else {
                $componentsNet += $net;
            }
        }

        // The whole payment is for nothing: the subscription it belongs to was replaced
        // by a Local one, moved to another cadence (whose own charge pays for these days),
        // or the configuration now bills nothing.
        $boughtNothing = $billable->getBillingSubscriptionSource() !== SubscriptionSource::Mollie->value
            || ($interval !== '' && $createdForInterval !== $interval)
            || ($componentsNet <= 0 && $surplusNet > 0 && $this->describesAnotherConfiguration($billable, $metadata, $plan));

        if ($boughtNothing) {
            if ($invoice->remainingRefundableNet() > 0) {
                $this->refund($billable, $invoice, null, sprintf(
                    'Renewal collected for %s/%s after the subscription had moved to %s/%s',
                    $createdForPlan, $createdForInterval, $plan !== '' ? $plan : '-', $interval !== '' ? $interval : '-',
                ));
            }

            return true;
        }

        $supersededConfiguration = $this->describesAnotherConfiguration($billable, $metadata, $plan);

        // The other direction. The payment was created for a configuration that cost
        // LESS than the one the billable is on now — a seat or addon booked after Mollie
        // had already created this debit. The difference is collected as its own payment,
        // exactly once ({@see UndercollectionCharge}). Only when the
        // payment itself says it was priced for something else: a shortfall with matching
        // metadata is a discount or a PATCH that did not land, and stays with
        // `PaymentAmountMismatch`.
        if ($surplusNet < 0 && $supersededConfiguration) {
            $components = $this->componentsAddedSince($billable, $invoice, $metadata, $plan, $interval);

            $this->nameWhatTheAdjustmentReduces($invoice, $components, -$surplusNet);

            app(UndercollectionCharge::class)->settle(
                $billable,
                $invoice,
                $componentsNet,
                $componentsNet + $surplusNet,
                \GraystackIT\MollieBilling\Events\RenewalUndercollected::REASON_RENEWAL_CREATED_BEFORE_CHANGE,
                components: $components,
            );

            return false;
        }

        if ($surplusNet <= 0 || ! $supersededConfiguration) {
            return false;
        }

        $this->refund($billable, $invoice, min($surplusNet, $invoice->remainingRefundableNet()), sprintf(
            'Renewal collected at the price of %s after the change to %s',
            $createdForPlan, $plan,
        ));

        return false;
    }

    /**
     * What the billable holds beyond what the payment was created for, at the list
     * amounts the renewal's own lines state: the seats and addons that were booked after
     * Mollie had prepared this debit. This is what the debit is short of.
     *
     * @param  array<string, mixed>  $metadata
     * @return list<array{kind: string, code: ?string, quantity: int, net: int, label: string}>
     */
    private function componentsAddedSince(Billable $billable, BillingInvoice $invoice, array $metadata, string $plan, string $interval): array
    {
        $catalog = app(\GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface::class);

        $extraNow = (int) $billable->getExtraBillingSeats();
        $extraThen = array_key_exists('extra_seats', $metadata) ? (int) $metadata['extra_seats'] : $extraNow;
        $addonsNow = array_values(array_map('strval', $billable->getActiveBillingAddonCodes()));
        $addonsThen = array_key_exists('addon_codes', $metadata)
            ? array_values(array_map('strval', (array) $metadata['addon_codes']))
            : $addonsNow;
        $seatsThen = $catalog->includedSeats($plan) + $extraThen;

        $components = [];

        foreach ((array) ($invoice->line_items ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }

            $kind = (string) ($line['kind'] ?? '');
            $kind = $kind === 'seat' ? 'seats' : $kind;
            $code = isset($line['code']) ? (string) $line['code'] : null;
            $net = (int) ($line['amount_net'] ?? $line['total_net'] ?? 0);
            $quantity = max(1, (int) ($line['quantity'] ?? 1));

            if ($net <= 0) {
                continue;
            }

            // How many of the line's units the payment did not know about.
            $added = match (true) {
                $kind === 'seats' => $extraNow - $extraThen,
                $kind === 'addon' && $code !== null => in_array($code, $addonsThen, true) ? 0 : $quantity,
                $kind === 'addon_seats' && $code !== null => in_array($code, $addonsThen, true)
                    ? $quantity - \GraystackIT\MollieBilling\Support\SubscriptionAmount::addonChargedSeats($catalog, $code, $plan, $interval, $seatsThen)
                    : $quantity,
                default => 0,
            };

            $added = min($quantity, $added);

            if ($added <= 0) {
                continue;
            }

            $components[] = [
                'kind' => $kind,
                'code' => $code,
                'quantity' => $added,
                'net' => (int) round($net * $added / $quantity),
                'label' => (string) ($line['label'] ?? $kind),
            ];
        }

        return $components;
    }

    /**
     * Say on the renewal which of its lines the `adjustment` takes its amount off.
     *
     * The renewal is booked with the lines of the configuration the billable is on and
     * one `adjustment` down to what Mollie collected. Read without more, that reduction
     * spreads over every line — the plan counts as partly unpaid and the added seat as
     * mostly paid — although the truth is the opposite: the payment covered the plan in
     * full and the added seat not at all. `reduces` (line key => net) puts each cent
     * where it belongs; `ProrataComposer::paidLineNet()` reads it. The amounts are the
     * same split the balance is invoiced in ({@see UndercollectionCharge::split()}), so
     * a renewal line and its balance line add up to the component's price.
     *
     * Document metadata only: no amount, total or printed line changes.
     *
     * @param  list<array{kind: string, code: ?string, quantity: int, net: int, label: string}>  $components
     */
    private function nameWhatTheAdjustmentReduces(BillingInvoice $invoice, array $components, int $shortfall): void
    {
        if ($components === [] || $shortfall <= 0) {
            return;
        }

        $lines = (array) ($invoice->line_items ?? []);

        foreach ($lines as $index => $line) {
            if (! is_array($line)
                || ($line['kind'] ?? null) !== 'adjustment'
                || (int) ($line['amount_net'] ?? $line['total_net'] ?? 0) >= 0
            ) {
                continue;
            }

            if (isset($line['reduces'])) {
                return;
            }

            $reduces = [];

            foreach (UndercollectionCharge::split($shortfall, $components) as $i => $net) {
                if ($net > 0) {
                    $key = ProrataComposer::lineKey($components[$i]);
                    $reduces[$key] = ($reduces[$key] ?? 0) + $net;
                }
            }

            $lines[$index]['reduces'] = $reduces;
            $invoice->forceFill(['line_items' => $lines])->save();

            return;
        }
    }

    /**
     * Give back part of what a renewal collected, for a reason the caller states.
     *
     * For `ProrataChargeHandler`: a downgrade whose prorata payment was still pending
     * when the period renewed leaves the customer on the cheaper plan in a period paid
     * at the dearer one's price.
     */
    public function refundOverpaid(Billable $billable, BillingInvoice $invoice, int $amountNet, string $reasonText): void
    {
        $this->refund($billable, $invoice, min($amountNet, $invoice->remainingRefundableNet()), $reasonText);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function describesAnotherConfiguration(Billable $billable, array $metadata, string $plan): bool
    {
        if ((string) $metadata['plan_code'] !== $plan) {
            return true;
        }

        if (array_key_exists('extra_seats', $metadata)
            && (int) $metadata['extra_seats'] !== $billable->getExtraBillingSeats()
        ) {
            return true;
        }

        if (array_key_exists('addon_codes', $metadata)) {
            $createdFor = array_values(array_map('strval', (array) $metadata['addon_codes']));
            $live = array_values($billable->getActiveBillingAddonCodes());
            sort($createdFor);
            sort($live);

            return $createdFor !== $live;
        }

        return false;
    }

    private function refund(Billable $billable, BillingInvoice $invoice, ?int $amountNet, string $reasonText): void
    {
        if ($amountNet !== null && $amountNet <= 0) {
            return;
        }

        try {
            $amountNet === null
                ? $this->refunds->refundFully($invoice, RefundReasonCode::PlanDowngrade, $reasonText)
                : $this->refunds->refundPartially($invoice, $amountNet, RefundReasonCode::PlanDowngrade, $reasonText);
        } catch (\Throwable $e) {
            // The renewal is booked either way; a refund that could not be posted needs
            // a human, not a 500 that makes Mollie re-deliver a payment already invoiced.
            Log::error('Superseded renewal could not be refunded automatically', [
                'billable' => $billable instanceof Model ? $billable->getKey() : null,
                'invoice_id' => $invoice->getKey(),
                'amount_net' => $amountNet,
                'error' => $e->getMessage(),
            ]);

            try {
                $admins = MollieBilling::notifyAdmin();
                $admins = is_array($admins) ? $admins : iterator_to_array($admins);

                if ($admins !== []) {
                    Notification::send($admins, MollieBilling::resolveNotification(
                        AdminPlanChangeFailedNotification::class,
                        'A renewal was collected for a configuration the customer has left and could not be refunded automatically',
                        [
                            'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
                            'invoice_id' => $invoice->getKey(),
                            'amount_net' => $amountNet,
                            'reason' => $reasonText,
                            'error' => $e->getMessage(),
                        ],
                    ));
                }
            } catch (\Throwable) {
                // Announcing a failure must never be what fails the webhook.
            }
        }
    }
}
