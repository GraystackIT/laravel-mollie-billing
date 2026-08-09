<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Webhook\ProrataChargeHandler;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\SpyMollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use Illuminate\Support\Facades\Storage;

// A plan change during a trial moves Mollie's charge date a full interval out, even on a
// same-interval switch — the handler forces `forceResetStartDate` for exactly that reason.
// The local period anchor was decided earlier, from the stored intent alone, where that
// flag is false for a trial. So Mollie and the package disagreed about when the period
// runs: `nextBillingDate()` named a day Mollie does not charge on, and the overage
// pre-pass, the cancel grace and every later proration were measured against it.

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');

    SpyMollieSubscriptionPatcher::$calls = [];
    app()->bind(MollieSubscriptionPatcher::class, SpyMollieSubscriptionPatcher::class);

    config()->set('mollie-billing-plans.plans', [
        'starter' => [
            'name' => 'Starter', 'tier' => 1, 'included_seats' => 1,
            'feature_keys' => [], 'allowed_addons' => [],
            'intervals' => ['monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => []]],
        ],
        'pro' => [
            'name' => 'Pro', 'tier' => 2, 'included_seats' => 1,
            'feature_keys' => [], 'allowed_addons' => [],
            'intervals' => ['monthly' => ['base_price_net' => 2000, 'seat_price_net' => null, 'included_usages' => []]],
        ],
    ]);
});

it('anchors the period on the payment when a trial converts through a plan change', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Trial Co',
        'email' => 'trialconv@x.test',
        'billing_country' => 'AT',
    ]);

    $trialStart = BillingTime::toUtc('2026-03-01T09:00:00Z');

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_plan_code' => 'starter',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => $trialStart,
        'trial_ends_at' => $trialStart->copy()->addDays(14),
        'mollie_customer_id' => 'cst_trialconv',
        'mollie_mandate_id' => 'mdt_trialconv',
        'subscription_meta' => [
            'mollie_subscription_id' => 'sub_trialconv',
            'seat_count' => 1,
            'pending_prorata_change' => [
                'charge_payment_id' => 'tr_trialconv',
                'charge_lines' => [[
                    'kind' => 'plan',
                    'code' => 'pro',
                    'label' => 'Pro — pro-rata',
                    'quantity' => 1,
                    'unit_price_net' => 500,
                    'amount_net' => 500,
                    'vat_rate' => 20.0,
                    'vat_amount' => 100,
                    'amount_gross' => 600,
                ]],
                'refund_lines' => [],
                'intent' => [
                    'billable_type' => TestBillable::class,
                    'billable_id' => 1,
                    'current_plan' => 'starter',
                    'new_plan' => 'pro',
                    'current_interval' => 'monthly',
                    'new_interval' => 'monthly',
                    'current_seats' => 1,
                    'new_seats' => 1,
                    'current_addons' => [],
                    'new_addons' => [],
                    // False for a trial: it is set for the past-due reset only.
                    'force_reset_start_date' => false,
                ],
            ],
        ],
    ])->save();

    $paidAt = $trialStart->copy()->addDays(5);

    app(ProrataChargeHandler::class)->paid((object) [
        'id' => 'tr_trialconv',
        'status' => 'paid',
        'amount' => (object) ['value' => '6.00', 'currency' => 'EUR'],
        'paidAt' => $paidAt->toIso8601String(),
        'subscriptionId' => 'sub_trialconv',
        'metadata' => [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'type' => 'prorata_charge',
        ],
    ], $billable->refresh(), ['type' => 'prorata_charge']);

    $billable->refresh();

    // Mollie's cadence was moved to one full interval from the payment — the handler does
    // that for a converted trial. The local anchor has to say the same thing.
    $patch = collect(SpyMollieSubscriptionPatcher::$calls)->firstWhere(0, 'update');

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($patch[3]['force_reset_start_date'] ?? null)->toBeTrue()
        ->and($billable->subscription_period_starts_at->toIso8601String())->toBe($paidAt->toIso8601String())
        ->and($billable->nextBillingDate()->toIso8601String())
        ->toBe($paidAt->copy()->addMonth()->toIso8601String());
});
