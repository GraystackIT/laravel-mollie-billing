<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use GraystackIT\MollieBilling\Exceptions\DowngradeRequiresMandateException;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;

/*
 * The plan-change preview without a mandate.
 *
 * Excess the new plan cannot absorb is charged with the change, through the mandate. Without
 * one `ValidateSubscriptionChange::validateWalletUsage()` refuses the change
 * (`DowngradeRequiresMandateException`) — the overage is not put back and "charged later".
 * The preview quoted it as due now regardless, and the confirm answered with a generic error.
 * The preview now asks the validator's own check, flags `usageOverageRequiresMandate`, and the
 * plan screen says what is missing instead of offering the change.
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

/** On Big, an hour into the period, 20 SMS beyond the quota (balance −20). */
function noMandateOveragePreviewBillable(?string $mandateId): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'No Mandate Co', 'email' => uniqid('nomandate-').'@x.test']);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'big',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subHour(),
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_nomandate',
        'mollie_mandate_id' => $mandateId,
        'allows_billing_overage' => true,
        'subscription_meta' => ['seat_count' => 1, 'mollie_subscription_id' => 'sub_nomandate'],
    ])->save();

    $billable->refresh()->createWallet(['name' => 'SMS', 'slug' => 'SMS']);
    $billable->refresh()->getWallet('SMS')->forceWithdraw(20, ['type' => 'SMS']);

    return $billable->refresh();
}

it('flags a plan change whose overage needs a mandate the billable does not have', function (?string $mandateId, bool $blocked): void {
    $billable = noMandateOveragePreviewBillable($mandateId);

    $preview = app(PreviewService::class)->previewPlanChange($billable, 'small', 'monthly');

    expect((int) $preview['usageOverageChargeNet'])->toBeGreaterThan(0)
        ->and($preview['usageOverageRequiresMandate'] ?? null)->toBe($blocked)
        ->and(collect($preview['errors'])->contains('type', 'usage_overage_requires_mandate'))->toBe($blocked);
})->with([
    'no mandate' => [null, true],
    'mandate on file' => ['mdt_nomandate', false],
]);

it('flags exactly what the change itself refuses', function (): void {
    $billable = noMandateOveragePreviewBillable(null);

    expect(app(PreviewService::class)->previewPlanChange($billable, 'small', 'monthly')['usageOverageRequiresMandate'])->toBeTrue();

    expect(fn () => app(UpdateSubscription::class)->update($billable->refresh(), [
        'plan_code' => 'small', 'interval' => 'monthly', 'apply_at' => 'immediate',
    ]))->toThrow(DowngradeRequiresMandateException::class);
});

it('says on the plan screen that a payment method is needed instead of offering the change', function (string $locale, string $expected): void {
    app()->setLocale($locale);

    $billable = noMandateOveragePreviewBillable(null);

    MollieBilling::resolveBillableUsing(fn () => TestBillable::query()->find($billable->getKey()) ?? $billable);
    MollieBilling::authUsing(fn (): bool => true);

    Livewire::test('mollie-billing::plan-change')
        ->call('previewFor', 'small')
        ->assertSee($expected)
        ->assertDontSeeHtml('$wire.applyChange(applyAt)');
})->with([
    'en' => ['en', 'None is stored, so the plan cannot be changed right now'],
    'de' => ['de', 'Es ist keine hinterlegt, deshalb kann der Plan gerade nicht gewechselt werden'],
]);

it('offers the change as before when a mandate is on file', function (): void {
    app()->setLocale('en');

    $billable = noMandateOveragePreviewBillable('mdt_nomandate');

    MollieBilling::resolveBillableUsing(fn () => TestBillable::query()->find($billable->getKey()) ?? $billable);
    MollieBilling::authUsing(fn (): bool => true);

    Livewire::test('mollie-billing::plan-change')
        ->call('previewFor', 'small')
        ->assertSeeHtml('$wire.applyChange(applyAt)')
        ->assertDontSee('None is stored, so the plan cannot be changed right now');
});
