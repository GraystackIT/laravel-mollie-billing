<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Sandbox;

use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\StartMandateCheckout;
use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * The payments a signup creates, against the real API.
 *
 * These are the shapes nobody can subscribe without, and the ones Mollie has the most
 * opinions about: a first payment has to open a recurring sequence, and a mandate-only
 * checkout asks for **€0.00**, which the API accepts only under conditions no fake can
 * enforce. A test double answers whatever we told it to.
 */
class MollieCheckoutPaymentTest extends SandboxTestCase
{
    private function freshBillable(): TestBillable
    {
        config()->set('mollie-billing-plans.plans.pro', [
            'name' => 'Pro',
            'tier' => 2,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => ['base_price_net' => 1000, 'seat_price_net' => 500, 'included_usages' => []],
                'yearly' => ['base_price_net' => 10000, 'seat_price_net' => 5000, 'included_usages' => []],
            ],
        ]);
        config()->set('mollie-billing-plans.plans.trialplan', [
            'name' => 'Trial Plan',
            'tier' => 1,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'trial_days' => 14, 'included_usages' => []],
            ],
        ]);

        /** @var TestBillable $billable */
        $billable = TestBillable::create([
            'name' => 'Checkout Co',
            'email' => 'checkout-'.bin2hex(random_bytes(3)).'@example.test',
        ]);

        $billable->forceFill([
            'subscription_status' => SubscriptionStatus::New,
            'billing_country' => 'AT',
            'billing_street' => 'Teststrasse 1',
            'billing_postal_code' => '1010',
            'billing_city' => 'Wien',
        ])->save();

        return $billable->refresh();
    }

    public function test_mollie_accepts_the_first_payment_a_paid_signup_creates(): void
    {
        $billable = $this->freshBillable();

        $result = app(StartSubscriptionCheckout::class)->handle($billable, [
            'plan_code' => 'pro',
            'interval' => 'monthly',
            'addon_codes' => [],
            'extra_seats' => 0,
            // 1000 net at 20% Austrian VAT — what the checkout component computes and what
            // Mollie is asked to collect.
            'amount_gross' => 1200,
        ]);

        $this->assertNotEmpty($result['payment_id'] ?? null, 'Mollie refused the first payment of a paid signup.');
        $this->assertNotEmpty($result['checkout_url'] ?? null, 'Without a checkout URL the customer has nowhere to pay.');

        $customerId = $billable->refresh()->getMollieCustomerId();
        $this->assertIsString($customerId);
        $this->trackMollieResource('customer', $customerId);

        $payment = Mollie::send(new GetPaymentRequest((string) $result['payment_id']));

        // 1000 net at 20% Austrian VAT, and a sequence that can capture a mandate — a
        // one-off here would leave the subscription with nothing to charge later.
        $this->assertSame('12.00', $payment->amount->value);
        $this->assertSame('first', $payment->sequenceType);

        // What the webhook routes and prices the activation on. Mollie stores metadata
        // verbatim, so anything the handler reads has to survive the round trip.
        $metadata = json_decode(json_encode($payment->metadata), true) ?: [];
        $this->assertSame('subscription', $metadata['type'] ?? null);
        $this->assertSame('pro', $metadata['plan_code'] ?? null);
        $this->assertSame('monthly', $metadata['interval'] ?? null);
        $this->assertSame((string) $billable->getKey(), (string) ($metadata['billable_id'] ?? ''));
    }

    public function test_mollie_accepts_the_zero_amount_payment_a_trial_signup_creates(): void
    {
        // The one request in the package that asks Mollie for nothing. The API refuses a
        // €0.00 payment outright unless it opens a recurring sequence, so this shape is
        // either exactly right or the entire trial signup is impossible — and no faked
        // adapter can tell the difference.
        $billable = $this->freshBillable();

        $result = app(StartMandateCheckout::class)->handle($billable, null, [
            'plan_code' => 'trialplan',
            'interval' => 'monthly',
            'addon_codes' => [],
            'extra_seats' => 0,
        ]);

        $this->assertNotEmpty($result['payment_id'] ?? null, 'Mollie refused the €0 mandate capture a trial signup needs.');

        $customerId = $billable->refresh()->getMollieCustomerId();
        $this->assertIsString($customerId);
        $this->trackMollieResource('customer', $customerId);

        $payment = Mollie::send(new GetPaymentRequest((string) $result['payment_id']));

        $this->assertSame('0.00', $payment->amount->value);
        $this->assertSame('first', $payment->sequenceType);

        $metadata = json_decode(json_encode($payment->metadata), true) ?: [];
        $this->assertSame('mandate_only', $metadata['type'] ?? null, 'The webhook routes the activation on this.');
    }
}
