<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\PlanChangeIntent;
use GraystackIT\MollieBilling\Services\Billing\ProrataComposer;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Tests\Support\SpyMollieSubscriptionPatcher;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mollie\Laravel\Facades\Mollie;

/**
 * A free plan that carries a paid addon is a supported shape — it stays on a Mollie
 * subscription (see FreeTargetAndMarkerAgreementTest) and is prorated like any other.
 *
 * ProrataComposer used to short-circuit on `isFreePlan()` twice, and `isFreePlan()` only
 * looks at base and seat price:
 *
 *  - free TARGET: every current component was credited and nothing of the target charged
 *    — an addon the customer KEEPS was refunded for the rest of the period and then used
 *    for free until the renewal.
 *  - free ORIGIN: compose() returned [] — an addon ADDED mid-period was never charged,
 *    and one REMOVED mid-period was never credited.
 */
beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();

    SpyMollieSubscriptionPatcher::$calls = [];
    $this->app->bind(MollieSubscriptionPatcher::class, SpyMollieSubscriptionPatcher::class);
    config()->set('mollie-billing.invoices.disk', 'local');
    config()->set('mollie-billing.vat.seller_country', 'AT');

    config()->set('mollie-billing-plans.plans', [
        'free' => [
            'name' => 'Free', 'tier' => 0, 'included_seats' => 1,
            'feature_keys' => [], 'allowed_addons' => ['storage', 'analytics'],
            'intervals' => ['monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []]],
        ],
        'pro' => [
            'name' => 'Pro', 'tier' => 2, 'included_seats' => 1,
            'feature_keys' => [], 'allowed_addons' => ['storage', 'analytics'],
            'intervals' => ['monthly' => ['base_price_net' => 2900, 'seat_price_net' => null, 'included_usages' => []]],
        ],
    ]);
    config()->set('mollie-billing-plans.addons', [
        'storage' => ['name' => 'Storage', 'feature_keys' => [], 'intervals' => ['monthly' => ['price_net' => 900]]],
        'analytics' => ['name' => 'Analytics', 'feature_keys' => [], 'intervals' => ['monthly' => ['price_net' => 600]]],
    ]);
});

function freeAddonBillable(string $plan, array $addons): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Free Addon Ltd',
        'email' => uniqid('free-addon-').'@x.test',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
        'mollie_customer_id' => 'cst_free_addon',
        'mollie_mandate_id' => 'mdt_free_addon',
    ]);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => $plan,
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => Carbon::parse('2026-01-01 00:00:00', 'UTC'),
        'active_addon_codes' => $addons,
        'subscription_meta' => ['seat_count' => 1, 'mollie_subscription_id' => 'sub_free_addon'],
    ])->save();

    return $billable->refresh();
}

function freeAddonRenewal(TestBillable $billable, array $lines): BillingInvoice
{
    $start = Carbon::parse('2026-01-01 00:00:00', 'UTC');
    $end = Carbon::parse('2026-02-01 00:00:00', 'UTC');
    $net = array_sum(array_map(fn (array $l): int => (int) $l['amount_net'], $lines));
    $vat = array_sum(array_map(fn (array $l): int => (int) round($l['amount_net'] * 0.2), $lines));

    return BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => uniqid('tr_free_addon_'),
        'mollie_subscription_id' => 'sub_free_addon',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => $net,
        'amount_vat' => $vat,
        'amount_gross' => $net + $vat,
        'line_items' => array_map(fn (array $l): array => $l + [
            'vat_rate' => 20.0,
            'vat_amount' => (int) round($l['amount_net'] * 0.2),
            'amount_gross' => $l['amount_net'] + (int) round($l['amount_net'] * 0.2),
            'period_start' => $start->toIso8601String(),
            'period_end' => $end->toIso8601String(),
        ], $lines),
        'period_start' => $start,
        'period_end' => $end,
    ])->refresh();
}

it('does not hand a kept addon out for free when the plan is downgraded to a free plan', function (): void {
    // Day 11 of 31 → 21 days left.
    $this->travelTo(Carbon::parse('2026-01-11 00:00:00', 'UTC'));

    $billable = freeAddonBillable('pro', ['storage']);
    freeAddonRenewal($billable, [
        ['kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1, 'unit_price_net' => 2900, 'amount_net' => 2900],
        ['kind' => 'addon', 'code' => 'storage', 'label' => 'Storage', 'quantity' => 1, 'unit_price_net' => 900, 'amount_net' => 900],
    ]);

    // Pro + storage → Free + storage. The addon is kept; only the plan goes.
    $lines = app(ProrataComposer::class)->compose(new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'pro', newPlan: 'free',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: ['storage' => 1], newAddons: ['storage' => 1],
    ));

    $net = array_sum(array_map(fn ($l): int => $l->amountNet, $lines));

    // Only the plan is given up: 2900 × 21/31 = 1965 back. The addon is paid and kept, so
    // it must come out at zero (credited 610 and charged 610, or not touched at all).
    expect($net)->toBe(-1965);
});

it('charges an addon that is added mid-period on a free plan that bills through Mollie', function (): void {
    $this->travelTo(Carbon::parse('2026-01-11 00:00:00', 'UTC'));

    // Free plan with one paid addon — source Mollie, renewal invoice for the addon only.
    $billable = freeAddonBillable('free', ['storage']);
    freeAddonRenewal($billable, [
        ['kind' => 'plan', 'code' => 'free', 'label' => 'Free', 'quantity' => 1, 'unit_price_net' => 0, 'amount_net' => 0],
        ['kind' => 'addon', 'code' => 'storage', 'label' => 'Storage', 'quantity' => 1, 'unit_price_net' => 900, 'amount_net' => 900],
    ]);

    $lines = app(ProrataComposer::class)->compose(new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'free', newPlan: 'free',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: ['storage' => 1], newAddons: ['storage' => 1, 'analytics' => 1],
    ));

    $chargeNet = array_sum(array_map(
        fn ($l): int => $l->amountNet,
        array_filter($lines, fn ($l): bool => $l->direction === 'charge'),
    ));

    // 600 × 21/31 = 406 — what the same addon costs mid-period on any paid plan.
    expect($chargeNet)->toBe(406);
});

/** Sum one stored pending line list, net. */
function freeAddonPendingNet(TestBillable $billable, string $key): int
{
    $pending = $billable->getBillingSubscriptionMeta()['pending_prorata_change'] ?? [];

    return (int) array_sum(array_map(fn (array $l): int => (int) $l['amount_net'], (array) ($pending[$key] ?? [])));
}

it('charges the kept addon through update() when the plan goes to free', function (): void {
    $this->travelTo(Carbon::parse('2026-01-11 00:00:00', 'UTC'));

    $billable = freeAddonBillable('pro', ['storage']);
    freeAddonRenewal($billable, [
        ['kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1, 'unit_price_net' => 2900, 'amount_net' => 2900],
        ['kind' => 'addon', 'code' => 'storage', 'label' => 'Storage', 'quantity' => 1, 'unit_price_net' => 900, 'amount_net' => 900],
    ]);

    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'tr_free_addon_keep', 'status' => 'open']);

    $result = app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'free', 'interval' => 'monthly', 'addons' => ['storage'], 'apply_at' => 'immediate',
    ]);

    $billable->refresh();

    // The addon is recharged for the 21 days it is kept (900 × 21/31 = 610), against the
    // 610 credited for it together with the plan's 1965: the customer is 1965 better off,
    // not 2575. The switch waits for that charge, like any plan change that has one.
    expect($result['pendingPaymentConfirmation'])->toBeTrue()
        ->and(freeAddonPendingNet($billable, 'charge_lines'))->toBe(610)
        ->and(freeAddonPendingNet($billable, 'refund_lines'))->toBe(-2575)
        ->and($billable->getBillingSubscriptionSource())->toBe(SubscriptionSource::Mollie->value);
});

it('charges an addon added on a free plan through update(), and tells Mollie at once', function (): void {
    $this->travelTo(Carbon::parse('2026-01-11 00:00:00', 'UTC'));

    $billable = freeAddonBillable('free', ['storage']);
    freeAddonRenewal($billable, [
        ['kind' => 'plan', 'code' => 'free', 'label' => 'Free', 'quantity' => 1, 'unit_price_net' => 0, 'amount_net' => 0],
        ['kind' => 'addon', 'code' => 'storage', 'label' => 'Storage', 'quantity' => 1, 'unit_price_net' => 900, 'amount_net' => 900],
    ]);

    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'tr_free_addon_add', 'status' => 'open']);

    app(UpdateSubscription::class)->update($billable, ['addons' => ['storage', 'analytics']]);

    $billable->refresh();

    // 600 × 21/31 = 406 is on its way to being collected; the addon is active and Mollie
    // bills it from the next renewal on.
    expect($billable->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'] ?? null)->toBe('tr_free_addon_add')
        ->and(freeAddonPendingNet($billable, 'charge_lines'))->toBe(406)
        ->and($billable->getActiveBillingAddonCodes())->toBe(['storage', 'analytics'])
        // One call to the patcher, in this same update(). (The spy files any free-plan
        // intent under 'cancel'; the real patcher decides on the target's net.)
        ->and(SpyMollieSubscriptionPatcher::$calls)->toHaveCount(1);
});

it('credits an addon removed on a free plan through update()', function (): void {
    $this->travelTo(Carbon::parse('2026-01-11 00:00:00', 'UTC'));

    $billable = freeAddonBillable('free', ['storage', 'analytics']);
    freeAddonRenewal($billable, [
        ['kind' => 'plan', 'code' => 'free', 'label' => 'Free', 'quantity' => 1, 'unit_price_net' => 0, 'amount_net' => 0],
        ['kind' => 'addon', 'code' => 'storage', 'label' => 'Storage', 'quantity' => 1, 'unit_price_net' => 900, 'amount_net' => 900],
        ['kind' => 'addon', 'code' => 'analytics', 'label' => 'Analytics', 'quantity' => 1, 'unit_price_net' => 600, 'amount_net' => 600],
    ]);

    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 're_free_addon_drop', 'status' => 'pending']);

    app(UpdateSubscription::class)->update($billable, ['addons' => ['storage']]);

    $billable->refresh();
    $credited = (int) BillingInvoice::query()
        ->where('billable_id', $billable->getKey())
        ->where('invoice_kind', InvoiceKind::Refund)
        ->sum('amount_net');

    // 600 × 21/31 = 406 back for the 21 days it is no longer used.
    expect($billable->getActiveBillingAddonCodes())->toBe(['storage'])
        ->and($credited)->toBe(-406);
});

it('previews exactly what the change then books', function (): void {
    $this->travelTo(Carbon::parse('2026-01-11 00:00:00', 'UTC'));

    $preview = app(\GraystackIT\MollieBilling\Services\Billing\PreviewService::class);

    $keeping = freeAddonBillable('pro', ['storage']);
    freeAddonRenewal($keeping, [
        ['kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1, 'unit_price_net' => 2900, 'amount_net' => 2900],
        ['kind' => 'addon', 'code' => 'storage', 'label' => 'Storage', 'quantity' => 1, 'unit_price_net' => 900, 'amount_net' => 900],
    ]);

    $adding = freeAddonBillable('free', ['storage']);
    freeAddonRenewal($adding, [
        ['kind' => 'addon', 'code' => 'storage', 'label' => 'Storage', 'quantity' => 1, 'unit_price_net' => 900, 'amount_net' => 900],
    ]);

    expect($preview->previewUpdate($keeping, ['plan_code' => 'free', 'interval' => 'monthly', 'addons' => ['storage']])['prorataTotalNet'])->toBe(-1965)
        ->and($preview->previewAddonChange($adding, ['storage', 'analytics'])['prorataTotalNet'])->toBe(406);
});
