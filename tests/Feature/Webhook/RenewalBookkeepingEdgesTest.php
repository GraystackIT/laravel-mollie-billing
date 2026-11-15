<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Events\InvoiceCreated;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use Illuminate\Support\Facades\Event;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;

require_once __DIR__.'/Support/RenewalLongRun.php';

/*
 * Two things a renewal must not do to the books, each of them invisible for months:
 * state a cent that was never collected, and write back a seat count somebody else has
 * changed in the meantime.
 */

beforeEach(fn () => longRunBoot());

afterEach(fn () => Carbon::setTestNow());

it('books a renewal at the amount collected when Mollie holds a cent less than the lines add up to', function (string $held, int $expectedGross): void {
    Event::fake([PaymentAmountMismatch::class]);

    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');

    // A subscription whose amount was set before the package rounded VAT per line (or
    // by hand in the dashboard): one cent away from what today's lines add up to.
    $world->heldValue = $held;

    foreach (range(1, 3) as $n) {
        $payment = longRunRenew($world);
        $invoice = BillingInvoice::query()->where('mollie_payment_id', $payment->id)->sole();
        $lines = collect((array) $invoice->line_items);

        // The document is the record of what was collected — to the cent.
        expect((int) $invoice->amount_gross)->toBe($expectedGross)
            ->and((int) $lines->sum('amount_gross'))->toBe($expectedGross)
            ->and((int) $lines->sum('amount_net'))->toBe((int) $invoice->amount_net)
            ->and((int) $invoice->amount_net + (int) $invoice->amount_vat)->toBe($expectedGross);
    }

    // A cent of rounding is not a mismatch anyone has to look at.
    Event::assertNotDispatched(PaymentAmountMismatch::class);

    // And a full refund asks Mollie for what the payment holds, not for a cent more —
    // which Mollie refuses outright.
    $world->requests = [];
    app(RefundInvoiceService::class)->refundFully($invoice->fresh(), RefundReasonCode::Goodwill);

    $refund = collect($world->requests)->last(fn ($r) => $r instanceof CreatePaymentRefundRequest);

    expect(longRunRequestProperty($refund, 'amount')->value)->toBe($held);
})->with([
    'a cent less than the lines' => ['34.50', 3450],
    'a cent more than the lines' => ['34.52', 3452],
]);

it('does not write back a seat count that changed while the renewal was being booked', function (): void {
    // 5 seats: 29.00 + 3 × 5.00 = 44.00 net; VAT 5.51 + 2.85 → 52.36.
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '52.36', ['extra_seats' => 3]);

    longRunAssertRenewal($world, longRunRenew($world), '52.36');

    // A seat sync commits in the middle of the next renewal — after the handler read the
    // seat count and built its lines, before it writes its own meta. `InvoiceCreated` is
    // dispatched synchronously at exactly that point.
    $billable = $world->billable;
    $fired = false;

    Event::listen(InvoiceCreated::class, function () use ($billable, &$fired): void {
        if ($fired) {
            return;
        }
        $fired = true;

        $fresh = $billable->fresh();
        $meta = $fresh->getBillingSubscriptionMeta();
        $meta['seat_count'] = 7;
        $fresh->forceFill(['subscription_meta' => $meta])->save();
    });

    $payment = longRunRenew($world);

    expect($fired)->toBeTrue()
        // The renewal was priced for five seats — that is what Mollie had created —
        ->and((int) BillingInvoice::query()->where('mollie_payment_id', $payment->id)->sole()->amount_gross)->toBe(5236)
        // — but the seven seats that are there now must still be there afterwards.
        ->and($world->billable->fresh()->getBillingSeatCount())->toBe(7);
});
