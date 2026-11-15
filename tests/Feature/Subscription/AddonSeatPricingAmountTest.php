<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\LocalSubscriptionDoesNotSupportPaidExtrasException;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\PlanChangeIntent;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ChargeDeferral;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest as MollieUpdateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * DEV-380 — an addon may carry a per-seat price next to its base price.
 *
 *   addon net = price_net + seat_price_net × max(0, seats − addon.included_seats)
 *
 * The per-seat price counts EVERY seat of the subscription, the plan's included ones
 * too; the addon's own `included_seats` are independent of the plan's. It is invoiced as
 * a second line (`addon_seats`) beside the unchanged `addon` line.
 *
 * This file pins the amount, the lines, and that the four places a customer meets the
 * number — preview, Mollie subscription amount, renewal invoice, catalog — agree.
 */
class AddonSeatPricingWebhookController extends MollieWebhookController
{
    public static ?object $nextPayment = null;

    protected function fetchPayment(string $paymentId): object
    {
        return self::$nextPayment ?? throw new RuntimeException('No payment stub configured.');
    }
}

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
    config()->set('mollie-billing.invoices.disk', 'local');
    config()->set('mollie-billing.vat.seller_country', 'AT');

    AddonSeatPricingWebhookController::$nextPayment = null;
    $this->app->bind(MollieWebhookController::class, AddonSeatPricingWebhookController::class);

    config()->set('mollie-billing-plans.plans', [
        'free' => [
            'name' => 'Free', 'tier' => 0, 'included_seats' => 3,
            'feature_keys' => [], 'allowed_addons' => ['sso', 'badge'],
            'intervals' => ['monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []]],
        ],
        'solo' => [
            'name' => 'Solo', 'tier' => 1, 'included_seats' => 1,
            'feature_keys' => [], 'allowed_addons' => ['crm', 'sso', 'flat'],
            'intervals' => ['monthly' => ['base_price_net' => 1000, 'seat_price_net' => 500, 'included_usages' => []]],
        ],
        // The ticket's example: 50 € with 10 seats included, 5 € per extra seat.
        'team' => [
            'name' => 'Team', 'tier' => 2, 'included_seats' => 10,
            'feature_keys' => [], 'allowed_addons' => ['crm', 'sso', 'flat', 'badge'],
            'intervals' => ['monthly' => ['base_price_net' => 5000, 'seat_price_net' => 500, 'included_usages' => []]],
        ],
    ]);
    config()->set('mollie-billing-plans.addons', [
        // Base 20 € + 5 € per seat, no free seats.
        'crm' => ['name' => 'CRM', 'feature_keys' => [], 'intervals' => ['monthly' => ['price_net' => 2000, 'seat_price_net' => 500]]],
        // Per seat only, five seats free.
        'sso' => ['name' => 'SSO', 'feature_keys' => [], 'included_seats' => 5, 'intervals' => ['monthly' => ['price_net' => 0, 'seat_price_net' => 200]]],
        // As addons have always been.
        'flat' => ['name' => 'Flat', 'feature_keys' => [], 'intervals' => ['monthly' => ['price_net' => 900]]],
        'badge' => ['name' => 'Badge', 'feature_keys' => [], 'intervals' => ['monthly' => ['price_net' => 0]]],
    ]);
});

function addonSeatAmountBillable(string $plan, int $seats, array $addons, SubscriptionSource $source = SubscriptionSource::Mollie): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Addon Seats Co',
        'email' => uniqid('addon-seats-amount-').'@x.test',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
    ]);

    $b->forceFill([
        'mollie_customer_id' => $source === SubscriptionSource::Mollie ? 'cst_addon_seats' : null,
        'mollie_mandate_id' => $source === SubscriptionSource::Mollie ? 'mdt_addon_seats' : null,
        'subscription_source' => $source,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => $plan,
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => Carbon::parse('2026-01-01 09:00:00', 'UTC'),
        'active_addon_codes' => $addons,
        'subscription_meta' => array_filter([
            'seat_count' => $seats,
            'mollie_subscription_id' => $source === SubscriptionSource::Mollie ? 'sub_addon_seats' : null,
        ]),
    ])->save();

    return $b->refresh();
}

/** [kind, code, quantity, total_net] per line. */
function addonSeatAmountLines(TestBillable $billable, string $plan, int $seats, array $addons): array
{
    $catalog = app(SubscriptionCatalogInterface::class);

    return array_map(
        fn (array $l): array => [$l['kind'], $l['code'], $l['quantity'], $l['total_net']],
        SubscriptionAmount::lineItems($catalog, $billable, $plan, 'monthly', max(0, $seats - $catalog->includedSeats($plan)), $addons),
    );
}

function addonSeatAmountNet(TestBillable $billable, string $plan, int $seats, array $addons): int
{
    return SubscriptionAmount::net(app(SubscriptionCatalogInterface::class), $billable, $plan, 'monthly', $seats, $addons);
}

function addonSeatAmountPatched(MollieUpdateSubscriptionRequest $request, string $property): mixed
{
    $p = (new ReflectionClass($request))->getProperty($property);
    $p->setAccessible(true);

    return $p->getValue($request);
}

it('prices the ticket example: base and per-seat price, every seat counted', function (): void {
    $billable = addonSeatAmountBillable('team', 12, ['crm']);

    // 50 + 2 × 5 + 20 + 12 × 5 = 140.
    expect(addonSeatAmountNet($billable, 'team', 12, ['crm']))->toBe(14000)
        ->and(addonSeatAmountLines($billable, 'team', 12, ['crm']))->toBe([
            ['plan', 'team', 1, 5000],
            ['seats', null, 2, 1000],
            ['addon', 'crm', 1, 2000],
            ['addon_seats', 'crm', 12, 6000],
        ]);
});

it('charges the plan\'s included seats for the addon too', function (): void {
    // Ten seats, all of them included in the plan — and all ten carry the addon.
    $billable = addonSeatAmountBillable('team', 10, ['crm']);

    expect(addonSeatAmountNet($billable, 'team', 10, ['crm']))->toBe(5000 + 2000 + 10 * 500)
        ->and(addonSeatAmountLines($billable, 'team', 10, ['crm']))->toBe([
            ['plan', 'team', 1, 5000],
            ['addon', 'crm', 1, 2000],
            ['addon_seats', 'crm', 10, 5000],
        ]);
});

it('takes the addon\'s own free seats off, independently of the plan\'s', function (): void {
    $billable = addonSeatAmountBillable('team', 12, ['sso']);

    // 12 seats, 5 free on the addon → 7 charged. Base is 0 and still gets its line.
    expect(addonSeatAmountNet($billable, 'team', 12, ['sso']))->toBe(5000 + 1000 + 7 * 200)
        ->and(addonSeatAmountLines($billable, 'team', 12, ['sso']))->toBe([
            ['plan', 'team', 1, 5000],
            ['seats', null, 2, 1000],
            ['addon', 'sso', 1, 0],
            ['addon_seats', 'sso', 7, 1400],
        ]);
});

it('emits no addon_seats line while the seats stay within the addon\'s free ones', function (): void {
    $billable = addonSeatAmountBillable('solo', 4, ['sso']);

    expect(addonSeatAmountNet($billable, 'solo', 4, ['sso']))->toBe(1000 + 3 * 500)
        ->and(array_column(addonSeatAmountLines($billable, 'solo', 4, ['sso']), 0))->toBe(['plan', 'seats', 'addon']);

    // One past the boundary: exactly one charged.
    expect(addonSeatAmountNet($billable, 'solo', 6, ['sso']))->toBe(1000 + 5 * 500 + 200);
});

it('leaves an addon without a seat price exactly as it was', function (): void {
    $billable = addonSeatAmountBillable('team', 12, ['flat']);

    expect(addonSeatAmountNet($billable, 'team', 12, ['flat']))->toBe(5000 + 1000 + 900)
        ->and(addonSeatAmountLines($billable, 'team', 12, ['flat']))->toBe([
            ['plan', 'team', 1, 5000],
            ['seats', null, 2, 1000],
            ['addon', 'flat', 1, 900],
        ]);
});

it('adds up several addons, and the lines always sum to the net', function (): void {
    $billable = addonSeatAmountBillable('team', 12, ['crm', 'sso', 'flat']);

    $net = addonSeatAmountNet($billable, 'team', 12, ['crm', 'sso', 'flat']);
    $lines = addonSeatAmountLines($billable, 'team', 12, ['crm', 'sso', 'flat']);

    expect($net)->toBe(5000 + 1000 + 2000 + 6000 + 0 + 1400 + 900)
        ->and(array_sum(array_column($lines, 3)))->toBe($net);
});

it('bills a seat count below the plan\'s included seats as the included seats', function (): void {
    // Every billing path carries seats as "extra beyond included", so 4 of 10 is 10.
    $billable = addonSeatAmountBillable('team', 4, ['crm']);

    $lines = addonSeatAmountLines($billable, 'team', 4, ['crm']);

    expect(addonSeatAmountNet($billable, 'team', 4, ['crm']))->toBe(5000 + 2000 + 10 * 500)
        ->and(array_sum(array_column($lines, 3)))->toBe(12000);
});

it('answers "is this addon free?" from base and seat price together', function (): void {
    $catalog = app(SubscriptionCatalogInterface::class);

    expect($catalog->addonIsFree('badge', 'monthly'))->toBeTrue()
        ->and($catalog->addonIsFree('flat', 'monthly'))->toBeFalse()
        ->and($catalog->addonIsFree('crm', 'monthly'))->toBeFalse()
        // Base 0, but a seat price: not free.
        ->and($catalog->addonIsFree('sso', 'monthly'))->toBeFalse()
        ->and($catalog->addonSeatPriceNet('flat', 'monthly'))->toBeNull()
        ->and($catalog->addonSeatPriceNet('crm', 'monthly'))->toBe(500)
        ->and($catalog->addonIncludedSeats('sso'))->toBe(5)
        ->and($catalog->addonIncludedSeats('crm'))->toBe(0);
});

it('refuses a base-0 per-seat addon on a Local subscription, which nothing could bill', function (): void {
    $this->travelTo(Carbon::parse('2026-01-11 12:00:00', 'UTC'));
    $billable = addonSeatAmountBillable('free', 3, [], SubscriptionSource::Local);

    expect(fn () => app(UpdateSubscription::class)->update($billable, ['addons' => ['sso']]))
        ->toThrow(LocalSubscriptionDoesNotSupportPaidExtrasException::class);

    expect($billable->refresh()->getActiveBillingAddonCodes())->toBe([]);

    // A genuinely free addon is still fine.
    app(UpdateSubscription::class)->update($billable, ['addons' => ['badge']]);
    expect($billable->refresh()->getActiveBillingAddonCodes())->toBe(['badge']);
});

it('previews, patches Mollie and invoices the renewal at one and the same amount', function (): void {
    $billable = addonSeatAmountBillable('team', 12, ['crm', 'sso']);

    // 5000 + 1000 + 2000 + 6000 + 0 + 1400 = 15400 net. VAT per line at 20 %:
    // 1000 + 200 + 400 + 1200 + 0 + 280 = 3080 → 184.80 gross.
    $preview = app(PreviewService::class)->previewUpdate($billable, ['seats' => 12]);

    expect($preview['newPriceNet'])->toBe(15400)
        ->and($preview['grossTotal'])->toBe(18480)
        ->and(array_column($preview['lineItems'], 'kind'))->toBe(['plan', 'seat', 'addon', 'addon_seats', 'addon', 'addon_seats']);

    $captured = null;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured) {
        $captured = $request;

        return (object) ['id' => 'sub_addon_seats'];
    });

    app(MollieSubscriptionPatcher::class)->updateForIntent($billable, new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'team', newPlan: 'team',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 12, newSeats: 12,
        currentAddons: ['crm' => 1, 'sso' => 1], newAddons: ['crm' => 1, 'sso' => 1],
    ));

    expect($captured)->toBeInstanceOf(MollieUpdateSubscriptionRequest::class)
        ->and(addonSeatAmountPatched($captured, 'amount')->value)->toBe('184.80');

    AddonSeatPricingWebhookController::$nextPayment = (object) [
        'id' => 'tr_addon_seats_renewal',
        'status' => 'paid',
        'amount' => (object) ['value' => '184.80', 'currency' => 'EUR'],
        'paidAt' => '2026-02-01T09:00:30Z',
        'subscriptionId' => 'sub_addon_seats',
        'countryCode' => 'AT',
        'metadata' => [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];
    $this->travelTo(Carbon::parse('2026-02-01 09:01:00', 'UTC'));
    $this->postJson(route('billing.webhook'), ['id' => 'tr_addon_seats_renewal'])->assertStatus(200);

    $invoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_addon_seats_renewal')->firstOrFail();
    $lines = array_map(fn (array $l): array => [$l['kind'], $l['code'], $l['quantity'], $l['amount_net']], (array) $invoice->line_items);

    expect((int) $invoice->amount_net)->toBe(15400)
        ->and((int) $invoice->amount_gross)->toBe(18480)
        ->and($lines)->toBe([
            ['plan', 'team', 1, 5000],
            ['seats', null, 2, 1000],
            ['addon', 'crm', 1, 2000],
            ['addon_seats', 'crm', 12, 6000],
            ['addon', 'sso', 1, 0],
            ['addon_seats', 'sso', 7, 1400],
        ])
        // The seat count is read off the plan's seat line only — 12, not 12 + 12 + 7.
        ->and($invoice->deriveSeatCount())->toBe(12)
        ->and($billable->refresh()->getBillingSeatCount())->toBe(12);
});

it('applies a percentage recurring coupon to the amount including the addon seats', function (): void {
    $billable = addonSeatAmountBillable('team', 12, ['crm']);
    $coupons = app(CouponService::class);

    $coupon = $coupons->create([
        'code' => 'HALF380', 'name' => 'Half', 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 50,
        'max_redemptions_per_billable' => 6,
    ]);
    $coupons->applyRecurringMarker($coupon, $billable, 14000, 'monthly');
    $billable->refresh();

    expect($coupons->computeMarkerDiscount($billable, addonSeatAmountNet($billable, 'team', 12, ['crm'])))->toBe(7000);
});

it('applies a fixed recurring coupon to the amount including the addon seats', function (): void {
    $billable = addonSeatAmountBillable('team', 12, ['crm']);
    $coupons = app(CouponService::class);

    $coupon = $coupons->create([
        'code' => 'FIX380', 'name' => 'Fixed', 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Fixed, 'discount_value' => 1400,
        'max_redemptions_per_billable' => 6,
    ]);
    $coupons->applyRecurringMarker($coupon, $billable, 14000, 'monthly');
    $billable->refresh();

    expect($coupons->computeMarkerDiscount($billable, addonSeatAmountNet($billable, 'team', 12, ['crm'])))->toBe(1400);
});

it('charges a per-seat addon added under a 100 % coupon from the next regular billing date', function (): void {
    $coupons = app(CouponService::class);
    $coupon = $coupons->create([
        'code' => 'FREE380', 'name' => 'Free', 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 100,
        'max_redemptions_per_billable' => 3,
    ]);

    $billable = addonSeatAmountBillable('solo', 1, []);
    $billable->forceFill(['subscription_period_starts_at' => BillingTime::nowUtc()->subDays(2)])->save();

    // 100 % of the 10.00 plan; Mollie's charge is deferred past the coupon.
    $coupons->applyRecurringMarker($coupon, $billable->fresh(), 1000, 'monthly');
    $billable = $billable->fresh();
    $meta = $billable->getBillingSubscriptionMeta();
    $meta[ChargeDeferral::META_KEY] = Carbon::parse((string) $meta['active_recurring_coupon']['valid_until'])->addDay()->toIso8601String();
    $billable->forceFill(['subscription_meta' => $meta, 'active_addon_codes' => ['crm']])->save();
    $billable = $billable->fresh();

    $captured = null;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured) {
        $captured = $request;

        return (object) ['id' => 'sub_addon_seats'];
    });

    // The CRM addon is added: 20.00 base + 1 seat × 5.00. The coupon stays locked to the
    // 10.00 it was granted on, so 25.00 net is owed — 30.00 gross at 20 %.
    $net = addonSeatAmountNet($billable, 'solo', 1, ['crm']);
    expect($net)->toBe(3500);

    app(MollieSubscriptionPatcher::class)->updateRecurringAmount(
        billable: $billable,
        planCode: 'solo',
        interval: 'monthly',
        addons: ['crm'],
        extraSeats: 0,
        couponDiscountNet: $coupons->computeMarkerDiscount($billable, $net),
    );

    expect($captured)->toBeInstanceOf(MollieUpdateSubscriptionRequest::class)
        ->and(addonSeatAmountPatched($captured, 'amount')->value)->toBe('30.00');

    // Not left on the date deferred past the coupon — pulled back to the regular cycle.
    $startDate = addonSeatAmountPatched($captured, 'startDate');
    expect($startDate)->not->toBeNull()
        ->and(str_contains((string) $startDate, $billable->nextBillingDate()->format('Y-m-d')))->toBeTrue();
});

it('creates the Mollie subscription at the amount including the addon seats', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Create Co',
        'email' => uniqid('addon-seats-create-').'@x.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_addon_seats_create',
    ]);

    $captured = null;
    Mollie::shouldReceive('send')->once()->andReturnUsing(function ($request) use (&$captured) {
        $captured = $request;

        return (object) ['id' => 'sub_addon_seats_create'];
    });

    app(\GraystackIT\MollieBilling\Services\Billing\CreateSubscription::class)->handle($billable->fresh(), [
        'plan_code' => 'team',
        'interval' => 'monthly',
        'addon_codes' => ['crm'],
        'extra_seats' => 2,
    ]);

    $amount = (new ReflectionObject($captured))->getProperty('amount');
    $amount->setAccessible(true);

    // 14000 net → 168.00 at 20 %.
    expect($amount->getValue($captured)->value)->toBe('168.00');
});

it('books the first payment with the addon seats on their own line', function (): void {
    $billable = addonSeatAmountBillable('team', 12, ['crm']);

    $invoice = app(\GraystackIT\MollieBilling\Services\Webhook\FirstPaymentArtifacts::class)->persist(
        (object) [
            'id' => 'tr_addon_seats_first',
            'amount' => (object) ['value' => '168.00', 'currency' => 'EUR'],
            'customerId' => 'cst_addon_seats',
            'mandateId' => 'mdt_addon_seats',
            'countryCode' => 'AT',
        ],
        $billable,
        'team',
        'monthly',
        ['crm'],
        2,
    );

    $lines = array_map(fn (array $l): array => [$l['kind'], $l['code'], $l['quantity'], $l['amount_net']], (array) $invoice->line_items);

    expect((int) $invoice->amount_net)->toBe(14000)
        ->and((int) $invoice->amount_gross)->toBe(16800)
        ->and($lines)->toBe([
            ['plan', 'team', 1, 5000],
            ['seats', null, 2, 1000],
            ['addon', 'crm', 1, 2000],
            ['addon_seats', 'crm', 12, 6000],
        ]);
});
