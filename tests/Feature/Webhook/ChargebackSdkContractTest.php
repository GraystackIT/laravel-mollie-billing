<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingProcessedWebhook;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Storage;
use Mollie\Api\Fake\FakeResponseLoader;
use Mollie\Api\Fake\MockMollieClient;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Requests\GetPaginatedPaymentChargebacksRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Laravel\Facades\Mollie;

/*
 * The contract between ChargebackHandler and the chargeback as Mollie sends it.
 *
 * Mollie's test mode cannot produce a chargeback — no endpoint creates one and the test
 * pages do not offer it (measured, see docs/sandbox-testing.md). The other chargeback
 * tests hand the handler objects whose field names the test author chose, so a field the
 * handler reads under the wrong name would pass all of them. This file does not choose:
 *
 * - the payment is the charged-back payment the SDK ships as its own sample
 *   (`_embedded.chargebacks[0]._embedded.payment` of
 *   vendor/mollie/mollie-api-php/src/Fake/Responses/chargeback-list.json: status `paid`,
 *   `amountChargedBack`, `_links.chargebacks`);
 * - each chargeback is the SDK's single-resource sample
 *   (src/Fake/Responses/chargeback.json — the one that carries `reversedAt` and `reason`),
 *   inside the envelope of chargeback-list.json;
 * - both are served as JSON through the SDK's own MockMollieClient, so the real
 *   GetPaymentRequest → Payment hydration, the typed GetPaginatedPaymentChargebacksRequest
 *   (through MollieCall, see MolliePaymentCredits) → ChargebackCollection, and the real
 *   webhook controller all run.
 *
 * Only values are overridden (ids, amounts, the billable's metadata, the subscription id,
 * `reversedAt`) — never a key. `settlementAmount` is left exactly as the sample has it
 * (-35.07 EUR against an `amount` in another currency): it is what the merchant's
 * settlement is debited, not what the customer's payment lost, and a handler that read it
 * would book the wrong credit — the partial case below would show it.
 *
 * Note the signs, from the samples: a chargeback's `amount` and the payment's
 * `amountChargedBack` are positive; only `settlementAmount` is negative. The handler takes
 * the magnitude, so either sign books the same credit.
 *
 * Pro 20.00 net, 19 % → 23.80 paid.
 */

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 2000, 'seat_price_net' => null, 'included_usages' => []]],
    ]);
});

/** @return array<string, mixed> */
function chargebackSdkSample(string $name, string $resourceId = ''): array
{
    return json_decode(str_replace('{{ RESOURCE_ID }}', $resourceId, FakeResponseLoader::load($name)), true, flags: JSON_THROW_ON_ERROR);
}

function chargebackSdkBillable(): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create(['name' => 'Contract Co', 'email' => uniqid('cbsdk-').'@x.test', 'billing_country' => 'DE']);
    $b->forceFill([
        'mollie_customer_id' => 'cst_cbsdk',
        'mollie_mandate_id' => 'mdt_cbsdk',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_cbsdk'],
    ])->save();

    return $b->refresh();
}

/** The renewal the bank later pulls back, booked as the paid delivery left it. */
function chargebackSdkInvoice(TestBillable $billable): BillingInvoice
{
    BillingProcessedWebhook::create([
        'mollie_payment_id' => 'tr_cbsdk',
        'event_signature' => BillingProcessedWebhook::outcomeSignature('tr_cbsdk', 'paid'),
        'received_at' => BillingTime::nowUtc()->subDays(5),
        'processed_at' => BillingTime::nowUtc()->subDays(5),
    ]);

    return BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_cbsdk',
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

/**
 * The SDK's charged-back payment, made this billable's renewal.
 *
 * @return array<string, mixed>
 */
function chargebackSdkPayment(TestBillable $billable, ?string $chargedBack): array
{
    $payment = chargebackSdkSample('chargeback-list')['_embedded']['chargebacks'][0]['_embedded']['payment'];

    expect($payment)->toHaveKeys(['status', 'amount', 'amountRefunded', 'amountChargedBack', '_links']);

    $payment['id'] = 'tr_cbsdk';
    $payment['amount'] = ['value' => '23.80', 'currency' => 'EUR'];
    $payment['amountRefunded'] = ['value' => '0.00', 'currency' => 'EUR'];
    $payment['metadata'] = [
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => (string) $billable->getKey(),
        'plan_code' => 'pro',
        'interval' => 'monthly',
    ];
    $payment['sequenceType'] = 'recurring';
    $payment['subscriptionId'] = 'sub_cbsdk';
    $payment['customerId'] = 'cst_cbsdk';
    $payment['mandateId'] = 'mdt_cbsdk';
    $payment['countryCode'] = 'DE';
    $payment['_links']['chargebacks']['href'] = 'https://api.mollie.com/v2/payments/tr_cbsdk/chargebacks';

    if ($chargedBack === null) {
        unset($payment['amountChargedBack']);
    } else {
        $payment['amountChargedBack']['value'] = $chargedBack;
        $payment['amountChargedBack']['currency'] = 'EUR';
    }

    return $payment;
}

/**
 * One chargeback, from the SDK's single-resource sample.
 *
 * @return array<string, mixed>
 */
function chargebackSdkChargeback(string $id, string $value, ?string $reversedAt = null): array
{
    $chargeback = chargebackSdkSample('chargeback', $id);

    expect($chargeback)->toHaveKeys(['id', 'amount', 'settlementAmount', 'reversedAt', 'paymentId', 'createdAt']);

    $chargeback['amount']['value'] = $value;
    $chargeback['amount']['currency'] = 'EUR';
    $chargeback['paymentId'] = 'tr_cbsdk';
    $chargeback['reversedAt'] = $reversedAt;

    return $chargeback;
}

/**
 * Deliver the payment webhook with Mollie answering from the given JSON.
 *
 * @param  array<string, mixed>  $payment
 * @param  array<int, array<string, mixed>>  $chargebacks
 */
function chargebackSdkDeliver(array $payment, array $chargebacks): MockMollieClient
{
    $list = chargebackSdkSample('chargeback-list');
    $list['count'] = count($chargebacks);
    $list['_embedded']['chargebacks'] = $chargebacks;

    $mollie = Mollie::fake([
        GetPaymentRequest::class => MockResponse::ok($payment),
        GetPaginatedPaymentChargebacksRequest::class => MockResponse::ok($list),
    ]);

    test()->postJson(route('billing.webhook'), ['id' => 'tr_cbsdk'])->assertStatus(200);

    return $mollie;
}

it('books a full chargeback in the SDK\'s own format', function (): void {
    $billable = chargebackSdkBillable();
    $invoice = chargebackSdkInvoice($billable);

    $mollie = chargebackSdkDeliver(
        chargebackSdkPayment($billable, '23.80'),
        [chargebackSdkChargeback('chb_full', '23.80')],
    );

    // The chargebacks were read as the typed list request for this payment.
    $mollie->assertSent(fn ($request): bool => $request->getRequest() instanceof GetPaginatedPaymentChargebacksRequest
        && $request->getRequest()->resolveResourcePath() === 'payments/tr_cbsdk/chargebacks');

    $note = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->sole();

    // All of 23.80: 20.00 net + 3.80 VAT credited.
    expect((int) $note->amount_net)->toBe(-2000)
        ->and((int) $note->amount_gross)->toBe(-2380)
        ->and($note->refund_reason_code)->toBe(RefundReasonCode::Chargeback)
        ->and(collect($note->line_items)->first()['mollie_chargeback_id'])->toBe('chb_full')
        ->and((int) $invoice->fresh()->refunded_net)->toBe(2000);

    $billable = $billable->fresh();
    expect($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::PastDue)
        ->and($billable->getBillingSubscriptionMeta()['payment_failure']['reason'] ?? null)->toBe('chargeback');
});

it('books a partial chargeback at its own amount, not the settlement figure', function (): void {
    $billable = chargebackSdkBillable();
    $invoice = chargebackSdkInvoice($billable);

    chargebackSdkDeliver(
        chargebackSdkPayment($billable, '10.00'),
        [chargebackSdkChargeback('chb_part', '10.00')],
    );

    $note = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->sole();

    // 10.00 gross on a 19 % invoice: the largest net whose gross stays within it is
    // 8.40 (8.40 + 1.60 = 10.00; 8.41 + 1.60 = 10.01). The sample's settlementAmount,
    // -35.07, would have credited the whole invoice.
    expect((int) $note->amount_net)->toBe(-840)
        ->and((int) $note->amount_gross)->toBe(-1000)
        ->and((int) $invoice->fresh()->refunded_net)->toBe(840)
        ->and($invoice->fresh()->remainingRefundableNet())->toBe(1160)
        ->and($billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::PastDue);
});

it('takes a chargeback back out of the books once the SDK reports it reversed', function (): void {
    $billable = chargebackSdkBillable();
    $invoice = chargebackSdkInvoice($billable);

    chargebackSdkDeliver(
        chargebackSdkPayment($billable, '23.80'),
        [chargebackSdkChargeback('chb_disputed', '23.80')],
    );

    expect($invoice->fresh()->isFullyRefunded())->toBeTrue();

    // The dispute is won. The payment still reports the charged-back total (whether
    // Mollie drops it on a reversal is not known); the chargeback carries `reversedAt`.
    chargebackSdkDeliver(
        chargebackSdkPayment($billable, '23.80'),
        [chargebackSdkChargeback('chb_disputed', '23.80', '2026-11-20T10:00:00.0Z')],
    );

    $documents = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->orderBy('id')->get();

    expect($documents)->toHaveCount(2)
        ->and((int) $documents->sum('amount_gross'))->toBe(0)
        ->and($documents[1]->line_items[0]['reverses_credit'] ?? null)->toBeTrue()
        ->and($invoice->fresh()->remainingRefundableNet())->toBe(2000)
        ->and($billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active);
});

it('books nothing for a chargeback first seen already reversed', function (): void {
    $billable = chargebackSdkBillable();
    $invoice = chargebackSdkInvoice($billable);

    chargebackSdkDeliver(
        chargebackSdkPayment($billable, '23.80'),
        [chargebackSdkChargeback('chb_won', '23.80', '2026-11-20T10:00:00.0Z')],
    );

    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(0)
        ->and($invoice->fresh()->remainingRefundableNet())->toBe(2000)
        ->and($billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active);
});
