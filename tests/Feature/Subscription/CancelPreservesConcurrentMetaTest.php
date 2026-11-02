<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\DB;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

// CancelSubscription read `subscription_meta` up front, made the Mollie cancel call, and
// then wrote that whole snapshot back. Anything committed in between was reverted — and
// the thing most likely to be committed in between is the nightly sweep's overage charge,
// whose `usage_overage` slot is the in-flight guard `ChargeUsageOverageDirectly::handle()`
// checks. With the slot erased, the immediate cancel's own overage pass charged the same
// unsettled debt a second time.

it('keeps meta a concurrent writer committed during the Mollie cancel call', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Cancel Co',
        'email' => uniqid('cancel-').'@x.test',
        'billing_country' => 'AT',
    ]);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
        'mollie_customer_id' => 'cst_cancel',
        'mollie_mandate_id' => 'mdt_cancel',
        'subscription_meta' => ['mollie_subscription_id' => 'sub_cancel', 'seat_count' => 1],
    ])->save();
    $billable->refresh();

    $snapshot = [
        'payment_id' => 'tr_overage_sweep',
        'line_items' => [['type' => 'tokens', 'quantity' => 10, 'unit_price_net' => 10, 'total_net' => 100]],
        'created_at' => BillingTime::nowUtc()->toIso8601String(),
    ];

    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')
        ->with(Mockery::type(CancelSubscriptionRequest::class))
        ->andReturnUsing(function () use ($billable, $snapshot) {
            // The sweep commits its overage payment while the cancel request is in flight.
            $row = DB::table('test_billables')->where('id', $billable->getKey())->first();
            $meta = json_decode((string) $row->subscription_meta, true);
            $meta[ChargeUsageOverageDirectly::META_KEY] = $snapshot;
            DB::table('test_billables')->where('id', $billable->getKey())
                ->update(['subscription_meta' => json_encode($meta)]);

            return (object) ['id' => 'sub_cancel', 'status' => 'canceled'];
        });

    app(CancelSubscription::class)->handle($billable, immediately: false);

    $meta = $billable->fresh()->getBillingSubscriptionMeta();

    expect($meta[ChargeUsageOverageDirectly::META_KEY]['payment_id'] ?? null)->toBe('tr_overage_sweep')
        ->and($meta['cancelled_from'] ?? null)->not->toBeNull()
        ->and($billable->fresh()->subscription_status)->toBe(SubscriptionStatus::Cancelled);
});
