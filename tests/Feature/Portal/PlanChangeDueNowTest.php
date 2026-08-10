<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * "Due now" is the amount Mollie takes now, not the balance of the change. A pro-rata plan
 * change produces charge lines for the new plan and refund lines for the unused remainder
 * of the old one, and those are two separate Mollie movements:
 * `InvoiceService::createCharge()` collects the sum of the charge lines, and the refunds
 * only follow in phase 2 once that payment has cleared (`ProrataExecutor::execute()`).
 *
 * The panel netted them, so an upgrade that charges €36.00 and credits €6.00 announced
 * "+€30.00" — a number that leaves nobody's account.
 */
beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans', [
        'starter' => [
            'name' => 'Starter',
            'tier' => 1,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => []],
                'yearly' => ['base_price_net' => 10000, 'seat_price_net' => null, 'included_usages' => []],
            ],
        ],
        'pro' => [
            'name' => 'Pro',
            'tier' => 2,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => ['base_price_net' => 6000, 'seat_price_net' => null, 'included_usages' => []],
                'yearly' => ['base_price_net' => 60000, 'seat_price_net' => null, 'included_usages' => []],
            ],
        ],
        'lateral' => [
            'name' => 'Lateral',
            'tier' => 1,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => []],
                'yearly' => ['base_price_net' => 10000, 'seat_price_net' => null, 'included_usages' => []],
            ],
        ],
    ]);

    // April has 30 days, so `periodStart->addMonth()` lands exactly 30 days later and the
    // pro-rata factor is a clean 0.5 for both the charge and the refund side.
    Carbon::setTestNow('2026-04-16 12:00:00');

    MollieBilling::authUsing(fn (): bool => true);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** On `starter`, exactly half-way through a 30-day period. */
function dueNowBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Acme',
        'email' => 'duenow@example.test',
        'billing_country' => 'AT',
    ]);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'starter',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => Carbon::parse('2026-04-01 12:00:00'),
        'mollie_customer_id' => 'cst_test',
        'mollie_mandate_id' => 'mdt_test',
        'subscription_meta' => ['seat_count' => 1, 'mollie_subscription_id' => 'sub_test'],
    ])->save();

    $billable = $billable->refresh();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    return $billable;
}

/** The paid invoice the pro-rata refund lines are composed against. */
function dueNowPeriodInvoice(TestBillable $billable, int $net = 1000): void
{
    $vat = (int) round($net * 0.20);

    BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_period_'.uniqid(),
        'mollie_subscription_id' => 'sub_test',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => $net,
        'amount_vat' => $vat,
        'amount_gross' => $net + $vat,
        'line_items' => [[
            'kind' => 'plan',
            'code' => 'starter',
            'label' => 'Starter',
            'quantity' => 1,
            'unit_price_net' => $net,
            'amount_net' => $net,
            'vat_rate' => 20.0,
            'vat_amount' => $vat,
            'amount_gross' => $net + $vat,
            'period_start' => Carbon::parse('2026-04-01 12:00:00')->toIso8601String(),
            'period_end' => Carbon::parse('2026-05-01 12:00:00')->toIso8601String(),
        ]],
        'period_start' => Carbon::parse('2026-04-01 12:00:00'),
        'period_end' => Carbon::parse('2026-05-01 12:00:00'),
    ]);
}

it('announces the amount Mollie collects, not the balance of the change', function (): void {
    $billable = dueNowBillable();
    dueNowPeriodInvoice($billable);

    $component = Livewire::test('mollie-billing::plan-change')->call('previewFor', 'pro');

    // Half a period of Pro is charged (3000 + 20% = 3600); half a period of Starter
    // (500 + 20% = 600) is refunded separately.
    $lines = $component->get('preview')['prorataLines'];
    $charge = array_sum(array_map(
        fn (array $l): int => $l['direction'] === 'charge' ? (int) $l['amount_gross'] : 0,
        $lines,
    ));
    $refund = array_sum(array_map(
        fn (array $l): int => $l['direction'] === 'refund' ? (int) $l['amount_gross'] : 0,
        $lines,
    ));

    expect($charge)->toBe(3600)
        ->and($refund)->toBe(-600);

    $component
        // The netted saldo. It appears nowhere else on the page.
        ->assertDontSee(BillingMoney::format($charge + $refund))
        // What Mollie collects, plus the credit named as the separate movement it is.
        ->assertSee(BillingMoney::format($charge))
        ->assertSee(__('billing::portal.prorata_refund_separate', ['amount' => BillingMoney::format(-$refund)]));
});

it('announces nothing due for a saldo-zero plan switch', function (): void {
    $billable = dueNowBillable();
    dueNowPeriodInvoice($billable);

    Livewire::test('mollie-billing::plan-change')
        ->call('previewFor', 'lateral')
        ->assertSee(__('billing::portal.prorata_sidegrade_no_charge'))
        ->assertDontSee(__('billing::portal.prorata_refund_separate', ['amount' => '€6.00']));
});

it('does not announce a separate refund when the change only charges', function (): void {
    // No period invoice, so the composer finds nothing to refund.
    dueNowBillable();

    Livewire::test('mollie-billing::plan-change')
        ->call('previewFor', 'pro')
        ->assertDontSee(__('billing::portal.prorata_refund_separate', ['amount' => '€6.00']));
});
