<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\PlanChangeIntent;
use GraystackIT\MollieBilling\Services\Billing\ProrataComposer;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Storage;
use Mollie\Laravel\Facades\Mollie;

/**
 * Two changes inside one billing period.
 *
 * The first is priced against the whole period and buys only the days that are left.
 * The second reads what the first *recorded* — `buildRefundLine()` derives its credit from
 * the line's own stamped window — so if a line that bought 21 days claims to have bought
 * 31, everything downstream divides by the wrong denominator. Nothing in the suite drove
 * two changes in one period before, which is why the mismatch could sit in the stamp.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
    config()->set('mollie-billing.vat.seller_country', 'AT');

    config()->set('mollie-billing-plans.plans', [
        'starter' => [
            'name' => 'Starter', 'tier' => 1, 'included_seats' => 1,
            'feature_keys' => [], 'allowed_addons' => ['analytics'],
            'intervals' => ['monthly' => ['base_price_net' => 900, 'seat_price_net' => 500, 'included_usages' => []]],
        ],
        'pro' => [
            'name' => 'Pro', 'tier' => 2, 'included_seats' => 1,
            'feature_keys' => [], 'allowed_addons' => ['analytics'],
            'intervals' => ['monthly' => ['base_price_net' => 2900, 'seat_price_net' => 500, 'included_usages' => []]],
        ],
        'enterprise' => [
            'name' => 'Enterprise', 'tier' => 3, 'included_seats' => 1,
            'feature_keys' => [], 'allowed_addons' => ['analytics'],
            'intervals' => ['monthly' => ['base_price_net' => 5900, 'seat_price_net' => 500, 'included_usages' => []]],
        ],
    ]);
    config()->set('mollie-billing-plans.plans.pro-b', [
        'name' => 'Pro B', 'tier' => 2, 'included_seats' => 1,
        'feature_keys' => [], 'allowed_addons' => ['analytics'],
        'intervals' => ['monthly' => ['base_price_net' => 2900, 'seat_price_net' => 500, 'included_usages' => []]],
    ]);
    config()->set('mollie-billing-plans.addons', [
        'analytics' => ['name' => 'Analytics', 'feature_keys' => [], 'intervals' => ['monthly' => ['price_net' => 900]]],
    ]);
});

/** Period 1 January → 1 February 2026 — 31 days, so the day arithmetic is unambiguous. */
function secondChangeBillable(string $plan = 'pro', int $seats = 1, array $addons = []): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Second Change Ltd',
        'email' => uniqid('second-').'@x.test',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
        'mollie_customer_id' => 'cst_second',
        'mollie_mandate_id' => 'mdt_second',
    ]);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => $plan,
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => Carbon::parse('2026-01-01 00:00:00', 'UTC'),
        'active_addon_codes' => array_keys($addons),
        'subscription_meta' => ['seat_count' => $seats, 'mollie_subscription_id' => 'sub_second'],
    ])->save();

    return $billable->refresh();
}

/** The renewal that paid for the period, one line per component. */
function secondChangeRenewalInvoice(TestBillable $billable, array $lines): BillingInvoice
{
    $start = Carbon::parse('2026-01-01 00:00:00', 'UTC');
    $end = Carbon::parse('2026-02-01 00:00:00', 'UTC');

    $net = array_sum(array_map(fn (array $l): int => (int) $l['amount_net'], $lines));
    $vat = (int) round($net * 0.2);

    return BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => uniqid('tr_second_'),
        'mollie_subscription_id' => 'sub_second',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => $net,
        'amount_vat' => $vat,
        'amount_gross' => $net + $vat,
        'line_items' => array_map(function (array $l) use ($start, $end): array {
            $lineVat = (int) round((int) $l['amount_net'] * 0.2);

            return $l + [
                'vat_rate' => 20.0,
                'vat_amount' => $lineVat,
                'amount_gross' => (int) $l['amount_net'] + $lineVat,
                'period_start' => $start->toIso8601String(),
                'period_end' => $end->toIso8601String(),
            ];
        }, $lines),
        'period_start' => $start,
        'period_end' => $end,
    ])->refresh();
}

/** Persist a composed charge line the way ProrataChargeHandler does. */
function persistProrataCharge(TestBillable $billable, array $chargeLines): BillingInvoice
{
    $lineItems = array_map(fn ($line): array => $line->toArray(), $chargeLines);

    return app(InvoiceService::class)->createInvoice(
        $billable,
        InvoiceKind::Subscription,
        uniqid('tr_prorata_'),
        'sub_second',
        $lineItems,
    );
}

it('credits a second change against what the first one actually bought', function (): void {
    // Day 11 of a 31-day period: 21 days left.
    $this->travelTo(Carbon::parse('2026-01-11 00:00:00', 'UTC'));

    $billable = secondChangeBillable('pro');
    secondChangeRenewalInvoice($billable, [
        ['kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1, 'unit_price_net' => 2900, 'amount_net' => 2900],
    ]);

    $composer = app(ProrataComposer::class);

    $upgrade = $composer->compose(new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'pro', newPlan: 'enterprise',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: [], newAddons: [],
    ));

    $charges = array_values(array_filter($upgrade, fn ($l): bool => $l->direction === 'charge'));

    // 5900 × 21/31 = 3997. That is the price of 21 days, not of 31.
    expect($charges)->toHaveCount(1)
        ->and($charges[0]->amountNet)->toBe(3997);

    $prorataInvoice = persistProrataCharge($billable, $charges);

    // What it bought is what it says it bought. The stamp used to read 1 Jan → 1 Feb, and
    // that is the number every later change divides by.
    $stamped = $prorataInvoice->line_items[0];
    expect(Carbon::parse((string) $stamped['period_start'])->toDateString())->toBe('2026-01-11');

    $billable->forceFill(['subscription_plan_code' => 'enterprise'])->save();

    // Day 20: 12 of those 21 days are still unused.
    $this->travelTo(Carbon::parse('2026-01-20 00:00:00', 'UTC'));

    $downgrade = $composer->compose(new PlanChangeIntent(
        billable: $billable->fresh(),
        currentPlan: 'enterprise', newPlan: 'pro',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: [], newAddons: [],
    ));

    $credits = array_values(array_filter($downgrade, fn ($l): bool => $l->direction === 'refund'));
    $creditNet = array_sum(array_map(fn ($l): int => abs($l->amountNet), $credits));

    // 3997 × 12/21 = 2284. Read off the old full-period stamp it was 3997 × 12/31 = 1547 —
    // 737 net of paid, unused service kept from the customer on every second downgrade, and
    // the same 737 overcharged when the second change goes the other way.
    expect($creditNet)->toBe(2284);
});

it('credits a mid-period seat the same way on a second change', function (): void {
    $this->travelTo(Carbon::parse('2026-01-11 00:00:00', 'UTC'));

    $billable = secondChangeBillable('pro', 1);
    secondChangeRenewalInvoice($billable, [
        ['kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1, 'unit_price_net' => 2900, 'amount_net' => 2900],
    ]);

    $composer = app(ProrataComposer::class);

    $addSeat = $composer->compose(new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'pro', newPlan: 'pro',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 2,
        currentAddons: [], newAddons: [],
    ));

    $charges = array_values(array_filter($addSeat, fn ($l): bool => $l->direction === 'charge'));

    // 500 × 21/31 = 339.
    expect($charges)->toHaveCount(1)
        ->and($charges[0]->amountNet)->toBe(339);

    persistProrataCharge($billable, $charges);

    $meta = $billable->getBillingSubscriptionMeta();
    $meta['seat_count'] = 2;
    $billable->forceFill(['subscription_meta' => $meta])->save();

    $this->travelTo(Carbon::parse('2026-01-20 00:00:00', 'UTC'));

    $dropSeat = $composer->compose(new PlanChangeIntent(
        billable: $billable->fresh(),
        currentPlan: 'pro', newPlan: 'pro',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 2, newSeats: 1,
        currentAddons: [], newAddons: [],
    ));

    $credits = array_values(array_filter($dropSeat, fn ($l): bool => $l->direction === 'refund'));
    $creditNet = array_sum(array_map(fn ($l): int => abs($l->amountNet), $credits));

    // 339 × 12/21 = 194, not 339 × 12/31 = 131.
    expect($creditNet)->toBe(194);
});

it('books every refund line of a plan switch against the invoice they credit', function (): void {
    $this->travelTo(Carbon::parse('2026-01-11 00:00:00', 'UTC'));

    $billable = secondChangeBillable('pro', 2, ['analytics' => 1]);

    // 2900 plan + 500 seat + 900 addon = 4300 net, all on one renewal invoice.
    $renewal = secondChangeRenewalInvoice($billable, [
        ['kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1, 'unit_price_net' => 2900, 'amount_net' => 2900],
        ['kind' => 'seats', 'code' => null, 'label' => 'Extra seat', 'quantity' => 1, 'unit_price_net' => 500, 'amount_net' => 500],
        ['kind' => 'addon', 'code' => 'analytics', 'label' => 'Analytics', 'quantity' => 1, 'unit_price_net' => 900, 'amount_net' => 900],
    ]);

    $lines = app(ProrataComposer::class)->compose(new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'pro', newPlan: 'starter',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 2, newSeats: 1,
        currentAddons: ['analytics' => 1], newAddons: [],
    ));

    $refunds = array_values(array_filter($lines, fn ($l): bool => $l->direction === 'refund'));
    $charges = array_values(array_filter($lines, fn ($l): bool => $l->direction === 'charge'));

    // Three separate currentPeriodLines() queries produced these, so each one holds its own
    // clone of the same renewal row.
    expect($refunds)->toHaveCount(3);

    $expected = array_sum(array_map(fn ($l): int => abs($l->amountNet), $refunds));

    app(InvoiceService::class)->createPlanSwitchInvoice($billable, $charges, $refunds);

    // Written per line, every save() overwrote the previous increment and only the last
    // line survived: `remainingRefundableNet()` then offered value already consumed by the
    // switch, and a later admin refund pays it out in real money.
    expect((int) $renewal->fresh()->refunded_net)->toBe($expected)
        ->and($expected)->toBeGreaterThan(1000);
});

it('books an equal-price plan switch instead of dropping it', function (): void {
    $this->travelTo(Carbon::parse('2026-01-11 00:00:00', 'UTC'));

    $billable = secondChangeBillable('pro');
    $renewal = secondChangeRenewalInvoice($billable, [
        ['kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1, 'unit_price_net' => 2900, 'amount_net' => 2900],
    ]);

    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_second', 'status' => 'active']);

    $before = BillingInvoice::query()->count();

    // Same price, different plan — a documented flavour of the change flow. `computeProrata()`
    // nets the two sides to 0/0, so the executor never ran: the composer's charge line for the
    // new plan and its credit for the old one were both discarded. Nothing recorded that the
    // old line's remaining 1965 now belongs to `pro-b`.
    app(\GraystackIT\MollieBilling\Services\Billing\UpdateSubscription::class)
        ->update($billable, ['plan_code' => 'pro-b']);

    expect($billable->fresh()->getBillingSubscriptionPlanCode())->toBe('pro-b')
        ->and(BillingInvoice::query()->count())->toBeGreaterThan($before);

    // 2900 × 21/31 = 1965 of the old plan line is consumed by the switch, so that much is no
    // longer refundable. It used to read as untouched for the rest of the period, and a later
    // admin refund would pay out value the customer has already received as `pro-b`.
    expect((int) $renewal->fresh()->refunded_net)->toBe(1965);
});

it('does not offer a credit line as something further to credit', function (): void {
    $this->travelTo(Carbon::parse('2026-01-11 00:00:00', 'UTC'));

    $billable = secondChangeBillable('pro', 2, ['analytics' => 1]);
    secondChangeRenewalInvoice($billable, [
        ['kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1, 'unit_price_net' => 2900, 'amount_net' => 2900],
        ['kind' => 'seats', 'code' => null, 'label' => 'Extra seat', 'quantity' => 1, 'unit_price_net' => 500, 'amount_net' => 500],
        ['kind' => 'addon', 'code' => 'analytics', 'label' => 'Analytics', 'quantity' => 1, 'unit_price_net' => 900, 'amount_net' => 900],
    ]);

    // A switch invoice carries its refund lines next to its charge lines, under the same
    // plan/seats/addon kinds and with `Paid`/`Subscription` on the header. Sorted
    // newest-invoice-first they won over the invoice that holds the money — and since the
    // header nets to 0 the pool was `max(0, 0 − 0)`, so every selected line was dropped
    // again. On the seat and addon paths the loop had already spent its quantity on the
    // discarded candidate, so the real invoice's line was never even asked for.
    $lines = app(ProrataComposer::class)->compose(new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'pro', newPlan: 'pro-b',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 2, newSeats: 2,
        currentAddons: ['analytics' => 1], newAddons: ['analytics' => 1],
    ));

    app(InvoiceService::class)->createPlanSwitchInvoice(
        $billable,
        array_values(array_filter($lines, fn ($l): bool => $l->direction === 'charge')),
        array_values(array_filter($lines, fn ($l): bool => $l->direction === 'refund')),
    );

    $billable->forceFill(['subscription_plan_code' => 'pro-b'])->save();

    $this->travelTo(Carbon::parse('2026-01-20 00:00:00', 'UTC'));

    // Every candidate offered for a later change must be a positive line on an invoice a
    // refund can actually be paid out of.
    foreach (['plan', 'seats', 'addon'] as $kind) {
        foreach (BillingInvoice::currentPeriodLines($billable->fresh(), $kind, null) as $candidate) {
            /** @var BillingInvoice $invoice */
            $invoice = $candidate['invoice'];
            $line = $invoice->line_items[$candidate['line_index']];

            expect((int) $line['amount_net'])->toBeGreaterThanOrEqual(0, "{$kind} candidate is a credit line")
                // Its own payment, or the one its charges were settled out of — either way a
                // refund of this line can actually be posted.
                ->and($invoice->refundablePaymentId())->not->toBeNull("{$kind} candidate has no payment to refund against");
        }
    }
});

it('credits a third change in one period after an equal-price switch', function (): void {
    $this->travelTo(Carbon::parse('2026-01-11 00:00:00', 'UTC'));

    $billable = secondChangeBillable('pro');
    $renewal = secondChangeRenewalInvoice($billable, [
        ['kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1, 'unit_price_net' => 2900, 'amount_net' => 2900],
    ]);

    $composer = app(ProrataComposer::class);

    // Day 11: equal-price switch. 2900 × 21/31 = 1965 charged for pro-b, the same credited
    // for pro. No money moves — the new plan's 21 days are paid for out of the renewal.
    $switchLines = $composer->compose(new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'pro', newPlan: 'pro-b',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: [], newAddons: [],
    ));

    $switch = app(InvoiceService::class)->createPlanSwitchInvoice(
        $billable,
        array_values(array_filter($switchLines, fn ($l): bool => $l->direction === 'charge')),
        array_values(array_filter($switchLines, fn ($l): bool => $l->direction === 'refund')),
    );

    // The switch carries no payment of its own — `mollie_payment_id` is unique and belongs to
    // the webhook's idempotency — so the settlement is recorded as a relationship.
    expect($switch->mollie_payment_id)->toBeNull()
        ->and((int) $switch->settled_by_invoice_id)->toBe((int) $renewal->getKey())
        ->and($switch->refundablePaymentId())->toBe((string) $renewal->mollie_payment_id)
        // Its header nets to zero, but the value it holds is the new plan's 21 days.
        ->and((int) $switch->amount_net)->toBe(0)
        ->and($switch->refundableBaseNet())->toBe(1965)
        ->and($switch->remainingRefundableNet())->toBe(1965);

    // And the books balance across the two: the renewal's own base dropped by exactly what
    // the switch gained, so together they never exceed what the payment collected.
    expect($renewal->fresh()->remainingRefundableNet() + $switch->remainingRefundableNet())
        ->toBe(2900);

    $billable->forceFill(['subscription_plan_code' => 'pro-b'])->save();

    // Day 20: downgrade to starter with 12 of those 21 days unused.
    $this->travelTo(Carbon::parse('2026-01-20 00:00:00', 'UTC'));

    $downgrade = $composer->compose(new PlanChangeIntent(
        billable: $billable->fresh(),
        currentPlan: 'pro-b', newPlan: 'starter',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: [], newAddons: [],
    ));

    $credits = array_values(array_filter($downgrade, fn ($l): bool => $l->direction === 'refund'));
    $creditNet = array_sum(array_map(fn ($l): int => abs($l->amountNet), $credits));

    // 1965 × 12/21 = 1123. The switch invoice used to be invisible to the composer — no
    // payment to refund against and a header pool of zero — so this credited 0 and the
    // customer was charged 348 for starter on top.
    expect($creditNet)->toBe(1123)
        ->and($credits[0]->originalInvoice?->getKey())->toBe($switch->getKey());
});

it('posts a refund of a settled switch invoice against the payment that holds the money', function (): void {
    $this->travelTo(Carbon::parse('2026-01-11 00:00:00', 'UTC'));

    $billable = secondChangeBillable('pro');
    $renewal = secondChangeRenewalInvoice($billable, [
        ['kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1, 'unit_price_net' => 2900, 'amount_net' => 2900],
    ]);

    $composer = app(ProrataComposer::class);

    $switchLines = $composer->compose(new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'pro', newPlan: 'pro-b',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 1,
        currentAddons: [], newAddons: [],
    ));

    app(InvoiceService::class)->createPlanSwitchInvoice(
        $billable,
        array_values(array_filter($switchLines, fn ($l): bool => $l->direction === 'charge')),
        array_values(array_filter($switchLines, fn ($l): bool => $l->direction === 'refund')),
    );

    $billable->forceFill(['subscription_plan_code' => 'pro-b'])->save();

    $this->travelTo(Carbon::parse('2026-01-20 00:00:00', 'UTC'));

    $credits = array_values(array_filter(
        $composer->compose(new PlanChangeIntent(
            billable: $billable->fresh(),
            currentPlan: 'pro-b', newPlan: 'starter',
            currentInterval: 'monthly', newInterval: 'monthly',
            currentSeats: 1, newSeats: 1,
            currentAddons: [], newAddons: [],
        )),
        fn ($l): bool => $l->direction === 'refund',
    ));

    $posted = [];
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$posted) {
        if ($request instanceof \Mollie\Api\Http\Requests\CreatePaymentRefundRequest) {
            $posted[] = $request;
        }

        return (object) ['id' => 're_settled'];
    });

    $creditNote = app(InvoiceService::class)->createRefund($billable->fresh(), $credits);

    // Not merely booked locally: the money leaves through the renewal's payment, which is
    // where it physically sits. A switch invoice has no payment of its own, and this used to
    // be rejected outright with "Missing original invoice or payment_id".
    expect($posted)->toHaveCount(1)
        ->and($creditNote)->not->toBeNull()
        ->and(abs((int) $creditNote->amount_net))->toBe(1123);

    // The request's own resource path names the payment it will be posted to.
    expect($posted[0]->resolveResourcePath())
        ->toContain((string) $renewal->mollie_payment_id);

    // And nothing was refunded twice: the renewal's own remaining base plus what is left on
    // the switch still adds up to what is refundable in total.
    $switch = BillingInvoice::query()->whereNotNull('settled_by_invoice_id')->firstOrFail();
    expect($renewal->fresh()->remainingRefundableNet() + $switch->fresh()->remainingRefundableNet())
        ->toBe(2900 - 1123);
});
