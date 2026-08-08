<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\RenewalCollectedForEndedSubscription;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Mollie can keep charging a subscription whose cancellation never landed.
 *
 * `CancelSubscription` writes the Cancelled status even when `cancelAtMollie()` fails — it
 * only leaves a `pending_subscription_cancel` marker — and `RetrySubscriptionCancelJob`
 * gives up after 24 hours with a single admin mail. From then on the recurring webhook
 * booked every period as an ordinary renewal: period anchor advanced, every wallet reset
 * and recharged, "your invoice is ready" mailed to a customer who cancelled and has no
 * access. Silently, forever. `failed()` in the same class has refused non-billable states
 * all along; `paid()` — the side the money arrives on — had nothing.
 */
class EndedRenewalFakeWebhookController extends MollieWebhookController
{
    public static ?object $nextPayment = null;

    protected function fetchPayment(string $paymentId): object
    {
        return self::$nextPayment ?? throw new RuntimeException('No payment stub configured.');
    }
}

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');

    EndedRenewalFakeWebhookController::$nextPayment = null;
    app()->bind(MollieWebhookController::class, EndedRenewalFakeWebhookController::class);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 1000,
                'seat_price_net' => null,
                'included_usages' => ['Tokens' => 100],
                'usage_overage_prices' => ['Tokens' => 10],
            ],
        ],
    ]);
    config()->set('mollie-billing-plans.usage_types', ['Tokens' => ['rollover' => false]]);
});

function endedRenewalBillable(SubscriptionStatus $status): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Ended Co',
        'email' => uniqid('ended-').'@x.test',
        'billing_country' => 'DE',
    ]);

    $billable->forceFill([
        'mollie_customer_id' => 'cst_ended',
        'mollie_mandate_id' => 'mdt_ended',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => $status,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::toUtc('2026-01-01T09:00:00Z'),
        'subscription_ends_at' => BillingTime::toUtc('2026-02-01T09:00:00Z'),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_ended'],
    ])->save();

    return $billable->refresh();
}

function deliverEndedRenewal(TestBillable $billable, string $paymentId = 'tr_ended'): void
{
    EndedRenewalFakeWebhookController::$nextPayment = (object) [
        'id' => $paymentId,
        'status' => 'paid',
        'amount' => (object) ['value' => '11.90', 'currency' => 'EUR'],
        'paidAt' => '2026-03-01T09:00:00Z',
        'subscriptionId' => 'sub_ended',
        'countryCode' => 'DE',
        'metadata' => [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];

    test()->postJson(route('billing.webhook'), ['id' => $paymentId])->assertStatus(200);
}

it('does not treat a charge on a cancelled subscription as a renewal', function (): void {
    Event::fake([RenewalCollectedForEndedSubscription::class]);
    \GraystackIT\MollieBilling\MollieBilling::notifyAdminUsing(fn (): array => [new AnonymousNotifiable]);
    Notification::fake();

    $billable = endedRenewalBillable(SubscriptionStatus::Cancelled);
    $billable->creditBillingUsage('Tokens', 100);
    $billable->recordBillingUsage('Tokens', 90);

    deliverEndedRenewal($billable);

    $fresh = $billable->fresh();

    // Nothing that would treat this as a live subscription: the period stays where it was,
    // the status stays Cancelled, and the wallet is not recharged.
    expect($fresh->subscription_period_starts_at->toIso8601String())->toBe('2026-01-01T09:00:00+00:00')
        ->and($fresh->subscription_status)->toBe(SubscriptionStatus::Cancelled)
        ->and($fresh->getWallet('Tokens')->balanceInt)->toBe(10);

    // The invoice is kept on purpose: the money arrived, the customer is entitled to a
    // document, and an operator needs something to refund against.
    expect(BillingInvoice::query()->where('mollie_payment_id', 'tr_ended')->where('invoice_kind', InvoiceKind::Subscription)->count())->toBe(1);

    Event::assertDispatched(RenewalCollectedForEndedSubscription::class);
    Notification::assertSentTo(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class);
});

it('does not treat a charge on an expired subscription as a renewal', function (): void {
    $billable = endedRenewalBillable(SubscriptionStatus::Expired);

    deliverEndedRenewal($billable, 'tr_ended_expired');

    expect($billable->fresh()->subscription_period_starts_at->toIso8601String())->toBe('2026-01-01T09:00:00+00:00')
        ->and($billable->fresh()->subscription_status)->toBe(SubscriptionStatus::Expired);
});

it('still books a genuine renewal for a running subscription', function (): void {
    // The guard must not touch the path that carries every legitimate renewal.
    $billable = endedRenewalBillable(SubscriptionStatus::Active);
    $billable->forceFill(['subscription_ends_at' => null])->save();

    deliverEndedRenewal($billable->refresh(), 'tr_running');

    expect($billable->fresh()->subscription_period_starts_at->toIso8601String())->toBe('2026-03-01T09:00:00+00:00');
});
