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

        $mocked = match (true) {
            $request instanceof CreatePaymentRequest => MockResponse::created(FakeMollie::createPayment($payload)),
            $request instanceof GetPaymentRequest => MockResponse::ok(FakeMollie::paymentJson(FakeMollie::payment($this->segmentAfter($path, 'payments')))),
            $request instanceof CreateCustomerRequest => MockResponse::created(FakeMollie::createCustomer($payload)),
            $request instanceof UpdateCustomerRequest => $this->updateCustomer($path, $payload),
            $request instanceof CreateSubscriptionRequest => MockResponse::created(FakeMollie::createSubscription($this->segmentAfter($path, 'customers'), $payload)),
            $request instanceof GetSubscriptionRequest => $this->subscription($path),
            $request instanceof UpdateSubscriptionRequest => $this->updateSubscription($path, $payload),
            $request instanceof CancelSubscriptionRequest => $this->cancelSubscription($path),
            $request instanceof GetMandateRequest => $this->mandate($path),
            $request instanceof RevokeMandateRequest => $this->revokeMandate($path),
            $request instanceof CreatePaymentRefundRequest => $this->createRefund($path, $payload),
            $request instanceof GetPaginatedCustomerPaymentsRequest => $this->customerPayments($path),
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

    private function updateSubscription(string $path, array $payload): MockResponse
    {
        $id = $this->segmentAfter($path, 'subscriptions');

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

        DB::table('fake_mollie_subscriptions')->where('id', $id)->update($update);

        return $this->subscription($path);
    }

    private function cancelSubscription(string $path): MockResponse
    {
        $id = $this->segmentAfter($path, 'subscriptions');

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

    /** The path segment following `{marker}/` — `payments/tr_wb_1` → `tr_wb_1`. */
    private function segmentAfter(string $path, string $marker): string
    {
        $segments = array_values(array_filter(explode('/', $path)));
        $index = array_search($marker, $segments, true);

        abort_if($index === false || ! isset($segments[$index + 1]), 500, "Fake Mollie cannot parse {$path}");

        return $segments[$index + 1];
    }
}
