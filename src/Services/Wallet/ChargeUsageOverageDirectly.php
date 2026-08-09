<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Wallet;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use GraystackIT\MollieBilling\Support\MollieCall;

class ChargeUsageOverageDirectly
{
    use MutatesBillingMeta;

    /**
     * `subscription_meta.usage_overage_status` value meaning "a charge exists that
     * has not been confirmed yet". The daily PrepareUsageOverageJob sweep looks
     * for exactly this value.
     */
    public const STATUS_PENDING = 'pending';

    /**
     * `usage_overage_status` value meaning "the dunning budget for this charge is
     * spent". Set by RetryUsageOverageChargeJob when it gives up; the snapshot is
     * deliberately kept so the amount stays auditable, which is why the next cycle
     * has to recognise it as finished rather than inherit it.
     */
    public const STATUS_FAILED = 'failed';

    /** `subscription_meta` key holding the snapshot of the charge in flight. */
    public const META_KEY = 'usage_overage';

    /**
     * `subscription_meta` key under which the dunning budget records which payments
     * of the CURRENT cycle it has already counted.
     */
    public const COUNTED_PAYMENTS_KEY = 'usage_overage_counted_payments';

    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
        private readonly VatCalculationService $vatService,
        private readonly InvoiceService $invoiceService,
    ) {
    }

    /**
     * Iterate the billable's wallets, build line items from any negative
     * balances, and create a Mollie payment for the gross amount. The
     * resulting BillingInvoice is created later, in the webhook.
     *
     * The result always says which of the three possible things happened —
     * "nothing owed" and "payment created" used to be indistinguishable.
     */
    public function handle(Billable $billable): UsageOverageChargeResult
    {
        // The wallets stay negative until the charge that covers them settles — with
        // SEPA that is days, not seconds — so rebuilding line items from them while a
        // payment is in flight bills the same debt again. `safeCharge()` and
        // `handleExplicit()` both guarded that; this entry point did not, and it is the
        // one an immediate cancellation goes through: the nightly sweep created the
        // period-end payment, the customer cancelled the next morning, and Mollie
        // collected both. The webhook cannot absorb it either — its idempotency is per
        // payment id, so each settles into its own invoice and credits the units again.
        if ($this->anotherChargeIsInFlight($billable)) {
            return UsageOverageChargeResult::nothingToCharge();
        }

        return $this->chargeFromWallets($billable);
    }

    /**
     * Same as {@see handle()} but with caller-supplied line items. Used by the
     * plan-change path (WalletPlanChangeAdjuster) where the wallets have already
     * been rebalanced, so their balances no longer describe what is owed.
     *
     * @param  array<int, array{type:string,quantity:int,unit_price_net:int,total_net:int}>  $lineItems
     */
    public function handleExplicit(Billable $billable, array $lineItems): UsageOverageChargeResult
    {
        if ($lineItems === []) {
            return UsageOverageChargeResult::nothingToCharge();
        }

        // `subscription_meta.usage_overage` is a single slot, and this path had no in-flight
        // guard while `safeCharge()` does. A plan-change charge therefore overwrote a
        // period-end charge that was still settling — inheriting its `created_at`, so its
        // dunning cycle and its counted-payments list too. `RetryUsageOverageChargeJob`
        // resolves the snapshot rather than a payment id, so a failure webhook for the
        // overwritten payment then acted on the new one: the *new* line items were charged a
        // second time while the overwritten charge's units were never recovered and were
        // invisible even to the stale-snapshot sweep, which only polls the id in the slot.
        if ($this->anotherChargeIsInFlight($billable)) {
            // Not an exception: the plan change itself is legitimate and must go through. The
            // excess stays on the wallet as a negative balance, which the next period-end
            // sweep charges once the slot is free.
            return UsageOverageChargeResult::nothingToCharge();
        }

        return $this->charge($billable, $lineItems);
    }

    /**
     * Whether a previous overage charge is still waiting for its webhook.
     *
     * `subscription_meta.usage_overage` is a single slot, so a second charge does not
     * merely duplicate the money — it overwrites the first one's snapshot, inheriting
     * its `created_at`, its dunning cycle and its counted-payments list, and leaving the
     * overwritten payment invisible to `CleanupStalePendingOverageChargeJob`, which only
     * ever polls the id currently in the slot.
     */
    private function anotherChargeIsInFlight(Billable $billable): bool
    {
        $meta = $billable->getBillingSubscriptionMeta();
        $inFlight = (array) ($meta[self::META_KEY] ?? []);
        $inFlightPaymentId = (string) ($inFlight['payment_id'] ?? '');

        if ($inFlightPaymentId === ''
            || ($meta['usage_overage_status'] ?? null) !== self::STATUS_PENDING
            || $this->pendingChargeIsSettled($billable)
        ) {
            return false;
        }

        Log::warning('Refusing an overage charge while another is still in flight', [
            'billable' => $billable instanceof Model ? $billable->getKey() : null,
            'in_flight_payment_id' => $inFlightPaymentId,
        ]);

        return true;
    }

    /**
     * Charge whatever the wallets currently owe, without the in-flight guard.
     *
     * Only the retry path may use this: it is driven by the snapshot of the charge
     * that is in flight, so refusing on account of that same snapshot would make the
     * retry a no-op forever.
     */
    private function chargeFromWallets(Billable $billable): UsageOverageChargeResult
    {
        $lineItems = $this->buildLineItemsFromWallets($billable);

        if ($lineItems === []) {
            return UsageOverageChargeResult::nothingToCharge();
        }

        return $this->charge($billable, $lineItems);
    }

    /**
     * Retry an overage charge without consulting the wallets.
     *
     * This is the entry point retries must use. Rebuilding from wallet balances
     * cannot work after `handleExplicit()`: the plan-change path zeroes the
     * wallets *before* charging, so a retry would find nothing owed and report
     * success while the money was never collected. The persisted snapshot is the
     * source of truth, and a snapshot whose payment already produced an invoice
     * is reported as settled instead of charged a second time.
     */
    public function retry(Billable $billable): UsageOverageChargeResult
    {
        $pending = $this->pendingCharge($billable);

        if ($pending !== null && $this->pendingChargeIsSettled($billable)) {
            return UsageOverageChargeResult::alreadySettled(
                $pending['payment_id'] ?? null,
                (array) ($pending['line_items'] ?? []),
            );
        }

        $lineItems = (array) ($pending['line_items'] ?? []);

        if ($lineItems !== []) {
            return $this->charge($billable, $lineItems);
        }

        return $this->chargeFromWallets($billable);
    }

    /**
     * The snapshot of the overage charge in flight, or null when there is none.
     *
     * Exposed so the retry job and the daily sweep can inspect `payment_id`
     * before creating another payment.
     *
     * @return array<string, mixed>|null
     */
    public function pendingCharge(Billable $billable): ?array
    {
        if (! $billable instanceof Model) {
            return null;
        }

        $pending = $billable->getBillingSubscriptionMeta()[self::META_KEY] ?? null;

        return is_array($pending) && $pending !== [] ? $pending : null;
    }

    /**
     * Whether the pending charge's Mollie payment already produced an invoice.
     *
     * `SingleChargeHandler::paid()` normally clears the whole snapshot, so this
     * is the safety net for the case where the webhook created the invoice but
     * died before clearing it — without the check, the next retry pass would
     * charge the customer twice for the same overage.
     */
    public function pendingChargeIsSettled(Billable $billable): bool
    {
        $paymentId = (string) ($this->pendingCharge($billable)['payment_id'] ?? '');

        if ($paymentId === '') {
            return false;
        }

        return BillingInvoice::query()->where('mollie_payment_id', $paymentId)->exists();
    }

    /**
     * @return array<int, array{type:string,quantity:int,unit_price_net:int,total_net:int}>
     */
    private function buildLineItemsFromWallets(Billable $billable): array
    {
        if (! $billable instanceof Model) {
            return [];
        }

        $items = [];
        $planCode = $billable->getBillingSubscriptionPlanCode() ?? '';
        $interval = $billable->getBillingSubscriptionInterval();

        // Force a fresh fetch — wallets is a MorphMany.
        $wallets = $billable->wallets()->get();

        foreach ($wallets as $wallet) {
            $balance = (int) $wallet->balanceInt;

            if ($balance >= 0) {
                continue;
            }

            $slug = (string) $wallet->slug;
            $quantity = abs($balance);
            $unitPrice = (int) ($this->catalog->usageOveragePrice($planCode, $interval, $slug) ?? 0);

            if ($unitPrice <= 0) {
                continue;
            }

            $items[] = [
                'kind' => 'overage',
                'code' => $slug,
                'type' => $slug,
                'label' => $this->overageLabel($slug),
                'quantity' => $quantity,
                'unit_price_net' => $unitPrice,
                'total_net' => $quantity * $unitPrice,
            ];
        }

        return $items;
    }

    /**
     * Localised, human-readable overage line label, e.g. "Mehrverbrauch: SMS".
     * Quantity is intentionally not included — it is rendered separately in the
     * invoice's "Menge" column.
     */
    private function overageLabel(string $usageType): string
    {
        return (string) __('billing::portal.invoice_line_overage', [
            'type' => $this->catalog->usageTypeName($usageType),
        ]);
    }

    /**
     * @param  array<int, array{type:string,quantity:int,unit_price_net:int,total_net:int}>  $lineItems
     */
    private function charge(Billable $billable, array $lineItems): UsageOverageChargeResult
    {
        $totalNet = array_sum(array_column($lineItems, 'total_net'));

        if ($totalNet <= 0) {
            return UsageOverageChargeResult::nothingToCharge();
        }

        // Shared fallback with checkout and the webhook: the country decides the
        // rate actually charged, so all sites must agree on it. Not
        // `default_billing_country`, which only pre-selects a dropdown.
        $country = $billable->getBillingCountry()
            ?? (string) config('mollie-billing.checkout_country_fallback', 'DE');

        $vat = $this->vatService->calculate($country, $totalNet, $billable);

        // The invoice the webhook writes for this payment derives its VAT from the
        // line items, one wallet at a time, so the amount we collect has to be built
        // the same way — see BillingMoney::grossWithLineVat(). With one line per
        // metered usage type the rounding residuals add up fast enough to trip
        // reconcileWithPaidAmount() on an otherwise perfectly ordinary charge. The
        // snapshot carries the same numbers, so a retry re-charges what was booked.
        $gross = BillingMoney::grossWithLineVat(
            array_map(static fn (array $line): int => (int) ($line['total_net'] ?? 0), $lineItems),
            (float) $vat['rate'],
        );
        $vat = [...$vat, 'vat' => $gross - $totalNet, 'gross' => $gross];

        // Persist the snapshot BEFORE talking to Mollie and mark the charge
        // pending. If the API call fails the amount owed is still on record, so
        // the daily sweep can pick it up instead of the charge evaporating with
        // the wallet balances it was derived from.
        $this->persistPendingCharge($billable, null, $lineItems, $vat);

        $payment = $this->createMolliePayment($billable, $vat, $lineItems);

        $paymentId = is_object($payment) ? ($payment->id ?? null) : null;
        $this->persistPendingCharge($billable, is_string($paymentId) ? $paymentId : null, $lineItems, $vat);

        // The actual BillingInvoice is created in the webhook handler when
        // the payment moves to "paid" state, so it cannot be returned here.
        return UsageOverageChargeResult::charged(
            is_string($paymentId) ? $paymentId : null,
            $lineItems,
            (int) $vat['net'],
            (int) $vat['gross'],
        );
    }

    /**
     * Write the pending overage onto `subscription_meta` so the webhook can create
     * the BillingInvoice when the payment confirms, and so a retry can rebuild the
     * exact same line items without the wallets.
     *
     * @param  array<int, array<string, mixed>>  $lineItems
     * @param  array{net:int,vat:int,gross:int,rate:float}  $vat
     */
    private function persistPendingCharge(Billable $billable, ?string $paymentId, array $lineItems, array $vat): void
    {
        if (! $billable instanceof Model) {
            return;
        }

        // Through the locked mutator: the read below used to be a plain in-memory
        // attribute read followed, after a Mollie round-trip, by a `forceFill` of the whole
        // JSON column — so a renewal webhook or a retry job that committed a different key
        // in between had its write reverted. `pending_refund_retries` is one of those keys,
        // and a resurrected refund line is a second payout to the customer.
        $this->mutateBillingMeta($billable, function (array $meta) use ($paymentId, $lineItems, $vat): array {
            $existing = (array) ($meta[self::META_KEY] ?? []);

            // A snapshot left behind by a cycle that gave up is a spent cycle, not this
            // one. Inheriting its `created_at` kept the cycle key identical, so
            // `usage_overage_counted_payments` still listed the three payments that had
            // already failed — and the new period's FIRST failure appended a fourth,
            // exceeded the budget and went straight to past_due with customer and admin
            // dunning mail, no retry ladder at all. Unsetting `usage_overage_attempts`
            // (below) never helped: the budget is derived from that id list, and the list
            // was still considered current.
            //
            // Within one cycle the inheritance is exactly right — p1 → p2 → p3 must keep
            // accumulating — so this only fires when the previous cycle is finished.
            if (($meta['usage_overage_status'] ?? null) === self::STATUS_FAILED) {
                $existing = [];
                unset($meta[self::COUNTED_PAYMENTS_KEY], $meta['usage_overage_attempts']);
            }

            $meta[self::META_KEY] = [
                'payment_id' => $paymentId ?? ($existing['payment_id'] ?? null),
                'line_items' => $lineItems,
                'amount_net' => $vat['net'],
                'amount_gross' => $vat['gross'],
                'vat_amount' => $vat['vat'],
                'vat_rate' => $vat['rate'],
                'currency' => (string) config('mollie-billing.currency', 'EUR'),
                'created_at' => $existing['created_at'] ?? BillingTime::nowUtc()->toIso8601String(),
            ];
            $meta['usage_overage_status'] = self::STATUS_PENDING;

            // A new payment starts a new dunning cycle. The retry job leaves the counter at
            // its maximum when it gives up, so without this reset the next period's first
            // failure already exceeded the budget: PastDue plus customer and admin dunning
            // after a single attempt, with no retry ladder ever again for that billable.
            if ($paymentId !== null && $paymentId !== ($existing['payment_id'] ?? null)) {
                unset($meta['usage_overage_attempts']);
            }

            return $meta;
        });
    }

    /**
     * Create a one-off Mollie payment for the overage gross amount.
     *
     * Uses Mollie\Laravel\Facades\Mollie if available; otherwise expects a
     * `mollie.api`/`Mollie\Api\MollieApiClient` binding in the container.
     *
     * @param  array{net:int,vat:int,gross:int,rate:float}  $vat
     * @param  array<int, array<string, mixed>>  $lineItems
     */
    private function createMolliePayment(Billable $billable, array $vat, array $lineItems): object
    {
        $currency = (string) config('mollie-billing.currency', 'EUR');
        $amountValue = BillingMoney::mollieAmount((int) $vat['gross'], $currency);

        return MollieCall::send(new CreatePaymentRequest(
            description: 'Usage overage',
            amount: new Money($currency, $amountValue),
            metadata: [
                'type' => 'overage',
                'billable_type' => $billable instanceof Model ? $billable->getMorphClass() : null,
                'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
                'line_items' => $lineItems,
            ],
            sequenceType: 'recurring',
            mandateId: $billable->getMollieMandateId(),
            customerId: $billable->getMollieCustomerId(),
            webhookUrl: route(BillingRoute::webhook()),
        ));
    }
}
