<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\DuplicatePaymentReceived;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
use GraystackIT\MollieBilling\Exceptions\AddonNotAllowedException;
use GraystackIT\MollieBilling\Exceptions\IntervalNotOfferedException;
use GraystackIT\MollieBilling\Exceptions\LocalSubscriptionUpgradeRequiresMolliePathException;
use GraystackIT\MollieBilling\Exceptions\NonEuCountryException;
use GraystackIT\MollieBilling\Exceptions\SeatCountExceedsMaximumException;
use GraystackIT\MollieBilling\Exceptions\SeatDowngradeRequiredException;
use GraystackIT\MollieBilling\Exceptions\SubscriptionChangeInProgressException;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\PlanWaiver;
use GraystackIT\MollieBilling\Services\Billing\StartGrantExtrasPurchase;
use GraystackIT\MollieBilling\Services\Billing\UpgradeLocalToMollie;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\BookingMatrix;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;

/*
 * Booking matrix, part 6: a tenant who was GIVEN their plan buys paid extras on top
 * (`UpgradeLocalToMollie::handleKeepingGrant()`). The amount is computed by the service,
 * so the chain here is: amount returned == amount sent to Mollie == invoice == Mollie
 * subscription amount == renewal invoice.
 */

afterEach(fn () => Carbon::setTestNow());

/** A tenant given Pro for `$days` days, optionally with addons included in the gift. */
function bmGifted(BookingMatrix $m, string $country = 'AT', string $interval = 'monthly', array $grantedAddons = [], int $days = 400, ?string $vatNumber = null): TestBillable
{
    $billable = $vatNumber !== null ? $m->business($country, $vatNumber) : $m->billable($country);

    $service = app(CouponService::class);
    $code = 'GIFT-'.strtoupper(bin2hex(random_bytes(3)));
    $coupon = $service->accessGrantCoupon($code, 'pro', $interval, $grantedAddons, $days);
    $service->validate($code, $billable->fresh(), []);
    $service->redeem($coupon, $billable->fresh(), []);

    return $billable->fresh();
}

dataset('bm grant purchases', [
    // label => [interval, granted addons, addons after purchase, seats after purchase, paid lines, waived net]
    'only an addon' => ['monthly', [], ['sso'], 2, ['addon:sso' => 1550], 2950],
    'only seats' => ['monthly', [], [], 4, ['seats' => 1110], 2950],
    'addon and seats' => ['monthly', [], ['sso'], 3, ['seats' => 555, 'addon:sso' => 1550], 2950],
    'a per-seat addon and seats' => ['monthly', [], ['crm'], 4, ['seats' => 1110, 'addon:crm' => 1000, 'addon_seats:crm' => 999], 2950],
    'an addon on top of a gifted addon' => ['monthly', ['sso'], ['sso', 'crm'], 2, ['addon:crm' => 1000, 'addon_seats:crm' => 333], 4500],
    'yearly: addon and seats' => ['yearly', [], ['sso'], 3, ['seats' => 5555, 'addon:sso' => 15550], 29950],
]);

dataset('bm grant vat', [
    'B2C AT' => ['AT', null, 20.0],
    'B2C DE' => ['DE', null, 19.0],
    'B2B DE, reverse charge' => ['DE', 'DE123456789', 0.0],
]);

it('gifted plan + paid extras: charged == invoiced == subscription == renewal, the plan stays free', function (
    string $interval, array $granted, array $addons, int $seats, array $paid, int $waived,
    string $country, ?string $vatNumber, float $rate,
): void {
    $m = BookingMatrix::boot();
    $billable = bmGifted($m, $country, $interval, $granted, 400, $vatNumber);
    $giftEnds = $billable->getBillingSubscriptionEndsAt();

    $result = app(UpgradeLocalToMollie::class)->handleKeepingGrant($billable, $addons, $seats);

    $net = array_sum($paid);
    expect($result['amount_net'])->toBe($net);

    $payment = $m->lastPayment();
    expect(BookingMatrix::minor($payment))->toBe($result['amount_gross']);

    $invoice = $m->settle($payment, $country);
    $items = collect($invoice->line_items);

    // The invoice shows everything, takes the gift off again, and is the amount paid.
    expect($items->firstWhere('kind', 'adjustment'))->toBeNull()
        ->and((int) $invoice->amount_net)->toBe($net)
        ->and((int) $invoice->amount_gross)->toBe($result['amount_gross'])
        ->and((int) $items->where('kind', 'coupon')->sum('amount_net'))->toBe(-$waived)
        ->and((int) $items->sum('amount_net'))->toBe($net)
        ->and((int) $items->sum('vat_amount'))->toBe((int) $invoice->amount_vat)
        ->and((int) $items->firstWhere('kind', 'plan')['amount_net'])->toBe($interval === 'yearly' ? 29950 : 2950);

    foreach ($paid as $key => $expected) {
        [$kind, $code] = array_pad(explode(':', $key, 2), 2, null);
        $line = $items->first(fn (array $i): bool => $i['kind'] === $kind && ($code === null || ($i['code'] ?? null) === $code));
        expect($line)->not->toBeNull()->and((int) $line['amount_net'])->toBe($expected);
    }

    // VAT to the cent of the rate on what is actually paid (reverse charge: none).
    expect(abs((int) $invoice->amount_vat - (int) round($net * $rate / 100)))->toBeLessThanOrEqual(1);
    if ($rate === 0.0) {
        expect((int) $invoice->amount_vat)->toBe(0);
    }

    $billable->refresh();
    expect($billable->subscription_source)->toBe(SubscriptionSource::Mollie)
        ->and($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->getActiveBillingAddonCodes())->toEqualCanonicalizing($addons)
        ->and($billable->getBillingSeatCount())->toBe($seats)
        ->and($billable->getBillingSubscriptionInterval())->toBe($interval);

    $marker = app(PlanWaiver::class)->marker($billable);
    expect($marker)->not->toBeNull()
        ->and(Carbon::parse((string) $marker['valid_until'])->toDateString())->toBe($giftEnds->toDateString());

    // Mollie collects the extras, and only the extras, every period.
    expect($m->subscriptionMinor())->toBe($result['amount_gross']);

    if ($interval === 'monthly') {
        $renewal = $m->renew($billable, $interval, $country);
        $renewed = collect($renewal->line_items);

        expect($renewed->firstWhere('kind', 'adjustment'))->toBeNull()
            ->and((int) $renewal->amount_net)->toBe($net)
            ->and((int) $renewal->amount_gross)->toBe($result['amount_gross'])
            ->and((int) $renewed->where('kind', 'coupon')->sum('amount_net'))->toBe(-$waived);
    }

    $m->assertNoMismatch();
})->with('bm grant purchases')->with('bm grant vat');

it('gifted plan + extras: refuses what cannot be bought, before any payment exists', function (Closure $arrange, array $addons, int $seats, string $exception): void {
    $m = BookingMatrix::boot();
    $billable = bmGifted($m);
    $arrange($billable);

    expect(fn () => app(UpgradeLocalToMollie::class)->handleKeepingGrant($billable->fresh(), $addons, $seats))
        ->toThrow($exception);

    expect($m->lastPayment())->toBeNull()
        ->and($billable->fresh()->subscription_source)->toBe(SubscriptionSource::Local);
})->with([
    'an addon the plan does not allow' => [fn ($b) => config()->set('mollie-billing-plans.plans.pro.allowed_addons', ['sso']), ['crm'], 2, AddonNotAllowedException::class],
    'an addon not sold in this interval' => [fn ($b) => config()->set('mollie-billing-plans.addons.sso.intervals', ['yearly' => ['price_net' => 15550]]), ['sso'], 2, IntervalNotOfferedException::class],
    'more seats than the package allows' => [fn ($b) => config()->set('mollie-billing.max_seats', 10), [], 11, SeatCountExceedsMaximumException::class],
    'seats on a plan that sells none' => [fn ($b) => config()->set('mollie-billing-plans.plans.pro.intervals.monthly.seat_price_net', null), [], 3, SeatDowngradeRequiredException::class],
    'nothing that costs anything' => [fn ($b) => null, [], 2, InvalidArgumentException::class],
    'no billing country on file' => [function ($b): void { $b->forceFill(['billing_country' => null])->save(); }, ['sso'], 2, NonEuCountryException::class],
    'the gift has already ended' => [function ($b): void { $b->forceFill(['subscription_ends_at' => BillingTime::nowUtc()->subDay()])->save(); }, ['sso'], 2, LocalSubscriptionUpgradeRequiresMolliePathException::class],
    'a free plan that is not a gift' => [function ($b): void { $b->forceFill(['subscription_ends_at' => null])->save(); }, ['sso'], 2, LocalSubscriptionUpgradeRequiresMolliePathException::class],
]);

it('gifted plan + extras: seats already in use cannot be undercut', function (): void {
    $m = BookingMatrix::boot();
    $billable = bmGifted($m);
    $meta = $billable->getBillingSubscriptionMeta();
    $meta['used_seats'] = 5;
    $billable->forceFill(['subscription_meta' => $meta])->save();

    // Asked for 2 seats while 5 are occupied: 3 extra seats are charged, not 0.
    $result = app(UpgradeLocalToMollie::class)->handleKeepingGrant($billable->fresh(), ['sso'], 2);

    expect($result['amount_net'])->toBe(1550 + 3 * 555)
        ->and(BookingMatrix::prop($m->lastPayment(), 'metadata')['extra_seats'])->toBe(3);
});

it('gifted plan + extras: a second payment for the same purchase is reported, not booked', function (): void {
    $m = BookingMatrix::boot();
    Event::fake([DuplicatePaymentReceived::class, PaymentAmountMismatch::class]);
    $billable = bmGifted($m);

    // Two tabs: both start the purchase, both pay.
    app(UpgradeLocalToMollie::class)->handleKeepingGrant($billable, ['sso'], 2);
    $first = $m->lastPayment();
    app(UpgradeLocalToMollie::class)->handleKeepingGrant($billable->fresh(), ['sso'], 2);
    $second = $m->lastPayment();

    expect($first)->not->toBe($second);

    $m->settle($first);
    $m->settle($second);

    expect(BillingInvoice::query()->count())->toBe(1)
        ->and($m->sentOf(CreateSubscriptionRequest::class))->toHaveCount(1);
    Event::assertDispatched(DuplicatePaymentReceived::class, 1);

    // A re-delivery of the first one changes nothing either.
    $m->settle($first);
    expect(BillingInvoice::query()->count())->toBe(1)
        ->and($m->sentOf(CreateSubscriptionRequest::class))->toHaveCount(1);
});

it('gifted plan + extras: a double click starts one payment', function (): void {
    $m = BookingMatrix::boot();
    $billable = bmGifted($m);

    // The first click is still talking to Mollie.
    $held = Cache::lock('billing:checkout-submit:'.$billable->getKey(), 60);
    expect($held->get())->toBeTrue();

    expect(fn () => app(StartGrantExtrasPurchase::class)->handle($billable, ['sso'], 2))
        ->toThrow(SubscriptionChangeInProgressException::class);
    expect($m->lastPayment())->toBeNull();

    $held->release();

    $url = app(StartGrantExtrasPurchase::class)->handle($billable, ['sso'], 2);
    expect($url)->not->toBeNull()
        ->and($billable->fresh()->getPendingFirstPaymentId())->not->toBeNull()
        ->and($m->sentOf(\Mollie\Api\Http\Requests\CreatePaymentRequest::class))->toHaveCount(1);
});

it('gifted plan + extras: says whether a change needs paying for at all', function (array $addons, int $seats, bool $needsPayment): void {
    $m = BookingMatrix::boot();
    config()->set('mollie-billing-plans.addons.freebie', ['name' => 'Freebie', 'feature_keys' => [], 'intervals' => ['monthly' => ['price_net' => 0]]]);
    $billable = bmGifted($m, 'AT', 'monthly', ['sso']);

    $service = app(StartGrantExtrasPurchase::class);

    expect($service->eligible($billable))->toBeTrue()
        ->and($service->needsPayment($billable, $addons, $seats))->toBe($needsPayment);
})->with([
    'nothing new' => [['sso'], 2, false],
    'a free addon' => [['sso', 'freebie'], 2, false],
    'a paid addon' => [['sso', 'crm'], 2, true],
    'a seat beyond the included ones' => [['sso'], 3, true],
]);

it('upgrade free → paid: still a duplicate once a Mollie subscription is running', function (): void {
    $m = BookingMatrix::boot();
    Event::fake([DuplicatePaymentReceived::class, PaymentAmountMismatch::class]);
    $billable = $m->billable('AT');
    app(\GraystackIT\MollieBilling\Services\Billing\ActivateLocalSubscription::class)->handle($billable, 'free', 'monthly');

    $request = ['plan_code' => 'pro', 'interval' => 'monthly', 'amount_gross' => 3540];
    app(UpgradeLocalToMollie::class)->handle($billable->fresh(), $request);
    $first = $m->lastPayment();
    app(UpgradeLocalToMollie::class)->handle($billable->fresh(), $request);
    $second = $m->lastPayment();

    $m->settle($first);
    $m->settle($second);

    expect(BillingInvoice::query()->count())->toBe(1)
        ->and($m->sentOf(CreateSubscriptionRequest::class))->toHaveCount(1);
    Event::assertDispatched(DuplicatePaymentReceived::class, 1);
});
