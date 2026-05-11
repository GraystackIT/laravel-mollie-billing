<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Notifications\AdminPaidWithoutBillableNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

/**
 * Mirror of FakeWebhookController in MollieWebhookControllerTest.php — kept
 * local so the suites stay independent.
 */
class FakeUnresolvableBillableController extends MollieWebhookController
{
    public static ?object $nextPayment = null;

    protected function fetchPayment(string $paymentId): object
    {
        if (self::$nextPayment === null) {
            throw new \RuntimeException('No payment stub configured.');
        }

        return self::$nextPayment;
    }
}

beforeEach(function (): void {
    FakeUnresolvableBillableController::$nextPayment = null;
    $this->app->bind(MollieWebhookController::class, FakeUnresolvableBillableController::class);
});

it('notifies admin when a paid webhook references an unresolvable billable', function (): void {
    Notification::fake();

    \GraystackIT\MollieBilling\MollieBilling::notifyAdminUsing(fn () => [
        new AnonymousNotifiable,
    ]);

    FakeUnresolvableBillableController::$nextPayment = (object) [
        'id' => 'tr_orphan_1',
        'status' => 'paid',
        'amount' => (object) ['value' => '29.00', 'currency' => 'EUR'],
        'metadata' => [
            'billable_type' => \GraystackIT\MollieBilling\Testing\TestBillable::class,
            'billable_id' => '00000000-0000-0000-0000-000000000000',
        ],
        'subscriptionId' => null,
        'customerId' => 'cst_test',
        'mandateId' => 'mdt_test',
    ];

    $response = $this->postJson(route('billing.webhook'), ['id' => 'tr_orphan_1']);
    $response->assertStatus(200);

    Notification::assertSentTo(
        new AnonymousNotifiable,
        AdminPaidWithoutBillableNotification::class,
        function ($notification) {
            return $notification->paymentId === 'tr_orphan_1'
                && $notification->amountCents === 2900
                && $notification->currency === 'EUR';
        },
    );
});

it('does not notify admin when admin callback is not registered', function (): void {
    Notification::fake();

    \GraystackIT\MollieBilling\MollieBilling::notifyAdminUsing(fn () => []);

    FakeUnresolvableBillableController::$nextPayment = (object) [
        'id' => 'tr_orphan_2',
        'status' => 'paid',
        'amount' => (object) ['value' => '10.00', 'currency' => 'EUR'],
        'metadata' => [
            'billable_type' => \GraystackIT\MollieBilling\Testing\TestBillable::class,
            'billable_id' => '00000000-0000-0000-0000-000000000000',
        ],
        'subscriptionId' => null,
        'customerId' => 'cst_test',
        'mandateId' => 'mdt_test',
    ];

    $response = $this->postJson(route('billing.webhook'), ['id' => 'tr_orphan_2']);
    $response->assertStatus(200);

    Notification::assertNothingSent();
});
