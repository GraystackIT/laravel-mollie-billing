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

    /**
     * Snapshot key meaning "we asked Mollie to create this payment and never learned the
     * answer" — as opposed to a `payment_id: null` that means "we never got as far as asking".
     *
     * The two look identical in the snapshot and must not be treated alike. `charge()` writes
     * the snapshot, calls Mollie, then stores the id; a throw in between (a read timeout on
     * the response is the ordinary case) leaves no id although the payment may well exist.
     * Reading that as "never charged" is what let the debt be restored to the wallet and
     * charged a second time by the next nightly sweep, while the first payment settled into
     * its own invoice. So the marker is set instead, nobody charges while it is present, and
     * `CleanupStalePendingOverageChargeJob` resolves it against Mollie's own payment list —
     * the only place that knows whether the request arrived.
     */
    public const PAYMENT_UNKNOWN_KEY = 'payment_unknown_at';

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

        if (($meta['usage_overage_status'] ?? null) !== self::STATUS_PENDING) {
            return false;
        }

        // A payment whose creation we never got an answer for counts as in flight, even though
        // there is no id to name it by. Otherwise this guard reads "no id" as "no payment" and
        // the next sweep charges a debt Mollie may already be collecting.
        if ($inFlightPaymentId === '' && isset($inFlight[self::PAYMENT_UNKNOWN_KEY])) {
            Log::warning('Refusing an overage charge while a previous one may already exist at Mollie', [
                'billable' => $billable instanceof Model ? $billable->getKey() : null,
                'unknown_since' => (string) $inFlight[self::PAYMENT_UNKNOWN_KEY],
            ]);

            return true;
        }

        if ($inFlightPaymentId === '' || $this->pendingChargeIsSettled($billable)) {
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
    /**
     * Whether a charge was handed to Mollie whose outcome we never learned.
     *
     * The one question that separates "this debt was never charged" from "this debt may already
     * be being collected". Every caller that would otherwise re-derive the debt from the
     * wallets, or hand it back to them, has to ask it — see
     * {@see self::PAYMENT_UNKNOWN_KEY}.
     */
    public function paymentMayExist(Billable $billable): bool
    {
        $meta = $billable->getBillingSubscriptionMeta();

        if (($meta['usage_overage_status'] ?? null) !== self::STATUS_PENDING) {
            return false;
        }

        $snapshot = (array) ($meta[self::META_KEY] ?? []);

        return (string) ($snapshot['payment_id'] ?? '') === ''
            && isset($snapshot[self::PAYMENT_UNKNOWN_KEY]);
    }

    /**
     * Record that a create-payment request left this process without an answer.
     *
     * Kept on the snapshot rather than beside it, so it travels with the charge it describes
     * and disappears with it — `persistPendingCharge()` rewrites the whole snapshot when an id
     * finally arrives, which is exactly when the marker stops being true.
     */
    private function markPaymentUnknown(Billable $billable): void
    {
        if (! $billable instanceof Model) {
            return;
        }

        $this->mutateBillingMeta($billable, function (array $meta): ?array {
            $snapshot = (array) ($meta[self::META_KEY] ?? []);

            if ($snapshot === [] || (string) ($snapshot['payment_id'] ?? '') !== '') {
                // Either there is no snapshot to mark, or an id arrived in the meantime — a
                // sibling delivery, or the retry that succeeded. Both make the marker false.
                return null;
            }

            $snapshot[self::PAYMENT_UNKNOWN_KEY] ??= BillingTime::nowUtc()->toIso8601String();
            $meta[self::META_KEY] = $snapshot;

            return $meta;
        });
    }

    /**
     * Drop the marker because Mollie has answered: no such payment exists.
     *
     * Only `CleanupStalePendingOverageChargeJob` may call this, and only after Mollie itself
     * has listed the customer's payments — clearing it on a guess would re-open the double
     * charge the marker exists to prevent.
     */
    public function forgetUnknownPayment(Billable $billable): void
    {
        if (! $billable instanceof Model) {
            return;
        }

        $this->mutateBillingMeta($billable, function (array $meta): ?array {
            $snapshot = (array) ($meta[self::META_KEY] ?? []);

            if (! isset($snapshot[self::PAYMENT_UNKNOWN_KEY])) {
                return null;
            }

            unset($snapshot[self::PAYMENT_UNKNOWN_KEY]);
            $meta[self::META_KEY] = $snapshot;

            return $meta;
        });
    }

    /**
     * Adopt a payment Mollie turned out to hold for a snapshot that lost its id.
     *
     * The snapshot keeps its `created_at` — this is the same cycle, not a new one — so the
     * dunning budget and the counted-payments list stay intact.
     */
    public function adoptUnknownPayment(Billable $billable, string $paymentId): void
    {
        if (! $billable instanceof Model || $paymentId === '') {
            return;
        }

        $this->mutateBillingMeta($billable, function (array $meta) use ($paymentId): ?array {
            $snapshot = (array) ($meta[self::META_KEY] ?? []);

            if ($snapshot === [] || (string) ($snapshot['payment_id'] ?? '') !== '') {
                return null;
            }

            $snapshot['payment_id'] = $paymentId;
            unset($snapshot[self::PAYMENT_UNKNOWN_KEY]);
            $meta[self::META_KEY] = $snapshot;

            return $meta;
        });
    }

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

            // Debt that carries its own price goes first, and at that price: plan-change
            // excess that could not be charged when the plan changed was consumed on the
            // OLD plan. Priced from the current plan it was billed 4.5 times too high in
            // the reproduction, and not at all when the new plan has no overage price.
            foreach (WalletUsageService::getDeferredOverage($wallet) as $deferred) {
                $items[] = [
                    'kind' => 'overage',
                    'code' => $slug,
                    'type' => $slug,
                    'label' => $this->overageLabel($slug),
                    'quantity' => $deferred['quantity'],
                    'unit_price_net' => $deferred['unit_price_net'],
                    'total_net' => $deferred['quantity'] * $deferred['unit_price_net'],
                    // Tells the settlement which record these units came from.
                    'deferred_price' => true,
                ];

                $quantity -= $deferred['quantity'];
            }

            if ($quantity <= 0) {
                continue;
            }

            // What is left is ordinary period overage, priced by the plan the billable is on.
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

        try {
            $payment = $this->createMolliePayment($billable, $vat, $lineItems, $this->idempotencyKeyFor($billable, $vat));
        } catch (\Throwable $e) {
            // The request left this process. Whether Mollie created the payment is now unknown
            // — and "unknown" must not be read as "no", because the next sweep would charge the
            // same debt again while the first payment settles into its own invoice. The marker
            // freezes the debt for `CleanupStalePendingOverageChargeJob` to reconcile against
            // Mollie's payment list, and the throw still reaches the caller so the retry ladder
            // runs (inside Mollie's one-hour idempotency window it is answered with the
            // original payment rather than a second one).
            $this->markPaymentUnknown($billable);

            throw $e;
        }

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
    /**
     * A key that identifies *this debt in this dunning cycle*, so a retry cannot buy a
     * second payment for it.
     *
     * The failure it exists for: `createMolliePayment()` throws after Mollie already created
     * the payment — a read timeout on the response is the ordinary case — so no payment id is
     * ever stored, and `RetryUsageOverageChargeJob` comes back 60 seconds later and charges
     * the same debt again. Both payments settle, each into its own invoice under the webhook's
     * per-payment idempotency, and the customer paid twice. With a stable key Mollie replays
     * the first response instead, and the retry learns the payment id it lost.
     *
     * What goes into it, and why each part:
     *
     *  - the snapshot's `created_at` is the *cycle* id. `persistPendingCharge()` inherits it
     *    while a cycle continues and only mints a new one after a cycle has failed, so p1 → p2
     *    → p3 of one cycle share a key and the next cycle's legitimate charge does not;
     *  - the gross amount, because Mollie answers 400 to a reused key whose parameters
     *    differ. If more usage accrued between two attempts the amount moves, and this makes
     *    that a new key rather than a rejected request;
     *  - the billable, so two customers can never collide.
     *
     * Mollie caches a key for **one hour**. That covers this ladder — `backoff()` is
     * `[60, 300, 900]`, so all three retries land inside ~21 minutes — and deliberately not
     * the nightly sweep, which is a day later and a genuinely new decision.
     *
     * @param  array{net:int,vat:int,gross:int,rate:float}  $vat
     */
    private function idempotencyKeyFor(Billable $billable, array $vat): ?string
    {
        if (! $billable instanceof Model) {
            return null;
        }

        $cycleStartedAt = (string) ($this->pendingCharge($billable)['created_at'] ?? '');

        if ($cycleStartedAt === '') {
            // No snapshot to key off. Better no key than an unstable one: a key that changes
            // per attempt buys nothing, and one that repeats across different charges would
            // suppress a legitimate second payment.
            return null;
        }

        // Readable rather than hashed, like `RefundInvoiceService`'s: when support has to ask
        // Mollie what happened to a key, the key should say what it was for.
        return sprintf(
            'usage-overage:%s:%s:%s:%d',
            $billable->getMorphClass(),
            (string) $billable->getKey(),
            $cycleStartedAt,
            (int) $vat['gross'],
        );
    }

    private function createMolliePayment(Billable $billable, array $vat, array $lineItems, ?string $idempotencyKey = null): object
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
        ), $idempotencyKey);
    }
}
