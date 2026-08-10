<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Support\AdminKpiService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Cache;

/**
 * The numbers the admin dashboard puts in front of an operator.
 *
 * They read as authoritative — "MRR", "ARR", "trial conversion" — so a wrong one is
 * worse than a missing one: it is used to decide pricing. Every case here pins a number
 * the previous implementation got wrong, not a formatting detail.
 */
beforeEach(function (): void {
    Cache::flush();
});

function kpiBillable(array $attributes = []): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'KPI Co',
        'email' => uniqid('kpi-').'@x.test',
    ]);

    if ($attributes !== []) {
        $billable->forceFill($attributes)->save();
    }

    return $billable->refresh();
}

function kpiInvoice(TestBillable $billable, int $net, int $periodDays, ?string $createdAt = null): BillingInvoice
{
    $start = BillingTime::nowUtc()->subDays(2);

    $invoice = BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => uniqid('tr_kpi_'),
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => $net,
        'amount_vat' => 0,
        'amount_gross' => $net,
        'period_start' => $start,
        'period_end' => $start->addDays($periodDays),
        'line_items' => [],
    ]);

    if ($createdAt !== null) {
        // created_at drives the KPI window and is not settable through create().
        $invoice->forceFill(['created_at' => $createdAt])->save();
    }

    return $invoice->refresh();
}

it('counts a yearly invoice as one twelfth of a month, not as a whole one', function (): void {
    $billable = kpiBillable();
    kpiInvoice($billable, net: 120_000, periodDays: 365);

    // 1.200,00 for a year is 100,00 a month. Unnormalised it read as 1.200,00 MRR and
    // 14.400,00 ARR — twelve times the recurring revenue this customer represents.
    expect(app(AdminKpiService::class)->mrr())->toBe(10_000);

    Cache::flush();
    expect(app(AdminKpiService::class)->arr())->toBe(120_000);
});

it('counts a monthly invoice in full', function (): void {
    $billable = kpiBillable();
    kpiInvoice($billable, net: 2_900, periodDays: 30);

    expect(app(AdminKpiService::class)->mrr())->toBe(2_900);
});

it('never scales a short prorata charge up to a full month', function (): void {
    $billable = kpiBillable();
    // Mid-cycle upgrades are InvoiceKind::Subscription too. A linear days→month
    // normalisation would turn this three-day charge into ten times its value.
    kpiInvoice($billable, net: 1_840, periodDays: 3);

    expect(app(AdminKpiService::class)->mrr())->toBe(1_840);
});

it('counts an invoice without a period in full rather than dropping it', function (): void {
    $billable = kpiBillable();
    $invoice = kpiInvoice($billable, net: 5_000, periodDays: 30);
    $invoice->forceFill(['period_start' => null, 'period_end' => null])->save();

    expect(app(AdminKpiService::class)->mrr())->toBe(5_000);
});

it('reports the monthly share per month in the trend', function (): void {
    $billable = kpiBillable();
    $thisMonth = BillingTime::nowUtc()->startOfMonth()->addDays(2);
    kpiInvoice($billable, net: 120_000, periodDays: 365, createdAt: $thisMonth->format('Y-m-d H:i:s'));

    $trend = app(AdminKpiService::class)->mrrTrend(2);

    expect($trend)->toHaveCount(2)
        ->and($trend[1]['month'])->toBe(BillingTime::nowUtc()->format('Y-m'))
        ->and($trend[1]['mrr'])->toBe(10_000);
});

it('does not count a converted trial as its own failure', function (): void {
    // One customer, one trial, converted inside the window: 100%.
    //
    // The shape a real conversion leaves behind — `trial_ends_at` nulled, the moment
    // stamped in the meta. Keying the cohort on `trial_ends_at` alone therefore found
    // nothing to count, and the tile read 0 % for a business converting everyone.
    kpiBillable([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'trial_ends_at' => null,
        'mollie_mandate_id' => 'mdt_converted',
        'subscription_meta' => ['trial_converted_at' => BillingTime::nowUtc()->subDays(10)->toIso8601String()],
    ]);

    expect(app(AdminKpiService::class)->trialConversionRate(90))->toBe(1.0);
});

it('counts an unpaid trial parked in past_due as a failed conversion', function (): void {
    // ProcessTrialLifecycleJob parks an expired trial without a payment method in
    // PastDue before it ever reaches Expired. That state was in neither the numerator
    // nor the denominator, so the rate silently read too high.
    kpiBillable([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::PastDue,
        'trial_ends_at' => BillingTime::nowUtc()->subDays(3),
        'mollie_mandate_id' => null,
    ]);
    kpiBillable([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'trial_ends_at' => null,
        'mollie_mandate_id' => 'mdt_ok',
        'subscription_meta' => ['trial_converted_at' => BillingTime::nowUtc()->subDays(5)->toIso8601String()],
    ]);

    expect(app(AdminKpiService::class)->trialConversionRate(90))->toBe(0.5);
});

it('leaves a running trial out of the rate entirely', function (): void {
    kpiBillable([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Trial,
        'trial_ends_at' => BillingTime::nowUtc()->addDays(7),
        'mollie_mandate_id' => null,
    ]);

    // Nothing ended in the window, so there is no cohort — not a 0% conversion.
    expect(app(AdminKpiService::class)->trialConversionRate(90))->toBe(0.0);
});

it('answers zero instead of raising when the invoice table is unavailable', function (): void {
    // The safety net for a boot-time / mid-migration state. `safely()` returns its
    // fallback, and the fallback has to be an int: without it the typed closure raised a
    // TypeError and the whole admin layout 500ed.
    Schema::drop('billing_invoices');

    expect(app(AdminKpiService::class)->mrr())->toBe(0);
});

it('stops counting a cancellation once its grace period has ended long ago', function (): void {
    // A yearly customer who cancelled eleven months ago and keeps access until next month
    // is not this month's churn. With only a lower bound on `subscription_ends_at` every
    // such cancellation counted on every dashboard load, and the number never decayed.
    kpiBillable([
        'subscription_status' => SubscriptionStatus::Cancelled,
        'subscription_ends_at' => BillingTime::nowUtc()->addMonths(11),
    ]);
    kpiBillable(['subscription_status' => SubscriptionStatus::Active]);

    expect(app(AdminKpiService::class)->churnRate(30))->toBe(0.0);

    Cache::flush();

    // One whose access actually ended inside the window does count.
    kpiBillable([
        'subscription_status' => SubscriptionStatus::Cancelled,
        'subscription_ends_at' => BillingTime::nowUtc()->subDays(3),
    ]);

    expect(app(AdminKpiService::class)->churnRate(30))->toBe(round(1 / 2, 4));
});

it('does not count revenue that was refunded', function (): void {
    $billable = kpiBillable();
    $invoice = kpiInvoice($billable, net: 2_900, periodDays: 30);

    expect(app(AdminKpiService::class)->mrr())->toBe(2_900);

    Cache::flush();

    // Billing error, corrected the same week: the money is back with the customer, so it
    // is not recurring revenue any more.
    $invoice->forceFill(['refunded_net' => 2_900])->save();

    expect(app(AdminKpiService::class)->mrr())->toBe(0);
});
