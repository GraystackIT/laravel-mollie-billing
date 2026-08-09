<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\InvalidSubscriptionStateException;
use GraystackIT\MollieBilling\Jobs\RetrySubscriptionPatchJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Services\Webhook\ProrataChargeHandler;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\SpyMollieSubscriptionPatcher;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 2 of a deferred (SEPA) plan change: the webhook that applies the change once
 * the money clears, days after the customer clicked.
 *
 * Five things went wrong here, and two of them charged the customer twice for the
 * same days. The period anchor is the root of most of it — the composer prices a
 * same-interval change as "the rest of the current window" and the patcher leaves
 * Mollie's cadence alone, so re-anchoring the local period invented a window nobody
 * paid for.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');

    SpyMollieSubscriptionPatcher::$calls = [];
    $this->app->bind(MollieSubscriptionPatcher::class, SpyMollieSubscriptionPatcher::class);

    foreach (['starter' => 1000, 'pro' => 3000] as $code => $price) {
        config()->set("mollie-billing-plans.plans.{$code}", [
            'name' => ucfirst($code),
            'tier' => $code === 'pro' ? 3 : 1,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => ['base_price_net' => $price, 'seat_price_net' => 500, 'included_usages' => []],
                'yearly' => ['base_price_net' => $price * 10, 'seat_price_net' => 5000, 'included_usages' => []],
            ],
        ]);
    }
});

/**
 * A mid-period subscriber with a settled deferred charge waiting to be applied.
 *
 * @param  array<string, mixed>  $intentOverrides
 * @param  array<string, mixed>  $billableOverrides
 */
function phase2Billable(array $intentOverrides = [], array $billableOverrides = []): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Phase2 Co',
        'email' => uniqid('phase2-').'@x.test',
        'billing_country' => 'DE',
    ]);

    $periodStart = BillingTime::nowUtc()->subDays(15);

    $intent = array_merge([
        'billable_type' => TestBillable::class,
        'billable_id' => $b->getKey(),
        'current_plan' => 'starter',
        'new_plan' => 'pro',
        'current_interval' => 'monthly',
        'new_interval' => 'monthly',
        'current_seats' => 1,
        'new_seats' => 1,
        'current_addons' => [],
        'new_addons' => [],
        'force_reset_start_date' => false,
    ], $intentOverrides);

    $b->forceFill(array_merge([
        'mollie_customer_id' => 'cst_p2',
        'mollie_mandate_id' => 'mdt_p2',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'starter',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => $periodStart,
        'subscription_meta' => [
            'seat_count' => 1,
            'mollie_subscription_id' => 'sub_p2',
            'pending_prorata_change' => [
                'charge_payment_id' => 'tr_p2',
                'charge_lines' => [[
                    'kind' => 'plan',
                    'code' => 'pro',
                    'label' => 'Pro (remainder)',
                    'quantity' => 1,
                    'amount_net' => 1500,
                    'vat_rate' => 19.0,
                    'amount_vat' => 285,
                    'amount_gross' => 1785,
                    'total_net' => 1500,
                ]],
                'intent' => $intent,
            ],
        ],
    ], $billableOverrides))->save();

    return $b->refresh();
}

function settleProrata(TestBillable $billable, ?string $paidAt = null): void
{
    app(ProrataChargeHandler::class)->paid((object) [
        'id' => 'tr_p2',
        'status' => 'paid',
        'amount' => (object) ['value' => '17.85', 'currency' => 'EUR'],
        'paidAt' => $paidAt ?? BillingTime::nowUtc()->toIso8601String(),
        'subscriptionId' => 'sub_p2',
        'metadata' => null,
    ], $billable, []);
}

// ─────────────────────────────────────────────────────────────────────────────
// 14a — the period anchor
// ─────────────────────────────────────────────────────────────────────────────

it('keeps the billing period where it was for a same-interval plan change', function (): void {
    $billable = phase2Billable();
    $originalStart = $billable->subscription_period_starts_at->copy();
    $originalNext = $billable->nextBillingDate()->copy();

    settleProrata($billable);
    $billable->refresh();

    // The charge covered day 15 → day 30 of the EXISTING window, and Mollie still
    // charges on day 30. Moving the anchor to `paidAt` claimed a fresh full period:
    // nextBillingDate() then named a date Mollie does not charge on, and the day-30
    // renewal billed days the customer had already paid for.
    expect($billable->subscription_period_starts_at->toIso8601String())->toBe($originalStart->toIso8601String())
        ->and($billable->nextBillingDate()->toIso8601String())->toBe($originalNext->toIso8601String())
        ->and($billable->subscription_plan_code)->toBe('pro');
});

it('starts a new period when the interval changes', function (): void {
    $billable = phase2Billable(['new_interval' => 'yearly']);
    $paidAt = BillingTime::nowUtc()->toIso8601String();

    settleProrata($billable, $paidAt);
    $billable->refresh();

    // A yearly window cannot continue a monthly one, and the patcher resets Mollie's
    // cadence too — so here the anchor must move.
    expect($billable->subscription_period_starts_at->toIso8601String())->toBe($paidAt)
        ->and($billable->subscription_interval)->toBe(SubscriptionInterval::Yearly);
});

it('starts a new period on a past-due reset', function (): void {
    $billable = phase2Billable(
        ['force_reset_start_date' => true],
        ['subscription_status' => SubscriptionStatus::PastDue],
    );
    $paidAt = BillingTime::nowUtc()->toIso8601String();

    settleProrata($billable, $paidAt);
    $billable->refresh();

    expect($billable->subscription_period_starts_at->toIso8601String())->toBe($paidAt)
        ->and($billable->subscription_status)->toBe(SubscriptionStatus::Active);
});

// ─────────────────────────────────────────────────────────────────────────────
// 14b — a converted trial must not be charged again at the old trial end
// ─────────────────────────────────────────────────────────────────────────────

it('moves the Mollie schedule when a same-interval change converts a trial', function (): void {
    $billable = phase2Billable([], [
        'subscription_status' => SubscriptionStatus::Trial,
        'trial_ends_at' => BillingTime::nowUtc()->addDays(9),
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
    ]);

    settleProrata($billable);
    $billable->refresh();

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->trial_ends_at)->toBeNull();

    // Mollie's startDate was the trial end. The charge just paid for the rest of the
    // local period, so leaving the schedule there collected a second full month for
    // days already paid — about 1.8× the monthly price in the first ten days.
    $update = collect(SpyMollieSubscriptionPatcher::$calls)->firstWhere(fn (array $c): bool => $c[0] === 'update');

    expect($update)->not->toBeNull()
        ->and($update[3]['force_reset_start_date'])->toBeTrue();
});

it('does not force a schedule reset for an ordinary active plan change', function (): void {
    $billable = phase2Billable();

    settleProrata($billable);

    $update = collect(SpyMollieSubscriptionPatcher::$calls)->firstWhere(fn (array $c): bool => $c[0] === 'update');

    // Amount-only: the cadence is correct already, and moving it would postpone the
    // renewal the customer's remaining-window charge stops short of.
    expect($update)->not->toBeNull()
        ->and($update[3]['force_reset_start_date'])->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// 14c — never apply onto a subscription that has ended
// ─────────────────────────────────────────────────────────────────────────────

it('refuses to apply a settled charge to an expired subscription', function (): void {
    $billable = phase2Billable([], [
        'subscription_status' => SubscriptionStatus::Expired,
        // What the expiry pass leaves behind: the plan-scoped fields are wiped.
        'subscription_plan_code' => null,
        'subscription_ends_at' => BillingTime::nowUtc()->subDay(),
    ]);

    settleProrata($billable);
    $billable->refresh();

    // Force-filling the plan back on would resurrect exactly the fields the expiry
    // wipe removes, so `@planFeature` and the feature middleware would grant access
    // to a subscription that has ended.
    expect($billable->subscription_plan_code)->toBeNull()
        ->and($billable->subscription_status)->toBe(SubscriptionStatus::Expired)
        ->and($billable->hasAccessibleBillingSubscription())->toBeFalse()
        // The pending state is consumed so the next delivery does not try again…
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('pending_prorata_change')
        // …and Mollie was not patched for a subscription that is gone.
        ->and(SpyMollieSubscriptionPatcher::$calls)->toBeEmpty();
});

it('refuses to apply a settled charge to a cancelled subscription', function (): void {
    $billable = phase2Billable([], [
        'subscription_status' => SubscriptionStatus::Cancelled,
        'subscription_ends_at' => BillingTime::nowUtc()->addDays(3),
    ]);

    settleProrata($billable);
    $billable->refresh();

    expect($billable->subscription_plan_code)->toBe('starter')
        ->and($billable->subscription_status)->toBe(SubscriptionStatus::Cancelled)
        ->and(SpyMollieSubscriptionPatcher::$calls)->toBeEmpty();
});

// ─────────────────────────────────────────────────────────────────────────────
// 14d — a failed PATCH has to be retried
// ─────────────────────────────────────────────────────────────────────────────

it('queues a retry when the Phase-2 PATCH fails', function (): void {
    Queue::fake();

    $billable = phase2Billable();

    // A patcher that throws, like an unreachable Mollie.
    $this->app->bind(MollieSubscriptionPatcher::class, fn ($app) => new class(
        $app->make(GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface::class),
        $app->make(GraystackIT\MollieBilling\Services\Vat\VatCalculationService::class),
    ) extends MollieSubscriptionPatcher {
        public function updateForIntent(
            GraystackIT\MollieBilling\Contracts\Billable $billable,
            GraystackIT\MollieBilling\Services\Billing\PlanChangeIntent $intent,
        ): bool {
            throw new RuntimeException('Mollie is down');
        }
    });

    settleProrata($billable);
    $billable->refresh();

    // Without the marker there is nothing for the hourly sweep to find, and the
    // pending state is cleared right after — so Mollie kept charging the old amount
    // forever while the customer had the new plan, and the renewal handler reconciled
    // each invoice down to it so it never self-healed.
    expect($billable->getBillingSubscriptionMeta())->toHaveKey('pending_subscription_patch');

    $marker = $billable->getBillingSubscriptionMeta()['pending_subscription_patch'];
    expect($marker['intent']['new_plan'])->toBe('pro')
        ->and($marker['intent']['billable_type'])->toBe(config('mollie-billing.billable_model'));

    Queue::assertPushed(RetrySubscriptionPatchJob::class);

    // The local change still applied — only Mollie is behind.
    expect($billable->subscription_plan_code)->toBe('pro')
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('pending_prorata_change');
});

// ─────────────────────────────────────────────────────────────────────────────
// 14e — a second change while one is in flight
// ─────────────────────────────────────────────────────────────────────────────

it('refuses a credit-only change while another change is awaiting payment', function (): void {
    $billable = phase2Billable([], ['subscription_plan_code' => 'pro']);

    // The paid invoice for the current period is what makes a downgrade produce a
    // credit at all — without one there is nothing refundable and the change is a noop.
    BillingInvoice::create([
        'billable_type' => $billable::class,
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => uniqid('tr_period_'),
        'invoice_kind' => GraystackIT\MollieBilling\Enums\InvoiceKind::Subscription,
        'status' => GraystackIT\MollieBilling\Enums\InvoiceStatus::Paid,
        'country' => 'DE',
        'currency' => 'EUR',
        'amount_net' => 3000,
        'amount_vat' => 570,
        'amount_gross' => 3570,
        'period_start' => $billable->subscription_period_starts_at,
        'period_end' => $billable->nextBillingDate(),
        'line_items' => [[
            'kind' => 'plan',
            'code' => 'pro',
            'label' => 'Pro',
            'quantity' => 1,
            'unit_price_net' => 3000,
            'amount_net' => 3000,
            'total_net' => 3000,
            'vat_rate' => 19.0,
            'vat_amount' => 570,
            'amount_gross' => 3570,
        ]],
    ]);

    // A downgrade prices as credit-only (charge 0, credit > 0). The readiness check
    // used to return early at `prorataChargeNet <= 0`, so this passed unchallenged —
    // and the downgrade was then keyed to the OTHER change's payment id and lost
    // without a trace when that payment settled.
    expect(fn () => app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'starter',
        'interval' => 'monthly',
    ]))->toThrow(InvalidSubscriptionStateException::class);

    // The in-flight change is untouched.
    expect($billable->refresh()->getBillingSubscriptionMeta())->toHaveKey('pending_prorata_change');
});

it('still allows applying the pending change itself', function (): void {
    // The apply path re-validates a change whose pending marker is present by
    // definition — the guard must not fire there.
    $billable = phase2Billable();

    settleProrata($billable);

    expect($billable->refresh()->subscription_plan_code)->toBe('pro')
        ->and(BillingInvoice::query()->count())->toBe(1);
});
