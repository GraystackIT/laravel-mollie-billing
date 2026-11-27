<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\StartGrantExtrasPurchase;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Laravel\Facades\Mollie;

/*
 * The upgrade from a free plan and the purchase of extras on a gift start a
 * `sequenceType: first` payment just like the checkout — and, unlike the checkout, never
 * asked about the one already on record. Their submit lock is released as soon as the
 * payment exists, so "Back from Mollie and confirm again" minted a second payment: both
 * could be paid, the first upgraded the billable, and the second was kept as a duplicate
 * with no invoice and no refund.
 */
beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans', [
        'free' => [
            'name' => 'Free', 'tier' => 0, 'included_seats' => 1,
            'feature_keys' => [], 'allowed_addons' => ['sso'],
            'intervals' => ['monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []]],
        ],
        'pro' => [
            'name' => 'Pro', 'tier' => 2, 'included_seats' => 1,
            'feature_keys' => [], 'allowed_addons' => ['sso'],
            'intervals' => ['monthly' => ['base_price_net' => 2900, 'seat_price_net' => null, 'included_usages' => []]],
        ],
    ]);
    config()->set('mollie-billing-plans.addons', [
        'sso' => ['name' => 'SSO', 'feature_keys' => [], 'intervals' => ['monthly' => ['price_net' => 1500]]],
    ]);

    MollieBilling::authUsing(fn (): bool => true);

    $resolver = Mockery::mock(\GraystackIT\MollieBilling\Support\MollieCustomerResolver::class);
    $resolver->shouldReceive('resolve')->andReturn('cst_reuse');
    app()->instance(\GraystackIT\MollieBilling\Support\MollieCustomerResolver::class, $resolver);
});

/**
 * Mollie answers: the probe sees the payment on record still open with its link, a create
 * mints a new one. Every request is recorded.
 *
 * @param  array<string, mixed>  $metadata  what the payment on record carries
 */
function reuseMollie(ArrayObject $sent, string $onRecord, array $metadata, string $value): void
{
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use ($sent, $onRecord, $metadata, $value) {
        $sent[] = $request;

        $probe = $request instanceof GetPaymentRequest;

        return new class($probe ? $onRecord : 'tr_second', $probe ? $metadata : [], $value)
        {
            public string $status = 'open';

            public object $metadata;

            public object $amount;

            public function __construct(public string $id, array $metadata, string $value)
            {
                $this->metadata = json_decode((string) json_encode((object) $metadata));
                $this->amount = (object) ['currency' => 'EUR', 'value' => $value];
            }

            public function getCheckoutUrl(): string
            {
                return 'https://mollie.test/checkout/'.$this->id;
            }
        };
    });
}

it('sends a free-plan customer who confirms the same upgrade again back to the open payment', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Upgrade Twice', 'email' => uniqid('up-').'@x.test', 'billing_country' => 'AT', 'mollie_customer_id' => 'cst_reuse']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'free',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'subscription_meta' => ['seat_count' => 1],
    ])->save();
    $billable = $billable->refresh();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    // The first "Confirm and pay" created tr_first; the customer came back from Mollie.
    $billable->recordPendingFirstPayment('tr_first');

    $sent = new ArrayObject;
    reuseMollie($sent, 'tr_first', [
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => (string) $billable->getKey(),
        'type' => 'subscription',
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'addon_codes' => [],
        'extra_seats' => 0,
        'coupon_code' => null,
        'upgrade_from_local' => true,
    ], '34.80');

    Livewire::test('mollie-billing::plan-change')
        ->call('previewFor', 'pro')
        ->call('confirmAndPay')
        ->assertRedirect('https://mollie.test/checkout/tr_first');

    expect(collect($sent)->contains(fn ($r) => $r instanceof CreatePaymentRequest))->toBeFalse()
        ->and($billable->fresh()->getPendingFirstPaymentId())->toBe('tr_first');
});

it('sends a gifted tenant who starts the same extras purchase again back to the open payment', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Gift Twice', 'email' => uniqid('gift-twice-').'@x.test', 'billing_country' => 'AT']);
    $coupons = app(CouponService::class);
    $code = 'GRANT-'.strtoupper(bin2hex(random_bytes(3)));
    $coupons->redeem($coupons->accessGrantCoupon($code, 'pro', 'monthly', [], 60), $billable->fresh(), []);
    $billable = $billable->fresh();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    $billable->recordPendingFirstPayment('tr_gift_first');

    $sent = new ArrayObject;
    reuseMollie($sent, 'tr_gift_first', [
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => (string) $billable->getKey(),
        'type' => 'subscription',
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'addon_codes' => ['sso'],
        'extra_seats' => 0,
        'coupon_code' => null,
        'upgrade_from_local' => true,
        'keep_grant' => true,
        'granted_addon_codes' => [],
    ], '18.00');

    $url = app(StartGrantExtrasPurchase::class)->handle($billable, ['sso'], 1);

    expect($url)->toBe('https://mollie.test/checkout/tr_gift_first')
        ->and(collect($sent)->contains(fn ($r) => $r instanceof CreatePaymentRequest))->toBeFalse()
        ->and($billable->fresh()->getPendingFirstPaymentId())->toBe('tr_gift_first');
});
