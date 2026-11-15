<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Tests\Support\BookingMatrix;

/*
 * Booking matrix, part 1: a first booking of a paid plan WITHOUT a coupon, through the
 * checkout component, the first-payment webhook and the first renewal — for every shape of
 * order (seats, addon, per-seat addon, yearly) and every VAT state.
 *
 * The chain: amount shown == amount sent to Mollie == invoice == Mollie subscription
 * amount == first renewal invoice, no `adjustment` line, no PaymentAmountMismatch.
 */

/** VAT the way an invoice carries it: rounded per line, then summed. */
function bmLineVat(array $lineNets, float $rate): int
{
    return (int) array_sum(array_map(fn (int $net): int => (int) round($net * $rate / 100), $lineNets));
}

dataset('bm plain orders', [
    // label => [plan, interval, addons, extra seats, expected invoice lines]
    'plan only' => ['pro', 'monthly', [], 0, ['plan' => 2950]],
    'plan + 3 extra seats' => ['pro', 'monthly', [], 3, ['plan' => 2950, 'seats' => 1665]],
    'plan + addon' => ['pro', 'monthly', ['sso'], 0, ['plan' => 2950, 'addon:sso' => 1550]],
    'plan + per-seat addon, plan seats only' => ['pro', 'monthly', ['crm'], 0, ['plan' => 2950, 'addon:crm' => 1000, 'addon_seats:crm' => 333]],
    'plan + per-seat addon + 2 extra seats' => ['pro', 'monthly', ['crm'], 2, ['plan' => 2950, 'seats' => 1110, 'addon:crm' => 1000, 'addon_seats:crm' => 999]],
    'everything' => ['pro', 'monthly', ['sso', 'crm'], 1, ['plan' => 2950, 'seats' => 555, 'addon:sso' => 1550, 'addon:crm' => 1000, 'addon_seats:crm' => 666]],
    'yearly plan' => ['pro', 'yearly', [], 0, ['plan' => 29950]],
    'yearly, everything' => ['pro', 'yearly', ['sso', 'crm'], 1, ['plan' => 29950, 'seats' => 5555, 'addon:sso' => 15550, 'addon:crm' => 10000, 'addon_seats:crm' => 6666]],
    'plan without seat price' => ['solo', 'monthly', [], 0, ['plan' => 1000]],
]);

dataset('bm vat states', [
    // label => [country, VAT number or null, additional_countries, rate]
    'B2C AT 20 %' => ['AT', null, [], 20.0],
    'B2C DE 19 %' => ['DE', null, [], 19.0],
    'B2B DE, reverse charge' => ['DE', 'DE123456789', [], 0.0],
    'B2B AT, domestic — no reverse charge' => ['AT', 'ATU12345678', [], 20.0],
    'additional country CH 8.1 %' => ['CH', null, ['CH' => ['vat_rate' => 8.1, 'name' => 'Switzerland']], 8.1],
    'additional country, business — no reverse charge' => ['GB', 'GB123456789', ['GB' => ['vat_rate' => 20.0, 'name' => 'United Kingdom']], 20.0],
]);

it('books a paid plan: shown == charged == invoiced == subscription == renewal', function (
    string $plan, string $interval, array $addons, int $extraSeats, array $lines,
    string $country, ?string $vatNumber, array $additional, float $rate,
): void {
    $m = BookingMatrix::boot();
    config()->set('mollie-billing.additional_countries', $additional);

    $billable = $vatNumber !== null ? $m->business($country, $vatNumber) : $m->billable($country);

    $net = array_sum($lines);
    $vat = bmLineVat(array_values($lines), $rate);

    $checkout = $m->checkout($plan, $interval, $addons, $extraSeats);
    $shown = $m->shown($checkout);

    expect($shown['net'])->toBe($net)
        ->and($shown['vat'])->toBe($vat)
        ->and($shown['gross'])->toBe($net + $vat)
        ->and($shown['reverseCharge'])->toBe($vatNumber !== null && $rate === 0.0);

    $checkout->call('submit');

    $payment = $m->lastPayment();
    expect($payment)->not->toBeNull()
        ->and(BookingMatrix::minor($payment))->toBe($shown['gross']);

    $invoice = $m->settle($payment, $country);
    $m->assertInvoice($invoice, $net, $vat, $rate, $lines);

    $billable->refresh();
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->subscription_source)->toBe(SubscriptionSource::Mollie)
        ->and($billable->subscription_plan_code)->toBe($plan)
        ->and($billable->getActiveBillingAddonCodes())->toBe($addons)
        ->and($billable->getExtraBillingSeats())->toBe($extraSeats);

    expect($m->subscriptionMinor())->toBe($shown['gross']);
    expect(BookingMatrix::prop($m->lastSubscription(), 'interval'))->toBe($interval === 'yearly' ? '12 months' : '1 month');

    $renewal = $m->renew($billable, $interval, $country);
    $m->assertInvoice($renewal, $net, $vat, $rate, $lines);

    $m->assertNoMismatch();
})->with('bm plain orders')->with('bm vat states');
