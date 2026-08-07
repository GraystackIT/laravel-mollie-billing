<?php

declare(strict_types=1);

namespace Workbench\App\FakeMollie;

use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Workbench\App\Support\WorkbenchSetting;

/**
 * State layer of the fake Mollie server.
 *
 * Everything lives in the shared SQLite file, so a payment created by the Dusk
 * server (checkout request) is visible to the test process (webhook invocation,
 * assertions) and vice versa. Ids are readable and sequential (tr_wb_1, …) so a
 * screenshot of a failing scenario tells you which payment it was looking at.
 */
class FakeMollie
{
    public static function createCustomer(array $payload): array
    {
        $id = WorkbenchSetting::nextId('cst');

        DB::table('fake_mollie_customers')->insert([
            'id' => $id,
            'name' => $payload['name'] ?? null,
            'email' => $payload['email'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return self::customerJson((array) DB::table('fake_mollie_customers')->where('id', $id)->first());
    }

    public static function createMandate(string $customerId): string
    {
        $id = WorkbenchSetting::nextId('mdt');

        DB::table('fake_mollie_mandates')->insert([
            'id' => $id,
            'customer_id' => $customerId,
            'status' => 'valid',
            'method' => 'creditcard',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public static function createPayment(array $payload): array
    {
        $id = WorkbenchSetting::nextId('tr');
        $sequenceType = (string) ($payload['sequenceType'] ?? 'oneoff');

        // A recurring charge has no checkout page — Mollie debits the mandate on
        // its own. The sandbox settles instantly, so the fake does too; the
        // webhook still only runs when someone posts it, exactly like reality.
        // `recurring_outcome` lets a test turn the next charges into failures.
        $status = 'open';
        $paidAt = null;

        if ($sequenceType === 'recurring') {
            $status = WorkbenchSetting::get('recurring_outcome', 'paid');
            $paidAt = $status === 'paid' ? now() : null;
        }

        DB::table('fake_mollie_payments')->insert([
            'id' => $id,
            'status' => $status,
            'amount_value' => (string) ($payload['amount']['value'] ?? '0.00'),
            'amount_currency' => (string) ($payload['amount']['currency'] ?? 'EUR'),
            'description' => $payload['description'] ?? null,
            'sequence_type' => $sequenceType,
            'redirect_url' => $payload['redirectUrl'] ?? null,
            'webhook_url' => $payload['webhookUrl'] ?? null,
            'customer_id' => $payload['customerId'] ?? null,
            'mandate_id' => $payload['mandateId'] ?? null,
            'metadata' => json_encode($payload['metadata'] ?? []),
            'paid_at' => $paidAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return self::paymentJson(self::payment($id));
    }

    public static function createSubscription(string $customerId, array $payload): array
    {
        $id = WorkbenchSetting::nextId('sub');

        DB::table('fake_mollie_subscriptions')->insert([
            'id' => $id,
            'customer_id' => $customerId,
            'status' => 'active',
            'amount_value' => (string) ($payload['amount']['value'] ?? '0.00'),
            'amount_currency' => (string) ($payload['amount']['currency'] ?? 'EUR'),
            'interval' => (string) ($payload['interval'] ?? '1 month'),
            'description' => $payload['description'] ?? null,
            'mandate_id' => $payload['mandateId'] ?? null,
            'webhook_url' => $payload['webhookUrl'] ?? null,
            'metadata' => json_encode($payload['metadata'] ?? []),
            'start_date' => $payload['startDate'] ?? now()->toDateString(),
            'next_payment_date' => $payload['startDate'] ?? now()->addMonth()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return self::subscriptionJson((array) DB::table('fake_mollie_subscriptions')->where('id', $id)->first());
    }

    /**
     * What Mollie's own scheduler does on a subscription's next payment date:
     * debit the mandate and mint a recurring payment that carries the
     * SUBSCRIPTION's metadata and id.
     *
     * The metadata propagation is the load-bearing part — `MollieWebhookController`
     * resolves the billable from `metadata.billable_type` / `billable_id`, so a
     * renewal without it books nothing at all. `recurring_outcome` decides whether
     * this charge succeeds, which is how a test drives dunning.
     *
     * @return array<string, mixed> the payment as the API would return it
     */
    public static function chargeSubscription(string $subscriptionId, ?string $amountValue = null): array
    {
        $subscription = DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->first();

        abort_if($subscription === null, 404, "Fake Mollie subscription {$subscriptionId} not found");

        $payment = self::createPayment([
            'amount' => [
                'value' => $amountValue ?? $subscription->amount_value,
                'currency' => $subscription->amount_currency,
            ],
            'description' => $subscription->description ?? 'Recurring payment',
            'sequenceType' => 'recurring',
            'customerId' => $subscription->customer_id,
            'mandateId' => $subscription->mandate_id,
            'webhookUrl' => $subscription->webhook_url,
            'metadata' => json_decode((string) ($subscription->metadata ?? '[]'), true) ?: [],
        ]);

        DB::table('fake_mollie_payments')->where('id', $payment['id'])->update([
            'subscription_id' => $subscriptionId,
            'country_code' => 'DE',
            'updated_at' => now(),
        ]);

        return self::paymentJson(self::payment($payment['id']));
    }

    public static function payment(string $id): array
    {
        $row = DB::table('fake_mollie_payments')->where('id', $id)->first();

        abort_if($row === null, 404, "Fake Mollie payment {$id} not found");

        return (array) $row;
    }

    /**
     * Settle an open payment the way the fake checkout page (or a test helper)
     * decides. A `first`/`oneoff` payment that succeeds mints a mandate — that is
     * what the real first payment does, and the package's activation depends on
     * the mandate arriving with the paid webhook.
     */
    public static function settle(string $id, string $outcome, ?string $countryCode = null): void
    {
        $payment = self::payment($id);

        $update = [
            'status' => $outcome,
            'paid_at' => $outcome === 'paid' ? now() : null,
            'country_code' => $countryCode ?? $payment['country_code'] ?? 'DE',
            'updated_at' => now(),
        ];

        if ($outcome === 'paid'
            && in_array($payment['sequence_type'], ['first', 'oneoff'], true)
            && $payment['customer_id'] !== null
            && $payment['mandate_id'] === null
        ) {
            $update['mandate_id'] = self::createMandate($payment['customer_id']);
        }

        DB::table('fake_mollie_payments')->where('id', $id)->update($update);
    }

    /**
     * Deliver the legacy webhook for a payment to the package's controller —
     * inline, not over HTTP: the Dusk server is a single PHP process, so a
     * self-request would deadlock. The controller then fetches the payment back
     * through the fake adapter, exactly as it would against api.mollie.com.
     */
    public static function deliverWebhook(string $paymentId): int
    {
        $request = Request::create('/billing/webhook', 'POST', ['id' => $paymentId]);

        $response = app()->call([app(MollieWebhookController::class), '__invoke'], ['request' => $request]);

        return $response->getStatusCode();
    }

    // ── JSON shapes the real API would return ────────────────────────────────

    public static function paymentJson(array $row): array
    {
        $links = [
            'self' => ['href' => 'https://fake.mollie/payments/'.$row['id'], 'type' => 'application/hal+json'],
            'dashboard' => ['href' => 'https://fake.mollie/dashboard', 'type' => 'text/html'],
        ];

        // Only an unsettled interactive payment has a checkout page — recurring
        // charges never do. The URL targets the workbench's own fake page.
        if ($row['status'] === 'open' && $row['sequence_type'] !== 'recurring') {
            $links['checkout'] = ['href' => url('/fake-mollie/checkout/'.$row['id']), 'type' => 'text/html'];
        }

        return [
            'resource' => 'payment',
            'id' => $row['id'],
            'mode' => 'test',
            'createdAt' => $row['created_at'],
            'status' => $row['status'],
            'amount' => ['value' => $row['amount_value'], 'currency' => $row['amount_currency']],
            'amountRefunded' => ['value' => $row['amount_refunded_value'] ?? '0.00', 'currency' => $row['amount_currency']],
            'description' => $row['description'],
            'metadata' => json_decode((string) ($row['metadata'] ?? '[]'), true),
            'sequenceType' => $row['sequence_type'],
            'redirectUrl' => $row['redirect_url'],
            'webhookUrl' => $row['webhook_url'],
            'profileId' => 'pfl_workbench',
            'customerId' => $row['customer_id'],
            'mandateId' => $row['mandate_id'],
            'subscriptionId' => $row['subscription_id'],
            'countryCode' => $row['country_code'],
            'paidAt' => $row['paid_at'],
            '_links' => $links,
        ];
    }

    public static function customerJson(array $row): array
    {
        return [
            'resource' => 'customer',
            'id' => $row['id'],
            'mode' => 'test',
            'name' => $row['name'],
            'email' => $row['email'],
            'locale' => null,
            'metadata' => null,
            'createdAt' => $row['created_at'],
        ];
    }

    public static function mandateJson(array $row): array
    {
        return [
            'resource' => 'mandate',
            'id' => $row['id'],
            'mode' => 'test',
            'status' => $row['status'],
            'method' => $row['method'],
            'details' => [
                'cardHolder' => 'Workbench Tester',
                'cardNumber' => '6787',
                'cardLabel' => 'Visa',
                'cardFingerprint' => 'wb',
                'cardExpiryDate' => '2030-12-31',
            ],
            'mandateReference' => null,
            'signatureDate' => $row['created_at'],
            'customerId' => $row['customer_id'],
            'createdAt' => $row['created_at'],
        ];
    }

    public static function subscriptionJson(array $row): array
    {
        return [
            'resource' => 'subscription',
            'id' => $row['id'],
            'mode' => 'test',
            'status' => $row['canceled_at'] !== null ? 'canceled' : $row['status'],
            'amount' => ['value' => $row['amount_value'], 'currency' => $row['amount_currency']],
            'times' => null,
            'timesRemaining' => null,
            'interval' => $row['interval'],
            'startDate' => $row['start_date'],
            'nextPaymentDate' => $row['canceled_at'] === null ? $row['next_payment_date'] : null,
            'description' => $row['description'],
            'method' => null,
            'mandateId' => $row['mandate_id'],
            'customerId' => $row['customer_id'],
            'webhookUrl' => $row['webhook_url'],
            'metadata' => json_decode((string) ($row['metadata'] ?? '[]'), true),
            'canceledAt' => $row['canceled_at'],
            'createdAt' => $row['created_at'],
        ];
    }

    public static function refundJson(array $row): array
    {
        return [
            'resource' => 'refund',
            'id' => $row['id'],
            'amount' => ['value' => $row['amount_value'], 'currency' => $row['amount_currency']],
            'status' => $row['status'],
            'paymentId' => $row['payment_id'],
            'description' => $row['description'],
            'createdAt' => $row['created_at'],
        ];
    }
}
