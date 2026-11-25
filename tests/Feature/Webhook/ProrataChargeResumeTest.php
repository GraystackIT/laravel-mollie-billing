<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Jobs\CleanupStalePendingProrataChangeJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Services\Billing\UndercollectionCharge;
use GraystackIT\MollieBilling\Services\Webhook\ProrataChargeHandler;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\ChangeMatrixWorld as World;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/*
 * A prorata charge whose webhook run dies part-way is finished by the next delivery.
 *
 * The invoice is the first thing the run writes, and "an invoice exists for this payment"
 * used to end every later delivery: a run that died after it — a fatal, PHP's time limit,
 * hit in production rendering the e-invoice — left the paid change unapplied for good.
 * Now the steps that are done are recorded, and a re-delivery picks the run up after the
 * last of them. Several of them move money, so the result has to be exactly that of an
 * uninterrupted run: one invoice, one credit, one refund, one PATCH, every event once.
 *
 * Each case crashes once right after the named step (an exception escaping the handler
 * answers 500, as a fatal leaves Mollie without an answer), then lets Mollie re-deliver.
 */

final class CrashingProrataChargeHandler extends ProrataChargeHandler
{
    public static ?string $crashAfter = null;

    /** Dies with the step done but not yet recorded. */
    public static ?string $crashBefore = null;

    protected function markStep(Billable $billable, string $paymentId, array &$done, string $step, array $extra = []): void
    {
        if ($step === self::$crashBefore) {
            self::$crashBefore = null;

            throw new RuntimeException("simulated crash before recording {$step}");
        }

        parent::markStep($billable, $paymentId, $done, $step, $extra);

        if ($step === self::$crashAfter) {
            self::$crashAfter = null;

            throw new RuntimeException("simulated crash after {$step}");
        }
    }
}

final class CrashingUndercollectionCharge extends UndercollectionCharge
{
    public static bool $armed = false;

    public function configurationLeft(Billable $billable, string $planBefore): void
    {
        if (self::$armed) {
            self::$armed = false;

            throw new RuntimeException('simulated crash after switched');
        }

        parent::configurationLeft($billable, $planBefore);
    }
}

beforeEach(function (): void {
    World::boot();
    CrashingProrataChargeHandler::$crashAfter = null;
    CrashingProrataChargeHandler::$crashBefore = null;
    CrashingUndercollectionCharge::$armed = false;
    $this->app->bind(ProrataChargeHandler::class, CrashingProrataChargeHandler::class);

    $this->events = [];
    Event::listen('GraystackIT\\MollieBilling\\Events\\*', function (string $name): void {
        $short = class_basename($name);
        $this->events[$short] = ($this->events[$short] ?? 0) + 1;
    });
});

/** Arrange for the run to die once, right after `$step` (or, for 'switched', between the switch and its events). */
function crashOnceAfter(string $step): void
{
    if ($step !== 'switched') {
        CrashingProrataChargeHandler::$crashAfter = $step === 'none' ? null : $step;

        return;
    }

    // The switch is written together with its progress, in one save; the first thing
    // after it that can die is announcing it.
    CrashingUndercollectionCharge::$armed = true;
    app()->bind(UndercollectionCharge::class, CrashingUndercollectionCharge::class);
}

/** Deliver the pending charge — first into the crash, then again as Mollie would. */
function settleThroughCrash(TestBillable $billable, string $step): void
{
    crashOnceAfter($step);
    // Only what the charge's own deliveries announce.
    test()->events = [];

    if ($step === 'none') {
        World::settle($billable);

        return;
    }

    World::settle($billable, expectHttp: 500);
    expect($billable->refresh()->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null)->not->toBeNull();

    World::settle($billable);
}

/** @return array<string, mixed> */
function resumeOutcome(TestBillable $billable): array
{
    $billable->refresh();

    return [
        'plan' => $billable->getBillingSubscriptionPlanCode(),
        'interval' => $billable->getBillingSubscriptionInterval(),
        'status' => $billable->getBillingSubscriptionStatus()->value,
        'seats' => $billable->getBillingSeatCount(),
        'waiting' => array_values(array_intersect(
            ['pending_prorata_change', 'pending_plan_change', 'prorata_pending_payment_id'],
            array_keys($billable->getBillingSubscriptionMeta()),
        )),
        'invoices' => BillingInvoice::query()->orderBy('id')->get()
            ->map(fn (BillingInvoice $i): array => [$i->invoice_kind->value, (int) $i->amount_gross])->all(),
        'refunds' => array_map(
            fn (array $refunds): array => array_map(fn (object $r): string => $r->amount->value, $refunds),
            World::$refunds,
        ),
        'held' => World::heldGross(),
    ];
}

$steps = ['none', 'invoiced', 'document', 'document_announced', 'announced', 'wallet', 'spanned_renewal', 'refunds', 'switched', 'events', 'coupons', 'patched'];

it('finishes an upgrade whose run died, exactly as an uninterrupted run would', function (string $step): void {
    $billable = World::subscription('none', 'pro', 2);
    World::change($billable, ['plan_code' => 'enterprise'], settle: null);

    settleThroughCrash($billable, $step);

    expect(resumeOutcome($billable))->toBe([
        'plan' => 'enterprise',
        'interval' => 'monthly',
        'status' => 'active',
        'seats' => 2,
        'waiting' => [],
        // The renewal that paid the period, the charge, and the credit note for the
        // unused share of the renewal — each once.
        'invoices' => [['subscription', 6000], ['subscription', 16258], ['refund', -4064]],
        'refunds' => ['tr_cm_renewal_2' => ['40.64']],
        'held' => 24000,
    ])->and($this->events)->toMatchArray([
        'InvoiceCreated' => 1,
        'PaymentSucceeded' => 1,
        'PlanChanged' => 1,
        'SubscriptionUpdated' => 1,
    ]);
})->with($steps);

it('finishes a trial conversion whose run died, crediting the new period once', function (string $step): void {
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.included_usages', ['tokens' => 100]);
    config()->set('mollie-billing-plans.plans.pro.intervals.yearly.included_usages', ['tokens' => 1000]);

    $billable = World::trial('pro', 2);
    World::change($billable, ['interval' => 'yearly'], settle: null);

    settleThroughCrash($billable, $step);

    expect(resumeOutcome($billable))->toBe([
        'plan' => 'pro',
        'interval' => 'yearly',
        // Read from the progress on the resumed run: after the switch the billable is no
        // longer in its trial, and the run still has to treat it as the conversion it is.
        'status' => 'active',
        'seats' => 2,
        'waiting' => [],
        'invoices' => [['subscription', 60000]],
        'refunds' => [],
        'held' => 60000,
    ])->and($billable->getBillingSubscriptionMeta()['trial_converted_at'] ?? null)->not->toBeNull()
        ->and($this->events)->toMatchArray([
            'InvoiceCreated' => 1,
            'PaymentSucceeded' => 1,
            'WalletCredited' => 1,
            'PlanChanged' => 1,
            'SubscriptionUpdated' => 1,
        ]);
})->with($steps);

it('still swallows a re-delivery once the run has finished', function (): void {
    $billable = World::subscription('none', 'pro', 2);
    World::change($billable, ['plan_code' => 'enterprise'], settle: null);
    $paymentId = (string) $billable->refresh()->getBillingSubscriptionMeta()['prorata_pending_payment_id'];
    $value = (string) World::$payments[$paymentId]['value'];

    World::settle($billable);
    $after = resumeOutcome($billable);
    $this->events = [];

    World::deliver($billable, $paymentId, 'paid', $value, World::$payments[$paymentId]['metadata'] ?? ['type' => 'prorata_charge']);

    expect(resumeOutcome($billable))->toBe($after)
        ->and($this->events['PaymentSucceeded'] ?? 0)->toBe(0);
});

it('keeps a converted trial\'s cadence when it dies right after the invoice and again after the switch', function (): void {
    // The first run dies with the invoice written and nothing recorded after it; the
    // second dies once the trial is already over. The third must still treat the charge
    // as the conversion it is — which it can only learn from what was noted before the
    // invoice — and move Mollie's first charge a full period out, as an uninterrupted
    // run does (2026-02-05).
    $billable = World::trial('pro', 2);
    World::change($billable, ['plan_code' => 'enterprise'], settle: null);

    CrashingProrataChargeHandler::$crashBefore = 'invoiced';
    World::settle($billable, expectHttp: 500);
    expect(BillingInvoice::query()->count())->toBe(1);

    crashOnceAfter('switched');
    World::settle($billable, expectHttp: 500);
    expect($billable->refresh()->getBillingSubscriptionStatus()->value)->toBe('active');

    World::settle($billable);

    expect((string) World::$heldStartDate)->toBe('2026-02-05')
        ->and(resumeOutcome($billable)['waiting'])->toBe([])
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('enterprise')
        ->and(BillingInvoice::query()->count())->toBe(1);
});

it('does not resume a run when a renewal was booked after it died, and tells the operators', function (): void {
    // The refund for the old period is out; then the old monthly cadence renews before
    // Mollie re-delivers. Everything the finished steps did was for the period that has
    // just ended — carrying on would book the switch on top of a renewal nobody credits.
    Notification::fake();
    MollieBilling::notifyAdminUsing(fn (): array => [new AnonymousNotifiable]);

    $billable = World::subscription('none', 'pro', 2);
    World::change($billable, ['interval' => 'yearly'], settle: null);

    crashOnceAfter('refunds');
    World::settle($billable, expectHttp: 500);
    World::renew($billable);
    $afterRenewal = resumeOutcome($billable);

    World::settle($billable);

    expect(resumeOutcome($billable))->toBe($afterRenewal)
        ->and($afterRenewal['interval'])->toBe('monthly')
        ->and($afterRenewal['waiting'])->toContain('pending_prorata_change');
    Notification::assertSentToTimes(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class, 1);

    // A later delivery of the same payment does not tell them again…
    World::settle($billable);
    Notification::assertSentToTimes(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class, 1);

    // …and neither does the daily clean-up, which unblocks the customer: the payment is
    // booked and reported, the change is not coming.
    $paymentId = (string) $billable->refresh()->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'];
    World::$paymentStatus[$paymentId] = 'paid';
    CleanupStalePendingProrataChangeJob::dispatchSync();

    expect(resumeOutcome($billable)['waiting'])->toBe([])
        ->and($billable->getBillingSubscriptionMeta()['orphaned_prorata_payment']['reason'] ?? null)
        ->toBe('charge paid and booked, but its plan change was never completed');
    Notification::assertSentToTimes(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class, 1);
});

/** The pending charge's payment id and what Mollie would deliver for it. */
function pendingCharge(TestBillable $billable): array
{
    $paymentId = (string) $billable->refresh()->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'];

    return [$paymentId, (string) World::$payments[$paymentId]['value'], World::$payments[$paymentId]['metadata'] ?? ['type' => 'prorata_charge']];
}

function operatorsTold(): void
{
    Notification::fake();
    MollieBilling::notifyAdminUsing(fn (): array => [new AnonymousNotifiable]);
}

it('does not run a change again from the start when its run recorded no steps, and tells the operators', function (): void {
    // A run on the version before steps were recorded: invoice written, refund lines paid
    // out, then it died. Resumed from nothing, the refunds would be paid a second time.
    operatorsTold();
    $billable = World::subscription('none', 'pro', 2);
    World::change($billable, ['plan_code' => 'enterprise'], settle: null);

    crashOnceAfter('refunds');
    World::settle($billable, expectHttp: 500);

    $meta = $billable->refresh()->getBillingSubscriptionMeta();
    unset($meta['pending_prorata_change']['progress']);
    $billable->forceFill(['subscription_meta' => $meta])->save();
    $before = resumeOutcome($billable);

    World::settle($billable);
    World::settle($billable);

    expect(resumeOutcome($billable))->toBe($before)
        ->and($before['refunds'])->toBe(['tr_cm_renewal_2' => ['40.64']])
        ->and($before['waiting'])->toContain('pending_prorata_change');
    Notification::assertSentToTimes(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class, 1);
    Notification::assertSentTo(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class,
        fn (AdminPlanChangeFailedNotification $n): bool => $n->context['why'] === 'no_progress');
});

it('leaves a run waiting to be resumed to the next delivery, even when its change is days old', function (): void {
    // With SEPA the change is days old when its webhook first arrives; the clean-up keys on
    // that age and dropped the change — the re-delivery then skipped the payment.
    $billable = World::subscription('none', 'pro', 2);
    World::change($billable, ['plan_code' => 'enterprise'], settle: null);
    [$paymentId] = pendingCharge($billable);

    $this->travel(3)->days();
    crashOnceAfter('invoiced');
    World::settle($billable, expectHttp: 500);

    World::$paymentStatus[$paymentId] = 'paid';
    CleanupStalePendingProrataChangeJob::dispatchSync();
    expect(resumeOutcome($billable)['waiting'])->toContain('pending_prorata_change');

    World::settle($billable);

    expect(resumeOutcome($billable)['plan'])->toBe('enterprise')
        ->and(resumeOutcome($billable)['waiting'])->toBe([])
        ->and(resumeOutcome($billable)['held'])->toBe(24000);
});

it('drops a run nobody resumed once the window is over, and says the payment is booked', function (): void {
    operatorsTold();
    $billable = World::subscription('none', 'pro', 2);
    World::change($billable, ['plan_code' => 'enterprise'], settle: null);
    [$paymentId] = pendingCharge($billable);

    crashOnceAfter('switched');
    World::settle($billable, expectHttp: 500);

    $this->travel(CleanupStalePendingProrataChangeJob::RESUME_WINDOW_HOURS + 1)->hours();
    World::$paymentStatus[$paymentId] = 'paid';
    CleanupStalePendingProrataChangeJob::dispatchSync();

    expect(resumeOutcome($billable)['waiting'])->toBe([]);
    // Past the switch the top-level `pending_plan_change` is gone; what was intended is
    // still named, from the progress.
    Notification::assertSentTo(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class,
        fn (AdminPlanChangeFailedNotification $n): bool => str_contains($n->reason, 'its invoice exists')
            && ($n->context['intended_plan_change']['plan_code'] ?? null) === 'enterprise'
            && in_array('switched', $n->context['completed_steps'], true));
});

it('resumes a change that named no payment, once the payment it was accepted for is written in', function (): void {
    $billable = World::subscription('none', 'pro', 2);
    World::change($billable, ['plan_code' => 'enterprise'], settle: null);
    [$paymentId, $value, $metadata] = pendingCharge($billable);

    $meta = $billable->getBillingSubscriptionMeta();
    $meta['pending_prorata_change']['charge_payment_id'] = '';
    $billable->forceFill(['subscription_meta' => $meta])->save();

    crashOnceAfter('wallet');
    World::deliver($billable, $paymentId, 'paid', $value, $metadata, expectHttp: 500);
    World::deliver($billable, $paymentId, 'paid', $value, $metadata);

    expect(resumeOutcome($billable))->toMatchArray([
        'plan' => 'enterprise',
        'waiting' => [],
        'invoices' => [['subscription', 6000], ['subscription', 16258], ['refund', -4064]],
        'refunds' => ['tr_cm_renewal_2' => ['40.64']],
        'held' => 24000,
    ]);
});

it('tells the operators at once when the subscription ended before a run could be resumed', function (): void {
    // Refund lines are out already, so returning the whole payment on top would return
    // more than was taken; what is owed is for an operator to settle — told now, once.
    operatorsTold();
    $billable = World::subscription('none', 'pro', 2);
    World::change($billable, ['plan_code' => 'enterprise'], settle: null);

    crashOnceAfter('refunds');
    World::settle($billable, expectHttp: 500);
    $billable->forceFill(['subscription_status' => \GraystackIT\MollieBilling\Enums\SubscriptionStatus::Cancelled])->save();

    World::settle($billable);
    World::settle($billable);

    expect(resumeOutcome($billable)['plan'])->toBe('pro')
        ->and(resumeOutcome($billable)['refunds'])->toBe(['tr_cm_renewal_2' => ['40.64']]);
    Notification::assertSentToTimes(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class, 1);
    Notification::assertSentTo(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class,
        fn (AdminPlanChangeFailedNotification $n): bool => $n->context['why'] === 'subscription_ended'
            && $n->context['completed_steps'] === ['invoiced', 'document', 'document_announced', 'announced', 'wallet', 'spanned_renewal', 'refunds']);
});

it('keeps trying to tell the operators until it has — the note follows the send', function (): void {
    // Noted before the send, an admin resolver (or a send) that threw left the note
    // behind, and the re-delivery and the clean-up both kept quiet for good.
    Notification::fake();
    $attempts = 0;
    MollieBilling::notifyAdminUsing(function () use (&$attempts): array {
        if (++$attempts === 1) {
            throw new RuntimeException('admin lookup failed');
        }

        return [new AnonymousNotifiable];
    });

    $billable = World::subscription('none', 'pro', 2);
    World::change($billable, ['interval' => 'yearly'], settle: null);
    crashOnceAfter('refunds');
    World::settle($billable, expectHttp: 500);
    World::renew($billable);

    World::settle($billable, expectHttp: 500);
    Notification::assertNothingSent();

    World::settle($billable);
    Notification::assertSentToTimes(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class, 1);

    World::settle($billable);
    Notification::assertSentToTimes(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class, 1);
});

/** A billable whose app scope finds nothing without a tenant context — as in a webhook. */
class TenantScopedResumeBillable extends TestBillable
{
    protected $table = 'test_billables';

    public function applyBillingScope(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->whereRaw('1 = 0');
    }
}

it('stores the charge invoice\'s PDF for a billable the app scopes by tenant', function (string $step): void {
    // The webhook resolves the billable scope-free; reading it back through the invoice's
    // relation applies the app's scope, finds nothing without a tenant, and the invoice was
    // left without a document while the change went through.
    $billable = World::subscription('none', 'pro', 2);
    World::change($billable, ['plan_code' => 'enterprise'], settle: null);
    [$paymentId] = pendingCharge($billable);

    Relation::morphMap([TestBillable::class => TenantScopedResumeBillable::class]);

    try {
        settleThroughCrash($billable, $step);
    } finally {
        Relation::morphMap([], false);
    }

    expect(resumeOutcome($billable)['plan'])->toBe('enterprise')
        ->and(BillingInvoice::query()->where('mollie_payment_id', $paymentId)->first()?->hasPdf())->toBeTrue();
})->with(['uninterrupted' => ['none'], 'died before the document' => ['invoiced']]);
