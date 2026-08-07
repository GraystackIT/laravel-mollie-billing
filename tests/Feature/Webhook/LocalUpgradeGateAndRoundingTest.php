<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\DuplicatePaymentReceived;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * The Local→Mollie upgrade, on both counts it got wrong.
 *
 * It is an activation path — it creates a Mollie subscription — but it carried
 * only the per-payment invoice guard, which cannot see a *second* upgrade payment.
 * And it charged a gross rounded over the summed net while the webhook books the
 * same lines with per-line VAT, so collected and booked disagreed by a cent or two
 * on ordinary multi-line upgrades. That cent is not cosmetic: a later full refund
 * asks Mollie for the booked figure, and Mollie refuses to refund more than the
 * payment holds.
 *
 * `multi` below is four lines at 5.50 € — the shape that diverges by 2 cents.
 */
class LocalUpgradeFakeWebhookController extends MollieWebhookController
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

    LocalUpgradeFakeWebhookController::$payments = [];
    $this->app->bind(MollieWebhookController::class, LocalUpgradeFakeWebhookController::class);

    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free',
        'tier' => 1,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []]],
    ]);

    // Plan 5.50 + three addons at 5.50 = four lines of 550 net. Per line at 19%:
    // 4 × (550 + 105) = 2620. Rounded over the sum: 2200 + 418 = 2618.
    config()->set('mollie-billing-plans.plans.multi', [
        'name' => 'Multi',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => ['a1', 'a2', 'a3'],
        'intervals' => ['monthly' => ['base_price_net' => 550, 'seat_price_net' => null, 'included_usages' => []]],
    ]);

    foreach (['a1', 'a2', 'a3'] as $code) {
        config()->set("mollie-billing-plans.addons.{$code}", [
            'name' => strtoupper($code),
            'feature_keys' => [],
            'intervals' => ['monthly' => ['price_net' => 550]],
        ]);
    }
});

function localUpgradeBillable(array $addons = []): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Upgrade Co',
        'email' => uniqid('upgrade-').'@x.test',
        'billing_country' => 'DE',
    ]);

    $b->forceFill([
        'mollie_customer_id' => 'cst_upgrade',
        'mollie_mandate_id' => 'mdt_upgrade',
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'free',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'subscription_meta' => ['seat_count' => 1] + ($addons !== [] ? ['active_addon_codes' => $addons] : []),
    ])->save();

    return $b->refresh();
}

function deliverUpgrade(TestBillable $billable, string $paymentId, string $gross, array $addonCodes = []): void
{
    LocalUpgradeFakeWebhookController::$payments[$paymentId] = (object) [
        'id' => $paymentId,
        'status' => 'paid',
        'amount' => (object) ['value' => $gross, 'currency' => 'EUR'],
        'paidAt' => now()->toIso8601String(),
        'subscriptionId' => null,
        'customerId' => 'cst_upgrade',
        'mandateId' => 'mdt_upgrade',
        'countryCode' => 'DE',
        'metadata' => [
            'type' => 'subscription',
            'upgrade_from_local' => true,
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'plan_code' => 'multi',
            'interval' => 'monthly',
            'addon_codes' => $addonCodes,
            'extra_seats' => 0,
        ],
    ];

    test()->postJson(route('billing.webhook'), ['id' => $paymentId])->assertStatus(200);
}

it('prices the upgrade with per-line VAT, so the charge equals what the invoice books', function (): void {
    $billable = localUpgradeBillable(['a1', 'a2', 'a3']);

    // Addons passed explicitly: previewPlanChange() alone would carry only the
    // plan line, and the divergence needs several lines to show up.
    $preview = app(PreviewService::class)->previewUpdate($billable, [
        'plan_code' => 'multi',
        'interval' => 'monthly',
        'addons' => ['a1' => 1, 'a2' => 1, 'a3' => 1],
    ]);

    // Four lines of 550 at 19% — the two roundings differ by 2 cents, and the
    // invoice side is the one that cannot be changed (it sums per-line VAT).
    $perLine = BillingMoney::grossWithLineVat([550, 550, 550, 550], 19.0);
    expect($perLine)->toBe(2620)
        ->and(2200 + (int) round(2200 * 19 / 100))->toBe(2618)
        ->and((int) $preview['grossTotal'])->toBe($perLine);

    // The VAT figure shown must match the gross it belongs to.
    expect((int) $preview['vatAmount'])->toBe($perLine - 2200);
});

it('books the upgrade invoice at the charged amount without a mismatch event', function (): void {
    Event::fake([PaymentAmountMismatch::class]);

    $billable = localUpgradeBillable(['a1', 'a2', 'a3']);
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_upgrade_1']);

    // Charged exactly what previewUpdate() now reports.
    deliverUpgrade($billable, 'tr_upg_multi', '26.20', ['a1', 'a2', 'a3']);

    $invoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_upg_multi')->sole();

    expect((int) $invoice->amount_net)->toBe(2200)
        ->and((int) $invoice->amount_gross)->toBe(2620)
        // No adjustment line: charged and booked agree, so nothing to reconcile.
        ->and(collect($invoice->line_items)->firstWhere('kind', 'adjustment'))->toBeNull();

    Event::assertNotDispatched(PaymentAmountMismatch::class);
});

it('reports a second upgrade payment as a duplicate instead of creating a second Mollie subscription', function (): void {
    Event::fake([DuplicatePaymentReceived::class]);

    $billable = localUpgradeBillable();

    $created = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$created) {
        if ($request instanceof CreateSubscriptionRequest) {
            $id = 'sub_upgrade_'.(count($created) + 1);
            $created[] = $id;

            return (object) ['id' => $id];
        }

        return (object) ['id' => 'unexpected'];
    });

    // Two tabs: UpgradeLocalToMollie mints a payment per attempt and cancels none.
    deliverUpgrade($billable, 'tr_upg_a', '6.55');
    deliverUpgrade($billable, 'tr_upg_b', '6.55');

    // Exactly one subscription at Mollie. Without the gate, `sub_upgrade_2` was
    // created and its id overwrote the first in meta — leaving a live subscription
    // charging the customer with nothing locally pointing at it.
    expect($created)->toHaveCount(1)
        ->and($billable->refresh()->getBillingSubscriptionMeta()['mollie_subscription_id'])->toBe('sub_upgrade_1')
        ->and($billable->subscription_source)->toBe(SubscriptionSource::Mollie);

    // And only the first payment produced an invoice.
    expect(BillingInvoice::query()->count())->toBe(1)
        ->and(BillingInvoice::query()->where('mollie_payment_id', 'tr_upg_b')->exists())->toBeFalse();

    Event::assertDispatched(DuplicatePaymentReceived::class);
});

it('swallows a re-delivery of the same upgrade payment without reporting a duplicate', function (): void {
    Event::fake([DuplicatePaymentReceived::class]);

    $billable = localUpgradeBillable();
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_upgrade_1']);

    deliverUpgrade($billable, 'tr_upg_same', '6.55');
    deliverUpgrade($billable, 'tr_upg_same', '6.55');

    // The invoice guard runs before the duplicate guard on purpose: a re-delivery of
    // the payment we already booked is nothing to tell the app about.
    expect(BillingInvoice::query()->count())->toBe(1);
    Event::assertNotDispatched(DuplicatePaymentReceived::class);
});
