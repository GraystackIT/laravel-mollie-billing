<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\MandateUpdated;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Models\BillingProcessedWebhook;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
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

it('handleCountryCorrectionFailed flips the mismatch back to Pending and re-cancels the subscription', function (): void {
    $b = webhookBillable();
    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'business',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'mollie_customer_id' => 'cst_test',
        'mollie_mandate_id' => 'mdt_test',
        'subscription_meta' => [
            'mollie_subscription_id' => 'sub_test',
            'country_corrections' => [
                'tr_corr_42' => [
                    'mismatch_id' => 0, // patched below once we know the id
                    'original_invoice_id' => 0,
                    'old_country' => 'HU',
                    'new_country' => 'AT',
                    'created_at' => now()->subHours(2)->toIso8601String(),
                ],
            ],
        ],
    ])->save();

    $mismatch = BillingCountryMismatch::create([
        'billable_type' => $b->getMorphClass(),
        'billable_id' => $b->getKey(),
        'tax_country_user' => 'HU',
        'tax_country_payment' => 'AT',
        'tax_country_ip' => 'AT',
        'status' => CountryMismatchStatus::Resolved,
        'chosen_country' => 'AT',
        'resolved_at' => now()->subHours(2),
    ]);

    // Patch the mismatch_id reference in subscription_meta now that we have it.
    $meta = $b->getBillingSubscriptionMeta();
    $meta['country_corrections']['tr_corr_42']['mismatch_id'] = (int) $mismatch->id;
    $b->forceFill(['subscription_meta' => $meta])->save();

    // CancelSubscription must be invoked exactly once with immediately=false.
    $cancelSpy = \Mockery::mock(CancelSubscription::class);
    $cancelSpy->shouldReceive('handle')
        ->once()
        ->with(\Mockery::on(fn (Billable $arg) => $arg->getKey() === $b->getKey()), false);
    app()->instance(CancelSubscription::class, $cancelSpy);

    $payment = (object) [
        'id' => 'tr_corr_42',
        'status' => 'failed',
        'details' => (object) ['failureReason' => 'Card declined'],
    ];

    $controller = app(MollieWebhookController::class);
    $controller->handleCountryCorrectionFailed($payment, $b->fresh(), [
        'type' => 'country_correction',
        'mismatch_id' => (string) $mismatch->id,
    ]);

    $b->refresh();
    expect($b->getBillingSubscriptionMeta()['country_corrections'] ?? null)->toBeNull();

    $mismatch->refresh();
    expect($mismatch->status)->toBe(CountryMismatchStatus::Pending);
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
