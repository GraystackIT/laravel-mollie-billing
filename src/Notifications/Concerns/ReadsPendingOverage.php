<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Notifications\Concerns;

use GraystackIT\MollieBilling\Contracts\Billable;

/**
 * Resolves the amount of the overage charge a notification reports on.
 *
 * A failed overage charge never produced a BillingInvoice — the invoice is only
 * written once the payment confirms. The pending amount lives on
 * `subscription_meta['usage_overage']`, put there by ChargeUsageOverageDirectly
 * right before the Mollie payment is created; that is the only correct source.
 * Reading `latestBillingInvoice()` instead reports an unrelated document.
 *
 * Call from the constructor: the notifications are queued, and Laravel restores
 * their Billable from the database when the mail job runs, by which point the
 * meta entry may already have been cleared or replaced.
 */
trait ReadsPendingOverage
{
    /**
     * @return array{0: ?int, 1: ?string} gross amount in cents and its currency
     */
    protected function pendingOverageAmount(Billable $billable): array
    {
        $overage = $billable->getBillingSubscriptionMeta()['usage_overage'] ?? null;

        if (! is_array($overage) || ! isset($overage['amount_gross'])) {
            return [null, null];
        }

        $currency = $overage['currency'] ?? null;

        return [
            (int) $overage['amount_gross'],
            is_string($currency) && $currency !== '' ? $currency : null,
        ];
    }
}
