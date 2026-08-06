<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Wallet;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Database\Eloquent\Model;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Laravel\Facades\Mollie;

class ChargeUsageOverageDirectly
{
    /**
     * `subscription_meta.usage_overage_status` value meaning "a charge exists that
     * has not been confirmed yet". The daily PrepareUsageOverageJob sweep looks
     * for exactly this value.
     */
    public const STATUS_PENDING = 'pending';

    /** `subscription_meta` key holding the snapshot of the charge in flight. */
    public const META_KEY = 'usage_overage';

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
        $lineItems = $this->buildLineItemsFromWallets($billable);

        if ($lineItems === []) {
            return UsageOverageChargeResult::nothingToCharge();
        }

        return $this->charge($billable, $lineItems);
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

        return $this->handle($billable);
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

        $meta = $billable->getBillingSubscriptionMeta();
        $existing = (array) ($meta[self::META_KEY] ?? []);

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

        $billable->forceFill(['subscription_meta' => $meta])->save();
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
        $amountValue = number_format($vat['gross'] / 100, 2, '.', '');

        return Mollie::send(new CreatePaymentRequest(
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
