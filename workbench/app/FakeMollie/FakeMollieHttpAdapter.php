<?php

declare(strict_types=1);

namespace Workbench\App\FakeMollie;

use Illuminate\Support\Facades\DB;
use Mollie\Api\Contracts\HttpAdapterContract;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\PendingRequest;
use Mollie\Api\Http\Requests\CancelPaymentRequest;
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

        // Opt-in outage of the cancel (`fail_cancel_subscription` = 1): Mollie answers a
        // subscription cancel with a 503 and cancels nothing — CancelSubscription then leaves
        // its `pending_subscription_cancel` marker for the hourly sweep. Counted in
        // `failed_cancel_subscriptions`.
        if ($request instanceof CancelSubscriptionRequest
            && WorkbenchSetting::get('fail_cancel_subscription') === '1'
        ) {
            WorkbenchSetting::set('failed_cancel_subscriptions', (string) ((int) WorkbenchSetting::get('failed_cancel_subscriptions', '0') + 1));

            return new Response(
                MockResponse::error(503, 'Service Unavailable', 'Fake Mollie: cancelling subscriptions is switched off for this test.')->createPsrResponse(),
                $pendingRequest->createPsrRequest(),
                $pendingRequest,
            );
        }

        // Idempotency keys, honoured by default as the sandbox measured the real API
        // (tests/Sandbox/MollieIdempotencyContractTest, MollieRequestContractTest,
        // MollieChargedSubscriptionContractTest, MollieSettledPaymentContractTest): a POST or
        // DELETE whose key was seen within the hour is answered with the first request's
        // response — a refusal included — and the same key with other parameters, or on
        // another URL, is a 400. A PATCH is not keyed.
        // `honour_idempotency_keys` = 0 switches it off.
        $idempotencyKey = $this->idempotencyKeyOf($pendingRequest);

        if ($idempotencyKey !== null
            && ($replay = $this->idempotentReplay($idempotencyKey, $pendingRequest, $path, $payload)) !== null
        ) {
            return new Response($replay->createPsrResponse(), $pendingRequest->createPsrRequest(), $pendingRequest);
        }

        // Opt-in lost answer: Mollie creates the payment, and the answer never arrives — the
        // throw a caller sees is the same as a refusal. `lose_create_payment_answer` names a
        // metadata key; only a payment carrying it is affected (e.g. `undercollection`, the
        // balance of an undercollected period).
        $loseFor = (string) WorkbenchSetting::get('lose_create_payment_answer', '');

        if ($request instanceof CreatePaymentRequest
            && $loseFor !== ''
            && array_key_exists($loseFor, (array) ($payload['metadata'] ?? []))
        ) {
            $lost = FakeMollie::createPayment($payload);

            if ($idempotencyKey !== null) {
                $this->rememberIdempotencyKey($idempotencyKey, $pendingRequest, $path, $payload, MockResponse::created($lost));
            }

            // Counted, so a test can tell the lever was pulled rather than the payment
            // simply created.
            WorkbenchSetting::set('lost_create_payment_answers', (string) ((int) WorkbenchSetting::get('lost_create_payment_answers', '0') + 1));

            throw new \RuntimeException('Fake Mollie: the answer to this create-payment request was lost.');
        }

        // Opt-in hold, in the Dusk server only: a request of the armed kind waits until the
        // test disarms it — so whatever the server holds across that call (the change lock
        // of a portal change, say) is still held while the test does something else.
        if (PHP_SAPI === 'cli-server') {
            $this->holdWhileArmed($request);
        }

        // Opt-in count of payment reads (`count_get_payments` = 1): every GetPaymentRequest
        // bumps `get_payment_count.<payment id>`, so a test can tell that a page did — or did
        // not — ask Mollie about a payment (e.g. the return page and a stranger's payment).
        if ($request instanceof GetPaymentRequest && WorkbenchSetting::get('count_get_payments') === '1') {
            $counted = 'get_payment_count.'.$this->segmentAfter($path, 'payments');
            WorkbenchSetting::set($counted, (string) ((int) WorkbenchSetting::get($counted, '0') + 1));
        }

        $mocked = match (true) {
            $request instanceof CreatePaymentRequest => MockResponse::created(FakeMollie::createPayment($payload)),
            $request instanceof GetPaymentRequest => MockResponse::ok(FakeMollie::paymentJson(FakeMollie::payment($this->segmentAfter($path, 'payments')))),
            $request instanceof CancelPaymentRequest => $this->cancelPayment($path),
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

        if ($idempotencyKey !== null) {
            $this->rememberIdempotencyKey($idempotencyKey, $pendingRequest, $path, $payload, $mocked);
        }

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

    /**
     * `hold_mollie_request` names a request class (its base name, e.g.
     * `CreatePaymentRequest`). The first such request the server sends announces itself in
     * `mollie_request_held` and then waits — at most 30 seconds — until the test forgets the
     * setting. Requests after that pass straight through.
     */
    private function holdWhileArmed(object $request): void
    {
        $armed = (string) WorkbenchSetting::get('hold_mollie_request', '');

        if ($armed === '' || class_basename($request) !== $armed) {
            return;
        }

        WorkbenchSetting::set('mollie_request_held', $armed);
        $deadline = microtime(true) + 30;

        while (WorkbenchSetting::get('hold_mollie_request') === $armed && microtime(true) < $deadline) {
            usleep(100_000);
        }
    }

    // ── Idempotency ──────────────────────────────────────────────────────────

    /** How long Mollie keeps an idempotency key (measured: one hour). */
    public const IDEMPOTENCY_TTL_MINUTES = 60;

    /**
     * The answer Mollie gives a POST or DELETE whose key it has seen within the hour
     * (measured 26 November 2026, `tests/Sandbox/MollieIdempotencyContractTest`, unless
     * noted):
     *
     *  - same method, URL and parameters: the first request's response, replayed as it was
     *    then — not the resource as it is now (`idempotency_replays` counts them). That holds
     *    for a refusal too: a 4xx binds its key, and the retry gets the refusal again even
     *    when its reason has gone;
     *  - another URL: 400 "…used before on a different URL…" (first measured 16 November
     *    2026, docs/sandbox-testing.md — two installations' refund keys for "invoice 1");
     *  - same URL, other parameters: 400 "…used before with different parameters…" — for
     *    payments, subscriptions and refunds alike.
     *
     * Both refusals count in `idempotency_conflicts`. Null when the request is new or the key
     * has expired. A PATCH carries a key too (the SDK puts one on it) but Mollie ignores it:
     * a repeat is applied again, see idempotencyKeyOf().
     *
     * Each remembered key is a `workbench_settings` row `idem.<sha1>` holding the key, the
     * request it answered, the response, the id of the resource it created (`payment_id` for a
     * payment) and when it was first seen, so a test can read which keys reached the fake and
     * what each one stands for. A 2xx and a 4xx answer are remembered; a 5xx is not — what
     * Mollie does with a key whose request met an outage cannot be measured, and an outage
     * lever must not stick to the key its retry sends again.
     */
    private function idempotentReplay(string $key, PendingRequest $pendingRequest, string $path, array $payload): ?MockResponse
    {
        $stored = WorkbenchSetting::get('idem.'.sha1($key));
        $entry = $stored !== null ? json_decode($stored, true) : null;

        if (! is_array($entry)
            || \Illuminate\Support\Carbon::parse((string) $entry['at'])->addMinutes(self::IDEMPOTENCY_TTL_MINUTES)->lessThanOrEqualTo(now())
        ) {
            return null;
        }

        if ((string) ($entry['method'] ?? '') !== $pendingRequest->method() || (string) ($entry['path'] ?? '') !== $path) {
            $this->countIdempotency('idempotency_conflicts');

            return MockResponse::error(400, 'Bad Request', 'You are using an idempotency key that you used before on a different URL. Please send a unique idempotency key with each request.');
        }

        if ((string) $entry['fingerprint'] !== $this->fingerprintOf($payload)) {
            $this->countIdempotency('idempotency_conflicts');

            return MockResponse::error(400, 'Bad Request', 'You are using an idempotency key that you used before with different parameters. Please send a unique idempotency key with each request.');
        }

        $this->countIdempotency('idempotency_replays');

        return new MockResponse((string) $entry['body'], (int) $entry['status']);
    }

    /** Remember the answer a key was given — a 2xx or a 4xx, see idempotentReplay(). */
    private function rememberIdempotencyKey(string $key, PendingRequest $pendingRequest, string $path, array $payload, MockResponse $answer): void
    {
        $status = $answer->createPsrResponse()->getStatusCode();

        if ($status < 200 || $status >= 500) {
            return;
        }

        $body = $answer->body();
        $id = (string) ((json_decode($body, true) ?: [])['id'] ?? '');

        WorkbenchSetting::set('idem.'.sha1($key), (string) json_encode(array_filter([
            'key' => $key,
            'request' => class_basename($pendingRequest->getRequest()),
            'method' => $pendingRequest->method(),
            'path' => $path,
            'resource_id' => $id,
            'payment_id' => $pendingRequest->getRequest() instanceof CreatePaymentRequest ? $id : null,
            'fingerprint' => $this->fingerprintOf($payload),
            'status' => $status,
            'body' => $body,
            'at' => now()->toIso8601String(),
        ], static fn (mixed $value): bool => $value !== null)));
    }

    private function countIdempotency(string $counter): void
    {
        WorkbenchSetting::set($counter, (string) ((int) WorkbenchSetting::get($counter, '0') + 1));
    }

    /**
     * The key a POST or DELETE carries — the caller's, or the SDK's random one — unless
     * `honour_idempotency_keys` is `0`. The SDK strips the header from every GET. It sends one
     * on a PATCH as well, and Mollie ignores it there (measured 26 November 2026: the same key
     * with another body is taken, and a repeat is applied again rather than replayed).
     */
    private function idempotencyKeyOf(PendingRequest $pendingRequest): ?string
    {
        if (WorkbenchSetting::get('honour_idempotency_keys') === '0'
            || ! in_array($pendingRequest->method(), ['POST', 'DELETE'], true)
        ) {
            return null;
        }

        $key = $pendingRequest->headers()->get('Idempotency-Key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /** The body as Mollie compares it: every parameter, key order aside. */
    private function fingerprintOf(array $payload): string
    {
        $normalise = static function (mixed $value) use (&$normalise): mixed {
            if (! is_array($value)) {
                return $value;
            }

            ksort($value);

            return array_map($normalise, $value);
        };

        return sha1((string) json_encode($normalise($payload)));
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
        // `MollieSubscriptionPatcher::rebindMandate()`: a payment-method change moves the
        // running subscription onto the new mandate before the old one is revoked.
        if (isset($payload['mandateId'])) {
            $update['mandate_id'] = (string) $payload['mandateId'];
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
        $id = $this->segmentAfter($path, 'mandates');
        $row = DB::table('fake_mollie_mandates')->where('id', $id)->first();

        // A revoked mandate is 410 Gone, not 404 (measured 26 November 2026).
        if ($row === null && WorkbenchSetting::get('revoked_mandate.'.$id) === '1') {
            return MockResponse::error(410, 'Gone', 'The mandate is no longer available');
        }

        return $row === null
            ? MockResponse::notFound('Mandate not found')
            : MockResponse::ok(FakeMollie::mandateJson((array) $row));
    }

    /**
     * Revoking a mandate cancels every subscription that charges it, at once (`canceled`,
     * with `canceledAt`), and the mandate reads back as 410 Gone — measured 26 November 2026,
     * `tests/Sandbox/MollieIdempotencyContractTest` (docs/decisions.md, "A payment-method
     * change revoked the mandate the subscription was bound to").
     */
    private function revokeMandate(string $path): MockResponse
    {
        $mandateId = $this->segmentAfter($path, 'mandates');

        DB::table('fake_mollie_mandates')->where('id', $mandateId)->delete();
        WorkbenchSetting::set('revoked_mandate.'.$mandateId, '1');

        DB::table('fake_mollie_subscriptions')
            ->where('mandate_id', $mandateId)
            ->whereNull('canceled_at')
            ->update(['status' => 'canceled', 'canceled_at' => now(), 'updated_at' => now()]);

        return MockResponse::noContent();
    }

    /**
     * Withdraw an open payment (`PendingFirstPayment::withdraw()` when the customer comes back
     * from the hosted page and confirms another order; the clean-up of a prorata or overage
     * charge). Mollie cancels exactly what it reports `isCancelable: true`, and refuses the
     * rest with 422 "The payment cannot be cancelled" (measured 26 November 2026,
     * `tests/Sandbox/MollieIdempotencyContractTest`): an open first payment — with no method
     * picked, with card or eps, at €0.00 — never is; a pending recurring direct debit is. The
     * fake's `isCancelable` is the `payments_cancelable` lever (FakeMollie::paymentJson()), so
     * a cancel succeeds only where a test switched it on. `refuse_cancel_payment` refuses even
     * then.
     */
    private function cancelPayment(string $path): MockResponse
    {
        $id = $this->segmentAfter($path, 'payments');
        $payment = FakeMollie::payment($id);

        if (WorkbenchSetting::get('refuse_cancel_payment') === '1'
            || ($payment['status'] ?? null) !== 'open'
            || (FakeMollie::paymentJson($payment)['isCancelable'] ?? false) !== true
        ) {
            return MockResponse::unprocessableEntity('The payment cannot be cancelled', 'id');
        }

        DB::table('fake_mollie_payments')->where('id', $id)->update(['status' => 'canceled', 'updated_at' => now()]);

        return MockResponse::ok(FakeMollie::paymentJson(FakeMollie::payment($id)));
    }

    /**
     * `refuse_refund` makes Mollie refuse every refund with a 422 — what it answers for a
     * refund it will not take (an amount above what is left: 422 "The specified amount cannot
     * be refunded", field `amount.value`, measured 26 November 2026).
     * `refused_refunds` counts how often it did, so a test can tell the refusal was reached.
     */
    private function createRefund(string $path, array $payload): MockResponse
    {
        $paymentId = $this->segmentAfter($path, 'payments');
        $payment = FakeMollie::payment($paymentId);

        if (WorkbenchSetting::get('refuse_refund') === '1') {
            WorkbenchSetting::set('refused_refunds', (string) ((int) WorkbenchSetting::get('refused_refunds', '0') + 1));

            return MockResponse::unprocessableEntity('The specified amount cannot be refunded', 'amount.value');
        }

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

    /** A payment's chargebacks — those a test created with `FakeMollie::chargeback()`. */
    private function paymentChargebacks(string $path): MockResponse
    {
        $paymentId = $this->segmentAfter($path, 'payments');

        $chargebacks = DB::table('fake_mollie_chargebacks')
            ->where('payment_id', $paymentId)
            ->orderBy('created_at')
            ->get()
            ->map(fn ($row) => FakeMollie::chargebackJson((array) $row))
            ->values()
            ->all();

        return MockResponse::ok([
            'count' => count($chargebacks),
            '_embedded' => ['chargebacks' => $chargebacks],
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
