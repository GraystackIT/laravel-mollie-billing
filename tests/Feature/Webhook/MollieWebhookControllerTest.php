<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Events\MandateUpdated;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingProcessedWebhook;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;

/**
 * Test subclass that bypasses Mollie API and returns a fake payment object.
 */
class FakeWebhookController extends MollieWebhookController
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
    FakeWebhookController::$nextPayment = null;
    $this->app->bind(MollieWebhookController::class, FakeWebhookController::class);
});

function webhookBillable(): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => 'wh@example.com',
        'name' => 'WH Billable',
        'billing_country' => 'DE',
    ])->save();

    return $b;
}

function fakePayment(array $attrs): object
{
    $default = [
        'id' => 'tr_'.uniqid(),
        'status' => 'paid',
        'amount' => (object) ['value' => '29.00', 'currency' => 'EUR'],
        'metadata' => [],
        'subscriptionId' => null,
        'customerId' => 'cst_test',
        'mandateId' => 'mdt_test',
    ];

    return (object) array_merge($default, $attrs);
}

it('returns 200 for empty payload', function (): void {
    $response = $this->postJson(route('billing.webhook'), []);
    $response->assertStatus(200);
});

it('reserves idempotency row and handles mandate-only payment', function (): void {
    Event::fake([MandateUpdated::class]);

    $billable = webhookBillable();

    FakeWebhookController::$nextPayment = fakePayment([
        'id' => 'tr_mandate_123',
        'status' => 'paid',
        'amount' => (object) ['value' => '0.00', 'currency' => 'EUR'],
        'metadata' => [
            'type' => 'mandate_only',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ]);

    $response = $this->postJson(route('billing.webhook'), ['id' => 'tr_mandate_123']);
    $response->assertStatus(200);

    expect($billable->fresh()->mollie_mandate_id)->toBe('mdt_test');

    Event::assertDispatched(MandateUpdated::class);

    $row = BillingProcessedWebhook::where('mollie_payment_id', 'tr_mandate_123')->first();
    expect($row)->not->toBeNull();
    expect($row->event_signature)->toBe('tr_mandate_123:paid');
});

it('is idempotent on second delivery of the same payment', function (): void {
    $billable = webhookBillable();

    FakeWebhookController::$nextPayment = fakePayment([
        'id' => 'tr_mandate_dup',
        'status' => 'paid',
        'amount' => (object) ['value' => '0.00', 'currency' => 'EUR'],
        'metadata' => [
            'type' => 'mandate_only',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ]);

    $this->postJson(route('billing.webhook'), ['id' => 'tr_mandate_dup'])->assertStatus(200);
    $this->postJson(route('billing.webhook'), ['id' => 'tr_mandate_dup'])->assertStatus(200);

    expect(BillingProcessedWebhook::where('mollie_payment_id', 'tr_mandate_dup')->count())->toBe(1);
});
