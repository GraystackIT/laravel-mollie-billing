<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * A trial is free, and everything the current plan allows is part of what is being
 * tried. Seats and addons added during one are not charged and not refunded: nothing was
 * paid for the window they are added to, so there is no fraction of anything to prorate.
 * The customer pays the full new amount when the trial converts, because the Mollie
 * subscription is PATCHed to it either way.
 *
 * A plan or interval change is the exception — it ends the trial and is charged.
 */
beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.enterprise', [
        'name' => 'Enterprise',
        'tier' => 3,
        'included_seats' => 10,
        'feature_keys' => [],
        'allowed_addons' => ['extra-storage'],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 9900,
                'seat_price_net' => 900,
                'trial_days' => 14,
                'included_usages' => [],
            ],
        ],
    ]);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 5,
        'feature_keys' => [],
        'allowed_addons' => ['extra-storage'],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 3900,
                'seat_price_net' => 900,
                'trial_days' => 14,
                'included_usages' => [],
            ],
        ],
    ]);

    config()->set('mollie-billing-plans.addons.extra-storage', [
        'name' => 'Extra Storage (50 GB)',
        'feature_keys' => [],
        'intervals' => ['monthly' => ['price_net' => 1000]],
    ]);
});

function trialAdjustmentBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Judith Guthrie',
        'email' => 'trial-adjust@x.test',
        'billing_country' => 'AT',
    ]);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_plan_code' => 'enterprise',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'mollie_customer_id' => 'cst_trial',
        'mollie_mandate_id' => 'mdt_trial',
        'trial_ends_at' => BillingTime::nowUtc()->addDays(14),
        'subscription_period_starts_at' => BillingTime::nowUtc(),
        'subscription_meta' => [
            'seat_count' => 10,
            'mollie_subscription_id' => 'sub_trial',
        ],
    ])->save();

    return $billable->refresh();
}

/**
 * Every Mollie request the change made, by class.
 *
 * @return array{payments: list<CreatePaymentRequest>, patches: list<UpdateSubscriptionRequest>}
 */
function captureMollieDuringTrialChange(Closure $change): array
{
    $payments = [];
    $patches = [];

    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$payments, &$patches) {
        if ($request instanceof CreatePaymentRequest) {
            $payments[] = $request;

            return (object) ['id' => 'tr_should_not_happen', 'status' => 'open', '_links' => null];
        }

        if ($request instanceof UpdateSubscriptionRequest) {
            $patches[] = $request;

            return (object) ['id' => 'sub_trial', 'status' => 'active'];
        }

        return (object) ['id' => 'unknown'];
    });

    $change();

    return ['payments' => $payments, 'patches' => $patches];
}

it('charges nothing for extra seats added during a trial', function (): void {
    $billable = trialAdjustmentBillable();

    $calls = captureMollieDuringTrialChange(fn () => $billable->syncBillingSeats(12));

    $billable->refresh();

    // The two extra seats are active…
    expect($billable->getBillingSeatCount())->toBe(12);

    // …and nothing was collected for them.
    expect($calls['payments'])->toBeEmpty();
    expect(BillingInvoice::query()->count())->toBe(0);

    // The trial is untouched: it was not converted by a charge that never happened.
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Trial);
    expect($billable->trial_ends_at)->not->toBeNull();

    // Mollie still learns the new amount, so the first real charge collects it:
    // 9900 base + 2 × 900 seats = 11700 net.
    expect($calls['patches'])->not->toBeEmpty();
});

it('refunds nothing when seats are removed again during a trial', function (): void {
    $billable = trialAdjustmentBillable();

    captureMollieDuringTrialChange(function () use ($billable): void {
        $billable->syncBillingSeats(12);
        $billable->refresh();
        $billable->syncBillingSeats(10);
    });

    $billable->refresh();

    expect($billable->getBillingSeatCount())->toBe(10);
    expect(BillingInvoice::query()->count())->toBe(0);
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Trial);
});

it('charges nothing for an addon enabled during a trial', function (): void {
    $billable = trialAdjustmentBillable();

    $calls = captureMollieDuringTrialChange(fn () => $billable->enableBillingAddon('extra-storage'));

    $billable->refresh();

    expect($billable->getActiveBillingAddonCodes())->toContain('extra-storage');
    expect($calls['payments'])->toBeEmpty();
    expect(BillingInvoice::query()->count())->toBe(0);
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Trial);
});

it('quotes nothing for a seat change while the trial runs', function (): void {
    $billable = trialAdjustmentBillable();

    $preview = app(PreviewService::class)->previewUpdate($billable, ['seats' => 12]);

    // The preview and the change have to agree — quoting an amount that is then not
    // collected is the same defect in the other direction.
    expect($preview['prorataChargeNet'])->toBe(0);
    expect($preview['prorataCreditNet'])->toBe(0);
    expect($preview['prorataLines'] ?? [])->toBeEmpty();
});

it('still charges a plan change, which ends the trial', function (): void {
    $billable = trialAdjustmentBillable();

    $preview = app(PreviewService::class)->previewUpdate($billable, ['planCode' => 'pro']);

    // Downgrade-shaped, so what matters is that the trial is *not* treated as free
    // here: the prorata pipeline runs and its lines are composed.
    expect($preview['prorataChargeNet'] + $preview['prorataCreditNet'])->toBeGreaterThan(0);
});
