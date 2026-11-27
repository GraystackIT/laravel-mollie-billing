<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\ScheduledChangeNotAppliedNotification;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\ChangeMatrixWorld as World;
use Illuminate\Support\Facades\Notification;

/**
 * Change matrix, part 6 — the cells the earlier parts reported as not covered: the trial
 * under a discount, a granted addon under every kind of waiver, a coupon entered in each
 * of the three forms under every discount state, and the sequences that were only
 * described so far.
 */
beforeEach(fn () => World::boot());

// ── Trial under a discount ──────────────────────────────────────────────────

it('keeps seats and addons free during a trial that runs under a discount, and discounts the first charge', function (string $discount) {
    $billable = World::trial('pro', 2, ['storage'], $discount);

    $seats = World::change($billable, ['seats' => 3]);
    $addon = World::change($billable, ['addons' => ['storage', 'crm']]);

    expect($seats['booked'])->toBe([])->and($addon['booked'])->toBe([])
        ->and(World::$payments)->toBe([])
        ->and($billable->refresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Trial)
        ->and(World::heldGross())->toBe($addon['preview']['grossTotal']);

    $expected = World::recurringNet($billable);

    // The trial ends: the first real charge, at the discounted recurring amount.
    $first = World::renew($billable, '2026-01-15T09:00:00+00:00');

    expect((int) $first->amount_net)->toBe($expected)
        ->and((int) $first->amount_gross)->toBe(World::heldGross())
        ->and(array_column((array) $first->line_items, 'kind'))->not->toContain('adjustment')
        ->and($billable->refresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active);
})->with([
    '50 % coupon' => 'pct50',
    'fixed coupon' => 'fixed',
    'waiver until a date, then paid' => 'waiver-date-paid',
    'waiver for N periods' => 'waiver-periods',
    'waiver with a granted addon' => 'waiver-gift',
]);

// ── A granted addon, under every kind of waiver ─────────────────────────────

it('neither credits nor charges a granted addon going off and on, whatever kind of waiver', function (string $discount) {
    $billable = World::subscription($discount, 'pro', 2, ['storage']);
    $held = World::$heldValue;

    $off = World::change($billable, ['addons' => ['storage']]);
    $this->travelTo(Carbon::parse('2026-01-15 12:00:00', 'UTC'));
    $on = World::change($billable, ['addons' => ['storage', 'sso']]);

    expect($off['booked'])->toBe([])->and($off['previewed'])->toBe([])
        ->and($on['booked'])->toBe([])->and($on['previewed'])->toBe([])
        ->and(World::$payments)->toBe([])
        ->and(World::$refunds)->toBe([])
        ->and(World::recurringNet($billable))->toBe(900)
        ->and(World::$heldValue)->toBe($held);

    $renewal = World::renew($billable);
    expect((int) $renewal->amount_net)->toBe(900)
        ->and(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment');
})->with([
    'date, then ends' => 'waiver-gift',
    'date, then paid' => 'waiver-gift-paid',
    'N periods' => 'waiver-gift-periods',
]);

// ── A coupon entered with a change ──────────────────────────────────────────

dataset('coupon forms', [
    'seat form' => [['seats' => 4]],
    'addon form' => [['addons' => ['storage', 'sso']]],
    'plan form' => [['plan_code' => 'enterprise', 'interval' => 'monthly', 'apply_at' => 'immediate']],
]);

/** A 20 % recurring coupon anyone may enter. */
function changeMatrixEnterableCoupon(): void
{
    app(CouponService::class)->create([
        'code' => 'EXTRA20', 'name' => 'Extra', 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 20,
        'max_redemptions_per_billable' => 3,
    ]);
}

it('applies a coupon entered with a change to the charge now and to the renewals that follow', function (array $payload) {
    $billable = World::subscription('none', 'pro', 3, ['storage'], used: 3);
    changeMatrixEnterableCoupon();

    $report = World::change($billable, $payload + ['coupon_code' => 'EXTRA20']);
    $billable->refresh();

    $couponLines = array_values(array_filter($report['booked'], fn (array $l): bool => $l[0] === 'coupon'));

    // On the charge, as previewed — and in the slot for every renewal from here on.
    expect($report['booked'])->toBe($report['previewed'])
        ->and($couponLines)->toHaveCount(1)
        ->and($couponLines[0][1])->toBe('EXTRA20')
        ->and($couponLines[0][2])->toBeLessThan(0)
        ->and($billable->getBillingSubscriptionMeta()['active_recurring_coupon']['code'] ?? null)->toBe('EXTRA20');

    $after = World::recurringNet($billable);
    $renewal = World::renew($billable);

    expect((int) $renewal->amount_net)->toBe($after)
        ->and((int) $renewal->amount_gross)->toBe(World::heldGross())
        ->and(array_column((array) $renewal->line_items, 'kind'))->toContain('coupon')
        ->and(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment');
})->with('coupon forms');

it('refuses a coupon entered with a change while another discount runs, and applies nothing', function (string $discount, array $payload) {
    $billable = World::subscription($discount, 'pro', 3, ['storage'], used: 3);
    $meta = $billable->getBillingSubscriptionMeta();
    $held = World::$heldValue;
    changeMatrixEnterableCoupon();

    $thrown = null;
    try {
        app(UpdateSubscription::class)->update($billable, $payload + ['coupon_code' => 'EXTRA20']);
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    $billable->refresh();

    // One slot: a second recurring discount is refused as a whole — no half-applied
    // change, nothing charged, patched or written.
    expect($thrown)->not->toBeNull()
        ->and($billable->getBillingSubscriptionMeta())->toBe($meta)
        ->and($billable->getBillingSeatCount())->toBe(3)
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and(World::$payments)->toBe([])
        ->and(World::$refunds)->toBe([])
        ->and(World::$heldValue)->toBe($held);
})->with([
    '50 % coupon' => 'pct50',
    'fixed coupon' => 'fixed',
    'waiver until a date, then ends' => 'waiver-date-end',
    'waiver until a date, then paid' => 'waiver-date-paid',
    'waiver for N periods' => 'waiver-periods',
    'waiver with a granted addon' => 'waiver-gift',
])->with('coupon forms');

// ── Sequences that were only described so far ───────────────────────────────

it('drops a scheduled seat expansion when the subscription has fallen past due by the time it is due', function (): void {
    MollieBilling::notifyBillingAdminsUsing(fn ($billable): array => [$billable]);
    $billable = World::subscription('none', 'pro', 2, [], used: 2);

    // Scheduled while everything was fine …
    app(UpdateSubscription::class)->update($billable, ['seats' => 4, 'apply_at' => 'end_of_period']);
    expect($billable->refresh()->getBillingSubscriptionMeta()['scheduled_change']['seats'])->toBe(4);

    // … then the renewal fails.
    $billable = World::failRenewal($billable);
    app(ScheduleSubscriptionChange::class)->apply($billable);
    $billable->refresh();

    // Not applied for free on the unpaid period: refused like any expansion while past
    // due, dropped, and the customer told to settle the payment first.
    expect($billable->getBillingSeatCount())->toBe(2)
        ->and($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull()
        ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::PastDue);

    Notification::assertSentTo($billable, ScheduledChangeNotAppliedNotification::class, fn ($n): bool => $n->reason === 'payment_outstanding');
});

it('leaves a scheduled change for later when its renewal arrives while another change waits for its payment', function (): void {
    $billable = World::subscription('none', 'pro', 2, [], used: 2);

    // A plan change is scheduled and announced to Mollie …
    app(UpdateSubscription::class)->update($billable, ['plan_code' => 'enterprise', 'interval' => 'monthly', 'apply_at' => 'end_of_period']);

    // … and on 30 Jan a seat is added, whose prorata debit is still pending at the renewal.
    $this->travelTo(Carbon::parse('2026-01-30 12:00:00', 'UTC'));
    World::change($billable, ['seats' => 3], settle: null);
    expect($billable->refresh()->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null)->not->toBeNull();

    // The renewal: the scheduled change cannot be applied with it (one pending change at
    // a time). It must not break the renewal, and it must not be lost.
    $renewal = World::renew($billable);
    $billable->refresh();

    expect($renewal->exists)->toBeTrue()
        ->and($billable->subscription_period_starts_at->toDateString())->toBe('2026-02-01')
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and($billable->getBillingSubscriptionMeta()['scheduled_change']['plan_code'] ?? null)->toBe('enterprise');

    // Once the seat's payment is through, the next dispatcher run applies the plan change.
    World::settle($billable);
    $this->travelTo(Carbon::parse('2026-02-02 02:00:00', 'UTC'));
    app(ScheduleSubscriptionChange::class)->apply($billable);
    World::settle($billable);

    expect($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('enterprise')
        ->and($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull();

    World::assertNothingOverRefunded($billable);
});

// ── A second change in a period whose plan change settled after the renewal ──

/**
 * On 30 Jan the plan is changed to Pro and the payment is slow; on 1 Feb the renewal is
 * collected for the plan the customer still had; on 3 Feb the payment settles and Pro
 * takes effect — the difference is returned against the renewal (`down`: from Enterprise)
 * or collected as a balance of its own (`up`: from Starter). Three seats in use, Storage
 * on. The clock is left at 10 Feb 12:00: 19 of February's 28 days are left.
 */
function changeMatrixAfterSpannedChange(string $first, string $discount): TestBillable
{
    $billable = World::subscription($discount, $first === 'down' ? 'enterprise' : 'starter', 3, ['storage'], at: '2026-01-30 12:00:00', used: 3);

    World::change($billable, ['plan_code' => 'pro', 'interval' => 'monthly', 'seats' => 3, 'apply_at' => 'immediate'], settle: null);
    World::renew($billable);

    test()->travelTo(Carbon::parse('2026-02-03 12:00:00', 'UTC'));
    World::settle($billable);

    foreach (World::$payments as $id => $payment) {
        if (isset($payment['metadata']['undercollection']) && ! \GraystackIT\MollieBilling\Models\BillingInvoice::query()->where('mollie_payment_id', $id)->exists()) {
            World::deliver($billable, (string) $id, 'paid', (string) $payment['value'], (array) $payment['metadata']);
        }
    }

    test()->travelTo(Carbon::parse('2026-02-10 12:00:00', 'UTC'));

    return $billable->refresh();
}

dataset('second changes', [
    'plan down' => [['plan_code' => 'starter', 'interval' => 'monthly', 'seats' => 3, 'apply_at' => 'immediate']],
    'plan up' => [['plan_code' => 'enterprise', 'interval' => 'monthly', 'seats' => 3, 'apply_at' => 'immediate']],
    'a seat more' => [['seats' => 4]],
    'a seat less' => [['seats' => 2]],
    'an addon more' => [['addons' => ['storage', 'sso']]],
    'an addon less' => [['addons' => []]],
]);

it('settles a second change as if the period had been paid on the plan in between from its first day', function (string $first, string $discount, array $payload) {
    $billable = changeMatrixAfterSpannedChange($first, $discount);

    expect($billable->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and($billable->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null)->toBeNull();

    if (($payload['seats'] ?? 3) < 3) {
        $meta = $billable->getBillingSubscriptionMeta();
        $meta['used_seats'] = 2;
        $billable->forceFill(['subscription_meta' => $meta])->save();
    }

    // What February costs on Pro with three seats and Storage, under the discount: that is
    // what the customer has paid for it, in whatever documents.
    $paid = World::recurringNet($billable);

    $report = World::change($billable, $payload);
    $billable->refresh();

    $after = World::recurringNet($billable);
    // 19 of 28 days left. Up to six lines, each rounded on its own.
    $expected = (int) round(($after - $paid) * 19 / 28);
    $net = World::sum($report['booked']);

    expect($report['booked'])->toBe($report['previewed']);

    if ($discount !== 'none' && ! isset($payload['plan_code']) && $expected < 0) {
        // A seat or an addon given back under a discount is credited at what was paid for
        // it — the discounted share — never more than its list price for the days left.
        expect($net)->toBeLessThan(0)->toBeGreaterThanOrEqual($expected - 3);
    } else {
        expect(abs($net - $expected))->toBeLessThanOrEqual(3, "booked {$net}; paid {$paid} for the period, new recurring {$after}, over 19/28 → {$expected}");
    }

    World::assertNothingOverRefunded($billable);

    if ($after > 0) {
        expect(World::heldGross())->toBe($report['preview']['grossTotal']);

        $held = World::heldGross();
        $renewal = World::renew($billable);

        expect((int) $renewal->amount_gross)->toBe($held)
            ->and(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment');
    }
})->with(['down', 'up'])->with(['none', 'pct50', 'fixed', 'waiver-periods'])->with('second changes');

it('credits the plan in between to the cent: 50.00 × 19/28 = 33.93, and charges Starter 20.00 × 19/28 = 13.57', function (string $country, ?string $vatNumber, float $rate) {
    World::$country = $country;
    World::$vatNumber = $vatNumber;

    $billable = World::subscription('none', 'enterprise', 2, [], at: '2026-01-30 12:00:00');
    World::change($billable, ['plan_code' => 'pro', 'interval' => 'monthly', 'apply_at' => 'immediate'], settle: null);
    World::renew($billable);
    $this->travelTo(Carbon::parse('2026-02-03 12:00:00', 'UTC'));
    World::settle($billable);
    $this->travelTo(Carbon::parse('2026-02-10 12:00:00', 'UTC'));
    $refundsBefore = array_sum(array_map('count', World::$refunds));

    $report = World::change($billable->refresh(), ['plan_code' => 'starter', 'interval' => 'monthly', 'apply_at' => 'immediate']);

    $lines = collect($report['booked'])->filter(fn (array $l): bool => $l[0] === 'plan')->mapWithKeys(fn (array $l): array => [$l[1] => $l[2]])->all();

    expect($report['booked'])->toBe($report['previewed'])
        ->and($lines)->toBe(['pro' => -3393, 'starter' => 1357])
        // Charged for Starter, with this customer's VAT.
        ->and($report['charged'])->toBe(number_format(round(1357 * (1 + $rate / 100)) / 100, 2, '.', ''))
        ->and(array_sum(array_map('count', World::$refunds)))->toBe($refundsBefore + 1)
        ->and($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('starter');

    World::assertNothingOverRefunded($billable);
})->with([
    'AT, 20 %' => ['AT', null, 20.0],
    'DE, 19 %' => ['DE', null, 19.0],
    'DE business, reverse charge' => ['DE', 'DE123456789', 0.0],
]);

it('credits the whole plan when the second change follows an upgrade whose balance was charged after the renewal', function (): void {
    // Starter → Pro across the renewal: February is paid as 20.00 (renewal) plus the
    // balance for the 26 of 28 days Pro runs from 3 Feb: 30.00 × 26/28 = 27.86, + 20 % =
    // 33.43. Pro → Enterprise on 10 Feb credits 50.00 × 19/28 = 33.93, in two lines (13.57
    // against the renewal, 27.86 × 19/26 = 20.36 against the balance), and charges
    // 200.00 × 19/28 = 135.71.
    $billable = World::subscription('none', 'starter', 2, [], at: '2026-01-30 12:00:00');
    World::change($billable, ['plan_code' => 'pro', 'interval' => 'monthly', 'apply_at' => 'immediate'], settle: null);
    World::renew($billable);
    $this->travelTo(Carbon::parse('2026-02-03 12:00:00', 'UTC'));
    World::settle($billable);

    $balance = collect(World::$payments)->filter(fn (array $p): bool => isset($p['metadata']['undercollection']));
    expect($balance)->toHaveCount(1)->and($balance->first()['value'])->toBe('33.43');
    World::deliver($billable, (string) $balance->keys()->first(), 'paid', '33.43', (array) $balance->first()['metadata']);

    $this->travelTo(Carbon::parse('2026-02-10 12:00:00', 'UTC'));
    $report = World::change($billable->refresh(), ['plan_code' => 'enterprise', 'interval' => 'monthly', 'apply_at' => 'immediate']);

    $credited = array_sum(array_map(fn (array $l): int => $l[0] === 'plan' && $l[2] < 0 ? $l[2] : 0, $report['booked']));
    $charged = array_sum(array_map(fn (array $l): int => $l[0] === 'plan' && $l[2] > 0 ? $l[2] : 0, $report['booked']));

    expect($report['booked'])->toBe($report['previewed'])
        ->and(abs($credited + 3393))->toBeLessThanOrEqual(1)
        ->and($charged)->toBe(13571);

    World::assertNothingOverRefunded($billable);
});

it('ends at a Local free plan and cancels at Mollie when the paid plan carried extra seats and an addon the free plan does not allow', function (string $discount) {
    config()->set('mollie-billing-plans.plans.free.allowed_addons', []);
    $billable = World::subscription($discount, 'pro', 4, ['storage'], used: 1);

    $report = World::change($billable, ['plan_code' => 'free', 'interval' => 'monthly', 'apply_at' => 'immediate']);
    $billable->refresh();

    expect($billable->getBillingSubscriptionPlanCode())->toBe('free')
        ->and($billable->subscription_source)->toBe(\GraystackIT\MollieBilling\Enums\SubscriptionSource::Local)
        ->and(array_values($billable->getActiveBillingAddonCodes()))->toBe([])
        ->and($billable->getBillingSeatCount())->toBe(2)
        ->and(World::$cancelled)->toBeTrue()
        ->and($report['booked'])->toBe($report['previewed']);

    World::assertNothingOverRefunded($billable);
})->with(['none', 'pct50', 'fixed', 'waiver-date-paid']);

it('refuses what costs money on a cancelled subscription before anything is charged, and says why', function (array $payload, string $sentence) {
    $billable = World::subscription('none', 'pro', 3, ['storage'], used: 1);
    $billable->forceFill(['subscription_status' => SubscriptionStatus::Cancelled, 'subscription_ends_at' => $billable->nextBillingDate()])->save();
    $sent = count(World::$sent);

    $preview = app(\GraystackIT\MollieBilling\Services\Billing\PreviewService::class)->previewUpdate($billable->refresh(), $payload);

    expect(array_column($preview['errors'], 'type'))->toContain('subscription_ended')
        ->and(fn () => app(UpdateSubscription::class)->update($billable, $payload))
        // A seat or a paid addon in the grace period names the end date and the way back
        // (decision 5); a plan or interval move keeps the general sentence.
        ->toThrow(\GraystackIT\MollieBilling\Exceptions\SubscriptionEndedChangeNotAllowedException::class, __($sentence, [
            'date' => \GraystackIT\MollieBilling\Exceptions\SubscriptionEndingBookingNotAllowedException::endDate($billable),
        ]));

    expect(count(World::$sent))->toBe($sent)
        ->and($billable->refresh()->getBillingSeatCount())->toBe(3)
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('pro');
})->with([
    'a seat more' => [['seats' => 4], 'billing::portal.error_subscription_ending_seats'],
    'a paid addon' => [['addons' => ['storage', 'sso']], 'billing::portal.error_subscription_ending_addons'],
    'another plan' => [['plan_code' => 'enterprise', 'interval' => 'monthly', 'apply_at' => 'immediate'], 'billing::portal.error_subscription_ended'],
    'the other interval' => [['plan_code' => 'pro', 'interval' => 'yearly', 'apply_at' => 'immediate'], 'billing::portal.error_subscription_ended'],
]);

it('still lets a cancelled subscription give up a seat or an addon, crediting what was paid', function (array $payload) {
    $billable = World::subscription('none', 'pro', 3, ['storage'], used: 1);
    $billable->forceFill(['subscription_status' => SubscriptionStatus::Cancelled, 'subscription_ends_at' => $billable->nextBillingDate()])->save();

    $report = World::change($billable->refresh(), $payload);

    expect(World::sum($report['booked']))->toBeLessThan(0)
        ->and($report['booked'])->toBe($report['previewed'])
        ->and($billable->refresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Cancelled);
})->with([
    'a seat less' => [['seats' => 2]],
    'an addon off' => [['addons' => []]],
]);

// ── Decided 16 Nov 2026: giving something back on a cancelled subscription ─────

it('refunds the unused paid share of a seat or addon given back on a cancelled subscription, and leaves its end and Mollie alone', function (string $discount, array $payload, int $listPrice) {
    $billable = World::subscription($discount, 'pro', 3, ['storage'], used: 1);
    $endsAt = $billable->nextBillingDate();
    $billable->forceFill(['subscription_status' => SubscriptionStatus::Cancelled, 'subscription_ends_at' => $endsAt])->save();
    World::$cancelled = true;

    $patches = count(array_filter(World::$sent, fn (object $r): bool => $r instanceof \Mollie\Api\Http\Requests\UpdateSubscriptionRequest));
    $held = World::$heldValue;

    $report = World::change($billable->refresh(), $payload);
    $billable->refresh();

    // What was paid for it: the list price, half of it under the 50 % coupon, nothing
    // under the 100 % coupon. 21 of January's 31 days are left.
    $paid = match ($discount) {
        'pct50' => (int) round($listPrice / 2),
        'full100' => 0,
        default => $listPrice,
    };
    $expected = -(int) round($paid * 21 / 31);
    $net = World::sum($report['booked']);
    $refundedGross = 0;
    foreach (World::$refunds as $refunds) {
        foreach ($refunds as $refund) {
            $refundedGross += \GraystackIT\MollieBilling\Support\BillingMoney::minorFromMollieAmount((string) $refund->amount->value, 'EUR');
        }
    }

    expect($report['booked'])->toBe($report['previewed'])
        ->and(abs($net - $expected))->toBeLessThanOrEqual(1, "booked {$net}, paid {$paid} × 21/31 = {$expected}")
        // AT, 20 %.
        ->and(abs($refundedGross - (int) round(-$expected * 1.2)))->toBeLessThanOrEqual(1)
        ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Cancelled)
        ->and($billable->subscription_ends_at->toIso8601String())->toBe($endsAt->toIso8601String())
        // The Mollie subscription is cancelled: there is nothing to re-price.
        ->and(count(array_filter(World::$sent, fn (object $r): bool => $r instanceof \Mollie\Api\Http\Requests\UpdateSubscriptionRequest)))->toBe($patches)
        ->and(World::$heldValue)->toBe($held);

    World::assertNothingOverRefunded($billable);
})->with(['none', 'pct50', 'full100'])->with([
    'a seat less' => [['seats' => 2], 500],
    'the addon off' => [['addons' => []], 900],
]);

it('credits a seat or addon given back on a cancelled subscription in full under a plan waiver — the waiver covers the plan, not them', function (string $discount, array $payload, int $listPrice) {
    $billable = World::subscription($discount, 'pro', 3, ['storage'], used: 1);
    $endsAt = $billable->nextBillingDate();
    $billable->forceFill(['subscription_status' => SubscriptionStatus::Cancelled, 'subscription_ends_at' => $endsAt])->save();
    World::$cancelled = true;
    $patches = count(array_filter(World::$sent, fn (object $r): bool => $r instanceof \Mollie\Api\Http\Requests\UpdateSubscriptionRequest));

    $report = World::change($billable->refresh(), $payload);
    $billable->refresh();

    // The plan is free, the seat and the addon were paid at list price: 21 of 31 days.
    $expected = -(int) round($listPrice * 21 / 31);

    expect($report['booked'])->toBe($report['previewed'])
        ->and(abs(World::sum($report['booked']) - $expected))->toBeLessThanOrEqual(1)
        ->and($billable->subscription_ends_at->toIso8601String())->toBe($endsAt->toIso8601String())
        ->and(count(array_filter(World::$sent, fn (object $r): bool => $r instanceof \Mollie\Api\Http\Requests\UpdateSubscriptionRequest)))->toBe($patches);

    World::assertNothingOverRefunded($billable);
})->with(['waiver-date-end', 'waiver-date-paid', 'waiver-periods'])->with([
    'a seat less' => [['seats' => 2], 500],
    'the addon off' => [['addons' => []], 900],
]);

// ── Decided 16 Nov 2026: scheduling a change while past due ────────────────────

/** Past due because the renewal failed, or only because a usage overage could not be collected. */
function changeMatrixPastDue(TestBillable $billable, string $kind): TestBillable
{
    if ($kind === 'renewal') {
        return World::failRenewal($billable);
    }

    $meta = $billable->refresh()->getBillingSubscriptionMeta();
    $meta[\GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly::PAST_DUE_KEY] = now()->toIso8601String();
    $billable->forceFill(['subscription_status' => SubscriptionStatus::PastDue, 'subscription_meta' => $meta])->save();

    return $billable->refresh();
}

dataset('scheduled expansions', [
    'a dearer plan' => [['plan_code' => 'enterprise', 'interval' => 'monthly', 'apply_at' => 'end_of_period']],
    'the yearly interval' => [['plan_code' => 'pro', 'interval' => 'yearly', 'apply_at' => 'end_of_period']],
    'more seats' => [['seats' => 5, 'apply_at' => 'end_of_period']],
    'another paid addon' => [['addons' => ['storage', 'sso'], 'apply_at' => 'end_of_period']],
]);

dataset('scheduled reductions', [
    'a cheaper plan' => [['plan_code' => 'starter', 'interval' => 'monthly', 'apply_at' => 'end_of_period'], true],
    'fewer seats' => [['seats' => 2, 'apply_at' => 'end_of_period'], false],
    'the addon off' => [['addons' => [], 'apply_at' => 'end_of_period'], false],
]);

it('refuses to schedule an expansion while past due, with the reason, and stores nothing', function (string $kind, array $payload) {
    $billable = changeMatrixPastDue(World::subscription('none', 'pro', 3, ['storage'], used: 1), $kind);
    $sent = count(World::$sent);

    expect(fn () => app(UpdateSubscription::class)->update($billable, $payload))
        ->toThrow(\GraystackIT\MollieBilling\Exceptions\PastDueExpansionNotAllowedException::class);

    expect($billable->refresh()->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull()
        ->and(count(World::$sent))->toBe($sent);
})->with(['renewal', 'overage'])->with('scheduled expansions');

it('schedules a reduction while past due — except a plan change under a pure overage debt, which could only be dropped', function (string $kind, array $payload, bool $movesPlan) {
    $billable = changeMatrixPastDue(World::subscription('none', 'pro', 3, ['storage'], used: 1), $kind);

    if ($kind === 'overage' && $movesPlan) {
        // The period is paid; a plan change on it would be the past-due reset charging a
        // second full period. Refused now as it is refused immediately — scheduling it
        // would only store something the nightly run has to throw away.
        expect(fn () => app(UpdateSubscription::class)->update($billable, $payload))
            ->toThrow(\GraystackIT\MollieBilling\Exceptions\PastDueExpansionNotAllowedException::class);
        expect($billable->refresh()->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull();

        return;
    }

    app(UpdateSubscription::class)->update($billable, $payload);

    expect($billable->refresh()->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->not->toBeNull()
        ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::PastDue);
})->with(['renewal', 'overage'])->with('scheduled reductions');

it('drops a scheduled expansion that meets a past-due subscription on its night, and tells the customer to settle first', function (string $kind, array $payload) {
    MollieBilling::notifyBillingAdminsUsing(fn ($billable): array => [$billable]);
    $billable = World::subscription('none', 'pro', 3, ['storage'], used: 1);
    app(UpdateSubscription::class)->update($billable, $payload);
    $before = [$billable->refresh()->getBillingSubscriptionPlanCode(), (string) $billable->getBillingSubscriptionInterval(), $billable->getBillingSeatCount(), $billable->getActiveBillingAddonCodes()];

    $billable = changeMatrixPastDue($billable, $kind);
    $payments = count(World::$payments);

    app(ScheduleSubscriptionChange::class)->apply($billable);
    $billable->refresh();

    expect($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull()
        ->and([$billable->getBillingSubscriptionPlanCode(), (string) $billable->getBillingSubscriptionInterval(), $billable->getBillingSeatCount(), $billable->getActiveBillingAddonCodes()])->toBe($before)
        ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::PastDue)
        // Not the past-due reset: nothing is charged.
        ->and(count(World::$payments))->toBe($payments)
        ->and(ScheduleSubscriptionChange::droppedNotice($billable)['reason'] ?? null)->toBe('payment_outstanding');

    Notification::assertSentTo($billable, ScheduledChangeNotAppliedNotification::class, fn ($n): bool => $n->reason === 'payment_outstanding');
})->with(['renewal', 'overage'])->with('scheduled expansions');

it('applies a scheduled seat or addon reduction on its night although the subscription is past due', function (string $kind, array $payload, bool $movesPlan) {
    $billable = World::subscription('none', 'pro', 3, ['storage'], used: 1);
    app(UpdateSubscription::class)->update($billable, $payload);
    $billable = changeMatrixPastDue($billable->refresh(), $kind);
    $payments = count(World::$payments);

    app(ScheduleSubscriptionChange::class)->apply($billable);
    $billable->refresh();

    expect($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull()
        ->and(isset($payload['seats']) ? $billable->getBillingSeatCount() : $billable->getActiveBillingAddonCodes())->toBe($payload['seats'] ?? [])
        // A reduction charges nothing; and no reset.
        ->and(count(World::$payments))->toBe($payments)
        ->and(ScheduleSubscriptionChange::droppedNotice($billable))->toBeNull();
})->with(['renewal', 'overage'])->with([
    'fewer seats' => [['seats' => 2, 'apply_at' => 'end_of_period'], false],
    'the addon off' => [['addons' => [], 'apply_at' => 'end_of_period'], false],
]);

it('applies a scheduled downgrade on a subscription whose renewal failed as the past-due reset, and drops it under a pure overage debt', function (string $kind) {
    MollieBilling::notifyBillingAdminsUsing(fn ($billable): array => [$billable]);
    $billable = World::subscription('none', 'pro', 2, [], used: 1);
    app(UpdateSubscription::class)->update($billable, ['plan_code' => 'starter', 'interval' => 'monthly', 'apply_at' => 'end_of_period']);
    $billable = changeMatrixPastDue($billable->refresh(), $kind);
    $payments = count(World::$payments);

    app(ScheduleSubscriptionChange::class)->apply($billable);
    World::settle($billable);
    $billable->refresh();

    if ($kind === 'overage') {
        // The period is paid: a plan change now would charge a second one. Dropped.
        expect($billable->getBillingSubscriptionPlanCode())->toBe('pro')
            ->and(count(World::$payments))->toBe($payments)
            ->and(ScheduleSubscriptionChange::droppedNotice($billable)['reason'] ?? null)->toBe('payment_outstanding');

        return;
    }

    // Nothing of the period was paid: Starter opens a fresh one, charged in full —
    // 20.00 + 20 % — and the subscription is active again.
    $charge = array_values(World::$payments)[count(World::$payments) - 1];

    expect($billable->getBillingSubscriptionPlanCode())->toBe('starter')
        ->and(count(World::$payments))->toBe($payments + 1)
        ->and($charge['value'])->toBe('24.00')
        ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active);
})->with(['renewal', 'overage']);


// ── Closing the list: interval across a renewal, and a balance that is not paid yet ──

it('returns the unused days of the monthly renewal when an interval change settles after it, and settles a further change on the yearly period', function (): void {
    // Pro monthly → Pro yearly on 30 Jan, paid slowly; 1 Feb the monthly renewal is
    // collected; 3 Feb the yearly payment settles and the year begins that day. The
    // monthly renewal is kept for the two days it covered and returned for the other 26:
    // 50.00 × 26/28 = 46.43, + 20 % = 55.72.
    $billable = World::subscription('none', 'pro', 2, [], at: '2026-01-30 12:00:00');
    World::change($billable, ['plan_code' => 'pro', 'interval' => 'yearly', 'apply_at' => 'immediate'], settle: null);
    $renewal = World::renew($billable);
    $this->travelTo(Carbon::parse('2026-02-03 12:00:00', 'UTC'));
    World::settle($billable);
    $billable->refresh();

    $returned = 0;
    foreach (World::$refunds[(string) $renewal->mollie_payment_id] ?? [] as $refund) {
        $returned += \GraystackIT\MollieBilling\Support\BillingMoney::minorFromMollieAmount((string) $refund->amount->value, 'EUR');
    }

    expect((string) $billable->getBillingSubscriptionInterval())->toBe('yearly')
        ->and($billable->getBillingPeriodStartsAt()->toDateString())->toBe('2026-02-03')
        ->and($returned)->toBe((int) round(round(5000 * 26 / 28) * 1.2));

    // 10 Feb: Pro → Enterprise on the yearly period. Credit and charge are the yearly
    // prices (500.00 / 2000.00) for the days of that year that are left.
    $this->travelTo(Carbon::parse('2026-02-10 12:00:00', 'UTC'));
    $start = $billable->getBillingPeriodStartsAt();
    $end = $billable->nextBillingDate();
    $total = (int) round($start->diffInDays($end));
    $left = (int) ceil(now()->diffInDays($end));

    $report = World::change($billable, ['plan_code' => 'enterprise', 'interval' => 'yearly', 'apply_at' => 'immediate']);
    $expected = (int) round((200000 - 50000) * $left / $total);

    expect($report['booked'])->toBe($report['previewed'])
        ->and(abs(World::sum($report['booked']) - $expected))->toBeLessThanOrEqual(2 + (int) round(150000 / $total), 'booked '.World::sum($report['booked'])." vs {$expected} ({$left}/{$total})");

    World::assertNothingOverRefunded($billable);
});

/**
 * Starter → Pro across the renewal, the balance (30.00 × 26/28 = 27.86, 33.43 gross) not
 * paid yet. Returns [billable, balance payment id, its metadata]; the clock is at 3 Feb 12:00.
 *
 * @return array{0: TestBillable, 1: string, 2: array<string, mixed>}
 */
function changeMatrixOpenBalance(): array
{
    $billable = World::subscription('none', 'starter', 2, [], at: '2026-01-30 12:00:00');
    World::change($billable, ['plan_code' => 'pro', 'interval' => 'monthly', 'apply_at' => 'immediate'], settle: null);
    World::renew($billable);
    test()->travelTo(Carbon::parse('2026-02-03 12:00:00', 'UTC'));
    World::settle($billable);

    $balance = collect(World::$payments)->filter(fn (array $p): bool => isset($p['metadata']['undercollection']));

    return [$billable->refresh(), (string) $balance->keys()->first(), (array) $balance->first()['metadata']];
}

/**
 * Net over every document for February: what the customer has paid for the month. The
 * prorata of the January change is booked on 3 Feb as well (Pro's extra for the last two
 * days of January, 30.00 × 2/31 = 1.94) and is not February's.
 */
function changeMatrixFebruaryNet(TestBillable $billable): int
{
    return (int) \GraystackIT\MollieBilling\Models\BillingInvoice::query()
        ->where('billable_id', $billable->getKey())
        ->where('created_at', '>=', '2026-02-01 00:00:00')
        ->sum('amount_net') - 194;
}

it('credits only what is paid while the balance is still open, and keeps of a balance that arrives late only the days the plan was used', function (): void {
    [$billable, $balanceId, $metadata] = changeMatrixOpenBalance();

    // 10 Feb, the balance still open: back to Starter. Of Pro only the renewal's 20.00 is
    // paid — 20.00 × 19/28 = 13.57 is credited, and Starter costs the same 13.57.
    $this->travelTo(Carbon::parse('2026-02-10 12:00:00', 'UTC'));
    $report = World::change($billable, ['plan_code' => 'starter', 'interval' => 'monthly', 'apply_at' => 'immediate']);

    $credited = array_sum(array_map(fn (array $l): int => $l[0] === 'plan' && $l[2] < 0 ? $l[2] : 0, $report['booked']));

    expect($report['booked'])->toBe($report['previewed'])
        ->and(abs($credited + 1357))->toBeLessThanOrEqual(1)
        ->and(abs(World::sum($report['booked'])))->toBeLessThanOrEqual(1);

    // 12 Feb: the balance is collected after all. It was priced for Pro from 3 Feb to the
    // end of the month; Pro ran until 10 Feb. The customer keeps paying for those seven
    // days — 30.00 × 7/28 = 7.50 — and the rest comes back.
    $this->travelTo(Carbon::parse('2026-02-12 12:00:00', 'UTC'));
    World::deliver($billable, $balanceId, 'paid', '33.43', $metadata);
    $billable->refresh();

    // February in total: Starter for the month, plus Pro's extra for seven days.
    expect(abs(changeMatrixFebruaryNet($billable) - (2000 + 750)))->toBeLessThanOrEqual(2, 'February net '.changeMatrixFebruaryNet($billable))
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('starter')
        ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active);

    World::assertNothingOverRefunded($billable);

    // Delivered again: nothing twice.
    $net = changeMatrixFebruaryNet($billable);
    World::deliver($billable, $balanceId, 'paid', '33.43', $metadata);
    expect(changeMatrixFebruaryNet($billable->refresh()))->toBe($net);
});

it('keeps the whole balance when it is paid while the plan it pays for still runs', function (): void {
    [$billable, $balanceId, $metadata] = changeMatrixOpenBalance();

    $this->travelTo(Carbon::parse('2026-02-12 12:00:00', 'UTC'));
    World::deliver($billable, $balanceId, 'paid', '33.43', $metadata);

    // Starter 20.00 + the balance 27.86, nothing returned.
    expect(changeMatrixFebruaryNet($billable->refresh()))->toBe(2000 + 2786);
});

// A declined balance and a plan change from there: the period is paid, so the change is an
// ordinary one and not the past-due reset — see ChangeMatrixPaidPeriodPastDueTest.

it('tells a customer who owes only a usage overage what to do instead of scheduling a plan change', function (): void {
    $billable = changeMatrixPastDue(World::subscription('none', 'pro', 3, ['storage'], used: 1), 'overage');

    $message = null;
    try {
        app(UpdateSubscription::class)->update($billable, ['plan_code' => 'starter', 'interval' => 'monthly', 'apply_at' => 'end_of_period']);
    } catch (\GraystackIT\MollieBilling\Exceptions\PastDueExpansionNotAllowedException $e) {
        $message = $e->getMessage();
    }

    // Says what is open, what to do, and what still works meanwhile.
    expect($message)->toBe(__('billing::portal.error_past_due_plan_change'))
        ->and(app('translator')->get('billing::portal.error_past_due_plan_change', [], 'en'))->toContain('settle')->toContain('seats')
        ->and(app('translator')->get('billing::portal.error_past_due_plan_change', [], 'de'))->toContain('begleiche')->toContain('Sitze');

    // And it is true: a seat can still be given back.
    app(UpdateSubscription::class)->update($billable, ['seats' => 2]);
    expect($billable->refresh()->getBillingSeatCount())->toBe(2);
});
