<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Events\RefundReversed;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingProcessedWebhook;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminRefundFailedNotification;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * A refund is only a refund if the money left.
 *
 * RefundHandler used to book every refund Mollie lists on a payment, whatever its status:
 * one reported as `failed` or `canceled` returned nothing, yet got a credit note and
 * reduced the invoice's refundable base. And a refund booked while still on its way stayed
 * booked when the bank bounced it afterwards.
 */
class RefundStatusFakeWebhookController extends MollieWebhookController
{
    public static ?object $nextPayment = null;

    protected function fetchPayment(string $paymentId): object
    {
        return self::$nextPayment ?? throw new RuntimeException('No payment stub configured.');
    }
}

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');

    RefundStatusFakeWebhookController::$nextPayment = null;
    $this->app->bind(MollieWebhookController::class, RefundStatusFakeWebhookController::class);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => []]],
    ]);
});

function refundStatusBillable(): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Refund Status Co',
        'email' => uniqid('rs-').'@x.test',
        'billing_country' => 'DE',
    ]);

    $b->forceFill([
        'mollie_customer_id' => 'cst_rs',
        'mollie_mandate_id' => 'mdt_rs',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_rs'],
    ])->save();

    return $b->refresh();
}

/** 10.00 net, 19 % → 11.90 gross. */
function refundStatusInvoice(TestBillable $billable, string $paymentId): BillingInvoice
{
    return BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => $paymentId,
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'DE',
        'currency' => 'EUR',
        'amount_net' => 1000,
        'amount_vat' => 190,
        'amount_gross' => 1190,
        'period_start' => $billable->subscription_period_starts_at,
        'period_end' => $billable->nextBillingDate(),
        'line_items' => [[
            'kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1,
            'unit_price_net' => 1000, 'amount_net' => 1000, 'total_net' => 1000,
            'vat_rate' => 19.0, 'vat_amount' => 190, 'amount_gross' => 1190,
        ]],
    ]);
}

/**
 * @param  array<int, array{id:string, value:string, status:string}>  $refunds
 */
function refundStatusPayment(TestBillable $billable, string $paymentId, string $amountRefunded, array $refunds): object
{
    return new class($billable, $paymentId, $amountRefunded, $refunds)
    {
        public string $status = 'paid';

        public object $amount;

        public object $amountRefunded;

        public ?string $subscriptionId = 'sub_rs';

        /** @var array<string, mixed> */
        public array $metadata;

        public function __construct(TestBillable $billable, public string $id, string $amountRefunded, private array $refundRows)
        {
            $this->amount = (object) ['value' => '11.90', 'currency' => 'EUR'];
            $this->amountRefunded = (object) ['value' => $amountRefunded, 'currency' => 'EUR'];
            $this->metadata = [
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
            ];
        }

        /** @return array<int, object> */
        public function refunds(): array
        {
            return array_map(static fn (array $r): object => (object) [
                'id' => $r['id'],
                'status' => $r['status'],
                'amount' => (object) ['value' => $r['value'], 'currency' => 'EUR'],
            ], $this->refundRows);
        }
    };
}

it('does not book a credit note for a refund Mollie reports as failed', function (): void {
    $billable = refundStatusBillable();
    $invoice = refundStatusInvoice($billable, 'tr_rs_1');

    // Two refunds on the payment: 5.00 went out, 5.95 failed at the bank and never left.
    // Mollie's own total says so: amountRefunded = 5.00.
    RefundStatusFakeWebhookController::$nextPayment = refundStatusPayment($billable, 'tr_rs_1', '5.00', [
        ['id' => 're_ok', 'value' => '5.00', 'status' => 'refunded'],
        ['id' => 're_failed', 'value' => '5.95', 'status' => 'failed'],
    ]);

    $this->postJson(route('billing.webhook'), ['id' => 'tr_rs_1'])->assertStatus(200);

    $creditNotes = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->get();
    $creditedGross = (int) $creditNotes->sum(fn (BillingInvoice $n): int => abs((int) $n->amount_gross));

    // Only the 5.00 that actually left may be credited.
    expect($creditedGross)->toBe(500)
        ->and($creditNotes)->toHaveCount(1)
        ->and($invoice->fresh()->remainingRefundableNet())->toBeGreaterThan(0);
});

it('does not book a credit note for a refund Mollie reports as canceled', function (): void {
    $billable = refundStatusBillable();
    $invoice = refundStatusInvoice($billable, 'tr_rs_2');

    RefundStatusFakeWebhookController::$nextPayment = refundStatusPayment($billable, 'tr_rs_2', '2.00', [
        ['id' => 're_ok2', 'value' => '2.00', 'status' => 'refunded'],
        ['id' => 're_canceled', 'value' => '9.90', 'status' => 'canceled'],
    ]);

    $this->postJson(route('billing.webhook'), ['id' => 'tr_rs_2'])->assertStatus(200);

    $creditedGross = (int) BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->get()
        ->sum(fn (BillingInvoice $n): int => abs((int) $n->amount_gross));

    expect($creditedGross)->toBe(200)
        ->and($invoice->fresh()->isFullyRefunded())->toBeFalse();
});

it('takes a booked refund back out of the books when Mollie later reports it as failed', function (): void {
    Event::fake([RefundReversed::class]);
    Notification::fake();

    $billable = refundStatusBillable();
    $invoice = refundStatusInvoice($billable, 'tr_rs_3');
    MollieBilling::notifyAdminUsing(fn (): array => [$billable]);

    // The paid delivery ran to completion long ago — which is what makes the last
    // delivery below byte-identical to an outcome already on record.
    BillingProcessedWebhook::create([
        'mollie_payment_id' => 'tr_rs_3',
        'event_signature' => BillingProcessedWebhook::outcomeSignature('tr_rs_3', 'paid'),
        'received_at' => BillingTime::nowUtc()->subDays(2),
        'processed_at' => BillingTime::nowUtc()->subDays(2),
    ]);

    // Delivery 1: the refund exists and is on its way.
    RefundStatusFakeWebhookController::$nextPayment = refundStatusPayment($billable, 'tr_rs_3', '11.90', [
        ['id' => 're_bounce', 'value' => '11.90', 'status' => 'pending'],
    ]);
    $this->postJson(route('billing.webhook'), ['id' => 'tr_rs_3'])->assertStatus(200);

    expect($invoice->fresh()->isFullyRefunded())->toBeTrue();

    // Delivery 2: the bank bounced it. The money is back with the merchant and Mollie's
    // refunded total is 0 again.
    RefundStatusFakeWebhookController::$nextPayment = refundStatusPayment($billable, 'tr_rs_3', '0.00', [
        ['id' => 're_bounce', 'value' => '11.90', 'status' => 'failed'],
    ]);
    $this->postJson(route('billing.webhook'), ['id' => 'tr_rs_3'])->assertStatus(200);

    // Nothing was returned to the customer, so the invoice is paid in full again.
    $fresh = BillingInvoice::query()->findOrFail($invoice->id);
    expect($fresh->isFullyRefunded())->toBeFalse()
        ->and($fresh->remainingRefundableNet())->toBe(1000)
        ->and((int) $fresh->refunded_net)->toBe(0)
        ->and($fresh->creditedVatTotal())->toBe(0);

    // The credit note is not deleted — it is reversed by a document of its own, and the
    // two net to zero.
    $documents = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->orderBy('id')->get();
    expect($documents)->toHaveCount(2)
        ->and((int) $documents->sum('amount_gross'))->toBe(0)
        ->and((int) $documents[1]->amount_gross)->toBe(1190)
        ->and($documents[1]->line_items[0]['reverses_credit'] ?? null)->toBeTrue()
        ->and($documents[1]->serial_number)->not->toBe($documents[0]->serial_number);

    Event::assertDispatchedTimes(RefundReversed::class, 1);
    Notification::assertSentTo($billable, AdminRefundFailedNotification::class);

    // Delivery 3: the same state again reverses nothing a second time.
    $this->postJson(route('billing.webhook'), ['id' => 'tr_rs_3'])->assertStatus(200);

    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(2);
    Event::assertDispatchedTimes(RefundReversed::class, 1);

    // And the refund can be attempted again: a new one for the full amount books normally.
    RefundStatusFakeWebhookController::$nextPayment = refundStatusPayment($billable, 'tr_rs_3', '11.90', [
        ['id' => 're_bounce', 'value' => '11.90', 'status' => 'failed'],
        ['id' => 're_second_try', 'value' => '11.90', 'status' => 'refunded'],
    ]);
    $this->postJson(route('billing.webhook'), ['id' => 'tr_rs_3'])->assertStatus(200);

    expect(BillingInvoice::query()->findOrFail($invoice->id)->isFullyRefunded())->toBeTrue();
});

it('still books a refund that is queued or pending, and one whose status Mollie does not state', function (string $status): void {
    $billable = refundStatusBillable();
    $invoice = refundStatusInvoice($billable, 'tr_rs_4');

    RefundStatusFakeWebhookController::$nextPayment = refundStatusPayment($billable, 'tr_rs_4', '5.00', [
        ['id' => 're_moving', 'value' => '5.00', 'status' => $status],
    ]);
    $this->postJson(route('billing.webhook'), ['id' => 'tr_rs_4'])->assertStatus(200);

    expect((int) BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->sole()->amount_gross)->toBe(-500)
        ->and($invoice->fresh()->effectiveRefundedNet())->toBeGreaterThan(0);
})->with(['queued', 'pending', 'processing', 'refunded', '']);
