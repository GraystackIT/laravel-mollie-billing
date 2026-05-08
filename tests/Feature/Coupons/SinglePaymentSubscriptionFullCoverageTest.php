<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\MandateUpdated;
use GraystackIT\MollieBilling\Events\SubscriptionCreated;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

class SubFullCoverageWebhookController extends MollieWebhookController
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
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 1000,
                'seat_price_net' => null,
                'included_usages' => ['Tokens' => 1000],
            ],
        ],
    ]);

    SubFullCoverageWebhookController::$nextPayment = null;
    $this->app->bind(MollieWebhookController::class, SubFullCoverageWebhookController::class);
});

it('StartSubscriptionCheckout routes amount_gross=0 to the Mandate-Only flow with the subscription spec in metadata', function (): void {
    app(CouponService::class)->create([
        'code' => 'FREE100',
        'name' => 'Free Pro',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
    ]);

    $billable = TestBillable::create([
        'name' => 'Free Sub',
        'email' => 'freesub@x.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cust_pro',
    ]);

    $captured = null;
    Mollie::shouldReceive('send')->once()->andReturnUsing(function ($request) use (&$captured) {
        $captured = $request;

        return new class {
            public string $id = 'tr_mandate_pro';

            public function getCheckoutUrl(): string
            {
                return 'https://checkout.mollie.com/mandate-pro';
            }
        };
    });

    $result = app(StartSubscriptionCheckout::class)->handle($billable->fresh(), [
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'amount_gross' => 0,
        'coupon_code' => 'FREE100',
    ]);

    expect($captured)->toBeInstanceOf(CreatePaymentRequest::class);

    $reflection = new ReflectionObject($captured);
    $amountProp = $reflection->getProperty('amount');
    $amountProp->setAccessible(true);
    $amount = $amountProp->getValue($captured);
    expect($amount->value)->toBe('0.00');

    $metadataProp = $reflection->getProperty('metadata');
    $metadataProp->setAccessible(true);
    $metadata = $metadataProp->getValue($captured);

    expect($metadata['type'])->toBe('mandate_only')
        ->and($metadata['pending_subscription_plan_code'])->toBe('pro')
        ->and($metadata['pending_subscription_interval'])->toBe('monthly')
        ->and($metadata['pending_subscription_coupon_code'])->toBe('FREE100');

    expect($result['checkout_url'])->toBe('https://checkout.mollie.com/mandate-pro')
        ->and($result['payment_id'])->toBe('tr_mandate_pro');
});

it('Mandate-Only webhook with pending_subscription metadata activates the subscription, writes a 0-EUR audit invoice, and redeems the coupon', function (): void {
    Event::fake([MandateUpdated::class, SubscriptionCreated::class]);

    $coupon = app(CouponService::class)->create([
        'code' => 'FREE100',
        'name' => 'Free Pro',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
    ]);

    $billable = TestBillable::create([
        'name' => 'Webhook Sub',
        'email' => 'whsub@x.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cust_wh',
    ]);

    SubFullCoverageWebhookController::$nextPayment = (object) [
        'id' => 'tr_mandate_wh',
        'status' => 'paid',
        'amount' => (object) ['value' => '0.00', 'currency' => 'EUR'],
        'subscriptionId' => null,
        'customerId' => 'cust_wh',
        'mandateId' => 'mdt_wh',
        'countryCode' => 'AT',
        'paidAt' => null,
        'metadata' => [
            'type' => 'mandate_only',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'pending_subscription_plan_code' => 'pro',
            'pending_subscription_interval' => 'monthly',
            'pending_subscription_addon_codes' => [],
            'pending_subscription_extra_seats' => 0,
            'pending_subscription_coupon_code' => 'FREE100',
        ],
    ];

    // CreateSubscription will call Mollie::send(CreateSubscriptionRequest) — stub it.
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) {
        if ($request instanceof CreateSubscriptionRequest) {
            return (object) ['id' => 'sub_full_cov'];
        }

        return (object) ['id' => 'unexpected'];
    });

    $response = $this->postJson(route('billing.webhook'), ['id' => 'tr_mandate_wh']);
    $response->assertStatus(200);

    $billable->refresh();

    // Mandate captured.
    expect($billable->mollie_mandate_id)->toBe('mdt_wh');

    // Subscription is active in the local DB.
    expect($billable->getBillingSubscriptionSource())->toBe(SubscriptionSource::Mollie->value)
        ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and($billable->getBillingSubscriptionInterval())->toBe('monthly');

    // Local 0-EUR audit invoice with plan + coupon line.
    $invoice = BillingInvoice::query()
        ->where('billable_id', $billable->getKey())
        ->where('invoice_kind', InvoiceKind::Subscription)
        ->latest('id')
        ->first();
    expect($invoice)->not->toBeNull()
        ->and((int) $invoice->amount_gross)->toBe(0)
        ->and((int) $invoice->amount_net)->toBe(0)
        ->and($invoice->status)->toBe(InvoiceStatus::Paid);

    $kinds = array_map(fn (array $line) => $line['kind'] ?? null, (array) $invoice->line_items);
    expect($kinds)->toContain('plan')
        ->and($kinds)->toContain('coupon');

    // Coupon redeemed against the audit invoice.
    $redemption = CouponRedemption::query()
        ->where('coupon_id', $coupon->id)
        ->latest('id')
        ->first();
    expect($redemption)->not->toBeNull()
        ->and((int) $redemption->discount_amount_net)->toBe(1000)
        ->and((int) $redemption->invoice_id)->toBe((int) $invoice->id);

    // Wallet hydrated from the plan's included_usages.
    $wallet = $billable->getWallet('Tokens');
    expect($wallet)->not->toBeNull()
        ->and((int) $wallet->balanceInt)->toBe(1000);

    Event::assertDispatched(MandateUpdated::class);
    Event::assertDispatched(SubscriptionCreated::class);
});
