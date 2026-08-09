<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Webhook\RefundHandler;
use GraystackIT\MollieBilling\Services\Webhook\SingleChargeHandler;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
    config()->set('mollie-billing.invoices.disk', 'local');
    config()->set('mollie-billing.checkout_country_fallback', 'DE');

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => ['monthly' => [
            'base_price_net' => 1000,
            'seat_price_net' => null,
            'included_usages' => ['SMS' => 100],
            'usage_overage_prices' => ['SMS' => 2],
        ]],
    ]);
});

function robustBillable(?string $country = 'AT'): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Robust Co', 'email' => uniqid('rb-').'@x.test']);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
        'billing_country' => $country,
        'mollie_customer_id' => 'cst_rb',
        'mollie_mandate_id' => 'mdt_rb',
        'subscription_meta' => ['mollie_subscription_id' => 'sub_rb'],
    ])->save();

    return $billable->refresh();
}

it('invoices an overage charge for a billable that has no billing country', function (): void {
    // Nothing forbids a null country — the column is nullable and a programmatically
    // provisioned or imported billable simply may not have one. The charge side already
    // fell back to `checkout_country_fallback`; the invoicing side read the empty column,
    // the VAT calculator refused it and the webhook 500ed, so Mollie re-delivered the same
    // collected payment forever: money taken, no invoice, no wallet settlement.
    $billable = robustBillable(country: null);
    $billable->createWallet(['name' => 'SMS', 'slug' => 'SMS'])->forceWithdraw(50);

    app(SingleChargeHandler::class)->paid((object) [
        'id' => 'tr_nocountry',
        'status' => 'paid',
        'amount' => (object) ['value' => '1.19', 'currency' => 'EUR'],
        'paidAt' => BillingTime::nowUtc()->toIso8601String(),
    ], $billable->refresh(), 'overage', [
        'line_items' => [[
            'kind' => 'overage',
            'code' => 'SMS',
            'type' => 'SMS',
            'label' => 'Overage: SMS',
            'quantity' => 50,
            'unit_price_net' => 2,
            'total_net' => 100,
        ]],
    ]);

    expect(BillingInvoice::query()->where('mollie_payment_id', 'tr_nocountry')->count())->toBe(1)
        // And the country the charge was priced with is now a fact about the billable,
        // so the next charge and the next invoice cannot disagree either.
        ->and($billable->refresh()->getBillingCountry())->toBe('DE');
});

it('never credits more than the invoice is worth across several refunds in one delivery', function (): void {
    // A guard, not a fix: an audit claimed the second refund of one delivery measured
    // itself against a memoised credit-note snapshot taken before the first one existed.
    // It does not — `RefundHandler` carries `refunded_net` forward inside the loop and
    // `effectiveRefundedNet()` takes the max of that column and the credit-note sum, so
    // the stale memo is compensated. This pins that, because the reasoning is subtle
    // enough that someone will re-report it.
    $billable = robustBillable();

    BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_multi',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 1000,
        'amount_vat' => 200,
        'amount_gross' => 1200,
        'serial_number' => 'IN-multi',
        'line_items' => [[
            'kind' => 'plan',
            'code' => 'pro',
            'label' => 'Pro',
            'quantity' => 1,
            'unit_price_net' => 1000,
            'amount_net' => 1000,
            'vat_rate' => 20.0,
            'vat_amount' => 200,
            'amount_gross' => 1200,
        ]],
    ]);

    // Two refunds in one delivery whose sum EXCEEDS the invoice: 6.00 + 9.00 gross is
    // 12.50 net against 10.00 refundable. The second one has to be clamped against what
    // the first one already credited — which is only visible if the invoice is re-read.
    $payment = new class
    {
        public string $id = 'tr_multi';

        public string $status = 'paid';

        public object $amount;

        public object $amountRefunded;

        public function __construct()
        {
            $this->amount = (object) ['value' => '12.00', 'currency' => 'EUR'];
            $this->amountRefunded = (object) ['value' => '15.00', 'currency' => 'EUR'];
        }

        /** @return array<int, object> */
        public function refunds(): array
        {
            return [
                (object) ['id' => 're_1', 'amount' => (object) ['value' => '6.00', 'currency' => 'EUR']],
                (object) ['id' => 're_2', 'amount' => (object) ['value' => '9.00', 'currency' => 'EUR']],
            ];
        }
    };

    app(RefundHandler::class)->handle($payment, $billable);

    $original = BillingInvoice::query()->where('mollie_payment_id', 'tr_multi')->firstOrFail();

    // Never more than the invoice is worth.
    expect((int) $original->refunded_net)->toBe(1000);
});
