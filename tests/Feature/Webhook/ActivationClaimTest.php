<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\DuplicatePaymentReceived;
use GraystackIT\MollieBilling\Events\WalletCredited;
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

it('finishes the activation even when the app\'s after-checkout hook throws', function (): void {
    $billable = claimBillable();

    $creates = 0;
    mockMollieCountingCreates($creates);

    // The after-checkout hook is an arbitrary app closure and the last activation step —
    // it throws AFTER the Mollie subscription exists.
    $shouldThrow = true;
    MollieBilling::afterCheckoutUsing(function (Billable $b, bool $success) use (&$shouldThrow): void {
        if ($shouldThrow) {
            $shouldThrow = false;
            throw new RuntimeException('app after-checkout hook exploded');
        }
    });

    ActivationClaimFakeWebhookController::$nextPayment = firstPaymentStub($billable, 'tr_claim_crash');

    try {
        // 200, not 500. Letting the throw escape *looked* like durability — Mollie
        // re-delivers — but the re-delivery finds the invoice, answers AlreadyBooked and
        // takes the quiet branch, so the steps that never ran were never going to run.
        // Each side effect is isolated now: the hook's failure is logged at error level
        // and everything else completes.
        $this->postJson(route('billing.webhook'), ['id' => 'tr_claim_crash'])->assertStatus(200);

        $billable->refresh();
        $meta = $billable->getBillingSubscriptionMeta();

        expect($creates)->toBe(1)
            ->and($meta['mollie_subscription_id'] ?? null)->toBe('sub_claim_1')
            ->and($meta)->not->toHaveKey(WebhookSupport::ACTIVATION_CLAIM_META_KEY)
            ->and(BillingInvoice::where('mollie_payment_id', 'tr_claim_crash')->count())->toBe(1)
            ->and($billable->subscription_status)->toBe(SubscriptionStatus::Active)
            // The quota the customer paid for. This is what the escaping throw used to
            // cost when it happened one step earlier in the same sequence.
            ->and((int) ($billable->getWallet('Tokens')?->balanceInt ?? 0))->toBe(10);

        Event::fake([DuplicatePaymentReceived::class]);

        // And should Mollie re-deliver anyway, the invoice guard still swallows the re-run:
        // no second CreateSubscriptionRequest, no second invoice, and no duplicate-payment
        // handling for the customer's only payment.
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

it('finishes a mandate-only trial activation even when the after-checkout hook throws', function (): void {
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
        // 200: the hook's failure is logged and isolated, so the trial itself is complete —
        // including the prorated quota, which an escaping throw one step earlier used to
        // cost the customer for good (the re-delivery answers with the duplicate outcome
        // and never re-runs the activation).
        $this->postJson(route('billing.webhook'), ['id' => 'tr_claim_trial_crash'])->assertStatus(200);

        $billable->refresh();
        $meta = $billable->getBillingSubscriptionMeta();
        expect($creates)->toBe(1)
            ->and($billable->subscription_status)->toBe(SubscriptionStatus::Trial)
            ->and($meta['mollie_subscription_id'] ?? null)->toBe('sub_claim_1')
            ->and($meta)->not->toHaveKey(WebhookSupport::ACTIVATION_CLAIM_META_KEY)
            // 10 monthly tokens prorated to 14 of 30 days.
            ->and((int) ($billable->getWallet('Tokens')?->balanceInt ?? 0))->toBe(5);

        $trialEndsAt = $billable->trial_ends_at?->toIso8601String();

        Event::fake([DuplicatePaymentReceived::class]);

        // A re-delivery of the same payment now carries the same outcome signature as one
        // already recorded as processed, so it is deduplicated before any handler runs —
        // which is why nothing reports it as a duplicate *payment*. Previously the escaping
        // throw released the reservation, the re-run reached the handler, and the accessible
        // Trial subscription was what stopped it.
        $this->postJson(route('billing.webhook'), ['id' => 'tr_claim_trial_crash'])->assertStatus(200);

        $billable->refresh();
        expect($creates)->toBe(1)
            ->and($billable->subscription_status)->toBe(SubscriptionStatus::Trial)
            ->and($billable->trial_ends_at?->toIso8601String())->toBe($trialEndsAt);

        Event::assertNotDispatched(DuplicatePaymentReceived::class);
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

it('credits the rest of the quota when one wallet listener throws', function (): void {
    // WalletCredited is a documented extension seam and is dispatched synchronously, so an
    // app listener is one throw away from the middle of an activation. Unwrapped, the
    // customer was left Active and paying with half their quota, no country-match check,
    // no SubscriptionCreated for the app to react to, and nobody told — because the
    // re-delivery finds the invoice, answers AlreadyBooked and takes the quiet branch.
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.included_usages', [
        'Tokens' => 10,
        'SMS' => 20,
    ]);

    $billable = claimBillable();

    $creates = 0;
    mockMollieCountingCreates($creates);

    Event::listen(WalletCredited::class, function (WalletCredited $event): void {
        if ($event->usageType === 'Tokens') {
            throw new RuntimeException('CRM push for the Tokens wallet exploded');
        }
    });

    ActivationClaimFakeWebhookController::$nextPayment = firstPaymentStub($billable, 'tr_claim_listener');

    $this->postJson(route('billing.webhook'), ['id' => 'tr_claim_listener'])->assertStatus(200);

    $billable->refresh();

    // The failing wallet is the only casualty; everything after it completed.
    expect((int) ($billable->getWallet('SMS')?->balanceInt ?? 0))->toBe(20)
        ->and($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? null)->toBe('sub_claim_1')
        ->and(BillingInvoice::where('mollie_payment_id', 'tr_claim_listener')->count())->toBe(1);
});
