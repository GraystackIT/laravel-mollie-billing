<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\ActivateLocalSubscription;
use GraystackIT\MollieBilling\Services\Billing\StartOneTimeOrderCheckout;
use GraystackIT\MollieBilling\Services\Billing\UpgradeLocalToMollie;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Tests\Support\BookingMatrix;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;

/*
 * Booking matrix, part 2: the booking paths other than "first booking of a paid plan",
 * each without a coupon — trial, free plan, upgrade free → paid, re-booking after the
 * subscription ended, and a one-time product.
 */

afterEach(fn () => Carbon::setTestNow());

it('books a plan with a trial: nothing now, the shown amount at the trial end', function (string $country, float $rate): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable($country);
    Carbon::setTestNow($start = BillingTime::nowUtc()->startOfSecond());

    // Team 19.50 + SSO 15.50 + 2 extra seats à 3.55.
    $lines = ['plan' => 1950, 'seats' => 710, 'addon:sso' => 1550];
    $net = array_sum($lines);
    $vat = (int) array_sum(array_map(fn (int $n): int => (int) round($n * $rate / 100), $lines));

    $checkout = $m->checkout('team', 'monthly', ['sso'], 2);
    $shown = $m->shown($checkout);

    expect($checkout->instance()->trialApplies('team', 'monthly'))->toBeTrue()
        ->and($shown['gross'])->toBe($net + $vat);

    $checkout->call('submit');

    // The trial collects nothing: a €0 mandate payment.
    $payment = $m->lastPayment();
    expect(BookingMatrix::minor($payment))->toBe(0)
        ->and(BookingMatrix::prop($payment, 'metadata')['type'])->toBe('mandate_only');

    $invoice = $m->settle($payment, $country);
    $billable->refresh();

    expect($invoice)->toBeNull()
        ->and(BillingInvoice::query()->count())->toBe(0)
        ->and($billable->subscription_status)->toBe(SubscriptionStatus::Trial)
        ->and($billable->trial_ends_at->toDateString())->toBe($start->copy()->addDays(14)->toDateString());

    // Mollie holds the amount the checkout showed, due on the day the checkout named.
    expect($m->subscriptionMinor())->toBe($shown['gross'])
        ->and($m->subscriptionStartDate())->toBe($start->copy()->addDays(14)->toDateString());

    // The first charge, at the trial's end, is the first invoice.
    $first = $m->renew($billable, 'monthly', $country, $billable->trial_ends_at);
    $m->assertInvoice($first, $net, $vat, $rate, $lines);

    expect($billable->fresh()->subscription_status)->toBe(SubscriptionStatus::Active);

    $second = $m->renew($billable, 'monthly', $country);
    $m->assertInvoice($second, $net, $vat, $rate, $lines);

    $m->assertNoMismatch();
})->with(['AT' => ['AT', 20.0], 'DE' => ['DE', 19.0]]);

it('books a free plan locally: no Mollie call, no invoice, access at once', function (): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable('AT');

    $checkout = $m->checkout('free');
    expect($m->shown($checkout)['gross'])->toBe(0);

    $checkout->call('submit');
    $billable->refresh();

    expect($m->sent)->toBe([])
        ->and(BillingInvoice::query()->count())->toBe(0)
        ->and($billable->subscription_source)->toBe(SubscriptionSource::Local)
        ->and($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->subscription_plan_code)->toBe('free')
        ->and($billable->hasAccessibleBillingSubscription())->toBeTrue()
        ->and($billable->hasMollieMandate())->toBeFalse();
});

it('upgrades a free plan to a paid one: charged == invoiced == subscription == renewal', function (string $country, float $rate, string $interval, array $addons, int $extraSeats, array $lines): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable($country);
    app(ActivateLocalSubscription::class)->handle($billable, 'free', 'monthly');
    $billable->refresh();

    $net = array_sum($lines);
    $gross = BillingMoney::grossWithLineVat(array_values($lines), $rate);

    app(UpgradeLocalToMollie::class)->handle($billable, [
        'plan_code' => 'pro',
        'interval' => $interval,
        'addon_codes' => $addons,
        'extra_seats' => $extraSeats,
        'amount_gross' => $gross,
    ]);

    $payment = $m->lastPayment();
    expect(BookingMatrix::minor($payment))->toBe($gross);

    $invoice = $m->settle($payment, $country);
    $m->assertInvoice($invoice, $net, $gross - $net, $rate, $lines);

    $billable->refresh();
    expect($billable->subscription_source)->toBe(SubscriptionSource::Mollie)
        ->and($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->subscription_plan_code)->toBe('pro')
        ->and($billable->getBillingSubscriptionInterval())->toBe($interval)
        ->and($billable->subscription_ends_at)->toBeNull()
        ->and($m->subscriptionMinor())->toBe($gross);

    // The invoice states the period it bought (a yearly upgrade is a year, not a month).
    $line = collect($invoice->line_items)->firstWhere('kind', 'plan');
    expect(Carbon::parse($line['period_start'])->diffInDays(Carbon::parse($line['period_end'])))
        ->toBeGreaterThan($interval === 'yearly' ? 360 : 27);

    $renewal = $m->renew($billable, $interval, $country);
    $m->assertInvoice($renewal, $net, $gross - $net, $rate, $lines);
    $m->assertNoMismatch();
})->with([
    'AT monthly, plan only' => ['AT', 20.0, 'monthly', [], 0, ['plan' => 2950]],
    'DE monthly, seats + addons' => ['DE', 19.0, 'monthly', ['sso', 'crm'], 1, ['plan' => 2950, 'seats' => 555, 'addon:sso' => 1550, 'addon:crm' => 1000, 'addon_seats:crm' => 666]],
    'AT yearly, addon' => ['AT', 20.0, 'yearly', ['sso'], 0, ['plan' => 29950, 'addon:sso' => 15550]],
]);

it('re-books after the subscription ended: full price at once, no second trial', function (SubscriptionStatus $status): void {
    $m = BookingMatrix::boot();

    // A customer who had Team (which offers a trial), and whose subscription is over.
    $billable = $m->billable('AT', [
        'subscription_status' => $status,
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_plan_code' => $status === SubscriptionStatus::Expired ? null : 'team',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subMonths(3),
        'subscription_ends_at' => BillingTime::nowUtc()->subDays(40),
        'trial_ends_at' => BillingTime::nowUtc()->subMonths(4),
        'mollie_mandate_id' => 'mdt_old',
        'subscription_meta' => ['seat_count' => 5, 'past_due_since' => BillingTime::nowUtc()->subDays(50)->toIso8601String()],
    ]);

    $lines = ['plan' => 1950, 'addon:sso' => 1550];
    $checkout = $m->checkout('team', 'monthly', ['sso']);
    $shown = $m->shown($checkout);

    expect($checkout->instance()->trialApplies('team', 'monthly'))->toBeFalse()
        ->and($shown['gross'])->toBe(4200);

    $checkout->call('submit');

    $payment = $m->lastPayment();
    expect(BookingMatrix::prop($payment, 'metadata')['type'])->toBe('subscription')
        ->and(BookingMatrix::minor($payment))->toBe(4200);

    $invoice = $m->settle($payment);
    $m->assertInvoice($invoice, 3500, 700, 20.0, $lines);

    $billable->refresh();
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->subscription_ends_at)->toBeNull()
        ->and($billable->getBillingSeatCount())->toBe(1)
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('past_due_since')
        ->and($m->subscriptionMinor())->toBe(4200);

    // The first charge is one interval away — not at once, and not at a stale trial end.
    expect($m->subscriptionStartDate())->toBe(BillingTime::addInterval(BillingTime::nowUtc(), 'monthly')->toDateString());

    // The invoice's period is the one just bought, not the anchor of the old subscription.
    $line = collect($invoice->line_items)->firstWhere('kind', 'plan');
    expect(Carbon::parse($line['period_start'])->toDateString())->toBe(BillingTime::nowUtc()->toDateString());

    $renewal = $m->renew($billable);
    $m->assertInvoice($renewal, 3500, 700, 20.0, $lines);
    $m->assertNoMismatch();
})->with(['expired' => [SubscriptionStatus::Expired], 'cancelled, grace over' => [SubscriptionStatus::Cancelled], 'past due' => [SubscriptionStatus::PastDue]]);

it('sells a one-time product: charged == invoiced, and no subscription is created', function (string $country, ?string $vatNumber, float $rate): void {
    $m = BookingMatrix::boot();
    $billable = $vatNumber !== null ? $m->business($country, $vatNumber) : $m->billable($country);

    $result = app(StartOneTimeOrderCheckout::class)->handle($billable, ['product_code' => 'pack']);

    $vat = (int) round(4950 * $rate / 100);
    $payment = $m->lastPayment();
    expect($result['checkout_url'])->not->toBeNull()
        ->and(BookingMatrix::minor($payment))->toBe(4950 + $vat);

    $invoice = $m->settle($payment, $country);
    $m->assertInvoice($invoice, 4950, $vat, $rate, ['one_time_order:pack' => 4950]);

    expect($invoice->invoice_kind->value)->toBe('one_time_order')
        ->and($m->sentOf(CreateSubscriptionRequest::class))->toBe([])
        ->and($billable->fresh()->subscription_status)->toBe(SubscriptionStatus::New)
        ->and($billable->fresh()->boughtBillingProducts())->toBe(['pack']);

    // A re-delivery books nothing twice.
    $m->settle($payment, $country);
    expect(BillingInvoice::query()->count())->toBe(1);

    $m->assertNoMismatch();
})->with([
    'B2C AT' => ['AT', null, 20.0],
    'B2C DE' => ['DE', null, 19.0],
    'B2B DE, reverse charge' => ['DE', 'DE123456789', 0.0],
    'B2B AT, domestic' => ['AT', 'ATU12345678', 20.0],
]);
