<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\InvoiceCreated;
use GraystackIT\MollieBilling\Events\SubscriptionActivationFailed;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingProcessedWebhook;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mollie\Laravel\Facades\Mollie;

/**
 * A webhook delivery that cannot finish must either retry or shout. Several paths
 * did neither: they answered 200 and forgot, which tells Mollie the payment was
 * handled and it never calls again.
 */
class DurabilityFakeWebhookController extends MollieWebhookController
{
    /** @var array<string, object> */
    public static array $payments = [];

    protected function fetchPayment(string $paymentId): object
    {
        return self::$payments[$paymentId] ?? throw new RuntimeException("No stub for {$paymentId}.");
    }
}

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');

    DurabilityFakeWebhookController::$payments = [];
    $this->app->bind(MollieWebhookController::class, DurabilityFakeWebhookController::class);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 2000, 'seat_price_net' => null, 'included_usages' => []]],
    ]);
});

function durabilityBillable(array $overrides = []): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Durable Co',
        'email' => uniqid('durable-').'@x.test',
        'billing_country' => 'DE',
    ]);

    $b->forceFill(array_merge(['mollie_customer_id' => 'cst_dur'], $overrides))->save();

    return $b->refresh();
}

function durabilityFirstPayment(TestBillable $billable, string $paymentId = 'tr_dur'): object
{
    return (object) [
        'id' => $paymentId,
        'status' => 'paid',
        'amount' => (object) ['value' => '23.80', 'currency' => 'EUR'],
        'paidAt' => BillingTime::nowUtc()->toIso8601String(),
        'subscriptionId' => null,
        'customerId' => 'cst_dur',
        'mandateId' => 'mdt_dur',
        'countryCode' => 'DE',
        'metadata' => [
            'type' => 'subscription',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'plan_code' => 'pro',
            'interval' => 'monthly',
            'addon_codes' => [],
            'extra_seats' => 0,
        ],
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// 4a — a leaked reservation must not absorb every later delivery
// ─────────────────────────────────────────────────────────────────────────────

it('takes over a reservation abandoned by a process that died', function (): void {
    $billable = durabilityBillable();
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_dur']);

    // What a hard death leaves behind: the pending row, with no processed_at.
    BillingProcessedWebhook::create([
        'mollie_payment_id' => 'tr_dur',
        'event_signature' => BillingProcessedWebhook::pendingSignature('tr_dur'),
        'received_at' => BillingTime::nowUtc()->subMinutes(MollieWebhookController::RESERVATION_TTL_MINUTES + 5),
    ]);

    DurabilityFakeWebhookController::$payments['tr_dur'] = durabilityFirstPayment($billable);

    $this->postJson(route('billing.webhook'), ['id' => 'tr_dur'])->assertStatus(200);

    // Without the takeover this delivery was answered 200 with nothing done, and
    // Mollie stopped calling: money collected, no subscription, no invoice — for the
    // 180 days until the prune job removed the row.
    $billable->refresh();
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and(BillingInvoice::query()->where('mollie_payment_id', 'tr_dur')->exists())->toBeTrue();
});

it('defers with 503 while a reservation is genuinely in flight', function (): void {
    $billable = durabilityBillable();

    BillingProcessedWebhook::create([
        'mollie_payment_id' => 'tr_dur',
        'event_signature' => BillingProcessedWebhook::pendingSignature('tr_dur'),
        'received_at' => BillingTime::nowUtc(),
    ]);

    DurabilityFakeWebhookController::$payments['tr_dur'] = durabilityFirstPayment($billable);

    // 503, not 200: this delivery may carry a refund the holder cannot see, and 200
    // would tell Mollie it was handled.
    $this->postJson(route('billing.webhook'), ['id' => 'tr_dur'])->assertStatus(503);

    expect($billable->refresh()->subscription_status)->not->toBe(SubscriptionStatus::Active);
});

// ─────────────────────────────────────────────────────────────────────────────
// 4b — an invoice without a finished activation is not "nothing left to do"
// ─────────────────────────────────────────────────────────────────────────────

it('reports a re-delivery that finds an invoice but no subscription', function (): void {
    Notification::fake();
    Event::fake([SubscriptionActivationFailed::class]);

    $billable = durabilityBillable();
    MollieBilling::notifyAdminUsing(fn (): array => [$billable]);

    // The state a crash between the invoice commit and CreateSubscription leaves:
    // a paid invoice for this payment, a mandate, and status New.
    BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_dur',
        'invoice_kind' => GraystackIT\MollieBilling\Enums\InvoiceKind::Subscription,
        'status' => GraystackIT\MollieBilling\Enums\InvoiceStatus::Paid,
        'country' => 'DE',
        'currency' => 'EUR',
        'amount_net' => 2000,
        'amount_vat' => 380,
        'amount_gross' => 2380,
        'line_items' => [],
    ]);
    $billable->forceFill(['mollie_mandate_id' => 'mdt_dur'])->save();

    DurabilityFakeWebhookController::$payments['tr_dur'] = durabilityFirstPayment($billable);

    $this->postJson(route('billing.webhook'), ['id' => 'tr_dur'])->assertStatus(200);

    // This used to be a Log::info and nothing else — a paying customer with no
    // subscription, and CleanupOrphanedBillablesJob refuses to touch a billable that
    // holds an invoice, so nothing ever reconciled it.
    Event::assertDispatched(SubscriptionActivationFailed::class);
    Notification::assertSentTo($billable, AdminPlanChangeFailedNotification::class);

    MollieBilling::flushCallbacks();
});

it('stays quiet for a re-delivery of a completed activation', function (): void {
    Notification::fake();
    Event::fake([SubscriptionActivationFailed::class]);

    $billable = durabilityBillable();
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_dur']);
    MollieBilling::notifyAdminUsing(fn (): array => [$billable]);

    DurabilityFakeWebhookController::$payments['tr_dur'] = durabilityFirstPayment($billable);
    $this->postJson(route('billing.webhook'), ['id' => 'tr_dur'])->assertStatus(200);

    // A genuine duplicate delivery of a payment whose activation finished: nothing to
    // report, and the noise would train operators to ignore the real alert.
    BillingProcessedWebhook::query()->delete();
    $this->postJson(route('billing.webhook'), ['id' => 'tr_dur'])->assertStatus(200);

    Event::assertNotDispatched(SubscriptionActivationFailed::class);
    Notification::assertNothingSent();

    MollieBilling::flushCallbacks();
});

// ─────────────────────────────────────────────────────────────────────────────
// 4c — a transient failure must not be recorded as processed
// ─────────────────────────────────────────────────────────────────────────────

it('does not record the outcome as processed when the activation throws', function (): void {
    $billable = durabilityBillable();

    // An app listener on the documented extension seam, with a dead dependency.
    Event::listen(InvoiceCreated::class, function (): void {
        throw new RuntimeException('mailer down');
    });

    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_dur']);

    DurabilityFakeWebhookController::$payments['tr_dur'] = durabilityFirstPayment($billable);

    $this->postJson(route('billing.webhook'), ['id' => 'tr_dur'])->assertStatus(500);

    // Nothing may be marked processed, or Mollie's retry would be deduped away and
    // the activation would never happen.
    expect(BillingProcessedWebhook::query()->whereNotNull('processed_at')->count())->toBe(0);
});
