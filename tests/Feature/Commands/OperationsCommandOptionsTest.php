<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingOssExport;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * The command options no test exercised: `billing:simulate` with `--usage-type`,
 * `--withdraw`, `--plan`, `--gross` and `--yes`, and `billing:oss-export` writing its file.
 */
beforeEach(function (): void {
    Notification::fake();
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
    config()->set('mollie-billing.billable_model', TestBillable::class);
    config()->set('mollie-billing.vat.seller_country', 'AT');

    config()->set('mollie-billing-plans.usage_types', ['Tokens' => ['rollover' => false]]);

    foreach (['pro' => 1000, 'free' => 0, 'basic' => 0] as $code => $price) {
        config()->set("mollie-billing-plans.plans.{$code}", [
            'name' => ucfirst($code), 'tier' => 1, 'included_seats' => 1, 'feature_keys' => [], 'allowed_addons' => [],
            'intervals' => ['monthly' => [
                'base_price_net' => $price, 'seat_price_net' => null,
                'included_usages' => ['Tokens' => 100],
                'usage_overage_prices' => $price > 0 ? ['Tokens' => 10] : [],
            ]],
        ]);
    }
});

function commandOptionsBillable(array $attributes = []): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Command Co',
        'email' => uniqid('command-').'@x.test',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
    ]);

    $billable->forceFill(array_merge([
        'mollie_customer_id' => 'cst_cmd',
        'mollie_mandate_id' => 'mdt_cmd',
        'allows_billing_overage' => true,
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
        'subscription_meta' => ['seat_count' => 1, 'mollie_subscription_id' => 'sub_cmd'],
    ], $attributes))->save();

    return $billable->refresh();
}

/** The amount string of a CreatePaymentRequest. */
function commandOptionsAmount(CreatePaymentRequest $request): string
{
    $amount = (new ReflectionObject($request))->getProperty('amount');
    $amount->setAccessible(true);

    return (string) $amount->getValue($request)->value;
}

it('billing:simulate overage-charge --usage-type --withdraw — withdraws those units and charges the overage', function (): void {
    $billable = commandOptionsBillable();
    $billable->creditBillingUsage('Tokens', 100);

    $charged = [];
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$charged): object {
        if ($request instanceof CreatePaymentRequest) {
            $charged[] = commandOptionsAmount($request);
        }

        return (object) ['id' => 'tr_cmd_overage', 'status' => 'open'];
    });

    $this->artisan('billing:simulate', [
        'flow' => 'overage-charge',
        '--billable' => $billable->getKey(),
        '--usage-type' => 'Tokens',
        '--withdraw' => 130,
        '--yes' => true,
    ])->assertSuccessful();

    // 130 withdrawn from 100: 30 over at 10 ct = 3.00 net, 3.60 with 20 %.
    expect((int) $billable->fresh()->getWallet('Tokens')->balanceInt)->toBe(-30)
        ->and($charged)->toBe(['3.60']);
});

it('billing:simulate renewal --gross — charges exactly the amount given and books the renewal', function (): void {
    $billable = commandOptionsBillable();

    $charged = null;
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$charged): object {
        if ($request instanceof CreatePaymentRequest) {
            $charged = commandOptionsAmount($request);

            return (object) ['id' => 'tr_cmd_renewal', 'status' => 'open'];
        }

        if ($request instanceof GetPaymentRequest) {
            return (object) [
                'id' => 'tr_cmd_renewal',
                'status' => 'paid',
                'amount' => (object) ['value' => $charged, 'currency' => 'EUR'],
                'paidAt' => BillingTime::nowUtc()->toIso8601String(),
                'countryCode' => 'AT',
                'metadata' => null,
            ];
        }

        return (object) ['id' => 'obj_cmd'];
    });

    $this->artisan('billing:simulate', [
        'flow' => 'renewal',
        '--billable' => $billable->getKey(),
        '--gross' => '12.00',
        '--yes' => true,
    ])->assertSuccessful();

    $invoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_cmd_renewal')->first();

    // 10.00 net + 20 %.
    expect($charged)->toBe('12.00')
        ->and($invoice)->not->toBeNull()
        ->and((int) $invoice->amount_gross)->toBe(1200)
        ->and((int) $invoice->amount_net)->toBe(1000);
});

it('billing:simulate apply-scheduled-change --plan — schedules that plan and applies it', function (): void {
    $billable = commandOptionsBillable([
        'mollie_customer_id' => null,
        'mollie_mandate_id' => null,
        'subscription_source' => SubscriptionSource::Local,
        'subscription_plan_code' => 'free',
    ]);

    Mollie::shouldReceive('send')->never();

    $this->artisan('billing:simulate', [
        'flow' => 'apply-scheduled-change',
        '--billable' => $billable->getKey(),
        '--plan' => 'basic',
        '--yes' => true,
    ])->assertSuccessful();

    $billable->refresh();

    expect($billable->subscription_plan_code)->toBe('basic')
        ->and($billable->scheduled_change_at)->toBeNull();
});

it('billing:oss-export {year} — writes the export for that year and says where', function (): void {
    $this->artisan('billing:oss-export', ['year' => 2026])->assertSuccessful();

    $export = BillingOssExport::query()->where('year', 2026)->first();
    $files = Storage::disk('local')->allFiles();

    expect($files)->toHaveCount(1)
        ->and($files[0])->toContain('oss-export-2026');

    // …and the run is on record as finished, where the admin page lists it.
    expect($export)->not->toBeNull()
        ->and($export->status)->toBe(\GraystackIT\MollieBilling\Enums\OssExportStatus::Ready)
        ->and($export->path)->toBe($files[0]);
});
