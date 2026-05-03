<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\CreateSubscription;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\PlanChangeIntent;
use GraystackIT\MollieBilling\Support\ProrataLine;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Http\Data\Date;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest as MollieUpdateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.business', [
        'name' => 'Business',
        'tier' => 2,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1900, 'seat_price_net' => null, 'included_usages' => []],
            'yearly' => ['base_price_net' => 19000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
    config()->set('mollie-billing-plans.plans.enterprise', [
        'name' => 'Enterprise',
        'tier' => 3,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 39000, 'seat_price_net' => null, 'included_usages' => []],
            'yearly' => ['base_price_net' => 39000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
});

function readPrivateProp(?object $obj, string $prop): mixed
{
    if ($obj === null) {
        throw new \InvalidArgumentException('readPrivateProp got null object — the captured Mollie request was never set.');
    }
    $reflection = new ReflectionClass($obj);
    return $reflection->getProperty($prop)->getValue($obj);
}

function bugFixBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Acme',
        'email' => 'acme@example.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_test',
        'mollie_mandate_id' => 'mdt_test',
    ]);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'business',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now(),
        'subscription_meta' => ['seat_count' => 1, 'mollie_subscription_id' => 'sub_test'],
        'active_addon_codes' => [],
    ])->save();
    return $billable->refresh();
}

it('Bug A: CreateSubscription sends startDate to push first recurring charge one period out', function (): void {
    $billable = TestBillable::create([
        'name' => 'Acme',
        'email' => 'acme@example.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_test',
        'mollie_mandate_id' => 'mdt_test',
    ]);

    $captured = null;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured) {
        if ($request instanceof CreateSubscriptionRequest) {
            $captured = $request;
            $sub = new \stdClass;
            $sub->id = 'sub_test_'.uniqid();
            return $sub;
        }
        throw new \LogicException('Unexpected Mollie call: '.get_class($request));
    });

    app(CreateSubscription::class)->handle($billable, [
        'plan_code' => 'business',
        'interval' => 'monthly',
        'amount_gross' => 1900,
    ]);

    expect($captured)->not->toBeNull();
    $startDate = readPrivateProp($captured, 'startDate');

    expect($startDate)->toBeInstanceOf(Date::class);
    expect((string) $startDate)->toBe(now()->addMonth()->format('Y-m-d'));
});

it('Bug A: yearly interval sets startDate to +1 year', function (): void {
    $billable = TestBillable::create([
        'name' => 'Acme',
        'email' => 'acme@example.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_test',
        'mollie_mandate_id' => 'mdt_test',
    ]);

    $captured = null;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured) {
        if ($request instanceof CreateSubscriptionRequest) {
            $captured = $request;
            $sub = new \stdClass;
            $sub->id = 'sub_test_'.uniqid();
            return $sub;
        }
        throw new \LogicException('Unexpected Mollie call: '.get_class($request));
    });

    app(CreateSubscription::class)->handle($billable, [
        'plan_code' => 'business',
        'interval' => 'yearly',
        'amount_gross' => 19000,
    ]);

    expect($captured)->not->toBeNull();
    $startDate = readPrivateProp($captured, 'startDate');

    expect((string) $startDate)->toBe(now()->addYear()->format('Y-m-d'));
});

it('Bug C: interval change patches Mollie with new interval AND new startDate', function (): void {
    $billable = bugFixBillable();

    $captured = null;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured) {
        if ($request instanceof MollieUpdateSubscriptionRequest) {
            $captured = $request;
            $sub = new \stdClass;
            $sub->id = 'sub_test';
            return $sub;
        }
        throw new \LogicException('Unexpected Mollie call: '.get_class($request));
    });

    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'business', newPlan: 'enterprise',
        currentInterval: 'monthly', newInterval: 'yearly',
        currentSeats: 1, newSeats: 1,
        currentAddons: [], newAddons: [],
    );

    app(MollieSubscriptionPatcher::class)->updateForIntent($billable, $intent);

    expect($captured)->not->toBeNull();
    expect(readPrivateProp($captured, 'interval'))->toBe('12 months');

    $startDate = readPrivateProp($captured, 'startDate');
    expect($startDate)->toBeInstanceOf(Date::class);
    expect((string) $startDate)->toBe(now()->addYear()->format('Y-m-d'));
});

it('Bug C: same-interval seat change patches Mollie with interval but no startDate reset', function (): void {
    $billable = bugFixBillable();

    $captured = null;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured) {
        if ($request instanceof MollieUpdateSubscriptionRequest) {
            $captured = $request;
            $sub = new \stdClass;
            $sub->id = 'sub_test';
            return $sub;
        }
        throw new \LogicException('Unexpected Mollie call: '.get_class($request));
    });

    $intent = new PlanChangeIntent(
        billable: $billable,
        currentPlan: 'business', newPlan: 'business',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 1, newSeats: 3,
        currentAddons: [], newAddons: [],
    );

    app(MollieSubscriptionPatcher::class)->updateForIntent($billable, $intent);

    expect($captured)->not->toBeNull();
    expect(readPrivateProp($captured, 'startDate'))->toBeNull();
});

it('Bug B: createRefund clamps the Mollie refund amount to original amount_gross', function (): void {
    $billable = bugFixBillable();

    // Original Mollie payment of 1900 cents (= 19 EUR), but local invoice
    // misrecorded VAT and ended up with amount_gross = 2299. The refund
    // attempt should be clamped down to 1900 — what Mollie actually charged.
    $original = BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_drift_'.uniqid(),
        'mollie_subscription_id' => 'sub_test',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 1900,
        'amount_vat' => 399,
        'amount_gross' => 1900, // Mollie only saw 1900 EUR
        'line_items' => [[
            'kind' => 'plan',
            'code' => 'business',
            'quantity' => 1,
            'amount_net' => 1900,
            'vat_rate' => 21.0,
            'vat_amount' => 399,
            'amount_gross' => 2299,
            'period_start' => now()->subDays(5)->toIso8601String(),
            'period_end' => now()->addDays(25)->toIso8601String(),
        ]],
        'refunded_net' => 0,
    ]);

    $refundLine = new ProrataLine(
        originalInvoice: $original,
        originalLineItemIndex: 0,
        kind: 'plan',
        code: 'business',
        label: 'Business — refund',
        quantity: 1,
        amountNet: -1900,
        vatRate: 21.0,
        amountVat: -399,
        amountGross: -2299, // local gross > Mollie's 1900
        periodStart: now()->subDays(5),
        periodEnd: now()->addDays(25),
        daysActive: 30,
        daysRemaining: 25,
        isCouponCovered: false,
        direction: 'refund',
    );

    $sentRefundAmount = null;
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$sentRefundAmount) {
        if ($request instanceof CreatePaymentRefundRequest) {
            $sentRefundAmount = readPrivateProp($request, 'amount');
            $r = new \stdClass;
            $r->id = 're_'.uniqid();
            return $r;
        }
        throw new \LogicException('Unexpected Mollie call: '.get_class($request));
    });

    app(InvoiceService::class)->createRefund($billable, [$refundLine]);

    expect($sentRefundAmount)->not->toBeNull();
    // Money value is "19.00" — Mollie's actual charge — not "22.99".
    expect(readPrivateProp($sentRefundAmount, 'value'))->toBe('19.00');
});

it('Bug regression: currentVatValidation() is null when billable has vat_number but no audit entry yet', function (): void {
    // This was the actual production failure: Livewire createBillable() saved
    // vat_number on the model, but the surrounding submit() flow only ran
    // validateAndPersist() if the *prior* vat_number value differed — which it
    // didn't, since createBillable() had just set it. Result: a billable with
    // a VAT number but no audit entry, and the webhook later charged country VAT.
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Hahn and Small LLC',
        'email' => 'h@example.test',
        'billing_country' => 'AT',
        'vat_number' => 'ATU77903226',
    ]);

    // No vatValidations entry was created.
    expect($billable->currentVatValidation())->toBeNull();
    expect($billable->vatValidations()->count())->toBe(0);

    // Simulate the fixed save flow: detect missing audit entry and persist one.
    // The fix lives in the Livewire components, but we exercise the contract here:
    // `currentVatValidation() === null` is the trigger to call validateAndPersist().
    $billable->vatValidations()->create([
        'vat_number' => 'ATU77903226',
        'country_code' => 'AT',
        'valid' => true,
        'vies_response' => ['valid' => true],
        'checked_at' => now(),
    ]);

    expect($billable->currentVatValidation())->not->toBeNull();
    expect($billable->currentVatValidation()->valid)->toBeTrue();
});

it('Bug regression: webhook-time VAT calculation respects persisted reverse-charge', function (): void {
    $billable = bugFixBillable();
    $billable->forceFill(['vat_number' => 'ATU77903226'])->save();
    $billable->refresh();

    // Persist a valid VIES audit entry, mimicking what the fixed checkout flow
    // does after createBillable.
    $billable->vatValidations()->create([
        'vat_number' => 'ATU77903226',
        'country_code' => 'AT',
        'valid' => true,
        'vies_response' => ['valid' => true],
        'checked_at' => now(),
    ]);

    // Now simulate the webhook persisting an invoice for a 1900-cent payment.
    $payment = new \stdClass;
    $payment->id = 'tr_'.uniqid();
    $payment->subscriptionId = null;

    $invoice = app(InvoiceService::class)->createForPayment(
        payment: $payment,
        invoiceKind: 'subscription',
        lineItems: [[
            'kind' => 'plan',
            'code' => 'business',
            'label' => 'Business',
            'quantity' => 1,
            'unit_price' => 1900,
            'unit_price_net' => 1900,
            'total_net' => 1900,
        ]],
        billable: $billable,
    );

    // Reverse-charge applies → no VAT, gross = net.
    expect($invoice->amount_net)->toBe(1900);
    expect($invoice->amount_vat)->toBe(0);
    expect($invoice->amount_gross)->toBe(1900);
    // And the audit anchor is set.
    expect($invoice->vat_validation_id)->not->toBeNull();
});

it('Bug regression: a second refund with the same (parent, idx, amount) signature is not silently skipped', function (): void {
    // Scenario: user reduces seat count, then later increases it again, then
    // reduces again. The two reductions both produce a refund line with the
    // same (parent_invoice_id, line_index, amount_net) signature against the
    // ORIGINAL seat invoice. The old idempotency check would silently skip
    // the second refund — leaving the user owed money. After the fix, the
    // refund must go through; currentPeriodLines's remaining_quantity filter
    // is the authoritative gate.
    $billable = bugFixBillable();

    // Original seat invoice with 2 seats @ 9000 each.
    $originalSeats = BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_seats_'.uniqid(),
        'mollie_subscription_id' => 'sub_test',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 18000,
        'amount_vat' => 0,
        'amount_gross' => 18000,
        'line_items' => [[
            'kind' => 'seats',
            'code' => null,
            'quantity' => 2,
            'unit_price_net' => 9000,
            'amount_net' => 18000,
            'vat_rate' => 0.0,
            'vat_amount' => 0,
            'amount_gross' => 18000,
            'period_start' => now()->subDays(1)->toIso8601String(),
            'period_end' => now()->addYear()->toIso8601String(),
        ]],
        'refunded_net' => 0,
    ]);

    // Pre-existing refund: the FIRST reduction. -1 seat = -9000.
    BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => null,
        'mollie_subscription_id' => null,
        'invoice_kind' => InvoiceKind::Refund,
        'status' => InvoiceStatus::Refunded,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => -9000,
        'amount_vat' => 0,
        'amount_gross' => -9000,
        'line_items' => [[
            'kind' => 'seats',
            'code' => null,
            'quantity' => 1,
            'unit_price_net' => -9000,
            'amount_net' => -9000,
            'vat_rate' => 0.0,
            'vat_amount' => 0,
            'amount_gross' => -9000,
            'parent_invoice_id' => $originalSeats->getKey(),
            'parent_line_item_index' => 0,
            'mollie_refund_id' => 're_first_reduction',
        ]],
        'refunded_net' => 0,
    ]);

    // Now the SECOND reduction: another -1 seat against the same original seat
    // invoice. Build the prorata refund line with identical signature.
    $secondRefundLine = new ProrataLine(
        originalInvoice: $originalSeats,
        originalLineItemIndex: 0,
        kind: 'seats',
        code: null,
        label: '1 seat refund',
        quantity: 1,
        amountNet: -9000,
        vatRate: 0.0,
        amountVat: 0,
        amountGross: -9000,
        periodStart: now()->subDays(1),
        periodEnd: now()->addYear(),
        daysActive: 365,
        daysRemaining: 365,
        isCouponCovered: false,
        direction: 'refund',
    );

    $mollieRefundCalled = false;
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$mollieRefundCalled) {
        if ($request instanceof CreatePaymentRefundRequest) {
            $mollieRefundCalled = true;
            $r = new \stdClass;
            $r->id = 're_second_'.uniqid();
            return $r;
        }
        throw new \LogicException('Unexpected Mollie call: '.get_class($request));
    });

    $invoice = app(InvoiceService::class)->createRefund($billable, [$secondRefundLine]);

    expect($mollieRefundCalled)->toBeTrue();
    expect($invoice)->not->toBeNull();
    expect($invoice->amount_net)->toBe(-9000);
});

it('Bug regression: currentPeriodLines prefers the newest invoice when periods are identical', function (): void {
    // Mid-cycle seat purchases create multiple seat invoices with the SAME
    // period_start (the subscription's period start). Without a stable
    // tiebreaker, the sort would non-deterministically pick any of them and
    // refund could target an already-fully-refunded Mollie payment, which
    // Mollie rejects with 409 "duplicate refund".
    //
    // The fix: when period_start ties, prefer the higher invoice ID (= newer).
    $billable = bugFixBillable();

    // Match the billable's monthly period (bugFixBillable sets it).
    $periodStart = $billable->getBillingPeriodStartsAt();
    $periodEnd = $billable->nextBillingDate();

    // Three seat invoices, identical periods, increasing IDs.
    foreach (['oldest', 'middle', 'newest'] as $label) {
        BillingInvoice::create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'mollie_payment_id' => 'tr_seats_'.$label,
            'mollie_subscription_id' => 'sub_test',
            'invoice_kind' => InvoiceKind::Subscription,
            'status' => InvoiceStatus::Paid,
            'country' => 'AT',
            'currency' => 'EUR',
            'amount_net' => 9000,
            'amount_vat' => 0,
            'amount_gross' => 9000,
            'line_items' => [[
                'kind' => 'seats',
                'code' => null,
                'quantity' => 1,
                'unit_price_net' => 9000,
                'amount_net' => 9000,
                'vat_rate' => 0.0,
                'vat_amount' => 0,
                'amount_gross' => 9000,
                'period_start' => $periodStart->toIso8601String(),
                'period_end' => $periodEnd->toIso8601String(),
            ]],
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'refunded_net' => 0,
        ]);
    }

    $candidates = BillingInvoice::currentPeriodLines($billable, 'seats', null);

    expect($candidates)->toHaveCount(3);
    // Newest invoice (highest ID) must come first.
    expect($candidates[0]['invoice']->mollie_payment_id)->toBe('tr_seats_newest');
    expect($candidates[1]['invoice']->mollie_payment_id)->toBe('tr_seats_middle');
    expect($candidates[2]['invoice']->mollie_payment_id)->toBe('tr_seats_oldest');
});

it('Bug 3g: createForPayment links the current vat_validation_id', function (): void {
    $billable = bugFixBillable();
    $billable->forceFill(['vat_number' => 'ATU12345678'])->save();
    $billable->refresh();

    $validation = $billable->vatValidations()->create([
        'vat_number' => 'ATU12345678',
        'country_code' => 'AT',
        'valid' => true,
        'vies_response' => ['valid' => true],
        'checked_at' => now(),
    ]);

    $payment = new \stdClass;
    $payment->id = 'tr_'.uniqid();
    $payment->subscriptionId = null;

    $invoice = app(InvoiceService::class)->createForPayment(
        payment: $payment,
        invoiceKind: 'subscription',
        lineItems: [[
            'kind' => 'plan',
            'code' => 'business',
            'label' => 'Business',
            'quantity' => 1,
            'unit_price' => 1900,
            'unit_price_net' => 1900,
            'total_net' => 1900,
        ]],
        billable: $billable,
    );

    expect($invoice->vat_validation_id)->toBe($validation->id);
});
