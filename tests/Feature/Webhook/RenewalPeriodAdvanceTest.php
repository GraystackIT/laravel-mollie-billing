<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Storage;

/**
 * The billing period has to WALK FORWARD on every renewal.
 *
 * `nextBillingDate()` is `subscription_period_starts_at` plus one interval, and
 * everything downstream keys off it: `PrepareUsageOverageJob`'s overage pass fires
 * the day before it, the dashboard shows it as "next billing", and the prorata
 * factor of a mid-period plan change is measured against it. A renewal that
 * booked the invoice but left the period where it was would therefore keep
 * charging overage for a period the customer already paid for, show a date in the
 * past forever, and price every plan change off a stale window.
 *
 * The anchor comes from the payment, not from `now()`: a webhook can arrive
 * minutes or hours late. It is the charge's `createdAt` — the cycle Mollie created it
 * for — and `paidAt` only where a payment carries no `createdAt`, as the stubs here do.
 * For a card the two are the same minute; for SEPA they are days apart, see
 * RenewalLongRunSepaTest.
 */
class RenewalFakeWebhookController extends MollieWebhookController
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

    RenewalFakeWebhookController::$nextPayment = null;
    $this->app->bind(MollieWebhookController::class, RenewalFakeWebhookController::class);

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
                'included_usages' => ['Tokens' => 100, 'SMS' => 50],
                'usage_overage_prices' => ['Tokens' => 10, 'SMS' => 15],
            ],
            'yearly' => [
                'base_price_net' => 10000,
                'seat_price_net' => null,
                'included_usages' => ['Tokens' => 1200, 'SMS' => 600],
                'usage_overage_prices' => ['Tokens' => 10, 'SMS' => 15],
            ],
        ],
    ]);
    config()->set('mollie-billing-plans.usage_types', [
        'Tokens' => ['rollover' => true],
        'SMS' => ['rollover' => false],
    ]);
});

function renewalBillable(string $interval = 'monthly', ?string $periodStart = null): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Renewal Co',
        'email' => uniqid('renewal-').'@x.test',
        'billing_country' => 'DE',
    ]);

    $b->forceFill([
        'mollie_customer_id' => 'cst_renew',
        'mollie_mandate_id' => 'mdt_renew',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => $interval === 'yearly' ? SubscriptionInterval::Yearly : SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => $periodStart !== null
            ? BillingTime::toUtc($periodStart)
            : BillingTime::nowUtc()->subMonth(),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_renew'],
    ])->save();

    return $b->refresh();
}

/** A recurring payment Mollie generated for the subscription, settled at $paidAt. */
function deliverRenewal(TestBillable $billable, string $paymentId, string $paidAt, string $gross = '11.90'): void
{
    RenewalFakeWebhookController::$nextPayment = (object) [
        'id' => $paymentId,
        'status' => 'paid',
        'amount' => (object) ['value' => $gross, 'currency' => 'EUR'],
        'paidAt' => $paidAt,
        'subscriptionId' => 'sub_renew',
        'countryCode' => 'DE',
        'metadata' => [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];

    test()->postJson(route('billing.webhook'), ['id' => $paymentId])->assertStatus(200);
}

it('advances the billing period to the moment the money moved', function (): void {
    $billable = renewalBillable('monthly', '2026-01-01T09:00:00Z');

    expect($billable->nextBillingDate()->toIso8601String())->toBe('2026-02-01T09:00:00+00:00');

    deliverRenewal($billable, 'tr_renew_1', '2026-02-01T09:04:12Z');

    $billable->refresh();

    // Anchored on paidAt, not on now(): a late webhook must not shorten or
    // lengthen the period the customer paid for.
    expect($billable->subscription_period_starts_at->toIso8601String())->toBe('2026-02-01T09:04:12+00:00')
        ->and($billable->nextBillingDate()->toIso8601String())->toBe('2026-03-01T09:04:12+00:00');
});

it('walks the period forward across consecutive renewals without drifting', function (): void {
    $billable = renewalBillable('monthly', '2026-01-10T12:00:00Z');

    foreach ([
        ['tr_renew_a', '2026-02-10T12:00:00Z', '2026-03-10T12:00:00+00:00'],
        ['tr_renew_b', '2026-03-10T12:00:00Z', '2026-04-10T12:00:00+00:00'],
        ['tr_renew_c', '2026-04-10T12:00:00Z', '2026-05-10T12:00:00+00:00'],
    ] as [$paymentId, $paidAt, $expectedNext]) {
        deliverRenewal($billable->refresh(), $paymentId, $paidAt);

        expect($billable->refresh()->nextBillingDate()->toIso8601String())->toBe($expectedNext);
    }

    // Three renewals, three invoices — and the period has moved three months, not
    // stayed put or jumped to today.
    expect(BillingInvoice::query()->count())->toBe(3)
        ->and($billable->refresh()->subscription_period_starts_at->toIso8601String())
        ->toBe('2026-04-10T12:00:00+00:00');
});

it('advances a yearly subscription by a year, not a month', function (): void {
    $billable = renewalBillable('yearly', '2026-03-01T08:00:00Z');

    deliverRenewal($billable, 'tr_renew_year', '2027-03-01T08:00:30Z', '119.00');

    expect($billable->refresh()->nextBillingDate()->toIso8601String())
        ->toBe('2028-03-01T08:00:30+00:00');
});

it('recharges rollover quota additively and resets non-rollover quota', function (): void {
    $billable = renewalBillable('monthly', '2026-01-01T09:00:00Z');

    // Tokens roll over, SMS do not. Leave some of each unused.
    $billable->recordBillingUsage('Tokens', 0);
    app(\GraystackIT\MollieBilling\Services\Wallet\WalletUsageService::class)
        ->credit($billable, 'Tokens', 100, 'seed');
    app(\GraystackIT\MollieBilling\Services\Wallet\WalletUsageService::class)
        ->credit($billable, 'SMS', 50, 'seed');
    app(\GraystackIT\MollieBilling\Services\Wallet\WalletUsageService::class)
        ->debit($billable, 'Tokens', 30, 'usage');
    app(\GraystackIT\MollieBilling\Services\Wallet\WalletUsageService::class)
        ->debit($billable, 'SMS', 10, 'usage');

    expect($billable->refresh()->remainingBillingQuota('Tokens'))->toBe(70)
        ->and($billable->remainingBillingQuota('SMS'))->toBe(40);

    deliverRenewal($billable, 'tr_renew_wallets', '2026-02-01T09:00:00Z');

    $billable->refresh();

    // Tokens: 70 survivors + 100 fresh. SMS: reset to the plan's 50.
    expect($billable->remainingBillingQuota('Tokens'))->toBe(170)
        ->and($billable->remainingBillingQuota('SMS'))->toBe(50);
});

it('keeps a negative overage balance when the renewal recharges', function (): void {
    // The customer overspent; the overage is charged separately. The renewal must
    // not paper over the debt by resetting the wallet to the plan quota.
    $billable = renewalBillable('monthly', '2026-01-01T09:00:00Z');

    app(\GraystackIT\MollieBilling\Services\Wallet\WalletUsageService::class)
        ->credit($billable, 'Tokens', 100, 'seed');
    app(\GraystackIT\MollieBilling\Services\Wallet\WalletUsageService::class)
        ->debit($billable, 'Tokens', 130, 'overuse');

    expect((int) $billable->refresh()->getWallet('Tokens')->balanceInt)->toBe(-30);

    deliverRenewal($billable, 'tr_renew_overage', '2026-02-01T09:00:00Z');

    // −30 + 100 = 70: the debt is carried, not forgiven.
    expect((int) $billable->refresh()->getWallet('Tokens')->balanceInt)->toBe(70);
});

it('converts a trial on the first real charge and starts the paid period there', function (): void {
    $billable = renewalBillable('monthly', '2026-01-01T09:00:00Z');
    $billable->forceFill([
        'subscription_status' => SubscriptionStatus::Trial,
        'trial_ends_at' => BillingTime::toUtc('2026-01-15T09:00:00Z'),
    ])->save();

    deliverRenewal($billable->refresh(), 'tr_trial_convert', '2026-01-15T09:02:00Z');

    $billable->refresh();

    // The paid period starts when the trial's first charge settled, so the next
    // billing date is a month after that — not a month after the trial started.
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->trial_ends_at)->toBeNull()
        ->and($billable->subscription_period_starts_at->toIso8601String())->toBe('2026-01-15T09:02:00+00:00')
        ->and($billable->nextBillingDate()->toIso8601String())->toBe('2026-02-15T09:02:00+00:00');
});

it('recovers a past-due billable and restarts its period on the successful charge', function (): void {
    $billable = renewalBillable('monthly', '2026-01-01T09:00:00Z');
    $billable->forceFill([
        'subscription_status' => SubscriptionStatus::PastDue,
        'subscription_meta' => [
            'mollie_subscription_id' => 'sub_renew',
            'past_due_since' => '2026-02-01T09:00:00Z',
            'payment_failure' => ['payment_id' => 'tr_failed'],
        ],
    ])->save();

    deliverRenewal($billable->refresh(), 'tr_renew_recovered', '2026-02-04T16:30:00Z');

    $billable->refresh();
    $meta = $billable->getBillingSubscriptionMeta();

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($meta)->not->toHaveKey('past_due_since')
        ->and($meta)->not->toHaveKey('payment_failure')
        // The retry's date anchors the new period, so the customer is not billed
        // again three days later for the days the dunning cost them.
        ->and($billable->nextBillingDate()->toIso8601String())->toBe('2026-03-04T16:30:00+00:00');
});

it('does not advance the period twice when Mollie redelivers the same renewal', function (): void {
    $billable = renewalBillable('monthly', '2026-01-01T09:00:00Z');

    deliverRenewal($billable, 'tr_renew_dupe', '2026-02-01T09:00:00Z');
    $afterFirst = $billable->refresh()->subscription_period_starts_at->toIso8601String();

    // Same payment id again — the invoice guard has to swallow it.
    deliverRenewal($billable->refresh(), 'tr_renew_dupe', '2026-02-01T09:00:00Z');

    expect($billable->refresh()->subscription_period_starts_at->toIso8601String())->toBe($afterFirst)
        ->and(BillingInvoice::query()->count())->toBe(1);
});
