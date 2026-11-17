<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\RenewalCollectedForEndedSubscription;
use GraystackIT\MollieBilling\Events\UnwantedPaymentRefunded;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Notifications\UnwantedPaymentRefundedNotification;
use GraystackIT\MollieBilling\Services\Webhook\UnwantedPaymentRefund;
use GraystackIT\MollieBilling\Support\BillingAuditEntry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;

require_once __DIR__.'/Support/RenewalLongRun.php';

/*
 * Mollie collects a renewal for a subscription that no longer runs — its cancellation
 * never reached Mollie. That used to end in a mail asking an operator to refund the
 * payment and cancel the Mollie subscription by hand. The package now does both itself:
 * the payment is invoiced and credited in full, the Mollie subscription is cancelled, the
 * customer is told. An operator hears about it only when one of the two cannot be done.
 */

beforeEach(function (): void {
    longRunBoot();
    Notification::fake();
    MollieBilling::notifyAdminUsing(fn (): array => [new \Illuminate\Notifications\AnonymousNotifiable]);
});

afterEach(function (): void {
    Carbon::setTestNow();
    MollieBilling::notifyAdminUsing(fn (): array => []);
});

/** A subscriber whose subscription ended locally while Mollie still holds it. */
function endedRenewalWorld(array $billableAttributes, string $gross, SubscriptionStatus $status = SubscriptionStatus::Cancelled): LongRunWorld
{
    $world = longRunStart('monthly', '2027-01-15 10:00:00', $gross, [], $billableAttributes);

    $world->billable->forceFill([
        'subscription_status' => $status,
        'subscription_ends_at' => Carbon::parse('2027-02-01 00:00:00', 'UTC'),
    ])->save();
    $world->billable = $world->billable->fresh();

    return $world;
}

it('refunds the renewal in full, cancels the Mollie subscription and tells the customer — nobody else', function (array $attributes, string $gross, int $vat): void {
    $world = endedRenewalWorld($attributes, $gross);
    $captured = [];
    Event::listen(UnwantedPaymentRefunded::class, function (UnwantedPaymentRefunded $e) use (&$captured): void {
        $captured[] = $e;
    });

    $payment = longRunRenew($world);

    $invoice = BillingInvoice::query()->where('mollie_payment_id', $payment->id)->sole();
    $creditNote = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->sole();

    // Invoice and credit note net to zero — in gross, net and VAT.
    expect((int) $invoice->amount_gross)->toBe((int) round(((float) $gross) * 100))
        ->and((int) $invoice->amount_vat)->toBe($vat)
        ->and((int) $creditNote->amount_gross)->toBe(-(int) $invoice->amount_gross)
        ->and((int) $creditNote->amount_net)->toBe(-(int) $invoice->amount_net)
        ->and((int) $creditNote->amount_vat)->toBe(-$vat)
        ->and($invoice->fresh()->isFullyRefunded())->toBeTrue();

    // Mollie was asked for exactly what it collected, and to stop charging.
    $refund = collect($world->requests)->last(fn ($r) => $r instanceof CreatePaymentRefundRequest);
    expect(longRunRequestProperty($refund, 'amount')->value)->toBe($gross)
        ->and(longRunRequestProperty($refund, 'paymentId'))->toBe($payment->id)
        ->and($world->cancelled)->toBeTrue();

    // Not booked as a renewal.
    expect($world->billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Cancelled)
        ->and($world->billable->getBillingPeriodStartsAt()->toDateString())->toBe('2027-01-15');

    // One event, with its audit rows, and the customer's mail.
    expect($captured)->toHaveCount(1)
        ->and($captured[0]->reason)->toBe(UnwantedPaymentRefund::REASON_RENEWAL_AFTER_END)
        ->and($captured[0]->creditNote?->getKey())->toBe($creditNote->getKey());

    $audit = BillingAuditEntry::model()::query()->pluck('event')->all();
    expect($audit)->toContain('unwanted_payment_refunded')
        ->and($audit)->toContain('renewal_collected_for_ended_subscription');

    Notification::assertSentOnDemand(
        UnwantedPaymentRefundedNotification::class,
        fn (UnwantedPaymentRefundedNotification $n): bool => $n->amountGross === (int) $invoice->amount_gross
            && $n->reason === UnwantedPaymentRefund::REASON_RENEWAL_AFTER_END,
    );

    // The operator is not bothered.
    Notification::assertNotSentTo(new \Illuminate\Notifications\AnonymousNotifiable, AdminPlanChangeFailedNotification::class);
    Notification::assertSentOnDemandTimes(AdminPlanChangeFailedNotification::class, 0);
})->with(longRunVatCases());

it('does nothing a second time when the renewal is delivered again', function (): void {
    $world = endedRenewalWorld(['billing_country' => 'DE'], '34.51', SubscriptionStatus::Expired);

    $payment = longRunRenew($world);
    $refundsBefore = collect($world->requests)->filter(fn ($r) => $r instanceof CreatePaymentRefundRequest)->count();

    longRunDeliver($world, $payment, '2027-02-16 09:00:00');
    longRunDeliver($world, $payment, '2027-02-17 09:00:00');

    expect(collect($world->requests)->filter(fn ($r) => $r instanceof CreatePaymentRefundRequest)->count())->toBe($refundsBefore)->toBe(1)
        ->and(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(1)
        ->and(BillingInvoice::query()->where('mollie_payment_id', $payment->id)->count())->toBe(1);

    Notification::assertSentOnDemandTimes(UnwantedPaymentRefundedNotification::class, 1);
});

it('queues a refund Mollie would not take and posts it on the retry', function (): void {
    $world = endedRenewalWorld(['billing_country' => 'DE'], '34.51');
    $world->refuse[CreatePaymentRefundRequest::class] = 1;

    $payment = longRunRenew($world);
    $invoice = BillingInvoice::query()->where('mollie_payment_id', $payment->id)->sole();

    // Nothing credited yet; the line waits where the retry sweep finds it. The customer
    // already knows. No operator is told: the automatic way is still running, and an admin
    // hears of a refund only when its retries are used up (RetryRefundLineJob).
    $queued = (array) ($world->billable->fresh()->getBillingSubscriptionMeta()['pending_refund_retries'] ?? []);

    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(0)
        ->and($queued)->toHaveCount(1);
    Notification::assertSentOnDemandTimes(UnwantedPaymentRefundedNotification::class, 1);
    Notification::assertSentOnDemandTimes(AdminPlanChangeFailedNotification::class, 0);

    // The webhook again in between: no second attempt next to the queued one, no second mail.
    longRunDeliver($world, $payment, '2027-02-15 08:00:00');
    expect(collect($world->requests)->filter(fn ($r) => $r instanceof CreatePaymentRefundRequest)->count())->toBe(1);

    // The sweep turns the marker into the retry job; Mollie takes it this time.
    app()->call([new \GraystackIT\MollieBilling\Jobs\SweepPendingRetriesJob, 'handle']);

    $creditNote = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->sole();

    expect((int) $creditNote->amount_gross)->toBe(-(int) $invoice->amount_gross)
        ->and($invoice->fresh()->isFullyRefunded())->toBeTrue()
        ->and($world->billable->fresh()->getBillingSubscriptionMeta()['pending_refund_retries'] ?? [])->toBe([])
        ->and(collect($world->requests)->filter(fn ($r) => $r instanceof CreatePaymentRefundRequest)->count())->toBe(2);

    Notification::assertSentOnDemandTimes(UnwantedPaymentRefundedNotification::class, 1);
    Notification::assertSentOnDemandTimes(AdminPlanChangeFailedNotification::class, 0);
});

it('leaves the Mollie cancellation to the retry sweep when Mollie cannot be reached', function (): void {
    $world = endedRenewalWorld(['billing_country' => 'DE'], '34.51');
    $world->refuse[CancelSubscriptionRequest::class] = 1;

    $payment = longRunRenew($world);

    // The refund went through regardless; the cancel is parked where SweepPendingRetriesJob finds it.
    $marker = $world->billable->fresh()->getBillingSubscriptionMeta()['pending_subscription_cancel'] ?? null;

    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(1)
        ->and($world->cancelled)->toBeFalse()
        ->and($marker['mollie_subscription_id'] ?? null)->toBe('sub_longrun')
        ->and($marker['mollie_customer_id'] ?? null)->toBe('cst_longrun');

    Notification::assertSentOnDemandTimes(AdminPlanChangeFailedNotification::class, 0);
});
