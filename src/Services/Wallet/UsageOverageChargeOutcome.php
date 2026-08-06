<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Wallet;

/**
 * What {@see ChargeUsageOverageDirectly} actually did.
 *
 * `handle()` used to answer `null` both for "payment created" and for "nothing
 * to charge", which is why a failed overage payment could be swallowed by the
 * retry path: it rebuilt the line items from the (already rebalanced) wallets,
 * got nothing, and reported success.
 */
enum UsageOverageChargeOutcome: string
{
    /** No negative balance and no pending snapshot — there was nothing to bill. */
    case NothingToCharge = 'nothing_to_charge';

    /** A Mollie payment was created. The BillingInvoice follows via the webhook. */
    case Charged = 'charged';

    /** The pending charge's payment already produced an invoice; re-charging would double-bill. */
    case AlreadySettled = 'already_settled';
}
