<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingProcessedWebhook;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Storage;

/*
 * A chargeback can be reversed: the merchant disputes it and wins, and the money comes
 * back. Mollie states that on the chargeback itself (`reversedAt`).
 *
 * The books used to know only the way out: the credit note stayed, the invoice kept
 * reading as refunded — revenue and output VAT under-declared for money that had
 * returned — and the subscription stayed past due on its way to the auto-cancel, for a
 * customer whose payment stands.
 *
 * NOT measured against Mollie: whether `amountChargedBack` drops when a chargeback is
 * reversed, and whether the payment webhook is called for it. The sandbox cannot produce
 * a chargeback. The handler therefore reads only `reversedAt`, and the controller looks
 * at a payment's chargebacks whenever one is still on the books.
 */
class ChargebackReversalFakeWebhookController extends MollieWebhookController
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

    ChargebackReversalFakeWebhookController::$nextPayment = null;
    $this->app->bind(MollieWebhookController::class, ChargebackReversalFakeWebhookController::class);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 2000, 'seat_price_net' => null, 'included_usages' => []]],
    ]);
});

function chargebackReversalBillable(): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create(['name' => 'Disputed Co', 'email' => uniqid('cbr-').'@x.test', 'billing_country' => 'DE']);
    $b->forceFill([
        'mollie_customer_id' => 'cst_cbr',
        'mollie_mandate_id' => 'mdt_cbr',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_cbr'],
    ])->save();

    return $b->refresh();
}

/** 20.00 net, 19 % → 23.80. */
function chargebackReversalInvoice(TestBillable $billable): BillingInvoice
{
    BillingProcessedWebhook::create([
        'mollie_payment_id' => 'tr_cbr',
        'event_signature' => BillingProcessedWebhook::outcomeSignature('tr_cbr', 'paid'),
        'received_at' => BillingTime::nowUtc()->subDays(5),
        'processed_at' => BillingTime::nowUtc()->subDays(5),
    ]);

    return BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_cbr',
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
            'kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1,
            'unit_price_net' => 2000, 'amount_net' => 2000, 'total_net' => 2000,
            'vat_rate' => 19.0, 'vat_amount' => 380, 'amount_gross' => 2380,
        ]],
    ]);
}

function chargebackReversalPayment(TestBillable $billable, ?string $chargedBack, ?string $reversedAt): object
{
    return new class($billable, $chargedBack, $reversedAt)
    {
        public string $id = 'tr_cbr';

        public string $status = 'paid';

        public object $amount;

        public ?object $amountChargedBack = null;

        public ?string $subscriptionId = 'sub_cbr';

        public array $metadata;

        public function __construct(TestBillable $billable, ?string $chargedBack, private ?string $reversedAt)
        {
            $this->amount = (object) ['value' => '23.80', 'currency' => 'EUR'];
            $this->metadata = [
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
            ];

            if ($chargedBack !== null) {
                $this->amountChargedBack = (object) ['value' => '-'.$chargedBack, 'currency' => 'EUR'];
            }
        }

        public function chargebacks(): array
        {
            return [(object) [
                'id' => 'chb_disputed',
                'amount' => (object) ['value' => '-23.80', 'currency' => 'EUR'],
                'reversedAt' => $this->reversedAt,
            ]];
        }

        public function refunds(): array
        {
            return [];
        }
    };
}

it('takes a chargeback back out of the books when it is reversed, and ends the dunning it started', function (?string $chargedBackAfterReversal): void {
    $billable = chargebackReversalBillable();
    $invoice = chargebackReversalInvoice($billable);

    // The bank pulls the money back.
    ChargebackReversalFakeWebhookController::$nextPayment = chargebackReversalPayment($billable, '23.80', null);
    $this->postJson(route('billing.webhook'), ['id' => 'tr_cbr'])->assertStatus(200);

    expect($invoice->fresh()->isFullyRefunded())->toBeTrue()
        ->and($billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::PastDue);

    // The dispute is won: the chargeback is reversed.
    ChargebackReversalFakeWebhookController::$nextPayment = chargebackReversalPayment($billable, $chargedBackAfterReversal, '2026-11-20T10:00:00+00:00');
    $this->postJson(route('billing.webhook'), ['id' => 'tr_cbr'])->assertStatus(200);

    $fresh = BillingInvoice::query()->findOrFail($invoice->id);
    $documents = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->orderBy('id')->get();

    // The credit note stays and is reversed by a document of its own.
    expect($fresh->isFullyRefunded())->toBeFalse()
        ->and($fresh->remainingRefundableNet())->toBe(2000)
        ->and($documents)->toHaveCount(2)
        ->and((int) $documents->sum('amount_gross'))->toBe(0)
        ->and($documents[1]->line_items[0]['reverses_credit'] ?? null)->toBeTrue();

    // The payment stands, so the customer is not past due because of it any more.
    $billable = $billable->fresh();
    expect($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('past_due_since')
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('payment_failure');

    // Delivered again: nothing more happens.
    $this->postJson(route('billing.webhook'), ['id' => 'tr_cbr'])->assertStatus(200);
    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(2);
})->with([
    'Mollie still reports the charged-back amount' => ['23.80'],
    'Mollie reports no charged-back amount any more' => [null],
]);

it('does not book a chargeback that is already reversed when it is first seen', function (): void {
    $billable = chargebackReversalBillable();
    $invoice = chargebackReversalInvoice($billable);

    ChargebackReversalFakeWebhookController::$nextPayment = chargebackReversalPayment($billable, '23.80', '2026-11-20T10:00:00+00:00');
    $this->postJson(route('billing.webhook'), ['id' => 'tr_cbr'])->assertStatus(200);

    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(0)
        ->and($invoice->fresh()->remainingRefundableNet())->toBe(2000)
        ->and($billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active);
});

it('leaves a customer past due whose dunning has another cause than the reversed chargeback', function (): void {
    $billable = chargebackReversalBillable();
    $invoice = chargebackReversalInvoice($billable);

    ChargebackReversalFakeWebhookController::$nextPayment = chargebackReversalPayment($billable, '23.80', null);
    $this->postJson(route('billing.webhook'), ['id' => 'tr_cbr'])->assertStatus(200);

    // Meanwhile a renewal fails as well: the dunning episode now has a second reason.
    $meta = $billable->fresh()->getBillingSubscriptionMeta();
    $meta['payment_failure'] = ['payment_id' => 'tr_later', 'reason' => 'insufficient_funds', 'failed_at' => BillingTime::nowUtc()->toIso8601String()];
    $billable->fresh()->forceFill(['subscription_meta' => $meta])->save();

    ChargebackReversalFakeWebhookController::$nextPayment = chargebackReversalPayment($billable, null, '2026-11-20T10:00:00+00:00');
    $this->postJson(route('billing.webhook'), ['id' => 'tr_cbr'])->assertStatus(200);

    // The books are corrected, the status is not touched.
    expect($invoice->fresh()->isFullyRefunded())->toBeFalse()
        ->and($billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::PastDue);
});
