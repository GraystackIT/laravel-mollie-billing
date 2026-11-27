<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Services\Webhook\SingleChargeHandler;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\EInvoiceFixtures;
use GraystackIT\MollieBilling\Tests\Support\PdfText;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mollie\Api\Http\Requests\CreatePaymentRequest;

/*
 * The overage final invoice of an IMMEDIATE cancellation states the real end.
 *
 * Period 1–31 March, cancelled immediately on 12 March: the accrued overage was invoiced
 * for "1 March – next billing date", a billing date the subscription never reaches, so the
 * invoice said 1–31 March. It must say 1–12 March. A graceful cancel runs to the period end
 * and keeps it.
 */

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
    EInvoiceFixtures::configureSeller();
    config()->set('mollie-billing.invoices.locale', 'de');

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => ['monthly' => [
            'base_price_net' => 1000,
            'seat_price_net' => null,
            'included_usages' => ['SMS' => 100],
            'usage_overage_prices' => ['SMS' => 2],
        ]],
    ]);
});

afterEach(fn () => Carbon::setTestNow());

function immediateCancelOverageBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Kundin GmbH', 'email' => uniqid('icop-').'@x.test']);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => '2026-03-01 00:00:00',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_icop',
        'mollie_mandate_id' => 'mdt_icop',
        'subscription_meta' => ['mollie_subscription_id' => 'sub_icop'],
        'allows_billing_overage' => true,
    ])->save();

    $billable->createWallet(['name' => 'SMS', 'slug' => 'SMS'])->forceWithdraw(50);

    return $billable->refresh();
}

/** Mollie: the subscription cancel succeeds, an overage payment is created as $paymentId. */
function immediateCancelOverageMollie(string $paymentId): void
{
    $client = Mockery::mock(\Mollie\Api\MollieApiClient::class);
    $client->shouldReceive('setIdempotencyKey')->andReturnUsing(fn () => $client);
    $client->shouldReceive('resetIdempotencyKey')->andReturnUsing(fn () => $client);
    $client->shouldReceive('send')->andReturnUsing(fn ($request) => $request instanceof CreatePaymentRequest
        ? (object) ['id' => $paymentId, 'status' => 'open']
        : (object) ['id' => 'sub_icop', 'status' => 'canceled']);
    \Mollie\Laravel\Facades\Mollie::swap($client);
}

/** The webhook books the paid overage payment of the snapshot in flight. */
function immediateCancelOverageSettle(TestBillable $billable, string $paymentId): BillingInvoice
{
    $pending = app(ChargeUsageOverageDirectly::class)->pendingCharge($billable->refresh());
    expect($pending['payment_id'] ?? null)->toBe($paymentId);

    $gross = 0;
    foreach ($pending['line_items'] as $line) {
        $gross += (int) round((int) $line['total_net'] * 1.2);
    }

    app(SingleChargeHandler::class)->paid((object) [
        'id' => $paymentId,
        'status' => 'paid',
        'amount' => (object) ['value' => number_format($gross / 100, 2, '.', ''), 'currency' => 'EUR'],
        'createdAt' => Carbon::now()->toIso8601String(),
        'paidAt' => Carbon::now()->toIso8601String(),
    ], $billable->refresh(), 'overage', ['type' => 'overage', 'line_items' => $pending['line_items']]);

    return BillingInvoice::query()->where('mollie_payment_id', $paymentId)->firstOrFail();
}

function immediateCancelOveragePdfText(BillingInvoice $invoice): string
{
    expect(app(InvoiceService::class)->regeneratePdf($invoice))->toBeTrue();
    $invoice->refresh();

    return PdfText::of((string) Storage::disk($invoice->pdf_disk)->get($invoice->pdf_path));
}

it('invoices the overage of an immediate cancellation up to the day it ended', function (): void {
    $billable = immediateCancelOverageBillable();
    immediateCancelOverageMollie('tr_icop_now');

    Carbon::setTestNow('2026-03-12 10:00:00');
    app(CancelSubscription::class)->handle($billable->refresh(), immediately: true);

    // The snapshot the webhook books from already carries the real end.
    $snapshot = $billable->refresh()->getBillingSubscriptionMeta()[ChargeUsageOverageDirectly::META_KEY];
    expect(Carbon::parse($snapshot['period_end'])->utc()->toDateTimeString())->toBe('2026-03-13 00:00:00')
        ->and(Carbon::parse($snapshot['line_items'][0]['period_end'])->utc()->toDateTimeString())->toBe('2026-03-13 00:00:00');

    Carbon::setTestNow('2026-03-14 09:00:00');
    $invoice = immediateCancelOverageSettle($billable, 'tr_icop_now');

    $overageLines = array_values(array_filter((array) $invoice->line_items, fn ($l) => ($l['kind'] ?? null) === 'overage'));

    expect($invoice->period_start->toDateString())->toBe('2026-03-01')
        ->and($invoice->period_end->utc()->toDateTimeString())->toBe('2026-03-13 00:00:00')
        ->and($overageLines)->not->toBeEmpty()
        ->and(Carbon::parse($overageLines[0]['period_start'])->utc()->toDateString())->toBe('2026-03-01')
        ->and(Carbon::parse($overageLines[0]['period_end'])->utc()->toDateTimeString())->toBe('2026-03-13 00:00:00');

    // Exclusive end, inclusive print: the service ran through 12 March.
    $text = immediateCancelOveragePdfText($invoice);
    expect($text)->toContain('Leistungszeitraum: 01.03.2026 – 12.03.2026')
        ->and($text)->not->toContain('31.03.2026');
});

it('invoices an immediate cancellation on the period\'s first day as that one day', function (): void {
    $billable = immediateCancelOverageBillable();
    immediateCancelOverageMollie('tr_icop_first_day');

    Carbon::setTestNow('2026-03-01 15:00:00');
    app(CancelSubscription::class)->handle($billable->refresh(), immediately: true);

    Carbon::setTestNow('2026-03-02 09:00:00');
    $invoice = immediateCancelOverageSettle($billable, 'tr_icop_first_day');

    expect($invoice->period_end->utc()->toDateTimeString())->toBe('2026-03-02 00:00:00')
        ->and(immediateCancelOveragePdfText($invoice))->toContain('Leistungsdatum: 01.03.2026');
});

it('keeps the period end for an immediate cancellation on the period\'s last day', function (): void {
    $billable = immediateCancelOverageBillable();
    immediateCancelOverageMollie('tr_icop_last_day');

    // 31 March 23:00: the next day starts the next period anyway.
    Carbon::setTestNow('2026-03-31 23:00:00');
    app(CancelSubscription::class)->handle($billable->refresh(), immediately: true);

    Carbon::setTestNow('2026-04-02 09:00:00');
    $invoice = immediateCancelOverageSettle($billable, 'tr_icop_last_day');

    expect($invoice->period_end->utc()->toDateTimeString())->toBe('2026-04-01 00:00:00')
        ->and(immediateCancelOveragePdfText($invoice))->toContain('Leistungszeitraum: 01.03.2026 – 31.03.2026');
});

it('keeps the full period for the overage of a graceful cancellation', function (): void {
    $billable = immediateCancelOverageBillable();
    immediateCancelOverageMollie('tr_icop_graceful');

    Carbon::setTestNow('2026-03-12 10:00:00');
    app(CancelSubscription::class)->handle($billable->refresh());

    expect($billable->refresh()->getBillingSubscriptionEndsAt()?->utc()->toDateString())->toBe('2026-04-01');

    // The last sweep before the end charges it, the day before (PrepareUsageOverageJob case B).
    Carbon::setTestNow('2026-03-31 02:00:00');
    app(ChargeUsageOverageDirectly::class)->handle($billable->refresh());

    Carbon::setTestNow('2026-04-03 09:00:00');
    $invoice = immediateCancelOverageSettle($billable, 'tr_icop_graceful');

    expect($invoice->period_start->toDateString())->toBe('2026-03-01')
        ->and($invoice->period_end->utc()->toDateString())->toBe('2026-04-01')
        ->and(immediateCancelOveragePdfText($invoice))->toContain('Leistungszeitraum: 01.03.2026 – 31.03.2026');
});

it('keeps the full period for the overage charged before an ordinary renewal', function (): void {
    $billable = immediateCancelOverageBillable();
    immediateCancelOverageMollie('tr_icop_renewal');

    Carbon::setTestNow('2026-03-31 02:00:00');
    app(ChargeUsageOverageDirectly::class)->handle($billable->refresh());

    Carbon::setTestNow('2026-03-31 09:00:00');
    $invoice = immediateCancelOverageSettle($billable, 'tr_icop_renewal');

    expect($invoice->period_end->utc()->toDateString())->toBe('2026-04-01')
        ->and(immediateCancelOveragePdfText($invoice))->toContain('Leistungszeitraum: 01.03.2026 – 31.03.2026');
});
