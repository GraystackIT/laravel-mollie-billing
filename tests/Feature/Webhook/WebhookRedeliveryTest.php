<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\InvoiceRefunded;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingProcessedWebhook;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;

/**
 * Mollie re-delivers the webhook of a payment it already reported as paid —
 * most importantly when a refund is created (the status stays `paid`). The
 * reservation must therefore deduplicate on what a delivery actually does,
 * not on the payment id alone.
 */
class RedeliveryFakeWebhookController extends MollieWebhookController
{
    public static ?object $nextPayment = null;

    protected function fetchPayment(string $paymentId): object
    {
        if (self::$nextPayment === null) {
            throw new \RuntimeException('No payment stub configured.');
        }

        return self::$nextPayment;
    }
}

beforeEach(function (): void {
    RedeliveryFakeWebhookController::$nextPayment = null;
    $this->app->bind(MollieWebhookController::class, RedeliveryFakeWebhookController::class);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
});

function redeliveryBillable(): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => 'redelivery@x.test',
        'name' => 'Redelivery',
        'billing_country' => 'DE',
        'mollie_customer_id' => 'cst_rd',
        'mollie_mandate_id' => 'mdt_rd',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_rd'],
    ])->save();

    return $b;
}

function redeliveryPaidInvoice(TestBillable $billable, string $paymentId): BillingInvoice
{
    $invoice = new BillingInvoice;
    $invoice->forceFill([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => $paymentId,
        'mollie_subscription_id' => 'sub_rd',
        'serial_number' => 'IN-26000501',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'DE',
        'currency' => 'EUR',
        'amount_net' => 1000,
        'amount_vat' => 190,
        'amount_gross' => 1190,
        'line_items' => [[
            'kind' => 'plan',
            'code' => 'pro',
            'label' => 'Pro',
            'quantity' => 1,
            'unit_price' => 1000,
            'unit_price_net' => 1000,
            'amount_net' => 1000,
            'total_net' => 1000,
            'vat_rate' => 19.0,
            'vat_amount' => 190,
            'amount_gross' => 1190,
        ]],
        'refunded_net' => 0,
    ])->save();

    return $invoice;
}

/**
 * Payment stub whose refunds() returns dashboard-created refunds, like the SDK.
 */
function redeliveryPaymentWithRefund(TestBillable $billable, string $paymentId, string $refundValue): object
{
    return new class($billable, $paymentId, $refundValue)
    {
        public string $status = 'paid';

        public object $amount;

        public object $amountRefunded;

        public ?string $subscriptionId = 'sub_rd';

        /** @var array<string, mixed> */
        public array $metadata;

        public function __construct(TestBillable $billable, public string $id, private string $refundValue)
        {
            $this->amount = (object) ['value' => '11.90', 'currency' => 'EUR'];
            $this->amountRefunded = (object) ['value' => $refundValue, 'currency' => 'EUR'];
            $this->metadata = [
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
            ];
        }

        /** @return array<int, object> */
        public function refunds(): array
        {
            return [(object) [
                'id' => 're_dashboard_1',
                'amount' => (object) ['value' => $this->refundValue, 'currency' => 'EUR'],
            ]];
        }
    };
}

it('processes a refund re-delivery of an already-paid payment and writes the credit note', function (): void {
    Event::fake([InvoiceRefunded::class]);

    $billable = redeliveryBillable();
    $original = redeliveryPaidInvoice($billable, 'tr_rd_refund');

    // The paid delivery already ran to completion.
    BillingProcessedWebhook::create([
        'mollie_payment_id' => 'tr_rd_refund',
        'event_signature' => BillingProcessedWebhook::outcomeSignature('tr_rd_refund', 'paid'),
        'received_at' => BillingTime::nowUtc()->subMinutes(10),
        'processed_at' => BillingTime::nowUtc()->subMinutes(10),
    ]);

    RedeliveryFakeWebhookController::$nextPayment = redeliveryPaymentWithRefund($billable, 'tr_rd_refund', '5.00');

    $this->postJson(route('billing.webhook'), ['id' => 'tr_rd_refund'])->assertStatus(200);

    $creditNote = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->first();
    expect($creditNote)->not->toBeNull();
    expect((int) $original->fresh()->refunded_net)->toBeGreaterThan(0);

    Event::assertDispatched(InvoiceRefunded::class);

    // The refunded delivery is recorded under its own outcome, so the paid row
    // survives and a further refund of a different total can still be processed.
    expect(BillingProcessedWebhook::where('mollie_payment_id', 'tr_rd_refund')->count())->toBe(2);
    expect(
        BillingProcessedWebhook::where('mollie_payment_id', 'tr_rd_refund')
            ->where('event_signature', 'tr_rd_refund:paid:refunded:500')
            ->whereNotNull('processed_at')
            ->exists()
    )->toBeTrue();
});

it('skips a second delivery that reports the very same refund state', function (): void {
    $billable = redeliveryBillable();
    redeliveryPaidInvoice($billable, 'tr_rd_twice');

    RedeliveryFakeWebhookController::$nextPayment = redeliveryPaymentWithRefund($billable, 'tr_rd_twice', '5.00');

    $this->postJson(route('billing.webhook'), ['id' => 'tr_rd_twice'])->assertStatus(200);
    $this->postJson(route('billing.webhook'), ['id' => 'tr_rd_twice'])->assertStatus(200);

    // One credit note, one reservation row: the repeated delivery carried no new information.
    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(1);
    expect(BillingProcessedWebhook::where('mollie_payment_id', 'tr_rd_twice')->count())->toBe(1);
});

it('does not record a non-terminal delivery as processed, so the later paid delivery still runs', function (): void {
    $billable = redeliveryBillable();
    $billable->forceFill([
        'subscription_status' => SubscriptionStatus::PastDue,
        'subscription_meta' => [
            'mollie_subscription_id' => 'sub_rd',
            'past_due_since' => BillingTime::nowUtc()->subDay()->toIso8601String(),
        ],
    ])->save();

    $open = (object) [
        'id' => 'tr_rd_open',
        'status' => 'open',
        'amount' => (object) ['value' => '11.90', 'currency' => 'EUR'],
        'subscriptionId' => 'sub_rd',
        'metadata' => [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];

    RedeliveryFakeWebhookController::$nextPayment = $open;
    $this->postJson(route('billing.webhook'), ['id' => 'tr_rd_open'])->assertStatus(200);

    // Nothing happened, and nothing was claimed as processed.
    expect(BillingProcessedWebhook::where('mollie_payment_id', 'tr_rd_open')->count())->toBe(0);
    expect(BillingInvoice::query()->count())->toBe(0);

    $paid = (object) [
        'id' => 'tr_rd_open',
        'status' => 'paid',
        'amount' => (object) ['value' => '11.90', 'currency' => 'EUR'],
        'paidAt' => BillingTime::nowUtc()->toIso8601String(),
        'subscriptionId' => 'sub_rd',
        'metadata' => $open->metadata,
    ];

    RedeliveryFakeWebhookController::$nextPayment = $paid;
    $this->postJson(route('billing.webhook'), ['id' => 'tr_rd_open'])->assertStatus(200);

    expect(BillingInvoice::query()->where('mollie_payment_id', 'tr_rd_open')->count())->toBe(1);
    expect($billable->fresh()->subscription_status)->toBe(SubscriptionStatus::Active);
});

it('syncs refunds for a one-time order too', function (): void {
    Event::fake([InvoiceRefunded::class]);

    config()->set('mollie-billing-plans.products.consulting', [
        'name' => 'Consulting',
        'price_net' => 10000,
    ]);

    $billable = redeliveryBillable();

    $original = redeliveryPaidInvoice($billable, 'tr_rd_oto');
    $original->forceFill(['invoice_kind' => InvoiceKind::OneTimeOrder])->save();

    $payment = new class($billable)
    {
        public string $id = 'tr_rd_oto';

        public string $status = 'paid';

        public object $amount;

        public object $amountRefunded;

        public ?string $subscriptionId = null;

        /** @var array<string, mixed> */
        public array $metadata;

        public function __construct(TestBillable $billable)
        {
            $this->amount = (object) ['value' => '119.00', 'currency' => 'EUR'];
            $this->amountRefunded = (object) ['value' => '119.00', 'currency' => 'EUR'];
            $this->metadata = [
                'type' => 'one_time_order',
                'product_code' => 'consulting',
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
            ];
        }

        /** @return array<int, object> */
        public function refunds(): array
        {
            return [(object) [
                'id' => 're_oto_full',
                'amount' => (object) ['value' => '119.00', 'currency' => 'EUR'],
            ]];
        }
    };

    RedeliveryFakeWebhookController::$nextPayment = $payment;

    $this->postJson(route('billing.webhook'), ['id' => 'tr_rd_oto'])->assertStatus(200);

    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(1);
    Event::assertDispatched(InvoiceRefunded::class);
});
