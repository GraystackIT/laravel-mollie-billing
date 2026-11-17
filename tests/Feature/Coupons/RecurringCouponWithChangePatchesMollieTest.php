<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Jobs\RetrySubscriptionPatchJob;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ChargeDeferral;
use Carbon\Carbon;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest;
use Illuminate\Support\Facades\Queue;
use Mollie\Laravel\Facades\Mollie;

/*
 * A recurring code entered together with a seat or addon change.
 *
 * `update()` PATCHes Mollie's recurring amount in the same call as the local change
 * (phase 2c) and redeems the coupon afterwards (phase 3) — and the PATCH is priced from
 * the marker the redemption writes. So the amount sent was the undiscounted one: until
 * the pro-rata charge settled (days, with SEPA) Mollie held the full price, and where
 * there is no charge at all — a seat added during a trial — it held it for good. Every
 * renewal was then collected in full and booked with an `adjustment` against the
 * discount the customer had been shown.
 *
 * Pro 50.00 (2 seats included, 5.00 per extra seat) + SSO 15.00, 20 % VAT.
 */

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro', 'tier' => 2, 'included_seats' => 2, 'feature_keys' => [], 'allowed_addons' => ['sso'],
        'intervals' => ['monthly' => ['base_price_net' => 5000, 'seat_price_net' => 500, 'included_usages' => []]],
    ]);
    config()->set('mollie-billing-plans.addons.sso', [
        'name' => 'SSO', 'feature_keys' => [], 'intervals' => ['monthly' => ['price_net' => 1500]],
    ]);

    ChangeWithCodeMollie::$patches = [];
    ChangeWithCodeMollie::$startDates = [];
    ChangeWithCodeMollie::$refunds = [];
    ChangeWithCodeMollie::$failPatches = false;
    ChangeWithCodeMollie::$onPatch = null;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) {
        if ($request instanceof UpdateSubscriptionRequest) {
            if (ChangeWithCodeMollie::$failPatches) {
                throw new RuntimeException('Mollie is unreachable.');
            }

            $p = (new ReflectionClass($request))->getProperty('amount');
            $p->setAccessible(true);
            ChangeWithCodeMollie::$patches[] = $p->getValue($request)?->value;
            $d = (new ReflectionClass($request))->getProperty('startDate');
            $d->setAccessible(true);
            ChangeWithCodeMollie::$startDates[] = $d->getValue($request) !== null ? (string) $d->getValue($request) : null;

            if (ChangeWithCodeMollie::$onPatch !== null) {
                (ChangeWithCodeMollie::$onPatch)();
            }
        }

        if ($request instanceof \Mollie\Api\Http\Requests\CreatePaymentRefundRequest) {
            $a = (new ReflectionClass($request))->getProperty('amount');
            $a->setAccessible(true);
            ChangeWithCodeMollie::$refunds[] = $a->getValue($request)?->value;

            return (object) ['id' => 're_change_code', 'status' => 'pending'];
        }

        return (object) ['id' => 'tr_change_code', 'status' => 'open', 'getCheckoutUrl' => null];
    });

    app(CouponService::class)->create([
        'code' => 'REC20', 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 20, 'discount_periods' => 6,
    ]);
});

final class ChangeWithCodeMollie
{
    /** @var list<?string> */
    public static array $patches = [];

    /** @var list<?string> the `startDate` of each PATCH, as Mollie receives it */
    public static array $startDates = [];

    /** @var list<?string> */
    public static array $refunds = [];

    public static bool $failPatches = false;

    /** Runs right after Mollie has taken a PATCH — what happens in the meantime elsewhere. */
    public static ?Closure $onPatch = null;
}

function changeWithCodeBillable(SubscriptionStatus $status): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Change', 'email' => uniqid('cc-').'@x.test', 'billing_country' => 'AT']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => $status,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(10),
        'trial_ends_at' => $status === SubscriptionStatus::Trial ? BillingTime::nowUtc()->addDays(4) : null,
        'subscription_meta' => ['mollie_subscription_id' => 'sub_change_code', 'seat_count' => 2],
        'mollie_customer_id' => 'cst_change_code',
        'mollie_mandate_id' => 'mdt_change_code',
    ])->save();

    return $billable->fresh();
}

it('leaves Mollie holding the discounted amount when a recurring code comes with the change', function (SubscriptionStatus $status, array $change, string $expected): void {
    $billable = changeWithCodeBillable($status);

    app(UpdateSubscription::class)->update($billable, $change + ['coupon_codes' => ['REC20']]);

    expect($billable->fresh()->getBillingSubscriptionMeta()['active_recurring_coupon']['code'] ?? null)->toBe('REC20')
        ->and(ChangeWithCodeMollie::$patches)->not->toBe([])
        // Whatever was sent on the way, the last word Mollie got is the discounted price.
        ->and(end(ChangeWithCodeMollie::$patches))->toBe($expected);
})->with([
    // 50.00 + 2 × 5.00 = 60.00, − 20 % = 48.00 + 20 % VAT.
    'two seats, paying (pro-rata charge in flight)' => [SubscriptionStatus::Active, ['seats' => 4], '57.60'],
    // 50.00 + 15.00 = 65.00, − 20 % = 52.00 + 20 % VAT.
    'an addon, paying' => [SubscriptionStatus::Active, ['addons' => ['sso']], '62.40'],
    // During a trial nothing is charged for the change: there is no later PATCH at all.
    'two seats, during a trial' => [SubscriptionStatus::Trial, ['seats' => 4], '57.60'],
    'an addon, during a trial' => [SubscriptionStatus::Trial, ['addons' => ['sso']], '62.40'],
]);

it('sends one PATCH and no more when no code comes with the change', function (): void {
    $billable = changeWithCodeBillable(SubscriptionStatus::Active);

    app(UpdateSubscription::class)->update($billable, ['seats' => 4]);

    expect(ChangeWithCodeMollie::$patches)->toBe(['72.00']);
});

/*
 * The first PATCH already carries the discount.
 *
 * The re-price above made the LAST word right; until then Mollie held the undiscounted
 * amount for the length of a request, and a renewal created in that window was collected
 * in full. `update()` now prices the PATCH from the marker its redemption is about to
 * write (`CouponService::recurringMarkerFor()`), so there is one PATCH and it is the
 * discounted one.
 */
it('sends the discounted amount with the one and only PATCH', function (SubscriptionStatus $status, array $change, string $expected, array $addonsBefore): void {
    $billable = changeWithCodeBillable($status);
    $billable->forceFill(['active_addon_codes' => $addonsBefore])->save();

    app(UpdateSubscription::class)->update($billable->fresh(), $change + ['coupon_codes' => ['REC20']]);

    expect(ChangeWithCodeMollie::$patches)->toBe([$expected])
        ->and($billable->fresh()->getBillingSubscriptionMeta()['active_recurring_coupon']['code'] ?? null)->toBe('REC20')
        ->and(CouponRedemption::query()->count())->toBe(1);
})->with([
    'two seats, paying (pro-rata charge in flight)' => [SubscriptionStatus::Active, ['seats' => 4], '57.60', []],
    'an addon, paying' => [SubscriptionStatus::Active, ['addons' => ['sso']], '62.40', []],
    'two seats, during a trial' => [SubscriptionStatus::Trial, ['seats' => 4], '57.60', []],
    'an addon, during a trial' => [SubscriptionStatus::Trial, ['addons' => ['sso']], '62.40', []],
    // 50.00, − 20 % = 40.00 + 20 % VAT. Nothing to charge: credits only, or nothing at all.
    'an addon given back, paying' => [SubscriptionStatus::Active, ['addons' => []], '48.00', ['sso']],
]);

it('keeps the redemption and lets the retry send the discount when the PATCH fails', function (): void {
    Queue::fake();
    $billable = changeWithCodeBillable(SubscriptionStatus::Trial);

    ChangeWithCodeMollie::$failPatches = true;
    app(UpdateSubscription::class)->update($billable, ['seats' => 4, 'coupon_codes' => ['REC20']]);

    $billable->refresh();
    $pending = $billable->getBillingSubscriptionMeta()['pending_subscription_patch'] ?? null;

    // The change is in force (a PATCH failure is queued, never thrown) — and so is the
    // code, once: one redemption, the marker, a retry waiting.
    expect($billable->getBillingSeatCount())->toBe(4)
        ->and(CouponRedemption::query()->count())->toBe(1)
        ->and((int) Coupon::query()->where('code', 'REC20')->value('redemptions_count'))->toBe(1)
        ->and($billable->getBillingSubscriptionMeta()['active_recurring_coupon']['code'] ?? null)->toBe('REC20')
        ->and($pending)->toBeArray()
        ->and(ChangeWithCodeMollie::$patches)->toBe([]);

    // Mollie is back: whatever retry runs, it prices from the marker as written.
    ChangeWithCodeMollie::$failPatches = false;
    $payload = isset($pending['intent']) ? $pending['intent'] : $pending + ['billable_type' => $billable::class, 'billable_id' => $billable->getKey()];
    (new RetrySubscriptionPatchJob($payload, (string) $pending['first_attempt_at']))
        ->handle(app(MollieSubscriptionPatcher::class), app(CouponService::class));

    foreach (Queue::pushed(RetrySubscriptionPatchJob::class) as $job) {
        $job->handle(app(MollieSubscriptionPatcher::class), app(CouponService::class));
    }

    expect(ChangeWithCodeMollie::$patches)->not->toBe([])
        ->and(array_unique(ChangeWithCodeMollie::$patches))->toBe(['57.60'])
        ->and(CouponRedemption::query()->count())->toBe(1);
});

it('puts Mollie back when the change fails after the discounted PATCH, and a second attempt goes through once', function (): void {
    $billable = changeWithCodeBillable(SubscriptionStatus::Trial);

    // The campaign fills up while the PATCH travels: the redemption in the change's own
    // transaction is refused, and the whole change with it.
    ChangeWithCodeMollie::$onPatch = function (): void {
        ChangeWithCodeMollie::$onPatch = null;
        Coupon::query()->where('code', 'REC20')->update(['max_redemptions' => 1, 'redemptions_count' => 1]);
    };

    expect(fn () => app(UpdateSubscription::class)->update($billable, ['seats' => 4, 'coupon_codes' => ['REC20']]))
        ->toThrow(InvalidCouponException::class);

    $billable->refresh();

    // Nothing of the change, nothing of the code — and Mollie holds what the books say:
    // Pro with its two included seats, 50.00 + 20 %, not the discounted 57.60 it was sent.
    expect($billable->getBillingSeatCount())->toBe(2)
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('active_recurring_coupon')
        ->and(CouponRedemption::query()->count())->toBe(0)
        ->and(end(ChangeWithCodeMollie::$patches))->toBe('60.00');

    // The operator makes room; the customer tries again. One redemption, one discount.
    Coupon::query()->where('code', 'REC20')->update(['max_redemptions' => null, 'redemptions_count' => 0]);
    ChangeWithCodeMollie::$patches = [];

    app(UpdateSubscription::class)->update($billable->fresh(), ['seats' => 4, 'coupon_codes' => ['REC20']]);

    expect(ChangeWithCodeMollie::$patches)->toBe(['57.60'])
        ->and(CouponRedemption::query()->count())->toBe(1)
        ->and((int) Coupon::query()->where('code', 'REC20')->value('redemptions_count'))->toBe(1)
        ->and($billable->fresh()->getBillingSeatCount())->toBe(4);
});

it('sends the discount with the PATCH when a stored change is applied on the legacy path', function (): void {
    $billable = changeWithCodeBillable(SubscriptionStatus::Active);
    $meta = $billable->getBillingSubscriptionMeta();
    $meta['pending_plan_change'] = [
        'current_plan' => 'pro', 'current_interval' => 'monthly', 'current_seats' => 2, 'current_addons' => [],
        'plan_code' => 'pro', 'interval' => 'monthly', 'seats' => 4, 'addons' => [],
        'new_net' => 6000, 'prorata_charge_net' => 0, 'coupon_codes' => ['REC20'],
        'requested_at' => now()->toIso8601String(),
    ];
    $billable->forceFill(['subscription_meta' => $meta])->save();

    app(UpdateSubscription::class)->applyPendingPlanChange($billable->fresh());

    expect(ChangeWithCodeMollie::$patches)->toBe(['57.60'])
        ->and($billable->fresh()->getBillingSubscriptionMeta()['active_recurring_coupon']['code'] ?? null)->toBe('REC20');
});

/*
 * A code that covers everything, entered with a change. Mollie cannot hold 0, so it keeps
 * the full price and the first charge is moved past the free charges. That date is now
 * taken from the marker built before the PATCH; it has to be the date that holds once the
 * redemption has written its own marker — what `ChargeDeferral` reports afterwards and
 * what the written marker gives: the day after its end.
 */
it('defers Mollie\'s first charge, in the one PATCH, to exactly the date the written marker gives', function (array $coupon, string $expected): void {
    // The period began on 1 January at 10:00; two seats are added on the 11th with a code
    // that covers everything. Its discount reaches the seats' prorata charge for the running
    // period, so that period is the first one it covers and Mollie collects again on a
    // regular charge date — the 1st — never a day the change happened to fall on.
    Carbon::setTestNow(Carbon::parse('2027-01-11 10:00:00', 'UTC'));

    try {
        app(CouponService::class)->create(['code' => 'FREE100', 'type' => CouponType::Recurring, 'discount_type' => DiscountType::Percentage, 'discount_value' => 100] + $coupon);
        $billable = changeWithCodeBillable(SubscriptionStatus::Active);

        app(UpdateSubscription::class)->update($billable, ['seats' => 4, 'coupon_codes' => ['FREE100']]);

        $billable->refresh();
        $marker = $billable->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null;

        // Full price held — 50.00 + 2 × 5.00 = 60.00 + 20 % — in a single PATCH …
        expect($marker['code'] ?? null)->toBe('FREE100')
            ->and(ChangeWithCodeMollie::$patches)->toBe(['72.00'])
            // … the date the package believes after the redemption, and the one the
            // written marker gives.
            ->and(ChargeDeferral::nextChargeDate($billable)?->toDateString())->toBe($expected)
            ->and(Carbon::parse((string) $marker['charge_resumes_at'])->toDateString())->toBe($expected);

        if ($expected === '2027-02-01') {
            // The code covers only the running period: the next charge is the regular one,
            // nothing is deferred and no date is sent.
            expect(ChangeWithCodeMollie::$startDates)->toBe([null])
                ->and(ChargeDeferral::until($billable))->toBeNull();
        } else {
            // … and the PATCH already carries the deferred date.
            expect(ChangeWithCodeMollie::$startDates)->toBe([$expected])
                ->and(ChargeDeferral::until($billable)?->toDateString())->toBe($expected);
        }
    } finally {
        Carbon::setTestNow();
    }
})->with([
    // Two periods: January (its prorata) and February; Mollie charges on 1 March. In
    // 30-day blocks from the change it was 13 March.
    'limited by discount_periods' => [['discount_periods' => 2], '2027-03-01'],
    // Ends on 20 February: the 1 February renewal is still covered, the first one that
    // is not is 1 March. The day after the end (21 February) moved the cycle to the 21st.
    'limited by valid_until' => [['valid_until' => '2027-02-20T10:00:00+00:00'], '2027-03-01'],
    // Ends at 23:00 on the day before a renewal: that renewal is the first not covered.
    'limited by valid_until, ending the evening before a renewal' => [['valid_until' => '2027-01-31T23:00:00+00:00'], '2027-02-01'],
]);

it('queues putting Mollie back when the change fails and Mollie cannot be reached for it, and the retry restores the committed amount', function (): void {
    $billable = changeWithCodeBillable(SubscriptionStatus::Trial);

    // The campaign fills up while the PATCH travels, and Mollie is gone right after it.
    ChangeWithCodeMollie::$onPatch = function (): void {
        ChangeWithCodeMollie::$onPatch = null;
        ChangeWithCodeMollie::$failPatches = true;
        Coupon::query()->where('code', 'REC20')->update(['max_redemptions' => 1, 'redemptions_count' => 1]);
    };

    expect(fn () => app(UpdateSubscription::class)->update($billable, ['seats' => 4, 'coupon_codes' => ['REC20']]))
        ->toThrow(InvalidCouponException::class);

    $billable->refresh();
    $pending = $billable->getBillingSubscriptionMeta()['pending_subscription_patch'] ?? null;

    // Mollie still holds the discounted new amount; putting it back is queued.
    expect(ChangeWithCodeMollie::$patches)->toBe(['57.60'])
        ->and($pending['reason'] ?? null)->toBe('plan_waiver_sync')
        ->and(CouponRedemption::query()->count())->toBe(0)
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('active_recurring_coupon')
        ->and($billable->getBillingSeatCount())->toBe(2);

    ChangeWithCodeMollie::$failPatches = false;
    (new RetrySubscriptionPatchJob($pending + ['billable_type' => $billable::class, 'billable_id' => $billable->getKey()], (string) $pending['first_attempt_at']))
        ->handle(app(MollieSubscriptionPatcher::class), app(CouponService::class));

    // What the books say: Pro with its two included seats, 50.00 + 20 %, no discount.
    expect(end(ChangeWithCodeMollie::$patches))->toBe('60.00')
        ->and($billable->fresh()->getBillingSubscriptionMeta())->not->toHaveKey('pending_subscription_patch')
        ->and(CouponRedemption::query()->count())->toBe(0);
});

it('credits an addon given back with a code at its unused share and discounts only the renewals', function (): void {
    // 1 January to 1 February, 31 days; the change on the morning of the 11th: 21 left.
    Carbon::setTestNow(Carbon::parse('2026-01-11 00:00:00', 'UTC'));
    \Illuminate\Support\Facades\Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('resetIdempotencyKey')->withAnyArgs()->andReturnSelf();

    try {
        $billable = changeWithCodeBillable(SubscriptionStatus::Active);
        $billable->forceFill([
            'active_addon_codes' => ['sso'],
            'subscription_period_starts_at' => Carbon::parse('2026-01-01 00:00:00', 'UTC'),
        ])->save();

        // The renewal that paid for the running period: Pro 50.00 + SSO 15.00, 20 % VAT.
        $renewal = \GraystackIT\MollieBilling\Models\BillingInvoice::create([
            'billable_type' => $billable->getMorphClass(), 'billable_id' => $billable->getKey(),
            'mollie_payment_id' => 'tr_renewal_jan', 'mollie_subscription_id' => 'sub_change_code',
            'invoice_kind' => \GraystackIT\MollieBilling\Enums\InvoiceKind::Subscription,
            'status' => \GraystackIT\MollieBilling\Enums\InvoiceStatus::Paid,
            'country' => 'AT', 'currency' => 'EUR', 'amount_net' => 6500, 'amount_vat' => 1300, 'amount_gross' => 7800,
            'line_items' => array_map(fn (array $l): array => $l + [
                'quantity' => 1, 'unit_price_net' => $l['total_net'], 'amount_net' => $l['total_net'],
                'vat_rate' => 20.0, 'vat_amount' => (int) round($l['total_net'] * 0.2),
                'amount_gross' => $l['total_net'] + (int) round($l['total_net'] * 0.2),
                'period_start' => '2026-01-01T00:00:00+00:00', 'period_end' => '2026-02-01T00:00:00+00:00',
            ], [
                ['kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'total_net' => 5000],
                ['kind' => 'addon', 'code' => 'sso', 'label' => 'SSO', 'total_net' => 1500],
            ]),
            'period_start' => Carbon::parse('2026-01-01 00:00:00', 'UTC'),
            'period_end' => Carbon::parse('2026-02-01 00:00:00', 'UTC'),
        ]);

        $result = app(UpdateSubscription::class)->update($billable->fresh(), ['addons' => [], 'coupon_codes' => ['REC20']]);
    } finally {
        Carbon::setTestNow();
    }

    // The rule: what is given back is credited at its unused share, 15.00 × 21 / 31 =
    // 10.16 net + 20 % = 12.19, as a credit note against the renewal, paid back at Mollie.
    $creditNote = \GraystackIT\MollieBilling\Models\BillingInvoice::query()
        ->where('billable_id', $billable->getKey())->whereKeyNot($renewal->id)->latest('id')->first();
    // A code reduces what is charged; nothing is charged now, so it reduces nothing now:
    // the redemption carries no discount, the recurring amount carries it — Pro 50.00 −
    // 20 % = 40.00 + 20 % = 48.00, from the next renewal.
    $redemption = CouponRedemption::query()->sole();

    expect($creditNote)->not->toBeNull()
        ->and(abs((int) $creditNote->amount_net))->toBe(1016)
        ->and(abs((int) $creditNote->amount_gross))->toBe(1219)
        ->and(ChangeWithCodeMollie::$refunds)->toBe(['12.19'])
        ->and((int) $redemption->discount_amount_net)->toBe(0)
        ->and(ChangeWithCodeMollie::$patches)->toBe(['48.00'])
        ->and($billable->fresh()->getActiveBillingAddonCodes())->toBe([])
        ->and($result['couponApplied'] ?? null)->toBe('REC20');
});

function legacyPendingWithCode(TestBillable $billable, string $code = 'REC20'): void
{
    $meta = $billable->getBillingSubscriptionMeta();
    $meta['pending_plan_change'] = [
        'current_plan' => 'pro', 'current_interval' => 'monthly', 'current_seats' => 2, 'current_addons' => [],
        'plan_code' => 'pro', 'interval' => 'monthly', 'seats' => 4, 'addons' => [],
        'new_net' => 6000, 'prorata_charge_net' => 0, 'coupon_codes' => [$code],
        'requested_at' => now()->toIso8601String(),
    ];
    $billable->forceFill(['subscription_meta' => $meta])->save();
}

it('sends the legacy path\'s one PATCH undiscounted when the code can no longer give a discount', function (Closure $spoil, string $code): void {
    // The legacy path does not re-validate (the charge has settled) and logs a redemption
    // it cannot write. A code it already knows it cannot redeem, or that discounts nothing
    // any more, must not reach Mollie as a discount first — 50.00 + 2 × 5.00 = 60.00 + 20 %.
    $billable = changeWithCodeBillable(SubscriptionStatus::Active);
    $spoil($billable);
    legacyPendingWithCode($billable, $code);

    app(UpdateSubscription::class)->applyPendingPlanChange($billable->fresh());

    $billable->refresh();

    expect(ChangeWithCodeMollie::$patches)->toBe(['72.00'])
        ->and($billable->getBillingSeatCount())->toBe(4)
        ->and(app(CouponService::class)->computeMarkerDiscount($billable, 6000))->toBe(0);
})->with([
    'the campaign is full' => [fn () => Coupon::query()->where('code', 'REC20')->update(['max_redemptions' => 1, 'redemptions_count' => 1]), 'REC20'],
    'the customer has used it up' => [function (TestBillable $billable): void {
        $redemption = new CouponRedemption();
        $redemption->coupon_id = (int) Coupon::query()->where('code', 'REC20')->value('id');
        $redemption->billable_type = $billable->getMorphClass();
        $redemption->billable_id = $billable->getKey();
        $redemption->applied_at = BillingTime::nowUtc()->subMonths(3);
        $redemption->discount_amount_net = 1000;
        $redemption->save();
    }, 'REC20'],
    'the code has expired' => [fn () => Coupon::query()->where('code', 'REC20')->update(['valid_until' => BillingTime::nowUtc()->subDay()]), 'REC20'],
    'the code is switched off' => [fn () => Coupon::query()->where('code', 'REC20')->update(['active' => false]), 'REC20'],
    'the code is not a recurring one' => [fn () => app(CouponService::class)->create([
        'code' => 'ONCE20', 'type' => CouponType::SinglePayment, 'discount_type' => DiscountType::Percentage, 'discount_value' => 20,
    ]), 'ONCE20'],
]);

it('puts Mollie on the full price when another customer takes the last slot while the legacy PATCH travels', function (): void {
    // Two redemptions of the last slot at the same moment: the code was redeemable when the
    // PATCH was priced and is not when phase 3 redeems. The re-price after phase 3 corrects
    // Mollie within the same request, and nothing stays redeemed.
    ChangeWithCodeMollie::$onPatch = function (): void {
        ChangeWithCodeMollie::$onPatch = null;
        Coupon::query()->where('code', 'REC20')->update(['max_redemptions' => 1, 'redemptions_count' => 1]);
    };
    $billable = changeWithCodeBillable(SubscriptionStatus::Active);
    legacyPendingWithCode($billable);

    app(UpdateSubscription::class)->applyPendingPlanChange($billable->fresh());

    $billable->refresh();

    expect(ChangeWithCodeMollie::$patches)->toBe(['57.60', '72.00'])
        ->and($billable->getBillingSeatCount())->toBe(4)
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('active_recurring_coupon')
        ->and(CouponRedemption::query()->count())->toBe(0);
});
