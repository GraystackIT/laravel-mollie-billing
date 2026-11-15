<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Sandbox;

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\CreateSubscription;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Api\Http\Requests\GetSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * What a renewal rests on at Mollie's end — measured where the sandbox allows it.
 *
 * The long-run tests (`tests/Feature/Webhook/RenewalLongRun*Test.php`) drive the renewal
 * handler with a stand-in for Mollie's subscription engine. That stand-in keeps "the
 * amount and start date last sent", and hands the handler payments with a `createdAt`.
 * Both are claims about the API. This is where they are checked.
 *
 * What stays out of reach — see SandboxTestCase — and is therefore still an assumption:
 * a settled recurring payment, so a refund (and its `status`, and whether a `failed` or
 * `canceled` refund stays listed) and a chargeback (`reversedAt`) cannot be produced; and
 * a period rolling over, so the cadence after a month end cannot be observed.
 */
class MollieRenewalContractTest extends SandboxTestCase
{
    /**
     * @return array{0: TestBillable, 1: string, 2: array{customerId: string, mandateId: string}}
     */
    private function runningSubscription(): array
    {
        config()->set('mollie-billing-plans.plans.pro', [
            'name' => 'Pro',
            'tier' => 2,
            'included_seats' => 2,
            'feature_keys' => [],
            'allowed_addons' => ['sso'],
            'intervals' => [
                'monthly' => ['base_price_net' => 2900, 'seat_price_net' => 500, 'included_usages' => []],
            ],
        ]);
        config()->set('mollie-billing-plans.addons.sso', [
            'name' => 'SSO',
            'feature_keys' => [],
            'intervals' => ['monthly' => ['price_net' => 1500]],
        ]);

        $ids = $this->sandboxCustomerWithMandate();

        /** @var TestBillable $billable */
        $billable = TestBillable::create([
            'name' => 'Sandbox Renewal Co',
            'email' => 'sandbox-renewal-'.bin2hex(random_bytes(3)).'@example.test',
        ]);

        $billable->forceFill([
            'subscription_source' => SubscriptionSource::Mollie,
            'subscription_status' => SubscriptionStatus::Active,
            'subscription_plan_code' => 'pro',
            'subscription_interval' => SubscriptionInterval::Monthly,
            'subscription_period_starts_at' => now(),
            'billing_country' => 'AT',
            'billing_street' => 'Teststrasse 1',
            'billing_postal_code' => '1010',
            'billing_city' => 'Wien',
            'mollie_customer_id' => $ids['customerId'],
            'mollie_mandate_id' => $ids['mandateId'],
            'subscription_meta' => ['seat_count' => 2],
        ])->save();

        app(CreateSubscription::class)->handle($billable->refresh(), [
            'plan_code' => 'pro',
            'interval' => 'monthly',
            'addon_codes' => [],
            'extra_seats' => 0,
        ]);

        $billable->refresh();
        $subscriptionId = (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'];
        $this->trackMollieResource('subscription', $subscriptionId, $ids['customerId']);

        return [$billable, $subscriptionId, $ids];
    }

    public function test_mollie_holds_exactly_the_amount_and_start_date_create_subscription_sends(): void
    {
        [, $subscriptionId, $ids] = $this->runningSubscription();

        $subscription = Mollie::send(new GetSubscriptionRequest($ids['customerId'], $subscriptionId));

        // Pro 29.00 at AT 20 %: 34.80. First recurring charge one interval from today.
        $expectedStart = BillingTime::addInterval(BillingTime::nowUtc(), 'monthly')->toDateString();

        $this->assertSame('34.80', $subscription->amount->value);
        $this->assertSame('1 month', (string) $subscription->interval);
        $this->assertSame($expectedStart, (string) $subscription->startDate);
        $this->assertSame($expectedStart, (string) $subscription->nextPaymentDate);
        $this->assertSame('pro', $subscription->metadata->plan_code ?? null);
        $this->assertSame('monthly', $subscription->metadata->interval ?? null);
    }

    public function test_the_patcher_s_amount_change_arrives_and_leaves_the_charge_date_alone(): void
    {
        [$billable, $subscriptionId, $ids] = $this->runningSubscription();
        $before = Mollie::send(new GetSubscriptionRequest($ids['customerId'], $subscriptionId));

        // Three extra seats and SSO are added: 29.00 + 15.00 + 15.00 = 59.00 net; per line
        // at 20 %: 5.80 + 3.00 + 3.00 → 70.80.
        $billable->forceFill([
            'active_addon_codes' => ['sso'],
            'subscription_meta' => ['seat_count' => 5] + $billable->getBillingSubscriptionMeta(),
        ])->save();

        app(MollieSubscriptionPatcher::class)->updateRecurringAmount(
            billable: $billable->refresh(),
            planCode: 'pro',
            interval: 'monthly',
            addons: ['sso'],
            extraSeats: 3,
        );

        $after = Mollie::send(new GetSubscriptionRequest($ids['customerId'], $subscriptionId));

        $this->assertSame('70.80', $after->amount->value, 'The amount the patcher builds is the amount Mollie holds.');
        $this->assertSame((string) $before->nextPaymentDate, (string) $after->nextPaymentDate, 'An amount-only PATCH must not move the next charge.');
        $this->assertSame((string) $before->startDate, (string) $after->startDate);
        $this->assertSame('1 month', (string) $after->interval);

        // The metadata is what a renewal payment will carry — SupersededRenewalRefund
        // compares it with the local configuration.
        $this->assertSame(3, (int) ($after->metadata->extra_seats ?? -1));
        $this->assertSame(['sso'], array_values((array) ($after->metadata->addon_codes ?? [])));
        $this->assertSame('pro', $after->metadata->plan_code ?? null);
    }

    public function test_the_patcher_s_amount_and_start_date_arrive_together(): void
    {
        [$billable, $subscriptionId, $ids] = $this->runningSubscription();

        // The past-due reset: a new amount AND the cadence moved to one interval from now.
        Carbon::setTestNow(BillingTime::nowUtc()->addDays(9));

        try {
            app(MollieSubscriptionPatcher::class)->updateRecurringAmount(
                billable: $billable->refresh(),
                planCode: 'pro',
                interval: 'monthly',
                addons: [],
                extraSeats: 1,
                forceResetStartDate: true,
            );

            $expectedStart = BillingTime::addInterval(BillingTime::nowUtc(), 'monthly')->toDateString();
        } finally {
            Carbon::setTestNow();
        }

        $after = Mollie::send(new GetSubscriptionRequest($ids['customerId'], $subscriptionId));

        // 29.00 + 5.00 = 34.00 net; 5.80 + 1.00 → 40.80.
        $this->assertSame('40.80', $after->amount->value);
        $this->assertSame($expectedStart, (string) $after->startDate);
        $this->assertSame($expectedStart, (string) $after->nextPaymentDate, 'Amount and start date sent in one PATCH must both be in force.');
    }

    public function test_a_recurring_payment_carries_what_the_renewal_handler_reads(): void
    {
        $ids = $this->sandboxCustomerWithMandate();

        $created = Mollie::send(new CreatePaymentRequest(
            description: 'Renewal contract',
            amount: new Money('EUR', '34.80'),
            metadata: ['billable_type' => 'sandbox', 'billable_id' => '1', 'plan_code' => 'pro', 'extra_seats' => 3],
            sequenceType: 'recurring',
            mandateId: $ids['mandateId'],
            customerId: $ids['customerId'],
        ));
        $this->trackMollieResource('payment', (string) $created->id, $ids['customerId']);

        $payment = Mollie::send(new GetPaymentRequest((string) $created->id));

        // `createdAt` is what the renewal handler anchors the period on — the cycle the
        // charge was created for — because a direct debit's `paidAt` comes days later.
        $this->assertNotEmpty($payment->createdAt ?? null, 'A payment must carry createdAt.');
        $this->assertLessThan(300, abs(Carbon::parse((string) $payment->createdAt)->diffInSeconds(now(), false)), 'createdAt is the moment the charge was created.');

        // A direct debit is not paid when it is created: this gap is the whole reason.
        $this->assertContains((string) $payment->status, ['pending', 'open']);
        $this->assertEmpty($payment->paidAt ?? null, 'A direct debit has no paidAt while it settles.');

        $this->assertSame('34.80', $payment->amount->value);
        $this->assertSame('EUR', $payment->amount->currency);
        $this->assertSame('pro', $payment->metadata->plan_code ?? null);
        $this->assertSame(3, (int) ($payment->metadata->extra_seats ?? -1));

        // Nothing refunded or charged back: the totals the dedup signature reads are
        // absent, and the lists the handlers iterate exist and are empty.
        $this->assertEmpty($payment->amountRefunded->value ?? null);
        $this->assertEmpty($payment->amountChargedBack->value ?? null);

        $refunds = iterator_to_array((function () use ($payment) {
            yield from $payment->refunds();
        })(), false);
        $chargebacks = iterator_to_array((function () use ($payment) {
            yield from $payment->chargebacks();
        })(), false);

        $this->assertSame([], $refunds);
        $this->assertSame([], $chargebacks);
    }

    /**
     * The limit of this suite, pinned so nobody reads the missing refund cases as an
     * oversight: Mollie does not refund a payment that has not settled, and no recurring
     * payment settles within a test run.
     */
    public function test_a_refund_cannot_be_created_before_the_payment_settled(): void
    {
        $ids = $this->sandboxCustomerWithMandate();

        $payment = Mollie::send(new CreatePaymentRequest(
            description: 'Refund reachability',
            amount: new Money('EUR', '10.00'),
            sequenceType: 'recurring',
            mandateId: $ids['mandateId'],
            customerId: $ids['customerId'],
        ));
        $this->trackMollieResource('payment', (string) $payment->id, $ids['customerId']);

        $refused = false;

        try {
            Mollie::send(new CreatePaymentRefundRequest(
                paymentId: (string) $payment->id,
                description: 'Refund reachability',
                amount: new Money('EUR', '1.00'),
            ));
        } catch (ApiException $e) {
            $refused = true;
        }

        $this->assertTrue(
            $refused,
            'Mollie created a refund for an unsettled payment — the refund status contract '
            .'(RefundHandler: failed/canceled, and whether they stay listed) is now measurable here; add it.',
        );
    }
}
