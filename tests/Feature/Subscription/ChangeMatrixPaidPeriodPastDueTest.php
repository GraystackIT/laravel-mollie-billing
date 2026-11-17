<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Services\Billing\UndercollectionCharge;
use GraystackIT\MollieBilling\Support\BillingPolicy;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\ChangeMatrixWorld as World;

/**
 * Change matrix, part 7 — a plan change from past due when the running period IS paid.
 *
 * The past-due reset (full first period of the new plan, nothing credited) rests on one
 * premise: the renewal failed, so nothing of the period was paid. A subscription is also
 * past due when its renewal was collected and only a balance charged on top of it was
 * declined. There the change is an ordinary one: the unused paid share is credited, the
 * new plan is charged for the days that are left, the declined balance is closed and the
 * dunning ends.
 *
 * February 2026 has 28 days; the clock is left at 10 Feb 12:00 with 19 of them remaining.
 */
beforeEach(fn () => World::boot());

/**
 * Starter → Pro on 30 Jan with a slow payment; the renewal of 1 Feb is collected for
 * Starter (20.00); on 3 Feb the payment settles, Pro takes effect and the balance for
 * the 26 remaining days is charged (30.00 × 26/28 = 27.86 net) — and declined on 5 Feb.
 *
 * @return array{0: TestBillable, 1: string, 2: array<string, mixed>, 3: string}  billable, balance payment id, its metadata, its value
 */
function paidPeriodPastDueAfterPlanBalance(string $discount = 'none', array $addons = []): array
{
    $billable = World::subscription($discount, 'starter', 2, $addons, at: '2026-01-30 12:00:00');
    World::change($billable, ['plan_code' => 'pro', 'interval' => 'monthly', 'apply_at' => 'immediate'], settle: null);
    World::renew($billable);
    test()->travelTo(Carbon::parse('2026-02-03 12:00:00', 'UTC'));
    World::settle($billable);

    $balance = collect(World::$payments)->filter(fn (array $p): bool => isset($p['metadata']['undercollection']));
    expect($balance)->toHaveCount(1);

    $id = (string) $balance->keys()->first();
    $value = (string) $balance->first()['value'];
    $metadata = (array) $balance->first()['metadata'];

    test()->travelTo(Carbon::parse('2026-02-05 12:00:00', 'UTC'));
    World::deliver($billable, $id, 'failed', $value, $metadata);
    expect($billable->refresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::PastDue);

    test()->travelTo(Carbon::parse('2026-02-10 12:00:00', 'UTC'));

    return [$billable->refresh(), $id, $metadata, $value];
}

/**
 * Pro with Storage; a third seat is added on 31 Jan after Mollie created the February
 * debit, which settles on 1 Feb at the old amount. The balance for the seat (5.00 net)
 * is declined on 5 Feb. Three seats are in use, so a plan change keeps them.
 *
 * @return array{0: TestBillable, 1: string, 2: array<string, mixed>, 3: string}
 */
function paidPeriodPastDueAfterSeatBalance(string $discount = 'none'): array
{
    $billable = World::subscription($discount, 'pro', 2, ['storage'], at: '2026-01-31 12:00:00', used: 3);
    $createdAt = World::$heldValue;
    $createdFor = World::$heldMetadata;

    World::change($billable, ['seats' => 3]);

    test()->travelTo(Carbon::parse('2026-02-01 09:01:00', 'UTC'));
    World::deliver($billable, 'tr_ppd_early', 'paid', (string) $createdAt, $createdFor, '2026-02-01T09:00:00+00:00', recurring: true);

    $balance = collect(World::$payments)->filter(fn (array $p): bool => isset($p['metadata']['undercollection']));
    expect($balance)->toHaveCount(1);

    $id = (string) $balance->keys()->first();
    $value = (string) $balance->first()['value'];
    $metadata = (array) $balance->first()['metadata'];

    test()->travelTo(Carbon::parse('2026-02-05 12:00:00', 'UTC'));
    World::deliver($billable, $id, 'failed', $value, $metadata);
    expect($billable->refresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::PastDue);

    test()->travelTo(Carbon::parse('2026-02-10 12:00:00', 'UTC'));

    return [$billable->refresh(), $id, $metadata, $value];
}

/**
 * Net over every document dated in February: what the customer has paid for the month.
 * The prorata of the January plan change is booked on 3 Feb as well — Pro's extra for
 * the last two days of January, 30.00 × 2/31 — and is January's, so it is taken out.
 */
function paidPeriodPastDueFebruaryNet(TestBillable $billable, bool $withJanuaryProrata = true): int
{
    return (int) BillingInvoice::query()
        ->where('billable_id', $billable->getKey())
        ->where('created_at', '>=', '2026-02-01 00:00:00')
        ->sum('amount_net') - ($withJanuaryProrata ? (int) round(3000 * 2 / 31) : 0);
}

/** The renewal that paid for February. */
function paidPeriodPastDueRenewal(TestBillable $billable): BillingInvoice
{
    return BillingInvoice::query()
        ->where('billable_id', $billable->getKey())
        ->whereNotNull('mollie_subscription_id')
        ->orderByDesc('id')
        ->firstOrFail();
}

/** What every one of these changes has to leave behind: an ordinary change, and the dunning over. */
function paidPeriodPastDueAssertOrdinaryChange(TestBillable $billable, array $report): void
{
    $meta = $billable->getBillingSubscriptionMeta();

    expect($report['preview']['isPastDueReset'])->toBeFalse()
        ->and($report['booked'])->toBe($report['previewed'])
        ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and($meta['payment_failure'] ?? null)->toBeNull()
        ->and($meta['past_due_since'] ?? null)->toBeNull()
        // The declined balance is closed: nothing is shown as open any more.
        ->and(UndercollectionCharge::openCases($billable))->toBe([])
        ->and(array_column(UndercollectionCharge::cases($billable), 'status'))->toBe([UndercollectionCharge::STATUS_CLOSED]);
}

it('settles the pinned example to the cent: back to Starter on 10 Feb credits 13.57, charges 13.57, and February costs what Starter costs', function (string $country, ?string $vatNumber, float $rate) {
    World::$country = $country;
    World::$vatNumber = $vatNumber;

    [$billable, $balanceId, $metadata, $value] = paidPeriodPastDueAfterPlanBalance();

    // The balance: 30.00 × 26/28 = 27.86, with this customer's VAT.
    expect($value)->toBe(number_format(round(round(3000 * 26 / 28) * (1 + $rate / 100)) / 100, 2, '.', ''))
        ->and(BillingPolicy::runningPeriodIsPaid($billable))->toBeTrue()
        ->and(BillingPolicy::isPastDueReset($billable, true, false))->toBeFalse();

    $refundsBefore = array_sum(array_map('count', World::$refunds));
    $report = World::change($billable, ['plan_code' => 'starter', 'interval' => 'monthly', 'apply_at' => 'immediate']);
    $billable->refresh();

    // Of Pro only the renewal's 20.00 is paid: 20.00 × 19/28 = 13.57 comes back, and
    // Starter for the same 19 days costs 20.00 × 19/28 = 13.57.
    $credit = (int) round(2000 * 19 / 28);
    $charge = (int) round(2000 * 19 / 28);
    $lines = collect($report['booked'])->filter(fn (array $l): bool => $l[0] === 'plan')->mapWithKeys(fn (array $l): array => [$l[1] => $l[2]])->all();

    paidPeriodPastDueAssertOrdinaryChange($billable, $report);

    expect($lines)->toEqual(['pro' => -$credit, 'starter' => $charge])
        // Credit and charge are the same amount, so no money moves: the change is booked
        // as the saldo-zero plan-switch document — no payment, no refund at Mollie.
        ->and($report['charged'])->toBeNull()
        ->and(array_sum(array_map('count', World::$refunds)))->toBe($refundsBefore)
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('starter')
        // The period is the one the renewal paid for — not a new one from 10 Feb.
        ->and($billable->subscription_period_starts_at->toDateString())->toBe('2026-02-01')
        // February: the renewal's 20.00 and nothing else. Under the reset it was 40.00.
        ->and(paidPeriodPastDueFebruaryNet($billable))->toBe(2000);

    World::assertNothingOverRefunded($billable);

    // The balance arrives after all (12 Feb). It was priced for Pro from 3 Feb to the end
    // of the month; Pro ran until 10 Feb. Seven days are kept — 30.00 × 7/28 = 7.50 —
    // and the rest is returned.
    $this->travelTo(Carbon::parse('2026-02-12 12:00:00', 'UTC'));
    World::deliver($billable, $balanceId, 'paid', $value, $metadata);
    $billable->refresh();

    // The customer has then paid exactly plan × days used: Starter for 21 days (1–3 and
    // 10–28 Feb) and Pro for 7 (3–10 Feb). For 1–10 Feb that is 20.00 × 2/28 + 50.00 ×
    // 7/28 = 13.93.
    $exact = (int) round(2000 * 21 / 28) + (int) round(5000 * 7 / 28);

    expect($exact)->toBe(2750)
        ->and((int) round(2000 * 2 / 28) + (int) round(5000 * 7 / 28))->toBe(1393)
        ->and(abs(paidPeriodPastDueFebruaryNet($billable) - $exact))->toBeLessThanOrEqual(1, 'February net '.paidPeriodPastDueFebruaryNet($billable))
        ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active);

    World::assertNothingOverRefunded($billable);

    // The next renewal is Starter's, at what Mollie holds, without an adjustment.
    $held = World::heldGross();
    $renewal = World::renew($billable);

    expect((int) $renewal->amount_net)->toBe(2000)
        ->and((int) $renewal->amount_gross)->toBe($held)
        ->and(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment');
})->with([
    'AT, 20 %' => ['AT', null, 20.0],
    'DE, 19 %' => ['DE', null, 19.0],
    'DE business, reverse charge' => ['DE', 'DE123456789', 0.0],
]);

it('charges an upgrade from there for the days that are left and credits what was paid, instead of a full new period', function (): void {
    [$billable, $balanceId, $metadata, $value] = paidPeriodPastDueAfterPlanBalance();

    $report = World::change($billable, ['plan_code' => 'enterprise', 'interval' => 'monthly', 'apply_at' => 'immediate']);
    $billable->refresh();

    $credit = (int) round(2000 * 19 / 28);
    $charge = (int) round(20000 * 19 / 28);
    $lines = collect($report['booked'])->filter(fn (array $l): bool => $l[0] === 'plan')->mapWithKeys(fn (array $l): array => [$l[1] => $l[2]])->all();

    paidPeriodPastDueAssertOrdinaryChange($billable, $report);

    expect($lines)->toEqual(['pro' => -$credit, 'enterprise' => $charge])
        ->and($report['charged'])->toBe(number_format(round($charge * 1.2) / 100, 2, '.', ''))
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('enterprise')
        ->and($billable->subscription_period_starts_at->toDateString())->toBe('2026-02-01')
        ->and(paidPeriodPastDueFebruaryNet($billable))->toBe(2000 - $credit + $charge);

    // With the balance arriving late: Starter 2 days, Pro 7, Enterprise 19 — to the cent
    // of each document's rounding.
    $this->travelTo(Carbon::parse('2026-02-12 12:00:00', 'UTC'));
    World::deliver($billable, $balanceId, 'paid', $value, $metadata);

    $exact = (int) round(2000 * 2 / 28) + (int) round(5000 * 7 / 28) + (int) round(20000 * 19 / 28);
    expect(abs(paidPeriodPastDueFebruaryNet($billable->refresh()) - $exact))->toBeLessThanOrEqual(2, 'February net '.paidPeriodPastDueFebruaryNet($billable));

    // Delivered again: nothing twice.
    $net = paidPeriodPastDueFebruaryNet($billable);
    World::deliver($billable, $balanceId, 'paid', $value, $metadata);
    expect(paidPeriodPastDueFebruaryNet($billable->refresh()))->toBe($net);

    World::assertNothingOverRefunded($billable);
});

it('is the ordinary change under every discount, down and up: what is booked is the difference of the two prices for the days left', function (string $scenario, string $discount, string $target) {
    [$billable] = match ($scenario) {
        'plan balance' => paidPeriodPastDueAfterPlanBalance($discount),
        // Under a waiver the plan is free: Storage is what makes a renewal at all.
        'plan balance with an addon' => paidPeriodPastDueAfterPlanBalance($discount, ['storage']),
        default => paidPeriodPastDueAfterSeatBalance($discount),
    };

    // What February was paid at: the renewal, and nothing else — the balance was declined.
    $paid = (int) paidPeriodPastDueRenewal($billable)->amount_net;
    $periodStart = $billable->subscription_period_starts_at->toDateString();
    $seats = $billable->getBillingSeatCount();

    $report = World::change($billable, ['plan_code' => $target, 'interval' => 'monthly', 'seats' => $seats, 'apply_at' => 'immediate']);
    $billable->refresh();

    $after = World::recurringNet($billable);
    // 19 of 28 days. Up to seven lines, each rounded on its own.
    $expected = (int) round(($after - $paid) * 19 / 28);
    $net = World::sum($report['booked']);

    paidPeriodPastDueAssertOrdinaryChange($billable, $report);

    expect($billable->getBillingSubscriptionPlanCode())->toBe($target)
        ->and($billable->subscription_period_starts_at->toDateString())->toBe($periodStart)
        ->and(abs($net - $expected))->toBeLessThanOrEqual(4, "booked {$net}; the renewal paid {$paid}, new recurring {$after}, over 19/28 → {$expected}")
        // Never the reset's full period on top of the paid one.
        ->and(World::sum($report['booked'], '-'))->toBeLessThan(0);

    World::assertNothingOverRefunded($billable);

    // Preview = booking = what Mollie holds = the next renewal.
    expect(World::heldGross())->toBe($report['preview']['grossTotal']);

    $held = World::heldGross();
    $renewal = World::renew($billable);

    expect((int) $renewal->amount_gross)->toBe($held)
        ->and((int) $renewal->amount_net)->toBe($after)
        ->and(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment');
})->with([
    'plan balance, no discount' => ['plan balance', 'none'],
    'plan balance, 50 % coupon' => ['plan balance', 'pct50'],
    'plan balance, fixed coupon' => ['plan balance', 'fixed'],
    'plan balance, plan waiver' => ['plan balance with an addon', 'waiver-periods'],
    'seat balance, no discount' => ['seat balance', 'none'],
    'seat balance, 50 % coupon' => ['seat balance', 'pct50'],
    'seat balance, fixed coupon' => ['seat balance', 'fixed'],
    'seat balance, plan waiver' => ['seat balance', 'waiver-periods'],
])->with(['down' => 'starter', 'up' => 'enterprise']);

it('ends the old period with an interval change from there: the paid share is credited and the year is charged in full', function (): void {
    [$billable, $balanceId, $metadata, $value] = paidPeriodPastDueAfterPlanBalance();

    $report = World::change($billable, ['plan_code' => 'pro', 'interval' => 'yearly', 'apply_at' => 'immediate']);
    $billable->refresh();

    $lines = collect($report['booked'])->filter(fn (array $l): bool => $l[0] === 'plan')->pluck(2)->sort()->values()->all();

    paidPeriodPastDueAssertOrdinaryChange($billable, $report);

    // Pro's paid share of February (the renewal's 20.00) for 19 of 28 days back; a year
    // of Pro (500.00) from today.
    expect($lines)->toBe([-(int) round(2000 * 19 / 28), 50000])
        ->and((string) $billable->getBillingSubscriptionInterval())->toBe('yearly')
        ->and($billable->subscription_period_starts_at->toDateString())->toBe('2026-02-10');

    // The monthly balance, late: kept for the seven days Pro ran monthly, the rest back.
    $before = (int) BillingInvoice::query()->where('billable_id', $billable->getKey())->sum('amount_net');
    $this->travelTo(Carbon::parse('2026-02-12 12:00:00', 'UTC'));
    World::deliver($billable, $balanceId, 'paid', $value, $metadata);

    $kept = (int) BillingInvoice::query()->where('billable_id', $billable->getKey())->sum('amount_net') - $before;
    expect(abs($kept - (int) round(3000 * 7 / 28)))->toBeLessThanOrEqual(1, "kept {$kept}");

    World::assertNothingOverRefunded($billable);
});

it('does not fall back into dunning when the decline of the closed balance is delivered again', function (): void {
    [$billable, $balanceId, $metadata, $value] = paidPeriodPastDueAfterPlanBalance();

    World::change($billable, ['plan_code' => 'starter', 'interval' => 'monthly', 'apply_at' => 'immediate']);
    expect($billable->refresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active);

    $invoices = BillingInvoice::query()->where('billable_id', $billable->getKey())->count();

    // Past the webhook's own duplicate window, so the handler itself has to know.
    \GraystackIT\MollieBilling\Models\BillingProcessedWebhook::query()->delete();
    World::deliver($billable, $balanceId, 'failed', $value, $metadata);
    $billable->refresh();

    expect($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and($billable->getBillingSubscriptionMeta()['past_due_since'] ?? null)->toBeNull()
        ->and(BillingInvoice::query()->where('billable_id', $billable->getKey())->count())->toBe($invoices);
});

it('books the change once when its prorata payment is delivered twice', function (): void {
    [$billable] = paidPeriodPastDueAfterPlanBalance();

    $report = World::change($billable, ['plan_code' => 'enterprise', 'interval' => 'monthly', 'apply_at' => 'immediate'], settle: null);
    $paymentId = (string) $billable->refresh()->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'];
    $payment = World::$payments[$paymentId];

    // Still past due, and still on Pro, while the charge is open.
    expect($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::PastDue)
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('pro');

    World::deliver($billable, $paymentId, 'paid', (string) $payment['value'], (array) $payment['metadata']);
    $net = paidPeriodPastDueFebruaryNet($billable->refresh());
    $refunds = array_sum(array_map('count', World::$refunds));

    \GraystackIT\MollieBilling\Models\BillingProcessedWebhook::query()->delete();
    World::deliver($billable, $paymentId, 'paid', (string) $payment['value'], (array) $payment['metadata']);
    $billable->refresh();

    expect(paidPeriodPastDueFebruaryNet($billable))->toBe($net)
        ->and(array_sum(array_map('count', World::$refunds)))->toBe($refunds)
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('enterprise')
        ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and($report['preview']['isPastDueReset'])->toBeFalse();
});

it('keeps the reset for a renewal that failed: nothing of the period was paid, so the new plan opens a full one', function (): void {
    $billable = World::failRenewal(World::subscription('none', 'pro', 2, []));

    expect(BillingPolicy::runningPeriodIsPaid($billable))->toBeFalse()
        ->and(BillingPolicy::isPastDueReset($billable, true, false))->toBeTrue();

    $preview = app(PreviewService::class)->previewUpdate($billable, ['plan_code' => 'starter', 'interval' => 'monthly', 'apply_at' => 'immediate']);
    $report = World::change($billable, ['plan_code' => 'starter', 'interval' => 'monthly', 'apply_at' => 'immediate']);
    $billable->refresh();

    // 20.00 for a period from today, + 20 %; nothing credited.
    expect($preview['isPastDueReset'])->toBeTrue()
        ->and(World::sum($report['booked']))->toBe(2000)
        ->and(World::sum($report['booked'], '-'))->toBe(0)
        ->and($report['charged'])->toBe('24.00')
        ->and($billable->subscription_period_starts_at->toDateString())->toBe('2026-02-03')
        ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active);
});

it('keeps the reset when the bank pulled the renewal back: a charged-back period is not a paid one', function (): void {
    $billable = World::subscription('none', 'pro', 2, []);
    $meta = $billable->getBillingSubscriptionMeta();
    $meta['past_due_since'] = now()->toIso8601String();
    $meta['payment_failure'] = ['reason' => 'chargeback', 'failed_at' => now()->toIso8601String()];
    $billable->forceFill(['subscription_status' => SubscriptionStatus::PastDue, 'subscription_meta' => $meta])->save();

    expect(BillingPolicy::runningPeriodIsPaid($billable->refresh()))->toBeFalse()
        ->and(BillingPolicy::isPastDueReset($billable, true, false))->toBeTrue();
});
