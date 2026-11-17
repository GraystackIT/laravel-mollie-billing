<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Support;

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingVatValidation;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Mollie\Api\Http\Requests\CreateCustomerRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;
use PHPUnit\Framework\Assert;

/**
 * Webhook controller whose "fetch from Mollie" is answered from the harness.
 */
class BookingMatrixWebhookController extends MollieWebhookController
{
    /** @var array<string, object> */
    public static array $payments = [];

    protected function fetchPayment(string $paymentId): object
    {
        return self::$payments[$paymentId] ?? throw new \RuntimeException("No stub for {$paymentId}.");
    }
}

/**
 * One booking, start to first renewal, against a faked Mollie.
 *
 * The chain every booking path has to hold:
 *
 *   amount shown == amount sent to Mollie == invoice (lines, net, VAT, gross)
 *                == amount of the Mollie subscription == first renewal invoice
 *
 * with no `adjustment` line and no `PaymentAmountMismatch` anywhere on the way. The
 * harness plays Mollie: it answers every request, keeps them, and delivers the webhooks a
 * paid payment and a renewal produce — with exactly the amount the package asked for.
 */
final class BookingMatrix
{
    /** @var list<object> every request the package sent to Mollie, in order */
    public array $sent = [];

    private int $sequence = 0;

    /** @var array<string, list<object>> refunds the package created, by payment id */
    public array $refunds = [];

    /** @var array<int, string> VAT numbers the faked VIES confirms */
    private array $validVatNumbers = [];

    public static function boot(): self
    {
        $matrix = new self;

        Storage::fake('local');
        config()->set('mollie-billing.invoices.disk', 'local');
        config()->set('mollie-billing.vat.seller_country', 'AT');
        config()->set('mollie-billing.additional_countries', []);

        // The wallet's balance cache expires after a day and, inside RefreshDatabase's
        // never-committing transaction, is the only place a balance lives: renewing a
        // month later would silently zero every wallet. In production it is in the DB.
        config()->set('wallet.cache.ttl', null);

        Notification::fake();
        Event::fake([PaymentAmountMismatch::class]);

        BookingMatrixWebhookController::$payments = [];
        app()->bind(MollieWebhookController::class, BookingMatrixWebhookController::class);

        MollieBilling::authUsing(fn (): bool => true);

        // VIES answers from a list; rates stay the real library's.
        app()->instance(
            \Mpociot\VatCalculator\VatCalculator::class,
            new \GraystackIT\MollieBilling\Testing\FakeVatCalculator($matrix->validVatNumbers),
        );
        app()->forgetInstance(VatCalculationService::class);

        self::catalog();

        Mollie::shouldReceive('send')->andReturnUsing(fn ($request) => $matrix->answer($request));
        // Idempotency keys are set and cleared on the client around refunds and charges.
        Mollie::getFacadeRoot()->shouldIgnoreMissing();

        return $matrix;
    }

    /**
     * Prices chosen so that per-line VAT and VAT on the summed net differ by a cent at
     * 19 % and at 20 % once two lines are involved (2950 + 1550, 2950 + 555).
     */
    public static function catalog(): void
    {
        config()->set('mollie-billing-plans.plans', [
            'free' => [
                'name' => 'Free', 'tier' => 0, 'included_seats' => 1, 'feature_keys' => [], 'allowed_addons' => ['sso'],
                'intervals' => [
                    'monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []],
                ],
            ],
            'pro' => [
                'name' => 'Pro', 'tier' => 2, 'included_seats' => 2, 'feature_keys' => [], 'allowed_addons' => ['sso', 'crm'],
                'intervals' => [
                    'monthly' => ['base_price_net' => 2950, 'seat_price_net' => 555, 'included_usages' => []],
                    'yearly' => ['base_price_net' => 29950, 'seat_price_net' => 5555, 'included_usages' => []],
                ],
            ],
            'team' => [
                'name' => 'Team', 'tier' => 1, 'included_seats' => 1, 'feature_keys' => [], 'allowed_addons' => ['sso'],
                'intervals' => [
                    'monthly' => ['base_price_net' => 1950, 'seat_price_net' => 355, 'trial_days' => 14, 'included_usages' => []],
                ],
            ],
            'solo' => [
                'name' => 'Solo', 'tier' => 1, 'included_seats' => 1, 'feature_keys' => [], 'allowed_addons' => [],
                'intervals' => [
                    'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => []],
                ],
            ],
        ]);

        config()->set('mollie-billing-plans.addons', [
            'sso' => [
                'name' => 'SSO', 'feature_keys' => [],
                'intervals' => ['monthly' => ['price_net' => 1550], 'yearly' => ['price_net' => 15550]],
            ],
            // Priced per seat: 10.00 base, 3.33 for every billed seat beyond its own free one.
            'crm' => [
                'name' => 'CRM', 'feature_keys' => [], 'included_seats' => 1,
                'intervals' => [
                    'monthly' => ['price_net' => 1000, 'seat_price_net' => 333],
                    'yearly' => ['price_net' => 10000, 'seat_price_net' => 3333],
                ],
            ],
        ]);

        config()->set('mollie-billing-plans.products', [
            'pack' => ['name' => 'Token pack', 'price_net' => 4950],
            'audit' => ['name' => 'Audit', 'price_net' => 1550],
        ]);
    }

    // ── Mollie ──────────────────────────────────────────────────────────────────

    /**
     * Lets a test stand in for Mollie on one request: return an object to answer with it,
     * throw to fail the call, or return null to let the harness answer as usual.
     *
     * @var (\Closure(object): ?object)|null
     */
    public ?\Closure $intercept = null;

    public function answer(object $request): object
    {
        $this->sent[] = $request;
        $n = ++$this->sequence;

        if ($this->intercept !== null && ($answer = ($this->intercept)($request)) !== null) {
            return $answer;
        }

        if ($request instanceof \Mollie\Api\Http\Requests\CreatePaymentRefundRequest) {
            $refund = (object) [
                'id' => 're_bm_'.$n,
                'status' => 'pending',
                'amount' => (object) ['value' => (string) self::prop($request, 'amount')->value, 'currency' => 'EUR'],
            ];
            $this->refunds[(string) self::prop($request, 'paymentId')][] = $refund;

            return $refund;
        }

        if ($request instanceof CreatePaymentRequest) {
            return new class('tr_bm_'.$n)
            {
                public string $status = 'open';

                public function __construct(public string $id)
                {
                }

                public function getCheckoutUrl(): string
                {
                    return 'https://mollie.test/checkout/'.$this->id;
                }
            };
        }

        if ($request instanceof GetPaymentRequest) {
            return (object) ['id' => 'tr_unknown', 'status' => 'expired'];
        }

        if ($request instanceof CreateCustomerRequest) {
            return (object) ['id' => 'cst_bm'];
        }

        if ($request instanceof CreateSubscriptionRequest) {
            return (object) ['id' => 'sub_bm_'.$n, 'status' => 'active'];
        }

        return (object) ['id' => 'obj_bm_'.$n, 'status' => 'active'];
    }

    /** @return list<object> */
    public function sentOf(string $class): array
    {
        return array_values(array_filter($this->sent, fn (object $r): bool => $r instanceof $class));
    }

    public function lastPayment(): ?CreatePaymentRequest
    {
        $all = $this->sentOf(CreatePaymentRequest::class);

        return $all === [] ? null : end($all);
    }

    public function lastSubscription(): ?CreateSubscriptionRequest
    {
        $all = $this->sentOf(CreateSubscriptionRequest::class);

        return $all === [] ? null : end($all);
    }

    /** The first-charge date (Y-m-d) the last created Mollie subscription was given. */
    public function subscriptionStartDate(): ?string
    {
        $subscription = $this->lastSubscription();

        return $subscription === null ? null : (string) self::prop($subscription, 'startDate');
    }

    public static function prop(object $request, string $name): mixed
    {
        $p = (new \ReflectionClass($request))->getProperty($name);
        $p->setAccessible(true);

        return $p->getValue($request);
    }

    /** Amount of a payment / subscription request in minor units. */
    public static function minor(object $request): int
    {
        return BillingMoney::minorFromMollieAmount((string) self::prop($request, 'amount')->value, 'EUR');
    }

    /**
     * The amount the Mollie subscription holds right now: what it was created with,
     * unless a later PATCH carried a new amount.
     */
    public function subscriptionMinor(): ?int
    {
        $amount = null;

        foreach ($this->sent as $request) {
            if ($request instanceof CreateSubscriptionRequest) {
                $amount = self::minor($request);
            }

            if ($request instanceof UpdateSubscriptionRequest && self::prop($request, 'amount') !== null) {
                $amount = self::minor($request);
            }
        }

        return $amount;
    }

    // ── Billables ───────────────────────────────────────────────────────────────

    /**
     * A billable with a complete address — so the checkout locks onto it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function billable(string $country = 'AT', array $attributes = []): TestBillable
    {
        /** @var TestBillable $billable */
        $billable = TestBillable::create(['name' => 'Matrix GmbH', 'email' => uniqid('bm-').'@x.test']);
        $billable->forceFill(array_merge([
            'subscription_status' => SubscriptionStatus::New,
            'billing_street' => 'Teststr. 1',
            'billing_postal_code' => '1010',
            'billing_city' => 'Wien',
            'billing_country' => $country,
            'tax_country_user' => $country,
            'mollie_customer_id' => 'cst_bm',
        ], $attributes))->save();

        $billable = $billable->refresh();
        MollieBilling::resolveBillableUsing(fn () => $billable->fresh());

        return $billable;
    }

    /** A business customer whose VAT number is on file as VIES-valid. */
    public function business(string $country, string $vatNumber): TestBillable
    {
        $this->validVatNumbers[] = $vatNumber;
        $billable = $this->billable($country, ['vat_number' => $vatNumber]);

        $validation = new BillingVatValidation;
        $validation->billable_type = $billable->getMorphClass();
        $validation->billable_id = $billable->getKey();
        $validation->vat_number = $vatNumber;
        $validation->country_code = substr($vatNumber, 0, 2);
        $validation->valid = true;
        $validation->vies_response = [];
        $validation->checked_at = now();
        $validation->save();

        return $billable->refresh();
    }

    // ── Checkout ────────────────────────────────────────────────────────────────

    /**
     * The checkout component for the resolved billable, with a selection made.
     *
     * @param  array<int, string>  $addons
     */
    public function checkout(string $plan, string $interval = 'monthly', array $addons = [], int $extraSeats = 0, ?string $coupon = null): Testable
    {
        $component = Livewire::test('mollie-billing::checkout')
            ->set('plan_code', $plan)
            ->set('interval', $interval)
            ->set('addon_codes', $addons)
            ->set('extra_seats', $extraSeats);

        if ($coupon !== null) {
            $component->set('coupon_input', $coupon)->call('applyCoupon');
        }

        return $component;
    }

    /** @return array{net:int,vat:int,gross:int,rate:float,reverseCharge:bool} */
    public function shown(Testable $component): array
    {
        return $component->instance()->totals(app(VatCalculationService::class));
    }

    // ── Webhooks ────────────────────────────────────────────────────────────────

    /**
     * Mollie reports the payment the package asked for as paid — with the amount and the
     * metadata of that very request.
     */
    public function settle(CreatePaymentRequest $request, string $country = 'AT', int $expectStatus = 200, ?string $id = null): ?BillingInvoice
    {
        // A fixed id per request object, so settling the same request twice is a
        // re-delivery of the same payment and not a second payment.
        $id ??= 'tr_paid_'.spl_object_id($request);

        // Like Mollie's own payment resource, it can list its refunds — the ones the
        // package has created for it so far.
        BookingMatrixWebhookController::$payments[$id] = new class($this, $id, (string) self::prop($request, 'amount')->value, $country, (array) self::prop($request, 'metadata'))
        {
            public string $status = 'paid';

            public object $amount;

            public string $paidAt;

            public ?string $subscriptionId = null;

            public string $customerId = 'cst_bm';

            public string $mandateId = 'mdt_bm';

            /** @param  array<string, mixed>  $metadata */
            public function __construct(private BookingMatrix $matrix, public string $id, string $value, public string $countryCode, public array $metadata)
            {
                $this->amount = (object) ['value' => $value, 'currency' => 'EUR'];
                $this->paidAt = BillingTime::nowUtc()->toIso8601String();
            }

            /** @return list<object> */
            public function refunds(): array
            {
                return $this->matrix->refunds[$this->id] ?? [];
            }
        };

        test()->postJson(route('billing.webhook'), ['id' => $id])->assertStatus($expectStatus);

        return BillingInvoice::query()->where('mollie_payment_id', $id)->first();
    }

    /** The id `settle()` delivers a payment request under. */
    public static function paymentIdFor(CreatePaymentRequest $request): string
    {
        return 'tr_paid_'.spl_object_id($request);
    }

    /** Deliver the webhook for an arbitrary payment object (a refund notification, say). */
    public function deliver(object $payment, int $expectStatus = 200): void
    {
        BookingMatrixWebhookController::$payments[(string) $payment->id] = $payment;

        test()->postJson(route('billing.webhook'), ['id' => (string) $payment->id])->assertStatus($expectStatus);
    }

    /**
     * One interval later Mollie collects the subscription's amount and calls the webhook.
     */
    public function renew(TestBillable $billable, string $interval = 'monthly', string $country = 'AT', ?\Carbon\CarbonInterface $at = null): ?BillingInvoice
    {
        $amount = $this->subscriptionMinor();
        Assert::assertNotNull($amount, 'No Mollie subscription was created, so nothing can renew.');

        $billable->refresh();
        $next = $at ?? $billable->nextBillingDate() ?? BillingTime::addInterval(BillingTime::nowUtc(), $interval);
        Carbon::setTestNow($next->copy()->addMinute());

        $subscription = $this->lastSubscription();
        $id = 'tr_renew_'.(++$this->sequence);

        BookingMatrixWebhookController::$payments[$id] = (object) [
            'id' => $id,
            'status' => 'paid',
            'amount' => (object) ['value' => BillingMoney::mollieAmount($amount, 'EUR'), 'currency' => 'EUR'],
            'paidAt' => BillingTime::nowUtc()->toIso8601String(),
            'subscriptionId' => (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? 'sub_bm'),
            'customerId' => 'cst_bm',
            'mandateId' => 'mdt_bm',
            'sequenceType' => 'recurring',
            'countryCode' => $country,
            'metadata' => $subscription !== null ? (array) self::prop($subscription, 'metadata') : [
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
            ],
        ];

        test()->postJson(route('billing.webhook'), ['id' => $id])->assertStatus(200);

        return BillingInvoice::query()->where('mollie_payment_id', $id)->first();
    }

    // ── Assertions ──────────────────────────────────────────────────────────────

    /**
     * The invoice is exactly its lines, taxed per line at one rate, and nothing had to be
     * reconciled to make it so.
     *
     * @param  array<string, int>  $lines  kind (or kind:code) => net, in any order
     */
    public function assertInvoice(?BillingInvoice $invoice, int $net, int $vat, float $rate, array $lines = []): void
    {
        Assert::assertNotNull($invoice, 'No invoice was written.');

        $items = collect((array) $invoice->line_items);

        Assert::assertNull($items->firstWhere('kind', 'adjustment'), 'The invoice carries an adjustment line: '.json_encode($invoice->line_items));
        Assert::assertSame($net, (int) $invoice->amount_net, 'invoice net');
        Assert::assertSame($vat, (int) $invoice->amount_vat, 'invoice VAT');
        Assert::assertSame($net + $vat, (int) $invoice->amount_gross, 'invoice gross');
        Assert::assertSame($net, (int) $items->sum('amount_net'), 'Σ line net');
        Assert::assertSame($vat, (int) $items->sum('vat_amount'), 'Σ line VAT');

        foreach ($items as $item) {
            Assert::assertEqualsWithDelta($rate, (float) $item['vat_rate'], 0.0001, 'line VAT rate');
            Assert::assertSame((int) round((int) $item['amount_net'] * $rate / 100), (int) $item['vat_amount'], 'line VAT is round(net × rate)');
        }

        foreach ($lines as $key => $expected) {
            [$kind, $code] = array_pad(explode(':', $key, 2), 2, null);
            $line = $items->first(fn (array $i): bool => ($i['kind'] ?? null) === $kind && ($code === null || ($i['code'] ?? null) === $code));
            Assert::assertNotNull($line, "The invoice has no [{$key}] line: ".json_encode($invoice->line_items));
            Assert::assertSame($expected, (int) $line['amount_net'], "net of the [{$key}] line");
        }

        if ($lines !== []) {
            Assert::assertCount(count($lines), $items, 'unexpected extra lines: '.json_encode($invoice->line_items));
        }
    }

    public function assertNoMismatch(): void
    {
        Event::assertNotDispatched(PaymentAmountMismatch::class);
    }
}
