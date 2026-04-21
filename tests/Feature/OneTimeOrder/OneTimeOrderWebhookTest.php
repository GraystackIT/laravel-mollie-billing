<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Events\OneTimeOrderCompleted;
use GraystackIT\MollieBilling\Events\OneTimeOrderFailed;
use GraystackIT\MollieBilling\Events\PaymentSucceeded;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingProcessedWebhook;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;

/**
 * Fake controller that bypasses Mollie API.
 */
class OneTimeOrderFakeWebhookController extends MollieWebhookController
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
    OneTimeOrderFakeWebhookController::$nextPayment = null;
    $this->app->bind(MollieWebhookController::class, OneTimeOrderFakeWebhookController::class);

    config()->set('mollie-billing-plans.products', [
        'token-pack' => [
            'name' => '500 Token Pack',
            'description' => 'Tokens for your account.',
            'image_url' => null,
            'price_net' => 4900,
            'usage_type' => 'Tokens',
            'quantity' => 500,
        ],
        'consulting' => [
            'name' => 'Consulting',
            'description' => null,
            'image_url' => null,
            'price_net' => 10000,
        ],
    ]);
});

function orderBillable(): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => 'order@example.com',
        'name' => 'Order Billable',
        'billing_country' => 'DE',
    ])->save();

    return $b;
}

function orderPayment(array $attrs): object
{
    $default = [
        'id' => 'tr_'.uniqid(),
        'status' => 'paid',
        'amount' => (object) ['value' => '58.31', 'currency' => 'EUR'],
        'metadata' => [],
        'subscriptionId' => null,
        'customerId' => null,
        'mandateId' => null,
    ];

    return (object) array_merge($default, $attrs);
}

it('creates invoice and fires events for blank product', function (): void {
    Event::fake([OneTimeOrderCompleted::class, PaymentSucceeded::class]);

    $billable = orderBillable();

    OneTimeOrderFakeWebhookController::$nextPayment = orderPayment([
        'id' => 'tr_oto_blank',
        'status' => 'paid',
        'amount' => (object) ['value' => '119.00', 'currency' => 'EUR'],
        'metadata' => [
            'type' => 'one_time_order',
            'product_code' => 'consulting',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ]);

    $response = $this->postJson(route('billing.webhook'), ['id' => 'tr_oto_blank']);
    $response->assertStatus(200);

    $invoice = BillingInvoice::where('mollie_payment_id', 'tr_oto_blank')->first();
    expect($invoice)->not->toBeNull();
    expect($invoice->invoice_kind)->toBe(InvoiceKind::OneTimeOrder);

    Event::assertDispatched(OneTimeOrderCompleted::class, function ($event) use ($billable) {
        return $event->billable->getKey() === $billable->getKey()
            && $event->productCode === 'consulting';
    });

    Event::assertDispatched(PaymentSucceeded::class);
});

it('credits wallet for usage-linked product', function (): void {
    Event::fake([OneTimeOrderCompleted::class, PaymentSucceeded::class]);

    $billable = orderBillable();

    OneTimeOrderFakeWebhookController::$nextPayment = orderPayment([
        'id' => 'tr_oto_usage',
        'status' => 'paid',
        'amount' => (object) ['value' => '58.31', 'currency' => 'EUR'],
        'metadata' => [
            'type' => 'one_time_order',
            'product_code' => 'token-pack',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ]);

    $response = $this->postJson(route('billing.webhook'), ['id' => 'tr_oto_usage']);
    $response->assertStatus(200);

    $invoice = BillingInvoice::where('mollie_payment_id', 'tr_oto_usage')->first();
    expect($invoice)->not->toBeNull();
    expect($invoice->invoice_kind)->toBe(InvoiceKind::OneTimeOrder);

    // Verify wallet was credited
    $wallet = $billable->getWallet('Tokens');
    expect((int) $wallet->balanceInt)->toBe(500);

    Event::assertDispatched(OneTimeOrderCompleted::class, function ($event) {
        return $event->productCode === 'token-pack';
    });
});

it('does not credit wallet for blank product', function (): void {
    Event::fake([OneTimeOrderCompleted::class, PaymentSucceeded::class]);

    $billable = orderBillable();

    OneTimeOrderFakeWebhookController::$nextPayment = orderPayment([
        'id' => 'tr_oto_nousage',
        'status' => 'paid',
        'amount' => (object) ['value' => '119.00', 'currency' => 'EUR'],
        'metadata' => [
            'type' => 'one_time_order',
            'product_code' => 'consulting',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ]);

    $this->postJson(route('billing.webhook'), ['id' => 'tr_oto_nousage'])->assertStatus(200);

    expect($billable->hasWallet('Tokens'))->toBeFalse();
});

it('fires OneTimeOrderFailed on failed payment', function (): void {
    Event::fake([OneTimeOrderFailed::class]);

    $billable = orderBillable();

    OneTimeOrderFakeWebhookController::$nextPayment = orderPayment([
        'id' => 'tr_oto_fail',
        'status' => 'failed',
        'amount' => (object) ['value' => '58.31', 'currency' => 'EUR'],
        'metadata' => [
            'type' => 'one_time_order',
            'product_code' => 'token-pack',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ]);

    $response = $this->postJson(route('billing.webhook'), ['id' => 'tr_oto_fail']);
    $response->assertStatus(200);

    expect(BillingInvoice::where('mollie_payment_id', 'tr_oto_fail')->exists())->toBeFalse();

    Event::assertDispatched(OneTimeOrderFailed::class, function ($event) {
        return $event->productCode === 'token-pack'
            && $event->reason === 'failed';
    });
});

it('is idempotent on duplicate webhook delivery', function (): void {
    Event::fake([OneTimeOrderCompleted::class, PaymentSucceeded::class]);

    $billable = orderBillable();

    OneTimeOrderFakeWebhookController::$nextPayment = orderPayment([
        'id' => 'tr_oto_dup',
        'status' => 'paid',
        'amount' => (object) ['value' => '119.00', 'currency' => 'EUR'],
        'metadata' => [
            'type' => 'one_time_order',
            'product_code' => 'consulting',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ]);

    $this->postJson(route('billing.webhook'), ['id' => 'tr_oto_dup'])->assertStatus(200);
    $this->postJson(route('billing.webhook'), ['id' => 'tr_oto_dup'])->assertStatus(200);

    expect(BillingInvoice::where('mollie_payment_id', 'tr_oto_dup')->count())->toBe(1);
    expect(BillingProcessedWebhook::where('mollie_payment_id', 'tr_oto_dup')->count())->toBe(1);
});
