<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Services\Webhook\SingleChargeHandler;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
 * The period an overage invoice states is the period the usage was in.
 *
 * The overage of a period is charged the day before the renewal, and a SEPA payment
 * settles days later — by which time the renewal has moved the billable's period on.
 * The invoice used to read the period from the billable at booking time, so October's
 * usage was invoiced as November's.
 */

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
    config()->set('mollie-billing.invoices.disk', 'local');

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

function overagePeriodBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Overage Co', 'email' => uniqid('op-').'@x.test']);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => '2026-10-01 00:00:00',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_op',
        'mollie_mandate_id' => 'mdt_op',
        'subscription_meta' => ['mollie_subscription_id' => 'sub_op'],
        'allows_billing_overage' => true,
    ])->save();

    $billable->createWallet(['name' => 'SMS', 'slug' => 'SMS'])->forceWithdraw(50);

    return $billable->refresh();
}

/** The sweep's charge, through the real service; Mollie answers with $paymentId. */
function chargeOverage(TestBillable $billable, string $paymentId, string $method = 'handle'): array
{
    $client = Mockery::mock(\Mollie\Api\MollieApiClient::class);
    $client->shouldReceive('setIdempotencyKey')->andReturnUsing(fn () => $client);
    $client->shouldReceive('resetIdempotencyKey')->andReturnUsing(fn () => $client);
    $client->shouldReceive('send')->andReturn((object) ['id' => $paymentId, 'status' => 'open']);
    \Mollie\Laravel\Facades\Mollie::swap($client);

    app(ChargeUsageOverageDirectly::class)->{$method}($billable->refresh());

    $pending = app(ChargeUsageOverageDirectly::class)->pendingCharge($billable->refresh());
    expect($pending['payment_id'] ?? null)->toBe($paymentId);

    return ['type' => 'overage', 'line_items' => $pending['line_items']];
}

/** Mollie answers $paymentId and records the metadata of every payment it is asked to create. */
function captureMollie(string $paymentId, array &$sentMetadata): void
{
    $client = Mockery::mock(\Mollie\Api\MollieApiClient::class);
    $client->shouldReceive('setIdempotencyKey')->andReturnUsing(fn () => $client);
    $client->shouldReceive('resetIdempotencyKey')->andReturnUsing(fn () => $client);
    $client->shouldReceive('send')->andReturnUsing(function ($request) use ($paymentId, &$sentMetadata) {
        $sentMetadata[] = $request->payload()->all()['metadata'] ?? null;

        return (object) ['id' => $paymentId, 'status' => 'open'];
    });
    \Mollie\Laravel\Facades\Mollie::swap($client);
}

function settleOverage(TestBillable $billable, string $paymentId, array $metadata, ?string $createdAt = null): BillingInvoice
{
    $gross = 0;
    foreach ($metadata['line_items'] as $line) {
        $gross += (int) round((int) $line['total_net'] * 1.2);
    }

    app(SingleChargeHandler::class)->paid((object) [
        'id' => $paymentId,
        'status' => 'paid',
        'amount' => (object) ['value' => number_format($gross / 100, 2, '.', ''), 'currency' => 'EUR'],
        'createdAt' => $createdAt,
        'paidAt' => Carbon::now()->toIso8601String(),
    ], $billable->refresh(), 'overage', $metadata);

    return BillingInvoice::query()->where('mollie_payment_id', $paymentId)->firstOrFail();
}

function renew(TestBillable $billable): void
{
    $billable->refresh()->forceFill(['subscription_period_starts_at' => '2026-11-01 00:00:00'])->save();
}

it('invoices October overage as October when the SEPA payment settles after the renewal', function (): void {
    $billable = overagePeriodBillable();

    // 31 October: the nightly sweep charges October's overage.
    Carbon::setTestNow('2026-10-31 02:00:00');
    $metadata = chargeOverage($billable, 'tr_overage_late');

    // 1 November: the renewal moves the period on.
    Carbon::setTestNow('2026-11-01 06:00:00');
    renew($billable);

    // 3 November: the SEPA payment for October's overage settles.
    Carbon::setTestNow('2026-11-03 09:00:00');
    $invoice = settleOverage($billable, 'tr_overage_late', $metadata, '2026-10-31T02:00:00+00:00');

    expect($invoice->period_start->toDateString())->toBe('2026-10-01')
        ->and($invoice->period_end->toDateString())->toBe('2026-11-01')
        ->and($invoice->line_items[0]['period_start'])->toStartWith('2026-10-01')
        ->and($invoice->line_items[0]['period_end'])->toStartWith('2026-11-01');
});

it('keeps the usage period for a retry created after the renewal', function (): void {
    $billable = overagePeriodBillable();

    Carbon::setTestNow('2026-10-31 02:00:00');
    chargeOverage($billable, 'tr_overage_first');

    // The first payment fails; the retry ladder creates the next one in November.
    Carbon::setTestNow('2026-11-02 02:00:00');
    renew($billable);
    $metadata = chargeOverage($billable, 'tr_overage_retry', 'retry');

    Carbon::setTestNow('2026-11-05 09:00:00');
    $invoice = settleOverage($billable, 'tr_overage_retry', $metadata, '2026-11-02T02:00:00+00:00');

    expect($invoice->period_start->toDateString())->toBe('2026-10-01')
        ->and($invoice->period_end->toDateString())->toBe('2026-11-01');
});

it('falls back to the period the payment was created in for a charge from before the upgrade', function (): void {
    $billable = overagePeriodBillable();

    Carbon::setTestNow('2026-10-31 02:00:00');
    $metadata = chargeOverage($billable, 'tr_overage_legacy');

    // A snapshot written by the previous version carries no period.
    $meta = $billable->refresh()->getBillingSubscriptionMeta();
    unset($meta['usage_overage']['period_start'], $meta['usage_overage']['period_end']);
    $billable->forceFill(['subscription_meta' => $meta])->save();

    Carbon::setTestNow('2026-11-01 06:00:00');
    renew($billable);

    Carbon::setTestNow('2026-11-03 09:00:00');
    $invoice = settleOverage($billable, 'tr_overage_legacy', $metadata, '2026-10-31T02:00:00+00:00');

    expect($invoice->period_start->toDateString())->toBe('2026-10-01')
        ->and($invoice->period_end->toDateString())->toBe('2026-11-01');
});

it('invoices overage settled within its own period for that period', function (): void {
    $billable = overagePeriodBillable();

    Carbon::setTestNow('2026-10-31 02:00:00');
    $metadata = chargeOverage($billable, 'tr_overage_card');
    $invoice = settleOverage($billable, 'tr_overage_card', $metadata, '2026-10-31T02:00:00+00:00');

    expect($invoice->period_start->toDateString())->toBe('2026-10-01')
        ->and($invoice->period_end->toDateString())->toBe('2026-11-01');
});

it('invoices a standing debt for its own period when it is collected later together with new overage', function (): void {
    $billable = overagePeriodBillable();
    $billable->forceFill(['subscription_period_starts_at' => '2026-09-01 00:00:00'])->save();

    // 30 September: September's overage is charged.
    Carbon::setTestNow('2026-09-30 02:00:00');
    chargeOverage($billable, 'tr_september');

    // 1 October the renewal; 7 October the cycle gives up and the debt stands.
    Carbon::setTestNow('2026-10-01 06:00:00');
    $billable->refresh()->forceFill(['subscription_period_starts_at' => '2026-10-01 00:00:00'])->save();
    Carbon::setTestNow('2026-10-07 02:00:00');
    expect(app(ChargeUsageOverageDirectly::class)->deferFailedCycle($billable->refresh()))->toBe('deferred');

    // 20 October: a plan change charges October's excess — and the standing debt travels with it.
    Carbon::setTestNow('2026-10-20 10:00:00');
    $sent = [];
    captureMollie('tr_recollect', $sent);
    app(ChargeUsageOverageDirectly::class)->handleExplicit($billable->refresh(), [[
        'kind' => 'overage', 'code' => 'SMS', 'type' => 'SMS', 'label' => 'Overage: SMS',
        'quantity' => 30, 'unit_price_net' => 2, 'total_net' => 60,
    ]]);

    expect(app(ChargeUsageOverageDirectly::class)->pendingCharge($billable->refresh())['line_items'])->toHaveCount(2)
        // The periods stay out of the payment's metadata (Mollie caps it at 1024 bytes).
        ->and($sent)->not->toBeEmpty()
        ->and(json_encode($sent[0]['line_items'] ?? null))->not->toContain('period_start');

    // 1 November the renewal, 3 November the SEPA payment settles.
    Carbon::setTestNow('2026-11-01 06:00:00');
    renew($billable);
    Carbon::setTestNow('2026-11-03 09:00:00');
    $invoice = settleOverage($billable, 'tr_recollect', ['type' => 'overage', 'line_items' => $sent[0]['line_items']], '2026-10-20T10:00:00+00:00');

    $periods = array_map(
        fn (array $line): string => substr((string) $line['period_start'], 0, 10).'/'.substr((string) $line['period_end'], 0, 10),
        $invoice->line_items,
    );

    expect($periods)->toBe(['2026-09-01/2026-10-01', '2026-10-01/2026-11-01'])
        ->and($invoice->period_start->toDateString())->toBe('2026-09-01')
        ->and($invoice->period_end->toDateString())->toBe('2026-11-01');
});

it('keeps each line\'s period when the reconciliation adds an adjustment line', function (): void {
    config()->set('mollie-billing.vat.seller_country', 'DE');

    $billable = overagePeriodBillable();
    $billable->forceFill(['subscription_period_starts_at' => '2026-09-01 00:00:00'])->save();

    Carbon::setTestNow('2026-09-30 02:00:00');
    chargeOverage($billable, 'tr_september_rc');

    Carbon::setTestNow('2026-10-01 06:00:00');
    $billable->refresh()->forceFill(['subscription_period_starts_at' => '2026-10-01 00:00:00'])->save();
    Carbon::setTestNow('2026-10-07 02:00:00');
    app(ChargeUsageOverageDirectly::class)->deferFailedCycle($billable->refresh());

    Carbon::setTestNow('2026-10-20 10:00:00');
    $sent = [];
    captureMollie('tr_recollect_rc', $sent);
    app(ChargeUsageOverageDirectly::class)->handleExplicit($billable->refresh(), [[
        'kind' => 'overage', 'code' => 'SMS', 'type' => 'SMS', 'label' => 'Overage: SMS',
        'quantity' => 30, 'unit_price_net' => 2, 'total_net' => 60,
    ]]);

    // While the SEPA payment settles, the customer's VAT number is validated: the supply
    // becomes reverse charge, every line is zero-rated and the reconciliation books the
    // VAT that was collected as an adjustment line.
    $billable->refresh()->forceFill(['vat_number' => 'ATU12345678'])->save();
    $billable->vatValidations()->create([
        'vat_number' => 'ATU12345678', 'country_code' => 'AT', 'valid' => true,
        'vies_response' => ['valid' => true], 'checked_at' => now(),
    ]);

    Carbon::setTestNow('2026-11-03 09:00:00');
    renew($billable);
    $invoice = settleOverage($billable, 'tr_recollect_rc', ['type' => 'overage', 'line_items' => $sent[0]['line_items']], '2026-10-20T10:00:00+00:00');

    expect(count($invoice->line_items))->toBeGreaterThan(2);

    $periods = array_map(
        fn (array $line): string => substr((string) $line['period_start'], 0, 10).'/'.substr((string) $line['period_end'], 0, 10),
        array_slice($invoice->line_items, 0, 2),
    );

    expect($periods)->toBe(['2026-09-01/2026-10-01', '2026-10-01/2026-11-01']);
});

it('keeps the usage period for a retry of a cycle that started before the upgrade', function (): void {
    $billable = overagePeriodBillable();

    Carbon::setTestNow('2026-10-31 02:00:00');
    chargeOverage($billable, 'tr_legacy_first');

    // A snapshot written by the previous version: no periods, neither on it nor on its lines.
    $meta = $billable->refresh()->getBillingSubscriptionMeta();
    unset($meta['usage_overage']['period_start'], $meta['usage_overage']['period_end']);
    foreach ($meta['usage_overage']['line_items'] as $i => $line) {
        unset($meta['usage_overage']['line_items'][$i]['period_start'], $meta['usage_overage']['line_items'][$i]['period_end']);
    }
    $billable->forceFill(['subscription_meta' => $meta])->save();

    // The payment fails; the retry is created after the renewal.
    Carbon::setTestNow('2026-11-02 02:00:00');
    renew($billable);
    $metadata = chargeOverage($billable, 'tr_legacy_retry', 'retry');

    Carbon::setTestNow('2026-11-05 09:00:00');
    $invoice = settleOverage($billable, 'tr_legacy_retry', $metadata, '2026-11-02T02:00:00+00:00');

    expect($invoice->period_start->toDateString())->toBe('2026-10-01')
        ->and($invoice->period_end->toDateString())->toBe('2026-11-01')
        ->and(substr((string) $invoice->line_items[0]['period_start'], 0, 10))->toBe('2026-10-01');
});

it('does not let an unreadable line period set or block the snapshot span', function (): void {
    $billable = overagePeriodBillable();

    Carbon::setTestNow('2026-10-20 10:00:00');
    $sent = [];
    captureMollie('tr_unreadable', $sent);
    app(ChargeUsageOverageDirectly::class)->handleExplicit($billable->refresh(), [
        ['kind' => 'overage', 'code' => 'SMS', 'type' => 'SMS', 'label' => 'A', 'quantity' => 1, 'unit_price_net' => 2, 'total_net' => 2,
            'period_start' => 'not-a-date', 'period_end' => 'not-a-date'],
        ['kind' => 'overage', 'code' => 'SMS', 'type' => 'SMS', 'label' => 'B', 'quantity' => 1, 'unit_price_net' => 2, 'total_net' => 2],
    ]);

    $pending = app(ChargeUsageOverageDirectly::class)->pendingCharge($billable->refresh());

    expect(substr((string) $pending['period_start'], 0, 10))->toBe('2026-10-01')
        ->and(substr((string) $pending['period_end'], 0, 10))->toBe('2026-11-01');
});
