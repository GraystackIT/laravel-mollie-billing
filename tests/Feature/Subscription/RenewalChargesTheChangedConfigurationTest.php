<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\SpyMollieSubscriptionPatcher;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mollie\Laravel\Facades\Mollie;

/**
 * The renewal that opens a period has to be collected for the configuration that
 * applies IN that period.
 *
 * Mollie creates a recurring payment with the amount the subscription holds at that
 * moment — for a SEPA debit around two days before the charge date — and the payment
 * then stays `pending` for days. A PATCH only ever reaches the *next* payment to be
 * created. So Mollie has to be told when the local state changes, not when a payment for
 * it happens to clear:
 *
 *  - a change scheduled for the period end is announced when it is scheduled (and taken
 *    back when it is withdrawn);
 *  - a seat or addon change PATCHes in the same call that writes the local state, not
 *    after its prorata charge has settled.
 *
 * And where a renewal was created too early to hear about it, what it collected beyond
 * the current configuration is given back.
 */
class RenewalFollowsChangeWebhookController extends MollieWebhookController
{
    public static ?object $nextPayment = null;

    protected function fetchPayment(string $paymentId): object
    {
        return self::$nextPayment ?? throw new RuntimeException('No payment stub configured.');
    }
}

/** A Mollie payment as the webhook sees it, including the live `refunds()` lookup. */
#[AllowDynamicProperties]
class RenewalFollowsChangePayment
{
    /** @var list<object> */
    public static array $refundsAtMollie = [];

    public function __construct(array $attributes)
    {
        foreach ($attributes as $key => $value) {
            $this->{$key} = $value;
        }
    }

    public function refunds(): array
    {
        return self::$refundsAtMollie;
    }
}

beforeEach(function (): void {
    RenewalFollowsChangePayment::$refundsAtMollie = [];

    Storage::fake('local');
    Notification::fake();
    config()->set('mollie-billing.invoices.disk', 'local');

    RenewalFollowsChangeWebhookController::$nextPayment = null;
    $this->app->bind(MollieWebhookController::class, RenewalFollowsChangeWebhookController::class);

    SpyMollieSubscriptionPatcher::$calls = [];
    SpyMollieSubscriptionPatcher::$cancelSucceeds = true;
    $this->app->bind(MollieSubscriptionPatcher::class, SpyMollieSubscriptionPatcher::class);

    config()->set('mollie-billing-plans.plans', [
        'free' => [
            'name' => 'Free', 'tier' => 0, 'included_seats' => 1,
            'feature_keys' => [], 'allowed_addons' => [],
            'intervals' => ['monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []]],
        ],
        'starter' => [
            'name' => 'Starter', 'tier' => 1, 'included_seats' => 1,
            'feature_keys' => [], 'allowed_addons' => [],
            'intervals' => ['monthly' => ['base_price_net' => 900, 'seat_price_net' => 500, 'included_usages' => []]],
        ],
        'pro' => [
            'name' => 'Pro', 'tier' => 2, 'included_seats' => 1,
            'feature_keys' => [], 'allowed_addons' => [],
            'intervals' => [
                'monthly' => ['base_price_net' => 2900, 'seat_price_net' => 500, 'included_usages' => []],
                'yearly' => ['base_price_net' => 29000, 'seat_price_net' => 5000, 'included_usages' => []],
            ],
        ],
    ]);
    config()->set('mollie-billing-plans.addons', []);
});

/** Pro monthly, period 1 Jan 09:00 → 1 Feb 09:00 UTC, one seat, DE (19 %). */
function followsChangeBillable(string $plan = 'pro'): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Follows Change Co',
        'email' => uniqid('follows-change-').'@x.test',
        'billing_country' => 'DE',
    ]);

    $b->forceFill([
        'mollie_customer_id' => 'cst_follow',
        'mollie_mandate_id' => 'mdt_follow',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => $plan,
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => Carbon::parse('2026-01-01 09:00:00', 'UTC'),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_follow', 'seat_count' => 1],
    ])->save();

    return $b->refresh();
}

/**
 * A payment settling at $paidAt. `$createdFor` is the subscription metadata Mollie
 * copied onto the payment when it created it — the configuration it was priced for.
 */
function followsChangeDeliver(TestBillable $billable, string $paymentId, string $paidAt, string $gross, array $createdFor): void
{
    RenewalFollowsChangeWebhookController::$nextPayment = new RenewalFollowsChangePayment([
        'id' => $paymentId,
        'status' => 'paid',
        'amount' => (object) ['value' => $gross, 'currency' => 'EUR'],
        'paidAt' => $paidAt,
        'subscriptionId' => ($createdFor['type'] ?? null) === 'prorata_charge' ? null : 'sub_follow',
        'countryCode' => 'DE',
        'metadata' => $createdFor + [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ]);

    test()->postJson(route('billing.webhook'), ['id' => $paymentId])->assertStatus(200);
}

function followsChangeRenewalFor(string $plan, string $interval = 'monthly', int $extraSeats = 0): array
{
    return ['plan_code' => $plan, 'interval' => $interval, 'addon_codes' => [], 'extra_seats' => $extraSeats];
}

/** Everything invoiced for the billable minus everything credited back, net. */
function followsChangeNetCollected(TestBillable $billable): int
{
    return (int) BillingInvoice::query()->where('billable_id', $billable->getKey())->sum('amount_net');
}

function followsChangeMollieReturns(string $id): void
{
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturn((object) ['id' => $id, 'status' => 'pending']);

    // What a later `refunds()` lookup on the payment reports back.
    RenewalFollowsChangePayment::$refundsAtMollie = [(object) ['id' => $id, 'status' => 'pending']];
}

it('tells Mollie about a scheduled downgrade when it is scheduled, so the renewal is created at the new price', function (): void {
    $billable = followsChangeBillable();

    $this->travelTo(Carbon::parse('2026-01-20 12:00:00', 'UTC'));
    $result = app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'starter', 'interval' => 'monthly', 'apply_at' => 'end_of_period',
    ]);
    expect($result['scheduledFor'])->toBe('2026-02-01T09:00:00+00:00');

    // Nothing local has moved — but Mollie already holds Starter for the next payment.
    $billable->refresh();
    expect($billable->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and(SpyMollieSubscriptionPatcher::$calls)->toHaveCount(1)
        ->and(SpyMollieSubscriptionPatcher::$calls[0][3]['plan_code'])->toBe('starter')
        ->and($billable->getBillingSubscriptionMeta()['scheduled_change']['mollie_prepatched'] ?? null)->toBeTrue();

    // 1 Feb: the dispatcher applies while the SEPA renewal is still pending …
    $this->travelTo(Carbon::parse('2026-02-01 10:00:00', 'UTC'));
    app(ScheduleSubscriptionChange::class)->apply($billable->refresh());
    expect($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('starter');

    // … and it settles two days later at what it was created with: 9.00 + 19 %.
    $this->travelTo(Carbon::parse('2026-02-03 08:00:00', 'UTC'));
    followsChangeDeliver($billable, 'tr_follow_feb', '2026-02-03T07:55:00Z', '10.71', followsChangeRenewalFor('starter'));

    expect(followsChangeNetCollected($billable))->toBe(900);
});

it('applies a scheduled change with the renewal that opens its period', function (): void {
    $billable = followsChangeBillable();

    $this->travelTo(Carbon::parse('2026-01-20 12:00:00', 'UTC'));
    app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'starter', 'interval' => 'monthly', 'apply_at' => 'end_of_period',
    ]);

    // Card: the renewal is paid on the charge date, before any dispatcher has run.
    $this->travelTo(Carbon::parse('2026-02-01 09:01:00', 'UTC'));
    followsChangeDeliver($billable, 'tr_follow_card', '2026-02-01T09:00:30Z', '10.71', followsChangeRenewalFor('starter'));

    $billable->refresh();
    $invoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_follow_card')->firstOrFail();
    $kinds = array_column((array) $invoice->line_items, 'kind');

    // Invoiced as what it is — a Starter renewal — not as Pro booked down by an
    // adjustment, with the change then applied mid-period on top.
    expect($billable->getBillingSubscriptionPlanCode())->toBe('starter')
        ->and($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull()
        ->and($billable->scheduled_change_at)->toBeNull()
        ->and($billable->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null)->toBeNull()
        ->and((int) $invoice->amount_net)->toBe(900)
        ->and($kinds)->not->toContain('adjustment')
        ->and($billable->subscription_period_starts_at->toIso8601String())->toBe('2026-02-01T09:00:30+00:00')
        ->and(followsChangeNetCollected($billable))->toBe(900);
});

it('does not charge a scheduled upgrade its difference twice', function (): void {
    $billable = followsChangeBillable('starter');

    $this->travelTo(Carbon::parse('2026-01-20 12:00:00', 'UTC'));
    app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'pro', 'interval' => 'monthly', 'apply_at' => 'end_of_period',
    ]);

    $this->travelTo(Carbon::parse('2026-02-01 09:01:00', 'UTC'));
    followsChangeDeliver($billable, 'tr_follow_up', '2026-02-01T09:00:30Z', '34.51', followsChangeRenewalFor('pro'));

    $billable->refresh();

    // One payment, for Pro, and no prorata charge started on top of it.
    expect($billable->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and($billable->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null)->toBeNull()
        ->and(followsChangeNetCollected($billable))->toBe(2900);
});

it('takes the announcement back when the scheduled change is withdrawn', function (): void {
    $billable = followsChangeBillable();

    $this->travelTo(Carbon::parse('2026-01-20 12:00:00', 'UTC'));
    app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'starter', 'interval' => 'monthly', 'apply_at' => 'end_of_period',
    ]);

    app(UpdateSubscription::class)->cancelScheduledChange($billable->refresh());

    expect(SpyMollieSubscriptionPatcher::$calls)->toHaveCount(2)
        ->and(SpyMollieSubscriptionPatcher::$calls[1][3]['plan_code'])->toBe('pro')
        ->and($billable->refresh()->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull();
});

it('gives back what a renewal collected at the old price when it was created before the change was known', function (): void {
    $billable = followsChangeBillable();

    // 31 Jan: Mollie has already created the February debit at Pro. The customer books
    // the downgrade now — the PATCH comes too late for that payment.
    $this->travelTo(Carbon::parse('2026-01-31 12:00:00', 'UTC'));
    app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'starter', 'interval' => 'monthly', 'apply_at' => 'end_of_period',
    ]);

    $this->travelTo(Carbon::parse('2026-02-01 10:00:00', 'UTC'));
    app(ScheduleSubscriptionChange::class)->apply($billable->refresh());

    // 3 Feb: it settles at 29.00 + 19 %, and says itself that it was created for Pro.
    $this->travelTo(Carbon::parse('2026-02-03 08:00:00', 'UTC'));
    followsChangeMollieReturns('re_follow_surplus');
    followsChangeDeliver($billable, 'tr_follow_old', '2026-02-03T07:55:00Z', '34.51', followsChangeRenewalFor('pro'));

    $billable->refresh();

    // Starter for all of February: 9.00 net, the other 20.00 credited back. And it IS the
    // renewal of the new period, so the period moves.
    expect($billable->getBillingSubscriptionPlanCode())->toBe('starter')
        ->and(followsChangeNetCollected($billable))->toBe(900)
        ->and($billable->subscription_period_starts_at->toIso8601String())->toBe('2026-02-03T07:55:00+00:00');
});

it('refunds a renewal in full when a scheduled downgrade to the free plan applies with it', function (): void {
    $billable = followsChangeBillable();

    $this->travelTo(Carbon::parse('2026-01-20 12:00:00', 'UTC'));
    app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'free', 'interval' => 'monthly', 'apply_at' => 'end_of_period',
    ]);

    // A free target has no amount to announce — Mollie is left alone until the boundary.
    expect(SpyMollieSubscriptionPatcher::$calls)->toBe([]);

    $this->travelTo(Carbon::parse('2026-02-01 09:01:00', 'UTC'));
    followsChangeMollieReturns('re_follow_free');
    followsChangeDeliver($billable, 'tr_follow_free', '2026-02-01T09:00:30Z', '34.51', followsChangeRenewalFor('pro'));

    $billable->refresh();

    expect($billable->getBillingSubscriptionPlanCode())->toBe('free')
        ->and($billable->getBillingSubscriptionSource())->toBe(SubscriptionSource::Local->value)
        ->and(array_column(SpyMollieSubscriptionPatcher::$calls, 0))->toContain('cancel')
        ->and(followsChangeNetCollected($billable))->toBe(0);
});

it('refunds a renewal of the old interval that settles after the switch to the new one', function (): void {
    $billable = followsChangeBillable();

    // 1 Feb, the renewal day: the customer switches to yearly while the monthly debit
    // Mollie created for February is still on its way. (A change SCHEDULED for the period
    // end waits for that renewal instead — see ChangeMatrixKnownLimitsTest.) The yearly
    // price is charged as its own payment.
    $this->travelTo(Carbon::parse('2026-02-01 10:00:00', 'UTC'));
    followsChangeMollieReturns('tr_follow_year');
    app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'pro', 'interval' => 'yearly', 'apply_at' => 'immediate',
    ]);

    // 2 Feb: the yearly charge clears — a year from here is paid for.
    $this->travelTo(Carbon::parse('2026-02-02 08:00:00', 'UTC'));
    followsChangeDeliver($billable, 'tr_follow_year', '2026-02-02T07:55:00Z', '345.10', ['type' => 'prorata_charge']);

    $billable->refresh();
    expect($billable->getBillingSubscriptionInterval())->toBe('yearly');
    $anchor = $billable->subscription_period_starts_at->toIso8601String();

    // 3 Feb: the monthly debit Mollie had created on the old cadence settles too.
    $this->travelTo(Carbon::parse('2026-02-03 08:00:00', 'UTC'));
    followsChangeDeliver($billable, 'tr_follow_month', '2026-02-03T07:55:00Z', '34.51', followsChangeRenewalFor('pro'));

    $billable->refresh();

    // The year is all the customer owes, and the stray payment opens no period.
    expect(followsChangeNetCollected($billable))->toBe(29000)
        ->and($billable->subscription_period_starts_at->toIso8601String())->toBe($anchor);
});

it('credits a renewal of the old interval that was booked while the new interval\'s charge was in flight', function (): void {
    $billable = followsChangeBillable();

    $this->travelTo(Carbon::parse('2026-02-01 10:00:00', 'UTC'));
    followsChangeMollieReturns('tr_follow_year2');
    app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'pro', 'interval' => 'yearly', 'apply_at' => 'immediate',
    ]);

    // 3 Feb: the monthly renewal settles FIRST. Locally this is still a monthly
    // subscription, so it is booked as one: period 3 Feb → 3 Mar.
    $this->travelTo(Carbon::parse('2026-02-03 08:00:00', 'UTC'));
    followsChangeDeliver($billable, 'tr_follow_month2', '2026-02-03T07:55:00Z', '34.51', followsChangeRenewalFor('pro'));

    // 4 Feb: the yearly charge clears. 27 of that month's 28 days are unused.
    $this->travelTo(Carbon::parse('2026-02-04 08:00:00', 'UTC'));
    followsChangeDeliver($billable, 'tr_follow_year2', '2026-02-04T07:55:00Z', '345.10', ['type' => 'prorata_charge']);

    $billable->refresh();
    $credited = (int) abs((int) BillingInvoice::query()
        ->where('billable_id', $billable->getKey())
        ->where('invoice_kind', InvoiceKind::Refund)
        ->sum('amount_net'));

    // 2900 × 27/28 = 2796.
    expect($billable->getBillingSubscriptionInterval())->toBe('yearly')
        ->and($credited)->toBe(2796);
});

it('moves the Mollie amount together with the seat count, not when the prorata charge clears', function (): void {
    $billable = followsChangeBillable();

    // 30 Jan: one extra seat, 2 of 31 days left → 500 × 2/31 = 32 net, as a SEPA debit.
    $this->travelTo(Carbon::parse('2026-01-30 12:00:00', 'UTC'));
    followsChangeMollieReturns('tr_follow_seat');

    app(UpdateSubscription::class)->update($billable, ['seats' => 2]);

    $billable->refresh();

    // Both halves have moved: the seat is active, and Mollie bills for it from the next
    // payment it creates — while the prorata charge is still travelling.
    expect($billable->getBillingSeatCount())->toBe(2)
        ->and($billable->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'] ?? null)->toBe('tr_follow_seat')
        ->and(SpyMollieSubscriptionPatcher::$calls)->toHaveCount(1)
        ->and(SpyMollieSubscriptionPatcher::$calls[0][3]['seats'])->toBe(2);

    // 1 Feb: the renewal, created from the patched subscription: 34.00 + 19 %.
    $this->travelTo(Carbon::parse('2026-02-01 09:05:00', 'UTC'));
    followsChangeDeliver($billable, 'tr_follow_feb_seat', '2026-02-01T09:04:00Z', '40.46', followsChangeRenewalFor('pro', 'monthly', 1));

    $renewal = BillingInvoice::query()->where('mollie_payment_id', 'tr_follow_feb_seat')->firstOrFail();

    expect((int) $renewal->amount_net)->toBe(3400)
        ->and(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment');
});

it('puts the Mollie amount back with the seat count when the prorata charge is declined', function (): void {
    $billable = followsChangeBillable();

    $this->travelTo(Carbon::parse('2026-01-20 12:00:00', 'UTC'));
    followsChangeMollieReturns('tr_follow_declined');

    app(UpdateSubscription::class)->update($billable, ['seats' => 3]);
    expect($billable->refresh()->getBillingSeatCount())->toBe(3);

    RenewalFollowsChangeWebhookController::$nextPayment = (object) [
        'id' => 'tr_follow_declined',
        'status' => 'failed',
        'amount' => (object) ['value' => '4.61', 'currency' => 'EUR'],
        'details' => (object) ['failureReason' => 'insufficient_funds'],
        'metadata' => [
            'type' => 'prorata_charge',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];
    $this->postJson(route('billing.webhook'), ['id' => 'tr_follow_declined'])->assertStatus(200);

    $billable->refresh();
    $last = SpyMollieSubscriptionPatcher::$calls[array_key_last(SpyMollieSubscriptionPatcher::$calls)];

    expect($billable->getBillingSeatCount())->toBe(1)
        ->and(SpyMollieSubscriptionPatcher::$calls)->toHaveCount(2)
        ->and($last[3]['seats'])->toBe(1);
});
