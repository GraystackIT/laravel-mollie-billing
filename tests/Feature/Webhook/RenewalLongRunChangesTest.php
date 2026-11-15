<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/Support/RenewalLongRun.php';

/*
 * Seats and addons over a long run, including a per-seat addon and changes made between
 * two renewals. After every change Mollie has to hold the new amount at once, and every
 * renewal that follows has to book exactly that.
 *
 * Pro 29.00 (2 seats included), extra seat 5.00, SSO 15.00,
 * CRM 10.00 + 2.00 for every billed seat beyond its own 3 free ones. DE 19 %, per line.
 */

beforeEach(function (): void {
    longRunBoot();
    Event::fake([PaymentAmountMismatch::class]);
});

afterEach(fn () => Carbon::setTestNow());

/** @return array<string, int> kind => quantity */
function longRunLineQuantities(BillingInvoice $invoice): array
{
    return collect((array) $invoice->line_items)->mapWithKeys(fn (array $l) => [$l['kind'] => (int) $l['quantity']])->all();
}

it('books a per-seat addon with its own free seats on every renewal for a year', function (): void {
    // 5 seats: plan 29.00 | 3 extra seats 15.00 | CRM 10.00 | CRM seats (5 − 3) × 2.00 = 4.00
    // net 58.00; VAT per line 5.51 + 2.85 + 1.90 + 0.76 = 11.02 → 69.02.
    $world = longRunStart('monthly', '2027-01-12 10:00:00', '69.02', ['addon_codes' => ['crm'], 'extra_seats' => 3]);

    expect(longRunLineQuantities($world->lastInvoice))->toBe(['plan' => 1, 'seats' => 3, 'addon' => 1, 'addon_seats' => 2]);

    foreach (range(1, 12) as $n) {
        $payment = longRunRenew($world);
        $invoice = longRunAssertRenewal($world, $payment, '69.02');

        expect(longRunLineQuantities($invoice))->toBe(['plan' => 1, 'seats' => 3, 'addon' => 1, 'addon_seats' => 2])
            ->and($world->billable->getBillingSeatCount())->toBe(5);
    }

    expect($world->patches)->toBe([]);
    Event::assertNotDispatched(PaymentAmountMismatch::class);
});

it('charges every renewal at the configuration the customer has after each seat or addon change', function (): void {
    $world = longRunStart('monthly', '2027-01-12 10:00:00', '69.02', ['addon_codes' => ['crm'], 'extra_seats' => 3]);

    foreach (range(1, 2) as $n) {
        longRunAssertRenewal($world, longRunRenew($world), '69.02');
    }

    // ── A sixth seat, mid-period ────────────────────────────────────────────────
    // + 5.00 seat + 2.00 CRM seat: net 65.00; VAT 5.51 + 3.80 + 1.90 + 1.14 → 77.35.
    Carbon::setTestNow(Carbon::parse('2027-03-27 09:00:00', 'UTC'));
    app(UpdateSubscription::class)->update($world->billable->fresh(), ['seats' => 6]);

    // Mollie holds the new amount in the same call, not once the prorata charge clears.
    expect($world->heldValue)->toBe('77.35');

    $prorata = longRunSettleSideCharge($world, '2027-03-27 09:05:00');

    // 16 of 31 days left of 12 March → 12 April: 7.00 × 16/31 = 3.61 net.
    expect((int) $prorata->amount_net)->toBeGreaterThan(0)->toBeLessThan(700)
        ->and($world->billable->getBillingSeatCount())->toBe(6);

    foreach (range(1, 3) as $n) {
        $invoice = longRunAssertRenewal($world, longRunRenew($world), '77.35');

        expect(longRunLineQuantities($invoice))->toBe(['plan' => 1, 'seats' => 4, 'addon' => 1, 'addon_seats' => 3])
            ->and($world->billable->getBillingSeatCount())->toBe(6);
    }

    // ── The CRM addon is dropped, mid-period ────────────────────────────────────
    // net 29.00 + 20.00 = 49.00; VAT 5.51 + 3.80 → 58.31.
    Carbon::setTestNow(Carbon::parse('2027-06-20 09:00:00', 'UTC'));
    app(UpdateSubscription::class)->update($world->billable->fresh(), ['addons' => []]);

    expect($world->heldValue)->toBe('58.31')
        ->and($world->billable->fresh()->getActiveBillingAddonCodes())->toBe([]);

    foreach (range(1, 3) as $n) {
        $invoice = longRunAssertRenewal($world, longRunRenew($world), '58.31');

        expect(longRunLineQuantities($invoice))->toBe(['plan' => 1, 'seats' => 4]);
    }

    // ── Two seats are given up, mid-period ──────────────────────────────────────
    // net 29.00 + 10.00 = 39.00; VAT 5.51 + 1.90 → 46.41.
    Carbon::setTestNow(Carbon::parse('2027-09-20 09:00:00', 'UTC'));
    app(UpdateSubscription::class)->update($world->billable->fresh(), ['seats' => 4]);

    expect($world->heldValue)->toBe('46.41');

    foreach (range(1, 4) as $n) {
        $invoice = longRunAssertRenewal($world, longRunRenew($world), '46.41');

        expect(longRunLineQuantities($invoice))->toBe(['plan' => 1, 'seats' => 2])
            ->and($world->billable->getBillingSeatCount())->toBe(4);
    }

    // The cadence never moved: an amount-only change sends no start date.
    expect(collect($world->patches)->pluck('startDate')->filter()->all())->toBe([])
        ->and($world->billable->getBillingPeriodStartsAt()->day)->toBe(12);

    Event::assertNotDispatched(PaymentAmountMismatch::class);
});
