<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Sandbox;

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\CreateSubscription;
use GraystackIT\MollieBilling\Services\Billing\MollieMandateInspector;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionGate;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\MollieCall;
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

        // The admin header keeps the summary between requests instead of asking again; a
        // real mandate has to come back from that round trip with what the header renders.
        $kept = \GraystackIT\MollieBilling\Support\MandateSummary::fromSnapshot(
            json_decode(json_encode($summary->snapshot()), true),
        );
        $this->assertSame($summary->id, $kept->id);
        $this->assertSame($summary->status, $kept->status);
        $this->assertSame($summary->method, $kept->method);
        $this->assertSame($summary->details, $kept->details);
        $this->assertSame($summary->mandateReference, $kept->mandateReference);
        $this->assertSame($summary->signatureDate?->toIso8601String(), $kept->signatureDate?->toIso8601String());
        $this->assertSame($summary->accountDisplay(), $kept->accountDisplay());
        $this->assertSame($summary->holder(), $kept->holder());
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

    /**
     * The whole point of the stable idempotency key: a repeated create-payment request must be
     * *answered with the first payment*, not create a second one.
     *
     * `ChargeUsageOverageDirectly::charge()` posts the payment before it can store the id, so a
     * read timeout on the response leaves a debt that looks uncharged. The retry ladder then
     * re-sends it — and this is the contract that decides whether that collects the money twice.
     * Documented by Mollie; never measured here, which for a claim about not charging customers
     * twice is not good enough.
     */
    public function test_it_answers_a_repeated_idempotency_key_with_the_first_payment(): void
    {
        $ids = $this->sandboxCustomerWithMandate();
        $billable = $this->billableOnSandbox($ids);

        // Deliberately the exact shape `ChargeUsageOverageDirectly::idempotencyKeyFor()` builds,
        // down to the FQCN's backslashes and the ISO timestamp's colons: Mollie documents no
        // constraint on the format, so whether those survive as a header value is a fact to
        // measure rather than assume.
        $key = sprintf(
            'usage-overage:%s:%s:%s:100',
            $billable->getMorphClass(),
            (string) $billable->getKey(),
            BillingTime::nowUtc()->toIso8601String(),
        );

        $this->assertStringContainsString('\\', $key, 'The production key carries a class name; test what production sends.');

        $request = fn (): \Mollie\Api\Http\Requests\CreatePaymentRequest => new \Mollie\Api\Http\Requests\CreatePaymentRequest(
            description: 'Idempotency contract',
            amount: new \Mollie\Api\Http\Data\Money('EUR', '1.00'),
            metadata: ['type' => 'overage', 'billable_id' => (string) $billable->getKey()],
            sequenceType: 'recurring',
            mandateId: $ids['mandateId'],
            customerId: $ids['customerId'],
        );

        $first = MollieCall::send($request(), $key);
        $this->trackMollieResource('payment', (string) $first->id, $ids['customerId']);

        // Byte-identical parameters, same key: Mollie must replay rather than charge again.
        $second = MollieCall::send($request(), $key);

        $this->assertSame(
            (string) $first->id,
            (string) $second->id,
            'Mollie created a SECOND payment for a repeated idempotency key — the retry ladder would double-charge.',
        );
    }

    /**
     * A key must not be reusable for a *different* amount.
     *
     * This is why the overage key carries the gross: if more usage accrued between two attempts
     * the request body moves, and Mollie rejects a reused key whose parameters differ. Without
     * the amount in the key that rejection would be the retry's answer instead of a payment.
     */
    public function test_it_refuses_a_reused_idempotency_key_for_a_different_amount(): void
    {
        $ids = $this->sandboxCustomerWithMandate();
        $billable = $this->billableOnSandbox($ids);

        $key = 'sandbox-idempotency-mismatch:'.bin2hex(random_bytes(6));

        $make = fn (string $value): \Mollie\Api\Http\Requests\CreatePaymentRequest => new \Mollie\Api\Http\Requests\CreatePaymentRequest(
            description: 'Idempotency mismatch',
            amount: new \Mollie\Api\Http\Data\Money('EUR', $value),
            metadata: ['type' => 'overage', 'billable_id' => (string) $billable->getKey()],
            sequenceType: 'recurring',
            mandateId: $ids['mandateId'],
            customerId: $ids['customerId'],
        );

        $first = MollieCall::send($make('1.00'), $key);
        $this->trackMollieResource('payment', (string) $first->id, $ids['customerId']);

        $rejected = false;

        try {
            MollieCall::send($make('2.00'), $key);
        } catch (\Mollie\Api\Exceptions\ApiException $e) {
            $rejected = true;
        }

        $this->assertTrue(
            $rejected,
            'Mollie accepted a reused idempotency key with a different amount; the overage key would not need the gross in it.',
        );
    }

    /**
     * The shape `CleanupStalePendingOverageChargeJob::matchLostPayment()` reads.
     *
     * When the answer to a create-payment call is lost, that method finds the payment again in
     * the customer's list — on `metadata.type`, `metadata.billable_id`, `amount.value` and
     * `createdAt`. The existing list test only proves an *empty* page is iterable; every field
     * the matcher keys off is asserted here, on a real listed payment. A missing `createdAt`
     * would silently disable the time guard and let a previous cycle's payment be adopted into
     * this one — which silences a debt that is still owed.
     */
    public function test_it_lists_a_payment_with_the_fields_the_reconciliation_matches_on(): void
    {
        $ids = $this->sandboxCustomerWithMandate();
        $billable = $this->billableOnSandbox($ids);

        $billable->createWallet(['name' => 'SMS', 'slug' => 'SMS'])->forceWithdraw(40);

        $result = app(\GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly::class)
            ->handle($billable->refresh());

        $this->assertNotNull($result->paymentId);
        $this->trackMollieResource('payment', (string) $result->paymentId, $ids['customerId']);

        $listed = null;

        foreach (Mollie::send(new GetPaginatedCustomerPaymentsRequest($ids['customerId'], limit: 50)) as $payment) {
            if ((string) ($payment->id ?? '') === (string) $result->paymentId) {
                $listed = $payment;
                break;
            }
        }

        $this->assertNotNull($listed, 'The payment the package just created is not in the customer list the reconciliation reads.');

        $metadata = json_decode(json_encode($listed->metadata ?? []), true) ?: [];

        $this->assertSame('overage', $metadata['type'] ?? null);
        $this->assertSame((string) $billable->getKey(), (string) ($metadata['billable_id'] ?? ''));

        // 40 SMS at 5 cents is 2.00 net, plus 20% Austrian VAT — the figure the matcher compares
        // against the snapshot's gross.
        $this->assertSame('2.40', (string) ($listed->amount->value ?? ''));

        $this->assertNotSame('', (string) ($listed->createdAt ?? ''), 'Without createdAt the matcher cannot tell this cycle from the last one.');
        $this->assertTrue(
            BillingTime::toUtc((string) $listed->createdAt)->isAfter(BillingTime::nowUtc()->subHour()),
            'createdAt must parse as a recent UTC instant, the way matchLostPayment() reads it.',
        );
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
