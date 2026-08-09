<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Sandbox;

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\CreateSubscription;
use GraystackIT\MollieBilling\Services\Billing\MollieMandateInspector;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionGate;
use GraystackIT\MollieBilling\Support\MollieCustomerResolver;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Http\Requests\GetPaginatedCustomerPaymentsRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * Every request type the package sends, against the real API.
 *
 * Built through the package's own services wherever one exists, because the question is
 * not "can the SDK construct this object" — the feature suite already answers that — but
 * "does Mollie accept what *this code* builds from real state, and does the response carry
 * the fields this code then reads".
 */
class MollieRequestContractTest extends SandboxTestCase
{
    private function billableOnSandbox(array $ids, array $overrides = []): TestBillable
    {
        config()->set('mollie-billing-plans.plans.pro', [
            'name' => 'Pro',
            'tier' => 2,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => ['base_price_net' => 1000, 'seat_price_net' => 500, 'included_usages' => ['SMS' => 100], 'usage_overage_prices' => ['SMS' => 5]],
                'yearly' => ['base_price_net' => 10000, 'seat_price_net' => 5000, 'included_usages' => ['SMS' => 1200], 'usage_overage_prices' => ['SMS' => 5]],
            ],
        ]);

        /** @var TestBillable $billable */
        $billable = TestBillable::create([
            'name' => 'Sandbox Co',
            'email' => 'sandbox-billable-'.bin2hex(random_bytes(3)).'@example.test',
        ]);

        $billable->forceFill(array_merge([
            'subscription_source' => SubscriptionSource::Mollie,
            'subscription_status' => SubscriptionStatus::Active,
            'subscription_plan_code' => 'pro',
            'subscription_interval' => SubscriptionInterval::Monthly,
            'subscription_period_starts_at' => now()->subDays(5),
            'billing_country' => 'AT',
            'billing_street' => 'Teststrasse 1',
            'billing_postal_code' => '1010',
            'billing_city' => 'Wien',
            'mollie_customer_id' => $ids['customerId'],
            'mollie_mandate_id' => $ids['mandateId'],
            'allows_billing_overage' => true,
            'subscription_meta' => ['seat_count' => 1],
        ], $overrides))->save();

        return $billable->refresh();
    }

    public function test_it_creates_and_updates_a_customer_through_the_resolver(): void
    {
        /** @var TestBillable $billable */
        $billable = TestBillable::create([
            'name' => 'Resolver Co',
            'email' => 'resolver-'.bin2hex(random_bytes(3)).'@example.test',
        ]);
        $billable->forceFill(['billing_country' => 'AT'])->save();

        $resolver = app(MollieCustomerResolver::class);

        $customerId = $resolver->resolve($billable->refresh());
        $this->assertIsString($customerId);
        $this->assertStringStartsWith('cst_', $customerId);
        $this->trackMollieResource('customer', $customerId);

        // The billable must be left holding the id, or the next call creates a second
        // customer for the same tenant.
        $this->assertSame($customerId, $billable->refresh()->getMollieCustomerId());

        // And the update path — the one the billing-data form now calls.
        $billable->forceFill(['name' => 'Resolver Co Renamed'])->save();
        $resolver->sync($billable->refresh());

        $customer = Mollie::send(new \Mollie\Api\Http\Requests\GetCustomerRequest($customerId));
        $this->assertSame('Resolver Co Renamed', $customer->name);
    }

    public function test_it_reads_a_mandate_the_way_the_inspector_expects(): void
    {
        $ids = $this->sandboxCustomerWithMandate();
        $billable = $this->billableOnSandbox($ids);

        $summary = app(MollieMandateInspector::class)->inspect($billable);

        // The fields the portal and the admin panel render, straight off a real mandate.
        $this->assertNotNull($summary, 'A freshly created test mandate must be readable.');
        $this->assertSame($ids['mandateId'], $summary->id);
        $this->assertSame('valid', $summary->status);
        $this->assertSame('directdebit', $summary->method);
        $this->assertNotNull($summary->signatureDate, 'The portal shows when the mandate was signed.');
        $this->assertNotSame('', (string) ($summary->details['consumerAccount'] ?? ''), 'The masked IBAN is what the customer recognises.');
    }

    public function test_it_creates_a_recurring_payment_through_the_overage_charger(): void
    {
        $ids = $this->sandboxCustomerWithMandate();
        $billable = $this->billableOnSandbox($ids);

        $billable->createWallet(['name' => 'SMS', 'slug' => 'SMS'])->forceWithdraw(40);

        $result = app(\GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly::class)
            ->handle($billable->refresh());

        $this->assertNotNull($result->paymentId, 'Mollie refused the overage charge this package builds.');
        $this->assertStringStartsWith('tr_', (string) $result->paymentId);

        // And the payment reads back with the fields the webhook handler needs.
        $payment = Mollie::send(new GetPaymentRequest((string) $result->paymentId));

        // 40 SMS at 5 cents is 2.00 net, plus 20% Austrian VAT.
        $this->assertSame('2.40', $payment->amount->value);
        $this->assertSame('EUR', $payment->amount->currency);
        $this->assertNotEmpty($payment->status);

        $metadata = json_decode(json_encode($payment->metadata), true) ?: [];
        $this->assertSame('overage', $metadata['type'] ?? null, 'The webhook routes on this.');
        $this->assertSame((string) $billable->getKey(), (string) ($metadata['billable_id'] ?? ''));
    }

    public function test_it_creates_reads_patches_and_cancels_a_subscription(): void
    {
        $ids = $this->sandboxCustomerWithMandate();
        $billable = $this->billableOnSandbox($ids);

        app(CreateSubscription::class)->handle($billable, [
            'plan_code' => 'pro',
            'interval' => 'monthly',
            'addon_codes' => [],
            'extra_seats' => 0,
        ]);

        $billable->refresh();
        $subscriptionId = $billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? null;

        $this->assertIsString($subscriptionId);
        $this->assertStringStartsWith('sub_', $subscriptionId);
        $this->trackMollieResource('subscription', $subscriptionId, $ids['customerId']);

        // Read it back the way MollieSubscriptionGate does.
        $gate = app(MollieSubscriptionGate::class);
        $this->assertTrue($gate->hasLiveMollieSubscription($billable), 'A subscription just created must read as live.');

        // Patch it the way a seat change does — through the package's own pricing.
        app(\GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher::class)
            ->updateRecurringAmount($billable->refresh(), 'pro', 'monthly', [], 1);

        $subscription = Mollie::send(new \Mollie\Api\Http\Requests\GetSubscriptionRequest(
            $ids['customerId'],
            $subscriptionId,
        ));
        // 1000 base + one extra seat at 500, plus 20% VAT.
        $this->assertSame('18.00', $subscription->amount->value, 'The PATCH must reach Mollie at the gross this package computed.');

        // And cancel it.
        app(\GraystackIT\MollieBilling\Services\Billing\CancelSubscription::class)
            ->handle($billable->refresh(), immediately: true);

        $cancelled = Mollie::send(new \Mollie\Api\Http\Requests\GetSubscriptionRequest(
            $ids['customerId'],
            $subscriptionId,
        ));
        $this->assertSame('canceled', $cancelled->status);
    }

    public function test_it_lists_customer_payments_the_way_the_orphan_sweep_does(): void
    {
        $ids = $this->sandboxCustomerWithMandate();

        $response = Mollie::send(new GetPaginatedCustomerPaymentsRequest($ids['customerId']));

        // The sweep iterates this; an empty page must be iterable, not null.
        $count = 0;
        foreach ($response as $_) {
            $count++;
        }

        $this->assertSame(0, $count);
    }

    public function test_it_revokes_a_mandate(): void
    {
        $ids = $this->sandboxCustomerWithMandate();
        $billable = $this->billableOnSandbox($ids);

        (new \GraystackIT\MollieBilling\Jobs\RevokeMollieMandateJob(
            $ids['customerId'],
            $ids['mandateId'],
        ))->handle();

        // Mollie answers 410 Gone for a revoked mandate rather than handing back one with
        // status `invalid`, so the only thing worth asserting is that the package survives
        // it: the inspector is fail-soft and the portal falls back to "no method on file".
        $this->assertNull(
            app(MollieMandateInspector::class)->inspect($billable->refresh()),
            'A revoked mandate must read as "none", not blow up the portal.',
        );
    }
}
