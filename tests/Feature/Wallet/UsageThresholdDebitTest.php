<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Notifications\UsageThresholdNotification;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    config()->set('mollie-billing.usage_threshold_percent', 80);
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
                'usage_overage_prices' => ['Tokens' => 5],
            ],
            'yearly' => [
                'base_price_net' => 10000,
                'seat_price_net' => null,
                'included_usages' => ['Tokens' => 1200],
                'usage_overage_prices' => ['Tokens' => 5],
            ],
        ],
    ]);
});

afterEach(function (): void {
    // The resolver is a static on the facade root and is not reset between
    // tests. Two of the tests below install one that throws, which would
    // otherwise break every later test file that notifies billing admins.
    MollieBilling::notifyBillingAdminsUsing(fn (): array => []);
});

function thresholdBillable(): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create(['name' => 'Acme', 'email' => 'a@b.test']);
    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(2),
        'billing_country' => 'AT',
        'mollie_mandate_id' => 'mdt_test',
        'mollie_customer_id' => 'cst_test',
        'allows_billing_overage' => true,
    ])->save();

    return $b->refresh();
}

it('notifies once when usage crosses the threshold', function (): void {
    Notification::fake();
    MollieBilling::notifyBillingAdminsUsing(fn ($billable): array => [$billable]);

    $billable = thresholdBillable();
    $service = app(WalletUsageService::class);
    $service->credit($billable, 'Tokens', 100);

    $service->debit($billable, 'Tokens', 85);

    Notification::assertSentTimes(UsageThresholdNotification::class, 1);
    expect((int) $billable->getWallet('Tokens')->refresh()->balanceInt)->toBe(15);

    // The marker is claimed inside the transaction, so a second debit is silent.
    $service->debit($billable, 'Tokens', 5);
    Notification::assertSentTimes(UsageThresholdNotification::class, 1);
});

it('records usage even when the threshold notification blows up', function (): void {
    $billable = thresholdBillable();
    $service = app(WalletUsageService::class);
    $service->credit($billable, 'Tokens', 100);

    // A dead mail transport used to abort debit()'s transaction and roll the
    // forceWithdraw back: usage was silently not recorded because an email failed.
    MollieBilling::notifyBillingAdminsUsing(function (): array {
        throw new RuntimeException('SMTP is down');
    });

    $service->debit($billable, 'Tokens', 85);

    expect((int) $billable->getWallet('Tokens')->refresh()->balanceInt)->toBe(15);
});

it('keeps the threshold marker after a failed send so debits stop retrying it', function (): void {
    $billable = thresholdBillable();
    $service = app(WalletUsageService::class);
    $service->credit($billable, 'Tokens', 100);

    $attempts = 0;
    MollieBilling::notifyBillingAdminsUsing(function () use (&$attempts): array {
        $attempts++;
        throw new RuntimeException('SMTP is down');
    });

    $service->debit($billable, 'Tokens', 85);
    $service->debit($billable, 'Tokens', 5);
    $service->debit($billable, 'Tokens', 5);

    // One attempt in total: the marker survived the failure, so the next debits
    // above the threshold do not keep hammering a broken transport.
    expect($attempts)->toBe(1);
    expect((int) $billable->getWallet('Tokens')->refresh()->balanceInt)->toBe(5);
});

it('does not notify below the threshold', function (): void {
    Notification::fake();

    $billable = thresholdBillable();
    $service = app(WalletUsageService::class);
    $service->credit($billable, 'Tokens', 100);

    $service->debit($billable, 'Tokens', 50);

    Notification::assertNothingSent();
});

it('still notifies only once when the period anchor already points at the next period', function (): void {
    // The nightly pass runs the evening before a renewal: it refills the wallets and moves
    // `subscription_period_starts_at` to tomorrow 00:00 — a deliberately future anchor. The
    // dedupe compared "notified at" against that anchor, and a timestamp from today is
    // never >= tomorrow, so the marker never counted as set: every single debit above the
    // threshold re-sent the mail, for the rest of the day. A busy metered day meant
    // hundreds of identical warnings.
    Notification::fake();
    MollieBilling::notifyBillingAdminsUsing(fn ($billable): array => [$billable]);

    $billable = thresholdBillable();
    $billable->forceFill([
        'subscription_period_starts_at' => now()->addDay()->startOfDay(),
    ])->save();

    $service = app(WalletUsageService::class);
    $service->credit($billable->refresh(), 'Tokens', 100);

    $service->debit($billable, 'Tokens', 85);
    $service->debit($billable, 'Tokens', 5);
    $service->debit($billable, 'Tokens', 5);

    Notification::assertSentTimes(UsageThresholdNotification::class, 1);
});
