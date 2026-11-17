<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\SubscriptionUpgradedFromLocal;
use GraystackIT\MollieBilling\Exceptions\LocalSubscriptionUpgradeRequiresMolliePathException;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\ActivateLocalSubscription;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\UpgradeLocalToMollie;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\BookingMatrix;
use Illuminate\Support\Facades\Event;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use Livewire\Livewire;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;

/*
 * A customer on a free plan upgrades on the PLAN SCREEN with a recurring code that covers
 * the whole price. Nothing is owed now, so there is nothing to pay — but a paid plan needs
 * a mandate. The screen used to refuse; it now does what the checkout does for the same
 * order: a €0 authorisation at Mollie (`mandate_only`), and the activation that follows it
 * is the checkout's own (`MandateOnlyPaymentHandler`) — one activation path, two doors.
 *
 * Every amount is computed here. Pro 29.50 net a month, SSO 15.50.
 */

afterEach(fn () => Carbon::setTestNow());

const FCU_START = '2026-03-10 09:00:00';

/** @param  array<string, int>  $lines */
function fcuGross(array $lines, float $rate): int
{
    return (int) array_sum(array_map(fn (int $n): int => $n + (int) round($n * $rate / 100), $lines));
}

function fcuCoupon(string $state): void
{
    $start = Carbon::parse(FCU_START, 'UTC');

    app(CouponService::class)->create(array_merge([
        'code' => 'FREE100', 'name' => 'All of it', 'active' => true, 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 100,
    ], match ($state) {
        'two periods' => ['discount_periods' => 2],
        'until a date' => ['valid_until' => $start->copy()->addDays(75)],
        // Over before the first charge a month from now.
        'until a date before the next charge' => ['valid_until' => $start->copy()->addDays(10)],
    }));
}

/** A customer on the free plan (local subscription), with wallets for its quota. */
function fcuFreeCustomer(BookingMatrix $m, string $country = 'AT', ?string $vatNumber = null, array $addons = []): TestBillable
{
    $billable = $vatNumber !== null ? $m->business($country, $vatNumber) : $m->billable($country);
    app(ActivateLocalSubscription::class)->handle($billable, 'free', 'monthly', $addons);

    return $billable->refresh();
}

function fcuWorld(): BookingMatrix
{
    $m = BookingMatrix::boot();
    config()->set('mollie-billing-plans.plans.free.intervals.monthly.included_usages', ['Tokens' => 10]);
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.included_usages', ['Tokens' => 100]);
    Carbon::setTestNow(Carbon::parse(FCU_START, 'UTC'));

    return $m;
}

/** Click through the plan screen: pick Pro, apply the code, confirm. */
function fcuUpgradeOnPlanScreen(): \Livewire\Features\SupportTesting\Testable
{
    $screen = Livewire::test('mollie-billing::plan-change')
        ->call('previewFor', 'pro')
        ->set('couponInput', 'FREE100')
        ->call('applyCoupon');

    expect($screen->get('couponError'))->toBeNull()
        ->and((int) $screen->get('preview')['grossTotal'])->toBe(0);

    return $screen->call('confirmAndPay');
}

/** What the books hold after an activation, in the terms both doors can be compared by. */
function fcuOutcome(BookingMatrix $m, TestBillable $billable): array
{
    $billable->refresh();
    $invoice = BillingInvoice::query()->where('billable_id', $billable->getKey())->latest('id')->first();

    return [
        'plan' => $billable->subscription_plan_code,
        'source' => $billable->subscription_source,
        'status' => $billable->subscription_status,
        'addons' => $billable->getActiveBillingAddonCodes(),
        'subscription_gross' => $m->subscriptionMinor(),
        'subscription_start' => $m->subscriptionStartDate(),
        'invoice_net' => (int) $invoice?->amount_net,
        'invoice_gross' => (int) $invoice?->amount_gross,
        'invoice_period' => [$invoice?->period_start?->toDateString(), $invoice?->period_end?->toDateString()],
        'invoice_lines' => collect((array) $invoice?->line_items)->map(fn (array $l): array => [$l['kind'] ?? null, (int) ($l['amount_net'] ?? 0), (int) ($l['vat_amount'] ?? 0)])->all(),
        'redemptions' => CouponRedemption::query()->where('billable_id', $billable->getKey())->count(),
        'tokens' => $billable->remainingBillingQuota('Tokens'),
    ];
}

it('upgrades a free customer under a fully covering recurring code through a €0 mandate, exactly as the checkout books it', function (string $state, string $country, ?string $vatNumber, float $rate): void {
    $m = fcuWorld();
    Event::fake([SubscriptionUpgradedFromLocal::class, \GraystackIT\MollieBilling\Events\PaymentAmountMismatch::class]);
    fcuCoupon($state);
    $billable = fcuFreeCustomer($m, $country, $vatNumber);

    fcuUpgradeOnPlanScreen();

    // A €0 authorisation, carrying the order.
    $payment = $m->lastPayment();
    expect($payment)->not->toBeNull()
        ->and(BookingMatrix::minor($payment))->toBe(0)
        ->and(BookingMatrix::prop($payment, 'sequenceType'))->toBe('first');
    $metadata = BookingMatrix::prop($payment, 'metadata');
    expect($metadata['type'])->toBe('mandate_only')
        ->and($metadata['pending_subscription_plan_code'])->toBe('pro')
        ->and($metadata['pending_subscription_coupon_code'])->toBe('FREE100')
        ->and($metadata['pending_subscription_upgrade_from_local'])->toBeTrue();

    // Nothing has changed before Mollie reports back.
    expect($billable->fresh()->subscription_plan_code)->toBe('free')
        ->and(CouponRedemption::query()->count())->toBe(0);

    $m->settle($payment, $country);
    $viaPlanScreen = fcuOutcome($m, $billable);

    expect($viaPlanScreen['plan'])->toBe('pro')
        ->and($viaPlanScreen['source'])->toBe(SubscriptionSource::Mollie)
        ->and($viaPlanScreen['status'])->toBe(SubscriptionStatus::Active)
        ->and($viaPlanScreen['invoice_net'])->toBe(0)
        ->and($viaPlanScreen['invoice_gross'])->toBe(0)
        ->and($viaPlanScreen['redemptions'])->toBe(1)
        // Mollie cannot hold 0: the full price, 29.50 plus VAT.
        ->and($viaPlanScreen['subscription_gross'])->toBe(fcuGross([2950], $rate))
        // The free plan's 10 are replaced by Pro's 100, not added to.
        ->and($viaPlanScreen['tokens'])->toBe(100);

    $firstCharge = Carbon::parse((string) $viaPlanScreen['subscription_start'], 'UTC');
    $start = Carbon::parse(FCU_START, 'UTC');
    match ($state) {
        // Two free months: the first charge is the third month's.
        'two periods' => expect($firstCharge->gte($start->copy()->addDays(60)->startOfDay()))->toBeTrue(),
        'until a date' => expect($firstCharge->gt($start->copy()->addDays(75)))->toBeTrue(),
        // The code is over before the next charge: this period is free, the next is not.
        'until a date before the next charge' => expect($firstCharge->toDateString())->toBe(BillingTime::addInterval($start, 'monthly')->toDateString()),
    };

    Event::assertDispatched(SubscriptionUpgradedFromLocal::class, 1);

    // Mollie delivers the same webhook again: nothing is booked or redeemed twice.
    $m->settle($payment, $country);
    expect(fcuOutcome($m, $billable))->toBe($viaPlanScreen)
        ->and(count($m->sentOf(CreateSubscriptionRequest::class)))->toBe(1)
        ->and(BillingInvoice::query()->count())->toBe(1);

    // The checkout, for a new customer with the same order and the same code.
    $other = $m->billable($country);
    if ($vatNumber !== null) {
        $other = $m->business($country, $vatNumber);
    }
    $m->checkout('pro', 'monthly', [], 0, 'FREE100')->call('submit');
    $m->settle($m->lastPayment(), $country);
    $viaCheckout = fcuOutcome($m, $other);

    expect(array_diff_key($viaPlanScreen, ['tokens' => 0]))->toBe(array_diff_key($viaCheckout, ['tokens' => 0]));

    // The first real charge is the full price, booked as such.
    MollieBilling::resolveBillableUsing(fn () => $other->fresh());
    $m->assertInvoice($m->renew($other, 'monthly', $country, Carbon::parse((string) $viaCheckout['subscription_start'], 'UTC')), 2950, fcuGross([2950], $rate) - 2950, $rate, ['plan' => 2950]);
    Event::assertNotDispatched(\GraystackIT\MollieBilling\Events\PaymentAmountMismatch::class);
})->with([
    'discount_periods 2' => ['two periods'],
    'valid_until in 75 days' => ['until a date'],
    'valid_until before the next charge' => ['until a date before the next charge'],
])->with([
    'AT 20 %' => ['AT', null, 20.0],
    'DE 19 %' => ['DE', null, 19.0],
    'DE business, reverse charge' => ['DE', 'DE123456789', 0.0],
]);

it('carries the addons the free customer already has, and extra seats, onto the subscription', function (): void {
    $m = fcuWorld();
    fcuCoupon('two periods');
    $billable = fcuFreeCustomer($m, 'DE', null, ['sso']);

    // The plan screen keeps the active addon; the service is handed the seats as well.
    app(UpgradeLocalToMollie::class)->handle($billable, [
        'plan_code' => 'pro', 'interval' => 'monthly', 'addon_codes' => ['sso'], 'extra_seats' => 2,
        'coupon_code' => 'FREE100', 'amount_gross' => 0,
    ]);

    $payment = $m->lastPayment();
    expect(BookingMatrix::minor($payment))->toBe(0)
        ->and(BookingMatrix::prop($payment, 'metadata')['pending_subscription_extra_seats'])->toBe(2)
        ->and(BookingMatrix::prop($payment, 'metadata')['pending_subscription_addon_codes'])->toBe(['sso']);

    $m->settle($payment, 'DE');
    $billable->refresh();

    // Pro 29.50 + 2 seats 11.10 + SSO 15.50 = 56.10 net; VAT per line at 19 %.
    expect($billable->subscription_plan_code)->toBe('pro')
        ->and($billable->getActiveBillingAddonCodes())->toBe(['sso'])
        ->and($billable->getExtraBillingSeats())->toBe(2)
        ->and($m->subscriptionMinor())->toBe(fcuGross([2950, 1110, 1550], 19.0));

    $invoice = BillingInvoice::query()->sole();
    expect((int) $invoice->amount_gross)->toBe(0)
        ->and((int) collect($invoice->line_items)->where('kind', 'coupon')->sum('amount_net'))->toBe(-5610);
});

it('changes nothing when the €0 authorisation is abandoned or fails', function (string $status): void {
    $m = fcuWorld();
    fcuCoupon('two periods');
    $billable = fcuFreeCustomer($m);

    fcuUpgradeOnPlanScreen();
    $payment = $m->lastPayment();

    $m->deliver((object) [
        'id' => BookingMatrix::paymentIdFor($payment), 'status' => $status,
        'amount' => (object) ['value' => '0.00', 'currency' => 'EUR'],
        'customerId' => 'cst_bm', 'mandateId' => null, 'sequenceType' => 'first', 'countryCode' => 'AT',
        'metadata' => BookingMatrix::prop($payment, 'metadata'),
    ]);

    $billable->refresh();
    expect($billable->subscription_plan_code)->toBe('free')
        ->and($billable->subscription_source)->toBe(SubscriptionSource::Local)
        ->and($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->remainingBillingQuota('Tokens'))->toBe(10)
        ->and(CouponRedemption::query()->count())->toBe(0)
        ->and(BillingInvoice::query()->count())->toBe(0)
        ->and($m->sentOf(CreateSubscriptionRequest::class))->toBe([]);

    // The code is still theirs to use: a second attempt goes through.
    fcuUpgradeOnPlanScreen();
    $m->settle($m->lastPayment());
    expect($billable->fresh()->subscription_plan_code)->toBe('pro')
        ->and(CouponRedemption::query()->count())->toBe(1);
})->with(['canceled' => ['canceled'], 'failed' => ['failed'], 'expired' => ['expired']]);

it('asks a customer who already has a mandate for the €0 authorisation too — the same as the checkout', function (): void {
    $m = fcuWorld();
    fcuCoupon('two periods');
    $billable = fcuFreeCustomer($m);
    $billable->forceFill(['mollie_mandate_id' => 'mdt_old'])->save();

    fcuUpgradeOnPlanScreen();

    expect(BookingMatrix::minor($m->lastPayment()))->toBe(0)
        ->and(BookingMatrix::prop($m->lastPayment(), 'metadata')['type'])->toBe('mandate_only')
        ->and($m->sentOf(CreateSubscriptionRequest::class))->toBe([]);
});

it('still refuses what the upgrade rules refuse, before anything is sent to Mollie', function (): void {
    $m = fcuWorld();
    fcuCoupon('two periods');

    // A customer whose subscription already runs at Mollie is not a local upgrade.
    $billable = $m->billable('AT', [
        'subscription_status' => SubscriptionStatus::PastDue,
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_plan_code' => 'team',
        'subscription_interval' => SubscriptionInterval::Monthly,
    ]);

    expect(fn () => app(UpgradeLocalToMollie::class)->handle($billable, [
        'plan_code' => 'pro', 'interval' => 'monthly', 'coupon_code' => 'FREE100', 'amount_gross' => 0,
    ]))->toThrow(LocalSubscriptionUpgradeRequiresMolliePathException::class);

    // And a total of 0 with NO code is not an order this path knows.
    $free = fcuFreeCustomer($m);
    expect(fn () => app(UpgradeLocalToMollie::class)->handle($free, [
        'plan_code' => 'pro', 'interval' => 'monthly', 'amount_gross' => 0,
    ]))->toThrow(InvalidArgumentException::class);

    expect($m->sentOf(CreatePaymentRequest::class))->toBe([]);
});

it('tells the customer on the plan screen that nothing is due today, and when and what the first charge is', function (): void {
    $m = fcuWorld();
    fcuCoupon('two periods');
    fcuFreeCustomer($m, 'DE');

    $screen = Livewire::test('mollie-billing::plan-change')
        ->call('previewFor', 'pro')
        ->set('couponInput', 'FREE100')
        ->call('applyCoupon');

    $first = $screen->instance()->fullCoverageFirstCharge();

    // Two free periods from 10 March, the upgrade's own and April's: 10 May 2026. (Until
    // 17 November 2026: 2 × 30 days + 1, the day after the code ends — 11 May.)
    expect($first)->not->toBeNull()
        ->and($first['date']->toDateString())->toBe('2026-05-10')
        ->and($first['gross'])->toBe(fcuGross([2950], 19.0));

    // No code, something to pay: no such notice.
    $plain = Livewire::test('mollie-billing::plan-change')->call('previewFor', 'pro');
    expect($plain->instance()->fullCoverageFirstCharge())->toBeNull();
});

/*
 * Mollie refuses the CreateSubscription call after the mandate was captured. The retry
 * path of every activation has to finish this one as well — and still know which free
 * plan the wallets are moved away from, although the plan code was overwritten when the
 * invoice was written.
 */
it('is finished by the activation retry when Mollie refuses the subscription at first', function (): void {
    $m = fcuWorld();
    \Illuminate\Support\Facades\Queue::fake();
    fcuCoupon('two periods');
    $billable = fcuFreeCustomer($m);

    fcuUpgradeOnPlanScreen();
    $payment = $m->lastPayment();
    $paymentId = BookingMatrix::paymentIdFor($payment);

    $refuse = true;
    $m->intercept = function (object $request) use (&$refuse, $paymentId): ?object {
        if ($request instanceof CreateSubscriptionRequest && $refuse) {
            throw new RuntimeException('Mollie is down');
        }

        if ($request instanceof \Mollie\Api\Http\Requests\GetPaymentRequest) {
            return \GraystackIT\MollieBilling\Tests\Support\BookingMatrixWebhookController::$payments[$paymentId] ?? null;
        }

        return null;
    };

    $m->settle($payment);

    // Mandate captured, the audit invoice written, no subscription yet — and a retry on record.
    // … and until it is there the customer is on the FREE plan, not on a Pro nobody
    // pays for: the plan code moves with the subscription, not ahead of it.
    $billable->refresh();
    expect($billable->subscription_source)->toBe(SubscriptionSource::Local)
        ->and($billable->subscription_plan_code)->toBe('free')
        ->and($billable->remainingBillingQuota('Tokens'))->toBe(10)
        ->and(BillingInvoice::query()->count())->toBe(1)
        ->and($billable->getBillingSubscriptionMeta()[\GraystackIT\MollieBilling\Support\ActivationRetry::META_KEY] ?? [])->toHaveKey($paymentId)
        ->and($billable->getBillingSubscriptionMeta()['upgrade_from_local_origin']['plan'] ?? null)->toBe('free');

    // The return page does not call that "paid, continue to your dashboard": the
    // authorisation went through, the upgrade has not.
    // (The webhook controller drops the "pending payment" marker once the paid handlers
    // have run, finished or not — so that marker cannot be what the page goes by.)
    $billable->clearPendingFirstPayment();
    expect(Livewire::test('mollie-billing::return')->get('activated'))->toBeFalse();

    // Mollie is back.
    $refuse = false;
    Carbon::setTestNow(Carbon::parse(FCU_START, 'UTC')->addMinutes(10));
    app()->call([new \GraystackIT\MollieBilling\Jobs\RetrySubscriptionActivationJob($paymentId, $billable->getMorphClass(), $billable->getKey()), 'handle']);

    expect(Livewire::test('mollie-billing::return')->get('activated'))->toBeTrue();

    $billable->refresh();
    expect($billable->subscription_plan_code)->toBe('pro')
        ->and($billable->subscription_source)->toBe(SubscriptionSource::Mollie)
        ->and($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($m->subscriptionMinor())->toBe(fcuGross([2950], 20.0))
        ->and(BillingInvoice::query()->count())->toBe(1)
        ->and(CouponRedemption::query()->count())->toBe(1)
        ->and($billable->remainingBillingQuota('Tokens'))->toBe(100)
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('upgrade_from_local_origin')
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey(\GraystackIT\MollieBilling\Support\ActivationRetry::META_KEY);
});

/*
 * A tenant who was GIVEN a plan (access grant, 90 days of Team) and upgrades to Pro under
 * a code that covers Pro in full. The gift's end date must not survive on the paid
 * subscription — it would end the subscription the customer just booked.
 */
it('replaces a gifted plan too: the paid subscription does not inherit the gift\'s end date', function (): void {
    $m = fcuWorld();
    fcuCoupon('two periods');
    $billable = $m->billable('AT');

    $service = app(CouponService::class);
    $grant = $service->accessGrantCoupon('GIFT', 'team', 'monthly', [], 90);
    $service->redeem($grant, $billable->fresh(), []);
    $billable->refresh();

    expect($billable->subscription_plan_code)->toBe('team')
        ->and($billable->subscription_source)->toBe(SubscriptionSource::Local)
        ->and($billable->subscription_ends_at)->not->toBeNull();

    app(UpgradeLocalToMollie::class)->handle($billable, [
        'plan_code' => 'pro', 'interval' => 'monthly', 'coupon_code' => 'FREE100', 'amount_gross' => 0,
    ]);
    $m->settle($m->lastPayment());

    $billable->refresh();
    expect($billable->subscription_plan_code)->toBe('pro')
        ->and($billable->subscription_source)->toBe(SubscriptionSource::Mollie)
        ->and($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->subscription_ends_at)->toBeNull()
        ->and($m->subscriptionMinor())->toBe(fcuGross([2950], 20.0))
        ->and(CouponRedemption::query()->where('coupon_id', '!=', $grant->id)->count())->toBe(1);
});

/*
 * The return page. A customer on a free plan HAS an accessible subscription the whole
 * time, and the page took that for "activated": abandoning the authorisation (or a paid
 * upgrade) at Mollie was answered with "Payment received". While an upgrade away from a
 * local plan is in flight, the page has to ask about the payment instead.
 */
it('does not report an upgrade from a free plan as paid before it is, and says so when it was abandoned', function (): void {
    $m = fcuWorld();
    fcuCoupon('two periods');
    $billable = fcuFreeCustomer($m);

    fcuUpgradeOnPlanScreen();
    $payment = $m->lastPayment();
    expect($billable->fresh()->getPendingFirstPaymentId())->not->toBeNull();

    // Still open at Mollie: the page waits.
    $m->intercept = fn (object $r) => $r instanceof \Mollie\Api\Http\Requests\GetPaymentRequest ? (object) ['id' => 'tr_x', 'status' => 'open'] : null;
    $page = Livewire::test('mollie-billing::return');
    expect($page->get('activated'))->toBeFalse();
    $page->call('checkStatus');
    expect($page->get('activated'))->toBeFalse()->and($page->get('failed'))->toBeFalse();

    // Abandoned: the page says so, and the free plan is what it was.
    $m->intercept = fn (object $r) => $r instanceof \Mollie\Api\Http\Requests\GetPaymentRequest ? (object) ['id' => 'tr_x', 'status' => 'canceled'] : null;
    $page->call('checkStatus');
    expect($page->get('failed'))->toBeTrue()
        ->and($page->get('failureReason'))->toBe('canceled')
        ->and($page->get('activated'))->toBeFalse()
        ->and($billable->fresh()->subscription_plan_code)->toBe('free');

    // A second attempt that is paid: activated once the webhook has done its work.
    $m->intercept = null;
    fcuUpgradeOnPlanScreen();
    $m->settle($m->lastPayment());
    expect(Livewire::test('mollie-billing::return')->get('activated'))->toBeTrue();

    // And a customer who simply is on a free plan, with nothing in flight, is not kept waiting.
    $plain = fcuFreeCustomer($m);
    expect(Livewire::test('mollie-billing::return')->get('activated'))->toBeTrue();
});

// ── What the customer is told before being sent to Mollie ───────────────────────

it('says on the confirmation that the customer is sent on briefly to confirm a payment method and that nothing is charged', function (bool $hasMandate): void {
    $m = fcuWorld();
    fcuCoupon('two periods');
    $billable = fcuFreeCustomer($m);

    if ($hasMandate) {
        $billable->forceFill(['mollie_mandate_id' => 'mdt_old'])->save();
    }

    $screen = Livewire::test('mollie-billing::plan-change')
        ->call('previewFor', 'pro')
        ->set('couponInput', 'FREE100')
        ->call('applyCoupon')
        ->call('applyChange');

    expect($screen->get('showLocalUpgradeConfirmation'))->toBeTrue();
    $screen->assertSee(__('billing::portal.upgrade_authorisation_notice'));

    // An upgrade that costs something is an ordinary payment: no such notice.
    Livewire::test('mollie-billing::plan-change')
        ->call('previewFor', 'pro')
        ->call('applyChange')
        ->assertDontSee(__('billing::portal.upgrade_authorisation_notice'));
})->with(['no mandate yet' => [false], 'a mandate on file — asked all the same, as the checkout does' => [true]]);

// ── The checkout's own mandate path, for a new customer ─────────────────────────

/*
 * Not an upgrade: a NEW customer books Pro in the checkout under the same code. The first
 * attempt writes the recurring marker and then Mollie refuses the subscription. The retry
 * validated the code against that marker — "recurring_already_active" — and could never
 * finish: mandate captured, invoice written, no subscription, until the retries ran out.
 */
it('lets the activation retry finish a new customer\'s fully covered checkout despite the marker its first attempt left', function (): void {
    $m = fcuWorld();
    \Illuminate\Support\Facades\Queue::fake();
    fcuCoupon('two periods');
    $billable = $m->billable('DE');

    $m->checkout('pro', 'monthly', [], 0, 'FREE100')->call('submit');
    $payment = $m->lastPayment();
    $paymentId = BookingMatrix::paymentIdFor($payment);
    expect(BookingMatrix::prop($payment, 'metadata')['type'])->toBe('mandate_only')
        ->and(BookingMatrix::prop($payment, 'metadata'))->not->toHaveKey('pending_subscription_upgrade_from_local');

    $refuse = true;
    $m->intercept = function (object $request) use (&$refuse, $paymentId): ?object {
        if ($request instanceof CreateSubscriptionRequest && $refuse) {
            throw new RuntimeException('Mollie is down');
        }

        if ($request instanceof \Mollie\Api\Http\Requests\GetPaymentRequest) {
            return \GraystackIT\MollieBilling\Tests\Support\BookingMatrixWebhookController::$payments[$paymentId] ?? null;
        }

        return null;
    };

    $m->settle($payment, 'DE');

    $billable->refresh();
    expect($billable->subscription_status)->not->toBe(SubscriptionStatus::Active)
        ->and(app(CouponService::class)->getActiveRecurringCouponMarker($billable)['code'] ?? null)->toBe('FREE100')
        ->and($billable->getBillingSubscriptionMeta()[\GraystackIT\MollieBilling\Support\ActivationRetry::META_KEY] ?? [])->toHaveKey($paymentId);

    $refuse = false;
    Carbon::setTestNow(Carbon::parse(FCU_START, 'UTC')->addMinutes(10));
    app()->call([new \GraystackIT\MollieBilling\Jobs\RetrySubscriptionActivationJob($paymentId, $billable->getMorphClass(), $billable->getKey()), 'handle']);

    // Pro 29.50 at 19 % = 35.11, held for after the two free months.
    $billable->refresh();
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->subscription_source)->toBe(SubscriptionSource::Mollie)
        ->and($m->subscriptionMinor())->toBe(3511)
        ->and(CouponRedemption::query()->count())->toBe(1)
        ->and(BillingInvoice::query()->count())->toBe(1)
        ->and(app(CouponService::class)->getActiveRecurringCouponMarker($billable)['code'] ?? null)->toBe('FREE100');
});

// ── A gifted plan, through the plan screen ──────────────────────────────────────

it('upgrades a tenant on a gifted plan through the plan screen under a fully covering code', function (): void {
    $m = fcuWorld();
    fcuCoupon('two periods');
    $billable = $m->billable('AT');

    $service = app(CouponService::class);
    $grant = $service->accessGrantCoupon('GIFT', 'team', 'monthly', [], 90);
    $service->redeem($grant, $billable->fresh(), []);

    fcuUpgradeOnPlanScreen();

    $payment = $m->lastPayment();
    expect(BookingMatrix::minor($payment))->toBe(0)
        ->and(BookingMatrix::prop($payment, 'metadata')['type'])->toBe('mandate_only')
        ->and($billable->fresh()->subscription_plan_code)->toBe('team');

    $m->settle($payment);

    // Pro 29.50 + 20 % = 35.40; the gift's end date is gone with the gift.
    $billable->refresh();
    expect($billable->subscription_plan_code)->toBe('pro')
        ->and($billable->subscription_source)->toBe(SubscriptionSource::Mollie)
        ->and($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->subscription_ends_at)->toBeNull()
        ->and($m->subscriptionMinor())->toBe(3540)
        ->and((int) BillingInvoice::query()->sole()->amount_gross)->toBe(0)
        ->and(CouponRedemption::query()->where('coupon_id', '!=', $grant->id)->count())->toBe(1);
});

// ── Seats and an addon, through the plan screen ─────────────────────────────────

/*
 * A free customer with four seats in use and the SSO addon. Pro includes two seats:
 * Pro 29.50 + 2 seats × 5.55 + SSO 15.50 = 56.10 net; at 19 % per line
 * 35.11 + 13.21 + 18.45 = 66.77. The preview, the amount Mollie holds and the first
 * charge have to be that one number.
 */
it('previews, creates and first charges one amount when seats and an addon come along on the plan screen', function (): void {
    $m = fcuWorld();
    fcuCoupon('two periods');
    $billable = fcuFreeCustomer($m, 'DE', null, ['sso']);
    $billable->forceFill(['subscription_meta' => array_merge($billable->getBillingSubscriptionMeta(), ['seat_count' => 4])])->save();

    $screen = Livewire::test('mollie-billing::plan-change')
        ->call('previewFor', 'pro')
        ->call('toggleKeepExtraSeats')
        ->set('couponInput', 'FREE100')
        ->call('applyCoupon');

    expect($screen->get('couponError'))->toBeNull()
        ->and((int) $screen->get('preview')['grossTotal'])->toBe(0);

    $announced = $screen->instance()->fullCoverageFirstCharge();
    expect($announced['gross'])->toBe(6677);

    $screen->call('confirmAndPay');
    $payment = $m->lastPayment();
    $metadata = BookingMatrix::prop($payment, 'metadata');
    expect(BookingMatrix::minor($payment))->toBe(0)
        ->and($metadata['pending_subscription_extra_seats'])->toBe(2)
        ->and($metadata['pending_subscription_addon_codes'])->toBe(['sso']);

    $m->settle($payment, 'DE');
    $billable->refresh();

    expect($m->subscriptionMinor())->toBe(6677)
        ->and($m->subscriptionStartDate())->toBe($announced['date']->toDateString())
        ->and($billable->getBillingSeatCount())->toBe(4)
        ->and($billable->getActiveBillingAddonCodes())->toBe(['sso']);

    $first = $m->renew($billable, 'monthly', 'DE', Carbon::parse((string) $m->subscriptionStartDate(), 'UTC'));
    $m->assertInvoice($first, 5610, 1067, 19.0, ['plan' => 2950, 'seats' => 1110, 'addon:sso' => 1550]);
    expect((int) $first->amount_gross)->toBe(6677);
});

// ── Subscriptions that are over ─────────────────────────────────────────────────

/*
 * The rules, not new ones: this path is for a LOCAL subscription. A Mollie subscription
 * that is cancelled or has expired is not one — a cancelled one is changed (or not) under
 * the plan-change rules, an expired one books again through the checkout, where the same
 * code is honoured through the same €0 authorisation.
 */
it('is not the way for a Mollie subscription that is cancelled or has expired — the checkout is, for the expired one', function (SubscriptionStatus $status): void {
    $m = fcuWorld();
    fcuCoupon('two periods');

    $billable = $m->billable('AT', [
        'subscription_status' => $status,
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_plan_code' => $status === SubscriptionStatus::Expired ? null : 'team',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(20),
        'subscription_ends_at' => $status === SubscriptionStatus::Expired ? BillingTime::nowUtc()->subDays(5) : BillingTime::nowUtc()->addDays(10),
        'mollie_mandate_id' => 'mdt_old',
    ]);

    expect(fn () => app(UpgradeLocalToMollie::class)->handle($billable, [
        'plan_code' => 'pro', 'interval' => 'monthly', 'coupon_code' => 'FREE100', 'amount_gross' => 0,
    ]))->toThrow(LocalSubscriptionUpgradeRequiresMolliePathException::class);
    expect($m->sentOf(CreatePaymentRequest::class))->toBe([]);

    if ($status === SubscriptionStatus::Expired) {
        $m->checkout('pro', 'monthly', [], 0, 'FREE100')->call('submit');
        expect(BookingMatrix::minor($m->lastPayment()))->toBe(0);
        $m->settle($m->lastPayment());

        expect($billable->fresh()->subscription_status)->toBe(SubscriptionStatus::Active)
            ->and($billable->fresh()->subscription_plan_code)->toBe('pro')
            ->and($m->subscriptionMinor())->toBe(3540);
    }
})->with(['cancelled, still running' => [SubscriptionStatus::Cancelled], 'expired' => [SubscriptionStatus::Expired]]);

it('upgrades from a local plan that has ended (a gift that ran out) the same way', function (): void {
    $m = fcuWorld();
    fcuCoupon('two periods');
    $billable = $m->billable('AT', [
        'subscription_status' => SubscriptionStatus::Expired,
        'subscription_source' => SubscriptionSource::Local,
        'subscription_plan_code' => 'team',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(95),
        'subscription_ends_at' => BillingTime::nowUtc()->subDays(5),
    ]);

    app(UpgradeLocalToMollie::class)->handle($billable, [
        'plan_code' => 'pro', 'interval' => 'monthly', 'coupon_code' => 'FREE100', 'amount_gross' => 0,
    ]);
    expect(BookingMatrix::minor($m->lastPayment()))->toBe(0);
    $m->settle($m->lastPayment());

    $billable->refresh();
    expect($billable->subscription_plan_code)->toBe('pro')
        ->and($billable->subscription_source)->toBe(SubscriptionSource::Mollie)
        ->and($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->subscription_ends_at)->toBeNull()
        ->and($m->subscriptionMinor())->toBe(3540)
        ->and(CouponRedemption::query()->count())->toBe(1);
});
