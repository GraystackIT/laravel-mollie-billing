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

        // Settled ON the scheduled date, and the schedule then moves one interval
        // on — which is what Mollie's scheduler does, and what makes consecutive
        // renewals produce distinct, forward-walking `paidAt` anchors. Settling
        // every renewal at `now()` instead made the second one land on the same
        // instant as the first, so the billing period appeared not to advance.
        //
        // At 09:00 UTC unless a test asks for another hour (`recurring_charge_time`, "HH:MM"):
        // real Mollie creates its charges shortly after midnight UTC (measured 00:28), which
        // is the previous calendar day for a customer west of UTC.
        [$hour, $minute] = array_map('intval', explode(':', (string) WorkbenchSetting::get('recurring_charge_time', '09:00')) + [1 => 0]);

        $scheduled = $subscription->next_payment_date !== null
            ? \Illuminate\Support\Carbon::parse((string) $subscription->next_payment_date, 'UTC')->setTime($hour, $minute)
            : now();

        $isYearly = str_contains((string) $subscription->interval, 'year')
            || str_contains((string) $subscription->interval, '12 month');

        DB::table('fake_mollie_payments')->where('id', $payment['id'])->update([
            'subscription_id' => $subscriptionId,
            'country_code' => 'DE',
            'paid_at' => self::payment($payment['id'])['status'] === 'paid' ? $scheduled : null,
            // Mollie CREATES a subscription charge on the scheduled date — that is the
            // cycle day the package anchors the billing period to
            // (SubscriptionPaymentHandler::cycleStart()). Left at `now()`, every renewal
            // of a run carried the same creation time and the period stood still.
            'created_at' => $scheduled,
            'updated_at' => now(),
        ]);

        DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->update([
            'next_payment_date' => self::nextAfter($subscription, $scheduled, $isYearly)->toDateString(),
            'updated_at' => now(),
        ]);

        return self::paymentJson(self::payment($payment['id']));
    }

    /**
     * Mollie's next date after a charge. Mollie never overflows a month (31 January → 28
     * February). What it does after a month end is not known before the canary's March 2027
     * visit, so a test chooses (`cadence_after_month_end`): `drift` counts on from the
     * charge (28 Feb → 28 Mar, the default), `clamp` from the subscription's start date
     * (31 Jan, 28 Feb → 31 Mar). A start date the package PATCHed is the start date.
     */
    private static function nextAfter(object $subscription, \Illuminate\Support\Carbon $charged, bool $yearly): \Illuminate\Support\Carbon
    {
        if (WorkbenchSetting::get('cadence_after_month_end', 'drift') === 'clamp' && $subscription->start_date !== null) {
            $start = \Illuminate\Support\Carbon::parse((string) $subscription->start_date, 'UTC');

            for ($n = 1; $n < 600; $n++) {
                $candidate = $yearly ? $start->copy()->addYearsNoOverflow($n) : $start->copy()->addMonthsNoOverflow($n);

                if ($candidate->toDateString() > $charged->toDateString()) {
                    return $candidate;
                }
            }
        }

        return $yearly ? $charged->copy()->addYearNoOverflow() : $charged->copy()->addMonthNoOverflow();
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
     * The customer's bank pulls a paid payment back: a chargeback of `$amountValue` (all of
     * the payment unless given). Like Mollie, the payment's status stays `paid`; only
     * `amountChargedBack` and the payment's chargeback list change. Returns the id.
     */
    public static function chargeback(string $paymentId, ?string $amountValue = null): string
    {
        $payment = self::payment($paymentId);
        $id = WorkbenchSetting::nextId('chb');

        DB::table('fake_mollie_chargebacks')->insert([
            'id' => $id,
            'payment_id' => $paymentId,
            'amount_value' => $amountValue ?? (string) $payment['amount_value'],
            'amount_currency' => (string) $payment['amount_currency'],
            'reversed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * The merchant disputed the chargeback and won: Mollie states `reversedAt` on it.
     *
     * `amountChargedBack` on the payment is left as it was. Whether Mollie lowers it after a
     * reversal is not measured (docs/decisions.md, "Chargebacks"); leaving it is the harder
     * case for the webhook, whose outcome signature then does not move.
     */
    public static function reverseChargeback(string $chargebackId): void
    {
        DB::table('fake_mollie_chargebacks')->where('id', $chargebackId)
            ->update(['reversed_at' => now(), 'updated_at' => now()]);
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
            // Mollie answers whether an open payment can still be cancelled; most methods
            // say no while they are under way. The fake says no unless a test switches it
            // on (`payments_cancelable`), so the default stays what the suite was built on.
            'isCancelable' => $row['status'] === 'open' && WorkbenchSetting::get('payments_cancelable') === '1',
            'paidAt' => $row['paid_at'],
            '_links' => $links,
        ] + self::expiresAt($row) + self::chargedBack($row);
    }

    /**
     * `expiresAt`, on an open interactive payment only — as the API sends it. Mollie keeps
     * an open first payment payable for 15 minutes after its creation (measured, see
     * `SandboxBrowserTestCase::completeMollieCheckout()`).
     *
     * @return array<string, string>
     */
    private static function expiresAt(array $row): array
    {
        if ($row['status'] !== 'open' || $row['sequence_type'] === 'recurring' || empty($row['created_at'])) {
            return [];
        }

        return ['expiresAt' => \Carbon\CarbonImmutable::parse((string) $row['created_at'], 'UTC')->addMinutes(15)->toIso8601String()];
    }

    /**
     * `amountChargedBack`, present only on a payment with a chargeback — as the API leaves
     * it out otherwise.
     *
     * @return array<string, mixed>
     */
    private static function chargedBack(array $row): array
    {
        $values = DB::table('fake_mollie_chargebacks')->where('payment_id', $row['id'])->pluck('amount_value');

        if ($values->isEmpty()) {
            return [];
        }

        $minor = $values->sum(fn ($value): int => (int) round(((float) $value) * 100));

        return ['amountChargedBack' => [
            'value' => number_format($minor / 100, 2, '.', ''),
            'currency' => $row['amount_currency'],
        ]];
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

    public static function chargebackJson(array $row): array
    {
        return [
            'resource' => 'chargeback',
            'id' => $row['id'],
            'amount' => ['value' => $row['amount_value'], 'currency' => $row['amount_currency']],
            'settlementAmount' => ['value' => '-'.$row['amount_value'], 'currency' => $row['amount_currency']],
            'reason' => ['code' => 'MD06', 'description' => 'Refund request by end customer'],
            'paymentId' => $row['payment_id'],
            'createdAt' => $row['created_at'],
            'reversedAt' => $row['reversed_at'],
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
