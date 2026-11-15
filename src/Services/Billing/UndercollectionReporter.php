<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Events\RenewalUndercollected;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Support\BillingMoney;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Says so when a period was collected at less than it costs — and does nothing else.
 *
 * The counterpart of {@see SupersededRenewalRefund}: that one gives back what a renewal
 * collected too much, automatically, because returning money is always safe. This one
 * cannot take money the same way. It records the case (event → audit trail), tells the
 * admins the amount, and leaves the charge, the subscription status and the invoice alone.
 */
class UndercollectionReporter
{
    /**
     * Rounding slack: per-line VAT can put a renewal a cent off its expected gross, and
     * that is documented as not being a mismatch at all.
     */
    private const TOLERANCE = 1;

    /**
     * @return bool whether a shortfall was reported
     */
    public function report(
        Billable $billable,
        ?BillingInvoice $invoice,
        int $expectedNet,
        int $collectedNet,
        string $reason,
    ): bool {
        $shortfall = $expectedNet - $collectedNet;

        if ($shortfall <= self::TOLERANCE) {
            return false;
        }

        Log::warning('A period was collected at less than its configuration costs', [
            'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
            'invoice_id' => $invoice?->getKey(),
            'expected_net' => $expectedNet,
            'collected_net' => $collectedNet,
            'shortfall_net' => $shortfall,
            'reason' => $reason,
        ]);

        try {
            event(new RenewalUndercollected($billable, $invoice, $expectedNet, $collectedNet, $shortfall, $reason));
        } catch (\Throwable $e) {
            // A listener must not be able to fail the webhook that noticed this.
            Log::error('RenewalUndercollected listener failed', ['error' => $e->getMessage()]);
        }

        try {
            $admins = MollieBilling::notifyAdmin();
            $admins = is_array($admins) ? $admins : iterator_to_array($admins);

            if ($admins !== []) {
                $currency = (string) ($invoice?->currency ?? config('mollie-billing.currency', 'EUR'));

                Notification::send($admins, MollieBilling::resolveNotification(
                    AdminPlanChangeFailedNotification::class,
                    'A billing period was collected at less than its configuration costs ('
                        .BillingMoney::format($shortfall, $currency).' net short) — nothing was charged automatically',
                    [
                        'billable_type' => $billable instanceof Model ? $billable->getMorphClass() : null,
                        'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
                        'invoice_id' => $invoice?->getKey(),
                        'invoice_serial' => $invoice?->serial_number,
                        'expected_net' => $expectedNet,
                        'collected_net' => $collectedNet,
                        'shortfall_net' => $shortfall,
                        'currency' => $currency,
                        'reason' => $reason,
                    ],
                ));
            }
        } catch (\Throwable $e) {
            Log::error('Could not notify the admins of an undercollected period', ['error' => $e->getMessage()]);
        }

        return true;
    }
}
