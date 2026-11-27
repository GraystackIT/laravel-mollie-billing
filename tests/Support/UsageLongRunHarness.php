<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Support;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob;
use GraystackIT\MollieBilling\Jobs\ProcessTrialLifecycleJob;
use GraystackIT\MollieBilling\Jobs\SweepPendingRetriesJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\GetPaginatedCustomerPaymentsRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * Drives one billable through many billing periods, night by night.
 *
 * The defects this exists to catch do not show in a single renewal: a quota credited
 * twice at a seam, an anchor that drifts a day per period, an overage that is charged
 * twice or not at all once a night is missed. So the tests built on it walk the calendar
 * — the nightly pass every night, the renewal webhook on Mollie's own cycle, overage
 * payments settling or failing in between — and check the books after every period.
 *
 * Two things about reading balances in this suite, both learned the hard way:
 *
 *  - bavix caches a balance for 24 h and writes the column on the commit `RefreshDatabase`
 *    never makes. Travelling past that TTL makes every read answer 0, so `boot()` raises
 *    it, and the assertions read the **ledger** (`transactions`) rather than the balance;
 *  - Mollie is faked at the facade. Every `CreatePaymentRequest` is counted, which is the
 *    package-independent answer to "how many times was this customer charged".
 *
 * The fake honours idempotency keys the way the sandbox measured the real API does
 * (`tests/Sandbox/MollieRequestContractTest.php`): a key repeated within one hour with the
 * same amount is answered with the payment it first created, and with a different amount it
 * is refused. A fake that ignored keys let a retry that reused its predecessor's key look
 * like a new payment, which the real API never makes.
 */
final class UsageLongRunHarness
{
    /** @var array<string, array{lines: array<int, array<string, mixed>>, net: int, gross: int, created_at: string, status: string}> */
    public array $overagePayments = [];

    /** How many `CreatePaymentRequest`s reached the fake. */
    public int $paymentRequests = 0;

    /** @var list<string> class names of every request sent to Mollie */
    public array $requests = [];

    /** When set, `CreatePaymentRequest` throws this instead of answering. */
    public ?\Throwable $failPaymentCreationWith = null;

    /**
     * When set, `CreatePaymentRequest` creates the payment and *then* throws this — the
     * response was lost on its way back, the ordinary read timeout.
     */
    public ?\Throwable $losePaymentCreationAnswerWith = null;

    /**
     * Every payment the fake created, by id, as Mollie holds it — including one whose answer
     * was lost and that the package therefore never recorded.
     *
     * @var array<string, array{gross: int, metadata: array<string, mixed>, created_at: string}>
     */
    public array $createdPayments = [];

    /** @var array<string, array{payment_id: string, gross: int, at: string}> Mollie's key cache */
    private array $idempotencyKeys = [];

    /** The key `MollieCall::send()` set for the request about to be sent. */
    private ?string $pendingIdempotencyKey = null;

    private int $sequence = 0;

    public TestBillable $billable;

    public static function boot(): self
    {
        config()->set('wallet.cache.ttl', 20 * 365 * 24 * 3600);
        config()->set('mollie-billing.billable_model', TestBillable::class);
        config()->set('mollie-billing.invoices.disk', 'local');

        Notification::fake();
        Storage::fake('local');

        $harness = new self;

        Mollie::shouldReceive('setIdempotencyKey')->andReturnUsing(function ($key = null) use ($harness) {
            $harness->pendingIdempotencyKey = is_string($key) ? $key : null;

            return Mollie::getFacadeRoot();
        });
        Mollie::shouldReceive('resetIdempotencyKey')->withAnyArgs()->andReturnSelf();
        Mollie::shouldReceive('send')->andReturnUsing(fn ($request) => $harness->answer($request));

        return $harness;
    }

    public function answer(object $request): mixed
    {
        $this->requests[] = $request::class;

        // A key belongs to the one request it was set for: `MollieCall::send()` clears it
        // afterwards on the real client, which this facade double does not model.
        $key = $this->pendingIdempotencyKey;
        $this->pendingIdempotencyKey = null;

        if ($request instanceof CreatePaymentRequest) {
            $gross = BillingMoney::minorFromMollieAmount(
                (string) (new \ReflectionProperty(CreatePaymentRequest::class, 'amount'))->getValue($request)->value,
                'EUR',
            );

            // Mollie's idempotency, as measured on 26 November 2026
            // (tests/Sandbox/MollieIdempotencyContractTest): within the hour a repeated key is
            // answered with its first answer — a refusal included — and the same key with
            // other parameters is a 400.
            $cached = $key !== null ? ($this->idempotencyKeys[$key] ?? null) : null;

            if ($cached !== null && Carbon::parse($cached['at'], 'UTC')->addHour()->greaterThan(Carbon::now('UTC'))) {
                if ($cached['gross'] !== $gross) {
                    throw new \RuntimeException('[400] You are using an idempotency key that you used before with different parameters');
                }

                if (isset($cached['refusal'])) {
                    throw $cached['refusal'];
                }

                // Mollie replays the first answer: no second payment.
                return (object) ['id' => $cached['payment_id'], 'status' => 'open'];
            }

            if ($this->failPaymentCreationWith !== null) {
                // A refusal (4xx) binds the key; an outage does not.
                $code = (int) $this->failPaymentCreationWith->getCode();

                if ($key !== null && $this->failPaymentCreationWith instanceof \Mollie\Api\Exceptions\ApiException && $code >= 400 && $code < 500) {
                    $this->idempotencyKeys[$key] = ['payment_id' => null, 'gross' => $gross, 'refusal' => $this->failPaymentCreationWith, 'at' => Carbon::now('UTC')->toIso8601String()];
                }

                throw $this->failPaymentCreationWith;
            }

            $this->paymentRequests++;
            $id = 'tr_run_'.(++$this->sequence);

            $this->createdPayments[$id] = [
                'gross' => $gross,
                'metadata' => (array) json_decode((string) json_encode($request->payload()->all()['metadata'] ?? []), true),
                'created_at' => Carbon::now('UTC')->toIso8601String(),
            ];

            if ($key !== null) {
                $this->idempotencyKeys[$key] = ['payment_id' => $id, 'gross' => $gross, 'at' => Carbon::now('UTC')->toIso8601String()];
            }

            if ($this->losePaymentCreationAnswerWith !== null) {
                throw $this->losePaymentCreationAnswerWith;
            }

            return (object) ['id' => $id, 'status' => 'open'];
        }

        if ($request instanceof GetPaymentRequest) {
            $id = (string) (new \ReflectionProperty(GetPaymentRequest::class, 'id'))->getValue($request);

            if (isset($this->createdPayments[$id])) {
                return $this->molliePayment($id);
            }
        }

        if ($request instanceof GetPaginatedCustomerPaymentsRequest) {
            // Newest first, as Mollie lists them.
            return array_map(fn (string $id): object => $this->molliePayment($id), array_reverse(array_keys($this->createdPayments)));
        }

        return (object) ['id' => 'obj_run_'.(++$this->sequence), 'status' => 'active'];
    }

    /** A created payment as Mollie would return it. */
    private function molliePayment(string $id): object
    {
        $created = $this->createdPayments[$id];

        return (object) [
            'id' => $id,
            'status' => $this->overagePayments[$id]['status'] ?? 'open',
            'amount' => (object) ['value' => BillingMoney::mollieAmount($created['gross'], 'EUR'), 'currency' => 'EUR'],
            'createdAt' => $created['created_at'],
            'metadata' => $created['metadata'],
        ];
    }

    /**
     * A plan with one non-rollover type (`Tokens`) and one rollover type (`SMS`).
     *
     * @param  array<string, mixed>  $monthly  overrides for the monthly interval block
     */
    public static function definePlan(string $code = 'pro', array $monthly = [], array $yearly = []): void
    {
        config()->set('mollie-billing-plans.usage_types', [
            'Tokens' => ['rollover' => false],
            'SMS' => ['rollover' => true],
        ]);

        config()->set("mollie-billing-plans.plans.{$code}", [
            'name' => ucfirst($code),
            'tier' => 2,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => array_merge([
                    'base_price_net' => 1000,
                    'seat_price_net' => null,
                    'included_usages' => ['Tokens' => 100, 'SMS' => 50],
                    'usage_overage_prices' => ['Tokens' => 10, 'SMS' => 15],
                ], $monthly),
                'yearly' => array_merge([
                    'base_price_net' => 10000,
                    'seat_price_net' => null,
                    'included_usages' => ['Tokens' => 1200, 'SMS' => 600],
                    'usage_overage_prices' => ['Tokens' => 10, 'SMS' => 15],
                ], $yearly),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createBillable(array $attributes = []): TestBillable
    {
        /** @var TestBillable $billable */
        $billable = TestBillable::create([
            'name' => 'Long Run GmbH',
            'email' => uniqid('longrun-').'@x.test',
            'billing_country' => 'DE',
        ]);

        $billable->forceFill(array_merge([
            'mollie_customer_id' => 'cst_run',
            'mollie_mandate_id' => 'mdt_run',
            'allows_billing_overage' => true,
            'subscription_source' => SubscriptionSource::Mollie,
            'subscription_status' => SubscriptionStatus::Active,
            'subscription_plan_code' => 'pro',
            'subscription_interval' => SubscriptionInterval::Monthly,
            'subscription_period_starts_at' => Carbon::now('UTC'),
            'subscription_meta' => ['seat_count' => 1, 'mollie_subscription_id' => 'sub_run'],
        ], $attributes))->save();

        return $this->billable = $billable->refresh();
    }

    public function fresh(): TestBillable
    {
        return $this->billable = $this->billable->fresh();
    }

    public function travelTo(CarbonInterface|string $moment): void
    {
        Carbon::setTestNow(Carbon::parse((string) $moment, 'UTC'));
        CarbonImmutable::setTestNow(Carbon::parse((string) $moment, 'UTC'));
    }

    public function credit(string $type, int $units, string $reason = 'subscription_activation'): void
    {
        app(WalletUsageService::class)->credit($this->fresh(), $type, $units, $reason);
    }

    public function purchase(string $type, int $units): void
    {
        $this->credit($type, $units, 'one_time_order:pack');
        WalletUsageService::addPurchasedBalance($this->fresh()->getWallet($type), $units);
    }

    public function use(string $type, int $units): void
    {
        app(WalletUsageService::class)->debit($this->fresh(), $type, $units);
    }

    /** One run of the nightly pass, plus whatever it dispatched (the queue is sync). */
    public function night(bool $trialJob = false, bool $sweepJob = false): void
    {
        PrepareUsageOverageJob::dispatchSync();

        if ($trialJob) {
            ProcessTrialLifecycleJob::dispatchSync();
        }

        if ($sweepJob) {
            SweepPendingRetriesJob::dispatchSync();
        }

        $this->registerOverageCharge();
    }

    /** Mollie's recurring payment for the subscription, settled at `$paidAt`. */
    public function renew(CarbonInterface|string $paidAt, string $gross = '11.90', string $status = 'paid'): string
    {
        $id = 'tr_renewal_'.(++$this->sequence);
        $billable = $this->fresh();

        app(MollieWebhookController::class)->replayDelivery((object) [
            'id' => $id,
            'status' => $status,
            'amount' => (object) ['value' => $gross, 'currency' => 'EUR'],
            'paidAt' => Carbon::parse((string) $paidAt, 'UTC')->toIso8601String(),
            'subscriptionId' => (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? 'sub_run'),
            'countryCode' => 'DE',
            'metadata' => [
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
            ],
        ], $billable);

        $this->registerOverageCharge();

        return $id;
    }

    /** The overage payment currently in the snapshot, if it is one we have not seen yet. */
    public function registerOverageCharge(): ?string
    {
        $meta = $this->fresh()->getBillingSubscriptionMeta();
        $snapshot = (array) ($meta[ChargeUsageOverageDirectly::META_KEY] ?? []);
        $id = (string) ($snapshot['payment_id'] ?? '');

        if ($id === '' || isset($this->overagePayments[$id])) {
            return null;
        }

        $this->overagePayments[$id] = [
            'lines' => (array) ($snapshot['line_items'] ?? []),
            'net' => (int) ($snapshot['amount_net'] ?? 0),
            'gross' => (int) ($snapshot['amount_gross'] ?? 0),
            'created_at' => Carbon::now('UTC')->toIso8601String(),
            'status' => 'open',
        ];

        return $id;
    }

    /** The overage payment waiting for its webhook, or null. */
    public function openOveragePayment(): ?string
    {
        foreach ($this->overagePayments as $id => $payment) {
            if ($payment['status'] === 'open') {
                return $id;
            }
        }

        return null;
    }

    /** Deliver the webhook for an overage payment: `paid`, or a terminal failure. */
    public function resolveOverage(string $paymentId, string $status = 'paid'): void
    {
        // A payment whose create answer was lost is known to Mollie but was never recorded
        // from the snapshot: its webhook comes all the same, carrying what Mollie holds.
        $payment = $this->overagePayments[$paymentId] ?? [
            'lines' => (array) ($this->createdPayments[$paymentId]['metadata']['line_items'] ?? []),
            'net' => 0,
            'gross' => (int) $this->createdPayments[$paymentId]['gross'],
            'created_at' => $this->createdPayments[$paymentId]['created_at'],
            'status' => 'open',
        ];
        $billable = $this->fresh();

        $this->overagePayments[$paymentId] = [...$payment, 'status' => $status];

        app(MollieWebhookController::class)->replayDelivery((object) [
            'id' => $paymentId,
            'status' => $status,
            'amount' => (object) ['value' => BillingMoney::mollieAmount($payment['gross'], 'EUR'), 'currency' => 'EUR'],
            'paidAt' => Carbon::now('UTC')->toIso8601String(),
            'metadata' => [
                'type' => 'overage',
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
                'line_items' => $payment['lines'],
            ],
        ], $billable);

        // A failure dispatches the retry job, which (sync queue) has already charged again.
        $this->registerOverageCharge();
    }

    /** Settle every overage payment that is still open — a card, in other words. */
    public function settleOpenOverage(): void
    {
        while (($id = $this->openOveragePayment()) !== null) {
            $this->resolveOverage($id);
        }
    }

    // ── Reading the books ─────────────────────────────────────────────────────

    /**
     * Every ledger row of one wallet, oldest first.
     *
     * @return list<array{amount:int, reason:?string, at:string}>
     */
    public function ledger(string $type): array
    {
        $wallet = $this->fresh()->getWallet($type);

        if ($wallet === null) {
            return [];
        }

        return DB::table('transactions')
            ->where('wallet_id', $wallet->getKey())
            ->orderBy('id')
            ->get()
            ->map(static fn ($row): array => [
                'amount' => (int) $row->amount,
                'reason' => ((array) json_decode((string) $row->meta, true))['reason'] ?? null,
                'at' => (string) $row->created_at,
            ])
            ->all();
    }

    /** The balance as the ledger states it — independent of bavix's cache. */
    public function balance(string $type): int
    {
        return (int) array_sum(array_column($this->ledger($type), 'amount'));
    }

    /** Units moved under a given reason, e.g. how much quota was ever credited. */
    public function units(string $type, string $reason): int
    {
        return (int) array_sum(array_column(
            array_filter($this->ledger($type), static fn (array $row): bool => $row['reason'] === $reason),
            'amount',
        ));
    }

    /** How many ledger rows carry a given reason. */
    public function count(string $type, string $reason): int
    {
        return count(array_filter($this->ledger($type), static fn (array $row): bool => $row['reason'] === $reason));
    }

    public function purchased(string $type): int
    {
        $wallet = $this->fresh()->getWallet($type);

        return $wallet === null ? 0 : WalletUsageService::getPurchasedBalance($wallet->refresh());
    }

    /** Net cents invoiced as overage so far. */
    public function overageInvoicedNet(): int
    {
        return (int) BillingInvoice::query()
            ->where('billable_id', $this->billable->getKey())
            ->where('invoice_kind', 'overage')
            ->sum('amount_net');
    }

    public function invoiceCount(string $kind): int
    {
        return BillingInvoice::query()
            ->where('billable_id', $this->billable->getKey())
            ->where('invoice_kind', $kind)
            ->count();
    }

    /**
     * The overage units a set of settled or open payments billed, per usage type and price.
     *
     * @return array<string, int> "Type@price" => units
     */
    public function billedUnits(): array
    {
        $billed = [];

        foreach ($this->overagePayments as $payment) {
            if (! in_array($payment['status'], ['paid', 'open'], true)) {
                continue;
            }

            foreach ($payment['lines'] as $line) {
                $key = ($line['type'] ?? '?').'@'.($line['unit_price_net'] ?? '?');
                $billed[$key] = ($billed[$key] ?? 0) + (int) ($line['quantity'] ?? 0);
            }
        }

        ksort($billed);

        return $billed;
    }
}
