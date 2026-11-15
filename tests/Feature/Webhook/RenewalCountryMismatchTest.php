<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\RenewalCollectedForEndedSubscription;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * A country mismatch opened BY a renewal must not swallow that renewal.
 *
 * `CountryMatchService::check()` is not a read: on a mismatch it calls `flag()`, which
 * cancels the subscription at period end and writes status Cancelled straight away. Run
 * before the booking — as it was — the very next guard in `SubscriptionPaymentHandler::paid()`
 * (`isBillableState()`) then saw a dead subscription and returned through
 * `reportRenewalForEndedSubscription()`: an admin alert claiming Mollie had collected for a
 * subscription that no longer runs, no wallet recharge, no period advance, and a customer
 * who had just paid locked out for the period they bought.
 *
 * It needs no exotic state — a customer who declared AT and pays with a DE card from a DE
 * IP is exactly the drift the mismatch flow exists for.
 */
class MismatchRenewalFakeWebhookController extends MollieWebhookController
{
    public static ?object $nextPayment = null;

    protected function fetchPayment(string $paymentId): object
    {
        return self::$nextPayment ?? throw new RuntimeException('No payment stub configured.');
    }
}

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
    config()->set('mollie-billing.invoices.disk', 'local');

    MismatchRenewalFakeWebhookController::$nextPayment = null;
    $this->app->bind(MollieWebhookController::class, MismatchRenewalFakeWebhookController::class);

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
                'included_usages' => ['SMS' => 50],
                'usage_overage_prices' => ['SMS' => 15],
            ],
        ],
    ]);
    config()->set('mollie-billing-plans.usage_types', ['SMS' => ['rollover' => false]]);
});

function mismatchRenewalBillable(): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Drift Co',
        'email' => uniqid('drift-').'@x.test',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
        'tax_country_ip' => 'DE',
    ]);

    $b->forceFill([
        'mollie_customer_id' => 'cst_drift',
        'mollie_mandate_id' => 'mdt_drift',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::toUtc('2026-01-01T09:00:00Z'),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_drift'],
    ])->save();

    // Spent most of the period's quota, so the recharge is observable. The quota has to
    // be on the wallet first: debited from an empty one, these 40 units are overage, and
    // a renewal collects uncharged overage rather than resetting it away.
    $b->refresh()->creditBillingUsage('SMS', 50);
    $b->refresh()->recordBillingUsage('SMS', 40);

    return $b->refresh();
}

function deliverMismatchRenewal(TestBillable $billable): void
{
    MismatchRenewalFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_drift_1',
        'status' => 'paid',
        'amount' => (object) ['value' => '12.00', 'currency' => 'EUR'],
        'paidAt' => '2026-02-01T09:00:00Z',
        'subscriptionId' => 'sub_drift',
        // The card's country, which disagrees with the declared one.
        'countryCode' => 'DE',
        'metadata' => [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];

    test()->postJson(route('billing.webhook'), ['id' => 'tr_drift_1'])->assertStatus(200);
}

it('books the renewal in full and flags the mismatch afterwards', function (): void {
    Event::fake([RenewalCollectedForEndedSubscription::class]);

    $billable = mismatchRenewalBillable();

    deliverMismatchRenewal($billable);

    $billable->refresh();

    // The renewal is booked: invoice, wallet recharge, period advance.
    expect(BillingInvoice::query()->where('mollie_payment_id', 'tr_drift_1')->count())->toBe(1)
        ->and($billable->remainingBillingQuota('SMS'))->toBe(50)
        ->and($billable->subscription_period_starts_at->toIso8601String())->toBe('2026-02-01T09:00:00+00:00');

    // And the mismatch is opened — the check still runs, just last.
    expect(BillingCountryMismatch::query()->where('status', CountryMismatchStatus::Pending)->count())->toBe(1)
        ->and($billable->tax_country_payment)->toBe('DE');

    // Not the "money for a dead subscription" alarm: this renewal was perfectly ordinary.
    Event::assertNotDispatched(RenewalCollectedForEndedSubscription::class);
});

it('leaves the subscription cancelled at period end, as flagging a mismatch should', function (): void {
    $billable = mismatchRenewalBillable();

    deliverMismatchRenewal($billable);

    $billable->refresh();

    // `flag()` cancels at period end — that part is intended and unchanged. What matters
    // is that it happens to a period the customer has actually been given.
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Cancelled)
        ->and($billable->subscription_ends_at)->not->toBeNull()
        ->and($billable->subscription_ends_at->greaterThan($billable->subscription_period_starts_at))->toBeTrue();
});
