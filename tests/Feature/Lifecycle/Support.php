<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Support\BillingAuditEntry;
use Illuminate\Support\Facades\Event;

require_once dirname(__DIR__).'/Webhook/Support/RenewalLongRun.php';

/*
 * Helpers of the event and notification catalogs.
 *
 * Nothing here is faked: an event is *listened* for, so the package's own listeners run
 * too — above all the audit recorder, whose row every catalog test asserts next to the
 * event's payload.
 */

/** Collect every dispatch of an event class from now on. */
function lifecycleListen(string $eventClass): ArrayObject
{
    $box = new ArrayObject;

    Event::listen($eventClass, static function (object $event) use ($box): void {
        $box->append($event);
    });

    return $box;
}

/**
 * The newest audit row of this kind, with its replace-values checked.
 *
 * @param  array<string, mixed>  $replace  values the row must carry
 */
function lifecycleAssertAudited(string $auditEvent, array $replace = []): object
{
    $row = BillingAuditEntry::model()::query()->where('event', $auditEvent)->orderByDesc('id')->first();

    expect($row)->not->toBeNull("no audit row for [{$auditEvent}]")
        ->and($row->log_name)->toBe('billing')
        ->and($row->description)->toBe('audit.'.$auditEvent);

    if ($replace !== []) {
        expect((array) $row->properties['replace'])->toMatchArray($replace);
    }

    // The row has to read as a sentence in both shipped languages.
    foreach (['en', 'de'] as $locale) {
        expect(trans()->has('billing::audit.'.$auditEvent, $locale, false))->toBeTrue("audit.{$auditEvent} has no {$locale} text");
    }

    return $row;
}

/** A second plan and a product for the tests that change plans or buy something. */
function lifecycleCatalogExtras(): void
{
    config()->set('mollie-billing-plans.plans.team', [
        'name' => 'Team',
        'tier' => 3,
        'included_seats' => 2,
        'feature_keys' => [],
        'allowed_addons' => ['sso', 'crm'],
        'intervals' => [
            'monthly' => ['base_price_net' => 5900, 'seat_price_net' => 500, 'included_usages' => ['Tokens' => 500]],
            'yearly' => ['base_price_net' => 59000, 'seat_price_net' => 5000, 'included_usages' => ['Tokens' => 6000]],
        ],
    ]);
    config()->set('mollie-billing-plans.plans.trialplan', [
        'name' => 'Trial Plan',
        'tier' => 1,
        'included_seats' => 2,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 2900, 'seat_price_net' => 500, 'trial_days' => 14, 'included_usages' => ['Tokens' => 100]],
        ],
    ]);
    config()->set('mollie-billing-plans.products.tokens_500', [
        'name' => '500 Tokens',
        'price_net' => 1000,
        'usage_type' => 'Tokens',
        'quantity' => 500,
    ]);
}

/**
 * A trial signup through the €0 mandate checkout. Returns the world, on status Trial.
 */
function lifecycleStartTrial(string $at = '2027-01-10 10:00:00'): LongRunWorld
{
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse($at, 'UTC'));

    /** @var \GraystackIT\MollieBilling\Testing\TestBillable $billable */
    $billable = \GraystackIT\MollieBilling\Testing\TestBillable::create([
        'name' => 'Trial Co',
        'email' => uniqid('trial-').'@x.test',
        'billing_country' => 'DE',
    ]);
    $billable->forceFill(['mollie_customer_id' => 'cst_longrun'])->save();

    $world = LongRunWorld::$current = new LongRunWorld($billable, 'monthly');
    $world->payments['tr_lr_mandate'] = LongRunPayment::make([
        'id' => 'tr_lr_mandate',
        'status' => 'paid',
        'amount' => (object) ['value' => '0.00', 'currency' => 'EUR'],
        'createdAt' => now()->toIso8601String(),
        'paidAt' => now()->toIso8601String(),
        'subscriptionId' => null,
        'customerId' => 'cst_longrun',
        'mandateId' => 'mdt_longrun',
        'countryCode' => 'DE',
        'metadata' => [
            'type' => 'mandate_only',
            'pending_subscription_plan_code' => 'trialplan',
            'pending_subscription_interval' => 'monthly',
            'pending_subscription_trial_days' => 14,
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ]);

    test()->postJson(route('billing.webhook'), ['id' => 'tr_lr_mandate'])->assertStatus(200);

    $world->billable = $billable->fresh();

    return $world;
}

/**
 * Everything a mail says, in one string, rendered in the given language.
 */
function lifecycleMailText(object $notification, object $notifiable, string $locale): string
{
    $previous = app()->getLocale();
    app()->setLocale($locale);

    try {
        /** @var \Illuminate\Notifications\Messages\MailMessage $mail */
        $mail = $notification->toMail($notifiable);

        return implode("\n", array_filter(array_map(
            static fn ($part): string => is_string($part) ? $part : (string) $part,
            [(string) $mail->subject, (string) $mail->greeting, ...$mail->introLines, (string) $mail->actionText, ...$mail->outroLines],
        )));
    } finally {
        app()->setLocale($previous);
    }
}

/**
 * The one notification of a class that was sent, with who received it.
 *
 * @return array{0: object, 1: object} notification, notifiable
 */
function lifecycleSentMail(string $notificationClass): array
{
    $found = [];

    \Illuminate\Support\Facades\Notification::assertSentTimes($notificationClass, 1);

    foreach ([...lifecycleRecipients()] as $notifiable) {
        foreach (\Illuminate\Support\Facades\Notification::sent($notifiable, $notificationClass) as $notification) {
            $found[] = [$notification, $notifiable];
        }
    }

    expect($found)->toHaveCount(1, class_basename($notificationClass).' did not reach the expected recipient');

    return $found[0];
}

/** @return list<object> the customer and the operator the catalog tests register */
function lifecycleRecipients(): array
{
    return array_values(array_filter([LifecycleMailbox::$customer, LifecycleMailbox::$operator]));
}

final class LifecycleMailbox
{
    public static ?object $customer = null;

    public static ?object $operator = null;

    /** Customer mails go to the billable itself, operator mails to one fixed operator. */
    public static function open(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        self::$customer = null;
        self::$operator = \GraystackIT\MollieBilling\Testing\TestBillable::create(['name' => 'Operator', 'email' => 'operator-'.uniqid().'@x.test']);

        \GraystackIT\MollieBilling\MollieBilling::notifyAdminUsing(fn (): array => [self::$operator]);
        \GraystackIT\MollieBilling\MollieBilling::notifyBillingAdminsUsing(function ($billable): array {
            self::$customer = $billable;

            return [$billable];
        });
    }

    public static function close(): void
    {
        \GraystackIT\MollieBilling\MollieBilling::notifyAdminUsing(fn (): array => []);
        \GraystackIT\MollieBilling\MollieBilling::notifyBillingAdminsUsing(fn (): array => []);
        self::$customer = self::$operator = null;
    }
}
