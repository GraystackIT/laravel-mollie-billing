<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Notifications\AdminOverageBillingFailedNotification;
use GraystackIT\MollieBilling\Notifications\AdminOverageSweepFailedNotification;
use GraystackIT\MollieBilling\Notifications\AdminPaidWithoutBillableNotification;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Notifications\AdminRefundFailedNotification;
use GraystackIT\MollieBilling\Notifications\CountryMismatchReissueFailedNotification;
use GraystackIT\MollieBilling\Notifications\CountryMismatchSelfNotification;
use GraystackIT\MollieBilling\Notifications\InvoiceAvailableNotification;
use GraystackIT\MollieBilling\Notifications\OverageBillingFailedNotification;
use GraystackIT\MollieBilling\Notifications\PlanChangeFailedNotification;
use GraystackIT\MollieBilling\Notifications\RefundProcessedNotification;
use GraystackIT\MollieBilling\Notifications\SubscriptionCancelledNotification;
use GraystackIT\MollieBilling\Notifications\SubscriptionPaymentFailedNotification;
use GraystackIT\MollieBilling\Notifications\TrialConvertedNotification;
use GraystackIT\MollieBilling\Notifications\TrialEndingSoonNotification;
use GraystackIT\MollieBilling\Notifications\TrialExpiredNotification;
use GraystackIT\MollieBilling\Notifications\UsageThresholdNotification;
use GraystackIT\MollieBilling\Tests\Support\NotificationFixtures as Fixture;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * One instance of every notification the package ships, wired with realistic
 * constructor arguments.
 *
 * @return array<class-string<Notification>, Notification>
 */
function everyBillingNotification(): array
{
    $billable = Fixture::billable();
    Fixture::pendingOverage($billable, 320);

    $invoice = Fixture::invoice($billable, InvoiceKind::Subscription, 4900);
    $creditNote = Fixture::invoice($billable, InvoiceKind::Refund, -4900);

    $mismatch = BillingCountryMismatch::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
        'tax_country_ip' => 'CH',
        'status' => CountryMismatchStatus::Pending,
    ]);

    $exception = Fixture::unserializableException();

    return [
        AdminOverageBillingFailedNotification::class => new AdminOverageBillingFailedNotification($billable, $exception),
        // Not about one billable: the pass walks the whole table, so it reports the pass.
        AdminOverageSweepFailedNotification::class => new AdminOverageSweepFailedNotification('probe'),
        AdminPaidWithoutBillableNotification::class => new AdminPaidWithoutBillableNotification('tr_x', 'Org', '1', 1000, 'EUR'),
        AdminPlanChangeFailedNotification::class => new AdminPlanChangeFailedNotification('patch failed', ['payment_id' => 'tr_x']),
        AdminRefundFailedNotification::class => new AdminRefundFailedNotification($billable, $exception),
        CountryMismatchReissueFailedNotification::class => new CountryMismatchReissueFailedNotification($billable, $mismatch, 'declined'),
        CountryMismatchSelfNotification::class => new CountryMismatchSelfNotification($billable, $mismatch),
        InvoiceAvailableNotification::class => new InvoiceAvailableNotification($billable, $invoice),
        OverageBillingFailedNotification::class => new OverageBillingFailedNotification($billable),
        PlanChangeFailedNotification::class => new PlanChangeFailedNotification($billable, 'tr_x'),
        RefundProcessedNotification::class => new RefundProcessedNotification($billable, $creditNote),
        SubscriptionCancelledNotification::class => new SubscriptionCancelledNotification($billable),
        SubscriptionPaymentFailedNotification::class => new SubscriptionPaymentFailedNotification($billable, 'tr_x', 4900, 'EUR'),
        TrialConvertedNotification::class => new TrialConvertedNotification($billable),
        TrialEndingSoonNotification::class => new TrialEndingSoonNotification($billable),
        TrialExpiredNotification::class => new TrialExpiredNotification($billable),
        UsageThresholdNotification::class => new UsageThresholdNotification($billable, 'sms', 80),
    ];
}

it('queues every notification the package ships', function (): void {
    $files = glob(__DIR__.'/../../../src/Notifications/*.php') ?: [];
    $classes = array_map(
        fn (string $file): string => 'GraystackIT\\MollieBilling\\Notifications\\'.basename($file, '.php'),
        $files,
    );

    expect($classes)->not->toBeEmpty();

    foreach ($classes as $class) {
        expect(is_subclass_of($class, ShouldQueue::class))->toBeTrue($class.' must implement ShouldQueue');
    }

    // Every shipped class is exercised below, so a new notification cannot slip
    // past the serialization and queue-routing assertions.
    expect(array_keys(everyBillingNotification()))->toEqualCanonicalizing($classes);
});

it('routes every notification onto the configured queue', function (): void {
    config()->set('mollie-billing.queue.connection', 'sqs');
    config()->set('mollie-billing.queue.name', 'billing-prio');

    foreach (everyBillingNotification() as $class => $notification) {
        expect($notification->connection)->toBe('sqs', $class.' connection')
            ->and($notification->queue)->toBe('billing-prio', $class.' queue');
    }
});

it('falls back to the framework default queue when the config is empty', function (): void {
    config()->set('mollie-billing.queue.connection', '');
    config()->set('mollie-billing.queue.name', null);

    foreach (everyBillingNotification() as $class => $notification) {
        expect($notification->connection)->toBeNull($class.' connection')
            ->and($notification->queue)->toBeNull($class.' queue');
    }
});

it('survives the queue serialization round-trip', function (): void {
    foreach (everyBillingNotification() as $class => $notification) {
        $restored = unserialize(serialize($notification));

        expect($restored)->toBeInstanceOf($class);
    }
});

it('keeps rendering the same mail after being restored from the queue', function (): void {
    config()->set('mollie-billing.currency', 'EUR');
    config()->set('mollie-billing.currency_symbol', '€');
    app()->setLocale('en');

    $notifiable = Fixture::billable(['name' => 'Recipient']);

    foreach (everyBillingNotification() as $class => $notification) {
        $before = $notification->toMail($notifiable);
        $after = unserialize(serialize($notification))->toMail($notifiable);

        expect($after->subject)->toBe($before->subject, $class.' subject')
            ->and($after->introLines)->toBe($before->introLines, $class.' lines');
    }
});
