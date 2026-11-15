<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\PlanChangeIntent;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Services\Billing\ProrataComposer;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\SpyMollieSubscriptionPatcher;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest as MollieUpdateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * DEV-380 — per-seat addon pricing through every prorata path.
 *
 * The `addon_seats` line moves on two axes: with the addon (on → all charged seats, off →
 * all of them back) and with the seat count while the addon stays (only the difference in
 * CHARGED seats, so the addon's free seats absorb a change below them). The base `addon`
 * line never moves with the seats.
 *
 * Period 1 Jan → 1 Feb 2026 (31 days); changes on 11 Jan leave 21 days: factor 21/31.
 */
beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
    config()->set('mollie-billing.invoices.disk', 'local');
    config()->set('mollie-billing.vat.seller_country', 'AT');

    SpyMollieSubscriptionPatcher::$calls = [];

    $intervals = fn (int $base): array => [
        'monthly' => ['base_price_net' => $base, 'seat_price_net' => 500, 'included_usages' => []],
        'yearly' => ['base_price_net' => $base * 10, 'seat_price_net' => 5000, 'included_usages' => []],
    ];

    config()->set('mollie-billing-plans.plans', [
        'solo' => ['name' => 'Solo', 'tier' => 1, 'included_seats' => 1, 'feature_keys' => [], 'allowed_addons' => ['crm', 'sso', 'flat'], 'intervals' => $intervals(1000)],
        'team' => ['name' => 'Team', 'tier' => 2, 'included_seats' => 10, 'feature_keys' => [], 'allowed_addons' => ['crm', 'sso', 'flat'], 'intervals' => $intervals(5000)],
        'growth' => ['name' => 'Growth', 'tier' => 3, 'included_seats' => 10, 'feature_keys' => [], 'allowed_addons' => ['crm', 'sso', 'flat'], 'intervals' => $intervals(9000)],
    ]);
    config()->set('mollie-billing-plans.addons', [
        'crm' => ['name' => 'CRM', 'feature_keys' => [], 'intervals' => [
            'monthly' => ['price_net' => 2000, 'seat_price_net' => 500],
            'yearly' => ['price_net' => 20000, 'seat_price_net' => 5000],
        ]],
        'sso' => ['name' => 'SSO', 'feature_keys' => [], 'included_seats' => 5, 'intervals' => [
            'monthly' => ['price_net' => 0, 'seat_price_net' => 200],
            'yearly' => ['price_net' => 0, 'seat_price_net' => 2000],
        ]],
        'flat' => ['name' => 'Flat', 'feature_keys' => [], 'intervals' => [
            'monthly' => ['price_net' => 900],
            'yearly' => ['price_net' => 9000],
        ]],
    ]);

    $this->travelTo(Carbon::parse('2026-01-11 00:00:00', 'UTC'));
});

function addonSeatProrataBillable(string $plan, int $seats, array $addons, SubscriptionStatus $status = SubscriptionStatus::Active): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Addon Seats Prorata Co',
        'email' => uniqid('addon-seats-prorata-').'@x.test',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
        'mollie_customer_id' => 'cst_asp',
        'mollie_mandate_id' => 'mdt_asp',
    ]);

    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => $status,
        'subscription_plan_code' => $plan,
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => Carbon::parse('2026-01-01 00:00:00', 'UTC'),
        'trial_ends_at' => $status === SubscriptionStatus::Trial ? Carbon::parse('2026-01-15 00:00:00', 'UTC') : null,
        'active_addon_codes' => $addons,
        'subscription_meta' => ['seat_count' => $seats, 'mollie_subscription_id' => 'sub_asp'],
    ])->save();

    return $b->refresh();
}

/**
 * The renewal that paid for the running period, built from the same lines the renewal
 * handler writes, plus an optional reduction line (a coupon).
 */
function addonSeatProrataRenewal(TestBillable $billable, ?int $couponNet = null): BillingInvoice
{
    $catalog = app(SubscriptionCatalogInterface::class);
    $start = Carbon::parse('2026-01-01 00:00:00', 'UTC');
    $end = Carbon::parse('2026-02-01 00:00:00', 'UTC');

    $lines = SubscriptionAmount::lineItems(
        $catalog,
        $billable,
        (string) $billable->getBillingSubscriptionPlanCode(),
        'monthly',
        $billable->getExtraBillingSeats(),
        $billable->getActiveBillingAddonCodes(),
    );

    if ($couponNet !== null) {
        $lines[] = ['kind' => 'coupon', 'code' => 'C380', 'label' => 'Coupon', 'quantity' => 1, 'unit_price_net' => -$couponNet, 'total_net' => -$couponNet];
    }

    $items = array_map(fn (array $l): array => $l + [
        'amount_net' => $l['total_net'],
        'vat_rate' => 20.0,
        'vat_amount' => (int) round($l['total_net'] * 0.2),
        'amount_gross' => $l['total_net'] + (int) round($l['total_net'] * 0.2),
        'period_start' => $start->toIso8601String(),
        'period_end' => $end->toIso8601String(),
    ], $lines);

    $net = array_sum(array_column($items, 'amount_net'));
    $vat = array_sum(array_column($items, 'vat_amount'));

    return BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => uniqid('tr_asp_'),
        'mollie_subscription_id' => 'sub_asp',
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

/**
 * Compose a change away from the billable's current state.
 *
 * @return array{charge: array<string, int>, refund: array<string, int>, quantity: array<string, int>}
 *         net per "kind:code" and direction, and the quantity of each line
 */
function addonSeatProrataCompose(TestBillable $billable, array $to): array
{
    $plan = (string) $billable->getBillingSubscriptionPlanCode();
    $current = array_fill_keys($billable->getActiveBillingAddonCodes(), 1);

    $lines = app(ProrataComposer::class)->compose(new PlanChangeIntent(
        billable: $billable,
        currentPlan: $plan,
        newPlan: $to['plan'] ?? $plan,
        currentInterval: 'monthly',
        newInterval: $to['interval'] ?? 'monthly',
        currentSeats: $billable->getBillingSeatCount(),
        newSeats: $to['seats'] ?? $billable->getBillingSeatCount(),
        currentAddons: $current,
        newAddons: isset($to['addons']) ? array_fill_keys($to['addons'], 1) : $current,
    ));

    $out = ['charge' => [], 'refund' => [], 'quantity' => []];

    foreach ($lines as $line) {
        $key = $line->kind.':'.($line->code ?? '');
        $out[$line->direction][$key] = ($out[$line->direction][$key] ?? 0) + $line->amountNet;
        $out['quantity'][$line->direction.' '.$key] = ($out['quantity'][$line->direction.' '.$key] ?? 0) + $line->quantity;
    }

    return $out;
}

it('charges the added seats for the plan and for the per-seat addon, and leaves the base alone', function (): void {
    $billable = addonSeatProrataBillable('team', 12, ['crm']);
    addonSeatProrataRenewal($billable);

    $change = addonSeatProrataCompose($billable, ['seats' => 14]);

    // 2 × 500 × 21/31 = 677, once for the plan's seats and once for the addon's.
    expect($change['charge'])->toBe(['seats:' => 677, 'addon_seats:crm' => 677])
        ->and($change['quantity']['charge addon_seats:crm'])->toBe(2)
        ->and($change['refund'])->toBe([]);
});

it('charges the seat difference on every per-seat addon', function (): void {
    $billable = addonSeatProrataBillable('team', 12, ['crm', 'sso', 'flat']);
    addonSeatProrataRenewal($billable);

    $change = addonSeatProrataCompose($billable, ['seats' => 14]);

    // crm: 2 × 500 × 21/31 = 677; sso: 2 × 200 × 21/31 = 271; flat has no seat price.
    expect($change['charge'])->toBe(['seats:' => 677, 'addon_seats:crm' => 677, 'addon_seats:sso' => 271]);
});

it('charges only the seats beyond the addon\'s free ones when the count crosses that boundary', function (): void {
    $billable = addonSeatProrataBillable('solo', 4, ['sso']);
    addonSeatProrataRenewal($billable);

    // 4 → 6 seats with 5 free on the addon: exactly one addon seat. 200 × 21/31 = 135.
    $change = addonSeatProrataCompose($billable, ['seats' => 6]);

    expect($change['charge'])->toBe(['seats:' => 677, 'addon_seats:sso' => 135])
        ->and($change['quantity']['charge addon_seats:sso'])->toBe(1);

    // And entirely below it: nothing for the addon at all.
    $below = addonSeatProrataCompose(addonSeatProrataBillable('solo', 2, ['sso']), ['seats' => 4]);
    expect($below['charge'])->toBe(['seats:' => 677]);
});

it('credits removed seats on the plan and on the per-seat addon', function (): void {
    $billable = addonSeatProrataBillable('team', 14, ['crm', 'sso']);
    addonSeatProrataRenewal($billable);

    $change = addonSeatProrataCompose($billable, ['seats' => 12]);

    // seats: 2 of 4 × 500; crm: 2 of 14 × 500; sso: 2 of 9 × 200 — each × 21/31.
    expect($change['refund'])->toBe(['seats:' => -677, 'addon_seats:crm' => -677, 'addon_seats:sso' => -271])
        ->and($change['quantity']['refund addon_seats:crm'])->toBe(2)
        ->and($change['charge'])->toBe([]);
});

it('credits only the charged addon seats when the count drops through the free ones', function (): void {
    $billable = addonSeatProrataBillable('solo', 6, ['sso']);
    addonSeatProrataRenewal($billable);

    // 6 → 3: one addon seat was charged, one comes back.
    $change = addonSeatProrataCompose($billable, ['seats' => 3]);

    expect($change['refund']['addon_seats:sso'])->toBe(-135)
        ->and($change['quantity']['refund addon_seats:sso'])->toBe(1);
});

it('charges base and seats when a per-seat addon is switched on mid-period', function (): void {
    $billable = addonSeatProrataBillable('team', 12, []);
    addonSeatProrataRenewal($billable);

    $change = addonSeatProrataCompose($billable, ['addons' => ['crm']]);

    // 2000 × 21/31 = 1355 and 12 × 500 × 21/31 = 4065.
    expect($change['charge'])->toBe(['addon:crm' => 1355, 'addon_seats:crm' => 4065])
        ->and($change['quantity']['charge addon_seats:crm'])->toBe(12);
});

it('credits base and seats when a per-seat addon is switched off mid-period', function (): void {
    $billable = addonSeatProrataBillable('team', 12, ['crm']);
    addonSeatProrataRenewal($billable);

    $change = addonSeatProrataCompose($billable, ['addons' => []]);

    expect($change['refund'])->toBe(['addon:crm' => -1355, 'addon_seats:crm' => -4065])
        ->and($change['quantity']['refund addon_seats:crm'])->toBe(12)
        ->and($change['charge'])->toBe([]);
});

it('credits and recharges everything, addon seats included, on a plan change', function (): void {
    $billable = addonSeatProrataBillable('team', 12, ['crm']);
    addonSeatProrataRenewal($billable);

    $change = addonSeatProrataCompose($billable, ['plan' => 'growth']);

    expect($change['refund'])->toBe(['plan:team' => -3387, 'seats:' => -677, 'addon:crm' => -1355, 'addon_seats:crm' => -4065])
        ->and($change['charge'])->toBe(['plan:growth' => 6097, 'seats:' => 677, 'addon:crm' => 1355, 'addon_seats:crm' => 4065]);
});

it('charges a full new interval including the addon seats on an interval change', function (): void {
    $billable = addonSeatProrataBillable('team', 12, ['crm']);
    addonSeatProrataRenewal($billable);

    $change = addonSeatProrataCompose($billable, ['interval' => 'yearly']);

    // 500 + 2 × 50 + 200 + 12 × 50 = 1400.00 for the year; the month's rest is credited.
    expect($change['charge'])->toBe(['plan:team' => 50000, 'seats:' => 10000, 'addon:crm' => 20000, 'addon_seats:crm' => 60000])
        ->and($change['refund'])->toBe(['plan:team' => -3387, 'seats:' => -677, 'addon:crm' => -1355, 'addon_seats:crm' => -4065]);
});

it('charges a full first period including the addon seats on a past-due reset', function (): void {
    $billable = addonSeatProrataBillable('team', 12, ['crm'], SubscriptionStatus::PastDue);

    $change = addonSeatProrataCompose($billable, ['plan' => 'growth']);

    expect($change['charge'])->toBe(['plan:growth' => 9000, 'seats:' => 1000, 'addon:crm' => 2000, 'addon_seats:crm' => 6000])
        ->and($change['refund'])->toBe([]);
});

it('keeps seats and addons free during a trial, and prices the addon seats into what follows', function (): void {
    $billable = addonSeatProrataBillable('team', 12, [], SubscriptionStatus::Trial);

    expect(addonSeatProrataCompose($billable, ['seats' => 14, 'addons' => ['crm']]))
        ->toBe(['charge' => [], 'refund' => [], 'quantity' => []]);

    // What Mollie is PATCHed to — the first real charge: 5000 + 4 × 500 + 2000 + 14 × 500.
    expect(SubscriptionAmount::net(app(SubscriptionCatalogInterface::class), $billable, 'team', 'monthly', 14, ['crm']))->toBe(16000);
});

it('credits the paid share of the addon lines on a coupon-discounted period, within the invoice', function (int $couponNet, int $addonCredit, int $addonSeatsCredit) {
    $billable = addonSeatProrataBillable('team', 12, ['crm']);
    $invoice = addonSeatProrataRenewal($billable, $couponNet);

    $off = addonSeatProrataCompose($billable, ['addons' => []]);

    expect($off['refund'])->toBe(['addon:crm' => -$addonCredit, 'addon_seats:crm' => -$addonSeatsCredit]);

    // Everything credited back by leaving the whole configuration stays inside what the
    // invoice collected — the pool is per invoice, the share per line.
    $leave = addonSeatProrataCompose($billable, ['plan' => 'growth']);
    $credited = (int) abs(array_sum($leave['refund']));

    expect($credited)->toBeLessThanOrEqual((int) $invoice->amount_net)
        ->and($credited)->toBe((int) round($invoice->amount_net * 21 / 31));
})->with([
    // 50 % of 14000: addon 1000 × 21/31, addon seats 3000 × 21/31.
    'percentage' => [7000, 677, 2032],
    // 14.00 off 140.00 → 90 % paid: addon 1800 × 21/31, addon seats 5400 × 21/31.
    'fixed' => [1400, 1219, 3658],
]);

it('previews the same lines update() then books for a seat change with a per-seat addon', function (): void {
    $this->app->bind(MollieSubscriptionPatcher::class, SpyMollieSubscriptionPatcher::class);

    $billable = addonSeatProrataBillable('team', 12, ['crm']);
    addonSeatProrataRenewal($billable);

    $preview = app(PreviewService::class)->previewSeatChange($billable, 14);

    $sent = [];
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$sent) {
        $sent[] = $request;

        return (object) ['id' => 'tr_asp_seats', 'status' => 'open'];
    });

    app(UpdateSubscription::class)->update($billable, ['seats' => 14]);

    $billable->refresh();
    $pending = $billable->getBillingSubscriptionMeta()['pending_prorata_change'];
    $booked = array_map(fn (array $l): array => [$l['kind'], $l['code'], $l['quantity'], $l['amount_net']], $pending['charge_lines']);
    $previewed = array_map(fn (array $l): array => [$l['kind'], $l['code'], $l['quantity'], $l['amount_net']], $preview['prorataLines']);

    $amount = (new ReflectionClass($sent[0]))->getProperty('amount');
    $amount->setAccessible(true);

    // 677 + 677 net; VAT per line 135 + 135 → 16.24 collected now.
    expect($booked)->toBe([['seats', null, 2, 677], ['addon_seats', 'crm', 2, 677]])
        ->and($previewed)->toBe($booked)
        ->and($preview['prorataTotalGross'])->toBe(1624)
        ->and($amount->getValue($sent[0])->value)->toBe('16.24')
        // The recurring side: 5000 + 4 × 500 + 2000 + 14 × 500.
        ->and($preview['newPriceNet'])->toBe(16000)
        ->and($billable->getBillingSubscriptionMeta()['pending_amount_net'])->toBe(16000)
        ->and(SpyMollieSubscriptionPatcher::$calls[0][3])->toMatchArray(['seats' => 14, 'addons' => ['crm']]);
});

it('books a per-seat addon enabled through update() and credits it when it is disabled again', function (): void {
    $this->app->bind(MollieSubscriptionPatcher::class, SpyMollieSubscriptionPatcher::class);

    $billable = addonSeatProrataBillable('team', 12, ['crm']);
    addonSeatProrataRenewal($billable);

    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 're_asp_off', 'status' => 'pending']);

    app(UpdateSubscription::class)->update($billable, ['addons' => []]);

    $credit = BillingInvoice::query()
        ->where('billable_id', $billable->getKey())
        ->where('invoice_kind', InvoiceKind::Refund)
        ->firstOrFail();

    $lines = array_map(fn (array $l): array => [$l['kind'], $l['code'], $l['quantity'], $l['amount_net']], (array) $credit->line_items);

    expect($lines)->toBe([['addon', 'crm', 1, -1355], ['addon_seats', 'crm', 12, -4065]])
        ->and($billable->refresh()->getActiveBillingAddonCodes())->toBe([]);

    // Credited once: the same units are not on offer a second time.
    expect(BillingInvoice::currentPeriodLines($billable, 'addon_seats', 'crm'))->toBe([]);
});

it('announces a scheduled change to Mollie at the amount including the addon seats', function (): void {
    $billable = addonSeatProrataBillable('team', 12, ['crm']);

    $captured = null;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured) {
        $captured = $request;

        return (object) ['id' => 'sub_asp'];
    });

    // The twelve seats are kept on purpose — without `seats` a plan change lands on the
    // seats in use or included.
    app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'growth', 'interval' => 'monthly', 'seats' => 12, 'apply_at' => 'end_of_period',
    ]);

    $amount = (new ReflectionClass($captured))->getProperty('amount');
    $amount->setAccessible(true);

    // Growth 9000 + 2 × 500 + CRM 2000 + 12 × 500 = 18000 net → 216.00.
    expect($captured)->toBeInstanceOf(MollieUpdateSubscriptionRequest::class)
        ->and($amount->getValue($captured)->value)->toBe('216.00');
});
