<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use Carbon\CarbonImmutable;
use GraystackIT\MollieBilling\Support\MollieCall;
use Illuminate\Support\Facades\DB;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Http\Data\Date;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CancelPaymentRequest;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Api\Http\Requests\GetMandateRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Api\Http\Requests\RevokeMandateRequest;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest;
use Workbench\App\FakeMollie\FakeMollie;
use Workbench\App\Support\WorkbenchSetting;

/**
 * The fake Mollie honours idempotency keys by default, the way the sandbox measured the real
 * API (decision 15 of 26 November 2026): a mutating request whose key was seen within the hour
 * is answered with the first request's response; the same key with other parameters, or on
 * another URL, is refused. `honour_idempotency_keys` = 0 is the lever to switch it off.
 *
 * Re-measured on 26 November 2026 (`tests/Sandbox/MollieIdempotencyContractTest`): both
 * refusals are 400, a refused (4xx) request binds its key like a taken one, a PATCH is not keyed
 * at all while a DELETE is, no open first payment can be cancelled, and a revoked mandate reads
 * back as 410.
 *
 * It used to be opt-in and for create-payment only, so every scenario but `UsageDeepTest`'s ran
 * against a Mollie that took a reused key as a new operation — a retry that should have been
 * replayed (or refused) passed as a second payment, refund or subscription.
 *
 * No browser is opened: the requests go from this test process through the real SDK to the
 * shared fake, exactly as the package sends them.
 */
class FakeMollieIdempotencyTest extends BrowserTestCase
{
    public function test_a_repeated_key_is_answered_with_the_first_payment_without_any_lever(): void
    {
        $this->assertNull(WorkbenchSetting::get('honour_idempotency_keys'), 'the default, nothing switched on');

        $first = MollieCall::send($this->payment('1.00'), 'fake-idem:payment');
        $again = MollieCall::send($this->payment('1.00'), 'fake-idem:payment');

        $this->assertSame((string) $first->id, (string) $again->id);
        $this->assertSame(1, DB::table('fake_mollie_payments')->count());
        $this->assertSame('1', WorkbenchSetting::get('idempotency_replays'));
    }

    public function test_a_reused_key_with_another_amount_is_refused(): void
    {
        MollieCall::send($this->payment('1.00'), 'fake-idem:amount');

        $refusal = $this->refusal(fn () => MollieCall::send($this->payment('2.00'), 'fake-idem:amount'));

        // Measured 26 November 2026: 400, not 422.
        $this->assertSame(400, $refusal->getCode());
        $this->assertStringContainsString('used before with different parameters', $refusal->getMessage());
        $this->assertSame(1, DB::table('fake_mollie_payments')->count());
        $this->assertSame('1', WorkbenchSetting::get('idempotency_conflicts'));
    }

    /** Measured 16 November 2026: 400 "used before on a different URL" (docs/sandbox-testing.md). */
    public function test_a_key_reused_on_another_payment_is_refused_with_a_400(): void
    {
        $one = FakeMollie::createPayment(['amount' => ['currency' => 'EUR', 'value' => '10.00'], 'sequenceType' => 'recurring']);
        $two = FakeMollie::createPayment(['amount' => ['currency' => 'EUR', 'value' => '10.00'], 'sequenceType' => 'recurring']);

        MollieCall::send($this->refund((string) $one['id'], '1.00'), 'fake-idem:refund-1');

        $refusal = $this->refusal(fn () => MollieCall::send($this->refund((string) $two['id'], '1.00'), 'fake-idem:refund-1'));

        $this->assertSame(400, $refusal->getCode());
        $this->assertStringContainsString('different URL', $refusal->getMessage());
        $this->assertSame(1, DB::table('fake_mollie_refunds')->count());
    }

    public function test_a_repeated_refund_and_a_repeated_subscription_create_are_replayed(): void
    {
        $payment = FakeMollie::createPayment(['amount' => ['currency' => 'EUR', 'value' => '10.00'], 'sequenceType' => 'recurring']);

        $refund = MollieCall::send($this->refund((string) $payment['id'], '3.00'), 'fake-idem:refund');
        $refundAgain = MollieCall::send($this->refund((string) $payment['id'], '3.00'), 'fake-idem:refund');

        $this->assertSame((string) $refund->id, (string) $refundAgain->id);
        $this->assertSame(1, DB::table('fake_mollie_refunds')->count());
        $this->assertSame('3.00', (string) DB::table('fake_mollie_payments')->where('id', $payment['id'])->value('amount_refunded_value'));

        // Measured 17 November 2026 (MollieChargedSubscriptionContractTest).
        $customer = FakeMollie::createCustomer(['name' => 'Idem', 'email' => 'idem@example.test']);
        $mandate = FakeMollie::createMandate((string) $customer['id']);

        $subscription = MollieCall::send($this->subscription((string) $customer['id'], $mandate), 'fake-idem:subscription');
        $subscriptionAgain = MollieCall::send($this->subscription((string) $customer['id'], $mandate), 'fake-idem:subscription');

        $this->assertSame((string) $subscription->id, (string) $subscriptionAgain->id);
        $this->assertSame(1, DB::table('fake_mollie_subscriptions')->count());
        $this->assertSame('2', WorkbenchSetting::get('idempotency_replays'));
    }

    /**
     * Measured 26 November 2026: a 4xx binds its key like a 2xx. The retry with the same key is
     * answered with the first refusal although the reason is gone; only a fresh key is new.
     */
    public function test_a_refused_request_binds_its_key(): void
    {
        $payment = FakeMollie::createPayment(['amount' => ['currency' => 'EUR', 'value' => '10.00'], 'sequenceType' => 'recurring']);

        WorkbenchSetting::set('refuse_refund', '1');
        $this->refusal(fn () => MollieCall::send($this->refund((string) $payment['id'], '3.00'), 'fake-idem:refused'));
        WorkbenchSetting::forget('refuse_refund');

        $replayed = $this->refusal(fn () => MollieCall::send($this->refund((string) $payment['id'], '3.00'), 'fake-idem:refused'));
        $this->assertSame(422, $replayed->getCode());
        $this->assertSame(0, DB::table('fake_mollie_refunds')->count());
        $this->assertSame('1', WorkbenchSetting::get('idempotency_replays'));

        // Another request with that key is "other parameters".
        $other = $this->refusal(fn () => MollieCall::send($this->refund((string) $payment['id'], '4.00'), 'fake-idem:refused'));
        $this->assertSame(400, $other->getCode());

        MollieCall::send($this->refund((string) $payment['id'], '3.00'), 'fake-idem:fresh');
        $this->assertSame(1, DB::table('fake_mollie_refunds')->count());
    }

    /** An outage (5xx) the fake answers does not bind: what Mollie does there is not measurable. */
    public function test_an_outage_does_not_bind_its_key(): void
    {
        $customer = FakeMollie::createCustomer(['name' => 'Idem', 'email' => 'idem-outage@example.test']);
        $subscription = MollieCall::send($this->subscription((string) $customer['id'], FakeMollie::createMandate((string) $customer['id'])));

        WorkbenchSetting::set('fail_cancel_subscription', '1');
        $this->refusal(fn () => MollieCall::send(new CancelSubscriptionRequest((string) $customer['id'], (string) $subscription->id), 'fake-idem:outage'));
        WorkbenchSetting::forget('fail_cancel_subscription');

        $cancelled = MollieCall::send(new CancelSubscriptionRequest((string) $customer['id'], (string) $subscription->id), 'fake-idem:outage');
        $this->assertSame('canceled', (string) $cancelled->status);
    }

    /**
     * Measured 26 November 2026: a PATCH is executed whatever key it carries; a DELETE with a
     * repeated key is replayed (a second cancel with a fresh key is a 422).
     */
    public function test_a_patch_is_not_keyed_and_a_delete_is(): void
    {
        $customer = FakeMollie::createCustomer(['name' => 'Idem', 'email' => 'idem-patch@example.test']);
        $subscription = MollieCall::send($this->subscription((string) $customer['id'], FakeMollie::createMandate((string) $customer['id'])));
        $patch = fn (string $value) => MollieCall::send(new UpdateSubscriptionRequest((string) $customer['id'], (string) $subscription->id, amount: new Money('EUR', $value)), 'fake-idem:patch');

        $patch('12.00');
        $patch('13.00');
        $this->assertSame('13.00', (string) DB::table('fake_mollie_subscriptions')->where('id', $subscription->id)->value('amount_value'));

        $cancel = fn (?string $key) => MollieCall::send(new CancelSubscriptionRequest((string) $customer['id'], (string) $subscription->id), $key);
        $cancel('fake-idem:delete');
        $this->assertSame('canceled', (string) $cancel('fake-idem:delete')->status);
        $this->assertSame(422, $this->refusal(fn () => $cancel('fake-idem:delete-fresh'))->getCode());
    }

    /**
     * Measured 26 November 2026: an open first payment is `isCancelable: false` and a cancel is
     * refused with 422 — before a method is picked and with one. `payments_cancelable` is the
     * lever for a payment that can be.
     */
    public function test_an_open_first_payment_cannot_be_cancelled_unless_the_lever_says_so(): void
    {
        $payment = FakeMollie::createPayment(['amount' => ['currency' => 'EUR', 'value' => '10.00'], 'sequenceType' => 'first']);

        $this->assertFalse(MollieCall::send(new GetPaymentRequest((string) $payment['id']))->isCancelable);
        $refusal = $this->refusal(fn () => MollieCall::send(new CancelPaymentRequest((string) $payment['id'])));
        $this->assertSame(422, $refusal->getCode());
        $this->assertSame('open', FakeMollie::payment((string) $payment['id'])['status']);

        WorkbenchSetting::set('payments_cancelable', '1');
        MollieCall::send(new CancelPaymentRequest((string) $payment['id']));
        $this->assertSame('canceled', FakeMollie::payment((string) $payment['id'])['status']);
    }

    /** Measured 26 November 2026: a revoked mandate reads back as 410 Gone, its subscriptions cancelled. */
    public function test_a_revoked_mandate_is_gone(): void
    {
        $customer = FakeMollie::createCustomer(['name' => 'Idem', 'email' => 'idem-revoke@example.test']);
        $mandate = FakeMollie::createMandate((string) $customer['id']);
        $subscription = MollieCall::send($this->subscription((string) $customer['id'], $mandate));

        MollieCall::send(new RevokeMandateRequest((string) $customer['id'], $mandate));

        $this->assertSame(410, $this->refusal(fn () => MollieCall::send(new GetMandateRequest((string) $customer['id'], $mandate)))->getCode());
        $this->assertSame('canceled', (string) DB::table('fake_mollie_subscriptions')->where('id', $subscription->id)->value('status'));
    }

    public function test_the_lever_switches_it_off(): void
    {
        WorkbenchSetting::set('honour_idempotency_keys', '0');

        $first = MollieCall::send($this->payment('1.00'), 'fake-idem:off');
        $second = MollieCall::send($this->payment('2.00'), 'fake-idem:off');

        $this->assertNotSame((string) $first->id, (string) $second->id);
        $this->assertSame(2, DB::table('fake_mollie_payments')->count());
    }

    public function test_after_the_hour_the_key_is_free_again(): void
    {
        $this->travelSharedClockTo('2027-03-10 08:00');
        $first = MollieCall::send($this->payment('1.00'), 'fake-idem:hour');

        $this->travelSharedClockTo('2027-03-10 09:01');
        $later = MollieCall::send($this->payment('2.00'), 'fake-idem:hour');

        $this->assertNotSame((string) $first->id, (string) $later->id);
    }

    private function payment(string $value): CreatePaymentRequest
    {
        return new CreatePaymentRequest(
            description: 'Idempotency default',
            amount: new Money('EUR', $value),
            metadata: ['type' => 'overage'],
            sequenceType: 'recurring',
            mandateId: 'mdt_wb_x',
            customerId: 'cst_wb_x',
        );
    }

    private function refund(string $paymentId, string $value): CreatePaymentRefundRequest
    {
        return new CreatePaymentRefundRequest(
            paymentId: $paymentId,
            description: 'Idempotency default refund',
            amount: new Money('EUR', $value),
        );
    }

    private function subscription(string $customerId, string $mandateId): CreateSubscriptionRequest
    {
        return new CreateSubscriptionRequest(
            customerId: $customerId,
            amount: new Money('EUR', '10.00'),
            interval: '1 month',
            description: 'Idempotency default subscription',
            startDate: new Date(CarbonImmutable::now('UTC')->addDays(30)),
            mandateId: $mandateId,
        );
    }

    private function refusal(callable $call): \Mollie\Api\Exceptions\RequestException
    {
        try {
            $call();
        } catch (\Mollie\Api\Exceptions\RequestException $e) {
            return $e;
        }

        $this->fail('The fake Mollie took the request.');
    }
}
