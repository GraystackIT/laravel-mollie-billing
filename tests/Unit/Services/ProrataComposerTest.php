<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\PlanChangeIntent;
use GraystackIT\MollieBilling\Services\Billing\ProrataComposer;
use GraystackIT\MollieBilling\Support\ProrataLine;
use GraystackIT\MollieBilling\Testing\TestBillable;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free',
        'tier' => 1,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);

    config()->set('mollie-billing-plans.plans.starter', [
        'name' => 'Starter',
        'tier' => 1,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => ['print-gateway'],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => 500, 'included_usages' => []],
        ],
    ]);

    config()->set('mollie-billing-plans.plans.enterprise', [
        'name' => 'Enterprise',
        'tier' => 3,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => ['print-gateway', 'example-addon'],
        'intervals' => [
            'monthly' => ['base_price_net' => 3000, 'seat_price_net' => 500, 'included_usages' => []],
        ],
    ]);

    config()->set('mollie-billing-plans.addons.print-gateway', [
        'name' => 'Print Gateway',
        'intervals' => ['monthly' => ['price_net' => 900]],
    ]);

    config()->set('mollie-billing-plans.addons.example-addon', [
        'name' => 'Example Addon',
        'intervals' => ['monthly' => ['price_net' => 990]],
    ]);
});

function makeComposerBillable(string $currentPlan = 'enterprise', int $seats = 1, array $addons = []): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Acme',
        'email' => 'acme@example.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_test',
        'mollie_mandate_id' => 'mdt_test',
    ]);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => $currentPlan,
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(15),
        'active_addon_codes' => array_keys($addons),
        'subscription_meta' => ['seat_count' => $seats, 'mollie_subscription_id' => 'sub_test'],
    ])->save();

    return $billable->refresh();
}

function makePeriodInvoiceWithLines(TestBillable $billable, array $lines): BillingInvoice
{
    $sumNet = array_sum(array_column($lines, 'amount_net'));
    $sumVat = array_sum(array_column($lines, 'vat_amount'));
    $sumGross = array_sum(array_column($lines, 'amount_gross'));

    return BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_'.uniqid(),
        'mollie_subscription_id' => 'sub_test',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => $sumNet,
        'amount_vat' => $sumVat,
        'amount_gross' => $sumGross,
        'line_items' => $lines,
        'period_start' => now()->subDays(15),
        'period_end' => now()->addDays(15),
    ]);
}

function planLine(string $code, int $netCents, float $vatRate, ?string $start = null, ?string $end = null): array
{
    $vatAmount = (int) round($netCents * $vatRate / 100);
    return [
        'kind' => 'plan',
        'code' => $code,
        'label' => ucfirst($code),
        'quantity' => 1,
        'unit_price_net' => $netCents,
        'amount_net' => $netCents,
        'vat_rate' => $vatRate,
        'vat_amount' => $vatAmount,
        'amount_gross' => $netCents + $vatAmount,
        'period_start' => $start ?? now()->subDays(15)->toIso8601String(),
        'period_end' => $end ?? now()->addDays(15)->toIso8601String(),
    ];
}

function seatsLine(int $quantity, int $netCents, float $vatRate, ?string $start = null, ?string $end = null): array
{
    $vatAmount = (int) round($netCents * $vatRate / 100);
    return [
        'kind' => 'seats',
        'code' => null,
        'label' => "{$quantity}× Sitz",
        'quantity' => $quantity,
        'unit_price_net' => intdiv($netCents, max(1, $quantity)),
        'amount_net' => $netCents,
        'vat_rate' => $vatRate,
        'vat_amount' => $vatAmount,
        'amount_gross' => $netCents + $vatAmount,
        'period_start' => $start ?? now()->subDays(15)->toIso8601String(),
        'period_end' => $end ?? now()->addDays(15)->toIso8601String(),
    ];
}

function addonLine(string $code, int $netCents, float $vatRate, ?string $start = null, ?string $end = null): array
{
    $vatAmount = (int) round($netCents * $vatRate / 100);
    return [
        'kind' => 'addon',
        'code' => $code,
        'label' => $code,
        'quantity' => 1,
        'unit_price_net' => $netCents,
        'amount_net' => $netCents,
        'vat_rate' => $vatRate,
        'vat_amount' => $vatAmount,
        'amount_gross' => $netCents + $vatAmount,
        'period_start' => $start ?? now()->subDays(15)->toIso8601String(),
        'period_end' => $end ?? now()->addDays(15)->toIso8601String(),
    ];
}

it('returns empty list for Free→Free (Free origin)', function (): void {
    $billable = makeComposerBillable('free');
    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'free', newPlan: 'free',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: [], newAddons: [],
    );

    $lines = app(ProrataComposer::class)->compose($intent);

    expect($lines)->toBe([]);
});

it('Mollie→Mollie plan change creates 2 lines: refund old, charge new', function (): void {
    $billable = makeComposerBillable('enterprise');
    makePeriodInvoiceWithLines($billable, [
        planLine('enterprise', 3000, 20.0),
    ]);

    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'enterprise', newPlan: 'starter',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: [], newAddons: [],
    );

    $lines = app(ProrataComposer::class)->compose($intent);

    expect($lines)->toHaveCount(2);
    expect($lines[0]->direction)->toBe('charge'); // Charges first
    expect($lines[0]->kind)->toBe('plan');
    expect($lines[0]->code)->toBe('starter');
    expect($lines[0]->amountNet)->toBeGreaterThan(0);

    expect($lines[1]->direction)->toBe('refund');
    expect($lines[1]->kind)->toBe('plan');
    expect($lines[1]->code)->toBe('enterprise');
    expect($lines[1]->amountNet)->toBeLessThan(0);
    expect($lines[1]->vatRate)->toBe(20.0);
});

it('Mollie→Free downgrade creates only refund (no charge)', function (): void {
    $billable = makeComposerBillable('enterprise');
    makePeriodInvoiceWithLines($billable, [
        planLine('enterprise', 3000, 20.0),
    ]);

    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'enterprise', newPlan: 'free',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: [], newAddons: [],
    );

    $lines = app(ProrataComposer::class)->compose($intent);

    expect($lines)->toHaveCount(1);
    expect($lines[0]->direction)->toBe('refund');
    expect($lines[0]->kind)->toBe('plan');
});

it('seat reduction creates one refund line against current period seats line', function (): void {
    $billable = makeComposerBillable('starter', 5);
    makePeriodInvoiceWithLines($billable, [
        planLine('starter', 1000, 20.0),
        seatsLine(4, 4 * 500, 20.0), // 4 extra seats × 500 cents
    ]);

    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'starter', newPlan: 'starter',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 5, newSeats: 3,
        currentAddons: [], newAddons: [],
    );

    $lines = app(ProrataComposer::class)->compose($intent);

    expect($lines)->toHaveCount(1);
    expect($lines[0]->kind)->toBe('seats');
    expect($lines[0]->direction)->toBe('refund');
    expect($lines[0]->quantity)->toBe(2);
});

it('addon disable creates refund line via currentPeriodLines lookup', function (): void {
    $billable = makeComposerBillable('starter', 1, ['print-gateway' => 1]);
    makePeriodInvoiceWithLines($billable, [
        planLine('starter', 1000, 20.0),
        addonLine('print-gateway', 900, 20.0),
    ]);

    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'starter', newPlan: 'starter',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: ['print-gateway' => 1],
        newAddons: [],
    );

    $lines = app(ProrataComposer::class)->compose($intent);

    expect($lines)->toHaveCount(1);
    expect($lines[0]->kind)->toBe('addon');
    expect($lines[0]->code)->toBe('print-gateway');
    expect($lines[0]->direction)->toBe('refund');
    expect($lines[0]->vatRate)->toBe(20.0);
});

it('multi-VAT: refund of addon with 10% VAT is independent of plan 20%', function (): void {
    $billable = makeComposerBillable('starter', 1, ['example-addon' => 1]);
    makePeriodInvoiceWithLines($billable, [
        planLine('starter', 1000, 20.0),
        addonLine('example-addon', 990, 10.0), // 10% VAT
    ]);

    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'starter', newPlan: 'starter',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: ['example-addon' => 1],
        newAddons: [],
    );

    $lines = app(ProrataComposer::class)->compose($intent);

    expect($lines)->toHaveCount(1);
    expect($lines[0]->vatRate)->toBe(10.0); // From original line, not from the plan
});

it('coupon-covered original (amount_net=0) marks line as isCouponCovered', function (): void {
    $billable = makeComposerBillable('starter', 1, ['print-gateway' => 1]);
    makePeriodInvoiceWithLines($billable, [
        planLine('starter', 1000, 20.0),
        // print-gateway via Coupon: amount_net = 0
        addonLine('print-gateway', 0, 20.0),
    ]);

    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'starter', newPlan: 'starter',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: ['print-gateway' => 1],
        newAddons: [],
    );

    $lines = app(ProrataComposer::class)->compose($intent);

    expect($lines)->toHaveCount(1);
    expect($lines[0]->isCouponCovered)->toBeTrue();
    expect($lines[0]->amountGross)->toBe(0);
});

it('seat reduction LIFO across multiple original line items', function (): void {
    $billable = makeComposerBillable('starter', 10);
    // 5 seats from period start, 3 seats added at day 5, 2 seats added at day 10.
    makePeriodInvoiceWithLines($billable, [
        planLine('starter', 1000, 20.0),
        seatsLine(5, 5 * 500, 20.0, now()->subDays(15)->toIso8601String(), now()->addDays(15)->toIso8601String()),
    ]);
    // Mid-cycle invoices (separate Subscription invoices via mid-cycle activation):
    BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_seats3',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 3 * 500,
        'amount_vat' => 3 * 100,
        'amount_gross' => 3 * 600,
        'line_items' => [
            seatsLine(3, 3 * 500, 20.0, now()->subDays(10)->toIso8601String(), now()->addDays(15)->toIso8601String()),
        ],
        'period_start' => now()->subDays(10),
        'period_end' => now()->addDays(15),
    ]);
    BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_seats2',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 2 * 500,
        'amount_vat' => 2 * 100,
        'amount_gross' => 2 * 600,
        'line_items' => [
            seatsLine(2, 2 * 500, 20.0, now()->subDays(5)->toIso8601String(), now()->addDays(15)->toIso8601String()),
        ],
        'period_start' => now()->subDays(5),
        'period_end' => now()->addDays(15),
    ]);

    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'starter', newPlan: 'starter',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 10, newSeats: 5,
        currentAddons: [], newAddons: [],
    );

    $lines = app(ProrataComposer::class)->compose($intent);

    // Return 5 seats: newest (2) first, then next-newest (3) -> 2 refund lines.
    expect($lines)->toHaveCount(2);
    expect($lines[0]->kind)->toBe('seats');
    expect($lines[0]->quantity)->toBe(2); // newest
    expect($lines[1]->quantity)->toBe(3); // second-newest
});

it('skips refund line when original plan invoice is missing', function (): void {
    $billable = makeComposerBillable('enterprise');
    // No period invoice with a plan line was created — e.g. after a full refund / data inconsistency.

    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'enterprise', newPlan: 'starter',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: [], newAddons: [],
    );

    $lines = app(ProrataComposer::class)->compose($intent);

    // No refund line, but the new plan is still charged pro-rata.
    expect($lines)->toHaveCount(1);
    expect($lines[0]->direction)->toBe('charge');
    expect($lines[0]->kind)->toBe('plan');
    expect($lines[0]->code)->toBe('starter');
});

it('skips plan refund when original invoice is fully refunded already', function (): void {
    $billable = makeComposerBillable('enterprise');
    $invoice = makePeriodInvoiceWithLines($billable, [
        planLine('enterprise', 3000, 20.0),
    ]);
    // Original invoice was fully refunded as a goodwill gesture (cached column).
    $invoice->refunded_net = $invoice->amount_net;
    $invoice->save();

    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'enterprise', newPlan: 'starter',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: [], newAddons: [],
    );

    $lines = app(ProrataComposer::class)->compose($intent);

    // No refund line — everything was already paid back.
    expect($lines)->toHaveCount(1);
    expect($lines[0]->direction)->toBe('charge');
    expect($lines[0]->kind)->toBe('plan');
});

it('caps plan refund at remaining refundable on partial pre-refund', function (): void {
    $billable = makeComposerBillable('enterprise');
    // 30.00 EUR plan line, with 25.00 EUR already refunded as goodwill -> 5.00 EUR left.
    $invoice = makePeriodInvoiceWithLines($billable, [
        planLine('enterprise', 3000, 20.0),
    ]);
    $invoice->refunded_net = 2500;
    $invoice->save();

    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'enterprise', newPlan: 'starter',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: [], newAddons: [],
    );

    $lines = app(ProrataComposer::class)->compose($intent);
    $refund = collect($lines)->firstWhere('direction', 'refund');

    expect($refund)->not->toBeNull();
    // Uncapped would be ~50% of 3000 ≈ 1500 — but only 500 remain refundable.
    expect($refund->amountNet)->toBe(-500);
    expect($refund->refundCapNote)->not->toBeNull();
    expect($refund->refundCapNote['alreadyRefundedNet'])->toBe(2500);
    expect($refund->refundCapNote['originalAmountNet'])->toBe(3000);
    expect($refund->refundCapNote['cappedRefundNet'])->toBe(500);
    expect($refund->refundCapNote['uncappedRefundNet'])->toBeGreaterThan(500);
});

it('shares remaining-refundable budget across plan and seat refund lines on the same invoice', function (): void {
    $billable = makeComposerBillable('enterprise', seats: 3);
    // Original invoice: plan 3000 + 2 seats 1000 = 4000 net. 1500 already refunded
    // (e.g. goodwill) leaves 2500 in the pool. Uncapped pro-rata at ~50% factor:
    // plan 1500 + seats 500 = 2000 — fits, but barely. Drop one more seat into the
    // mix so the budget overflows and the seat line gets capped.
    $invoice = makePeriodInvoiceWithLines($billable, [
        planLine('enterprise', 3000, 20.0),
        seatsLine(2, 1000, 20.0),
    ]);
    $invoice->refunded_net = 2000; // pool = 2000
    $invoice->save();

    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'enterprise', newPlan: 'starter',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 3, newSeats: 1,
        currentAddons: [], newAddons: [],
    );

    $lines = app(ProrataComposer::class)->compose($intent);
    $refunds = collect($lines)->where('direction', 'refund')->values();

    // Sum of refund nets must not exceed remaining budget.
    $totalRefundNet = $refunds->sum(fn ($l) => abs($l->amountNet));
    expect($totalRefundNet)->toBeLessThanOrEqual(2000);

    // Plan refund consumes 1500 (uncapped, fits within 2000), leaves 500 for seats.
    $plan = $refunds->firstWhere('kind', 'plan');
    expect($plan)->not->toBeNull();
    expect($plan->refundCapNote)->toBeNull();
    expect(abs($plan->amountNet))->toBe(1500);

    // Seat refund gets capped from uncapped 500 down to the remaining 500 — exact fit,
    // so no cap note. Verify the budget was respected.
    $seats = $refunds->firstWhere('kind', 'seats');
    expect($seats)->not->toBeNull();
    expect(abs($seats->amountNet))->toBe(500);
});

it('caps refunds per invoice when seats are spread across two original invoices with different pre-refund states', function (): void {
    $billable = makeComposerBillable('starter', seats: 6);
    // Newer invoice (5 days old): 3 seats × 500. Half-refunded -> pool 750.
    $newer = BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_newer',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 1500,
        'amount_vat' => 300,
        'amount_gross' => 1800,
        'line_items' => [
            seatsLine(3, 1500, 20.0, now()->subDays(5)->toIso8601String(), now()->addDays(15)->toIso8601String()),
        ],
        'period_start' => now()->subDays(5),
        'period_end' => now()->addDays(15),
        'refunded_net' => 750,
    ]);
    // Older invoice (10 days old): 2 seats × 500. Untouched -> pool 1000.
    $older = BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_older',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 1000,
        'amount_vat' => 200,
        'amount_gross' => 1200,
        'line_items' => [
            seatsLine(2, 1000, 20.0, now()->subDays(10)->toIso8601String(), now()->addDays(15)->toIso8601String()),
        ],
        'period_start' => now()->subDays(10),
        'period_end' => now()->addDays(15),
        'refunded_net' => 0,
    ]);

    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'starter', newPlan: 'starter',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 6, newSeats: 1, // refund 5 seats
        currentAddons: [], newAddons: [],
    );

    $lines = app(ProrataComposer::class)->compose($intent);
    $refunds = collect($lines)->where('direction', 'refund')->values();

    // Two refund lines — newer first, then older.
    expect($refunds)->toHaveCount(2);

    // Newer invoice: refund of 3 seats × 500 × ~50% factor ≈ 750 — exactly the pool size,
    // so no cap. Each invoice has its own budget; the older invoice's budget is unaffected.
    $newerRefund = $refunds->firstWhere(fn ($l) => $l->originalInvoice?->id === $newer->id);
    expect($newerRefund)->not->toBeNull();
    expect($newerRefund->quantity)->toBe(3);

    // Older invoice: refund of 2 seats untouched by the newer-invoice cap.
    $olderRefund = $refunds->firstWhere(fn ($l) => $l->originalInvoice?->id === $older->id);
    expect($olderRefund)->not->toBeNull();
    expect($olderRefund->quantity)->toBe(2);
    expect($olderRefund->refundCapNote)->toBeNull();
});

it('caps seat refund line when plan refund already consumed most of the budget', function (): void {
    $billable = makeComposerBillable('enterprise', seats: 3);
    // Plan 3000 + 2 seats 1000 = 4000 net. 2500 already refunded -> 1500 in pool.
    // Plan-refund uncapped 1500 consumes the entire pool exactly; seats then get fully capped.
    $invoice = makePeriodInvoiceWithLines($billable, [
        planLine('enterprise', 3000, 20.0),
        seatsLine(2, 1000, 20.0),
    ]);
    $invoice->refunded_net = 2500;
    $invoice->save();

    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'enterprise', newPlan: 'starter',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 3, newSeats: 1,
        currentAddons: [], newAddons: [],
    );

    $lines = app(ProrataComposer::class)->compose($intent);
    $refunds = collect($lines)->where('direction', 'refund')->values();

    // Plan takes the full 1500, no cap note.
    $plan = $refunds->firstWhere('kind', 'plan');
    expect($plan)->not->toBeNull();
    expect(abs($plan->amountNet))->toBe(1500);
    expect($plan->refundCapNote)->toBeNull();

    // Budget exhausted -> seat line is dropped entirely.
    $seats = $refunds->firstWhere('kind', 'seats');
    expect($seats)->toBeNull();
});

it('Past-Due plan change: charges full first period of new plan, no refund', function (): void {
    $billable = makeComposerBillable('starter');
    $billable->forceFill([
        'subscription_status' => SubscriptionStatus::PastDue,
        'subscription_period_starts_at' => now()->subDays(40), // period long expired
    ])->save();
    $billable->refresh();

    // Intentionally seed an old paid invoice for the starter plan — composer
    // must NOT generate refund lines against it (nothing was paid for the
    // unpaid current period).
    makePeriodInvoiceWithLines($billable, [planLine('starter', 1000, 20.0)]);

    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'starter', newPlan: 'enterprise',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: [], newAddons: [],
    );

    $lines = app(ProrataComposer::class)->compose($intent);

    expect($lines)->toHaveCount(1);
    expect($lines[0]->direction)->toBe('charge');
    expect($lines[0]->kind)->toBe('plan');
    expect($lines[0]->code)->toBe('enterprise');
    expect($lines[0]->amountNet)->toBe(3000); // full enterprise price, factor = 1.0
    // No refund line — the old period was never paid.
    $refunds = array_filter($lines, fn (ProrataLine $l) => $l->direction === 'refund');
    expect($refunds)->toBe([]);
});

it('Past-Due plan change includes extra seats and addons at full price', function (): void {
    $billable = makeComposerBillable('starter');
    $billable->forceFill([
        'subscription_status' => SubscriptionStatus::PastDue,
        'subscription_period_starts_at' => now()->subDays(40),
    ])->save();
    $billable->refresh();

    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'starter', newPlan: 'enterprise',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 3, // 2 extra seats
        currentAddons: [], newAddons: ['print-gateway' => 1],
    );

    $lines = app(ProrataComposer::class)->compose($intent);

    $byKind = [];
    foreach ($lines as $line) {
        $byKind[$line->kind] = $line;
    }

    expect(array_keys($byKind))->toEqualCanonicalizing(['plan', 'seats', 'addon']);
    expect($byKind['plan']->amountNet)->toBe(3000);
    // 2 extra seats × 500 = 1000
    expect($byKind['seats']->amountNet)->toBe(1000);
    expect($byKind['seats']->quantity)->toBe(2);
    // print-gateway full price 900
    expect($byKind['addon']->amountNet)->toBe(900);
    // All charges — no refunds.
    foreach ($lines as $line) {
        expect($line->direction)->toBe('charge');
    }
});

it('Past-Due → Free plan change yields empty lines (no charge for free target)', function (): void {
    $billable = makeComposerBillable('starter');
    $billable->forceFill([
        'subscription_status' => SubscriptionStatus::PastDue,
        'subscription_period_starts_at' => now()->subDays(40),
    ])->save();
    $billable->refresh();

    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'starter', newPlan: 'free',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: [], newAddons: [],
    );

    // Free target: composer falls through to the regular Mollie→Free path
    // (refund-only, but in PastDue there is nothing to refund), yielding [].
    $lines = app(ProrataComposer::class)->compose($intent);

    expect($lines)->toBe([]);
});
