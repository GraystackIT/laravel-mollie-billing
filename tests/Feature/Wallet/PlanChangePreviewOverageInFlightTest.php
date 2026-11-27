<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * The plan-change preview says WHEN the excess is collected.
 *
 * The excess a plan change bills is charged with the change — unless another overage charge
 * is still in flight: `subscription_meta.usage_overage` is a single slot, so
 * `ChargeUsageOverageDirectly::handleExplicit()` refuses, and `restoreUnchargedExcess()`
 * leaves the units on the wallet, with their price, for the next period-end sweep. The
 * screen listed them under what is due now either way. Decision 8 of 26 November 2026: in
 * that case say "charged with the next debit, once the current one has completed".
 */
beforeEach(function (): void {
    Notification::fake();
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
    config()->set('mollie-billing.billable_model', TestBillable::class);

    config()->set('mollie-billing-plans.usage_types', ['SMS' => ['rollover' => false]]);

    config()->set('mollie-billing-plans.plans.big', [
        'name' => 'Big', 'tier' => 2, 'included_seats' => 1, 'feature_keys' => [], 'allowed_addons' => [],
        'intervals' => ['monthly' => [
            'base_price_net' => 2900, 'seat_price_net' => null,
            'included_usages' => ['SMS' => 50],
            'usage_overage_prices' => ['SMS' => 15],
        ]],
    ]);
    config()->set('mollie-billing-plans.plans.small', [
        'name' => 'Small', 'tier' => 1, 'included_seats' => 1, 'feature_keys' => [], 'allowed_addons' => [],
        'intervals' => ['monthly' => [
            'base_price_net' => 990, 'seat_price_net' => null,
            'included_usages' => ['SMS' => 20],
            'usage_overage_prices' => ['SMS' => 18],
        ]],
    ]);
});

/**
 * On Big, an hour into the period, 40 SMS used beyond what the renewal left (−20). With
 * `$overageCharge` the sweep's charge for last period's 30 is pending: 'open' still in
 * flight, 'settled' already invoiced (the webhook has not cleared the status yet).
 */
function inFlightOveragePreviewBillable(string $overageCharge): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'In Flight Co', 'email' => uniqid('inflight-').'@x.test']);

    $meta = ['seat_count' => 1, 'mollie_subscription_id' => 'sub_inflight'];

    if ($overageCharge !== 'none') {
        $meta[ChargeUsageOverageDirectly::META_KEY] = [
            'payment_id' => 'tr_inflight_sepa',
            'line_items' => [[
                'kind' => 'overage', 'code' => 'SMS', 'type' => 'SMS',
                'quantity' => 30, 'unit_price_net' => 15, 'total_net' => 450,
            ]],
            'created_at' => now()->subDay()->toIso8601String(),
        ];
        $meta['usage_overage_status'] = ChargeUsageOverageDirectly::STATUS_PENDING;
    }

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'big',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subHour(),
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_inflight',
        'mollie_mandate_id' => 'mdt_inflight',
        'allows_billing_overage' => true,
        'subscription_meta' => $meta,
    ])->save();

    $billable->refresh()->createWallet(['name' => 'SMS', 'slug' => 'SMS']);
    $billable->refresh()->getWallet('SMS')->forceWithdraw(20, ['type' => 'SMS']);

    if ($overageCharge === 'settled') {
        BillingInvoice::create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'mollie_payment_id' => 'tr_inflight_sepa',
            'invoice_kind' => InvoiceKind::Overage,
            'status' => InvoiceStatus::Paid,
            'country' => 'AT',
            'currency' => 'EUR',
            'amount_net' => 450,
            'amount_vat' => 90,
            'amount_gross' => 540,
            'line_items' => [],
        ]);
    }

    return $billable->refresh();
}

it('tells the preview whether the excess is collected with the change or after the charge in flight', function (string $overageCharge, bool $deferred): void {
    $billable = inFlightOveragePreviewBillable($overageCharge);

    $preview = app(PreviewService::class)->previewPlanChange($billable, 'small', 'monthly');

    expect((int) $preview['usageOverageChargeNet'])->toBeGreaterThan(0)
        ->and($preview['usageOverageAwaitsChargeInFlight'] ?? null)->toBe($deferred);
})->with([
    'no overage charge pending' => ['none', false],
    'the sweep\'s charge still open' => ['open', true],
    'the sweep\'s charge already invoiced' => ['settled', false],
]);

it('says on the plan screen that the excess waits for the charge in flight', function (string $locale, string $expected): void {
    app()->setLocale($locale);

    $billable = inFlightOveragePreviewBillable('open');

    MollieBilling::resolveBillableUsing(fn () => TestBillable::query()->find($billable->getKey()) ?? $billable);
    MollieBilling::authUsing(fn (): bool => true);

    Livewire::test('mollie-billing::plan-change')
        ->call('previewFor', 'small')
        ->assertSee($expected);
})->with([
    'en' => ['en', 'The overage will be charged with the next debit, once the current one has completed.'],
    'de' => ['de', 'Der Mehrverbrauch wird mit der nächsten Abbuchung berechnet, sobald die laufende abgeschlossen ist.'],
]);

it('says nothing of the kind when no other overage charge is in flight', function (): void {
    app()->setLocale('en');

    $billable = inFlightOveragePreviewBillable('none');

    MollieBilling::resolveBillableUsing(fn () => TestBillable::query()->find($billable->getKey()) ?? $billable);
    MollieBilling::authUsing(fn (): bool => true);

    Livewire::test('mollie-billing::plan-change')
        ->call('previewFor', 'small')
        ->assertSee('Overage')
        ->assertDontSee('once the current one has completed');
});
