<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Jobs\ProcessTrialLifecycleJob;
use GraystackIT\MollieBilling\Jobs\RetrySubscriptionActivationJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Notifications;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Bus;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;

require_once __DIR__.'/Support.php';

/*
 * Every mail the package sends, sent by the thing that really causes it. Each case
 * asserts who receives it and what it says — in German and in English, with the amount
 * and the date held against the data they come from.
 *
 * Customer mails go to whoever `notifyBillingAdminsUsing()` names (here: the billable),
 * operator mails to `notifyAdminUsing()`.
 */

beforeEach(function (): void {
    longRunBoot();
    lifecycleCatalogExtras();
    LifecycleMailbox::open();
    config()->set('mollie-billing.company_name', 'Acme Cloud');
});

afterEach(function (): void {
    Carbon::setTestNow();
    LifecycleMailbox::close();
});

it('sends InvoiceAvailableNotification to the customer when a payment is invoiced', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');

    [$mail, $to] = lifecycleSentMail(Notifications\InvoiceAvailableNotification::class);

    expect($to->getKey())->toBe($world->billable->getKey())
        ->and($mail->invoice->getKey())->toBe($world->lastInvoice->getKey());

    foreach (['de', 'en'] as $locale) {
        app()->setLocale($locale);
        expect(lifecycleMailText($mail, $to, $locale))
            ->toContain(BillingMoney::format(3451, 'EUR'))
            ->not->toContain('billing::');
    }

    expect(lifecycleMailText($mail, $to, 'de'))->not->toBe(lifecycleMailText($mail, $to, 'en'));
});

it('sends SubscriptionPaymentFailedNotification with the amount and the day the renewal failed', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    LifecycleMailbox::open();

    $failed = longRunCreateCharge($world, status: 'failed');
    longRunDeliver($world, $failed, '2027-02-16 07:00:00');

    [$mail, $to] = lifecycleSentMail(Notifications\SubscriptionPaymentFailedNotification::class);

    expect($to->getKey())->toBe($world->billable->getKey())
        ->and($mail->paymentId)->toBe($failed->id)
        ->and($mail->amountGross)->toBe(3451);

    foreach (['de', 'en'] as $locale) {
        app()->setLocale($locale);
        expect(lifecycleMailText($mail, $to, $locale))
            ->toContain(BillingMoney::format(3451, 'EUR'))
            ->toContain('Acme Cloud')
            ->not->toContain('billing::');
    }

    expect(lifecycleMailText($mail, $to, 'de'))->not->toBe(lifecycleMailText($mail, $to, 'en'));
});

it('sends TrialConvertedNotification with the plan and the first invoice amount', function (): void {
    $world = lifecycleStartTrial('2027-01-10 10:00:00');
    LifecycleMailbox::open();

    $payment = longRunRenew($world);
    $invoice = BillingInvoice::query()->where('mollie_payment_id', $payment->id)->sole();

    [$mail, $to] = lifecycleSentMail(Notifications\TrialConvertedNotification::class);

    expect($to->getKey())->toBe($world->billable->getKey())
        ->and($mail->planCode)->toBe('trialplan')
        ->and($mail->amountGross)->toBe((int) $invoice->amount_gross);

    foreach (['de', 'en'] as $locale) {
        app()->setLocale($locale);
        expect(lifecycleMailText($mail, $to, $locale))
            ->toContain('Trial Plan')
            ->toContain(BillingMoney::format((int) $invoice->amount_gross, 'EUR'))
            ->not->toContain('billing::');
    }
});

it('sends TrialEndingSoonNotification the day before a trial ends, naming the day', function (): void {
    $world = lifecycleStartTrial('2027-01-10 10:00:00');
    LifecycleMailbox::open();

    Carbon::setTestNow(Carbon::parse('2027-01-23 02:05:00', 'UTC'));
    app()->call([new ProcessTrialLifecycleJob, 'handle']);

    [$mail, $to] = lifecycleSentMail(Notifications\TrialEndingSoonNotification::class);

    expect($to->getKey())->toBe($world->billable->getKey());

    foreach (['de', 'en'] as $locale) {
        expect(lifecycleMailText($mail, $to, $locale))
            ->toContain('2027-01-24')
            ->toContain('Acme Cloud')
            ->not->toContain('billing::');
    }

    expect(lifecycleMailText($mail, $to, 'de'))->not->toBe(lifecycleMailText($mail, $to, 'en'));
});

it('sends TrialExpiredNotification when a trial lapses, naming the day it ended', function (): void {
    $world = lifecycleStartTrial('2027-01-10 10:00:00');
    LifecycleMailbox::open();

    Carbon::setTestNow(Carbon::parse('2027-01-24 10:00:00', 'UTC')->addDays(ProcessTrialLifecycleJob::FIRST_CHARGE_GRACE_DAYS + 1));
    app()->call([new ProcessTrialLifecycleJob, 'handle']);

    [$mail, $to] = lifecycleSentMail(Notifications\TrialExpiredNotification::class);

    expect($to->getKey())->toBe($world->billable->getKey());

    foreach (['de', 'en'] as $locale) {
        expect(lifecycleMailText($mail, $to, $locale))
            ->toContain('2027-01-24')
            ->not->toContain('billing::');
    }
});

it('sends SubscriptionCancelledNotification with the day access ends', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    LifecycleMailbox::open();

    Carbon::setTestNow(Carbon::parse('2027-01-20 09:00:00', 'UTC'));
    $world->billable->fresh()->cancelBillingSubscription(false);

    [$mail, $to] = lifecycleSentMail(Notifications\SubscriptionCancelledNotification::class);

    expect($to->getKey())->toBe($world->billable->getKey());

    foreach (['de', 'en'] as $locale) {
        expect(lifecycleMailText($mail, $to, $locale))
            ->toContain('2027-02-15')
            ->not->toContain('billing::');
    }
});

it('sends RefundProcessedNotification with the refunded amount', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    LifecycleMailbox::open();

    $creditNote = app(RefundInvoiceService::class)->refundPartially($world->lastInvoice, 1000, RefundReasonCode::Goodwill, 'sorry');

    [$mail, $to] = lifecycleSentMail(Notifications\RefundProcessedNotification::class);

    expect($to->getKey())->toBe($world->billable->getKey())
        ->and($mail->creditNote->getKey())->toBe($creditNote->getKey());

    foreach (['de', 'en'] as $locale) {
        app()->setLocale($locale);
        expect(lifecycleMailText($mail, $to, $locale))
            ->toContain(BillingMoney::format(1190, 'EUR'))
            ->not->toContain('billing::');
    }
});

it('sends AdminRefundFailedNotification to the operator when Mollie refuses a refund', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    LifecycleMailbox::open();
    $world->refuse[CreatePaymentRefundRequest::class] = 1;

    expect(fn () => app(RefundInvoiceService::class)->refundPartially($world->lastInvoice, 1000, RefundReasonCode::Goodwill, 'sorry'))
        ->toThrow(RuntimeException::class);

    [$mail, $to] = lifecycleSentMail(Notifications\AdminRefundFailedNotification::class);

    expect($to->getKey())->toBe(LifecycleMailbox::$operator->getKey())
        ->and(lifecycleMailText($mail, $to, 'en'))->toContain((string) $world->lastInvoice->serial_number)
        ->and(lifecycleMailText($mail, $to, 'en'))->toContain('Mollie is unavailable');

    // Nothing was booked and the customer was told nothing.
    \Illuminate\Support\Facades\Notification::assertNothingSentTo($world->billable);
});

it('sends PlanChangeFailedNotification to the customer when the charge of a plan change fails', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');

    Carbon::setTestNow(Carbon::parse('2027-01-20 09:00:00', 'UTC'));
    app(UpdateSubscription::class)->update($world->billable->fresh(), ['plan_code' => 'team', 'interval' => 'monthly']);
    LifecycleMailbox::open();

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

    [$mail, $to] = lifecycleSentMail(Notifications\PlanChangeFailedNotification::class);

    expect($to->getKey())->toBe($world->billable->getKey())
        ->and($mail->paymentId)->toBe($paymentId);

    foreach (['de', 'en'] as $locale) {
        expect(lifecycleMailText($mail, $to, $locale))->not->toContain('billing::');
    }

    expect(lifecycleMailText($mail, $to, 'de'))->not->toBe(lifecycleMailText($mail, $to, 'en'));
});

it('sends AdminPlanChangeFailedNotification to the operator when an activation is given up', function (): void {
    Bus::fake([RetrySubscriptionActivationJob::class]);

    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51', [], [], expectActivated: false,
        beforeDelivery: fn (LongRunWorld $w) => $w->refuse[CreateSubscriptionRequest::class] = PHP_INT_MAX);
    LifecycleMailbox::open();

    (new RetrySubscriptionActivationJob('tr_lr_first', TestBillable::class, $world->billable->getKey()))
        ->failed(new RuntimeException('Mollie is unavailable'));

    [$mail, $to] = lifecycleSentMail(Notifications\AdminPlanChangeFailedNotification::class);

    expect($to->getKey())->toBe(LifecycleMailbox::$operator->getKey())
        ->and($mail->reason)->toContain('refunded automatically')
        ->and($mail->context['payment_id'])->toBe('tr_lr_first')
        ->and($mail->context['amount_gross'])->toBe(3451)
        ->and(lifecycleMailText($mail, $to, 'en'))->toContain('refunded automatically');
});

it('sends UnwantedPaymentRefundedNotification to the customer with the amount and the reason', function (): void {
    Bus::fake([RetrySubscriptionActivationJob::class]);

    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51', [], [], expectActivated: false,
        beforeDelivery: fn (LongRunWorld $w) => $w->refuse[CreateSubscriptionRequest::class] = PHP_INT_MAX);
    LifecycleMailbox::open();

    (new RetrySubscriptionActivationJob('tr_lr_first', TestBillable::class, $world->billable->getKey()))
        ->failed(new RuntimeException('Mollie is unavailable'));

    [$mail, $to] = lifecycleSentMail(Notifications\UnwantedPaymentRefundedNotification::class);

    expect($to->getKey())->toBe($world->billable->getKey())
        ->and($mail->amountGross)->toBe(3451)
        ->and($mail->reason)->toBe('activation_failed');

    app()->setLocale('de');
    expect(lifecycleMailText($mail, $to, 'de'))
        ->toContain(BillingMoney::format(3451, 'EUR'))
        ->toContain('nicht eingerichtet')
        ->not->toContain('billing::');

    app()->setLocale('en');
    expect(lifecycleMailText($mail, $to, 'en'))
        ->toContain(BillingMoney::format(3451, 'EUR'))
        ->toContain('could not be set up')
        ->not->toContain('billing::');
});

it('sends AdminPaidWithoutBillableNotification to the operator for a payment that names nobody', function (): void {
    Carbon::setTestNow(Carbon::parse('2027-03-01 10:00:00', 'UTC'));
    $world = LongRunWorld::$current = new LongRunWorld(TestBillable::create(['name' => 'Unrelated', 'email' => uniqid('u-').'@x.test']), 'monthly');

    $world->payments['tr_nobody'] = LongRunPayment::make([
        'id' => 'tr_nobody', 'status' => 'paid',
        'amount' => (object) ['value' => '29.00', 'currency' => 'EUR'],
        'createdAt' => now()->toIso8601String(), 'paidAt' => now()->toIso8601String(),
        'subscriptionId' => null, 'customerId' => 'cst_nobody', 'mandateId' => null, 'countryCode' => 'DE',
        'metadata' => ['billable_type' => TestBillable::class, 'billable_id' => '987654'],
    ]);
    longRunDeliver($world, $world->payments['tr_nobody']);

    [$mail, $to] = lifecycleSentMail(Notifications\AdminPaidWithoutBillableNotification::class);

    expect($to->getKey())->toBe(LifecycleMailbox::$operator->getKey())
        ->and($mail->paymentId)->toBe('tr_nobody')
        ->and($mail->amountCents)->toBe(2900)
        ->and(lifecycleMailText($mail, $to, 'en'))->toContain('tr_nobody')
        ->and(lifecycleMailText($mail, $to, 'en'))->toContain('refunded to the customer automatically after 14 days');
});

it('sends CountryMismatchSelfNotification to the customer when the country signals disagree', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    LifecycleMailbox::open();

    $world->billable->forceFill(['tax_country_user' => 'AT', 'tax_country_ip' => 'CH'])->save();
    longRunRenew($world);

    $sent = \Illuminate\Support\Facades\Notification::sent($world->billable, Notifications\CountryMismatchSelfNotification::class);

    expect($sent)->toHaveCount(1);

    foreach (['de', 'en'] as $locale) {
        expect(lifecycleMailText($sent->first(), $world->billable, $locale))->not->toContain('billing::');
    }

    expect(lifecycleMailText($sent->first(), $world->billable, 'de'))->not->toBe(lifecycleMailText($sent->first(), $world->billable, 'en'));
});

it('sends UsageThresholdNotification when usage crosses the warning threshold', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    LifecycleMailbox::open();

    // The shipped threshold is 80 %.
    $world->billable->recordBillingUsage('Tokens', 85);

    [$mail, $to] = lifecycleSentMail(Notifications\UsageThresholdNotification::class);

    expect($to->getKey())->toBe($world->billable->getKey())
        ->and($mail->usageType)->toBe('Tokens')
        ->and($mail->percent)->toBeGreaterThanOrEqual(80);

    foreach (['de', 'en'] as $locale) {
        expect(lifecycleMailText($mail, $to, $locale))
            ->toContain('Tokens')
            ->not->toContain('billing::');
    }
});

it('sends AdminOverageSweepFailedNotification to the operator when the nightly overage pass dies', function (): void {
    // The queue calls failed() once the job is out of attempts.
    (new \GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob)->failed(new RuntimeException('database has gone away'));

    [$mail, $to] = lifecycleSentMail(Notifications\AdminOverageSweepFailedNotification::class);

    expect($to->getKey())->toBe(LifecycleMailbox::$operator->getKey())
        ->and($mail->reason)->toContain('database has gone away')
        ->and(lifecycleMailText($mail, $to, 'en'))->toContain('database has gone away');
});

it('sends CountryMismatchReissueFailedNotification to the customer when the corrected charge fails', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    $world->billable->forceFill(['tax_country_user' => 'AT', 'tax_country_ip' => 'CH'])->save();
    longRunRenew($world);

    $mismatch = \GraystackIT\MollieBilling\Models\BillingCountryMismatch::query()->sole();
    LifecycleMailbox::open();

    // The reissue at the corrected country's VAT is charged — and bounces.
    $world->payments['tr_correction'] = LongRunPayment::make([
        'id' => 'tr_correction', 'status' => 'failed',
        'amount' => (object) ['value' => '34.51', 'currency' => 'EUR'],
        'createdAt' => '2027-02-20T09:00:00+00:00', 'paidAt' => null,
        'details' => (object) ['failureReason' => 'insufficient_funds'],
        'subscriptionId' => null, 'customerId' => 'cst_longrun', 'mandateId' => 'mdt_longrun', 'countryCode' => 'DE',
        'metadata' => [
            'type' => 'country_correction', 'mismatch_id' => $mismatch->id,
            'original_invoice_id' => (int) $world->lastInvoice->id,
            'billable_type' => $world->billable->getMorphClass(), 'billable_id' => (string) $world->billable->getKey(),
        ],
    ]);
    longRunDeliver($world, $world->payments['tr_correction'], '2027-02-21 09:00:00');

    [$mail, $to] = lifecycleSentMail(Notifications\CountryMismatchReissueFailedNotification::class);

    expect($to->getKey())->toBe($world->billable->getKey())
        ->and($mail->reason)->toBe('insufficient_funds')
        ->and($mail->mismatch->getKey())->toBe($mismatch->getKey());

    foreach (['de', 'en'] as $locale) {
        expect(lifecycleMailText($mail, $to, $locale))->not->toContain('billing::');
    }

    expect(lifecycleMailText($mail, $to, 'de'))->not->toBe(lifecycleMailText($mail, $to, 'en'));
});

it('sends UndercollectionChargedNotification with the amount charged on top', function (): void {
    // A renewal that collected 29.00 net where 34.00 was owed: 5.00 net short, 5.95 with
    // German VAT, charged on the mandate and announced to the customer.
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    LifecycleMailbox::open();

    Carbon::setTestNow(Carbon::parse('2027-01-16 09:00:00', 'UTC'));
    $charged = app(\GraystackIT\MollieBilling\Services\Billing\UndercollectionCharge::class)
        ->settle($world->billable->fresh(), $world->lastInvoice, 3400, 2900, 'renewal_created_before_change');

    [$mail, $to] = lifecycleSentMail(Notifications\UndercollectionChargedNotification::class);

    expect($charged)->toBeTrue()
        ->and($to->getKey())->toBe($world->billable->getKey())
        ->and($mail->amountGross)->toBe(595)
        ->and($mail->reason)->toBe('renewal_created_before_change');

    foreach (['de', 'en'] as $locale) {
        app()->setLocale($locale);
        expect(lifecycleMailText($mail, $to, $locale))
            ->toContain(BillingMoney::format(595, 'EUR'))
            ->toContain('Acme Cloud')
            ->not->toContain('billing::');
    }

    expect(lifecycleMailText($mail, $to, 'de'))->not->toBe(lifecycleMailText($mail, $to, 'en'));
});

it('sends ScheduledChangeNotAppliedNotification when a scheduled change cannot be carried out', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');

    Carbon::setTestNow(Carbon::parse('2027-01-20 09:00:00', 'UTC'));
    app(UpdateSubscription::class)->update($world->billable->fresh(), ['plan_code' => 'team', 'interval' => 'monthly', 'apply_at' => 'end_of_period']);

    // By the time it is due, the plan is no longer sold.
    config()->set('mollie-billing-plans.plans.team.hidden', true);
    $plans = config('mollie-billing-plans.plans');
    unset($plans['team']);
    config()->set('mollie-billing-plans.plans', $plans);
    LifecycleMailbox::open();

    Carbon::setTestNow(Carbon::parse('2027-02-15 02:00:00', 'UTC'));
    app(\GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange::class)->apply($world->billable->fresh());

    [$mail, $to] = lifecycleSentMail(Notifications\ScheduledChangeNotAppliedNotification::class);

    expect($to->getKey())->toBe($world->billable->getKey())
        ->and($mail->planCode)->toBe('team')
        ->and($world->billable->fresh()->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and($world->billable->fresh()->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull();

    foreach (['de', 'en'] as $locale) {
        app()->setLocale($locale);
        expect(lifecycleMailText($mail, $to, $locale))
            ->toContain('Acme Cloud')
            ->toContain((string) __('billing::notifications.scheduled_change_not_applied.reasons.'.$mail->reason))
            ->not->toContain('billing::');
    }

    expect(lifecycleMailText($mail, $to, 'de'))->not->toBe(lifecycleMailText($mail, $to, 'en'));
});
