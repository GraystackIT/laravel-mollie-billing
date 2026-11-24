<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\InvoiceCreated;
use GraystackIT\MollieBilling\Events\SubscriptionActivationFailed;
use GraystackIT\MollieBilling\Events\UnwantedPaymentRefunded;
use GraystackIT\MollieBilling\Jobs\RetrySubscriptionActivationJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Notifications\UnwantedPaymentRefundedNotification;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Webhook\UnwantedPaymentRefund;
use GraystackIT\MollieBilling\Support\ActivationRetry;
use GraystackIT\MollieBilling\Support\BillingAuditEntry;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;

require_once __DIR__.'/Support/RenewalLongRun.php';

/*
 * An activation that does not finish is run again — by the package, not by an operator.
 *
 * A customer has paid (or handed over a mandate) and the subscription is not there:
 * CreateSubscription failed, the process died between the invoice and that call, or the
 * activation was refused. Each of these used to send an operator a mail and stop. Now a
 * job replays the payment through the ordinary two-phase activation, with a backoff; only
 * when every attempt is spent is the payment refunded and an operator told.
 */

/** Coupon pricing that fails, while the coupon itself stays redeemable. */
class ActivationRetryFailingPricing extends \GraystackIT\MollieBilling\Services\Webhook\FirstPaymentCouponPricing
{
    public function price(
        \GraystackIT\MollieBilling\Contracts\Billable $billable,
        string $couponCode,
        string $planCode,
        string $interval,
        array $addonCodes,
        int $extraSeats,
        bool $trialStarting = false,
    ): ?array {
        return null;
    }
}

function activationRetryTrialWorld(string $at, ?string $couponCode = null): LongRunWorld
{
    Carbon::setTestNow(Carbon::parse($at, 'UTC'));
    $billable = TestBillable::create(['name' => 'Trial Co', 'email' => uniqid('trial-').'@x.test', 'billing_country' => 'DE']);
    $billable->forceFill(['mollie_customer_id' => 'cst_longrun'])->save();

    $world = LongRunWorld::$current = new LongRunWorld($billable, 'monthly');
    $world->payments['tr_lr_trial'] = LongRunPayment::make([
        'id' => 'tr_lr_trial', 'status' => 'paid',
        'amount' => (object) ['value' => '0.00', 'currency' => 'EUR'],
        'createdAt' => now()->toIso8601String(), 'paidAt' => now()->toIso8601String(),
        'subscriptionId' => null, 'customerId' => 'cst_longrun', 'mandateId' => 'mdt_longrun', 'countryCode' => 'DE',
        'metadata' => array_filter([
            'type' => 'mandate_only',
            'pending_subscription_plan_code' => 'pro',
            'pending_subscription_interval' => 'monthly',
            'pending_subscription_trial_days' => 14,
            'pending_subscription_coupon_code' => $couponCode,
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ], static fn ($v): bool => $v !== null),
    ]);

    return $world;
}

beforeEach(function (): void {
    longRunBoot();
    Notification::fake();
    // A real queue: on `sync` the retry is left to the sweep (ActivationRetrySyncQueueTest).
    config()->set('queue.default', 'database');
    Bus::fake([RetrySubscriptionActivationJob::class]);
    MollieBilling::notifyAdminUsing(fn (): array => [new AnonymousNotifiable]);
});

afterEach(function (): void {
    Carbon::setTestNow();
    MollieBilling::notifyAdminUsing(fn (): array => []);
});

function activationRetryJob(LongRunWorld $world, string $paymentId = 'tr_lr_first'): RetrySubscriptionActivationJob
{
    return new RetrySubscriptionActivationJob($paymentId, TestBillable::class, $world->billable->getKey());
}

function activationRetryRun(LongRunWorld $world, string $at, string $paymentId = 'tr_lr_first'): void
{
    Carbon::setTestNow(Carbon::parse($at, 'UTC'));
    app()->call([activationRetryJob($world, $paymentId), 'handle']);
    $world->billable = $world->billable->fresh();
}

function activationRetryCreateCalls(LongRunWorld $world): int
{
    return collect($world->requests)->filter(fn ($r) => $r instanceof CreateSubscriptionRequest)->count();
}

it('activates on the retry when creating the Mollie subscription failed the first time', function (array $attributes, string $gross, int $vat): void {
    $failures = 0;
    Event::listen(SubscriptionActivationFailed::class, function () use (&$failures): void {
        $failures++;
    });

    $world = longRunStart('monthly', '2027-01-15 10:00:00', $gross, [], $attributes, expectActivated: false,
        beforeDelivery: fn (LongRunWorld $w) => $w->refuse[CreateSubscriptionRequest::class] = 1);

    // The payment is booked, nothing else is — and nobody was mailed about it.
    expect($world->lastInvoice)->not->toBeNull()
        ->and((int) $world->lastInvoice->amount_vat)->toBe($vat)
        ->and($world->billable->hasAccessibleBillingSubscription())->toBeFalse()
        ->and($world->billable->getBillingSubscriptionMeta()[ActivationRetry::META_KEY] ?? [])->toHaveKey('tr_lr_first')
        ->and($failures)->toBe(1);

    Bus::assertDispatched(RetrySubscriptionActivationJob::class, fn (RetrySubscriptionActivationJob $job): bool => $job->paymentId === 'tr_lr_first');
    Notification::assertSentToTimes(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class, 0);

    // A minute later Mollie answers again.
    activationRetryRun($world, '2027-01-15 10:01:00');

    expect($world->billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and($world->billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? null)->toBe('sub_longrun')
        ->and($world->billable->getBillingSubscriptionMeta())->not->toHaveKey(ActivationRetry::META_KEY)
        ->and($world->subscriptionsCreated)->toBe(1)
        ->and($world->heldValue)->toBe($gross)
        ->and(BillingInvoice::query()->count())->toBe(1)
        ->and($world->billable->remainingBillingQuota('Tokens'))->toBe(100)
        ->and($failures)->toBe(1);

    // A second run of the job, and a re-delivery of the webhook, change nothing.
    activationRetryRun($world, '2027-01-15 10:06:00');
    longRunDeliver($world, $world->payments['tr_lr_first'], '2027-01-15 10:07:00');

    expect($world->subscriptionsCreated)->toBe(1)
        ->and(BillingInvoice::query()->count())->toBe(1)
        ->and($world->billable->remainingBillingQuota('Tokens'))->toBe(100);

    // And it is an ordinary subscription from here on.
    longRunAssertRenewal($world, longRunRenew($world), $gross);
    longRunAssertRenewal($world, longRunRenew($world), $gross);

    Notification::assertSentToTimes(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class, 0);
    Notification::assertSentOnDemandTimes(UnwantedPaymentRefundedNotification::class, 0);
})->with(longRunVatCases());

it('adopts the subscription Mollie did create when only its answer was lost', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51', [], [], expectActivated: false,
        beforeDelivery: fn (LongRunWorld $w) => $w->loseAnswer[CreateSubscriptionRequest::class] = 1);

    expect($world->subscriptionsCreated)->toBe(1)
        ->and($world->billable->hasAccessibleBillingSubscription())->toBeFalse();

    activationRetryRun($world, '2027-01-15 10:01:00');

    // Activated on the subscription that exists. A second one would charge the customer
    // twice every period, with nothing local pointing at the first.
    expect($world->billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and($world->billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? null)->toBe('sub_longrun')
        ->and($world->subscriptionsCreated)->toBe(1)
        ->and(activationRetryCreateCalls($world))->toBe(1);

    longRunAssertRenewal($world, longRunRenew($world), '34.51');
});

it('keeps the trial end Mollie was given when a retried trial activation adopts its subscription', function (): void {
    Carbon::setTestNow(Carbon::parse('2027-01-10 10:00:00', 'UTC'));
    $billable = TestBillable::create(['name' => 'Trial Co', 'email' => uniqid('trial-').'@x.test', 'billing_country' => 'DE']);
    $billable->forceFill(['mollie_customer_id' => 'cst_longrun'])->save();

    $world = LongRunWorld::$current = new LongRunWorld($billable, 'monthly');
    $world->loseAnswer[CreateSubscriptionRequest::class] = 1;
    $world->payments['tr_lr_trial'] = LongRunPayment::make([
        'id' => 'tr_lr_trial', 'status' => 'paid',
        'amount' => (object) ['value' => '0.00', 'currency' => 'EUR'],
        'createdAt' => now()->toIso8601String(), 'paidAt' => now()->toIso8601String(),
        'subscriptionId' => null, 'customerId' => 'cst_longrun', 'mandateId' => 'mdt_longrun', 'countryCode' => 'DE',
        'metadata' => [
            'type' => 'mandate_only',
            'pending_subscription_plan_code' => 'pro',
            'pending_subscription_interval' => 'monthly',
            'pending_subscription_trial_days' => 14,
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ]);

    // Mollie created the subscription — trial end 24 January — but its answer was lost.
    $this->postJson(route('billing.webhook'), ['id' => 'tr_lr_trial'])->assertStatus(200);
    expect($world->subscriptionsCreated)->toBe(1);

    // The retry runs the next day and adopts that subscription. Its trial end is the one
    // Mollie holds, not one read off the retry's clock.
    activationRetryRun($world, '2027-01-11 09:00:00', 'tr_lr_trial');

    expect($world->billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Trial)
        ->and($world->subscriptionsCreated)->toBe(1)
        ->and($world->billable->trial_ends_at->toDateString())->toBe('2027-01-24');
});

it('keeps a trial coupon granted by the first attempt when its pricing fails on the retry', function (): void {
    app(CouponService::class)->create([
        'code' => 'HALF',
        'name' => 'Half off the first charge',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
    ]);

    Carbon::setTestNow(Carbon::parse('2027-01-10 10:00:00', 'UTC'));
    $billable = TestBillable::create(['name' => 'Trial Co', 'email' => uniqid('trial-').'@x.test', 'billing_country' => 'DE']);
    $billable->forceFill(['mollie_customer_id' => 'cst_longrun'])->save();

    $world = LongRunWorld::$current = new LongRunWorld($billable, 'monthly');
    $world->refuse[CreateSubscriptionRequest::class] = 1;
    $world->payments['tr_lr_trial'] = LongRunPayment::make([
        'id' => 'tr_lr_trial', 'status' => 'paid',
        'amount' => (object) ['value' => '0.00', 'currency' => 'EUR'],
        'createdAt' => now()->toIso8601String(), 'paidAt' => now()->toIso8601String(),
        'subscriptionId' => null, 'customerId' => 'cst_longrun', 'mandateId' => 'mdt_longrun', 'countryCode' => 'DE',
        'metadata' => [
            'type' => 'mandate_only',
            'pending_subscription_plan_code' => 'pro',
            'pending_subscription_interval' => 'monthly',
            'pending_subscription_trial_days' => 14,
            'pending_subscription_coupon_code' => 'HALF',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ]);

    // The first attempt grants the coupon (its marker) and then fails at Mollie.
    $this->postJson(route('billing.webhook'), ['id' => 'tr_lr_trial'])->assertStatus(200);
    expect($billable->fresh()->getBillingSubscriptionMeta())->toHaveKey('active_recurring_coupon');

    // Switched off before the retry: pricing it again fails. The grant stands, and Mollie
    // must be created at the amount it promises — not at the full price beside it.
    Coupon::query()->where('code', 'HALF')->update(['active' => false]);
    activationRetryRun($world, '2027-01-10 10:05:00', 'tr_lr_trial');

    expect($world->billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Trial)
        ->and($world->billable->getBillingSubscriptionMeta())->toHaveKey('active_recurring_coupon')
        ->and($world->heldValue)->toBe('17.25');
});

it('reuses a recorded trial end that is still ahead later the same day', function (): void {
    $world = activationRetryTrialWorld('2027-01-10 10:00:00');
    $world->loseAnswer[CreateSubscriptionRequest::class] = 1;

    $this->postJson(route('billing.webhook'), ['id' => 'tr_lr_trial'])->assertStatus(200);

    // The retry runs on the trial end's own day, an hour before it: still ahead, still the
    // date Mollie holds.
    activationRetryRun($world, '2027-01-24 09:00:00', 'tr_lr_trial');

    expect($world->subscriptionsCreated)->toBe(1)
        ->and($world->billable->trial_ends_at->toIso8601String())->toBe('2027-01-24T10:00:00+00:00');
});

it('counts the grant a retry keeps when its coupon pricing fails', function (): void {
    app(CouponService::class)->create([
        'code' => 'HALF',
        'name' => 'Half off the first charge',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
    ]);

    $world = activationRetryTrialWorld('2027-01-10 10:00:00', 'HALF');
    $world->refuse[CreateSubscriptionRequest::class] = 1;

    $this->postJson(route('billing.webhook'), ['id' => 'tr_lr_trial'])->assertStatus(200);
    expect(\GraystackIT\MollieBilling\Models\CouponRedemption::query()->count())->toBe(0);

    // Pricing fails on the retry for a reason of its own; the coupon is still good. The
    // grant the first attempt made is kept — and counted, as a priced one would be.
    $this->app->bind(\GraystackIT\MollieBilling\Services\Webhook\FirstPaymentCouponPricing::class, ActivationRetryFailingPricing::class);
    activationRetryRun($world, '2027-01-10 10:05:00', 'tr_lr_trial');

    expect($world->heldValue)->toBe('17.25')
        ->and(\GraystackIT\MollieBilling\Models\CouponRedemption::query()->count())->toBe(1);
});

it('resumes an activation that died between the invoice and the Mollie subscription', function (): void {
    // An app listener on InvoiceCreated throws — events are the documented extension seam.
    $thrown = false;
    Event::listen(InvoiceCreated::class, function () use (&$thrown): void {
        if (! $thrown) {
            $thrown = true;

            throw new RuntimeException('CRM is down');
        }
    });

    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51', [], [], expectActivated: false);

    expect($world->lastInvoice)->not->toBeNull()
        ->and($world->subscriptionsCreated)->toBe(0);

    // Mollie re-delivers the webhook it got a 500 for: the invoice exists, the subscription does not.
    longRunDeliver($world, $world->payments['tr_lr_first'], '2027-01-15 10:02:00');

    Bus::assertDispatched(RetrySubscriptionActivationJob::class);
    Notification::assertSentToTimes(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class, 0);
    expect($world->billable->hasAccessibleBillingSubscription())->toBeFalse();

    activationRetryRun($world, '2027-01-15 10:03:00');

    expect($world->billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and($world->subscriptionsCreated)->toBe(1)
        ->and(BillingInvoice::query()->count())->toBe(1)
        ->and($world->billable->remainingBillingQuota('Tokens'))->toBe(100);

    longRunAssertRenewal($world, longRunRenew($world), '34.51');
});

it('refunds the payment and tells customer and operator once every retry is spent', function (array $attributes, string $gross, int $vat): void {
    $refunded = [];
    Event::listen(UnwantedPaymentRefunded::class, function (UnwantedPaymentRefunded $e) use (&$refunded): void {
        $refunded[] = $e;
    });

    $world = longRunStart('monthly', '2027-01-15 10:00:00', $gross, [], $attributes, expectActivated: false,
        beforeDelivery: fn (LongRunWorld $w) => $w->refuse[CreateSubscriptionRequest::class] = PHP_INT_MAX);

    // Every attempt fails; the queue gives up after the last one.
    foreach (['10:01', '10:06', '10:21', '11:21', '17:21'] as $time) {
        expect(fn () => activationRetryRun($world, '2027-01-15 '.$time.':00'))->toThrow(RuntimeException::class);
    }
    Notification::assertSentToTimes(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class, 0);

    Carbon::setTestNow(Carbon::parse('2027-01-15 17:22:00', 'UTC'));
    activationRetryJob($world)->failed(new RuntimeException('Mollie is unavailable'));

    $invoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_lr_first')->sole();
    $creditNote = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->sole();
    $refund = collect($world->requests)->last(fn ($r) => $r instanceof CreatePaymentRefundRequest);

    expect((int) $creditNote->amount_gross)->toBe(-(int) $invoice->amount_gross)
        ->and((int) $creditNote->amount_net)->toBe(-(int) $invoice->amount_net)
        ->and((int) $creditNote->amount_vat)->toBe(-$vat)
        ->and(longRunRequestProperty($refund, 'amount')->value)->toBe($gross)
        ->and($world->billable->fresh()->hasAccessibleBillingSubscription())->toBeFalse()
        ->and($world->billable->fresh()->getBillingSubscriptionMeta())->not->toHaveKey(ActivationRetry::META_KEY)
        ->and($refunded)->toHaveCount(1)
        ->and($refunded[0]->reason)->toBe(UnwantedPaymentRefund::REASON_ACTIVATION_FAILED);

    expect(BillingAuditEntry::model()::query()->pluck('event')->all())
        ->toContain('unwanted_payment_refunded')
        ->toContain('subscription_activation_failed');

    Notification::assertSentOnDemand(
        UnwantedPaymentRefundedNotification::class,
        fn (UnwantedPaymentRefundedNotification $n): bool => $n->reason === UnwantedPaymentRefund::REASON_ACTIVATION_FAILED
            && $n->amountGross === (int) $invoice->amount_gross,
    );
    Notification::assertSentTo(
        new AnonymousNotifiable,
        AdminPlanChangeFailedNotification::class,
        fn (AdminPlanChangeFailedNotification $n): bool => str_contains($n->reason, 'refunded automatically'),
    );

    // Whatever arrives afterwards — the webhook again, the job again — starts nothing new.
    longRunDeliver($world, $world->payments['tr_lr_first'], '2027-01-16 09:00:00');
    activationRetryJob($world)->failed(new RuntimeException('again'));

    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(1)
        ->and(collect($world->requests)->filter(fn ($r) => $r instanceof CreatePaymentRefundRequest)->count())->toBe(1)
        ->and($world->billable->fresh()->getBillingSubscriptionMeta())->not->toHaveKey(ActivationRetry::META_KEY);

    Notification::assertSentToTimes(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class, 1);
    Notification::assertSentOnDemandTimes(UnwantedPaymentRefundedNotification::class, 1);
})->with(longRunVatCases());

it('retries a mandate-only activation that was refused, and activates once the reason is gone', function (): void {
    app(CouponService::class)->create([
        'code' => 'FREE3',
        'name' => 'Three periods free',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
        'max_redemptions_per_billable' => 3,
    ]);

    // Between checkout and webhook an operator switches the coupon off.
    Coupon::query()->where('code', 'FREE3')->update(['active' => false]);

    Carbon::setTestNow(Carbon::parse('2027-01-10 10:00:00', 'UTC'));
    $billable = TestBillable::create(['name' => 'Mandate Co', 'email' => uniqid('mandate-').'@x.test', 'billing_country' => 'DE']);
    $billable->forceFill(['mollie_customer_id' => 'cst_longrun'])->save();

    $world = LongRunWorld::$current = new LongRunWorld($billable, 'monthly');
    $world->payments['tr_lr_mandate'] = LongRunPayment::make([
        'id' => 'tr_lr_mandate', 'status' => 'paid',
        'amount' => (object) ['value' => '0.00', 'currency' => 'EUR'],
        'createdAt' => now()->toIso8601String(), 'paidAt' => now()->toIso8601String(),
        'subscriptionId' => null, 'customerId' => 'cst_longrun', 'mandateId' => 'mdt_longrun', 'countryCode' => 'DE',
        'metadata' => [
            'type' => 'mandate_only',
            'pending_subscription_plan_code' => 'pro',
            'pending_subscription_interval' => 'monthly',
            'pending_subscription_coupon_code' => 'FREE3',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ]);

    $this->postJson(route('billing.webhook'), ['id' => 'tr_lr_mandate'])->assertStatus(200);
    $world->billable = $billable->fresh();

    // Mandate saved, nothing activated, a retry queued — and no operator mail.
    expect($world->billable->mollie_mandate_id)->toBe('mdt_longrun')
        ->and($world->billable->hasAccessibleBillingSubscription())->toBeFalse()
        ->and($world->billable->getBillingSubscriptionMeta()[ActivationRetry::META_KEY] ?? [])->toHaveKey('tr_lr_mandate');
    Bus::assertDispatched(RetrySubscriptionActivationJob::class, fn (RetrySubscriptionActivationJob $job): bool => $job->paymentId === 'tr_lr_mandate');
    Notification::assertSentToTimes(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class, 0);

    // First retry: still switched off.
    expect(fn () => activationRetryRun($world, '2027-01-10 10:01:00', 'tr_lr_mandate'))->toThrow(RuntimeException::class);

    // The coupon is switched on again; the next retry activates.
    Coupon::query()->where('code', 'FREE3')->update(['active' => true]);
    activationRetryRun($world, '2027-01-10 10:06:00', 'tr_lr_mandate');

    expect($world->billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and($world->subscriptionsCreated)->toBe(1)
        ->and($world->billable->getBillingSubscriptionMeta())->not->toHaveKey(ActivationRetry::META_KEY);
    Notification::assertSentToTimes(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class, 0);
});

it('tells an operator about a mandate without a subscription only when the retries are spent', function (): void {
    app(CouponService::class)->create([
        'code' => 'GONE',
        'name' => 'Gone',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
        'max_redemptions_per_billable' => 3,
    ]);
    Coupon::query()->where('code', 'GONE')->update(['active' => false]);

    Carbon::setTestNow(Carbon::parse('2027-01-10 10:00:00', 'UTC'));
    $billable = TestBillable::create(['name' => 'Mandate Co', 'email' => uniqid('mandate-').'@x.test', 'billing_country' => 'DE']);
    $billable->forceFill(['mollie_customer_id' => 'cst_longrun'])->save();

    $world = LongRunWorld::$current = new LongRunWorld($billable, 'monthly');
    $world->payments['tr_lr_mandate'] = LongRunPayment::make([
        'id' => 'tr_lr_mandate', 'status' => 'paid',
        'amount' => (object) ['value' => '0.00', 'currency' => 'EUR'],
        'createdAt' => now()->toIso8601String(), 'paidAt' => now()->toIso8601String(),
        'subscriptionId' => null, 'customerId' => 'cst_longrun', 'mandateId' => 'mdt_longrun', 'countryCode' => 'DE',
        'metadata' => [
            'type' => 'mandate_only',
            'pending_subscription_plan_code' => 'pro',
            'pending_subscription_interval' => 'monthly',
            'pending_subscription_coupon_code' => 'GONE',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ]);

    $this->postJson(route('billing.webhook'), ['id' => 'tr_lr_mandate'])->assertStatus(200);
    $world->billable = $billable->fresh();

    activationRetryJob($world, 'tr_lr_mandate')->failed(new RuntimeException('still refused'));

    // Nothing was collected, so there is nothing to refund — the operator is the last resort.
    expect(BillingInvoice::query()->count())->toBe(0);
    Notification::assertSentOnDemandTimes(UnwantedPaymentRefundedNotification::class, 0);
    Notification::assertSentTo(
        new AnonymousNotifiable,
        AdminPlanChangeFailedNotification::class,
        fn (AdminPlanChangeFailedNotification $n): bool => str_contains($n->reason, 'mandate was captured') && str_contains($n->reason, 'after every retry'),
    );
    Notification::assertSentToTimes(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class, 1);
});
