<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Storage;
use Mollie\Laravel\Facades\Mollie;

// The coupon line on a prorata change took its VAT rate from a line with `kind === 'plan'`.
// A seat-only or addon-only change emits no such line, so the rate stayed 0.0: the discount
// was booked without VAT while the lines it offsets carried the country's rate, and the
// invoice's own totals stopped adding up.

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
    config()->set('mollie-billing.vat.seller_country', 'AT');

    config()->set('mollie-billing-plans.plans.team', [
        'name' => 'Team',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 1000, 'seat_price_net' => 1000, 'included_usages' => []]],
    ]);
});

it('books the prorata coupon line at the rate of the lines it discounts', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Seat Coupon Co',
        'email' => 'seatcoupon@x.test',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
    ]);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'team',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc(),
        'mollie_customer_id' => 'cst_seatcoupon',
        'mollie_mandate_id' => 'mdt_seatcoupon',
        'subscription_meta' => ['mollie_subscription_id' => 'sub_seatcoupon', 'seat_count' => 1],
    ])->save();

    BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_seatcoupon_base',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 1000,
        'amount_vat' => 200,
        'amount_gross' => 1200,
        'serial_number' => 'IN-seatcoupon',
        'period_start' => BillingTime::nowUtc(),
        'period_end' => BillingTime::nowUtc()->addMonth(),
        'line_items' => [[
            'kind' => 'plan',
            'code' => 'team',
            'label' => 'Team',
            'quantity' => 1,
            'unit_price_net' => 1000,
            'amount_net' => 1000,
            'vat_rate' => 20.0,
            'vat_amount' => 200,
            'amount_gross' => 1200,
        ]],
    ]);

    app(CouponService::class)->create([
        'code' => 'REC20',
        'name' => 'Recurring 20',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 20,
        'max_redemptions_per_billable' => 6,
    ]);

    $captured = [];
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured) {
        $captured[] = $request;

        return (object) ['id' => 'tr_seatcoupon_'.count($captured), 'status' => 'open', 'subscriptionId' => 'sub_seatcoupon'];
    });

    // Seats 1 → 2. No plan line is produced, only a seat line.
    $billable->syncBillingSeats(2, null, ['REC20']);

    $lines = (array) ($billable->fresh()->getBillingSubscriptionMeta()['pending_prorata_change']['charge_lines'] ?? []);

    expect($lines)->not->toBeEmpty();

    $couponLines = array_values(array_filter($lines, fn (array $l): bool => ($l['kind'] ?? null) === 'coupon'));
    $seatLines = array_values(array_filter($lines, fn (array $l): bool => ($l['kind'] ?? null) !== 'coupon'));

    expect($couponLines)->not->toBeEmpty()
        ->and($seatLines)->not->toBeEmpty();

    $chargedRate = (float) $seatLines[0]['vat_rate'];

    // The discount has to carry the same rate as what it discounts, and a matching
    // (negative) VAT amount — otherwise the customer pays VAT on the undiscounted net.
    expect((float) $couponLines[0]['vat_rate'])->toBe($chargedRate)
        ->and((int) $couponLines[0]['vat_amount'])
        ->toBe((int) round((int) $couponLines[0]['amount_net'] * $chargedRate / 100));
});
