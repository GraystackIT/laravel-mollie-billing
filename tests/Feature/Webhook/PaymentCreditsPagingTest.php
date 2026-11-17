<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingProcessedWebhook;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\MolliePaymentCredits;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Storage;
use Mollie\Api\Fake\FakeResponseLoader;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Fake\SequenceMockResponse;
use Mollie\Api\Http\PendingRequest;
use Mollie\Api\Http\Requests\DynamicGetRequest;
use Mollie\Api\Http\Requests\GetPaginatedPaymentChargebacksRequest;
use Mollie\Api\Http\Requests\GetPaginatedPaymentRefundsRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Laravel\Facades\Mollie;

/*
 * A payment's refunds and chargebacks are read page by page, to the last one.
 *
 * `$payment->refunds()` / `chargebacks()` — what the handlers used before — return the
 * first page Mollie sends (50 entries by default) and nothing after it. A payment with
 * more was booked short: the money left, the credit notes for everything past the first
 * page never existed. Mollie is answered here with the SDK's own samples
 * (src/Fake/Responses/refund.json, chargeback.json, payment.json) split over two pages,
 * the first carrying a `next` link as the API does; the old route (`_links.refunds`) is
 * answered with the first page, which is all it would have read.
 *
 * Pro 20.00 net, 19 % → 23.80 paid. A 5.95 refund is 5.00 net (5.00 + 0.95).
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
function creditsPagingSample(string $name, string $id = ''): array
{
    return json_decode(str_replace('{{ RESOURCE_ID }}', $id, FakeResponseLoader::load($name)), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @param  list<array<string, mixed>>  $items
 * @return array<string, mixed>
 */
function creditsPagingPage(string $key, array $items, ?string $nextFrom): array
{
    return [
        'count' => count($items),
        '_embedded' => [$key => $items],
        '_links' => [
            'self' => ['href' => 'https://api.mollie.com/v2/payments/tr_paged/'.$key, 'type' => 'application/hal+json'],
            'previous' => null,
            'next' => $nextFrom === null ? null : [
                'href' => 'https://api.mollie.com/v2/payments/tr_paged/'.$key.'?from='.$nextFrom.'&limit='.MolliePaymentCredits::PAGE_SIZE,
                'type' => 'application/hal+json',
            ],
        ],
    ];
}

/** A renewal of 23.80, booked, with the paid delivery on record. */
function creditsPagingWorld(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Paged Co', 'email' => uniqid('paged-').'@x.test', 'billing_country' => 'DE']);
    $billable->forceFill([
        'mollie_customer_id' => 'cst_paged',
        'mollie_mandate_id' => 'mdt_paged',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_paged'],
    ])->save();

    BillingProcessedWebhook::create([
        'mollie_payment_id' => 'tr_paged',
        'event_signature' => BillingProcessedWebhook::outcomeSignature('tr_paged', 'paid'),
        'received_at' => BillingTime::nowUtc()->subDays(5),
        'processed_at' => BillingTime::nowUtc()->subDays(5),
    ]);

    BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_paged',
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

    return $billable->refresh();
}

/** @return array<string, mixed> */
function creditsPagingPayment(TestBillable $billable, ?string $refunded, ?string $chargedBack): array
{
    $payment = creditsPagingSample('payment', 'tr_paged');

    $payment['status'] = 'paid';
    $payment['amount'] = ['value' => '23.80', 'currency' => 'EUR'];
    $payment['amountRefunded'] = ['value' => $refunded ?? '0.00', 'currency' => 'EUR'];
    if ($chargedBack !== null) {
        $payment['amountChargedBack'] = ['value' => $chargedBack, 'currency' => 'EUR'];
    }
    $payment['metadata'] = ['billable_type' => $billable->getMorphClass(), 'billable_id' => (string) $billable->getKey()];
    $payment['sequenceType'] = 'recurring';
    $payment['subscriptionId'] = 'sub_paged';
    $payment['customerId'] = 'cst_paged';
    $payment['mandateId'] = 'mdt_paged';
    $payment['countryCode'] = 'DE';
    $payment['_links']['refunds'] = ['href' => 'https://api.mollie.com/v2/payments/tr_paged/refunds', 'type' => 'application/hal+json'];
    $payment['_links']['chargebacks'] = ['href' => 'https://api.mollie.com/v2/payments/tr_paged/chargebacks', 'type' => 'application/hal+json'];

    return $payment;
}

it('books the refunds of every page', function (): void {
    $billable = creditsPagingWorld();

    $refund = function (string $id): array {
        $refund = creditsPagingSample('refund', $id);
        $refund['amount'] = ['value' => '5.95', 'currency' => 'EUR'];
        $refund['paymentId'] = 'tr_paged';

        return $refund;
    };

    $first = creditsPagingPage('refunds', [$refund('re_page1')], 're_page2');
    $second = creditsPagingPage('refunds', [$refund('re_page2')], null);

    $mollie = Mollie::fake([
        GetPaymentRequest::class => MockResponse::ok(creditsPagingPayment($billable, '11.90', null)),
        GetPaginatedPaymentRefundsRequest::class => new SequenceMockResponse(MockResponse::ok($first), MockResponse::ok($second)),
        DynamicGetRequest::class => MockResponse::ok($first),
    ]);

    test()->postJson(route('billing.webhook'), ['id' => 'tr_paged'])->assertStatus(200);

    // The second page was asked for from where the first one ended.
    $mollie->assertSent(fn (PendingRequest $r): bool => $r->getRequest() instanceof GetPaginatedPaymentRefundsRequest
        && str_contains($r->createPsrRequest()->getUri()->getQuery(), 'from=re_page2'));

    $notes = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->get();

    expect($notes)->toHaveCount(2)
        ->and((int) $notes->sum('amount_gross'))->toBe(-1190)
        ->and((int) BillingInvoice::query()->where('mollie_payment_id', 'tr_paged')->sole()->refunded_net)->toBe(1000);
});

it('books the chargebacks of every page', function (): void {
    $billable = creditsPagingWorld();

    $chargeback = function (string $id): array {
        $chargeback = creditsPagingSample('chargeback', $id);
        $chargeback['amount'] = ['value' => '5.95', 'currency' => 'EUR'];
        $chargeback['paymentId'] = 'tr_paged';

        return $chargeback;
    };

    $first = creditsPagingPage('chargebacks', [$chargeback('chb_page1')], 'chb_page2');
    $second = creditsPagingPage('chargebacks', [$chargeback('chb_page2')], null);

    Mollie::fake([
        GetPaymentRequest::class => MockResponse::ok(creditsPagingPayment($billable, null, '11.90')),
        GetPaginatedPaymentChargebacksRequest::class => new SequenceMockResponse(MockResponse::ok($first), MockResponse::ok($second)),
        DynamicGetRequest::class => MockResponse::ok($first),
    ]);

    test()->postJson(route('billing.webhook'), ['id' => 'tr_paged'])->assertStatus(200);

    $notes = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->get();

    expect($notes)->toHaveCount(2)
        ->and((int) $notes->sum('amount_gross'))->toBe(-1190)
        ->and((int) BillingInvoice::query()->where('mollie_payment_id', 'tr_paged')->sole()->refunded_net)->toBe(1000)
        ->and($billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::PastDue);
});
