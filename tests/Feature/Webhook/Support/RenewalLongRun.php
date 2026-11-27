<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Storage;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/*
 * A stand-in for Mollie's subscription engine, for running a subscription over many
 * periods through the real webhook route.
 *
 * It keeps exactly what Mollie keeps — the amount, the next charge date, the interval and
 * the metadata last sent by CreateSubscription / UpdateSubscription — and produces the
 * recurring payments Mollie would: at the amount held *when the charge is created*, with
 * the subscription's metadata copied onto the payment, `createdAt` on the cycle date and
 * `paidAt` whenever the test says the money settled.
 *
 * Two things here are a model, not a measurement, and the sandbox cannot settle either
 * (its scheduler runs daily and no run can watch a period roll over):
 *
 *  - the cadence: `start + n × interval`, clamped to the month's last day and returning
 *    to the start's own day afterwards (31 Jan → 28 Feb → 31 Mar);
 *  - the hour a charge is created: 06:00 UTC on the cycle date.
 */
final class LongRunWorld
{
    public static ?self $current = null;

    /** The currency the test Mollie settles in. Set before the run starts. */
    public static string $currency = 'EUR';

    public ?string $heldValue = null;

    public ?CarbonImmutable $cadenceStart = null;

    public int $cadenceCount = 0;

    /**
     * `clamp`: every charge is `start + n × interval`, so a start on the 30th returns to
     * the 30th after February. `drift`: every charge is the previous one plus an
     * interval, so February pulls the day down for good. Which one Mollie does is not
     * measurable in the sandbox; the package has to be right under both.
     */
    public string $cadence = 'clamp';

    public ?CarbonImmutable $lastCycle = null;

    public string $mollieInterval = '1 month';

    /** @var array<string, mixed> */
    public array $subscriptionMetadata = [];

    public bool $cancelled = false;

    /**
     * Whether Mollie has created a charge for the subscription it holds. From then on it
     * refuses a new start date (measured 17 November 2026, see
     * tests/Sandbox/MollieChargedSubscriptionContractTest) — the date has to be moved by
     * replacing the subscription.
     */
    public bool $charged = false;

    /** The id of the subscription Mollie holds; a replacement gets a new one. */
    public string $subscriptionId = 'sub_longrun';

    public ?string $description = null;

    /** @var array<string, object> */
    public array $payments = [];

    /** @var list<object> every request the package sent */
    public array $requests = [];

    /** @var list<array{amount: ?string, startDate: ?string, at: string}> */
    public array $patches = [];

    /**
     * Every next-charge date Mollie was given after the first subscription existed: a
     * start date it took in a PATCH, or the start date of a subscription that replaced a
     * charged one.
     *
     * @var list<string>
     */
    public array $moves = [];

    public int $sequence = 0;

    /**
     * Requests Mollie refuses: class name => how many more times. `PHP_INT_MAX` for
     * "always". The request is still recorded.
     *
     * @var array<class-string, int>
     */
    public array $refuse = [];

    /**
     * Requests Mollie carries out and then fails to answer: class name => how many more
     * times. The costliest failure there is — the caller cannot tell it from a refusal.
     *
     * @var array<class-string, int>
     */
    public array $loseAnswer = [];

    /**
     * Answer (or throw) for a request before the engine sees it; return null to pass on.
     *
     * @var (\Closure(object): mixed)|null
     */
    public ?\Closure $intercept = null;

    /** @var array<string, list<object>> refunds Mollie created, by payment id */
    public array $refunds = [];

    /** @var list<mixed> every idempotency key the package set, in order */
    public array $idempotencyKeys = [];

    /** Mollie's idempotency keys, honoured by default (`$idempotency->honour = false` switches it off). */
    public \GraystackIT\MollieBilling\Tests\Support\MollieIdempotencyMemory $idempotency;

    /** How many subscriptions were actually created at Mollie. */
    public int $subscriptionsCreated = 0;

    public ?BillingInvoice $lastInvoice = null;

    public function __construct(public TestBillable $billable, public string $interval)
    {
        $this->idempotency = new \GraystackIT\MollieBilling\Tests\Support\MollieIdempotencyMemory;
    }

    public function nextChargeAt(): ?CarbonImmutable
    {
        if ($this->cadenceStart === null || $this->cancelled) {
            return null;
        }

        $months = $this->mollieInterval === '12 months' ? 12 : 1;

        if ($this->cadence === 'drift' && $this->cadenceCount > 0 && $this->lastCycle !== null) {
            return $this->lastCycle->addMonthsNoOverflow($months)->setTime(6, 0);
        }

        return $this->cadenceStart->addMonthsNoOverflow($months * $this->cadenceCount)->setTime(6, 0);
    }

    public function heldMinor(): int
    {
        return BillingMoney::minorFromMollieAmount($this->heldValue, self::$currency);
    }
}

/**
 * A payment as the SDK hands it over: plain properties, plus the two lookups the refund
 * and chargeback handlers call on it.
 */
#[\AllowDynamicProperties]
final class LongRunPayment
{
    /** @param array<string, mixed> $attributes */
    public static function make(array $attributes): self
    {
        $payment = new self;

        foreach ($attributes as $key => $value) {
            $payment->{$key} = $value;
        }

        return $payment;
    }

    /** @return list<object> */
    public function refunds(): array
    {
        return LongRunWorld::$current?->refunds[(string) $this->id] ?? [];
    }

    /** @return list<object> */
    public function chargebacks(): array
    {
        return [];
    }
}

class LongRunFakeWebhookController extends MollieWebhookController
{
    protected function fetchPayment(string $paymentId): object
    {
        return LongRunWorld::$current?->payments[$paymentId]
            ?? throw new RuntimeException("No stub for {$paymentId}.");
    }
}

function longRunRequestProperty(object $request, string $name): mixed
{
    $class = new ReflectionClass($request);

    if (! $class->hasProperty($name)) {
        return null;
    }

    $property = $class->getProperty($name);
    $property->setAccessible(true);

    return $property->getValue($request);
}

function longRunDate(mixed $date): ?CarbonImmutable
{
    if ($date === null) {
        return null;
    }

    return CarbonImmutable::parse((string) $date, 'UTC')->startOfDay();
}

/** A 422 as Mollie words it — the detail is what the package reads. */
function longRunMollieRefusal(string $detail): \Mollie\Api\Exceptions\ApiException
{
    return \GraystackIT\MollieBilling\Tests\Support\MollieApiExceptionFactory::withStatus(
        422,
        'Error executing API call (422: Unprocessable Entity): '.$detail,
    );
}

/** The subscription Mollie holds, as `GetSubscriptionRequest` answers it. */
function longRunSubscriptionObject(LongRunWorld $world): object
{
    return (object) [
        'id' => $world->subscriptionId,
        'status' => $world->cancelled ? 'canceled' : 'active',
        'amount' => (object) ['value' => $world->heldValue, 'currency' => LongRunWorld::$currency],
        'interval' => $world->mollieInterval,
        'description' => $world->description,
        'metadata' => (object) $world->subscriptionMetadata,
        'mandateId' => 'mdt_longrun',
        'webhookUrl' => 'https://example.test/billing/webhook',
        'startDate' => $world->cadenceStart?->toDateString(),
        'nextPaymentDate' => $world->cancelled ? null : $world->nextChargeAt()?->toDateString(),
    ];
}

/** Catalog and fakes shared by every long-run test. Call from `beforeEach`. */
function longRunBoot(): void
{
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');

    // The wallet's balance cache expires after a day, and inside RefreshDatabase's
    // never-committing transaction that cache is the only place a balance lives — so
    // travelling a month forward would silently zero every wallet. Not a package
    // behaviour: in production the balance is in the database.
    config()->set('wallet.cache.ttl', null);

    LongRunWorld::$current = null;
    LongRunWorld::$currency = 'EUR';
    app()->bind(MollieWebhookController::class, LongRunFakeWebhookController::class);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 2,
        'feature_keys' => [],
        'allowed_addons' => ['sso', 'crm'],
        'intervals' => [
            'monthly' => ['base_price_net' => 2900, 'seat_price_net' => 500, 'included_usages' => ['Tokens' => 100]],
            'yearly' => ['base_price_net' => 29000, 'seat_price_net' => 5000, 'included_usages' => ['Tokens' => 1200]],
        ],
    ]);
    config()->set('mollie-billing-plans.usage_types', ['Tokens' => ['rollover' => false]]);
    config()->set('mollie-billing-plans.addons.sso', [
        'name' => 'SSO',
        'feature_keys' => [],
        'intervals' => ['monthly' => ['price_net' => 1500], 'yearly' => ['price_net' => 15000]],
    ]);
    // Priced per seat: 10.00 base, 2.00 for every billed seat beyond its own 3 free ones.
    config()->set('mollie-billing-plans.addons.crm', [
        'name' => 'CRM',
        'feature_keys' => [],
        'included_seats' => 3,
        'intervals' => ['monthly' => ['price_net' => 1000, 'seat_price_net' => 200]],
    ]);

    Mollie::shouldReceive('setIdempotencyKey')->andReturnUsing(function (mixed $key = null) {
        if (LongRunWorld::$current !== null) {
            LongRunWorld::$current->idempotencyKeys[] = $key;
            LongRunWorld::$current->idempotency->setKey($key);
        }

        return Mollie::getFacadeRoot();
    });
    $answer = function ($request) {
        $world = LongRunWorld::$current;

        if ($world === null) {
            throw new RuntimeException('Mollie was called before the long run started.');
        }

        $world->requests[] = $request;

        if ($world->intercept !== null && ($answer = ($world->intercept)($request)) !== null) {
            return $answer;
        }

        foreach ($world->refuse as $class => $times) {
            if ($request instanceof $class && $times > 0) {
                $world->refuse[$class] = $times === PHP_INT_MAX ? $times : $times - 1;

                throw new RuntimeException('Mollie is unavailable ('.class_basename($class).').');
            }
        }

        if ($request instanceof CreateSubscriptionRequest) {
            // Mollie keeps a customer's descriptions unique among its active subscriptions.
            if (! $world->cancelled && $world->heldValue !== null
                && $world->description === (string) longRunRequestProperty($request, 'description')) {
                throw longRunMollieRefusal('A subscription with the same description already exists for this customer.');
            }

            $world->subscriptionId = $world->subscriptionsCreated === 0 ? 'sub_longrun' : 'sub_longrun_'.($world->subscriptionsCreated + 1);

            if ($world->subscriptionsCreated > 0 && ($replacementStart = longRunDate(longRunRequestProperty($request, 'startDate'))) !== null) {
                $world->moves[] = $replacementStart->toDateString();
            }
            $world->description = (string) longRunRequestProperty($request, 'description');
            $world->charged = false;
            $world->heldValue = (string) longRunRequestProperty($request, 'amount')->value;
            $world->cadenceStart = longRunDate(longRunRequestProperty($request, 'startDate'));
            $world->cadenceCount = 0;
            $world->mollieInterval = (string) longRunRequestProperty($request, 'interval');
            $world->subscriptionMetadata = (array) longRunRequestProperty($request, 'metadata');
            $world->cancelled = false;
            $world->subscriptionsCreated++;

            if (($world->loseAnswer[CreateSubscriptionRequest::class] ?? 0) > 0) {
                $world->loseAnswer[CreateSubscriptionRequest::class]--;
                // Mollie created it; a retry with the same key gets it back.
                $world->idempotency->answerLost((object) ['id' => $world->subscriptionId]);

                throw new RuntimeException('Connection reset — the answer was lost.');
            }

            return (object) ['id' => $world->subscriptionId];
        }

        if ($request instanceof \Mollie\Api\Http\Requests\GetSubscriptionRequest) {
            return longRunSubscriptionObject($world);
        }

        if ($request instanceof \Mollie\Api\Http\Requests\GetPaymentRequest) {
            return $world->payments[(string) longRunRequestProperty($request, 'id')]
                ?? throw new RuntimeException('Unknown payment.');
        }

        if ($request instanceof \Mollie\Api\Http\Requests\GetPaginatedSubscriptionsRequest) {
            return $world->heldValue === null || $world->cancelled ? [] : [longRunSubscriptionObject($world)];
        }

        if ($request instanceof UpdateSubscriptionRequest) {
            $amount = longRunRequestProperty($request, 'amount');
            $start = longRunDate(longRunRequestProperty($request, 'startDate'));

            if ($world->cancelled) {
                throw longRunMollieRefusal('The subscription has been cancelled.');
            }

            // The real rule: once charged, only the start date it already has passes.
            if ($start !== null && $world->charged && ! $start->equalTo($world->cadenceStart)) {
                throw longRunMollieRefusal('The start date cannot be changed when the subscription is already charged.');
            }
            $metadata = longRunRequestProperty($request, 'metadata');
            $interval = longRunRequestProperty($request, 'interval');

            if ($amount !== null) {
                $world->heldValue = (string) $amount->value;
            }
            if ($start !== null) {
                $world->cadenceStart = $start;
                $world->cadenceCount = 0;
                $world->moves[] = $start->toDateString();
            }
            if (is_array($metadata)) {
                $world->subscriptionMetadata = $metadata;
            }
            if (is_string($interval) && $interval !== '') {
                $world->mollieInterval = $interval;
            }

            $world->patches[] = [
                'amount' => $amount?->value,
                'startDate' => $start?->toDateString(),
                'at' => BillingTime::nowUtc()->toIso8601String(),
            ];

            return (object) ['id' => $world->subscriptionId, 'nextPaymentDate' => $world->nextChargeAt()?->toDateString()];
        }

        if ($request instanceof CancelSubscriptionRequest) {
            if ($world->cancelled) {
                throw longRunMollieRefusal('The subscription has been cancelled.');
            }

            $world->cancelled = true;

            return (object) ['id' => $world->subscriptionId, 'status' => 'canceled'];
        }

        if ($request instanceof CreatePaymentRequest) {
            return (object) ['id' => 'tr_side_'.(++$world->sequence), 'status' => 'pending'];
        }

        if ($request instanceof CreatePaymentRefundRequest) {
            $paymentId = (string) longRunRequestProperty($request, 'paymentId');
            $amount = longRunRequestProperty($request, 'amount');
            $refund = (object) [
                'id' => 're_longrun_'.(++$world->sequence),
                'status' => 'pending',
                'amount' => (object) ['value' => $amount->value, 'currency' => $amount->currency],
            ];

            // Mollie lists the refund on the payment and adds it to the refunded total.
            $world->refunds[$paymentId][] = $refund;

            if (isset($world->payments[$paymentId])) {
                $total = array_sum(array_map(static fn (object $r): float => (float) $r->amount->value, $world->refunds[$paymentId]));
                $world->payments[$paymentId]->amountRefunded = (object) ['value' => number_format($total, 2, '.', ''), 'currency' => $amount->currency];
            }

            return $refund;
        }

        return (object) [
            'id' => $world->subscriptionId,
            'status' => $world->cancelled ? 'canceled' : 'active',
            'nextPaymentDate' => $world->nextChargeAt()?->toDateString(),
            'amount' => (object) ['value' => $world->heldValue, 'currency' => LongRunWorld::$currency],
        ];
    };

    // A request answered from Mollie's idempotency memory was sent all the same.
    Mollie::shouldReceive('send')->andReturnUsing(fn ($request) => LongRunWorld::$current === null
        ? $answer($request)
        : LongRunWorld::$current->idempotency->answer($request, $answer, function (object $request): void {
            LongRunWorld::$current->requests[] = $request;
        }));
}

/**
 * Sign a customer up through the real first-payment webhook at `$at`.
 *
 * @param  array<string, mixed>  $checkout  extra checkout metadata: `addon_codes`, `extra_seats`, `coupon_code`
 */
function longRunStart(string $interval, string $at, string $firstGross, array $checkout = [], array $billableAttributes = [], bool $expectActivated = true, ?callable $beforeDelivery = null): LongRunWorld
{
    Carbon::setTestNow(Carbon::parse($at, 'UTC'));

    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Long Run Co',
        'email' => uniqid('longrun-').'@x.test',
        'billing_country' => 'DE',
    ]);
    $billable->forceFill(['mollie_customer_id' => 'cst_longrun'] + $billableAttributes)->save();
    longRunValidateVat($billable->refresh());

    $world = LongRunWorld::$current = new LongRunWorld($billable, $interval);

    $world->payments['tr_lr_first'] = LongRunPayment::make([
        'id' => 'tr_lr_first',
        'status' => 'paid',
        'amount' => (object) ['value' => $firstGross, 'currency' => LongRunWorld::$currency],
        'createdAt' => BillingTime::nowUtc()->toIso8601String(),
        'paidAt' => BillingTime::nowUtc()->toIso8601String(),
        'subscriptionId' => null,
        'customerId' => 'cst_longrun',
        'mandateId' => 'mdt_longrun',
        'countryCode' => 'DE',
        'metadata' => [
            'type' => 'subscription',
            'plan_code' => 'pro',
            'interval' => $interval,
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ] + $checkout,
    ]);

    if ($beforeDelivery !== null) {
        $beforeDelivery($world);
    }

    $response = test()->postJson(route('billing.webhook'), ['id' => 'tr_lr_first']);

    $world->billable = $billable->fresh();
    $world->lastInvoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_lr_first')->first();

    if (! $expectActivated) {
        return $world;
    }

    $response->assertStatus(200);

    longRunAssertDocumentAddsUp($world->lastInvoice, $firstGross, 'first payment');

    expect($world->heldValue)->not->toBeNull('CreateSubscription was never sent');

    return $world;
}

/**
 * Let Mollie create its next recurring charge, without delivering anything yet.
 *
 * `$settlesAfter` is the time the money takes: `'0 days'` for a card, days for SEPA.
 */
function longRunCreateCharge(LongRunWorld $world, string $settlesAfter = '90 seconds', string $status = 'paid'): ?object
{
    $cycle = $world->nextChargeAt();

    if ($cycle === null) {
        return null;
    }

    $world->cadenceCount++;
    $world->lastCycle = $cycle;
    $world->charged = true;

    $id = 'tr_lr_'.(++$world->sequence);

    return $world->payments[$id] = LongRunPayment::make([
        'id' => $id,
        'status' => $status,
        // What Mollie held at the moment it created the charge.
        'amount' => (object) ['value' => $world->heldValue, 'currency' => LongRunWorld::$currency],
        'createdAt' => $cycle->toIso8601String(),
        'paidAt' => $status === 'paid' ? $cycle->modify('+'.$settlesAfter)->toIso8601String() : null,
        'subscriptionId' => $world->subscriptionId,
        'customerId' => 'cst_longrun',
        'mandateId' => 'mdt_longrun',
        'countryCode' => 'DE',
        'metadata' => $world->subscriptionMetadata,
    ]);
}

/** Deliver a payment's webhook at the moment it settled (or failed). */
function longRunDeliver(LongRunWorld $world, object $payment, ?string $at = null): void
{
    $moment = $at ?? $payment->paidAt ?? $payment->createdAt;

    Carbon::setTestNow(Carbon::parse((string) $moment, 'UTC'));

    test()->postJson(route('billing.webhook'), ['id' => $payment->id])->assertStatus(200);

    $world->billable = $world->billable->fresh();
}

/** Charge and deliver in one step — the ordinary renewal. */
function longRunRenew(LongRunWorld $world, string $settlesAfter = '90 seconds'): ?object
{
    $payment = longRunCreateCharge($world, $settlesAfter);

    if ($payment !== null) {
        longRunDeliver($world, $payment);
    }

    return $payment;
}

/**
 * The document adds up, and to what was paid: header == Σ lines == the payment, with no
 * `adjustment` line papering over a difference.
 */
function longRunAssertDocumentAddsUp(BillingInvoice $invoice, string $paidGross, string $what): void
{
    $lines = collect((array) $invoice->line_items);

    expect(in_array('adjustment', $lines->pluck('kind')->all(), true))->toBeFalse("{$what}: the invoice carries an adjustment line")
        ->and((int) $invoice->amount_gross)->toBe(BillingMoney::minorFromMollieAmount($paidGross, LongRunWorld::$currency), "{$what}: invoice gross != amount paid")
        ->and((int) $lines->sum('amount_net'))->toBe((int) $invoice->amount_net, "{$what}: Σ line net != header net")
        ->and((int) $lines->sum('vat_amount'))->toBe((int) $invoice->amount_vat, "{$what}: Σ line VAT != header VAT")
        ->and((int) $lines->sum('amount_gross'))->toBe((int) $invoice->amount_gross, "{$what}: Σ line gross != header gross")
        ->and((int) $invoice->amount_net + (int) $invoice->amount_vat)->toBe((int) $invoice->amount_gross, "{$what}: net + VAT != gross");
}

function longRunSerialCounter(BillingInvoice $invoice): int
{
    return (int) substr((string) $invoice->serial_number, -6);
}

/**
 * Everything a renewal has to leave behind, checked against the charge that caused it.
 *
 * @param  ?string  $expectedGross  what the test says this period costs — the independent
 *                                  figure. Mollie's own amount is checked either way.
 * @param  int  $gapToleranceDays   how far this period's start may sit from the previous
 *                                  period's end
 */
function longRunAssertRenewal(LongRunWorld $world, object $payment, ?string $expectedGross = null, int $gapToleranceDays = 1): BillingInvoice
{
    $what = "renewal {$payment->id} (cycle {$payment->createdAt})";
    $invoice = BillingInvoice::query()->where('mollie_payment_id', $payment->id)->first();

    expect($invoice)->not->toBeNull("{$what}: no invoice");

    if ($expectedGross !== null) {
        expect($payment->amount->value)->toBe($expectedGross, "{$what}: Mollie charged something other than the period's price");
    }

    longRunAssertDocumentAddsUp($invoice, $payment->amount->value, $what);

    $cycle = CarbonImmutable::parse($payment->createdAt, 'UTC');
    $billable = $world->billable->fresh();
    $interval = $world->mollieInterval === '12 months' ? 'yearly' : 'monthly';

    // The invoice states the period the payment bought, and the billable is in it.
    expect(abs($invoice->period_start->diffInHours($cycle, false)))->toBeLessThanOrEqual(24 * 6, "{$what}: period does not start near the cycle")
        // One interval, on the subscription's anchor day (31 Jan → 28 Feb → 31 Mar).
        ->and($invoice->period_end->toIso8601String())->toBe(\GraystackIT\MollieBilling\Support\BillingAnchor::next($invoice->period_start, $interval, \GraystackIT\MollieBilling\Support\BillingAnchor::day($billable))->toIso8601String(), "{$what}: period is not one interval long")
        ->and($billable->getBillingPeriodStartsAt()->toIso8601String())->toBe($invoice->period_start->toIso8601String(), "{$what}: the anchor is not the invoiced period's start");

    // No hole and no overlap between this period and the one before it.
    $previous = $world->lastInvoice;
    if ($previous !== null) {
        expect(abs($previous->period_end->diffInHours($invoice->period_start, false)))
            ->toBeLessThanOrEqual(24 * $gapToleranceDays, "{$what}: gap or overlap against the previous period ({$previous->period_end->toIso8601String()} → {$invoice->period_start->toIso8601String()})");

    }

    longRunAssertSerialFollows($invoice, $what);

    $world->lastInvoice = $invoice;
    $world->billable = $billable;

    return $invoice;
}

/** Charge-side invoices only, oldest first. */
function longRunInvoices(LongRunWorld $world): \Illuminate\Support\Collection
{
    return BillingInvoice::query()
        ->where('billable_id', $world->billable->getKey())
        ->where('invoice_kind', '!=', InvoiceKind::Refund)
        ->orderBy('id')
        ->get();
}

/**
 * Sign a customer up through the €0 mandate checkout — the route a fully covering
 * coupon takes, since there is nothing to pay.
 */
function longRunStartMandateOnly(string $interval, string $at, string $couponCode): LongRunWorld
{
    Carbon::setTestNow(Carbon::parse($at, 'UTC'));

    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Long Run Free Co',
        'email' => uniqid('longrun-free-').'@x.test',
        'billing_country' => 'DE',
    ]);
    $billable->forceFill(['mollie_customer_id' => 'cst_longrun'])->save();

    $world = LongRunWorld::$current = new LongRunWorld($billable, $interval);

    $world->payments['tr_lr_mandate'] = LongRunPayment::make([
        'id' => 'tr_lr_mandate',
        'status' => 'paid',
        'amount' => (object) ['value' => '0.00', 'currency' => LongRunWorld::$currency],
        'createdAt' => BillingTime::nowUtc()->toIso8601String(),
        'paidAt' => BillingTime::nowUtc()->toIso8601String(),
        'subscriptionId' => null,
        'customerId' => 'cst_longrun',
        'mandateId' => 'mdt_longrun',
        'countryCode' => 'DE',
        'metadata' => [
            'type' => 'mandate_only',
            'pending_subscription_plan_code' => 'pro',
            'pending_subscription_interval' => $interval,
            'pending_subscription_coupon_code' => $couponCode,
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ]);

    test()->postJson(route('billing.webhook'), ['id' => 'tr_lr_mandate'])->assertStatus(200);

    $world->billable = $billable->fresh();
    $world->lastInvoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_lr_mandate')->sole();

    longRunAssertDocumentAddsUp($world->lastInvoice, '0.00', 'mandate-only signup');

    return $world;
}

/** One night of the scheduler: the pass that renews a period Mollie does not charge for. */
function longRunNight(LongRunWorld $world, string $at): void
{
    Carbon::setTestNow(Carbon::parse($at, 'UTC'));

    app()->call([new \GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob, 'handle']);

    $world->billable = $world->billable->fresh();
}

/**
 * A tenant who is GIVEN Pro for `$days` days and then buys paid extras on top — the
 * route that puts a date-based plan waiver on a Mollie subscription.
 *
 * @param  list<string>  $addons
 */
function longRunStartGifted(string $giftedAt, int $days, ?string $onExpiry, string $purchaseAt, array $addons, int $seats): LongRunWorld
{
    Carbon::setTestNow(Carbon::parse($giftedAt, 'UTC'));

    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Long Run Gift Co',
        'email' => uniqid('longrun-gift-').'@x.test',
        'billing_country' => 'DE',
    ]);
    $billable->forceFill(['mollie_customer_id' => 'cst_longrun'])->save();

    $coupons = app(\GraystackIT\MollieBilling\Services\Billing\CouponService::class);
    $code = 'GIFT-'.strtoupper(bin2hex(random_bytes(3)));
    $coupon = $coupons->accessGrantCoupon($code, 'pro', 'monthly', [], $days, $onExpiry);
    $coupons->validate($code, $billable->fresh(), []);
    $coupons->redeem($coupon, $billable->fresh(), []);

    $world = LongRunWorld::$current = new LongRunWorld($billable->fresh(), 'monthly');

    Carbon::setTestNow(Carbon::parse($purchaseAt, 'UTC'));

    $resolver = Mockery::mock(\GraystackIT\MollieBilling\Support\MollieCustomerResolver::class);
    $resolver->shouldReceive('resolve')->andReturn('cst_longrun');
    app()->instance(\GraystackIT\MollieBilling\Support\MollieCustomerResolver::class, $resolver);

    app(\GraystackIT\MollieBilling\Services\Billing\UpgradeLocalToMollie::class)
        ->handleKeepingGrant($billable->fresh(), $addons, $seats);

    $request = collect($world->requests)->last(fn ($r) => $r instanceof CreatePaymentRequest);
    $paymentId = 'tr_side_'.$world->sequence;

    $world->payments[$paymentId] = LongRunPayment::make([
        'id' => $paymentId,
        'status' => 'paid',
        'amount' => (object) ['value' => longRunRequestProperty($request, 'amount')->value, 'currency' => LongRunWorld::$currency],
        'createdAt' => BillingTime::nowUtc()->toIso8601String(),
        'paidAt' => BillingTime::nowUtc()->toIso8601String(),
        'subscriptionId' => null,
        'customerId' => 'cst_longrun',
        'mandateId' => 'mdt_longrun',
        'countryCode' => 'DE',
        'metadata' => longRunRequestProperty($request, 'metadata'),
    ]);

    test()->postJson(route('billing.webhook'), ['id' => $paymentId])->assertStatus(200);

    $world->billable = $billable->fresh();
    $world->lastInvoice = BillingInvoice::query()->where('mollie_payment_id', $paymentId)->sole();

    longRunAssertDocumentAddsUp($world->lastInvoice, $world->payments[$paymentId]->amount->value, 'gift extras purchase');

    return $world;
}


/**
 * Consecutive serials across every charge document, whatever wrote it; the counter
 * restarts with the year.
 */
function longRunAssertSerialFollows(BillingInvoice $invoice, string $what): void
{
    $before = BillingInvoice::query()
        ->where('invoice_kind', '!=', InvoiceKind::Refund)
        ->where('id', '<', $invoice->id)
        ->orderByDesc('id')
        ->first();

    if ($before === null) {
        return;
    }

    $sameYear = substr((string) $before->serial_number, 0, -6) === substr((string) $invoice->serial_number, 0, -6);

    expect(longRunSerialCounter($invoice))->toBe(
        $sameYear ? longRunSerialCounter($before) + 1 : 1,
        "{$what}: serial {$invoice->serial_number} does not follow {$before->serial_number}",
    );
}

/**
 * Settle the one-off charge the package just created (a prorata charge for a seat or
 * addon bought mid-period) and deliver its webhook.
 */
function longRunSettleSideCharge(LongRunWorld $world, string $at): BillingInvoice
{
    $request = collect($world->requests)->last(fn ($r) => $r instanceof CreatePaymentRequest);
    $paymentId = 'tr_side_'.$world->sequence;

    expect($request)->not->toBeNull('no one-off charge was created');

    Carbon::setTestNow(Carbon::parse($at, 'UTC'));

    $world->payments[$paymentId] = LongRunPayment::make([
        'id' => $paymentId,
        'status' => 'paid',
        'amount' => (object) ['value' => longRunRequestProperty($request, 'amount')->value, 'currency' => LongRunWorld::$currency],
        'createdAt' => BillingTime::nowUtc()->toIso8601String(),
        'paidAt' => BillingTime::nowUtc()->toIso8601String(),
        'subscriptionId' => null,
        'customerId' => 'cst_longrun',
        'mandateId' => 'mdt_longrun',
        'countryCode' => 'DE',
        'metadata' => longRunRequestProperty($request, 'metadata'),
    ]);

    test()->postJson(route('billing.webhook'), ['id' => $paymentId])->assertStatus(200);

    $world->billable = $world->billable->fresh();

    $invoice = BillingInvoice::query()->where('mollie_payment_id', $paymentId)->sole();
    longRunAssertDocumentAddsUp($invoice, $world->payments[$paymentId]->amount->value, 'one-off charge '.$paymentId);
    longRunAssertSerialFollows($invoice, 'one-off charge '.$paymentId);

    return $invoice;
}


/**
 * The three VAT situations every money path is run through: German consumer (19 %),
 * Austrian consumer (20 %), and a German business with a validated VAT number bought
 * from an Austrian seller — reverse charge, no VAT.
 *
 * @return array<string, array{0: array<string, mixed>, 1: string, 2: int}>  billable attributes, Pro monthly gross, VAT in cents
 */
function longRunVatCases(): array
{
    return [
        'DE 19 %' => [['billing_country' => 'DE'], '34.51', 551],
        'AT 20 %' => [['billing_country' => 'AT'], '34.80', 580],
        'reverse charge' => [['billing_country' => 'DE', 'vat_number' => 'DE123456789'], '29.00', 0],
    ];
}

function longRunValidateVat(TestBillable $billable): void
{
    if (($billable->vat_number ?? null) === null) {
        return;
    }

    config()->set('mollie-billing.vat.seller_country', 'AT');

    \GraystackIT\MollieBilling\Models\BillingVatValidation::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'vat_number' => (string) $billable->vat_number,
        'country_code' => substr((string) $billable->vat_number, 0, 2),
        'valid' => true,
        'vies_response' => [],
        'checked_at' => now(),
    ]);
}
