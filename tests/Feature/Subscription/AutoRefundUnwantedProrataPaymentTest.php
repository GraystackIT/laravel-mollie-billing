<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\UnwantedPaymentRefunded;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Notifications\UnwantedPaymentRefundedNotification;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Support\BillingAuditEntry;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\ChangeMatrixWorld as World;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/**
 * A prorata payment that arrives for a change which is no longer wanted is returned
 * automatically — nobody has to refund it by hand.
 *
 *  - the customer cancelled the pending change while its payment could not be cancelled
 *    any more (SEPA in flight), and the payment then settled;
 *  - the subscription ended before the payment settled.
 *
 * The payment is booked (an invoice) and credited (a credit note), netting to zero in net
 * and VAT; the customer is told; an admin hears about it only when the refund itself
 * could not be posted.
 */
beforeEach(function (): void {
    World::boot();

    $this->admin = TestBillable::create(['name' => 'Operator', 'email' => 'operator@x.test']);
    MollieBilling::notifyAdminUsing(fn (): array => [$this->admin]);
    MollieBilling::notifyBillingAdminsUsing(fn ($billable): array => [$billable]);
});

/**
 * A Pro → Enterprise change whose prorata payment is in flight, then cancelled by the
 * customer. Mollie reports the payment as pending and not cancelable.
 *
 * @return array{0: TestBillable, 1: string, 2: string} billable, payment id, gross value
 */
function unwantedCancelledChange(string $discount = 'none'): array
{
    $billable = World::subscription($discount, 'pro', 2, []);

    World::change($billable, ['plan_code' => 'enterprise', 'interval' => 'monthly', 'apply_at' => 'immediate'], settle: null);

    $paymentId = (string) $billable->refresh()->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'];

    app(UpdateSubscription::class)->cancelPendingPlanChange($billable);

    return [$billable->refresh(), $paymentId, World::$payments[$paymentId]['value']];
}

/** @return array{0: ?BillingInvoice, 1: ?BillingInvoice} the payment's invoice and its credit note */
function unwantedDocuments(TestBillable $billable, string $paymentId): array
{
    $invoice = BillingInvoice::query()->where('mollie_payment_id', $paymentId)->first();

    $creditNote = $invoice === null ? null : BillingInvoice::query()
        ->where('billable_id', $billable->getKey())
        ->where('invoice_kind', InvoiceKind::Refund)
        ->get()
        ->first(fn (BillingInvoice $note): bool => (int) ($note->line_items[0]['parent_invoice_id'] ?? 0) === (int) $invoice->id);

    return [$invoice, $creditNote];
}

it('does not alert anyone when a change is cancelled while its payment can still settle', function (): void {
    [$billable] = unwantedCancelledChange();

    // The marker is kept — it is what explains the payment if it arrives.
    expect($billable->getBillingSubscriptionMeta()['orphaned_prorata_payment']['reason'] ?? null)->toBe('not_cancelable')
        ->and($billable->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null)->toBeNull();

    Notification::assertNothingSentTo($this->admin);
});

it('refunds a prorata payment in full when it settles after the change was cancelled', function (): void {
    [$billable, $paymentId, $value] = unwantedCancelledChange();
    $collected = World::netCollected($billable);
    $held = World::$heldValue;

    Event::fake([UnwantedPaymentRefunded::class]);
    World::deliver($billable, $paymentId, 'paid', $value, ['type' => 'prorata_charge']);
    $billable->refresh();

    [$invoice, $creditNote] = unwantedDocuments($billable, $paymentId);

    // 200.00 × 21/31 = 135.48 net, 162.58 gross — booked, and credited back in full.
    expect($value)->toBe('162.58')
        ->and($invoice)->not->toBeNull()
        ->and((int) $invoice->amount_gross)->toBe(16258)
        ->and($creditNote)->not->toBeNull()
        ->and((int) $creditNote->amount_net)->toBe(-(int) $invoice->amount_net)
        ->and((int) $creditNote->amount_vat)->toBe(-(int) $invoice->amount_vat)
        ->and((int) $creditNote->amount_gross)->toBe(-16258)
        ->and(World::netCollected($billable))->toBe($collected)
        // The whole payment went back at Mollie.
        ->and(array_map(fn ($r): string => (string) $r->amount->value, World::$refunds[$paymentId] ?? []))->toBe(['162.58'])
        // And nothing about the subscription moved.
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and(World::$heldValue)->toBe($held)
        ->and($billable->getBillingSubscriptionMeta()['orphaned_prorata_payment'] ?? null)->toBeNull();

    Event::assertDispatchedTimes(UnwantedPaymentRefunded::class, 1);
    Event::assertDispatched(UnwantedPaymentRefunded::class, fn ($e): bool => $e->reason === 'change_cancelled'
        && $e->paymentId === $paymentId
        && $e->creditNote !== null);

    Notification::assertSentTo($billable, UnwantedPaymentRefundedNotification::class, fn ($n): bool => $n->amountGross === 16258 && $n->reason === 'change_cancelled');
    Notification::assertNothingSentTo($this->admin);
});

it('writes the automatic refund to the audit trail', function (): void {
    [$billable, $paymentId, $value] = unwantedCancelledChange();

    World::deliver($billable, $paymentId, 'paid', $value, ['type' => 'prorata_charge']);

    $rows = BillingAuditEntry::model()::query()->where('event', 'unwanted_payment_refunded')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->properties['replace']['reason'])->toBe('change_cancelled')
        ->and((int) $rows->first()->properties['replace']['amount_cents'])->toBe(16258);
});

it('refunds, credits and announces once, however often the payment is delivered', function (): void {
    [$billable, $paymentId, $value] = unwantedCancelledChange();

    World::deliver($billable, $paymentId, 'paid', $value, ['type' => 'prorata_charge']);
    World::deliver($billable, $paymentId, 'paid', $value, ['type' => 'prorata_charge']);
    World::deliver($billable, $paymentId, 'paid', $value, ['type' => 'prorata_charge']);

    expect(World::$refunds[$paymentId])->toHaveCount(1)
        ->and(BillingInvoice::query()->where('mollie_payment_id', $paymentId)->count())->toBe(1)
        ->and(BillingInvoice::query()->where('billable_id', $billable->getKey())->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(1)
        ->and(BillingAuditEntry::model()::query()->where('event', 'unwanted_payment_refunded')->count())->toBe(1);

    Notification::assertSentToTimes($billable, UnwantedPaymentRefundedNotification::class, 1);
});

it('queues a refused refund without telling an admin, and tells one — once — when the retries are used up', function (): void {
    [$billable, $paymentId, $value] = unwantedCancelledChange();

    World::$failRefunds = true;
    World::deliver($billable, $paymentId, 'paid', $value, ['type' => 'prorata_charge']);
    $billable->refresh();

    [$invoice, $creditNote] = unwantedDocuments($billable, $paymentId);
    $queued = $billable->getBillingSubscriptionMeta()['pending_refund_retries'] ?? [];

    // Booked, not yet credited, parked for the refund retry job. The automatic way is
    // not exhausted: nobody is asked to do anything.
    expect($invoice)->not->toBeNull()
        ->and($creditNote)->toBeNull()
        ->and($queued)->toHaveCount(1)
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('pro');

    Notification::assertNotSentTo($this->admin, AdminPlanChangeFailedNotification::class);

    // A re-delivery while the line is queued does not post a second refund.
    World::$failRefunds = false;
    World::deliver($billable, $paymentId, 'paid', $value, ['type' => 'prorata_charge']);

    expect(World::$refunds[$paymentId] ?? [])->toBe([]);
    Notification::assertNotSentTo($this->admin, AdminPlanChangeFailedNotification::class);

    // The retry job runs out of attempts: now an operator has to know, exactly once.
    $entry = (array) array_values($queued)[0];
    $job = new \GraystackIT\MollieBilling\Jobs\RetryRefundLineJob(
        $billable::class,
        $billable->getKey(),
        is_array($entry['line'] ?? null) ? $entry['line'] : $entry,
        now()->toIso8601String(),
    );
    $job->failed(new RuntimeException('Mollie keeps refusing'));

    Notification::assertSentToTimes($this->admin, AdminPlanChangeFailedNotification::class, 1);
    expect($billable->refresh()->getBillingSubscriptionMeta()['pending_refund_retries'] ?? [])->toBe([])
        ->and($billable->getBillingSubscriptionMeta()['pending_refund_retries_dead_letter'] ?? [])->toHaveCount(1);
});

/** The process dies between booking the payment and returning it: no refund, nothing queued. */
class UnwantedRefundThatDiesAfterBooking extends \GraystackIT\MollieBilling\Services\Webhook\UnwantedPaymentRefund
{
    protected function refund(\GraystackIT\MollieBilling\Contracts\Billable $billable, BillingInvoice $invoice): ?BillingInvoice
    {
        throw new RuntimeException('the process died here');
    }
}

it('finishes the refund on a re-delivery when the first run booked the payment and got no further', function (): void {
    [$billable, $paymentId, $value] = unwantedCancelledChange();

    app()->bind(\GraystackIT\MollieBilling\Services\Webhook\UnwantedPaymentRefund::class, UnwantedRefundThatDiesAfterBooking::class);
    try {
        World::deliver($billable, $paymentId, 'paid', $value, ['type' => 'prorata_charge']);
    } catch (Throwable) {
        // The delivery fails; Mollie will deliver again.
    }

    [$invoice, $creditNote] = unwantedDocuments($billable->refresh(), $paymentId);
    expect($invoice)->not->toBeNull()->and($creditNote)->toBeNull()->and(World::$refunds[$paymentId] ?? [])->toBe([]);

    // Mollie delivers again; this time the process lives.
    unset(app()[\GraystackIT\MollieBilling\Services\Webhook\UnwantedPaymentRefund::class]);
    World::deliver($billable, $paymentId, 'paid', $value, ['type' => 'prorata_charge']);

    [$invoice, $creditNote] = unwantedDocuments($billable->refresh(), $paymentId);

    // The same invoice, now credited in full, and Mollie asked once for all of it.
    expect($creditNote)->not->toBeNull()
        ->and(abs((int) $creditNote->amount_gross))->toBe((int) $invoice->amount_gross)
        ->and(World::$refunds[$paymentId] ?? [])->toHaveCount(1)
        ->and((string) World::$refunds[$paymentId][0]->amount->value)->toBe($value)
        ->and(BillingInvoice::query()->where('mollie_payment_id', $paymentId)->count())->toBe(1);
});

it('refunds a prorata payment that settles after the subscription has ended', function (): void {
    $billable = World::subscription('none', 'pro', 2, []);
    World::change($billable, ['plan_code' => 'enterprise', 'interval' => 'monthly', 'apply_at' => 'immediate'], settle: null);
    $paymentId = (string) $billable->refresh()->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'];
    $value = World::$payments[$paymentId]['value'];

    // The subscription is cancelled and runs out before the debit clears.
    $billable->forceFill(['subscription_status' => SubscriptionStatus::Expired])->save();
    $this->travelTo(Carbon::parse('2026-02-05 12:00:00', 'UTC'));

    Event::fake([UnwantedPaymentRefunded::class]);
    World::deliver($billable, $paymentId, 'paid', $value, ['type' => 'prorata_charge']);
    World::deliver($billable, $paymentId, 'paid', $value, ['type' => 'prorata_charge']);
    $billable->refresh();

    [$invoice, $creditNote] = unwantedDocuments($billable, $paymentId);

    expect($invoice)->not->toBeNull()
        ->and($creditNote)->not->toBeNull()
        ->and((int) $creditNote->amount_gross)->toBe(-(int) $invoice->amount_gross)
        ->and(World::$refunds[$paymentId])->toHaveCount(1)
        // Not applied: an ended subscription does not get its plan back.
        ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Expired)
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and($billable->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null)->toBeNull();

    Event::assertDispatchedTimes(UnwantedPaymentRefunded::class, 1);
    Event::assertDispatched(UnwantedPaymentRefunded::class, fn ($e): bool => $e->reason === 'subscription_ended');
    Notification::assertSentTo($billable, UnwantedPaymentRefundedNotification::class, fn ($n): bool => $n->reason === 'subscription_ended');
    Notification::assertNothingSentTo($this->admin);
});

it('returns exactly what was collected under every VAT treatment', function (string $country, ?string $vatNumber, string $expectedValue, int $expectedVat) {
    World::$country = $country;
    World::$vatNumber = $vatNumber;

    [$billable, $paymentId, $value] = unwantedCancelledChange();

    World::deliver($billable, $paymentId, 'paid', $value, ['type' => 'prorata_charge']);

    [$invoice, $creditNote] = unwantedDocuments($billable->refresh(), $paymentId);

    // 135.48 net in every case; the VAT is what the country says.
    expect($value)->toBe($expectedValue)
        ->and((int) $invoice->amount_net)->toBe(13548)
        ->and((int) $invoice->amount_vat)->toBe($expectedVat)
        ->and((int) $creditNote->amount_net)->toBe(-13548)
        ->and((int) $creditNote->amount_vat)->toBe(-$expectedVat)
        ->and((string) World::$refunds[$paymentId][0]->amount->value)->toBe($expectedValue);
})->with([
    'AT, 20 %' => ['AT', null, '162.58', 2710],
    'DE, 19 %' => ['DE', null, '161.22', 2574],
    'DE business, reverse charge' => ['DE', 'DE123456789', '135.48', 0],
]);

it('returns the discounted amount when the change was priced under a coupon or a waiver', function (string $discount) {
    [$billable, $paymentId, $value] = unwantedCancelledChange($discount);
    $collected = World::netCollected($billable);

    World::deliver($billable, $paymentId, 'paid', $value, ['type' => 'prorata_charge']);

    [$invoice, $creditNote] = unwantedDocuments($billable->refresh(), $paymentId);

    expect((string) World::$refunds[$paymentId][0]->amount->value)->toBe($value)
        ->and((int) $creditNote->amount_gross)->toBe(-(int) $invoice->amount_gross)
        ->and(World::netCollected($billable))->toBe($collected)
        // The discount is exactly where it was.
        ->and($billable->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null)->not->toBeNull();
})->with(['50 % coupon' => 'pct50', 'fixed coupon' => 'fixed', 'waiver with a paid seat' => 'waiver-periods']);

it('returns the payment in full for a billable that has lost its billing country, with the tax the payment was charged at', function (): void {
    [$billable, $paymentId, $value] = unwantedCancelledChange();

    // The address was emptied after the change had been priced (AT, 20 %).
    $billable->forceFill(['billing_country' => null, 'tax_country_user' => null])->save();

    World::deliver($billable->refresh(), $paymentId, 'paid', $value, ['type' => 'prorata_charge']);

    [$invoice, $creditNote] = unwantedDocuments($billable->refresh(), $paymentId);
    $gross = \GraystackIT\MollieBilling\Support\BillingMoney::minorFromMollieAmount($value, 'EUR');

    // Whatever rate the documents carry, the money is exact: all of it booked, all of it
    // credited, all of it asked back from Mollie — once.
    expect($invoice)->not->toBeNull()
        ->and($creditNote)->not->toBeNull()
        ->and((int) $invoice->amount_gross)->toBe($gross)
        ->and(abs((int) $creditNote->amount_gross))->toBe($gross)
        ->and((int) $invoice->amount_net + (int) $invoice->amount_vat)->toBe($gross)
        ->and(World::$refunds[$paymentId] ?? [])->toHaveCount(1)
        ->and((string) World::$refunds[$paymentId][0]->amount->value)->toBe($value)
        // Priced in Austria: the document says 20 %, not the fallback country's rate.
        ->and((float) ($invoice->line_items[0]['vat_rate'] ?? -1))->toBe(20.0);
});
