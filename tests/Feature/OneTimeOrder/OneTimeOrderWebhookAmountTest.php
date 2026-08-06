<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Events\OneTimeOrderCompleted;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;

/**
 * The one-time-order webhook rebuilds its invoice from the catalog at webhook
 * time. It must survive a billable without a billing country (the checkout
 * charged with a fallback) and must never state more than what was collected.
 */
class OneTimeOrderAmountFakeWebhookController extends MollieWebhookController
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
    OneTimeOrderAmountFakeWebhookController::$nextPayment = null;
    $this->app->bind(MollieWebhookController::class, OneTimeOrderAmountFakeWebhookController::class);

    config()->set('mollie-billing-plans.products', [
        'token-pack' => [
            'name' => '500 Token Pack',
            'price_net' => 4900,
            'usage_type' => 'Tokens',
            'quantity' => 500,
        ],
    ]);
});

it('completes a paid order for a billable without a billing country instead of retry-looping', function (): void {
    Event::fake([OneTimeOrderCompleted::class]);

    $billable = new TestBillable;
    $billable->forceFill([
        'email' => 'nocountry@x.test',
        'name' => 'No Country',
        'billing_country' => null,
    ])->save();

    // Checkout charged 49.00 + 19% DE VAT — its own fallback country.
    OneTimeOrderAmountFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_oto_nocountry',
        'status' => 'paid',
        'amount' => (object) ['value' => '58.31', 'currency' => 'EUR'],
        'subscriptionId' => null,
        'metadata' => [
            'type' => 'one_time_order',
            'product_code' => 'token-pack',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_oto_nocountry'])->assertStatus(200);

    $invoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_oto_nocountry')->first();
    expect($invoice)->not->toBeNull()
        ->and($invoice->country)->toBe('DE')
        ->and((int) $invoice->amount_net)->toBe(4900)
        ->and((int) $invoice->amount_gross)->toBe(5831);

    expect((int) $billable->fresh()->getWallet('Tokens')->balanceInt)->toBe(500);

    Event::assertDispatched(OneTimeOrderCompleted::class);
});

it('invoices what was actually paid when the checkout coupon is no longer valid at webhook time', function (): void {
    Event::fake([PaymentAmountMismatch::class]);

    $coupon = app(CouponService::class)->create([
        'code' => 'EXPIRING',
        'name' => 'Half off, expiring',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
        'applicable_products' => ['token-pack'],
    ]);
    // Expired between checkout and webhook.
    $coupon->forceFill(['valid_until' => Carbon::now()->subMinute()])->save();

    $billable = new TestBillable;
    $billable->forceFill([
        'email' => 'expired-coupon@x.test',
        'name' => 'Expired Coupon',
        'billing_country' => 'DE',
    ])->save();

    // Mollie collected the discounted gross: 2450 net + 19% = 29.16.
    OneTimeOrderAmountFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_oto_expired_coupon',
        'status' => 'paid',
        'amount' => (object) ['value' => '29.16', 'currency' => 'EUR'],
        'subscriptionId' => null,
        'metadata' => [
            'type' => 'one_time_order',
            'product_code' => 'token-pack',
            'coupon_codes' => ['EXPIRING'],
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_oto_expired_coupon'])->assertStatus(200);

    $invoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_oto_expired_coupon')->first();
    // Net is the paid gross split at the country's rate; re-deriving the gross
    // from it may land a cent off, which is the tolerance the check itself uses.
    expect($invoice)->not->toBeNull()
        ->and((int) $invoice->amount_net)->toBe(2450)
        ->and(abs((int) $invoice->amount_gross - 2916))->toBeLessThanOrEqual(1);

    // The redemption could not be recorded (the coupon is invalid now), so the
    // difference is booked as an explicit adjustment instead of silently
    // overstating net + VAT in the invoice, the PDF and the OSS export.
    expect(CouponRedemption::query()->count())->toBe(0);

    $kinds = array_map(fn (array $line) => $line['kind'] ?? null, (array) $invoice->line_items);
    expect($kinds)->toContain('adjustment');

    Event::assertDispatched(PaymentAmountMismatch::class, function (PaymentAmountMismatch $e) {
        return $e->paymentId === 'tr_oto_expired_coupon'
            && $e->expectedGross === 5831
            && $e->actualGross === 2916;
    });
});
