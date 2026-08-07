<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
use GraystackIT\MollieBilling\Events\SubscriptionCreated;
use GraystackIT\MollieBilling\Events\TrialConverted;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\TrialConvertedNotification;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * The invoice a webhook writes must add up to the money Mollie collected: the
 * coupon discount the checkout charged has to be a line item, and anything the
 * handler cannot reconstruct has to surface as a mismatch instead of silently
 * overstating revenue and VAT.
 */
class InvoiceAmountFakeWebhookController extends MollieWebhookController
{
    public static ?object $nextPayment = null;

    protected function fetchPayment(string $paymentId): object
    {
        if (self::$nextPayment === null) {
            throw new \RuntimeException('No payment stub configured.');
        }

        return self::$nextPayment;
    }
}

beforeEach(function (): void {
    InvoiceAmountFakeWebhookController::$nextPayment = null;
    $this->app->bind(MollieWebhookController::class, InvoiceAmountFakeWebhookController::class);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);

    Mollie::shouldReceive('send')->andReturnUsing(function ($request) {
        if ($request instanceof CreateSubscriptionRequest) {
            return (object) ['id' => 'sub_created'];
        }

        return (object) ['id' => 'unexpected'];
    });
});

function invoiceAmountBillable(): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => 'amounts@x.test',
        'name' => 'Amounts',
        'billing_country' => 'DE',
        'mollie_customer_id' => 'cst_am',
    ])->save();

    return $b;
}

/** @return array<string, mixed> */
function lineByKind(BillingInvoice $invoice, string $kind): ?array
{
    foreach ((array) $invoice->line_items as $line) {
        if (($line['kind'] ?? null) === $kind) {
            return $line;
        }
    }

    return null;
}

it('books the checkout coupon discount on the first-payment invoice', function (): void {
    Event::fake([PaymentAmountMismatch::class]);

    app(CouponService::class)->create([
        'code' => 'HALF',
        'name' => 'Half off once',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
    ]);

    $billable = invoiceAmountBillable();

    // 1000 net − 50% = 500 net → 595 gross at DE 19%: exactly what checkout charged.
    InvoiceAmountFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_first_coupon',
        'status' => 'paid',
        'amount' => (object) ['value' => '5.95', 'currency' => 'EUR'],
        'subscriptionId' => null,
        'customerId' => 'cst_am',
        'mandateId' => 'mdt_am',
        'countryCode' => 'DE',
        'metadata' => [
            'type' => 'subscription',
            'plan_code' => 'pro',
            'interval' => 'monthly',
            'coupon_code' => 'HALF',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_first_coupon'])->assertStatus(200);

    $invoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_first_coupon')->first();
    expect($invoice)->not->toBeNull()
        ->and((int) $invoice->amount_net)->toBe(500)
        ->and((int) $invoice->amount_vat)->toBe(95)
        ->and((int) $invoice->amount_gross)->toBe(595);

    $couponLine = lineByKind($invoice, 'coupon');
    expect($couponLine)->not->toBeNull()
        ->and((int) $couponLine['total_net'])->toBe(-500)
        ->and((int) $couponLine['quantity'])->toBe(1)
        ->and((int) $couponLine['unit_price'])->toBe(-500)
        ->and($couponLine['label'])->toBe('Coupon HALF');

    // The invoice matches the payment, so nothing is off and no adjustment is needed.
    expect(lineByKind($invoice, 'adjustment'))->toBeNull();
    Event::assertNotDispatched(PaymentAmountMismatch::class);

    expect($billable->fresh()->subscription_status)->toBe(SubscriptionStatus::Active);
});

it('reconciles the first-payment invoice to the amount actually paid and reports the mismatch', function (): void {
    Event::fake([PaymentAmountMismatch::class]);

    $billable = invoiceAmountBillable();

    // Checkout charged 11.90 (no coupon), but the plan price was raised to 1200
    // net between checkout and webhook: expected 1428 gross ≠ 1190 paid.
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.base_price_net', 1200);

    InvoiceAmountFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_first_mismatch',
        'status' => 'paid',
        'amount' => (object) ['value' => '11.90', 'currency' => 'EUR'],
        'subscriptionId' => null,
        'customerId' => 'cst_am',
        'mandateId' => 'mdt_am',
        'countryCode' => 'DE',
        'metadata' => [
            'type' => 'subscription',
            'plan_code' => 'pro',
            'interval' => 'monthly',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_first_mismatch'])->assertStatus(200);

    $invoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_first_mismatch')->first();
    expect($invoice)->not->toBeNull()
        ->and((int) $invoice->amount_gross)->toBe(1190)
        ->and((int) $invoice->amount_net)->toBe(1000);

    expect(lineByKind($invoice, 'adjustment'))->not->toBeNull();

    Event::assertDispatched(PaymentAmountMismatch::class, function (PaymentAmountMismatch $e) {
        return $e->paymentId === 'tr_first_mismatch'
            && $e->expectedGross === 1428
            && $e->actualGross === 1190;
    });
});

it('creates the Mollie subscription outside the gate transaction and spends the activation claim', function (): void {
    $billable = invoiceAmountBillable();

    $levelInside = null;
    Event::listen(SubscriptionCreated::class, function () use (&$levelInside): void {
        $levelInside ??= DB::transactionLevel();
    });
    $levelOutside = DB::transactionLevel();

    InvoiceAmountFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_first_locked',
        'status' => 'paid',
        'amount' => (object) ['value' => '11.90', 'currency' => 'EUR'],
        'subscriptionId' => null,
        'customerId' => 'cst_am',
        'mandateId' => 'mdt_am',
        'countryCode' => 'DE',
        'metadata' => [
            'type' => 'subscription',
            'plan_code' => 'pro',
            'interval' => 'monthly',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_first_locked'])->assertStatus(200);

    // Concurrent checkouts are serialized by the gate transaction (row lock +
    // durable activation claim), but the Mollie call and everything after it
    // must run at the caller's transaction level: a rollback there would erase
    // mollie_subscription_id and the invoice while the subscription exists at
    // Mollie, and the re-delivery would create a second one.
    expect($levelInside)->not->toBeNull()
        ->and($levelInside)->toBe($levelOutside);

    // A successful activation spends the claim the gate took.
    expect($billable->fresh()->getBillingSubscriptionMeta())->not->toHaveKey('activation_claim');
});

it('reconciles the renewal invoice to the amount actually paid and reports the mismatch', function (): void {
    Event::fake([PaymentAmountMismatch::class]);

    $billable = invoiceAmountBillable();
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(30),
        'mollie_mandate_id' => 'mdt_am',
        'subscription_meta' => ['mollie_subscription_id' => 'sub_drift'],
    ])->save();

    // The plan was raised 1000 → 1200 net between renewals while the Mollie
    // subscription still charges the old 11.90 gross (the PATCH did not run):
    // the invoice must book the 1190 that cleared, not the 1428 the catalog
    // says — otherwise 2.38 of output VAT is over-declared per renewal and the
    // refundable base exceeds what Mollie can refund.
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.base_price_net', 1200);

    InvoiceAmountFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_renewal_drift',
        'status' => 'paid',
        'amount' => (object) ['value' => '11.90', 'currency' => 'EUR'],
        'paidAt' => BillingTime::nowUtc()->toIso8601String(),
        'subscriptionId' => 'sub_drift',
        'countryCode' => 'DE',
        'metadata' => [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_renewal_drift'])->assertStatus(200);

    $invoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_renewal_drift')->first();
    expect($invoice)->not->toBeNull()
        ->and((int) $invoice->amount_gross)->toBe(1190)
        ->and((int) $invoice->amount_net)->toBe(1000)
        ->and((int) $invoice->amount_vat)->toBe(190);

    $adjustment = lineByKind($invoice, 'adjustment');
    expect($adjustment)->not->toBeNull()
        ->and((int) $adjustment['total_net'])->toBe(-200);

    // The refundable base stays within what Mollie can actually refund.
    expect($invoice->remainingRefundableNet())->toBe(1000);

    Event::assertDispatchedTimes(PaymentAmountMismatch::class, 1);
    Event::assertDispatched(PaymentAmountMismatch::class, function (PaymentAmountMismatch $e) {
        return $e->paymentId === 'tr_renewal_drift'
            && $e->expectedGross === 1428
            && $e->actualGross === 1190;
    });
});

it('books the recurring coupon discount on the renewal invoice with the canonical line keys', function (): void {
    $coupon = app(CouponService::class)->create([
        'code' => 'HALFREC',
        'name' => 'Half off forever',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
        'max_redemptions_per_billable' => 6,
    ]);

    $billable = invoiceAmountBillable();
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(30),
        'mollie_mandate_id' => 'mdt_am',
        'subscription_meta' => ['mollie_subscription_id' => 'sub_rec'],
    ])->save();

    app(CouponService::class)->applyRecurringMarker($coupon, $billable->fresh(), 1000);

    InvoiceAmountFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_renewal_coupon',
        'status' => 'paid',
        'amount' => (object) ['value' => '5.95', 'currency' => 'EUR'],
        'paidAt' => BillingTime::nowUtc()->toIso8601String(),
        'subscriptionId' => 'sub_rec',
        'countryCode' => 'DE',
        'metadata' => [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_renewal_coupon'])->assertStatus(200);

    $invoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_renewal_coupon')->first();
    expect($invoice)->not->toBeNull()
        ->and((int) $invoice->amount_net)->toBe(500)
        ->and((int) $invoice->amount_vat)->toBe(95)
        ->and((int) $invoice->amount_gross)->toBe(595);

    $couponLine = lineByKind($invoice, 'coupon');
    expect($couponLine)->not->toBeNull()
        ->and((int) $couponLine['quantity'])->toBe(1)
        ->and((int) $couponLine['unit_price'])->toBe(-500)
        ->and((int) $couponLine['total_net'])->toBe(-500)
        ->and($couponLine['label'])->toBe('Coupon HALFREC');

    // Header and lines agree, so the invoice is fully refundable as charged.
    expect($invoice->remainingRefundableNet())->toBe(500);

    expect(CouponRedemption::query()->where('coupon_id', $coupon->id)->count())->toBe(1);
});

it('sends TrialConvertedNotification when the first recurring charge after a trial lands', function (): void {
    Notification::fake();
    Event::fake([TrialConverted::class]);

    MollieBilling::notifyBillingAdminsUsing(fn ($billable): array => [$billable]);

    $billable = invoiceAmountBillable();
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(14),
        'trial_ends_at' => BillingTime::nowUtc(),
        'mollie_mandate_id' => 'mdt_am',
        'subscription_meta' => ['mollie_subscription_id' => 'sub_trial'],
    ])->save();

    InvoiceAmountFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_trial_converted',
        'status' => 'paid',
        'amount' => (object) ['value' => '11.90', 'currency' => 'EUR'],
        'paidAt' => BillingTime::nowUtc()->toIso8601String(),
        'subscriptionId' => 'sub_trial',
        'countryCode' => 'DE',
        'metadata' => [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];

    try {
        $this->postJson(route('billing.webhook'), ['id' => 'tr_trial_converted'])->assertStatus(200);
    } finally {
        $prop = (new ReflectionClass(MollieBilling::class))->getProperty('notifyBillingAdminsCallback');
        $prop->setValue(null, null);
    }

    $billable->refresh();
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->trial_ends_at)->toBeNull();

    Event::assertDispatched(TrialConverted::class);
    Notification::assertSentTo($billable, TrialConvertedNotification::class);
});
