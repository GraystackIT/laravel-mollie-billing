<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\MandateUpdated;
use GraystackIT\MollieBilling\Events\PlanChangeFailed;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Models\BillingInvoice;
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

it('prorata_charge failed webhook is a no-op when local pending state is already cleared (user-cancel race)', function (): void {
    Event::fake([PlanChangeFailed::class]);

    $b = webhookBillable();
    // Note: no pending_prorata_change / pending_plan_change in meta — simulates
    // a user who hit "cancel pending change" before Mollie reported the final
    // status. The expired/failed webhook must not surface a plan-change-failed
    // toast or notification in this case.
    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_plan_code' => 'business',
        'subscription_interval' => SubscriptionInterval::Monthly,
    ])->save();

    FakeWebhookController::$nextPayment = fakePayment([
        'id' => 'tr_user_cancelled_race',
        'status' => 'expired',
        'metadata' => [
            'type' => 'prorata_charge',
            'billable_type' => $b->getMorphClass(),
            'billable_id' => (string) $b->getKey(),
        ],
    ]);

    $response = $this->postJson(route('billing.webhook'), ['id' => 'tr_user_cancelled_race']);
    $response->assertStatus(200);

    $b->refresh();
    $meta = $b->getBillingSubscriptionMeta();
    expect($meta['plan_change_failed_at'] ?? null)->toBeNull();
    expect($meta['plan_change_failed_reason'] ?? null)->toBeNull();

    Event::assertNotDispatched(PlanChangeFailed::class);
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

it('does not retry-loop when an invoice already exists for the payment (handler crash recovery)', function (): void {
    // Scenario: a previous webhook delivery successfully persisted the invoice but
    // crashed afterwards (e.g. wallet recharge threw). The reservation row was
    // deleted by __invoke()'s catch, so Mollie re-delivers. Without the idempotency
    // guard the second run would hit the unique index on billing_invoices and trap
    // the webhook in a 500-retry loop. With the guard, the handler short-circuits
    // and returns 200 without producing duplicate invoices or side-effects.

    $billable = webhookBillable();
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'mollie_customer_id' => 'cst_test',
        'mollie_mandate_id' => 'mdt_test',
        'subscription_meta' => ['mollie_subscription_id' => 'sub_test'],
    ])->save();

    // Simulate the first run's leftover: invoice persisted, then a crash.
    BillingInvoice::query()->forceCreate([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_renewal_replay',
        'serial_number' => 'IN-26000099',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'DE',
        'currency' => 'EUR',
        'amount_net' => 1000,
        'amount_vat' => 190,
        'amount_gross' => 1190,
        'line_items' => [],
        'refunded_net' => 0,
    ]);

    FakeWebhookController::$nextPayment = fakePayment([
        'id' => 'tr_renewal_replay',
        'status' => 'paid',
        'amount' => (object) ['value' => '11.90', 'currency' => 'EUR'],
        'subscriptionId' => 'sub_test',
        'metadata' => [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ]);

    $this->postJson(route('billing.webhook'), ['id' => 'tr_renewal_replay'])->assertStatus(200);

    // Still exactly one invoice for this payment.
    expect(BillingInvoice::where('mollie_payment_id', 'tr_renewal_replay')->count())->toBe(1);

    // Reservation is final so a third delivery would be skipped at the reserve() level.
    $row = BillingProcessedWebhook::where('mollie_payment_id', 'tr_renewal_replay')->first();
    expect($row)->not->toBeNull();
    expect($row->processed_at)->not->toBeNull();
});
