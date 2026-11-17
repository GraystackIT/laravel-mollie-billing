<?php

declare(strict_types=1);

namespace Workbench\App\FakeMollie;

use Illuminate\Support\Facades\DB;
use Mollie\Api\Contracts\HttpAdapterContract;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\PendingRequest;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;
use Mollie\Api\Http\Requests\CreateCustomerRequest;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Api\Http\Requests\GetMandateRequest;
use Mollie\Api\Http\Requests\GetPaginatedCustomerPaymentsRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Api\Http\Requests\GetSubscriptionRequest;
use Mollie\Api\Http\Requests\RevokeMandateRequest;
use Mollie\Api\Http\Requests\UpdateCustomerRequest;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest;
use Mollie\Api\Http\Response;
use Mollie\Api\Traits\HasDefaultFactories;
use Workbench\App\Support\WorkbenchSetting;

/**
 * Mollie's HTTP adapter, answered from the workbench database instead of
 * api.mollie.com.
 *
 * This sits at the same seam the SDK's own MockMollieHttpAdapter uses, which is
 * the whole point: every typed request object the package builds, every response
 * hydration, every `$payment->getCheckoutUrl()` runs the REAL SDK code — only the
 * wire is fake. State is SQLite-backed, so the Dusk server process and the test
 * process act on one shared Mollie.
 */
class FakeMollieHttpAdapter implements HttpAdapterContract
{
    use HasDefaultFactories;

    public function sendRequest(PendingRequest $pendingRequest): Response
    {
        $request = $pendingRequest->getRequest();
        $path = (string) parse_url($pendingRequest->url(), PHP_URL_PATH);
        $payload = $pendingRequest->payload()?->all() ?? [];

        // Opt-in outage, for the scenarios in which a mandate or payment exists and the
        // subscription cannot be created: the activation retry has to finish them.
        if ($request instanceof CreateSubscriptionRequest
            && \Workbench\App\Support\WorkbenchSetting::get('fail_create_subscription') === '1'
        ) {
            throw new \RuntimeException('Fake Mollie: creating subscriptions is switched off for this test.');
        }

        $mocked = match (true) {
            $request instanceof CreatePaymentRequest => MockResponse::created(FakeMollie::createPayment($payload)),
            $request instanceof GetPaymentRequest => MockResponse::ok(FakeMollie::paymentJson(FakeMollie::payment($this->segmentAfter($path, 'payments')))),
            $request instanceof CreateCustomerRequest => MockResponse::created(FakeMollie::createCustomer($payload)),
            $request instanceof UpdateCustomerRequest => $this->updateCustomer($path, $payload),
            $request instanceof CreateSubscriptionRequest => $this->createSubscription($path, $payload),
            $request instanceof GetSubscriptionRequest => $this->subscription($path),
            $request instanceof UpdateSubscriptionRequest => $this->updateSubscription($path, $payload),
            $request instanceof CancelSubscriptionRequest => $this->cancelSubscription($path),
            $request instanceof GetMandateRequest => $this->mandate($path),
            $request instanceof RevokeMandateRequest => $this->revokeMandate($path),
            $request instanceof CreatePaymentRefundRequest => $this->createRefund($path, $payload),
            $request instanceof GetPaginatedCustomerPaymentsRequest => $this->customerPayments($path),
            $request instanceof \Mollie\Api\Http\Requests\GetPaginatedSubscriptionsRequest => $this->customerSubscriptions($path),
            $request instanceof \Mollie\Api\Http\Requests\GetPaginatedPaymentRefundsRequest => $this->paymentRefunds($path),
            $request instanceof \Mollie\Api\Http\Requests\GetPaginatedPaymentChargebacksRequest => $this->paymentChargebacks($path),
            default => MockResponse::notFound('Fake Mollie has no handler for '.get_class($request)),
        };

        return new Response(
            $mocked->createPsrResponse(),
            $pendingRequest->createPsrRequest(),
            $pendingRequest,
        );
    }

    public function version(): ?string
    {
        return 'FakeMollie/1.0';
    }

    // ── Handlers ─────────────────────────────────────────────────────────────

    private function updateCustomer(string $path, array $payload): MockResponse
    {
        $id = $this->segmentAfter($path, 'customers');

        DB::table('fake_mollie_customers')->where('id', $id)->update(array_filter([
            'name' => $payload['name'] ?? null,
            'email' => $payload['email'] ?? null,
            'updated_at' => now(),
        ]));

        return MockResponse::ok(FakeMollie::customerJson((array) DB::table('fake_mollie_customers')->where('id', $id)->first()));
    }

    private function subscription(string $path): MockResponse
    {
        $row = DB::table('fake_mollie_subscriptions')->where('id', $this->segmentAfter($path, 'subscriptions'))->first();

        return $row === null
            ? MockResponse::notFound('Subscription not found')
            : MockResponse::ok(FakeMollie::subscriptionJson((array) $row));
    }

    /**
     * Mollie keeps a customer's subscription descriptions unique among the active ones
     * (measured 17 November 2026, tests/Sandbox/MollieChargedSubscriptionContractTest).
     */
    private function createSubscription(string $path, array $payload): MockResponse
    {
        $customerId = $this->segmentAfter($path, 'customers');

        $clash = DB::table('fake_mollie_subscriptions')
            ->where('customer_id', $customerId)
            ->where('description', $payload['description'] ?? null)
            ->whereNull('canceled_at')
            ->where('status', 'active')
            ->exists();

        if ($clash) {
            return MockResponse::unprocessableEntity('A subscription with the same description already exists for this customer.', 'description');
        }

        return MockResponse::created(FakeMollie::createSubscription($customerId, $payload));
    }

    private function updateSubscription(string $path, array $payload): MockResponse
    {
        $id = $this->segmentAfter($path, 'subscriptions');
        $row = DB::table('fake_mollie_subscriptions')->where('id', $id)->first();

        if ($row !== null && $row->canceled_at !== null) {
            return MockResponse::unprocessableEntity('The subscription has been cancelled.', 'id');
        }

        // The real rule (measured 17 November 2026): once Mollie has created a charge for a
        // subscription, any start date other than the one it holds is refused — the date
        // can then only be moved by replacing the subscription. Amount, interval,
        // description and metadata are still taken.
        if ($row !== null && isset($payload['startDate'])
            && (string) $payload['startDate'] !== (string) $row->start_date
            && DB::table('fake_mollie_payments')->where('subscription_id', $id)->exists()
        ) {
            return MockResponse::unprocessableEntity('The start date cannot be changed when the subscription is already charged.', 'startDate');
        }

        $update = ['updated_at' => now()];
        if (isset($payload['amount']['value'])) {
            $update['amount_value'] = (string) $payload['amount']['value'];
        }
        if (isset($payload['description'])) {
            $update['description'] = (string) $payload['description'];
        }
        if (isset($payload['metadata'])) {
            $update['metadata'] = json_encode($payload['metadata']);
        }
        if (isset($payload['interval'])) {
            $update['interval'] = (string) $payload['interval'];
        }
        // Like the real API (tests/Sandbox/MollieChargeDateContractTest): a start date
        // that is sent moves the next payment, one that is not sent leaves it alone.
        if (isset($payload['startDate'])) {
            $update['start_date'] = (string) $payload['startDate'];
            $update['next_payment_date'] = (string) $payload['startDate'];
        }

        DB::table('fake_mollie_subscriptions')->where('id', $id)->update($update);

        return $this->subscription($path);
    }

    private function cancelSubscription(string $path): MockResponse
    {
        $id = $this->segmentAfter($path, 'subscriptions');

        // A second cancel is refused, not a no-op (measured 17 November 2026).
        if (DB::table('fake_mollie_subscriptions')->where('id', $id)->whereNotNull('canceled_at')->exists()) {
            return MockResponse::unprocessableEntity('The subscription has been cancelled.', 'id');
        }

        DB::table('fake_mollie_subscriptions')->where('id', $id)->update(['status' => 'canceled', 'canceled_at' => now(), 'updated_at' => now()]);

        $row = DB::table('fake_mollie_subscriptions')->where('id', $id)->first();

        return $row === null
            ? MockResponse::notFound('Subscription not found')
            : MockResponse::ok(FakeMollie::subscriptionJson((array) $row));
    }

    private function mandate(string $path): MockResponse
    {
        $row = DB::table('fake_mollie_mandates')->where('id', $this->segmentAfter($path, 'mandates'))->first();

        return $row === null
            ? MockResponse::notFound('Mandate not found')
            : MockResponse::ok(FakeMollie::mandateJson((array) $row));
    }

    private function revokeMandate(string $path): MockResponse
    {
        DB::table('fake_mollie_mandates')->where('id', $this->segmentAfter($path, 'mandates'))->delete();

        return MockResponse::noContent();
    }

    private function createRefund(string $path, array $payload): MockResponse
    {
        $paymentId = $this->segmentAfter($path, 'payments');
        $payment = FakeMollie::payment($paymentId);

        $id = WorkbenchSetting::nextId('re');
        $value = (string) ($payload['amount']['value'] ?? '0.00');

        DB::table('fake_mollie_refunds')->insert([
            'id' => $id,
            'payment_id' => $paymentId,
            'amount_value' => $value,
            'amount_currency' => (string) ($payload['amount']['currency'] ?? $payment['amount_currency']),
            'description' => $payload['description'] ?? null,
            'status' => 'refunded',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('fake_mollie_payments')->where('id', $paymentId)->update([
            'amount_refunded_value' => number_format((float) ($payment['amount_refunded_value'] ?? 0) + (float) $value, 2, '.', ''),
            'updated_at' => now(),
        ]);

        return MockResponse::created(FakeMollie::refundJson((array) DB::table('fake_mollie_refunds')->where('id', $id)->first()));
    }

    private function customerPayments(string $path): MockResponse
    {
        $customerId = $this->segmentAfter($path, 'customers');

        $payments = DB::table('fake_mollie_payments')
            ->where('customer_id', $customerId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($row) => FakeMollie::paymentJson((array) $row))
            ->values()
            ->all();

        return MockResponse::ok([
            'count' => count($payments),
            '_embedded' => ['payments' => $payments],
            '_links' => ['self' => ['href' => 'https://fake.mollie'.$path, 'type' => 'application/hal+json'], 'next' => null, 'previous' => null],
        ]);
    }

    /**
     * A customer's subscriptions — what the activation retry asks before it creates one,
     * so a subscription that reached Mollie on the failed attempt is not created twice.
     */
    private function customerSubscriptions(string $path): MockResponse
    {
        $customerId = $this->segmentAfter($path, 'customers');

        $subscriptions = DB::table('fake_mollie_subscriptions')
            ->where('customer_id', $customerId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($row) => FakeMollie::subscriptionJson((array) $row))
            ->values()
            ->all();

        return MockResponse::ok([
            'count' => count($subscriptions),
            '_embedded' => ['subscriptions' => $subscriptions],
            '_links' => ['self' => ['href' => 'https://fake.mollie'.$path, 'type' => 'application/hal+json'], 'next' => null, 'previous' => null],
        ]);
    }

    /**
     * A payment's refunds — what the refund webhook reads (`MolliePaymentCredits`), one page
     * holding all of them.
     */
    private function paymentRefunds(string $path): MockResponse
    {
        $paymentId = $this->segmentAfter($path, 'payments');

        $refunds = DB::table('fake_mollie_refunds')
            ->where('payment_id', $paymentId)
            ->orderBy('created_at')
            ->get()
            ->map(fn ($row) => FakeMollie::refundJson((array) $row))
            ->values()
            ->all();

        return MockResponse::ok([
            'count' => count($refunds),
            '_embedded' => ['refunds' => $refunds],
            '_links' => ['self' => ['href' => 'https://fake.mollie'.$path, 'type' => 'application/hal+json'], 'next' => null, 'previous' => null],
        ]);
    }

    /** A payment's chargebacks: the fake Mollie cannot produce one, so always none. */
    private function paymentChargebacks(string $path): MockResponse
    {
        return MockResponse::ok([
            'count' => 0,
            '_embedded' => ['chargebacks' => []],
            '_links' => ['self' => ['href' => 'https://fake.mollie'.$path, 'type' => 'application/hal+json'], 'next' => null, 'previous' => null],
        ]);
    }

    /** The path segment following `{marker}/` — `payments/tr_wb_1` → `tr_wb_1`. */
    private function segmentAfter(string $path, string $marker): string
    {
        $segments = array_values(array_filter(explode('/', $path)));
        $index = array_search($marker, $segments, true);

        abort_if($index === false || ! isset($segments[$index + 1]), 500, "Fake Mollie cannot parse {$path}");

        return $segments[$index + 1];
    }
}
