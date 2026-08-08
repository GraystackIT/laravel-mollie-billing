<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * No Mollie call may run inside a database transaction.
 *
 * CLAUDE.md states it for the activation path — "anything throwing after it rolled back
 * `mollie_subscription_id` and the invoice while the subscription existed at Mollie" — and
 * the reason generalises: a transaction can be rolled back, an HTTP call to a payment
 * provider cannot. `UpdateSubscription::update()` and `RefundInvoiceService::refund()` both
 * used to hold one transaction across their Mollie calls *and* every local write, so a
 * failure after the money moved undid the local half and the retry moved the money again.
 *
 * `DB::transactionLevel()` at the moment of the call is the only assertion that actually
 * pins this — a comment cannot. It is compared against the level *outside* the call rather
 * than against 0, because `RefreshDatabase` wraps every test in a transaction of its own.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');

    config()->set('mollie-billing-plans.plans', [
        'starter' => [
            'name' => 'Starter',
            'tier' => 1,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => ['monthly' => ['base_price_net' => 900, 'seat_price_net' => 500, 'included_usages' => []]],
        ],
        'pro' => [
            'name' => 'Pro',
            'tier' => 2,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => ['monthly' => ['base_price_net' => 2900, 'seat_price_net' => 500, 'included_usages' => []]],
        ],
    ]);
    config()->set('mollie-billing.vat.seller_country', 'AT');
});

function txBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Transaction Ltd',
        'email' => uniqid('tx-').'@x.test',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
    ]);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'starter',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(8),
        'mollie_customer_id' => 'cst_tx',
        'mollie_mandate_id' => 'mdt_tx',
        'subscription_meta' => ['mollie_subscription_id' => 'sub_tx', 'seat_count' => 1],
    ])->save();

    return $billable->refresh();
}

function txPaidInvoice(TestBillable $billable, int $net = 900): BillingInvoice
{
    return BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => uniqid('tr_tx_'),
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => $net,
        'amount_vat' => (int) round($net * 0.2),
        'amount_gross' => $net + (int) round($net * 0.2),
        'serial_number' => 'IN-'.uniqid(),
        'period_start' => BillingTime::nowUtc()->subDays(8),
        'period_end' => BillingTime::nowUtc()->addDays(22),
        'line_items' => [[
            'kind' => 'plan',
            'code' => 'starter',
            'label' => 'Starter',
            'quantity' => 1,
            'unit_price_net' => $net,
            'amount_net' => $net,
            'vat_rate' => 20.0,
            'vat_amount' => (int) round($net * 0.2),
            'amount_gross' => $net + (int) round($net * 0.2),
            'period_start' => BillingTime::nowUtc()->subDays(8)->toIso8601String(),
            'period_end' => BillingTime::nowUtc()->addDays(22)->toIso8601String(),
        ]],
    ])->refresh();
}

it('creates a plan-change charge with no transaction open', function (): void {
    $billable = txBillable();
    txPaidInvoice($billable);

    $levels = [];
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$levels) {
        if ($request instanceof CreatePaymentRequest || $request instanceof CreatePaymentRefundRequest) {
            $levels[] = DB::transactionLevel();
        }

        return (object) ['id' => 'tr_prorata_tx', 'status' => 'open', 'subscriptionId' => 'sub_tx'];
    });

    $baseline = DB::transactionLevel();

    app(UpdateSubscription::class)->update($billable, ['plan_code' => 'pro']);

    // An upgrade mid-period charges the difference. Whatever the composer produced, the
    // call must not have been wrapped in a transaction that a later throw could roll back.
    expect($levels)->not->toBeEmpty()
        ->and(array_unique($levels))->toBe([$baseline]);
});

it('posts an admin refund with no transaction open', function (): void {
    $billable = txBillable();
    $invoice = txPaidInvoice($billable, 2900);

    $levels = [];
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$levels) {
        if ($request instanceof CreatePaymentRefundRequest) {
            $levels[] = DB::transactionLevel();
        }

        return (object) ['id' => 're_tx'];
    });

    $baseline = DB::transactionLevel();

    app(RefundInvoiceService::class)->refund($invoice, [
        'amount_net' => 1000,
        'reason_code' => RefundReasonCode::Goodwill,
        'notify_user' => false,
    ]);

    expect($levels)->toBe([$baseline]);

    // And the booking still happened — the split may not cost the credit note.
    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(1)
        ->and((int) $invoice->fresh()->refunded_net)->toBe(1000);
});

it('still writes the credit note PDF', function (): void {
    $billable = txBillable();
    $invoice = txPaidInvoice($billable, 2900);

    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 're_tx_pdf']);

    // The render moved out of the transaction — it used to hold the invoice row lock and
    // the serial range, which is what made a lock-wait timeout against a concurrent refund
    // realistic. It still has to happen.
    $creditNote = app(RefundInvoiceService::class)->refund($invoice, [
        'amount_net' => 500,
        'reason_code' => RefundReasonCode::Goodwill,
        'notify_user' => false,
    ]);

    expect($creditNote->fresh()->pdf_path)->not->toBeNull();
});
