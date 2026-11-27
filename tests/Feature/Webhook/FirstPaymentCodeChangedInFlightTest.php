<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Events\CouponRedemptionRecordMissing;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/*
 * A code the checkout accepted is changed while the first payment travels: its campaign
 * fills up, an operator switches it off, its end date passes. The customer has paid the
 * discounted amount by then. The webhook used to validate the code a second time, get
 * "globally exhausted" / "inactive" / "expired", and activate as if no code had been
 * entered: the invoice booked the full plan plus a payment adjustment, PaymentAmountMismatch
 * fired, and the Mollie subscription charged the full price from the second period on.
 *
 * decisions.md: "a payment made with a code is honoured even if the code was switched off
 * while the payment travelled; validate() is the gate" — the gate is the checkout.
 *
 * Pro 50.00 net, DE 19 %. LAUNCH20: 20 % for three periods → 40.00 net, 47.60 gross.
 */

class InFlightCodeWebhookController extends MollieWebhookController
{
    public static ?object $nextPayment = null;

    protected function fetchPayment(string $paymentId): object
    {
        return self::$nextPayment ?? throw new RuntimeException('No payment stub configured.');
    }
}

final class InFlightCodeMollie
{
    /** @var list<object> */
    public static array $sent = [];

    public static function createdAmount(): ?string
    {
        foreach (self::$sent as $request) {
            if ($request instanceof CreateSubscriptionRequest) {
                $p = (new ReflectionClass($request))->getProperty('amount');
                $p->setAccessible(true);

                return $p->getValue($request)->value;
            }
        }

        return null;
    }
}

beforeEach(function (): void {
    InFlightCodeWebhookController::$nextPayment = null;
    InFlightCodeMollie::$sent = [];
    $this->app->bind(MollieWebhookController::class, InFlightCodeWebhookController::class);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro', 'tier' => 2, 'included_seats' => 1, 'feature_keys' => [], 'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 5000, 'seat_price_net' => null, 'included_usages' => []]],
    ]);

    Mollie::shouldReceive('send')->andReturnUsing(function ($request) {
        InFlightCodeMollie::$sent[] = $request;

        return (object) ['id' => $request instanceof CreateSubscriptionRequest ? 'sub_in_flight' : 'unexpected'];
    });
});

/** Checkout accepted LAUNCH20 and charged 47.60; the webhook arrives after `$change`. */
function deliverFirstPaymentAfter(Closure $change): array
{
    $coupon = app(CouponService::class)->create([
        'code' => 'LAUNCH20',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 20,
        'discount_periods' => 3,
        'max_redemptions' => 100,
    ]);

    $billable = new TestBillable;
    $billable->forceFill([
        'email' => uniqid('flight-').'@x.test', 'name' => 'Flight', 'billing_country' => 'DE', 'mollie_customer_id' => 'cst_flight',
    ])->save();

    $change($coupon);

    InFlightCodeWebhookController::$nextPayment = (object) [
        'id' => 'tr_in_flight',
        'status' => 'paid',
        'amount' => (object) ['value' => '47.60', 'currency' => 'EUR'],
        'subscriptionId' => null,
        'customerId' => 'cst_flight',
        'mandateId' => 'mdt_flight',
        'countryCode' => 'DE',
        'metadata' => [
            'type' => 'subscription',
            'plan_code' => 'pro',
            'interval' => 'monthly',
            'coupon_code' => 'LAUNCH20',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];

    test()->postJson(route('billing.webhook'), ['id' => 'tr_in_flight'])->assertStatus(200);

    $invoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_in_flight')->firstOrFail();
    $kinds = array_column((array) $invoice->line_items, 'kind');

    return [$billable->fresh(), $invoice, $kinds];
}

it('honours a code whose campaign filled up while the first payment travelled', function (): void {
    Event::fake([PaymentAmountMismatch::class, CouponRedemptionRecordMissing::class]);

    // Customer X took the last slot between this customer's checkout and webhook.
    [$billable, $invoice, $kinds] = deliverFirstPaymentAfter(
        fn (Coupon $c) => Coupon::query()->whereKey($c->id)->update(['redemptions_count' => 100]),
    );

    expect($kinds)->toContain('coupon')
        ->and($kinds)->not->toContain('adjustment')
        ->and((int) $invoice->amount_net)->toBe(4000);
    Event::assertNotDispatched(PaymentAmountMismatch::class);

    // Renewals 2 and 3 keep the discount: Mollie holds 47.60, the marker says why.
    expect(InFlightCodeMollie::createdAmount())->toBe('47.60')
        ->and($billable->getBillingSubscriptionMeta()['active_recurring_coupon']['code'] ?? null)->toBe('LAUNCH20');

    // The row cannot be written behind the full campaign — that is the documented
    // compensation, audited rather than silent.
    Event::assertDispatched(CouponRedemptionRecordMissing::class);
});

it('honours a code switched off while the first payment travelled, and ends the discount there', function (): void {
    Event::fake([PaymentAmountMismatch::class]);

    [$billable, $invoice, $kinds] = deliverFirstPaymentAfter(
        fn (Coupon $c) => Coupon::query()->whereKey($c->id)->update(['active' => false]),
    );

    // The payment that was made with it is booked as such…
    expect($kinds)->toContain('coupon')
        ->and($kinds)->not->toContain('adjustment')
        ->and(CouponRedemption::query()->where('billable_id', $billable->getKey())->count())->toBe(1);
    Event::assertNotDispatched(PaymentAmountMismatch::class);

    // …and switching a code off is the documented way to end its discount at the next
    // renewal: Mollie is not left holding one the renewal would not honour.
    expect(InFlightCodeMollie::createdAmount())->toBe('59.50');
});

it('honours a code whose end date passed while the first payment travelled', function (): void {
    Event::fake([PaymentAmountMismatch::class]);

    [$billable, $invoice, $kinds] = deliverFirstPaymentAfter(
        fn (Coupon $c) => Coupon::query()->whereKey($c->id)->update(['valid_until' => BillingTime::nowUtc()->subMinute()]),
    );

    expect($kinds)->toContain('coupon')
        ->and($kinds)->not->toContain('adjustment')
        ->and(CouponRedemption::query()->where('billable_id', $billable->getKey())->count())->toBe(1);
    Event::assertNotDispatched(PaymentAmountMismatch::class);

    // The code's own end date caps the recurring part, as it does at the checkout.
    expect(InFlightCodeMollie::createdAmount())->toBe('59.50');
});

/** The first renewal of `sub_in_flight`: Mollie collected `$gross`, the charge created at `$createdAt`. */
function deliverInFlightRenewal(TestBillable $billable, string $gross, Carbon\CarbonInterface $createdAt): BillingInvoice
{
    InFlightCodeWebhookController::$nextPayment = (object) [
        'id' => 'tr_in_flight_renewal',
        'status' => 'paid',
        'amount' => (object) ['value' => $gross, 'currency' => 'EUR'],
        'createdAt' => $createdAt->toIso8601String(),
        'paidAt' => $createdAt->toIso8601String(),
        'subscriptionId' => 'sub_in_flight',
        'customerId' => 'cst_flight',
        'mandateId' => 'mdt_flight',
        'countryCode' => 'DE',
        'metadata' => [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];

    test()->postJson(route('billing.webhook'), ['id' => 'tr_in_flight_renewal'])->assertStatus(200);

    return BillingInvoice::query()->where('mollie_payment_id', 'tr_in_flight_renewal')->firstOrFail();
}

/*
 * decisions.md, 26 November 2026 (maintainer decision 6): the payment made with a code that
 * was switched off in flight is honoured — redemption counted, Mollie created at the full
 * price — but no `active_recurring_coupon` marker is written for it. The marker used to be
 * written all the same and was only kept quiet by `isMarkerStillRedeemable()` reading the
 * coupon's live `active` flag: switched back on, the code revived a discount Mollie was
 * never told about, and every renewal booked a coupon line, took it back off with an
 * adjustment and fired PaymentAmountMismatch.
 */
it('writes no recurring marker for a code switched off in flight, so switching it back on discounts nothing', function (): void {
    Event::fake([PaymentAmountMismatch::class]);
    $this->travelTo(Carbon\Carbon::parse('2026-01-10 15:00:00', 'UTC'));

    [$billable] = deliverFirstPaymentAfter(
        fn (Coupon $c) => Coupon::query()->whereKey($c->id)->update(['active' => false]),
    );

    expect(InFlightCodeMollie::createdAmount())->toBe('59.50')
        ->and(CouponRedemption::query()->where('billable_id', $billable->getKey())->count())->toBe(1)
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('active_recurring_coupon');

    // The operator switches the campaign back on for new customers.
    Coupon::query()->where('code', 'LAUNCH20')->update(['active' => true]);

    $this->travelTo(Carbon\Carbon::parse('2026-02-10 15:00:00', 'UTC'));
    $invoice = deliverInFlightRenewal($billable->fresh(), '59.50', Carbon\Carbon::parse('2026-02-10 06:00:00', 'UTC'));
    $kinds = array_column((array) $invoice->line_items, 'kind');

    expect((int) $invoice->amount_net)->toBe(5000)
        ->and($kinds)->not->toContain('coupon')
        ->and($kinds)->not->toContain('adjustment')
        ->and(CouponRedemption::query()->where('billable_id', $billable->getKey())->count())->toBe(1);
    Event::assertNotDispatched(PaymentAmountMismatch::class);
});

/*
 * The same rule for the code's own end date, which price() measures against the next
 * charge at the current hour while the renewal asks at the start of the charge's day
 * (SubscriptionPaymentHandler::paid(), `$chargeDay`). A code ending at noon on the day of
 * the first renewal priced Mollie at the full amount, but its marker — capped at that same
 * noon — was still valid at midnight, so the renewal expected the discount Mollie had not
 * collected. No marker where the priced recurring discount is 0 for the code's own reasons.
 */
it('writes no recurring marker for a code whose end date falls before the first renewal', function (): void {
    Event::fake([PaymentAmountMismatch::class]);
    $this->travelTo(Carbon\Carbon::parse('2026-01-10 15:00:00', 'UTC'));

    [$billable] = deliverFirstPaymentAfter(
        fn (Coupon $c) => Coupon::query()->whereKey($c->id)->update(['valid_until' => Carbon\Carbon::parse('2026-02-10 12:00:00', 'UTC')]),
    );

    expect(InFlightCodeMollie::createdAmount())->toBe('59.50')
        ->and(CouponRedemption::query()->where('billable_id', $billable->getKey())->count())->toBe(1)
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('active_recurring_coupon');

    $this->travelTo(Carbon\Carbon::parse('2026-02-10 08:00:00', 'UTC'));
    $invoice = deliverInFlightRenewal($billable->fresh(), '59.50', Carbon\Carbon::parse('2026-02-10 06:00:00', 'UTC'));
    $kinds = array_column((array) $invoice->line_items, 'kind');

    expect((int) $invoice->amount_net)->toBe(5000)
        ->and($kinds)->not->toContain('coupon')
        ->and($kinds)->not->toContain('adjustment');
    Event::assertNotDispatched(PaymentAmountMismatch::class);
});
