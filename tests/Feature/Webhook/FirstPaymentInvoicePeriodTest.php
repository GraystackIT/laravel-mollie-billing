<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Storage;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * The first invoice of a subscription has to carry the period its payment bought.
 *
 * The first-payment invoice (and the Local→Mollie upgrade invoice) is written BEFORE
 * CreateSubscription sets plan, interval and anchor on the billable. Left to read the
 * period off the billable, createForPayment() stamped the lines with whatever was there
 * before — nothing ("now + 1 month", also for a yearly plan), the Local period being
 * upgraded out of, or the anchor of a subscription that expired months ago.
 * `BillingInvoice::currentPeriodLines()` then could not find the invoice that paid for
 * the running period, and a mid-period downgrade charged the new plan in full while
 * crediting nothing.
 */
class FirstPeriodFakeWebhookController extends MollieWebhookController
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

    FirstPeriodFakeWebhookController::$nextPayment = null;
    $this->app->bind(MollieWebhookController::class, FirstPeriodFakeWebhookController::class);

    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free',
        'tier' => 1,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []]],
    ]);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => []],
            'yearly' => ['base_price_net' => 10000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);

    Mollie::shouldReceive('send')->andReturnUsing(function ($request) {
        if ($request instanceof CreateSubscriptionRequest) {
            return (object) ['id' => 'sub_created'];
        }

        return (object) ['id' => 'unexpected'];
    });
});

it('stamps a yearly first-payment invoice with the yearly period it paid for', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Yearly Co',
        'email' => uniqid('yr-').'@x.test',
        'billing_country' => 'DE',
    ]);
    $billable->forceFill(['mollie_customer_id' => 'cst_yr'])->save();

    // 100.00 net yearly + 19 % = 119.00.
    FirstPeriodFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_first_yearly',
        'status' => 'paid',
        'amount' => (object) ['value' => '119.00', 'currency' => 'EUR'],
        'subscriptionId' => null,
        'customerId' => 'cst_yr',
        'mandateId' => 'mdt_yr',
        'countryCode' => 'DE',
        'metadata' => [
            'type' => 'subscription',
            'plan_code' => 'pro',
            'interval' => 'yearly',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_first_yearly'])->assertStatus(200);

    $billable = $billable->fresh();
    $invoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_first_yearly')->sole();

    expect($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and($billable->subscription_interval)->toBe(SubscriptionInterval::Yearly);

    // The refund machinery must be able to find the line that paid for the running period —
    // otherwise a mid-period downgrade composes its credit against nothing.
    expect(BillingInvoice::currentPeriodLines($billable, 'plan', 'pro'))->toHaveCount(1);

    // The customer paid for a year; the document must say so.
    expect(abs($invoice->period_end->diffInDays($billable->nextBillingDate(), false)))->toBeLessThanOrEqual(1);
});

it('stamps a local-to-Mollie upgrade invoice with the new paid period, not the old local one', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Upgrade Co',
        'email' => uniqid('up-').'@x.test',
        'billing_country' => 'DE',
    ]);
    $billable->forceFill([
        'mollie_customer_id' => 'cst_up',
        'mollie_mandate_id' => 'mdt_up',
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'free',
        'subscription_interval' => SubscriptionInterval::Monthly,
        // The free plan has been running for ten days when the customer upgrades.
        'subscription_period_starts_at' => now()->subDays(10),
        'subscription_meta' => ['seat_count' => 1],
    ])->save();

    FirstPeriodFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_upgrade_period',
        'status' => 'paid',
        'amount' => (object) ['value' => '11.90', 'currency' => 'EUR'],
        'paidAt' => now()->toIso8601String(),
        'subscriptionId' => null,
        'customerId' => 'cst_up',
        'mandateId' => 'mdt_up',
        'countryCode' => 'DE',
        'metadata' => [
            'type' => 'subscription',
            'upgrade_from_local' => true,
            'plan_code' => 'pro',
            'interval' => 'monthly',
            'addon_codes' => [],
            'extra_seats' => 0,
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_upgrade_period'])->assertStatus(200);

    $billable = $billable->fresh();
    $invoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_upgrade_period')->sole();

    expect($billable->getBillingSubscriptionSource())->toBe(SubscriptionSource::Mollie->value);

    expect(BillingInvoice::currentPeriodLines($billable, 'plan', 'pro'))->toHaveCount(1);
    expect(abs($invoice->period_end->diffInDays($billable->nextBillingDate(), false)))->toBeLessThanOrEqual(1);
});

it('stamps a re-checkout after expiry with the new period, not the expired anchor', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Returning Co',
        'email' => uniqid('ret-').'@x.test',
        'billing_country' => 'DE',
    ]);
    // What the expiry pass leaves behind: plan and interval wiped, the old anchor kept.
    $billable->forceFill([
        'mollie_customer_id' => 'cst_ret',
        'subscription_source' => SubscriptionSource::None,
        'subscription_status' => SubscriptionStatus::Expired,
        'subscription_plan_code' => null,
        'subscription_interval' => null,
        'subscription_period_starts_at' => now()->subMonths(3),
    ])->save();

    FirstPeriodFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_first_returning',
        'status' => 'paid',
        'amount' => (object) ['value' => '11.90', 'currency' => 'EUR'],
        'subscriptionId' => null,
        'customerId' => 'cst_ret',
        'mandateId' => 'mdt_ret',
        'countryCode' => 'DE',
        'metadata' => [
            'type' => 'subscription',
            'plan_code' => 'pro',
            'interval' => 'monthly',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_first_returning'])->assertStatus(200);

    $billable = $billable->fresh();
    $invoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_first_returning')->sole();

    expect($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active);

    // The prorata factor of a later change is measured over the line's own window. With
    // the expired anchor as its start the window is four months long and a downgrade on
    // day one credits a quarter of the plan instead of all of it.
    $line = $invoice->line_items[0];
    expect(abs(\Carbon\Carbon::parse($line['period_start'])->diffInDays($billable->getBillingPeriodStartsAt(), false)))->toBeLessThanOrEqual(1)
        ->and(abs($invoice->period_start->diffInDays($billable->getBillingPeriodStartsAt(), false)))->toBeLessThanOrEqual(1);
});
