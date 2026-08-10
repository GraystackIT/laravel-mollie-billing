<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Notifications\AdminOverageBillingFailedNotification;
use GraystackIT\MollieBilling\Notifications\InvoiceAvailableNotification;
use GraystackIT\MollieBilling\Notifications\OverageBillingFailedNotification;
use GraystackIT\MollieBilling\Notifications\RefundProcessedNotification;
use GraystackIT\MollieBilling\Notifications\SubscriptionPaymentFailedNotification;
use GraystackIT\MollieBilling\Notifications\TrialConvertedNotification;
use GraystackIT\MollieBilling\Tests\Support\NotificationFixtures as Fixture;

beforeEach(function (): void {
    config()->set('mollie-billing.currency', 'EUR');
    config()->set('mollie-billing.currency_symbol', '€');
    app()->setLocale('en');
});

it('renders invoice amounts with a currency symbol instead of a raw ISO code', function (): void {
    $billable = Fixture::billable();
    $invoice = Fixture::invoice($billable, InvoiceKind::Subscription, 123456);

    $body = Fixture::mailText(new InvoiceAvailableNotification($billable, $invoice), $billable);

    expect($body)->toContain('€1,234.56')
        ->and($body)->not->toContain('EUR')
        ->and($body)->not->toContain('1.234,56');
});

it('renders invoice amounts in the active locale', function (): void {
    $billable = Fixture::billable();
    $invoice = Fixture::invoice($billable, InvoiceKind::Subscription, 123456);

    app()->setLocale('de');

    expect(Fixture::mailText(new InvoiceAvailableNotification($billable, $invoice), $billable))
        ->toContain('€1.234,56');
});

it('fills the :app placeholder in the invoice subject', function (): void {
    config()->set('mollie-billing.company_name', 'Graystack');

    $billable = Fixture::billable();
    $invoice = Fixture::invoice($billable, InvoiceKind::Subscription, 1000);

    expect((new InvoiceAvailableNotification($billable, $invoice))->toMail($billable)->subject)
        ->toBe('New invoice from Graystack')
        ->not->toContain(':app');
});

it('renders a refund as a positive symbol amount', function (): void {
    $billable = Fixture::billable();
    $creditNote = Fixture::invoice($billable, InvoiceKind::Refund, -500);

    $body = Fixture::mailText(new RefundProcessedNotification($billable, $creditNote), $billable);

    expect($body)->toContain('€5.00')
        ->and($body)->not->toContain('-€5.00')
        ->and($body)->not->toContain('EUR');
});

it('reports the pending overage amount, not the latest invoice', function (): void {
    $billable = Fixture::billable();
    // The previous subscription invoice that latestBillingInvoice() would return.
    Fixture::invoice($billable, InvoiceKind::Subscription, 4900);
    Fixture::pendingOverage($billable, 320);

    $body = Fixture::mailText(new OverageBillingFailedNotification($billable), $billable);

    expect($body)->toContain('€3.20')
        ->and($body)->not->toContain('49.00');
});

it('reports the pending overage amount to admins, not a credit note', function (): void {
    $billable = Fixture::billable();
    // A credit note carries a negative gross — reporting it would print "-€49.00".
    Fixture::invoice($billable, InvoiceKind::Refund, -4900);
    Fixture::pendingOverage($billable, 320);

    $body = Fixture::mailText(
        new AdminOverageBillingFailedNotification($billable, new RuntimeException('mandate revoked')),
        $billable,
    );

    expect($body)->toContain('€3.20')
        ->and($body)->toContain('mandate revoked')
        ->and($body)->not->toContain('-€');
});

it('honours the currency stored alongside the pending overage', function (): void {
    $billable = Fixture::billable();
    Fixture::pendingOverage($billable, 320, 'GBP');

    expect(Fixture::mailText(new OverageBillingFailedNotification($billable), $billable))
        ->toContain('£3.20');
});

it('names no amount when the pending overage is missing', function (): void {
    $billable = Fixture::billable();
    Fixture::invoice($billable, InvoiceKind::Subscription, 4900);

    $body = Fixture::mailText(new OverageBillingFailedNotification($billable), $billable);

    expect($body)->toContain('an unknown amount')
        ->and($body)->not->toContain('€');
});

it('captures the pending overage at construction time', function (): void {
    $billable = Fixture::billable();
    Fixture::pendingOverage($billable, 320);

    $notification = new OverageBillingFailedNotification($billable);

    // Simulates the queue worker: the meta entry is cleared before the mail is rendered.
    $billable->forceFill(['subscription_meta' => []])->save();

    expect(Fixture::mailText($notification, $billable))->toContain('€3.20');
});

it('reports the failed payment amount handed in by the caller', function (): void {
    $billable = Fixture::billable();
    Fixture::invoice($billable, InvoiceKind::Subscription, 4900);

    $notification = new SubscriptionPaymentFailedNotification(
        $billable,
        'tr_failed',
        1999,
        'EUR',
        CarbonImmutable::parse('2026-03-04 10:00:00', 'UTC'),
    );

    $body = Fixture::mailText($notification, $billable);

    expect($body)->toContain('€19.99')
        ->and($body)->toContain('2026-03-04')
        ->and($body)->not->toContain('49.00');
});

it('names no amount for a failed payment when the caller passes none', function (): void {
    $billable = Fixture::billable();
    Fixture::invoice($billable, InvoiceKind::Subscription, 4900);

    $notification = new SubscriptionPaymentFailedNotification($billable, 'tr_failed');
    $body = Fixture::mailText($notification, $billable);

    expect($body)->toContain('an unknown amount')
        ->and($body)->not->toContain('49.00')
        ->and($notification->failedAt->format('Y-m-d'))->toBe(CarbonImmutable::now('UTC')->format('Y-m-d'));
});

it('freezes the failure date so a late mail still names the failure day', function (): void {
    Carbon\Carbon::setTestNow('2026-03-04 23:50:00');
    CarbonImmutable::setTestNow('2026-03-04 23:50:00');

    $billable = Fixture::billable();
    $notification = new SubscriptionPaymentFailedNotification($billable, 'tr_failed', 1999, 'EUR');

    Carbon\Carbon::setTestNow('2026-03-06 08:00:00');
    CarbonImmutable::setTestNow('2026-03-06 08:00:00');

    expect(Fixture::mailText($notification, $billable))->toContain('2026-03-04');

    Carbon\Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('picks the subscription invoice over a same-second credit note on trial conversion', function (): void {
    $billable = Fixture::billable();
    $sameSecond = CarbonImmutable::parse('2026-03-04 10:00:00', 'UTC');

    Fixture::invoice($billable, InvoiceKind::Subscription, 4900, $sameSecond);
    Fixture::invoice($billable, InvoiceKind::Refund, -4900, $sameSecond);

    $body = Fixture::mailText(new TrialConvertedNotification($billable), $billable);

    expect($body)->toContain('€49.00')
        ->and($body)->not->toContain('-€49.00');
});

it('names no amount on trial conversion when no subscription invoice exists', function (): void {
    $billable = Fixture::billable();
    Fixture::invoice($billable, InvoiceKind::Overage, 320);

    expect(Fixture::mailText(new TrialConvertedNotification($billable), $billable))
        ->toContain('an unknown amount');
});
