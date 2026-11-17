<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events;
use GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob;
use GraystackIT\MollieBilling\Jobs\ProcessTrialLifecycleJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\AdminExtensionService;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange;
use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;

require_once __DIR__.'/Support.php';

/*
 * Every event of a subscription's life, dispatched by the thing that really causes it —
 * a webhook, a service call, a nightly job — and never by `event(new …)` in the test.
 * Each case asserts the payload and the audit row the event leaves behind.
 */

beforeEach(function (): void {
    longRunBoot();
    lifecycleCatalogExtras();
});

afterEach(fn () => Carbon::setTestNow());

it('dispatches CheckoutStarted when a checkout creates its payment at Mollie', function (): void {
    $events = lifecycleListen(Events\CheckoutStarted::class);

    Carbon::setTestNow(Carbon::parse('2027-01-15 10:00:00', 'UTC'));
    $billable = \GraystackIT\MollieBilling\Testing\TestBillable::create(['name' => 'Start Co', 'email' => uniqid('start-').'@x.test', 'billing_country' => 'DE']);
    $billable->forceFill(['mollie_customer_id' => 'cst_longrun'])->save();
    LongRunWorld::$current = new LongRunWorld($billable, 'monthly');

    $result = app(StartSubscriptionCheckout::class)->handle($billable->fresh(), [
        'plan_code' => 'pro', 'interval' => 'monthly', 'addon_codes' => [], 'extra_seats' => 0, 'amount_gross' => 3451,
    ]);

    expect($result['payment_id'])->not->toBe('')
        ->and($events)->toHaveCount(1)
        ->and($events[0]->billable->getKey())->toBe($billable->getKey());

    lifecycleAssertAudited('checkout_started');
});

it('dispatches SubscriptionCreated when the first payment activates the subscription', function (): void {
    $events = lifecycleListen(Events\SubscriptionCreated::class);

    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');

    expect($events)->toHaveCount(1)
        ->and($events[0]->planCode)->toBe('pro')
        ->and($events[0]->interval)->toBe('monthly')
        ->and($events[0]->billable->getKey())->toBe($world->billable->getKey());

    lifecycleAssertAudited('subscription_created', ['plan' => 'pro', 'interval' => 'monthly']);
});

it('dispatches InvoiceCreated and PaymentSucceeded for the invoice a payment is booked on', function (): void {
    $created = lifecycleListen(Events\InvoiceCreated::class);
    $succeeded = lifecycleListen(Events\PaymentSucceeded::class);

    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    $invoice = $world->lastInvoice;

    expect($created)->toHaveCount(1)
        ->and($created[0]->invoice->getKey())->toBe($invoice->getKey())
        ->and($succeeded)->toHaveCount(1)
        ->and($succeeded[0]->invoice->getKey())->toBe($invoice->getKey())
        ->and((int) $succeeded[0]->invoice->amount_gross)->toBe(3451);

    lifecycleAssertAudited('invoice_created', ['serial' => $invoice->serial_number, 'amount_cents' => 3451, 'payment_id' => 'tr_lr_first']);
    lifecycleAssertAudited('payment_succeeded', ['serial' => $invoice->serial_number, 'amount_cents' => 3451]);
});

it('dispatches WalletCredited for the included quota of a new subscription', function (): void {
    $events = lifecycleListen(Events\WalletCredited::class);

    longRunStart('monthly', '2027-01-15 10:00:00', '34.51');

    expect($events)->toHaveCount(1)
        ->and($events[0]->usageType)->toBe('Tokens')
        ->and($events[0]->units)->toBe(100);

    lifecycleAssertAudited('wallet_credited');
});

it('dispatches CouponRedeemed when a checkout coupon is redeemed', function (): void {
    app(CouponService::class)->create([
        'code' => 'HALF', 'name' => 'Half off once', 'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 50,
    ]);
    $events = lifecycleListen(Events\CouponRedeemed::class);

    // 29.00 − 50 % = 14.50 net; VAT 5.51 − 2.76 → 17.25.
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '17.25', ['coupon_code' => 'HALF']);

    expect($events)->toHaveCount(1)
        ->and($events[0]->coupon->code)->toBe('HALF')
        ->and((int) $events[0]->redemption->discount_amount_net)->toBe(1450)
        ->and((int) $events[0]->redemption->invoice_id)->toBe((int) $world->lastInvoice->id);

    lifecycleAssertAudited('coupon_redeemed');
});

it('dispatches MandateUpdated and TrialStarted when a trial signup captures its mandate', function (): void {
    $mandate = lifecycleListen(Events\MandateUpdated::class);
    $trial = lifecycleListen(Events\TrialStarted::class);

    $world = lifecycleStartTrial();

    expect($world->billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Trial)
        ->and($mandate)->toHaveCount(1)
        ->and($mandate[0]->previousMandateId)->toBeNull()
        ->and($mandate[0]->newMandateId)->toBe('mdt_longrun')
        ->and($trial)->toHaveCount(1)
        ->and($trial[0]->planCode)->toBe('trialplan')
        ->and($trial[0]->trialDays)->toBe(14);

    lifecycleAssertAudited('mandate_updated');
    lifecycleAssertAudited('trial_started', ['plan' => 'trialplan']);
});

it('dispatches TrialConverted when the first charge after a trial settles', function (): void {
    $world = lifecycleStartTrial('2027-01-10 10:00:00');
    $events = lifecycleListen(Events\TrialConverted::class);

    // Mollie charges at the trial's end: 24 January.
    $payment = longRunRenew($world);

    expect(substr($payment->createdAt, 0, 10))->toBe('2027-01-24')
        ->and($world->billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and($events)->toHaveCount(1)
        ->and($events[0]->planCode)->toBe('trialplan');

    lifecycleAssertAudited('trial_converted', ['plan' => 'trialplan']);
});

it('dispatches TrialExtended when a running trial is given more time', function (): void {
    $world = lifecycleStartTrial('2027-01-10 10:00:00');
    $events = lifecycleListen(Events\TrialExtended::class);

    $world->billable->extendBillingTrialUntil(Carbon::parse('2027-02-07 10:00:00', 'UTC'));

    expect($events)->toHaveCount(1)
        ->and($events[0]->previousEndsAt->toDateString())->toBe('2027-01-24')
        ->and($events[0]->newEndsAt->toDateString())->toBe('2027-02-07')
        // Mollie's first charge moved with it.
        ->and($world->nextChargeAt()->toDateString())->toBe('2027-02-07');

    lifecycleAssertAudited('trial_extended');
});

it('dispatches TrialExpired when a trial ends without its first charge', function (): void {
    $world = lifecycleStartTrial('2027-01-10 10:00:00');
    $events = lifecycleListen(Events\TrialExpired::class);

    // Past the trial's end and past the grace a mandate-holding trial is given for its
    // first charge to arrive.
    Carbon::setTestNow(Carbon::parse('2027-01-24 10:00:00', 'UTC')->addDays(ProcessTrialLifecycleJob::FIRST_CHARGE_GRACE_DAYS + 1));
    app()->call([new ProcessTrialLifecycleJob, 'handle']);

    expect($events)->toHaveCount(1)
        ->and($events[0]->billable->getKey())->toBe($world->billable->getKey())
        ->and($world->billable->fresh()->getBillingSubscriptionStatus())->not->toBe(SubscriptionStatus::Trial);

    lifecycleAssertAudited('trial_expired');
});

it('dispatches PaymentFailed when a renewal fails', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    $events = lifecycleListen(Events\PaymentFailed::class);

    $failed = longRunCreateCharge($world, status: 'failed');
    longRunDeliver($world, $failed, '2027-02-16 07:00:00');

    expect($world->billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::PastDue)
        ->and($events)->toHaveCount(1)
        ->and($events[0]->paymentId)->toBe($failed->id);

    lifecycleAssertAudited('payment_failed');
});

it('dispatches PaymentAmountMismatch when Mollie collected another amount than the lines add up to', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    $events = lifecycleListen(Events\PaymentAmountMismatch::class);

    // A PATCH that never landed: Mollie still holds an old price.
    $world->heldValue = '23.80';
    $payment = longRunRenew($world);

    expect($events)->toHaveCount(1)
        ->and($events[0]->paymentId)->toBe($payment->id)
        ->and($events[0]->expectedGross)->toBe(3451)
        ->and($events[0]->actualGross)->toBe(2380)
        // Booked at what was collected.
        ->and((int) BillingInvoice::query()->where('mollie_payment_id', $payment->id)->sole()->amount_gross)->toBe(2380);

    lifecycleAssertAudited('payment_amount_mismatch');
});

it('dispatches WalletReset when a renewal refills a quota that does not roll over', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    $world->billable->recordBillingUsage('Tokens', 30);
    $events = lifecycleListen(Events\WalletReset::class);

    longRunRenew($world);

    expect($events)->toHaveCount(1)
        ->and($events[0]->usageType)->toBe('Tokens')
        ->and($events[0]->previousBalance)->toBe(70)
        ->and($events[0]->newBalance)->toBe(100);

    lifecycleAssertAudited('wallet_reset');
});

it('dispatches DuplicatePaymentReceived for a second first payment', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    $events = lifecycleListen(Events\DuplicatePaymentReceived::class);

    // The second checkout tab settles too.
    $second = clone $world->payments['tr_lr_first'];
    $second->id = 'tr_lr_second';
    $world->payments['tr_lr_second'] = $second;
    longRunDeliver($world, $second, '2027-01-15 10:05:00');

    expect($events)->toHaveCount(1)
        ->and($events[0]->paymentId)->toBe('tr_lr_second')
        ->and($world->subscriptionsCreated)->toBe(1);

    lifecycleAssertAudited('duplicate_payment_received', ['payment_id' => 'tr_lr_second']);
});

it('dispatches SeatsChanged and SubscriptionUpdated when seats are added', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    $seats = lifecycleListen(Events\SeatsChanged::class);
    $updated = lifecycleListen(Events\SubscriptionUpdated::class);

    Carbon::setTestNow(Carbon::parse('2027-01-20 09:00:00', 'UTC'));
    app(UpdateSubscription::class)->update($world->billable->fresh(), ['seats' => 4]);

    expect($seats)->toHaveCount(1)
        ->and($seats[0]->oldCount)->toBe(2)
        ->and($seats[0]->newCount)->toBe(4)
        ->and($updated)->toHaveCount(1)
        ->and($world->heldValue)->toBe('46.41');

    lifecycleAssertAudited('seats_changed');
    lifecycleAssertAudited('subscription_updated');
});

it('dispatches AddonEnabled and AddonDisabled when an addon is booked and dropped', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    $enabled = lifecycleListen(Events\AddonEnabled::class);
    $disabled = lifecycleListen(Events\AddonDisabled::class);

    Carbon::setTestNow(Carbon::parse('2027-01-20 09:00:00', 'UTC'));
    app(UpdateSubscription::class)->update($world->billable->fresh(), ['addons' => ['sso']]);

    expect($enabled)->toHaveCount(1)
        ->and($enabled[0]->addonCode)->toBe('sso')
        ->and($world->heldValue)->toBe('52.36');
    lifecycleAssertAudited('addon_enabled', ['addon' => 'sso']);

    longRunSettleSideCharge($world, '2027-01-20 09:05:00');

    Carbon::setTestNow(Carbon::parse('2027-01-25 09:00:00', 'UTC'));
    app(UpdateSubscription::class)->update($world->billable->fresh(), ['addons' => []]);

    expect($disabled)->toHaveCount(1)
        ->and($disabled[0]->addonCode)->toBe('sso')
        ->and($world->heldValue)->toBe('34.51');
    lifecycleAssertAudited('addon_disabled', ['addon' => 'sso']);
});

it('dispatches PlanChangePending, then PlanChanged once the prorata charge settles', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    $pending = lifecycleListen(Events\PlanChangePending::class);
    $changed = lifecycleListen(Events\PlanChanged::class);

    Carbon::setTestNow(Carbon::parse('2027-01-20 09:00:00', 'UTC'));
    app(UpdateSubscription::class)->update($world->billable->fresh(), ['plan_code' => 'team', 'interval' => 'monthly']);

    expect($pending)->toHaveCount(1)
        ->and($pending[0]->paymentId)->toBe('tr_side_'.$world->sequence)
        ->and($changed)->toHaveCount(0)
        ->and($world->billable->fresh()->getBillingSubscriptionPlanCode())->toBe('pro');
    lifecycleAssertAudited('plan_change_pending');

    $chargeInvoice = longRunSettleSideCharge($world, '2027-01-20 09:05:00');

    expect($changed)->toHaveCount(1)
        ->and($changed[0]->oldPlan)->toBe('pro')
        ->and($changed[0]->newPlan)->toBe('team')
        ->and($changed[0]->interval)->toBe('monthly')
        ->and($world->billable->fresh()->getBillingSubscriptionPlanCode())->toBe('team')
        // Team 59.00 + 19 % = 70.21.
        ->and($world->heldValue)->toBe('70.21');
    lifecycleAssertAudited('plan_changed', ['old_plan' => 'pro', 'new_plan' => 'team', 'interval' => 'monthly']);

    // Mollie delivers the charge again: the change is announced once.
    test()->postJson(route('billing.webhook'), ['id' => (string) $chargeInvoice->mollie_payment_id])->assertStatus(200);

    expect($changed)->toHaveCount(1)
        ->and(\GraystackIT\MollieBilling\Support\BillingAuditEntry::model()::query()->where('event', 'plan_changed')->count())->toBe(1);
});

it('dispatches PlanChangeFailed when the prorata charge of a plan change fails', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    $events = lifecycleListen(Events\PlanChangeFailed::class);

    Carbon::setTestNow(Carbon::parse('2027-01-20 09:00:00', 'UTC'));
    app(UpdateSubscription::class)->update($world->billable->fresh(), ['plan_code' => 'team', 'interval' => 'monthly']);

    $request = collect($world->requests)->last(fn ($r) => $r instanceof \Mollie\Api\Http\Requests\CreatePaymentRequest);
    $paymentId = 'tr_side_'.$world->sequence;
    $world->payments[$paymentId] = LongRunPayment::make([
        'id' => $paymentId, 'status' => 'failed',
        'amount' => (object) ['value' => longRunRequestProperty($request, 'amount')->value, 'currency' => 'EUR'],
        'createdAt' => now()->toIso8601String(), 'paidAt' => null,
        'subscriptionId' => null, 'customerId' => 'cst_longrun', 'mandateId' => 'mdt_longrun', 'countryCode' => 'DE',
        'metadata' => longRunRequestProperty($request, 'metadata'),
    ]);
    longRunDeliver($world, $world->payments[$paymentId], '2027-01-22 09:00:00');

    expect($events)->toHaveCount(1)
        ->and($events[0]->paymentId)->toBe($paymentId)
        ->and($world->billable->fresh()->getBillingSubscriptionPlanCode())->toBe('pro');

    lifecycleAssertAudited('plan_change_failed');
});

it('dispatches SubscriptionChangeScheduled, Rescheduled and Cancelled for a change booked for the period end', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    $scheduled = lifecycleListen(Events\SubscriptionChangeScheduled::class);
    $rescheduled = lifecycleListen(Events\SubscriptionChangeRescheduled::class);
    $cancelled = lifecycleListen(Events\SubscriptionChangeCancelled::class);

    Carbon::setTestNow(Carbon::parse('2027-01-20 09:00:00', 'UTC'));
    $service = app(UpdateSubscription::class);

    $service->update($world->billable->fresh(), ['plan_code' => 'team', 'interval' => 'monthly', 'apply_at' => 'end_of_period']);

    expect($scheduled)->toHaveCount(1)
        ->and($scheduled[0]->scheduledChange['plan_code'] ?? null)->toBe('team')
        ->and($scheduled[0]->scheduledAt->toDateString())->toBe('2027-02-15')
        ->and($world->billable->fresh()->getBillingSubscriptionPlanCode())->toBe('pro');
    lifecycleAssertAudited('subscription_change_scheduled');

    // The customer changes their mind about what to switch to.
    $service->update($world->billable->fresh(), ['plan_code' => 'team', 'interval' => 'monthly', 'seats' => 4, 'apply_at' => 'end_of_period']);

    expect($rescheduled)->toHaveCount(1)
        ->and((int) ($rescheduled[0]->newScheduledChange['seats'] ?? 0))->toBe(4)
        ->and($rescheduled[0]->previousScheduledChange['plan_code'] ?? null)->toBe('team');
    lifecycleAssertAudited('subscription_change_rescheduled');

    app(ScheduleSubscriptionChange::class)->cancel($world->billable->fresh());

    expect($cancelled)->toHaveCount(1)
        ->and($cancelled[0]->previousScheduledChange['plan_code'] ?? null)->toBe('team')
        ->and($world->billable->fresh()->getBillingSubscriptionMeta())->not->toHaveKey('scheduled_change');
    lifecycleAssertAudited('subscription_change_cancelled');
});

it('dispatches SubscriptionCancelled, SubscriptionResumed and SubscriptionExpired along a cancellation', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    $cancelled = lifecycleListen(Events\SubscriptionCancelled::class);
    $resumed = lifecycleListen(Events\SubscriptionResumed::class);
    $expired = lifecycleListen(Events\SubscriptionExpired::class);

    Carbon::setTestNow(Carbon::parse('2027-01-20 09:00:00', 'UTC'));
    $world->billable->fresh()->cancelBillingSubscription(false, 'too expensive');

    expect($cancelled)->toHaveCount(1)
        ->and($cancelled[0]->immediately)->toBeFalse()
        ->and($cancelled[0]->reason)->toBe('too expensive')
        ->and($world->cancelled)->toBeTrue()
        ->and($world->billable->fresh()->getBillingSubscriptionEndsAt()->toDateString())->toBe('2027-02-15');
    lifecycleAssertAudited('subscription_cancelled', ['immediately' => false, 'reason' => 'too expensive']);

    // Back before the grace period ends.
    Carbon::setTestNow(Carbon::parse('2027-01-25 09:00:00', 'UTC'));
    $world->billable->fresh()->resubscribeBillingPlan();

    expect($resumed)->toHaveCount(1)
        ->and($world->billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        // The paid period is not charged twice: Mollie starts again at its end.
        ->and($world->nextChargeAt()->toDateString())->toBe('2027-02-15');
    lifecycleAssertAudited('subscription_resumed');

    // Cancelled again and left alone: the nightly pass expires it once the period is over.
    $world->billable->fresh()->cancelBillingSubscription(false);
    Carbon::setTestNow(Carbon::parse('2027-02-16 02:00:00', 'UTC'));
    app()->call([new PrepareUsageOverageJob, 'handle']);

    expect($expired)->toHaveCount(1)
        ->and($world->billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Expired);
    lifecycleAssertAudited('subscription_expired');
});

it('dispatches SubscriptionExtended when an operator gives a running gift more days', function (): void {
    // A plan given for 30 days from 15 January: a Local subscription ending 14 February.
    Carbon::setTestNow(Carbon::parse('2027-01-15 10:00:00', 'UTC'));
    $billable = \GraystackIT\MollieBilling\Testing\TestBillable::create(['name' => 'Gift Co', 'email' => uniqid('gift-').'@x.test', 'billing_country' => 'DE']);
    $coupons = app(\GraystackIT\MollieBilling\Services\Billing\CouponService::class);
    $coupon = $coupons->accessGrantCoupon('GIFT-LIFECYCLE', 'pro', 'monthly', [], 30, null);
    $coupons->validate('GIFT-LIFECYCLE', $billable->fresh(), []);
    $coupons->redeem($coupon, $billable->fresh(), []);

    $events = lifecycleListen(Events\SubscriptionExtended::class);

    Carbon::setTestNow(Carbon::parse('2027-01-20 09:00:00', 'UTC'));
    $newEnd = app(AdminExtensionService::class)->extendGrant($billable->fresh(), 10);

    expect($events)->toHaveCount(1)
        ->and($events[0]->previousEndsAt->toDateString())->toBe('2027-02-14')
        ->and($events[0]->newEndsAt->toDateString())->toBe('2027-02-24')
        ->and($newEnd->toDateString())->toBe('2027-02-24')
        ->and($billable->fresh()->getBillingSubscriptionEndsAt()->toDateString())->toBe('2027-02-24');

    lifecycleAssertAudited('subscription_extended', ['code' => 'GIFT-LIFECYCLE']);
});

it('moves the next charge, not an end date, when free days go to a paying subscriber', function (): void {
    // On a Mollie subscription nothing "ends": the days push the next charge back. That
    // is announced as the redemption it is (CouponRedeemed), never as SubscriptionExtended,
    // whose payload is an end date this subscription does not have.
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    $extended = lifecycleListen(Events\SubscriptionExtended::class);
    $redeemed = lifecycleListen(Events\CouponRedeemed::class);

    Carbon::setTestNow(Carbon::parse('2027-01-20 09:00:00', 'UTC'));
    app(AdminExtensionService::class)->extendByDays($world->billable->fresh(), 10);

    expect($extended)->toHaveCount(0)
        ->and($redeemed)->toHaveCount(1)
        ->and($world->nextChargeAt()->toDateString())->toBe('2027-02-25')
        ->and($world->billable->fresh()->getBillingSubscriptionEndsAt())->toBeNull();
});

it('dispatches CheckoutStarted for a trial checkout, and not for a change of payment method', function (): void {
    $events = lifecycleListen(Events\CheckoutStarted::class);

    Carbon::setTestNow(Carbon::parse('2027-01-15 10:00:00', 'UTC'));
    $billable = \GraystackIT\MollieBilling\Testing\TestBillable::create(['name' => 'Trial Start Co', 'email' => uniqid('tstart-').'@x.test', 'billing_country' => 'DE']);
    $billable->forceFill(['mollie_customer_id' => 'cst_longrun'])->save();
    LongRunWorld::$current = new LongRunWorld($billable, 'monthly');

    // A trial collects nothing: the checkout is a €0 mandate payment carrying the plan.
    $result = app(StartSubscriptionCheckout::class)->handle($billable->fresh(), [
        'plan_code' => 'trialplan', 'interval' => 'monthly', 'addon_codes' => [], 'extra_seats' => 0, 'amount_gross' => 3451,
    ]);

    $request = collect(LongRunWorld::$current->requests)->last(fn ($r) => $r instanceof \Mollie\Api\Http\Requests\CreatePaymentRequest);

    expect($result['payment_id'])->not->toBe('')
        ->and(longRunRequestProperty($request, 'amount')->value)->toBe('0.00')
        ->and($events)->toHaveCount(1);

    // The same €0 payment without a subscription behind it is a new payment method.
    app(\GraystackIT\MollieBilling\Services\Billing\StartMandateCheckout::class)->handle($billable->fresh());

    expect($events)->toHaveCount(1);
});
