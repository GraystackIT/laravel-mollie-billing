<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\SeatsChanged;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;
use Mollie\Laravel\Facades\Mollie;

/**
 * A change's announcements must not be able to undo it, and its prices must not be able to
 * disagree with themselves.
 *
 * `update()` wraps the whole change — including the Mollie payments and refunds
 * `applyProrata()` posts — in one transaction, and events are the documented extension seam.
 * A throwing listener could therefore roll back the local half of settled money.
 */
beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => ['print-gateway'],
        'intervals' => [
            'monthly' => ['base_price_net' => 2900, 'seat_price_net' => 500, 'included_usages' => []],
        ],
    ]);
    config()->set('mollie-billing-plans.addons', [
        'print-gateway' => ['name' => 'Print Gateway', 'feature_keys' => [], 'intervals' => ['monthly' => ['price_net' => 900]]],
    ]);
});

/** A billable whose app bills `print-gateway` three times over. */
function quantityBillable(): TestBillable
{
    $billable = new class extends TestBillable
    {
        protected $table = 'test_billables';

        public function getBillingAddonQuantity(string $addonCode): int
        {
            return $addonCode === 'print-gateway' ? 3 : 1;
        }
    };

    $billable->forceFill([
        'name' => 'Quantity Ltd',
        'email' => uniqid('qty-').'@x.test',
        'billing_country' => 'AT',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(6),
        'active_addon_codes' => ['print-gateway'],
        'mollie_customer_id' => 'cst_qty',
        'mollie_mandate_id' => 'mdt_qty',
        'subscription_meta' => ['mollie_subscription_id' => 'sub_qty', 'seat_count' => 1],
    ])->save();

    return $billable->refresh();
}

it('keeps the change when a listener on its own event throws', function (): void {
    $billable = quantityBillable();

    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_qty', 'status' => 'active']);

    // Dispatched inside the transaction, this rolled back the seat count — and, on a change
    // that had refunded something, the credit note and the `refunded_net` increment too,
    // while Mollie had already paid the customer. The retry then refunded a second time.
    Event::listen(SeatsChanged::class, function (): void {
        throw new RuntimeException('CRM push exploded');
    });

    // The listener's failure still surfaces — it is an app bug and hiding it would be worse
    // — but it surfaces *after* the commit, so nothing it touched is undone.
    expect(fn () => app(UpdateSubscription::class)->update($billable, ['seats' => 2]))
        ->toThrow(RuntimeException::class);

    expect($billable->fresh()->getBillingSeatCount())->toBe(2);
});

it('prices a quantitative addon by its real quantity on both sides of a change', function (): void {
    $billable = quantityBillable();

    // `SubscriptionAmount::net()` reads getBillingAddonQuantity() — 2900 + 3 × 900 = 5600 —
    // and its docblock promises the override is honoured "in every place where pricing is
    // calculated". The intent hardcoded 1, so the prorata window priced one unit while the
    // recurring window billed three.
    $preview = \GraystackIT\MollieBilling\Facades\MollieBilling::preview()
        ->previewUpdate($billable, ['seats' => 2]);

    expect($preview['newPriceNet'])->toBe(5600 + 500);
});
