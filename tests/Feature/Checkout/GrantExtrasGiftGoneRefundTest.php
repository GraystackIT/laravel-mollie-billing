<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\DuplicatePaymentReceived;
use GraystackIT\MollieBilling\Events\GrantExtrasPurchaseRefunded;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Jobs\RevokeMollieMandateJob;
use GraystackIT\MollieBilling\Jobs\SweepPendingRetriesJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Notifications\GrantExtrasPurchaseRefundedNotification;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\PlanWaiver;
use GraystackIT\MollieBilling\Services\Billing\UpgradeLocalToMollie;
use GraystackIT\MollieBilling\Services\Vat\OssProtocolService;
use GraystackIT\MollieBilling\Support\BillingAuditMap;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\BookingMatrix;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;

/*
 * A tenant on a gifted plan buys paid extras (`handleKeepingGrant()`), and the gift is gone
 * by the time the payment settles: revoked by an operator, run out, or expired by the
 * nightly pass. The purchase was priced as "the extras, the plan stays free" — so it is
 * not carried out. The payment is booked, returned in full, and nothing about the tenant
 * changes: no subscription, no waiver, no addons, no seats.
 *
 * (It used to be booked as a regular upgrade — a subscription at the full price from the
 * next period on — and before that it was dropped as a "duplicate".)
 */

afterEach(fn () => Carbon::setTestNow());

final class GiftGoneAdmin
{
    use Illuminate\Notifications\Notifiable;

    public string $email = 'ops@x.test';

    public function getKey(): string
    {
        return 'ops';
    }
}

/**
 * @return array{0: BookingMatrix, 1: TestBillable, 2: GiftGoneAdmin}
 */
function giftGoneSetup(string $country = 'AT', ?string $vatNumber = null): array
{
    $m = BookingMatrix::boot();
    Event::fake([DuplicatePaymentReceived::class, PaymentAmountMismatch::class, GrantExtrasPurchaseRefunded::class]);
    Bus::fake([RevokeMollieMandateJob::class]);

    $billable = $vatNumber !== null ? $m->business($country, $vatNumber) : $m->billable($country);

    $service = app(CouponService::class);
    $coupon = $service->accessGrantCoupon('GIFT-GONE', 'pro', 'monthly', [], 90);
    $service->validate('GIFT-GONE', $billable->fresh(), []);
    $service->redeem($coupon, $billable->fresh(), []);

    $admin = new GiftGoneAdmin;
    MollieBilling::notifyAdminUsing(fn (): array => [$admin]);
    MollieBilling::notifyBillingAdminsUsing(fn ($b): array => [$b]);

    return [$m, $billable->fresh(), $admin];
}

/** The state of the tenant that a purchase could have touched. */
function giftGoneState(TestBillable $billable): array
{
    $billable = $billable->fresh();

    return [
        'source' => $billable->subscription_source,
        'status' => $billable->subscription_status,
        'plan' => $billable->subscription_plan_code,
        'ends_at' => $billable->subscription_ends_at?->toIso8601String(),
        'addons' => $billable->getActiveBillingAddonCodes(),
        'seat_count' => $billable->getBillingSubscriptionMeta()['seat_count'] ?? null,
        'mandate' => $billable->mollie_mandate_id,
        'mollie_subscription' => $billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? null,
        'waiver' => app(PlanWaiver::class)->marker($billable),
    ];
}

dataset('gift gone', [
    'revoked by an operator' => [fn (TestBillable $b) => app(CouponService::class)->revokeGrant(CouponRedemption::query()->sole(), 'test')],
    'ran out while the payment was travelling' => [fn (TestBillable $b) => Carbon::setTestNow(BillingTime::nowUtc()->addDays(91))],
    'expired and wiped by the nightly pass' => [fn (TestBillable $b) => $b->forceFill([
        'subscription_status' => SubscriptionStatus::Expired,
        'subscription_source' => SubscriptionSource::None,
        'subscription_plan_code' => null,
        'subscription_ends_at' => BillingTime::nowUtc()->subDay(),
        'active_addon_codes' => [],
    ])->save()],
    'the grant redemption is gone although the dates still say it runs' => [fn (TestBillable $b) => CouponRedemption::query()->update(['revoked_at' => now()])],
]);

it('refunds the payment in full and changes nothing when the gift is gone', function (Closure $end): void {
    [$m, $billable, $admin] = giftGoneSetup();

    $result = app(UpgradeLocalToMollie::class)->handleKeepingGrant($billable, ['sso'], 3);
    $payment = $m->lastPayment();
    expect($result['amount_gross'])->toBe(2526); // (15.50 + 5.55) + 20 %

    $end($billable->fresh());
    $before = giftGoneState($billable);

    $invoice = $m->settle($payment);

    // The whole payment goes back — the amount Mollie collected, to the cent.
    $refunds = $m->sentOf(CreatePaymentRefundRequest::class);
    expect($refunds)->toHaveCount(1)
        ->and(BookingMatrix::minor($refunds[0]))->toBe(2526)
        ->and(BookingMatrix::prop($refunds[0], 'paymentId'))->toBe(BookingMatrix::paymentIdFor($payment));

    // No subscription, no waiver, and the tenant is exactly where they were.
    expect($m->sentOf(CreateSubscriptionRequest::class))->toBe([])
        ->and(giftGoneState($billable))->toBe($before)
        ->and($before['waiver'])->toBeNull()
        ->and($before['mandate'])->toBeNull()
        ->and($billable->fresh()->getActiveBillingAddonCodes())->not->toContain('sso');

    // Booked and credited: an invoice for what came in, a credit note for what went back.
    $creditNote = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->sole();

    expect($invoice)->not->toBeNull()
        ->and((int) $invoice->amount_gross)->toBe(2526)
        ->and((int) $creditNote->amount_gross)->toBe(-2526)
        ->and((int) $invoice->amount_net + (int) $creditNote->amount_net)->toBe(0)
        ->and((int) $invoice->amount_vat + (int) $creditNote->amount_vat)->toBe(0)
        ->and($invoice->fresh()->remainingRefundableNet())->toBe(0)
        ->and(BillingInvoice::query()->count())->toBe(2)
        ->and($billable->fresh()->getBillingSubscriptionMeta()['pending_refund_retries'] ?? [])->toBe([]);

    // The customer is told, once. It resolved itself: no operator is asked to do anything.
    Notification::assertSentToTimes($billable, GrantExtrasPurchaseRefundedNotification::class, 1);
    Notification::assertNotSentTo($admin, AdminPlanChangeFailedNotification::class);
    Event::assertDispatchedTimes(GrantExtrasPurchaseRefunded::class, 1);
    Event::assertNotDispatched(DuplicatePaymentReceived::class);
    Event::assertNotDispatched(PaymentAmountMismatch::class);

    // The mandate the first payment captured is not adopted, and not left behind.
    Bus::assertDispatched(RevokeMollieMandateJob::class, 1);
})->with('gift gone');

it('books the purchase as before while the gift still runs', function (): void {
    [$m, $billable, $admin] = giftGoneSetup();

    app(UpgradeLocalToMollie::class)->handleKeepingGrant($billable, ['sso'], 3);
    $invoice = $m->settle($m->lastPayment());

    expect($m->sentOf(CreatePaymentRefundRequest::class))->toBe([])
        ->and($m->sentOf(CreateSubscriptionRequest::class))->toHaveCount(1)
        ->and((int) $invoice->amount_gross)->toBe(2526)
        ->and($billable->fresh()->subscription_source)->toBe(SubscriptionSource::Mollie)
        ->and(app(PlanWaiver::class)->marker($billable->fresh()))->not->toBeNull();

    Notification::assertNothingSentTo($admin);
    Event::assertNotDispatched(GrantExtrasPurchaseRefunded::class);
});

it('does not refund a regular upgrade from an ended local plan — only a purchase that was priced on a gift', function (): void {
    [$m, $billable] = giftGoneSetup();

    // A regular upgrade (`handle()`, no `keep_grant`): the customer agreed to the full price.
    app(UpgradeLocalToMollie::class)->handle($billable, ['plan_code' => 'pro', 'interval' => 'monthly', 'amount_gross' => 3540]);
    app(CouponService::class)->revokeGrant(CouponRedemption::query()->sole(), 'test');

    $m->assertInvoice($m->settle($m->lastPayment()), 2950, 590, 20.0, ['plan' => 2950]);

    expect($m->sentOf(CreatePaymentRefundRequest::class))->toBe([])
        ->and($billable->fresh()->subscription_source)->toBe(SubscriptionSource::Mollie);
    Event::assertNotDispatched(GrantExtrasPurchaseRefunded::class);
});

it('refunds once: a re-delivery posts no second refund and sends no second mail', function (): void {
    [$m, $billable, $admin] = giftGoneSetup();

    app(UpgradeLocalToMollie::class)->handleKeepingGrant($billable, ['sso'], 3);
    $payment = $m->lastPayment();
    app(CouponService::class)->revokeGrant(CouponRedemption::query()->sole(), 'test');

    $m->settle($payment);
    $m->settle($payment);
    $m->settle($payment);

    expect($m->sentOf(CreatePaymentRefundRequest::class))->toHaveCount(1)
        ->and(BillingInvoice::query()->count())->toBe(2)
        ->and($m->sentOf(CreateSubscriptionRequest::class))->toBe([]);

    Notification::assertSentToTimes($billable, GrantExtrasPurchaseRefundedNotification::class, 1);
    Notification::assertNotSentTo($admin, AdminPlanChangeFailedNotification::class);
    Event::assertDispatchedTimes(GrantExtrasPurchaseRefunded::class, 1);
    Bus::assertDispatched(RevokeMollieMandateJob::class, 1);
});

it('does not book the refund a second time when Mollie reports it through the payment webhook', function (string $refundStatus): void {
    [$m, $billable] = giftGoneSetup();

    app(UpgradeLocalToMollie::class)->handleKeepingGrant($billable, ['sso'], 3);
    $payment = $m->lastPayment();
    app(CouponService::class)->revokeGrant(CouponRedemption::query()->sole(), 'test');
    $m->settle($payment);

    $refundId = (string) BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->sole()->line_items[0]['mollie_refund_id'];
    expect($refundId)->toStartWith('re_bm_');

    // Mollie calls the payment's webhook again once the refund exists.
    $m->deliver(new class(BookingMatrix::paymentIdFor($payment), $billable, BookingMatrix::prop($payment, 'metadata'), $refundId, $refundStatus)
    {
        public string $status = 'paid';

        public object $amount;

        public object $amountRefunded;

        public ?string $subscriptionId = null;

        public string $customerId = 'cst_bm';

        public string $mandateId = 'mdt_bm';

        public function __construct(public string $id, TestBillable $billable, public array $metadata, private string $refundId, private string $refundStatus)
        {
            $this->amount = (object) ['value' => '25.26', 'currency' => 'EUR'];
            $this->amountRefunded = (object) ['value' => '25.26', 'currency' => 'EUR'];
        }

        /** @return array<int, object> */
        public function refunds(): array
        {
            return [(object) ['id' => $this->refundId, 'status' => $this->refundStatus, 'amount' => (object) ['value' => '25.26', 'currency' => 'EUR']]];
        }
    });

    $invoice = BillingInvoice::query()->where('invoice_kind', '!=', InvoiceKind::Refund)->sole();

    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(1)
        ->and($invoice->effectiveRefundedNet())->toBe((int) $invoice->amount_net)
        ->and($m->sentOf(CreatePaymentRefundRequest::class))->toHaveCount(1)
        ->and($m->sentOf(CreateSubscriptionRequest::class))->toBe([]);
    Event::assertDispatchedTimes(GrantExtrasPurchaseRefunded::class, 1);
})->with(['pending' => ['pending'], 'refunded' => ['refunded']]);

it('queues the refund without alerting anyone when Mollie cannot be asked to pay it out, and the retry posts it', function (): void {
    [$m, $billable, $admin] = giftGoneSetup();

    app(UpgradeLocalToMollie::class)->handleKeepingGrant($billable, ['sso'], 3);
    $payment = $m->lastPayment();
    app(CouponService::class)->revokeGrant(CouponRedemption::query()->sole(), 'test');
    $before = giftGoneState($billable);

    // Mollie is down for the refund call.
    $m->intercept = fn (object $request) => $request instanceof CreatePaymentRefundRequest
        ? throw new RuntimeException('Mollie is unavailable')
        : null;

    $invoice = $m->settle($payment);

    // The payment is on the books, the refund is not — and it is parked where the
    // package's own retry picks it up, not forgotten.
    $pending = $billable->fresh()->getBillingSubscriptionMeta()['pending_refund_retries'] ?? [];

    expect($invoice)->not->toBeNull()
        ->and(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(0)
        ->and($pending)->toHaveCount(1)
        ->and((int) $pending[0]['line']['parent_invoice_id'])->toBe((int) $invoice->id)
        ->and((int) $pending[0]['line']['amount_gross'])->toBe(-2526)
        ->and($m->sentOf(CreateSubscriptionRequest::class))->toBe([]);

    $after = giftGoneState($billable);
    expect($after)->toBe($before);

    // The customer is told what happened. The refund is queued — the automatic way is
    // still running, so no admin hears of it yet.
    Notification::assertNotSentTo($admin, AdminPlanChangeFailedNotification::class);
    Notification::assertSentToTimes($billable, GrantExtrasPurchaseRefundedNotification::class, 1);

    // A re-delivery does not post a second attempt on its own: the line is queued.
    $m->settle($payment);
    expect($m->sentOf(CreatePaymentRefundRequest::class))->toHaveCount(1)
        ->and($billable->fresh()->getBillingSubscriptionMeta()['pending_refund_retries'])->toHaveCount(1);

    // Mollie is back; the hourly sweep retries the line and the credit note is written.
    $m->intercept = null;
    (new SweepPendingRetriesJob)->handle();

    $creditNote = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->sole();

    expect((int) $creditNote->amount_gross)->toBe(-2526)
        ->and($m->sentOf(CreatePaymentRefundRequest::class))->toHaveCount(2)
        ->and(BookingMatrix::minor($m->sentOf(CreatePaymentRefundRequest::class)[1]))->toBe(2526)
        ->and($billable->fresh()->getBillingSubscriptionMeta()['pending_refund_retries'] ?? [])->toBe([])
        ->and($invoice->fresh()->remainingRefundableNet())->toBe(0);

    Notification::assertSentToTimes($billable, GrantExtrasPurchaseRefundedNotification::class, 1);
    Notification::assertNotSentTo($admin, AdminPlanChangeFailedNotification::class);
});

it('tells an admin exactly once when the queued refund has used up its retries', function (): void {
    [$m, $billable, $admin] = giftGoneSetup();

    app(UpgradeLocalToMollie::class)->handleKeepingGrant($billable, ['sso'], 3);
    $payment = $m->lastPayment();
    app(CouponService::class)->revokeGrant(CouponRedemption::query()->sole(), 'test');

    $m->intercept = fn (object $request) => $request instanceof CreatePaymentRefundRequest
        ? throw new RuntimeException('Mollie is unavailable')
        : null;
    $m->settle($payment);

    Notification::assertNotSentTo($admin, AdminPlanChangeFailedNotification::class);

    $entry = (array) ($billable->fresh()->getBillingSubscriptionMeta()['pending_refund_retries'][0] ?? []);
    (new \GraystackIT\MollieBilling\Jobs\RetryRefundLineJob($billable::class, $billable->getKey(), (array) $entry['line'], now()->toIso8601String()))
        ->failed(new RuntimeException('Mollie keeps refusing'));

    Notification::assertSentToTimes($admin, AdminPlanChangeFailedNotification::class, 1);
    expect($billable->fresh()->getBillingSubscriptionMeta()['pending_refund_retries'] ?? [])->toBe([])
        ->and($billable->fresh()->getBillingSubscriptionMeta()['pending_refund_retries_dead_letter'] ?? [])->toHaveCount(1);
});

it('refunds a reverse-charge customer the net amount they paid, with no VAT on either document', function (): void {
    [$m, $billable] = giftGoneSetup('DE', 'DE123456789');

    $result = app(UpgradeLocalToMollie::class)->handleKeepingGrant($billable, ['sso'], 3);
    $payment = $m->lastPayment();
    expect($result['amount_gross'])->toBe(2105);

    app(CouponService::class)->revokeGrant(CouponRedemption::query()->sole(), 'test');
    $invoice = $m->settle($payment, 'DE');
    $creditNote = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->sole();

    expect(BookingMatrix::minor($m->sentOf(CreatePaymentRefundRequest::class)[0]))->toBe(2105)
        ->and((int) $invoice->amount_net)->toBe(2105)
        ->and((int) $invoice->amount_vat)->toBe(0)
        ->and((int) $creditNote->amount_net)->toBe(-2105)
        ->and((int) $creditNote->amount_vat)->toBe(0)
        ->and($invoice->vat_validation_id)->not->toBeNull()
        ->and($creditNote->vat_validation_id)->toBe($invoice->vat_validation_id);
});

it('nets to zero in the OSS export', function (): void {
    [$m, $billable] = giftGoneSetup('DE');
    Storage::fake('local');
    config()->set('mollie-billing.oss.disk', 'local');

    app(UpgradeLocalToMollie::class)->handleKeepingGrant($billable, ['sso'], 3);
    $payment = $m->lastPayment();
    app(CouponService::class)->revokeGrant(CouponRedemption::query()->sole(), 'test');
    $m->settle($payment, 'DE');

    $result = (new OssProtocolService)->export((int) now('UTC')->format('Y'));
    $rows = array_map('str_getcsv', explode("\n", trim((string) Storage::disk('local')->get($result->path))));

    $net = 0.0;
    $vat = 0.0;
    foreach (array_slice($rows, 1) as $row) {
        $net += (float) $row[4];
        $vat += (float) $row[5];
    }

    expect(count($rows))->toBeGreaterThan(1)
        ->and(round($net, 2))->toBe(0.0)
        ->and(round($vat, 2))->toBe(0.0);
});

it('writes an audit entry and words the customer mail in both languages', function (string $locale): void {
    app()->setLocale($locale);
    [$m, $billable] = giftGoneSetup();

    expect(array_keys(BillingAuditMap::all()))->toContain(GrantExtrasPurchaseRefunded::class);

    $key = 'billing::audit.event.grant_extras_purchase_refunded';
    $descriptor = BillingAuditMap::all()[GrantExtrasPurchaseRefunded::class];
    expect(trans('billing::'.$descriptor->descriptionKey()))->not->toBe('billing::'.$descriptor->descriptionKey());

    $mail = (new GrantExtrasPurchaseRefundedNotification($billable, 2526, 'EUR'))->toMail($billable);
    $text = implode(' ', $mail->introLines);

    expect($mail->subject)->not->toContain('billing::')
        ->and($text)->not->toContain('billing::')
        ->and($text)->toContain('25')
        ->and($mail->actionUrl)->not->toBeEmpty();
})->with(['en', 'de']);

/*
 * Decided by the maintainer on 16 Nov 2026: a refund that went through on its own is not an
 * operator's business. No admin mail on a successful automatic refund — an admin hears of
 * it only when the queued refund has used up its retries (tested above). The event and the
 * audit entry are how the successful case stays on record.
 */
it('sends no admin mail on a successful automatic refund, and still dispatches the event and writes the audit entry', function (): void {
    $m = BookingMatrix::boot();
    Bus::fake([RevokeMollieMandateJob::class]);

    $billable = $m->billable('AT');
    $service = app(CouponService::class);
    $coupon = $service->accessGrantCoupon('GIFT-GONE', 'pro', 'monthly', [], 90);
    $service->redeem($coupon, $billable->fresh(), []);

    $admin = new GiftGoneAdmin;
    MollieBilling::notifyAdminUsing(fn (): array => [$admin]);
    MollieBilling::notifyBillingAdminsUsing(fn ($b): array => [$b]);

    // The real event, with its listeners (the audit trail is one of them).
    $events = [];
    Event::listen(GrantExtrasPurchaseRefunded::class, function (GrantExtrasPurchaseRefunded $event) use (&$events): void {
        $events[] = $event;
    });

    app(UpgradeLocalToMollie::class)->handleKeepingGrant($billable->fresh(), ['sso'], 3);
    $payment = $m->lastPayment();
    $service->revokeGrant(CouponRedemption::query()->sole(), 'test');

    $m->settle($payment);

    // The refund went out — (15.50 + 5.55) + 20 % = 25.26.
    expect(BookingMatrix::minor($m->sentOf(CreatePaymentRefundRequest::class)[0]))->toBe(2526);

    // No operator is told anything, by any channel.
    Notification::assertNothingSentTo($admin);
    Notification::assertSentToTimes(new \Illuminate\Notifications\AnonymousNotifiable, AdminPlanChangeFailedNotification::class, 0);

    // The customer is, once.
    Notification::assertSentToTimes($billable, GrantExtrasPurchaseRefundedNotification::class, 1);

    // On record: the event, once, with the credit note — and the audit entry it produces.
    expect($events)->toHaveCount(1)
        ->and($events[0]->creditNote)->not->toBeNull();

    $descriptor = BillingAuditMap::all()[GrantExtrasPurchaseRefunded::class];
    expect(\Illuminate\Support\Facades\DB::table('activity_log')
        ->where('event', $descriptor->key)
        ->where('subject_id', $billable->getKey())
        ->count())->toBe(1);
});
