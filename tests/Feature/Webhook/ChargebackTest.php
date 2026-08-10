<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\PaymentFailed;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingProcessedWebhook;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * A chargeback is money the bank pulls back after a payment settled. Mollie reports it
 * on the SAME payment, whose status stays `paid` — only `amountChargedBack` changes.
 *
 * So the dedup signature was byte-identical to the paid delivery already on record and
 * the whole thing was answered 200 and forgotten: the money was gone while the invoice
 * stayed fully paid (output VAT and the OSS return over-declared by that amount), no
 * credit note existed, and the customer kept access indefinitely. For SEPA this is a
 * routine event — the payer has eight weeks and needs no reason.
 */
class ChargebackFakeWebhookController extends MollieWebhookController
{
    /** @var array<string, object> */
    public static array $payments = [];

    protected function fetchPayment(string $paymentId): object
    {
        return self::$payments[$paymentId] ?? throw new RuntimeException("No stub for {$paymentId}.");
    }
}

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');

    ChargebackFakeWebhookController::$payments = [];
    $this->app->bind(MollieWebhookController::class, ChargebackFakeWebhookController::class);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 2000, 'seat_price_net' => null, 'included_usages' => []]],
    ]);
});

function chargebackBillable(array $overrides = []): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Chargeback Co',
        'email' => uniqid('cb-').'@x.test',
        'billing_country' => 'DE',
    ]);

    $b->forceFill(array_merge([
        'mollie_customer_id' => 'cst_cb',
        'mollie_mandate_id' => 'mdt_cb',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_cb'],
    ], $overrides))->save();

    return $b->refresh();
}

/** The renewal invoice a chargeback is credited against: 20.00 net, 19 % → 23.80. */
function chargebackInvoice(TestBillable $billable, string $paymentId = 'tr_cb'): BillingInvoice
{
    return BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => $paymentId,
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'DE',
        'currency' => 'EUR',
        'amount_net' => 2000,
        'amount_vat' => 380,
        'amount_gross' => 2380,
        'period_start' => $billable->subscription_period_starts_at,
        'period_end' => $billable->nextBillingDate(),
        'line_items' => [[
            'kind' => 'plan',
            'code' => 'pro',
            'label' => 'Pro',
            'quantity' => 1,
            'unit_price_net' => 2000,
            'amount_net' => 2000,
            'total_net' => 2000,
            'vat_rate' => 19.0,
            'vat_amount' => 380,
            'amount_gross' => 2380,
        ]],
    ]);
}

/**
 * A payment object as the SDK hydrates it, with N chargebacks.
 *
 * @param  array<int, array{id:string, value:string}>  $chargebacks
 */
function chargebackPayment(TestBillable $billable, array $chargebacks, string $refunded = '0.00', string $paymentId = 'tr_cb'): object
{
    return new class($billable, $chargebacks, $refunded, $paymentId)
    {
        public string $id;

        public string $status = 'paid';

        public object $amount;

        public object $amountRefunded;

        public ?object $amountChargedBack = null;

        public ?string $paidAt;

        public ?string $subscriptionId = 'sub_cb';

        public ?string $countryCode = 'DE';

        public array $metadata;

        /** @var array<int, object> */
        private array $chargebackObjects = [];

        public function __construct(TestBillable $billable, array $chargebacks, string $refunded, string $paymentId)
        {
            $this->id = $paymentId;
            $this->amount = (object) ['value' => '23.80', 'currency' => 'EUR'];
            $this->amountRefunded = (object) ['value' => $refunded, 'currency' => 'EUR'];
            $this->paidAt = BillingTime::nowUtc()->toIso8601String();
            $this->metadata = [
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
            ];

            $total = 0.0;
            foreach ($chargebacks as $cb) {
                $total += (float) $cb['value'];
                $this->chargebackObjects[] = (object) [
                    'id' => $cb['id'],
                    'amount' => (object) ['value' => '-'.$cb['value'], 'currency' => 'EUR'],
                ];
            }

            if ($total > 0.0) {
                // Mollie reports the total as a negative gross — money leaving.
                $this->amountChargedBack = (object) ['value' => '-'.number_format($total, 2, '.', ''), 'currency' => 'EUR'];
            }
        }

        public function chargebacks(): array
        {
            return $this->chargebackObjects;
        }

        public function refunds(): array
        {
            return [];
        }
    };
}

it('books a chargeback as a credit note and puts the subscription past due', function (): void {
    Notification::fake();

    $billable = chargebackBillable();
    $invoice = chargebackInvoice($billable);
    MollieBilling::notifyAdminUsing(fn (): array => [$billable]);

    ChargebackFakeWebhookController::$payments['tr_cb'] = chargebackPayment($billable, [
        ['id' => 'chb_1', 'value' => '23.80'],
    ]);

    $this->postJson(route('billing.webhook'), ['id' => 'tr_cb'])->assertStatus(200);

    $creditNote = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->sole();

    // A credit note carries negative amounts (it is a credit); `refunded_net` on the
    // original counts the positive magnitude. Same convention as a dashboard refund.
    expect((int) $creditNote->amount_net)->toBe(-2000)
        ->and($creditNote->refund_reason_code)->toBe(RefundReasonCode::Chargeback)
        ->and(collect($creditNote->line_items)->first()['mollie_chargeback_id'])->toBe('chb_1');

    // The invoice must stop claiming the money is still ours — output VAT and the OSS
    // return are derived from it.
    expect((int) $invoice->fresh()->refunded_net)->toBe(2000);

    // Money collected and taken back is a failed payment: the existing past-due ladder
    // owns what follows (dunning, then auto-cancel).
    $billable->refresh();
    expect($billable->subscription_status)->toBe(SubscriptionStatus::PastDue)
        ->and($billable->getBillingSubscriptionMeta())->toHaveKey('past_due_since')
        ->and($billable->getBillingSubscriptionMeta()['payment_failure']['reason'])->toBe('chargeback');

    Notification::assertSentTo($billable, AdminPlanChangeFailedNotification::class);

    MollieBilling::flushCallbacks();
});

it('does not dedupe the chargeback delivery away as a repeat of the paid one', function (): void {
    $billable = chargebackBillable();
    chargebackInvoice($billable);

    // The paid delivery, recorded exactly as the controller would.
    BillingProcessedWebhook::create([
        'mollie_payment_id' => 'tr_cb',
        'event_signature' => BillingProcessedWebhook::outcomeSignature('tr_cb', 'paid'),
        'received_at' => BillingTime::nowUtc()->subHour(),
        'processed_at' => BillingTime::nowUtc()->subHour(),
    ]);

    ChargebackFakeWebhookController::$payments['tr_cb'] = chargebackPayment($billable, [
        ['id' => 'chb_1', 'value' => '23.80'],
    ]);

    $this->postJson(route('billing.webhook'), ['id' => 'tr_cb'])->assertStatus(200);

    // Without the chargeback component in the signature this delivery looked identical
    // to the one above and was skipped, so nothing was ever booked.
    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(1);
});

it('ignores a repeated chargeback delivery', function (): void {
    $billable = chargebackBillable();
    chargebackInvoice($billable);

    ChargebackFakeWebhookController::$payments['tr_cb'] = chargebackPayment($billable, [
        ['id' => 'chb_1', 'value' => '23.80'],
    ]);

    $this->postJson(route('billing.webhook'), ['id' => 'tr_cb'])->assertStatus(200);

    // Mollie re-delivers; the outcome signature is now identical, but clear the record
    // so the handler itself has to be idempotent.
    BillingProcessedWebhook::query()->delete();
    $this->postJson(route('billing.webhook'), ['id' => 'tr_cb'])->assertStatus(200);

    // Deduped on the chargeback id, like refunds are on the refund id — Mollie can
    // report several of the same value against one payment.
    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(1)
        ->and((int) BillingInvoice::query()->where('mollie_payment_id', 'tr_cb')->sole()->refunded_net)->toBe(2000);
});

it('books a second, distinct chargeback on the same payment', function (): void {
    $billable = chargebackBillable();
    chargebackInvoice($billable);

    // A partial chargeback…
    ChargebackFakeWebhookController::$payments['tr_cb'] = chargebackPayment($billable, [
        ['id' => 'chb_1', 'value' => '10.00'],
    ]);
    $this->postJson(route('billing.webhook'), ['id' => 'tr_cb'])->assertStatus(200);

    // …and later the rest, as its own chargeback.
    ChargebackFakeWebhookController::$payments['tr_cb'] = chargebackPayment($billable, [
        ['id' => 'chb_1', 'value' => '10.00'],
        ['id' => 'chb_2', 'value' => '13.80'],
    ]);
    $this->postJson(route('billing.webhook'), ['id' => 'tr_cb'])->assertStatus(200);

    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(2)
        // 10.00 gross + 13.80 gross = the whole 23.80, i.e. the full 2000 net.
        ->and((int) BillingInvoice::query()->where('mollie_payment_id', 'tr_cb')->sole()->refunded_net)->toBe(2000);
});

it('keeps refunds and chargebacks on one payment apart', function (): void {
    $billable = chargebackBillable();
    chargebackInvoice($billable);

    // A partial refund the operator issued, plus a chargeback for the rest. Both are
    // reported on the same payment, and both have to be booked exactly once.
    $payment = chargebackPayment($billable, [['id' => 'chb_1', 'value' => '13.80']], refunded: '10.00');
    ChargebackFakeWebhookController::$payments['tr_cb'] = $payment;

    $this->postJson(route('billing.webhook'), ['id' => 'tr_cb'])->assertStatus(200);

    // The refund side has no refund objects in this stub, so only the chargeback books —
    // what matters is that the chargeback is not skipped because a refund total exists,
    // and that the two use separate signature components so equal amounts cannot mask
    // one another.
    expect(BillingProcessedWebhook::outcomeSignature('tr_cb', 'paid', 1000, 1380))
        ->toBe('tr_cb:paid:refunded:1000:chargedback:1380')
        ->and(BillingProcessedWebhook::outcomeSignature('tr_cb', 'paid', 1000))
        ->toBe('tr_cb:paid:refunded:1000');

    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(1);
});

it('announces the chargeback-driven past due as a failed payment', function (): void {
    Notification::fake();
    Event::fake([PaymentFailed::class]);

    $billable = chargebackBillable();
    chargebackInvoice($billable);

    ChargebackFakeWebhookController::$payments['tr_cb'] = chargebackPayment($billable, [
        ['id' => 'chb_1', 'value' => '23.80'],
    ]);

    $this->postJson(route('billing.webhook'), ['id' => 'tr_cb'])->assertStatus(200);

    expect($billable->refresh()->subscription_status)->toBe(SubscriptionStatus::PastDue);

    // The same transition a failed renewal produces has to be announced the same way:
    // `SubscriptionPaymentHandler::failed()` dispatches PaymentFailed, so an app that
    // reacts to dunning on that event silently missed every chargeback-driven episode.
    Event::assertDispatched(PaymentFailed::class, fn (PaymentFailed $e): bool => $e->billable->getKey() === $billable->getKey()
        && $e->paymentId === 'tr_cb'
        && $e->reason === 'chargeback');
});

it('leaves a cancelled subscription alone', function (): void {
    $billable = chargebackBillable([
        'subscription_status' => SubscriptionStatus::Cancelled,
        'subscription_ends_at' => BillingTime::nowUtc()->subDay(),
    ]);
    chargebackInvoice($billable);

    ChargebackFakeWebhookController::$payments['tr_cb'] = chargebackPayment($billable, [
        ['id' => 'chb_1', 'value' => '23.80'],
    ]);

    $this->postJson(route('billing.webhook'), ['id' => 'tr_cb'])->assertStatus(200);

    // The credit note is still right — the money did leave. But forcing PastDue on an
    // ended subscription would feed it back into the auto-cancel ladder.
    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(1)
        ->and($billable->refresh()->subscription_status)->toBe(SubscriptionStatus::Cancelled);
});
