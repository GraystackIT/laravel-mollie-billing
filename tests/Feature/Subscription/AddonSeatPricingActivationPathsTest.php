<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Testing\LifecycleSimulator;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * Two paths that price a subscription with a per-seat addon and had no test doing so:
 * the trial activation (`MandateOnlyPaymentHandler` → `CreateSubscription`) and the
 * staging simulator's renewal. The expected figures are worked out by hand here, not
 * through `SubscriptionAmount`, so a shared mistake cannot agree with itself.
 *
 *   Solo, 4 seats (1 included), CRM addon — net:
 *     plan 1 000 + 3 extra seats × 500 + CRM 2 000 + CRM per seat 4 × 500 = 6 500
 *   AT, 20 %, VAT per line: 200 + 300 + 400 + 400 = 1 300 → gross 7 800
 */
class AddonSeatActivationWebhookController extends MollieWebhookController
{
    public static ?object $nextPayment = null;

    protected function fetchPayment(string $paymentId): object
    {
        return self::$nextPayment ?? throw new RuntimeException('No payment stub configured.');
    }
}

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
    config()->set('mollie-billing.invoices.disk', 'local');
    config()->set('mollie-billing.vat.seller_country', 'AT');

    AddonSeatActivationWebhookController::$nextPayment = null;
    $this->app->bind(MollieWebhookController::class, AddonSeatActivationWebhookController::class);

    config()->set('mollie-billing-plans.plans.solo', [
        'name' => 'Solo', 'tier' => 1, 'included_seats' => 1,
        'feature_keys' => [], 'allowed_addons' => ['crm'],
        'intervals' => ['monthly' => ['base_price_net' => 1000, 'seat_price_net' => 500, 'trial_days' => 14, 'included_usages' => []]],
    ]);
    config()->set('mollie-billing-plans.addons', [
        'crm' => ['name' => 'CRM', 'feature_keys' => [], 'intervals' => ['monthly' => ['price_net' => 2000, 'seat_price_net' => 500]]],
    ]);
});

it('creates the trial subscription at the amount a per-seat addon makes it', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Trial Seats Co',
        'email' => 'addon-seat-trial@x.test',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
    ]);
    $billable->forceFill(['mollie_customer_id' => 'cst_addon_trial'])->save();

    $created = null;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$created) {
        if ($request instanceof CreateSubscriptionRequest) {
            $created = $request;
        }

        return (object) ['id' => 'sub_addon_trial'];
    });

    AddonSeatActivationWebhookController::$nextPayment = (object) [
        'id' => 'tr_addon_trial_mandate',
        'status' => 'paid',
        'amount' => (object) ['value' => '0.00', 'currency' => 'EUR'],
        'metadata' => [
            'type' => 'mandate_only',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'pending_subscription_plan_code' => 'solo',
            'pending_subscription_interval' => 'monthly',
            'pending_subscription_addon_codes' => ['crm'],
            'pending_subscription_extra_seats' => 3,
            'pending_subscription_trial_days' => 14,
        ],
        'subscriptionId' => null,
        'customerId' => 'cst_addon_trial',
        'mandateId' => 'mdt_addon_trial',
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_addon_trial_mandate'])->assertStatus(200);

    $billable->refresh();

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Trial)
        ->and($billable->getActiveBillingAddonCodes())->toBe(['crm'])
        ->and($billable->getBillingSeatCount())->toBe(4);

    expect($created)->toBeInstanceOf(CreateSubscriptionRequest::class);

    $amount = (new ReflectionObject($created))->getProperty('amount');
    $amount->setAccessible(true);

    // What Mollie will collect when the trial ends, every period from then on.
    expect((string) $amount->getValue($created)->value)->toBe('78.00');
});

it('simulates a renewal for a per-seat addon at the amount the invoice then books', function (): void {
    Event::fake([PaymentAmountMismatch::class]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Sim Seats Co',
        'email' => 'addon-seat-sim@x.test',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
    ]);
    $billable->forceFill([
        'mollie_customer_id' => 'cst_addon_sim',
        'mollie_mandate_id' => 'mdt_addon_sim',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'solo',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subMonth(),
        'active_addon_codes' => ['crm'],
        'subscription_meta' => ['seat_count' => 4, 'mollie_subscription_id' => 'sub_addon_sim'],
    ])->save();

    $charged = null;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$charged) {
        if ($request instanceof CreatePaymentRequest) {
            $amount = (new ReflectionObject($request))->getProperty('amount');
            $amount->setAccessible(true);
            $charged = (string) $amount->getValue($request)->value;

            return (object) ['id' => 'tr_addon_sim', 'status' => 'open'];
        }

        if ($request instanceof GetPaymentRequest) {
            return (object) [
                'id' => 'tr_addon_sim',
                'status' => 'paid',
                'amount' => (object) ['value' => $charged, 'currency' => 'EUR'],
                'paidAt' => now()->toIso8601String(),
                'countryCode' => 'AT',
                'metadata' => null,
            ];
        }

        return (object) ['id' => 'obj_addon_sim'];
    });

    $result = app(LifecycleSimulator::class)->renewal($billable->refresh());

    expect($charged)->toBe('78.00')
        ->and($result['status'])->toBe('paid');

    $invoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_addon_sim')->firstOrFail();
    $lines = collect($invoice->line_items)->mapWithKeys(
        fn (array $line): array => [(string) $line['kind'] => (int) ($line['amount_net'] ?? $line['total_net'] ?? 0)],
    );

    expect((int) $invoice->amount_net)->toBe(6500)
        ->and((int) $invoice->amount_gross)->toBe(7800)
        ->and($lines->all())->toBe(['plan' => 1000, 'seats' => 1500, 'addon' => 2000, 'addon_seats' => 2000]);

    // The simulated charge reconciled against the invoice without a correction.
    Event::assertNotDispatched(PaymentAmountMismatch::class);
});
