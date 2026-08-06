<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Notifications\AdminRefundFailedNotification;
use GraystackIT\MollieBilling\Notifications\TrialConvertedNotification;
use GraystackIT\MollieBilling\Notifications\TrialEndingSoonNotification;
use GraystackIT\MollieBilling\Notifications\TrialExpiredNotification;
use GraystackIT\MollieBilling\Notifications\UsageThresholdNotification;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\NotificationFixtures as Fixture;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Every notification is queued, and Illuminate\Notifications\Notification uses
 * SerializesModels: the billable travels as a class + key and is reloaded from the
 * database when the worker renders the mail — minutes or hours after the event, and
 * after any number of intervening state changes.
 *
 * Each case therefore constructs the notification, changes the world the way the real
 * system would in the meantime, and only then renders.
 *
 * Two rules make these tests discriminate, both learned the hard way:
 *   - render through the serialize round-trip, never off the in-memory instance —
 *     that instance never re-reads, so a render-time read returns the same value the
 *     constructor would have frozen and the test passes either way;
 *   - mutate through the query builder, so the change cannot reach the notification
 *     via the object it was handed.
 */

beforeEach(function (): void {
    app()->setLocale('en');
    config()->set('mollie-billing.company_name', 'Graystack');
    config()->set('mollie-billing.currency', 'EUR');
    config()->set('mollie-billing.currency_symbol', '€');

    Carbon::setTestNow('2026-03-04 02:05:00');
    CarbonImmutable::setTestNow('2026-03-04 02:05:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

/** The notification as a queue worker gets it back: models reloaded from the database. */
function fromQueue(Notification $notification): Notification
{
    return unserialize(serialize($notification));
}

/** Subject plus body, rendered from the restored notification. */
function renderQueued(Notification $notification, object $notifiable): string
{
    return Fixture::mailText(fromQueue($notification), $notifiable);
}

/** Writes straight to the table, bypassing the in-memory billable entirely. */
function driftBillable(TestBillable $billable, array $columns): void
{
    DB::table($billable->getTable())->where('id', $billable->getKey())->update($columns);
}

function trialingBillable(array $overrides = []): TestBillable
{
    $billable = Fixture::billable();
    $billable->forceFill(array_merge([
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => 'monthly',
        'trial_ends_at' => CarbonImmutable::parse('2026-03-05 01:00:00', 'UTC'),
    ], $overrides))->save();

    return $billable;
}

it('keeps the no-mandate wording when the mandate arrives before the mail is rendered', function (): void {
    $billable = trialingBillable(['mollie_mandate_id' => null]);

    $notification = new TrialEndingSoonNotification($billable);

    // The customer adds a card between the lifecycle pass and the worker.
    driftBillable($billable, ['mollie_mandate_id' => 'mdt_late']);

    expect(renderQueued($notification, $billable))
        ->toContain('please add a payment method')
        ->not->toContain('no action required');

    expect(fromQueue($notification)->toArray($billable)['has_mandate'])->toBeFalse();
});

it('keeps the mandate wording when the mandate is revoked before the mail is rendered', function (): void {
    $billable = trialingBillable(['mollie_mandate_id' => 'mdt_present']);

    $notification = new TrialEndingSoonNotification($billable);

    driftBillable($billable, ['mollie_mandate_id' => null]);

    expect(renderQueued($notification, $billable))
        ->toContain('no action required')
        ->not->toContain('please add a payment method');

    expect(fromQueue($notification)->toArray($billable)['has_mandate'])->toBeTrue();
});

it('keeps the trial end date and day count of the sending pass', function (): void {
    $billable = trialingBillable(['mollie_mandate_id' => 'mdt_present']);

    $notification = new TrialEndingSoonNotification($billable);

    // The trial converted while the mail sat on the queue: activation clears
    // trial_ends_at, which used to blank the date and reset the day count.
    driftBillable($billable, [
        'trial_ends_at' => null,
        'subscription_status' => SubscriptionStatus::Active->value,
    ]);

    Carbon::setTestNow('2026-03-06 08:00:00');
    CarbonImmutable::setTestNow('2026-03-06 08:00:00');

    $body = renderQueued($notification, $billable);

    expect($body)->toContain('2026-03-05')
        ->and($body)->toContain('ends in 1 days')
        ->and(fromQueue($notification)->toArray($billable)['days'])->toBe(1);
});

it('keeps the expired trial date when a resubscribe moves it', function (): void {
    $billable = trialingBillable(['trial_ends_at' => CarbonImmutable::parse('2026-03-01 12:00:00', 'UTC')]);

    $notification = new TrialExpiredNotification($billable);

    // The CTA in this very mail leads to checkout, which starts a fresh period.
    driftBillable($billable, ['trial_ends_at' => '2026-04-10 12:00:00']);

    $body = renderQueued($notification, $billable);

    expect($body)->toContain('2026-03-01')
        ->and($body)->not->toContain('2026-04-10')
        ->and(fromQueue($notification)->toArray($billable)['trial_ends_at'])->toStartWith('2026-03-01');
});

it('keeps the overage hint the debit was judged by after a plan change', function (): void {
    config()->set('mollie-billing-plans.plans', [
        'starter' => [
            'name' => 'Starter',
            'intervals' => [
                'monthly' => [
                    'price' => 1000,
                    'included_usages' => ['sms' => 100],
                    'usage_overage_prices' => ['sms' => 5],
                ],
            ],
        ],
        'free' => [
            'name' => 'Free',
            'intervals' => [
                'monthly' => [
                    'price' => 0,
                    'included_usages' => ['sms' => 10],
                    'usage_overage_prices' => [],
                ],
            ],
        ],
    ]);

    $billable = Fixture::billable();
    $billable->forceFill([
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_plan_code' => 'starter',
        'subscription_interval' => 'monthly',
        'mollie_mandate_id' => 'mdt_ok',
    ])->save();

    $notification = new UsageThresholdNotification($billable, 'sms', 80);

    // Downgrade to a plan without an sms overage price — canChargeBillingOverage()
    // now answers the opposite of what it answered for the debit.
    driftBillable($billable, ['subscription_plan_code' => 'free']);

    $body = renderQueued($notification, $billable);

    expect($body)->toContain('will be billed at the end of the period')
        ->and($body)->not->toContain('No further sms usage will be allowed')
        ->and(fromQueue($notification)->toArray($billable)['overage_allowed'])->toBeTrue();
});

it('keeps the plan the trial converted onto in both the mail and the payload', function (): void {
    config()->set('mollie-billing-plans.plans', [
        'starter' => ['name' => 'Starter', 'intervals' => ['monthly' => ['price' => 1000]]],
        'enterprise' => ['name' => 'Enterprise', 'intervals' => ['monthly' => ['price' => 9900]]],
    ]);

    $billable = Fixture::billable();
    $billable->forceFill([
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'starter',
        'subscription_interval' => 'monthly',
    ])->save();

    $invoice = Fixture::invoice($billable, InvoiceKind::Subscription, 1200);
    $notification = new TrialConvertedNotification($billable, $invoice);

    driftBillable($billable, ['subscription_plan_code' => 'enterprise']);

    $payload = fromQueue($notification)->toArray($billable);

    expect(renderQueued($notification, $billable))->toContain('Starter')
        ->and($payload['plan'])->toBe('Starter')
        ->and($payload['plan_code'])->toBe('starter');
});

it('names the invoice the failed refund targeted, not the billable newest one', function (): void {
    $billable = Fixture::billable();

    $target = Fixture::invoice($billable, InvoiceKind::Subscription, 1190);
    // Written after the refund target: a later renewal, plus the credit note of an
    // earlier successful refund. Both outrank the target in every "latest" lookup.
    $renewal = Fixture::invoice($billable, InvoiceKind::Subscription, 1190);
    $creditNote = Fixture::invoice($billable, InvoiceKind::Refund, -500);

    $notification = new AdminRefundFailedNotification(
        $billable,
        new RuntimeException('Mollie declined the refund'),
        $target,
    );

    $body = renderQueued($notification, $billable);

    expect($body)->toContain('invoice '.$target->id)
        ->and($body)->not->toContain('invoice '.$renewal->id)
        ->and($body)->not->toContain('invoice '.$creditNote->id)
        ->and($body)->toContain('Mollie declined the refund')
        ->and(fromQueue($notification)->toArray($billable)['invoice_id'])->toBe((int) $target->id);
});

it('never names a credit note when no refund target is handed in', function (): void {
    $billable = Fixture::billable();

    $payable = Fixture::invoice($billable, InvoiceKind::Subscription, 1190);
    $creditNote = Fixture::invoice($billable, InvoiceKind::Refund, -500);

    // An unpaid invoice is not refundable either, so it must lose to the paid one.
    $open = Fixture::invoice($billable, InvoiceKind::Subscription, 4900);
    $open->forceFill(['status' => InvoiceStatus::Open])->save();

    $body = renderQueued(new AdminRefundFailedNotification($billable, new RuntimeException('declined')), $billable);

    expect($body)->toContain('invoice '.$payable->id)
        ->and($body)->not->toContain('invoice '.$creditNote->id)
        ->and($body)->not->toContain('invoice '.$open->id);
});

it('freezes the refund target so a later invoice cannot take its place', function (): void {
    $billable = Fixture::billable();
    $target = Fixture::invoice($billable, InvoiceKind::Subscription, 1190);

    $notification = new AdminRefundFailedNotification($billable, new RuntimeException('declined'), $target);

    // The next renewal lands before the queued alert is rendered.
    $later = Fixture::invoice($billable, InvoiceKind::Subscription, 1190);
    expect((int) $later->id)->not->toBe((int) $target->id);

    $body = renderQueued($notification, $billable);

    expect($body)->toContain('invoice '.$target->id)
        ->and($body)->not->toContain('invoice '.$later->id);
});

it('reports no invoice when the billable has none at all', function (): void {
    $billable = Fixture::billable();

    expect(renderQueued(new AdminRefundFailedNotification($billable, new RuntimeException('declined')), $billable))
        ->toContain('invoice -');
});

it('keeps every captured value across the queue serialization round-trip', function (): void {
    $billable = trialingBillable(['mollie_mandate_id' => 'mdt_present']);
    $invoice = Fixture::invoice($billable, InvoiceKind::Subscription, 1190);

    $notifications = [
        new TrialEndingSoonNotification($billable),
        new TrialExpiredNotification($billable),
        new UsageThresholdNotification($billable, 'sms', 80),
        new TrialConvertedNotification($billable, $invoice),
        new AdminRefundFailedNotification($billable, Fixture::unserializableException(), $invoice),
    ];

    foreach ($notifications as $notification) {
        expect(fromQueue($notification)->toArray($billable))
            ->toBe($notification->toArray($billable), $notification::class);
    }
});
