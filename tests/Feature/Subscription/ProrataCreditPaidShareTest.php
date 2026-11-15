<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\PlanChangeIntent;
use GraystackIT\MollieBilling\Services\Billing\ProrataComposer;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Storage;

/**
 * A prorata credit gives back the unused share of what was PAID for a component.
 *
 * An invoice line states the component's list price; whatever reduced the payment sits on
 * a negative line of its own — a recurring coupon, or the `adjustment` that books an
 * invoice down to what Mollie actually collected. Prorating from the list price credited
 * value nobody paid: the per-invoice pool only bounds the credit at the invoice header,
 * so a customer who bought the period at half price got the whole payment back after
 * using half of it.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
    config()->set('mollie-billing.vat.seller_country', 'AT');

    config()->set('mollie-billing-plans.plans', [
        'starter' => [
            'name' => 'Starter', 'tier' => 1, 'included_seats' => 1,
            'feature_keys' => [], 'allowed_addons' => [],
            'intervals' => ['monthly' => ['base_price_net' => 900, 'seat_price_net' => 500, 'included_usages' => []]],
        ],
        'pro' => [
            'name' => 'Pro', 'tier' => 2, 'included_seats' => 1,
            'feature_keys' => [], 'allowed_addons' => [],
            'intervals' => ['monthly' => ['base_price_net' => 2900, 'seat_price_net' => 500, 'included_usages' => []]],
        ],
    ]);
    config()->set('mollie-billing-plans.addons', []);
});

/** Pro monthly, period 1 Jan → 1 Feb 2026 (31 days), AT at 20 %. */
function paidShareBillable(int $seats = 1): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Paid Share Ltd',
        'email' => uniqid('paid-share-').'@x.test',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
        'mollie_customer_id' => 'cst_paid_share',
        'mollie_mandate_id' => 'mdt_paid_share',
    ]);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => Carbon::parse('2026-01-01 00:00:00', 'UTC'),
        'active_addon_codes' => [],
        'subscription_meta' => ['seat_count' => $seats, 'mollie_subscription_id' => 'sub_paid_share'],
    ])->save();

    return $billable->refresh();
}

/**
 * A renewal invoice in the shape SubscriptionPaymentHandler writes: every component at
 * list price, every reduction on its own negative line, the header at what was paid.
 *
 * @param  list<array{0: string, 1: ?string, 2: int, 3: int}>  $lines  [kind, code, quantity, net]
 */
function paidShareRenewal(TestBillable $billable, array $lines): BillingInvoice
{
    $start = Carbon::parse('2026-01-01 00:00:00', 'UTC');
    $end = Carbon::parse('2026-02-01 00:00:00', 'UTC');

    $items = array_map(fn (array $l): array => [
        'kind' => $l[0], 'code' => $l[1], 'label' => ucfirst($l[0]), 'quantity' => $l[2],
        'unit_price_net' => intdiv($l[3], max(1, $l[2])), 'total_net' => $l[3], 'amount_net' => $l[3],
        'vat_rate' => 20.0,
        'vat_amount' => (int) round($l[3] * 0.2),
        'amount_gross' => $l[3] + (int) round($l[3] * 0.2),
        'period_start' => $start->toIso8601String(),
        'period_end' => $end->toIso8601String(),
    ], $lines);

    $net = array_sum(array_column($items, 'amount_net'));
    $vat = array_sum(array_column($items, 'vat_amount'));

    return BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => uniqid('tr_paid_share_'),
        'mollie_subscription_id' => 'sub_paid_share',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => $net,
        'amount_vat' => $vat,
        'amount_gross' => $net + $vat,
        'line_items' => $items,
        'period_start' => $start,
        'period_end' => $end,
    ])->refresh();
}

/** Net credited by a composed change. */
function paidShareCredit(TestBillable $billable, string $newPlan, int $currentSeats, int $newSeats): int
{
    $lines = app(ProrataComposer::class)->compose(new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'pro', newPlan: $newPlan,
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: $currentSeats, newSeats: $newSeats,
        currentAddons: [], newAddons: [],
    ));

    return (int) array_sum(array_map(
        fn ($l): int => abs($l->amountNet),
        array_filter($lines, fn ($l): bool => $l->direction === 'refund'),
    ));
}

it('credits the unused share of what was actually paid on a coupon-discounted period', function (): void {
    // 16 Jan: 16 of 31 days left.
    $this->travelTo(Carbon::parse('2026-01-16 00:00:00', 'UTC'));

    $billable = paidShareBillable();
    paidShareRenewal($billable, [
        ['plan', 'pro', 1, 2900],
        ['coupon', 'HALF', 1, -1450],
    ]);

    // 1450 was paid for 31 days; 16 are unused → 1450 × 16/31 = 748. From the list price
    // it was 2900 × 16/31 = 1497, capped at the invoice: all 1450 back for half a period.
    expect(paidShareCredit($billable, 'starter', 1, 1))->toBe(748);
});

it('credits from what Mollie collected when the invoice was booked down by an adjustment', function (): void {
    $this->travelTo(Carbon::parse('2026-01-16 00:00:00', 'UTC'));

    $billable = paidShareBillable();
    // Mollie collected 1900 for a configuration priced at 2900 (a PATCH that had not
    // landed); `reconcileWithPaidAmount()` records the gap as an `adjustment` line.
    paidShareRenewal($billable, [
        ['plan', 'pro', 1, 2900],
        ['adjustment', null, 1, -1000],
    ]);

    // 1900 × 16/31 = 981, not 2900 × 16/31 = 1497.
    expect(paidShareCredit($billable, 'starter', 1, 1))->toBe(981);
});

it('spreads a reduction over every component, so a single seat is credited at its paid share', function (): void {
    $this->travelTo(Carbon::parse('2026-01-16 00:00:00', 'UTC'));

    $billable = paidShareBillable(seats: 3);
    // Plan 2900 + two extra seats 1000, half off: 1950 paid.
    paidShareRenewal($billable, [
        ['plan', 'pro', 1, 2900],
        ['seats', null, 2, 1000],
        ['coupon', 'HALF', 1, -1950],
    ]);

    // One seat out: paid 250 of its 500 → 250 × 16/31 = 129.
    expect(paidShareCredit($billable, 'pro', 3, 2))->toBe(129);
});

it('leaves an undiscounted invoice exactly as it was', function (): void {
    $this->travelTo(Carbon::parse('2026-01-16 00:00:00', 'UTC'));

    $billable = paidShareBillable();
    paidShareRenewal($billable, [['plan', 'pro', 1, 2900]]);

    // 2900 × 16/31 = 1497.
    expect(paidShareCredit($billable, 'starter', 1, 1))->toBe(1497);
});

it('does not credit a component above its list price when Mollie collected a surplus', function (): void {
    $this->travelTo(Carbon::parse('2026-01-16 00:00:00', 'UTC'));

    $billable = paidShareBillable();
    paidShareRenewal($billable, [
        ['plan', 'pro', 1, 2900],
        ['adjustment', null, 1, 500],
    ]);

    expect(paidShareCredit($billable, 'starter', 1, 1))->toBe(1497);
});

it('previews the same credit the change books', function (): void {
    $this->travelTo(Carbon::parse('2026-01-16 00:00:00', 'UTC'));

    $billable = paidShareBillable();
    paidShareRenewal($billable, [
        ['plan', 'pro', 1, 2900],
        ['coupon', 'HALF', 1, -1450],
    ]);

    $preview = app(\GraystackIT\MollieBilling\Services\Billing\PreviewService::class)
        ->previewPlanChange($billable, 'starter', 'monthly');

    $credit = array_sum(array_map(
        fn (array $l): int => (int) $l['amount_net'],
        array_filter($preview['prorataLines'], fn (array $l): bool => $l['direction'] === 'refund'),
    ));

    expect($credit)->toBe(-748);
});
