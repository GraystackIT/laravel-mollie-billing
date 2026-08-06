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

/**
 * `prorataFactor()` clamps the remaining days at >= 0 but never at <= the period
 * total, so a line whose period starts in the future yields a factor above 1 and a
 * refund larger than the line was ever paid. The per-invoice remaining-refundable
 * pool bounds the invoice total, not the single line, so on a multi-line invoice
 * the surplus really would be sent to Mollie and inflate `refunded_net`.
 */
beforeEach(function (): void {
    // Frozen clock: the whole point is a line period that starts three days from
    // "now", so the day arithmetic has to be exact.
    $this->travelTo(Carbon::parse('2026-03-10 12:00:00', 'UTC'));

    config()->set('mollie-billing.vat_rate_overrides', ['DE' => 19]);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 2900, 'seat_price_net' => 500, 'included_usages' => []],
        ],
    ]);
    config()->set('mollie-billing-plans.plans.starter', [
        'name' => 'Starter',
        'tier' => 1,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => 500, 'included_usages' => []],
        ],
    ]);
});

function refundCapBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Cap Test',
        'email' => 'cap@example.test',
        'billing_country' => 'DE',
        'mollie_customer_id' => 'cst_cap',
        'mollie_mandate_id' => 'mdt_cap',
    ]);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        // Period 2026-03-09 → 2026-04-09.
        'subscription_period_starts_at' => Carbon::parse('2026-03-09 00:00:00', 'UTC'),
        'subscription_meta' => ['seat_count' => 1, 'mollie_subscription_id' => 'sub_cap'],
    ])->save();

    return $billable->refresh();
}

/**
 * A paid invoice whose plan line only starts on 2026-03-13 while the period still
 * ends on 2026-04-09: 30 remaining days over a 27-day line = factor 1.111111.
 * The seats line is never refunded here — it exists so the invoice-level pool
 * (3900) is comfortably larger than the uncapped plan refund (3222) and therefore
 * cannot be what bounds the line.
 */
function refundCapInvoice(TestBillable $billable): BillingInvoice
{
    $lineEnd = Carbon::parse('2026-04-09 00:00:00', 'UTC')->toIso8601String();

    return BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_cap',
        'mollie_subscription_id' => 'sub_cap',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'DE',
        'currency' => 'EUR',
        'amount_net' => 3900,
        'amount_vat' => 741,
        'amount_gross' => 4641,
        'line_items' => [
            [
                'kind' => 'plan', 'code' => 'pro', 'label' => 'Pro',
                'quantity' => 1, 'unit_price_net' => 2900, 'amount_net' => 2900,
                'vat_rate' => 19.0, 'vat_amount' => 551, 'amount_gross' => 3451,
                'period_start' => Carbon::parse('2026-03-13 00:00:00', 'UTC')->toIso8601String(),
                'period_end' => $lineEnd,
            ],
            [
                'kind' => 'seats', 'code' => null, 'label' => 'Extra seats',
                'quantity' => 2, 'unit_price_net' => 500, 'amount_net' => 1000,
                'vat_rate' => 19.0, 'vat_amount' => 190, 'amount_gross' => 1190,
                'period_start' => Carbon::parse('2026-03-09 00:00:00', 'UTC')->toIso8601String(),
                'period_end' => $lineEnd,
            ],
        ],
        'period_start' => Carbon::parse('2026-03-09 00:00:00', 'UTC'),
        'period_end' => Carbon::parse('2026-04-09 00:00:00', 'UTC'),
    ]);
}

it('never refunds more than the plan line was paid, even at a factor above 1', function (): void {
    $billable = refundCapBillable();
    $invoice = refundCapInvoice($billable);

    $lines = app(ProrataComposer::class)->compose(new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'pro', newPlan: 'starter',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: [], newAddons: [],
    ));

    $refunds = array_values(array_filter($lines, fn ($line): bool => $line->direction === 'refund'));

    expect($refunds)->toHaveCount(1);
    $refund = $refunds[0];

    // Precondition: the line period really does yield a factor > 1 (30 remaining
    // days out of a 27-day line). Without that the cap would never be exercised.
    expect($refund->daysRemaining)->toBeGreaterThan($refund->daysActive);

    // Uncapped this would be round(2900 × 1.111111) = 3222 against a line paid 2900.
    expect($refund->amountNet)->toBe(-2900)
        ->and($refund->amountVat)->toBe(-551)
        ->and($refund->amountGross)->toBe(-3451)
        // The invoice-level pool is 3900 and never fired, so the per-line cap is
        // what bounded this — a pool cap would have left a note behind.
        ->and($refund->refundCapNote)->toBeNull()
        ->and($invoice->remainingRefundableNet())->toBe(3900);
});

it('still prorates the plan refund normally inside the period', function (): void {
    $billable = refundCapBillable();

    // Same invoice, but the plan line covers the whole period: 30 of 31 days left.
    $invoice = refundCapInvoice($billable);
    $lineItems = $invoice->line_items;
    $lineItems[0]['period_start'] = Carbon::parse('2026-03-09 00:00:00', 'UTC')->toIso8601String();
    $invoice->line_items = $lineItems;
    $invoice->save();

    $lines = app(ProrataComposer::class)->compose(new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'pro', newPlan: 'starter',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: [], newAddons: [],
    ));

    $refunds = array_values(array_filter($lines, fn ($line): bool => $line->direction === 'refund'));

    // round(2900 × round(30/31, 6)) = round(2806.45) = 2806 — the cap must not
    // round ordinary pro-rata up to the full line.
    expect($refunds)->toHaveCount(1)
        ->and($refunds[0]->amountNet)->toBe(-2806);
});
