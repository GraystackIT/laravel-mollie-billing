<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
use GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use Illuminate\Support\Facades\Event;

require_once dirname(__DIR__).'/Lifecycle/Support.php';

/*
 * The long-run variants the first round left open: other VAT situations, another
 * currency, a trial that converts into the run, an interval change and a scheduled change
 * in the middle of it, and overage collected month after month.
 *
 * Every renewal goes through `longRunAssertRenewal()`: Mollie's amount == the figure the
 * test states == invoice gross == Σ lines, no adjustment line, the period one interval
 * long on the cycle and following the one before, consecutive serials.
 */

beforeEach(function (): void {
    longRunBoot();
    lifecycleCatalogExtras();
    Event::fake([PaymentAmountMismatch::class]);
});

afterEach(fn () => Carbon::setTestNow());

it('runs twelve renewals in every VAT situation', function (array $attributes, string $gross, int $vat): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', $gross, [], $attributes);

    foreach (range(1, 12) as $n) {
        $invoice = longRunAssertRenewal($world, longRunRenew($world), $gross);

        expect((int) $invoice->amount_net)->toBe(2900)
            ->and((int) $invoice->amount_vat)->toBe($vat);
    }

    expect($world->patches)->toBe([]);
    Event::assertNotDispatched(PaymentAmountMismatch::class);
})->with(longRunVatCases());

it('runs twelve renewals in a currency without decimals', function (): void {
    // ¥2900 a month; 19 % → ¥3451. Written "3451" to Mollie, never "34.51" or "3451.00".
    config()->set('mollie-billing.currency', 'JPY');
    LongRunWorld::$currency = 'JPY';

    $world = longRunStart('monthly', '2027-01-15 10:00:00', '3451');

    expect($world->heldValue)->toBe('3451');

    foreach (range(1, 12) as $n) {
        $invoice = longRunAssertRenewal($world, longRunRenew($world), '3451');

        expect($invoice->currency)->toBe('JPY')
            ->and((int) $invoice->amount_gross)->toBe(3451);
    }

    Event::assertNotDispatched(PaymentAmountMismatch::class);
});

it('runs twelve renewals in a second two-decimal currency', function (): void {
    config()->set('mollie-billing.currency', 'CHF');
    LongRunWorld::$currency = 'CHF';

    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');

    foreach (range(1, 12) as $n) {
        expect(longRunAssertRenewal($world, longRunRenew($world), '34.51')->currency)->toBe('CHF');
    }

    Event::assertNotDispatched(PaymentAmountMismatch::class);
});

it('converts a trial with its first charge and runs on from the day the trial ended', function (): void {
    $world = lifecycleStartTrial('2027-01-10 10:00:00');

    expect($world->billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Trial)
        ->and($world->heldValue)->toBe('34.51')
        ->and($world->nextChargeAt()->toDateString())->toBe('2027-01-24')
        ->and(BillingInvoice::query()->count())->toBe(0);

    // The first charge: no invoice precedes it.
    $first = longRunRenew($world);
    $invoice = longRunAssertRenewal($world, $first, '34.51');

    expect(substr($first->createdAt, 0, 10))->toBe('2027-01-24')
        ->and($world->billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and($world->billable->getBillingTrialEndsAt())->toBeNull()
        ->and($invoice->period_start->toDateString())->toBe('2027-01-24')
        // The trial's prorated quota is replaced by a full period's.
        ->and($world->billable->remainingBillingQuota('Tokens'))->toBe(100);

    foreach (range(1, 12) as $n) {
        longRunAssertRenewal($world, longRunRenew($world), '34.51');

        expect($world->billable->getBillingPeriodStartsAt()->day)->toBe(24);
    }

    expect($world->billable->nextBillingDate()->toDateString())->toBe('2028-02-24');
    Event::assertNotDispatched(PaymentAmountMismatch::class);
});

it('switches from monthly to yearly in the middle of a run and renews yearly from the switch', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');

    foreach (range(1, 3) as $n) {
        longRunAssertRenewal($world, longRunRenew($world), '34.51');
    }

    // 25 April, ten days into the period that started on the 15th.
    Carbon::setTestNow(Carbon::parse('2027-04-25 09:00:00', 'UTC'));
    app(UpdateSubscription::class)->update($world->billable->fresh(), ['plan_code' => 'pro', 'interval' => 'yearly']);

    // Nothing switches before the money is there.
    expect($world->billable->fresh()->getBillingSubscriptionInterval())->toBe('monthly');

    $switch = longRunSettleSideCharge($world, '2027-04-25 09:05:00');
    $world->billable = $world->billable->fresh();

    // A full year is charged, less the unused 20 of 30 days of April's month.
    expect($world->billable->getBillingSubscriptionInterval())->toBe('yearly')
        ->and($world->mollieInterval)->toBe('12 months')
        ->and($world->heldValue)->toBe('345.10')
        ->and($world->nextChargeAt()->toDateString())->toBe('2028-04-25')
        ->and($world->billable->getBillingPeriodStartsAt()->toDateString())->toBe('2027-04-25')
        ->and($switch->period_end->toDateString())->toBe('2028-04-25')
        ->and((int) $switch->amount_net)->toBe(29000);

    $creditNote = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->sole();
    expect(abs((int) $creditNote->amount_net))->toBeGreaterThan(1800)->toBeLessThan(2100);

    $world->lastInvoice = $switch;

    foreach (['2028-04-25', '2029-04-25', '2030-04-25'] as $cycle) {
        $payment = longRunRenew($world);

        expect(substr($payment->createdAt, 0, 10))->toBe($cycle);
        longRunAssertRenewal($world, $payment, '345.10');
    }

    Event::assertNotDispatched(PaymentAmountMismatch::class);
});

it('applies a change scheduled for the period end with the renewal that starts the next period', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');

    foreach (range(1, 2) as $n) {
        longRunAssertRenewal($world, longRunRenew($world), '34.51');
    }

    // Booked on 25 March for the period end, 15 April: Team, 59.00 + 19 % = 70.21.
    Carbon::setTestNow(Carbon::parse('2027-03-25 09:00:00', 'UTC'));
    app(UpdateSubscription::class)->update($world->billable->fresh(), ['plan_code' => 'team', 'interval' => 'monthly', 'apply_at' => 'end_of_period']);

    // Still Pro for the customer — but Mollie already knows what to collect on the 15th.
    expect($world->billable->fresh()->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and($world->heldValue)->toBe('70.21');

    // The renewal that starts the next period is the first Team period.
    $payment = longRunRenew($world);
    $invoice = longRunAssertRenewal($world, $payment, '70.21');

    expect(substr($payment->createdAt, 0, 10))->toBe('2027-04-15')
        ->and($world->billable->getBillingSubscriptionPlanCode())->toBe('team')
        ->and(collect($invoice->line_items)->firstWhere('kind', 'plan')['code'])->toBe('team')
        ->and($world->billable->getBillingSubscriptionMeta())->not->toHaveKey('scheduled_change')
        // The new plan's quota, from its first day.
        ->and($world->billable->remainingBillingQuota('Tokens'))->toBe(500);

    foreach (range(1, 6) as $n) {
        longRunAssertRenewal($world, longRunRenew($world), '70.21');
    }

    expect($world->billable->getBillingPeriodStartsAt()->day)->toBe(15);
    Event::assertNotDispatched(PaymentAmountMismatch::class);
});

it('collects the overage of every period before it renews, six months running', function (): void {
    // 100 Tokens included, 0.10 for each one beyond.
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.usage_overage_prices', ['Tokens' => 10]);

    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');

    foreach (range(1, 6) as $month) {
        // 100 + 10 × month Tokens used: 10 × month of overage.
        $world->billable->fresh()->recordBillingUsage('Tokens', 100 + 10 * $month);

        expect($world->billable->fresh()->billingOverageCount('Tokens'))->toBe(10 * $month);

        // The night before the renewal the sweep charges it.
        $cycle = $world->nextChargeAt();
        Carbon::setTestNow($cycle->subDay()->setTime(2, 0));
        app()->call([new PrepareUsageOverageJob, 'handle']);

        $overage = longRunSettleSideCharge($world, $cycle->subDay()->setTime(2, 5)->toDateTimeString());

        // 10 × month × 0.10 net, 19 % on top.
        $net = 100 * $month;
        expect($overage->invoice_kind)->toBe(InvoiceKind::Overage)
            ->and((int) $overage->amount_net)->toBe($net)
            ->and((int) $overage->amount_gross)->toBe($net + (int) round($net * 0.19))
            ->and($world->billable->fresh()->billingOverageCount('Tokens'))->toBe(0);

        // Then the renewal: the plan's price, and a full quota again.
        $renewal = longRunRenew($world);
        $invoice = BillingInvoice::query()->where('mollie_payment_id', $renewal->id)->sole();

        longRunAssertDocumentAddsUp($invoice, '34.51', 'renewal of month '.$month);
        longRunAssertSerialFollows($invoice, 'renewal of month '.$month);

        expect($invoice->period_start->toDateString())->toBe($cycle->toDateString())
            ->and($world->billable->fresh()->remainingBillingQuota('Tokens'))->toBe(100);
    }

    // Six overage invoices and six renewals after the signup, nothing else.
    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Overage)->count())->toBe(6)
        ->and(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Subscription)->count())->toBe(7)
        ->and((int) BillingInvoice::query()->where('invoice_kind', InvoiceKind::Overage)->sum('amount_net'))->toBe(2100);

    Event::assertNotDispatched(PaymentAmountMismatch::class);
});
