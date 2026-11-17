<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events;
use GraystackIT\MollieBilling\Exceptions\UsageLimitExceededException;
use GraystackIT\MollieBilling\Jobs\CleanupOrphanedBillablesJob;
use GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\ActivateLocalSubscription;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionGate;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use GraystackIT\MollieBilling\Services\Vat\CountryMatchService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Http\Requests\GetSubscriptionRequest;

require_once __DIR__.'/Support.php';

/*
 * The events around money, documents, usage and compliance — each dispatched by its real
 * cause, with payload and audit row asserted. See EventCatalogSubscriptionTest.
 */

beforeEach(function (): void {
    longRunBoot();
    lifecycleCatalogExtras();
});

afterEach(fn () => Carbon::setTestNow());

it('dispatches InvoiceRefunded and CreditNoteIssued when an invoice is refunded', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    $refunded = lifecycleListen(Events\InvoiceRefunded::class);
    $issued = lifecycleListen(Events\CreditNoteIssued::class);

    $creditNote = app(RefundInvoiceService::class)->refundPartially($world->lastInvoice, 1000, RefundReasonCode::Goodwill, 'sorry');

    expect($refunded)->toHaveCount(1)
        ->and($refunded[0]->originalInvoice->getKey())->toBe($world->lastInvoice->getKey())
        ->and($refunded[0]->creditNote->getKey())->toBe($creditNote->getKey())
        ->and((int) $refunded[0]->creditNote->amount_gross)->toBe(-1190)
        ->and($issued)->toHaveCount(1)
        ->and($issued[0]->creditNote->getKey())->toBe($creditNote->getKey())
        ->and($issued[0]->originalInvoice->getKey())->toBe($world->lastInvoice->getKey());

    lifecycleAssertAudited('invoice_refunded');
    lifecycleAssertAudited('credit_note_issued');
});

it('dispatches RefundReversed when Mollie reports a booked refund as failed', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    app(RefundInvoiceService::class)->refundPartially($world->lastInvoice, 1000, RefundReasonCode::Goodwill, 'sorry');
    $events = lifecycleListen(Events\RefundReversed::class);

    // The bank bounces the refund: Mollie lists it as failed and takes it off the total.
    $refund = $world->refunds['tr_lr_first'][0];
    $refund->status = 'failed';
    $world->payments['tr_lr_first']->amountRefunded = null;
    longRunDeliver($world, $world->payments['tr_lr_first'], '2027-01-18 09:00:00');

    expect($events)->toHaveCount(1)
        ->and($events[0]->mollieRefundId)->toBe($refund->id)
        ->and($events[0]->status)->toBe('failed')
        ->and((int) $events[0]->reversal->amount_gross)->toBe(1190)
        ->and($world->lastInvoice->fresh()->remainingRefundableNet())->toBe(2900);

    lifecycleAssertAudited('refund_reversed');
});

it('dispatches InvoicePdfRegenerated when an invoice document is rendered again', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    $events = lifecycleListen(Events\InvoicePdfRegenerated::class);

    expect(app(InvoiceService::class)->regeneratePdf($world->lastInvoice))->toBeTrue()
        ->and($events)->toHaveCount(1)
        ->and($events[0]->invoice->getKey())->toBe($world->lastInvoice->getKey());

    lifecycleAssertAudited('invoice_pdf_regenerated');
});

it('dispatches OneTimeOrderCompleted when a product purchase is paid, and OneTimeOrderFailed when it is not', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    $completed = lifecycleListen(Events\OneTimeOrderCompleted::class);
    $failed = lifecycleListen(Events\OneTimeOrderFailed::class);

    $order = fn (string $id, string $status): object => $world->payments[$id] = LongRunPayment::make([
        'id' => $id, 'status' => $status,
        // 10.00 net + 19 % = 11.90.
        'amount' => (object) ['value' => '11.90', 'currency' => 'EUR'],
        'createdAt' => '2027-01-20T09:00:00+00:00', 'paidAt' => $status === 'paid' ? '2027-01-20T09:00:30+00:00' : null,
        'subscriptionId' => null, 'customerId' => 'cst_longrun', 'mandateId' => 'mdt_longrun', 'countryCode' => 'DE',
        'metadata' => [
            'type' => 'one_time_order', 'product_code' => 'tokens_500',
            'billable_type' => $world->billable->getMorphClass(), 'billable_id' => (string) $world->billable->getKey(),
        ],
    ]);

    longRunDeliver($world, $order('tr_order_paid', 'paid'));

    expect($completed)->toHaveCount(1)
        ->and($completed[0]->productCode)->toBe('tokens_500')
        ->and((int) $completed[0]->invoice->amount_gross)->toBe(1190)
        ->and($world->billable->remainingBillingQuota('Tokens'))->toBe(600);
    lifecycleAssertAudited('one_time_order_completed', ['product' => 'tokens_500']);

    longRunDeliver($world, $order('tr_order_failed', 'failed'), '2027-01-21 09:00:00');

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->productCode)->toBe('tokens_500')
        ->and($failed[0]->paymentId)->toBe('tr_order_failed')
        ->and($world->billable->remainingBillingQuota('Tokens'))->toBe(600);
    lifecycleAssertAudited('one_time_order_failed', ['product' => 'tokens_500']);
});

it('dispatches UsageLimitReached when usage hits a quota that cannot be exceeded', function (): void {
    // Tokens carry no overage price on Pro: a hard cap.
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    $events = lifecycleListen(Events\UsageLimitReached::class);

    $world->billable->recordBillingUsage('Tokens', 90);

    expect(fn () => $world->billable->recordBillingUsage('Tokens', 25))->toThrow(UsageLimitExceededException::class);

    expect($events)->toHaveCount(1)
        ->and($events[0]->usageType)->toBe('Tokens')
        ->and($events[0]->remaining)->toBe(10)
        ->and($events[0]->attemptedQuantity)->toBe(25)
        ->and($world->billable->remainingBillingQuota('Tokens'))->toBe(10);

    lifecycleAssertAudited('usage_limit_reached');
});

it('dispatches OverageCharged when the overage of a period is collected', function (): void {
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.usage_overage_prices', ['Tokens' => 10]);

    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    $world->billable->recordBillingUsage('Tokens', 150);
    $events = lifecycleListen(Events\OverageCharged::class);

    // The night before the renewal the sweep creates the charge: 50 × 0.10 = 5.00 net → 5.95.
    Carbon::setTestNow(Carbon::parse('2027-02-14 02:00:00', 'UTC'));
    app()->call([new PrepareUsageOverageJob, 'handle']);

    $invoice = longRunSettleSideCharge($world, '2027-02-14 02:05:00');

    expect((int) $invoice->amount_gross)->toBe(595)
        ->and($events)->toHaveCount(1)
        ->and($events[0]->invoice->getKey())->toBe($invoice->getKey())
        ->and((int) ($events[0]->lineItems[0]['quantity'] ?? 0))->toBe(50)
        // Paid for, so no longer owed.
        ->and($world->billable->fresh()->billingOverageCount('Tokens'))->toBe(0);

    lifecycleAssertAudited('overage_charged');
});

it('dispatches CountryMismatchFlagged at a renewal whose signals disagree, and CountryMismatchResolved when it is corrected', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    $flagged = lifecycleListen(Events\CountryMismatchFlagged::class);
    $resolved = lifecycleListen(Events\CountryMismatchResolved::class);

    // Declared Austria; the card and the IP both say otherwise.
    $world->billable->forceFill(['tax_country_user' => 'AT', 'tax_country_ip' => 'CH'])->save();
    longRunRenew($world);

    expect($flagged)->toHaveCount(1)
        ->and($flagged[0]->mismatch->tax_country_user)->toBe('AT')
        ->and($flagged[0]->mismatch->tax_country_payment)->toBe('DE');
    lifecycleAssertAudited('country_mismatch_flagged');

    app(CountryMatchService::class)->resolve($world->billable->fresh(), $flagged[0]->mismatch->fresh(), 'DE', null, 'customer confirmed by phone');

    expect($resolved)->toHaveCount(1)
        ->and($resolved[0]->justification)->toBe('customer confirmed by phone')
        ->and($resolved[0]->mismatch->getKey())->toBe($flagged[0]->mismatch->getKey());
    lifecycleAssertAudited('country_mismatch_resolved');
});

it('dispatches MollieSubscriptionReferenceCleared when Mollie says the subscription is gone', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    $events = lifecycleListen(Events\MollieSubscriptionReferenceCleared::class);

    $world->intercept = function (object $request): mixed {
        if ($request instanceof GetSubscriptionRequest) {
            throw new class('gone', 410) extends ApiException
            {
                public function __construct(string $message, int $code)
                {
                    \Exception::__construct($message, $code);
                }
            };
        }

        return null;
    };

    expect(app(MollieSubscriptionGate::class)->hasLiveMollieSubscription($world->billable->fresh()))->toBeFalse()
        ->and($events)->toHaveCount(1)
        ->and($events[0]->mollieSubscriptionId)->toBe('sub_longrun')
        ->and($events[0]->reason)->toBe('gone')
        ->and($world->billable->fresh()->getBillingSubscriptionMeta())->not->toHaveKey('mollie_subscription_id');

    lifecycleAssertAudited('mollie_subscription_reference_cleared');
});

it('dispatches MollieSubscriptionReplaced when the next charge of a charged subscription has to move', function (): void {
    // Signed up on 31 January: Mollie's subscription starts on 28 February, the anchor day
    // is the 31st. After the 28 February charge Mollie (counting on from the charge) would
    // charge on 28 March, and refuses a new start date on a charged subscription — so the
    // subscription is replaced, starting on 31 March.
    $world = longRunStart('monthly', '2027-01-31 10:00:00', '34.51');
    $world->cadence = 'drift';
    $events = lifecycleListen(Events\MollieSubscriptionReplaced::class);

    longRunRenew($world);

    expect($events)->toHaveCount(1)
        ->and($events[0]->previousSubscriptionId)->toBe('sub_longrun')
        ->and($events[0]->subscriptionId)->toBe('sub_longrun_2')
        ->and($events[0]->startDate->toDateString())->toBe('2027-03-31')
        ->and($events[0]->reason)->toBe('anchor_pin')
        ->and($world->billable->fresh()->getBillingSubscriptionMeta()['mollie_subscription_id'])->toBe('sub_longrun_2');

    lifecycleAssertAudited('mollie_subscription_replaced');
});

it('dispatches CheckoutAbandoned when a signup that never paid is cleaned up', function (): void {
    config(['mollie-billing.cleanup.enabled' => true, 'mollie-billing.cleanup.threshold_minutes' => 60]);
    $events = lifecycleListen(Events\CheckoutAbandoned::class);

    $orphan = TestBillable::create(['name' => 'Stale Co', 'email' => uniqid('stale-').'@x.test']);
    $orphan->forceFill(['created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2)])->save();
    LongRunWorld::$current = new LongRunWorld($orphan, 'monthly');

    CleanupOrphanedBillablesJob::dispatchSync();

    expect(TestBillable::find($orphan->getKey()))->toBeNull()
        ->and($events)->toHaveCount(1)
        ->and($events[0]->billable->getKey())->toBe($orphan->getKey());

    lifecycleAssertAudited('checkout_abandoned');
});

it('dispatches GrantRevoked when a gift is taken back', function (): void {
    Carbon::setTestNow(Carbon::parse('2027-01-10 10:00:00', 'UTC'));
    $billable = TestBillable::create(['name' => 'Gift Co', 'email' => uniqid('gift-').'@x.test', 'billing_country' => 'DE']);
    LongRunWorld::$current = new LongRunWorld($billable, 'monthly');

    $coupons = app(CouponService::class);
    $coupon = $coupons->accessGrantCoupon('GIFT90', 'pro', 'monthly', [], 90);
    $coupons->redeem($coupon, $billable->fresh(), []);

    expect($billable->fresh()->getBillingSubscriptionSource())->toBe(SubscriptionSource::Local->value);

    $events = lifecycleListen(Events\GrantRevoked::class);
    $coupons->revokeGrant(CouponRedemption::query()->sole(), 'given by mistake');

    expect($events)->toHaveCount(1)
        ->and($events[0]->coupon->code)->toBe('GIFT90')
        ->and($events[0]->reason)->toBe('given by mistake')
        ->and($billable->fresh()->hasAccessibleBillingSubscription())->toBeFalse();

    lifecycleAssertAudited('grant_revoked');
});

it('dispatches SubscriptionUpgradedFromLocal when a free subscription becomes a paid one', function (): void {
    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free', 'tier' => 1, 'included_seats' => 2, 'feature_keys' => [], 'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => ['Tokens' => 10]]],
    ]);

    Carbon::setTestNow(Carbon::parse('2027-01-10 10:00:00', 'UTC'));
    $billable = TestBillable::create(['name' => 'Free Co', 'email' => uniqid('free-').'@x.test', 'billing_country' => 'DE']);
    $billable->forceFill(['mollie_customer_id' => 'cst_longrun'])->save();
    $world = LongRunWorld::$current = new LongRunWorld($billable, 'monthly');
    app(ActivateLocalSubscription::class)->handle($billable->fresh(), 'free', 'monthly');
    $world->billable = $billable->fresh();

    expect($world->billable->getBillingSubscriptionSource())->toBe(SubscriptionSource::Local->value);

    $events = lifecycleListen(Events\SubscriptionUpgradedFromLocal::class);

    Carbon::setTestNow(Carbon::parse('2027-01-20 10:00:00', 'UTC'));
    $world->payments['tr_upgrade'] = LongRunPayment::make([
        'id' => 'tr_upgrade', 'status' => 'paid',
        'amount' => (object) ['value' => '34.51', 'currency' => 'EUR'],
        'createdAt' => now()->toIso8601String(), 'paidAt' => now()->toIso8601String(),
        'subscriptionId' => null, 'customerId' => 'cst_longrun', 'mandateId' => 'mdt_longrun', 'countryCode' => 'DE',
        'metadata' => [
            'type' => 'subscription', 'upgrade_from_local' => true,
            'plan_code' => 'pro', 'interval' => 'monthly', 'addon_codes' => [], 'extra_seats' => 0,
            'billable_type' => $billable->getMorphClass(), 'billable_id' => (string) $billable->getKey(),
        ],
    ]);
    longRunDeliver($world, $world->payments['tr_upgrade']);

    expect($world->billable->getBillingSubscriptionSource())->toBe(SubscriptionSource::Mollie->value)
        ->and($world->billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and($events)->toHaveCount(1)
        ->and($events[0]->oldPlanCode)->toBe('free')
        ->and($events[0]->newPlanCode)->toBe('pro')
        ->and($events[0]->newInterval)->toBe('monthly')
        ->and((int) BillingInvoice::query()->where('mollie_payment_id', 'tr_upgrade')->sole()->amount_gross)->toBe(3451);

    lifecycleAssertAudited('subscription_upgraded_from_local');
});

it('dispatches OverageChargeFailed when the retry of an overage charge cannot reach Mollie', function (): void {
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.usage_overage_prices', ['Tokens' => 10]);
    \Illuminate\Support\Facades\Bus::fake([\GraystackIT\MollieBilling\Jobs\RetryUsageOverageChargeJob::class]);

    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    $world->billable->recordBillingUsage('Tokens', 150);

    // The sweep charges the overage; the charge fails at the bank.
    Carbon::setTestNow(Carbon::parse('2027-02-14 02:00:00', 'UTC'));
    app()->call([new PrepareUsageOverageJob, 'handle']);

    $request = collect($world->requests)->last(fn ($r) => $r instanceof \Mollie\Api\Http\Requests\CreatePaymentRequest);
    $paymentId = 'tr_side_'.$world->sequence;
    $world->payments[$paymentId] = LongRunPayment::make([
        'id' => $paymentId, 'status' => 'failed',
        'amount' => (object) ['value' => longRunRequestProperty($request, 'amount')->value, 'currency' => 'EUR'],
        'createdAt' => now()->toIso8601String(), 'paidAt' => null,
        'subscriptionId' => null, 'customerId' => 'cst_longrun', 'mandateId' => 'mdt_longrun', 'countryCode' => 'DE',
        'metadata' => longRunRequestProperty($request, 'metadata'),
    ]);
    longRunDeliver($world, $world->payments[$paymentId], '2027-02-14 08:00:00');

    \Illuminate\Support\Facades\Bus::assertDispatched(\GraystackIT\MollieBilling\Jobs\RetryUsageOverageChargeJob::class);

    // The retry: Mollie cannot be reached.
    $events = lifecycleListen(Events\OverageChargeFailed::class);
    $world->refuse[\Mollie\Api\Http\Requests\CreatePaymentRequest::class] = PHP_INT_MAX;

    try {
        app()->call([new \GraystackIT\MollieBilling\Jobs\RetryUsageOverageChargeJob(TestBillable::class, $world->billable->getKey(), 1), 'handle']);
    } catch (\Throwable) {
        // the queue would back off and try again
    }

    expect($events)->toHaveCount(1)
        ->and($events[0]->exception->getMessage())->toContain('Mollie is unavailable')
        // Nothing was charged, so the debt stands.
        ->and($world->billable->fresh()->billingOverageCount('Tokens'))->toBe(50);

    lifecycleAssertAudited('overage_charge_failed');
});
