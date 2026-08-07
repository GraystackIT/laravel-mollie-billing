<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;

/**
 * A single-charge payment without `line_items` metadata (a legacy payment in
 * flight across a package upgrade) carries only Mollie's GROSS amount. The
 * fallback must derive the net from the invoice country's rate — booking the
 * gross as net would add VAT on top of VAT that was already collected.
 */
class LegacyFallbackFakeWebhookController extends MollieWebhookController
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
    LegacyFallbackFakeWebhookController::$nextPayment = null;
    $this->app->bind(MollieWebhookController::class, LegacyFallbackFakeWebhookController::class);
});

it('derives the net from the paid gross for a legacy single charge without line_items metadata', function (): void {
    Event::fake([PaymentAmountMismatch::class]);

    $billable = new TestBillable;
    $billable->forceFill([
        'email' => 'legacy@x.test',
        'name' => 'Legacy',
        'billing_country' => 'DE',
        'mollie_customer_id' => 'cst_legacy',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
        'subscription_meta' => [
            'mollie_subscription_id' => 'sub_legacy',
            'usage_overage' => ['Tokens' => 5],
            'usage_overage_status' => 'pending',
        ],
    ])->save();

    // 11.90 gross collected at DE 19% → the invoice must book net 1000 / VAT
    // 190 / gross 1190, not net 1190 / VAT 226 / gross 1416.
    LegacyFallbackFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_legacy_overage',
        'status' => 'paid',
        'amount' => (object) ['value' => '11.90', 'currency' => 'EUR'],
        'subscriptionId' => null,
        'metadata' => [
            'type' => 'overage',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_legacy_overage'])->assertStatus(200);

    $invoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_legacy_overage')->first();
    expect($invoice)->not->toBeNull()
        ->and((int) $invoice->amount_net)->toBe(1000)
        ->and((int) $invoice->amount_vat)->toBe(190)
        ->and((int) $invoice->amount_gross)->toBe(1190);

    $lines = (array) $invoice->line_items;
    $overageLine = collect($lines)->firstWhere('kind', 'overage');
    expect($overageLine)->not->toBeNull()
        ->and((int) $overageLine['total_net'])->toBe(1000);

    // The derived split matches the collection exactly, so no adjustment line
    // and no mismatch report are warranted.
    expect(collect($lines)->firstWhere('kind', 'adjustment'))->toBeNull();
    Event::assertNotDispatched(PaymentAmountMismatch::class);

    // The overage bookkeeping the paid handler owns still ran.
    $meta = $billable->fresh()->getBillingSubscriptionMeta();
    expect($meta)->not->toHaveKey('usage_overage')
        ->and($meta)->not->toHaveKey('usage_overage_status');
});
