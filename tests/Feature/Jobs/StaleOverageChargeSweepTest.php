<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\CleanupStalePendingOverageChargeJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Mollie\Laravel\Facades\Mollie;

/**
 * `usage_overage_status = pending` with a `payment_id` was the one overage state nothing
 * owned.
 *
 * Pass 3 of `PrepareUsageOverageJob` skips a snapshot that carries a payment id — "its
 * outcome belongs to the webhook" — and `safeCharge()` skips a billable whose status is
 * `pending`. Both are right while a payment is in flight, and both had no escape hatch: a
 * lost webhook or an exhausted retry chain left the snapshot armed forever, so the charge
 * was never collected, every renewal carried the negative balance into the new period
 * (costing the customer that much quota for good), and every *future* overage for that
 * billable was silenced.
 */
beforeEach(function (): void {
    Notification::fake();
    MollieBilling::notifyAdminUsing(fn (): array => [new AnonymousNotifiable]);

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

function stalledOverageBillable(int $ageHours, string $paymentId = 'tr_stalled'): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Stalled Co',
        'email' => uniqid('stalled-').'@x.test',
        'billing_country' => 'DE',
    ]);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(10),
        'mollie_customer_id' => 'cst_stalled',
        'mollie_mandate_id' => 'mdt_stalled',
        'subscription_meta' => [
            'mollie_subscription_id' => 'sub_stalled',
            'usage_overage_status' => ChargeUsageOverageDirectly::STATUS_PENDING,
            ChargeUsageOverageDirectly::META_KEY => [
                'payment_id' => $paymentId,
                'line_items' => [['kind' => 'overage', 'code' => 'Tokens', 'quantity' => 37, 'amount_net' => 370]],
                'amount_net' => 370,
                'amount_gross' => 440,
                'vat_amount' => 70,
                'vat_rate' => 19.0,
                'currency' => 'EUR',
                'created_at' => BillingTime::nowUtc()->subHours($ageHours)->toIso8601String(),
            ],
            ChargeUsageOverageDirectly::COUNTED_PAYMENTS_KEY => [$paymentId],
        ],
    ])->save();

    return $billable->refresh();
}

it('leaves a charge that is still young alone', function (): void {
    $billable = stalledOverageBillable(ageHours: 2);

    // Mollie's own webhook retries span hours and a SEPA debit legitimately takes days —
    // polling early would just hammer the API and pre-empt a delivery that is coming.
    Mollie::shouldReceive('send')->never();

    app(CleanupStalePendingOverageChargeJob::class)->handle();

    expect($billable->fresh()->getBillingSubscriptionMeta()['usage_overage_status'])
        ->toBe(ChargeUsageOverageDirectly::STATUS_PENDING);
});

it('writes off a charge Mollie reports as failed, so the next cycle is free', function (): void {
    $billable = stalledOverageBillable(ageHours: 100);

    Mollie::shouldReceive('send')->once()->andReturn((object) ['id' => 'tr_stalled', 'status' => 'failed']);

    app(CleanupStalePendingOverageChargeJob::class)->handle();

    // `failed` is the status the next cycle recognises as spent — `persistPendingCharge()`
    // then starts a fresh dunning cycle instead of inheriting this one's counted payments.
    expect($billable->fresh()->getBillingSubscriptionMeta()['usage_overage_status'])
        ->toBe(ChargeUsageOverageDirectly::STATUS_FAILED);

    // The debt is still on the wallet, so somebody has to know it was never collected.
    Notification::assertSentTo(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class);
});

it('writes off a charge still sitting at open past the hard limit', function (): void {
    $billable = stalledOverageBillable(ageHours: 24 * 20);

    // A payment stuck in `open` for three weeks is not going to settle, and leaving the
    // snapshot armed costs the customer more than a fresh attempt does.
    Mollie::shouldReceive('send')->once()->andReturn((object) ['id' => 'tr_stalled', 'status' => 'open']);

    app(CleanupStalePendingOverageChargeJob::class)->handle();

    expect($billable->fresh()->getBillingSubscriptionMeta()['usage_overage_status'])
        ->toBe(ChargeUsageOverageDirectly::STATUS_FAILED);
});

it('keeps waiting on a charge that is open but not yet past the hard limit', function (): void {
    $billable = stalledOverageBillable(ageHours: 96);

    Mollie::shouldReceive('send')->once()->andReturn((object) ['id' => 'tr_stalled', 'status' => 'open']);

    app(CleanupStalePendingOverageChargeJob::class)->handle();

    expect($billable->fresh()->getBillingSubscriptionMeta()['usage_overage_status'])
        ->toBe(ChargeUsageOverageDirectly::STATUS_PENDING);
});

it('clears a snapshot whose payment was in fact invoiced', function (): void {
    $billable = stalledOverageBillable(ageHours: 100);

    // The webhook ran and only the snapshot was left behind — nothing to poll, nothing to
    // write off, and the dunning budget must not carry this spent cycle forward.
    BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_stalled',
        'invoice_kind' => InvoiceKind::Overage,
        'status' => InvoiceStatus::Paid,
        'country' => 'DE',
        'currency' => 'EUR',
        'amount_net' => 370,
        'amount_vat' => 70,
        'amount_gross' => 440,
        'line_items' => [],
    ]);

    Mollie::shouldReceive('send')->never();

    app(CleanupStalePendingOverageChargeJob::class)->handle();

    $meta = $billable->fresh()->getBillingSubscriptionMeta();

    expect($meta)->not->toHaveKey('usage_overage_status')
        ->and($meta)->not->toHaveKey(ChargeUsageOverageDirectly::META_KEY)
        ->and($meta)->not->toHaveKey(ChargeUsageOverageDirectly::COUNTED_PAYMENTS_KEY);
});

it('leaves the snapshot alone when Mollie cannot be reached', function (): void {
    $billable = stalledOverageBillable(ageHours: 100);

    // Unreachable is not a verdict — tomorrow's pass asks again.
    Mollie::shouldReceive('send')->once()->andThrow(new RuntimeException('connection refused'));

    app(CleanupStalePendingOverageChargeJob::class)->handle();

    expect($billable->fresh()->getBillingSubscriptionMeta()['usage_overage_status'])
        ->toBe(ChargeUsageOverageDirectly::STATUS_PENDING);

    Notification::assertNothingSent();
});
