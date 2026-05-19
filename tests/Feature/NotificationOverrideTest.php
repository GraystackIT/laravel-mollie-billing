<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\ProcessTrialLifecycleJob;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\TrialEndingSoonNotification;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();

    MollieBilling::notifyBillingAdminsUsing(fn ($billable): array => [$billable]);
});

afterEach(function (): void {
    $reflection = new ReflectionClass(MollieBilling::class);
    $prop = $reflection->getProperty('notifyBillingAdminsCallback');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
});

it('resolves the registered package notification class by default', function (): void {
    $notification = MollieBilling::resolveNotification(
        TrialEndingSoonNotification::class,
        TestBillable::create([
            'name' => 'Default',
            'email' => 'default@x.test',
            'billing_country' => 'AT',
        ]),
    );

    expect($notification)->toBeInstanceOf(TrialEndingSoonNotification::class);
});

it('swaps the package notification for an app-provided class', function (): void {
    MollieBilling::useNotification(
        TrialEndingSoonNotification::class,
        CustomTrialEndingMail::class,
    );

    $billable = TestBillable::create([
        'name' => 'Swap',
        'email' => 'swap@x.test',
        'billing_country' => 'AT',
    ]);

    $notification = MollieBilling::resolveNotification(TrialEndingSoonNotification::class, $billable);

    expect($notification)->toBeInstanceOf(CustomTrialEndingMail::class)
        ->and($notification)->not->toBeInstanceOf(TrialEndingSoonNotification::class);
});

it('sends the swapped class from ProcessTrialLifecycleJob for trials without mandate', function (): void {
    MollieBilling::useNotification(
        TrialEndingSoonNotification::class,
        CustomTrialEndingMail::class,
    );

    $billable = TestBillable::create([
        'name' => 'Trial '.uniqid(),
        'email' => uniqid('trial-').'@x.test',
        'billing_country' => 'AT',
    ]);

    $billable->forceFill([
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'trial_ends_at' => BillingTime::nowUtc()->copy()->addDay()->addHours(2),
    ])->save();

    (new ProcessTrialLifecycleJob)->handle();

    Notification::assertSentTo($billable, CustomTrialEndingMail::class);
    Notification::assertSentTimes(TrialEndingSoonNotification::class, 0);
});

class CustomTrialEndingMail extends BaseNotification
{
    public function __construct(public readonly object $billable)
    {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Custom trial-ending mail')->line('Custom');
    }
}
