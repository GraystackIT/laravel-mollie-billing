<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\DuplicatePaymentReceived;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingProcessedWebhook;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Services\Webhook\WebhookSupport;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * A first payment's activation runs in two phases: a short gate transaction
 * (row lock + duplicate/invoice guards + durable claim) and the activation
 * itself OUTSIDE any transaction. These tests pin the two invariants the split
 * exists for: a failure after the Mollie CreateSubscription call must never
 * lead to a second Mollie subscription on re-delivery, and a concurrent
 * checkout tab's payment must back off while a fresh claim is held — without a
 * dead claim being able to block activation forever.
 */
class ActivationClaimFakeWebhookController extends MollieWebhookController
{
    public static ?object $nextPayment = null;

    protected function fetchPayment(string $paymentId): object
    {
        if (self::$nextPayment === null) {
            throw new \RuntimeException('No payment stub configured.');
        }

        return self::$nextPayment;
    }
}

beforeEach(function (): void {
    ActivationClaimFakeWebhookController::$nextPayment = null;
    $this->app->bind(MollieWebhookController::class, ActivationClaimFakeWebhookController::class);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => ['Tokens' => 10]],
        ],
    ]);
});

function claimBillable(): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => 'claim@x.test',
        'name' => 'Claim',
        'billing_country' => 'DE',
        'mollie_customer_id' => 'cst_claim',
    ])->save();

    return $b;
}

/**
 * Counts CreateSubscriptionRequest sends — the metric every test here pins.
 */
function mockMollieCountingCreates(int &$creates): void
{
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$creates) {
        if ($request instanceof CreateSubscriptionRequest) {
            $creates++;

            return (object) ['id' => 'sub_claim_'.$creates];
        }

        return (object) ['id' => 'unexpected'];
    });
}

/** @return array<string, mixed> */
function firstPaymentStub(TestBillable $billable, string $paymentId): object
{
    return (object) [
        'id' => $paymentId,
        'status' => 'paid',
        'amount' => (object) ['value' => '11.90', 'currency' => 'EUR'],
        'subscriptionId' => null,
        'customerId' => 'cst_claim',
        'mandateId' => 'mdt_claim',
        'countryCode' => 'DE',
        'metadata' => [
            'type' => 'subscription',
            'plan_code' => 'pro',
            'interval' => 'monthly',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];
}

it('does not create a second Mollie subscription when a failure after the Mollie call forces a redelivery', function (): void {
    $billable = claimBillable();

    $creates = 0;
    mockMollieCountingCreates($creates);

    // The after-checkout hook is an arbitrary app closure and the last
    // activation step — it throws AFTER the Mollie subscription exists.
    $shouldThrow = true;
    MollieBilling::afterCheckoutUsing(function (Billable $b, bool $success) use (&$shouldThrow): void {
        if ($shouldThrow) {
            $shouldThrow = false;
            throw new RuntimeException('app after-checkout hook exploded');
        }
    });

    ActivationClaimFakeWebhookController::$nextPayment = firstPaymentStub($billable, 'tr_claim_crash');

    try {
        $this->postJson(route('billing.webhook'), ['id' => 'tr_claim_crash'])->assertStatus(500);

        // The partial state survives the throw — that is the fix: nothing that
        // already happened at Mollie may be rolled back locally, and the claim
        // is released so the re-delivery can pass the gate.
        $billable->refresh();
        $meta = $billable->getBillingSubscriptionMeta();
        expect($creates)->toBe(1)
            ->and($meta['mollie_subscription_id'] ?? null)->toBe('sub_claim_1')
            ->and($meta)->not->toHaveKey(WebhookSupport::ACTIVATION_CLAIM_META_KEY)
            ->and(BillingInvoice::where('mollie_payment_id', 'tr_claim_crash')->count())->toBe(1);

        Event::fake([DuplicatePaymentReceived::class]);

        // Mollie re-delivers after the 500. The invoice guard must swallow the
        // re-run: no second CreateSubscriptionRequest, no second invoice, and
        // no duplicate-payment handling for the customer's only payment.
        $this->postJson(route('billing.webhook'), ['id' => 'tr_claim_crash'])->assertStatus(200);
    } finally {
        $prop = (new ReflectionClass(MollieBilling::class))->getProperty('afterCheckoutCallback');
        $prop->setValue(null, null);
    }

    $billable->refresh();
    expect($creates)->toBe(1)
        ->and(BillingInvoice::where('mollie_payment_id', 'tr_claim_crash')->count())->toBe(1)
        ->and($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? null)->toBe('sub_claim_1');

    Event::assertNotDispatched(DuplicatePaymentReceived::class);

    // The re-delivery completed the reservation, so a third delivery is deduped upstream.
    expect(
        BillingProcessedWebhook::where('mollie_payment_id', 'tr_claim_crash')->whereNotNull('processed_at')->exists()
    )->toBeTrue();
});

it('defers a first payment while another payment holds a fresh activation claim', function (): void {
    $billable = claimBillable();
    $billable->forceFill([
        'subscription_meta' => [
            WebhookSupport::ACTIVATION_CLAIM_META_KEY => [
                'payment_id' => 'tr_other_tab',
                'claimed_at' => BillingTime::nowUtc()->subMinute()->toIso8601String(),
            ],
        ],
    ])->save();

    $creates = 0;
    mockMollieCountingCreates($creates);

    ActivationClaimFakeWebhookController::$nextPayment = firstPaymentStub($billable, 'tr_claim_blocked');

    // 503, not 200: this delivery must stay retryable, because the holder may
    // still die before finishing.
    $this->postJson(route('billing.webhook'), ['id' => 'tr_claim_blocked'])->assertStatus(503);

    $billable->refresh();
    expect($creates)->toBe(0)
        ->and(BillingInvoice::count())->toBe(0)
        // The holder's claim is untouched...
        ->and($billable->getBillingSubscriptionMeta()[WebhookSupport::ACTIVATION_CLAIM_META_KEY]['payment_id'] ?? null)->toBe('tr_other_tab')
        // ...and nothing was recorded as processed, so Mollie's retry is admitted.
        ->and(BillingProcessedWebhook::where('mollie_payment_id', 'tr_claim_blocked')->count())->toBe(0);
});

it('takes over a stale activation claim so a dead delivery cannot block activation forever', function (): void {
    $billable = claimBillable();
    $billable->forceFill([
        'subscription_meta' => [
            WebhookSupport::ACTIVATION_CLAIM_META_KEY => [
                'payment_id' => 'tr_dead_delivery',
                'claimed_at' => BillingTime::nowUtc()
                    ->subMinutes(WebhookSupport::ACTIVATION_CLAIM_TTL_MINUTES + 5)
                    ->toIso8601String(),
            ],
        ],
    ])->save();

    $creates = 0;
    mockMollieCountingCreates($creates);

    ActivationClaimFakeWebhookController::$nextPayment = firstPaymentStub($billable, 'tr_claim_steal');

    $this->postJson(route('billing.webhook'), ['id' => 'tr_claim_steal'])->assertStatus(200);

    $billable->refresh();
    expect($creates)->toBe(1)
        ->and($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey(WebhookSupport::ACTIVATION_CLAIM_META_KEY);
});

it('lets the same payment re-claim immediately after a crash between the claim and the Mollie call', function (): void {
    $billable = claimBillable();
    // A fresh claim by the SAME payment: the previous delivery crashed hard
    // after committing the gate (finally never ran). reserve() guarantees only
    // one delivery of a payment id is in flight, so this re-delivery is the
    // claim's owner and must not wait out the TTL.
    $billable->forceFill([
        'subscription_meta' => [
            WebhookSupport::ACTIVATION_CLAIM_META_KEY => [
                'payment_id' => 'tr_claim_resume',
                'claimed_at' => BillingTime::nowUtc()->toIso8601String(),
            ],
        ],
    ])->save();

    $creates = 0;
    mockMollieCountingCreates($creates);

    ActivationClaimFakeWebhookController::$nextPayment = firstPaymentStub($billable, 'tr_claim_resume');

    $this->postJson(route('billing.webhook'), ['id' => 'tr_claim_resume'])->assertStatus(200);

    $billable->refresh();
    expect($creates)->toBe(1)
        ->and($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey(WebhookSupport::ACTIVATION_CLAIM_META_KEY);
});

it('does not create a second Mollie subscription when a mandate-only trial activation fails after the Mollie call', function (): void {
    $billable = claimBillable();

    $creates = 0;
    mockMollieCountingCreates($creates);

    $shouldThrow = true;
    MollieBilling::afterCheckoutUsing(function (Billable $b, bool $success) use (&$shouldThrow): void {
        if ($shouldThrow) {
            $shouldThrow = false;
            throw new RuntimeException('app after-checkout hook exploded');
        }
    });

    ActivationClaimFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_claim_trial_crash',
        'status' => 'paid',
        'amount' => (object) ['value' => '0.00', 'currency' => 'EUR'],
        'subscriptionId' => null,
        'customerId' => 'cst_claim',
        'mandateId' => 'mdt_claim',
        'countryCode' => 'DE',
        'metadata' => [
            'type' => 'mandate_only',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'pending_subscription_plan_code' => 'pro',
            'pending_subscription_interval' => 'monthly',
            'pending_subscription_trial_days' => 14,
        ],
    ];

    try {
        $this->postJson(route('billing.webhook'), ['id' => 'tr_claim_trial_crash'])->assertStatus(500);

        $billable->refresh();
        $meta = $billable->getBillingSubscriptionMeta();
        expect($creates)->toBe(1)
            ->and($billable->subscription_status)->toBe(SubscriptionStatus::Trial)
            ->and($meta['mollie_subscription_id'] ?? null)->toBe('sub_claim_1')
            ->and($meta)->not->toHaveKey(WebhookSupport::ACTIVATION_CLAIM_META_KEY);

        $trialEndsAt = $billable->trial_ends_at?->toIso8601String();

        Event::fake([DuplicatePaymentReceived::class]);

        // Re-delivery: the trial branch writes no invoice, so the accessible
        // Trial subscription is what stops the re-run — surfaced as the
        // duplicate outcome, exactly like any later mandate-only payment.
        $this->postJson(route('billing.webhook'), ['id' => 'tr_claim_trial_crash'])->assertStatus(200);

        $billable->refresh();
        expect($creates)->toBe(1)
            ->and($billable->subscription_status)->toBe(SubscriptionStatus::Trial)
            ->and($billable->trial_ends_at?->toIso8601String())->toBe($trialEndsAt);

        Event::assertDispatched(DuplicatePaymentReceived::class);
    } finally {
        $prop = (new ReflectionClass(MollieBilling::class))->getProperty('afterCheckoutCallback');
        $prop->setValue(null, null);
    }
});

it('defers a mandate-only activation while another payment holds a fresh claim', function (): void {
    $billable = claimBillable();
    $billable->forceFill([
        'subscription_meta' => [
            WebhookSupport::ACTIVATION_CLAIM_META_KEY => [
                'payment_id' => 'tr_other_tab',
                'claimed_at' => BillingTime::nowUtc()->subMinute()->toIso8601String(),
            ],
        ],
    ])->save();

    $creates = 0;
    mockMollieCountingCreates($creates);

    ActivationClaimFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_claim_mandate_blocked',
        'status' => 'paid',
        'amount' => (object) ['value' => '0.00', 'currency' => 'EUR'],
        'subscriptionId' => null,
        'customerId' => 'cst_claim',
        'mandateId' => 'mdt_claim',
        'countryCode' => 'DE',
        'metadata' => [
            'type' => 'mandate_only',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'pending_subscription_plan_code' => 'pro',
            'pending_subscription_interval' => 'monthly',
            'pending_subscription_trial_days' => 14,
        ],
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_claim_mandate_blocked'])->assertStatus(503);

    $billable->refresh();
    expect($creates)->toBe(0)
        ->and($billable->subscription_status)->toBe(SubscriptionStatus::New)
        ->and($billable->getBillingSubscriptionMeta()[WebhookSupport::ACTIVATION_CLAIM_META_KEY]['payment_id'] ?? null)->toBe('tr_other_tab')
        ->and(BillingProcessedWebhook::where('mollie_payment_id', 'tr_claim_mandate_blocked')->count())->toBe(0);
});
