<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Tests\Support\NotificationFixtures as Fixture;

it('renders the admin plan-change failure from the English lang file', function (): void {
    app()->setLocale('en');

    $notification = new AdminPlanChangeFailedNotification('Mollie PATCH rejected', ['payment_id' => 'tr_x']);
    $body = Fixture::mailText($notification, Fixture::billable());

    expect($body)->toContain('Plan change operation failed permanently')
        ->and($body)->toContain('Reason: Mollie PATCH rejected')
        ->and($body)->toContain('tr_x')
        ->and($body)->not->toContain('Grund')
        ->and($body)->not->toContain('fehlgeschlagen');
});

it('renders the admin plan-change failure from the German lang file', function (): void {
    app()->setLocale('de');

    $notification = new AdminPlanChangeFailedNotification('Mollie PATCH abgelehnt');
    $body = Fixture::mailText($notification, Fixture::billable());

    expect($body)->toContain('Plan-Change-Operation dauerhaft fehlgeschlagen')
        ->and($body)->toContain('Grund: Mollie PATCH abgelehnt')
        ->and($body)->toContain('Kontext:');
});
