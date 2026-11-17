<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\MollieCall;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mollie\Api\Http\Data\Money as MollieMoney;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * No Mollie call may run inside a database transaction.
 *
 * CLAUDE.md states it for the activation path — "anything throwing after it rolled back
 * `mollie_subscription_id` and the invoice while the subscription existed at Mollie" — and
 * the reason generalises: a transaction can be rolled back, an HTTP call to a payment
 * provider cannot. `UpdateSubscription::update()` and `RefundInvoiceService::refund()` both
 * used to hold one transaction across their Mollie calls *and* every local write, so a
 * failure after the money moved undid the local half and the retry moved the money again.
 *
 * `DB::transactionLevel()` at the moment of the call is the only assertion that actually
 * pins this — a comment cannot. It is compared against the level *outside* the call rather
 * than against 0, because `RefreshDatabase` wraps every test in a transaction of its own.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');

    config()->set('mollie-billing-plans.plans', [
        'starter' => [
            'name' => 'Starter',
            'tier' => 1,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => ['monthly' => ['base_price_net' => 900, 'seat_price_net' => 500, 'included_usages' => []]],
        ],
        'pro' => [
            'name' => 'Pro',
            'tier' => 2,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => ['monthly' => ['base_price_net' => 2900, 'seat_price_net' => 500, 'included_usages' => []]],
        ],
    ]);
    config()->set('mollie-billing.vat.seller_country', 'AT');
});

function txBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Transaction Ltd',
        'email' => uniqid('tx-').'@x.test',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
    ]);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'starter',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(8),
        'mollie_customer_id' => 'cst_tx',
        'mollie_mandate_id' => 'mdt_tx',
        'subscription_meta' => ['mollie_subscription_id' => 'sub_tx', 'seat_count' => 1],
    ])->save();

    return $billable->refresh();
}

function txPaidInvoice(TestBillable $billable, int $net = 900, string $planCode = 'starter'): BillingInvoice
{
    return BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => uniqid('tr_tx_'),
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => $net,
        'amount_vat' => (int) round($net * 0.2),
        'amount_gross' => $net + (int) round($net * 0.2),
        'serial_number' => 'IN-'.uniqid(),
        'period_start' => BillingTime::nowUtc()->subDays(8),
        'period_end' => BillingTime::nowUtc()->addDays(22),
        'line_items' => [[
            'kind' => 'plan',
            'code' => $planCode,
            'label' => ucfirst($planCode),
            'quantity' => 1,
            'unit_price_net' => $net,
            'amount_net' => $net,
            'vat_rate' => 20.0,
            'vat_amount' => (int) round($net * 0.2),
            'amount_gross' => $net + (int) round($net * 0.2),
            'period_start' => BillingTime::nowUtc()->subDays(8)->toIso8601String(),
            'period_end' => BillingTime::nowUtc()->addDays(22)->toIso8601String(),
        ]],
    ])->refresh();
}

it('refuses any Mollie request made from inside a transaction', function (): void {
    // The guard itself. Every Mollie request in the package goes through MollieCall, so
    // this one assertion is what turns "no Mollie call inside a transaction" from a rule
    // reviewers have to re-check on every change into one the runtime enforces. Without
    // it the whole mechanism could rot silently — a passing suite would prove nothing.
    Mollie::shouldReceive('send')->never();

    expect(fn () => DB::transaction(fn () => MollieCall::send(new CreatePaymentRequest(
        description: 'nope',
        amount: new MollieMoney('EUR', '1.00'),
    ))))->toThrow(LogicException::class, 'inside a database transaction');
});

it('allows a Mollie request at the ambient transaction depth', function (): void {
    // RefreshDatabase holds a transaction of its own for the whole test, so the guard
    // compares against that depth rather than against 0 — otherwise it would refuse
    // every call in the suite and prove nothing either.
    Mollie::shouldReceive('send')->once()->andReturn((object) ['id' => 'tr_ok']);

    $payment = MollieCall::send(new CreatePaymentRequest(
        description: 'fine',
        amount: new MollieMoney('EUR', '1.00'),
    ));

    expect($payment->id)->toBe('tr_ok');
});

it('creates a plan-change charge with no transaction open', function (): void {
    $billable = txBillable();
    txPaidInvoice($billable);

    $levels = [];
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$levels) {
        if ($request instanceof CreatePaymentRequest || $request instanceof CreatePaymentRefundRequest) {
            $levels[] = DB::transactionLevel();
        }

        return (object) ['id' => 'tr_prorata_tx', 'status' => 'open', 'subscriptionId' => 'sub_tx'];
    });

    $baseline = DB::transactionLevel();

    app(UpdateSubscription::class)->update($billable, ['plan_code' => 'pro']);

    // An upgrade mid-period charges the difference. Whatever the composer produced, the
    // call must not have been wrapped in a transaction that a later throw could roll back.
    expect($levels)->not->toBeEmpty()
        ->and(array_unique($levels))->toBe([$baseline]);
});

it('enables an addon with no transaction open', function (): void {
    // The addon services used to wrap `update()` in `DB::transaction` to serialise their
    // read-modify-write of `active_addon_codes`. That put the whole prorata pipeline —
    // payment, refund, PATCH — inside a transaction a later throw rolls back, while
    // Mollie keeps its half. The serialisation now comes from the change lock instead.
    config()->set('mollie-billing-plans.addons.extra', [
        'name' => 'Extra',
        'feature_keys' => [],
        'intervals' => ['monthly' => ['price_net' => 500]],
    ]);
    config()->set('mollie-billing-plans.plans.starter.allowed_addons', ['extra']);

    $billable = txBillable();
    txPaidInvoice($billable);

    $levels = [];
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$levels) {
        $levels[] = DB::transactionLevel();

        return (object) ['id' => 'tr_addon_tx', 'status' => 'open', 'subscriptionId' => 'sub_tx'];
    });

    $baseline = DB::transactionLevel();

    app(\GraystackIT\MollieBilling\Services\Billing\EnableAddon::class)->handle($billable, 'extra');

    expect($levels)->not->toBeEmpty()
        ->and(array_unique($levels))->toBe([$baseline])
        ->and($billable->fresh()->getActiveBillingAddonCodes())->toBe(['extra']);
});

it('disables an addon with no transaction open', function (): void {
    config()->set('mollie-billing-plans.addons.extra', [
        'name' => 'Extra',
        'feature_keys' => [],
        'intervals' => ['monthly' => ['price_net' => 500]],
    ]);
    config()->set('mollie-billing-plans.plans.starter.allowed_addons', ['extra']);

    $billable = txBillable();
    $billable->forceFill(['active_addon_codes' => ['extra']])->save();
    txPaidInvoice($billable, 1400);

    $levels = [];
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$levels) {
        $levels[] = DB::transactionLevel();

        return (object) ['id' => 're_addon_tx', 'status' => 'active', 'subscriptionId' => 'sub_tx'];
    });

    $baseline = DB::transactionLevel();

    app(\GraystackIT\MollieBilling\Services\Billing\DisableAddon::class)->handle($billable->fresh(), 'extra');

    expect($levels)->not->toBeEmpty()
        ->and(array_unique($levels))->toBe([$baseline])
        ->and($billable->fresh()->getActiveBillingAddonCodes())->toBe([]);
});

it('charges the plan-change overage with no transaction open', function (): void {
    // The wallet rebalance is local and stays in the phase-3 transaction, but the charge
    // for the excess it cannot resolve is a real Mollie payment. Inside the transaction a
    // later throw dropped the `usage_overage` snapshot and the `pending` status while the
    // payment stayed live — unreconcilable by every retry and sweep, which key off exactly
    // that snapshot, and the restored negative balance is charged again by the next sweep.
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.included_usages', ['Tokens' => 100]);
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.usage_overage_prices', ['Tokens' => 5]);
    config()->set('mollie-billing-plans.plans.starter.intervals.monthly.included_usages', ['Tokens' => 10]);
    config()->set('mollie-billing-plans.plans.starter.intervals.monthly.usage_overage_prices', ['Tokens' => 5]);

    $billable = txBillable();
    $billable->forceFill(['subscription_plan_code' => 'pro', 'allows_billing_overage' => true])->save();
    $billable->createWallet(['name' => 'Tokens', 'slug' => 'Tokens'])->forceWithdraw(50);
    txPaidInvoice($billable, 2900, 'pro');

    $overageLevels = [];
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$overageLevels) {
        $payload = $request instanceof CreatePaymentRequest
            ? (array) json_decode((string) $request->payload(), true)
            : [];

        if (($payload['metadata']['type'] ?? null) === 'overage') {
            $overageLevels[] = DB::transactionLevel();
        }

        return (object) ['id' => 'tr_dg_'.count($overageLevels), 'status' => 'open', 'subscriptionId' => 'sub_tx'];
    });

    $baseline = DB::transactionLevel();

    // Phase 1 defers the switch behind a prorata charge; phase 2 applies it once that
    // charge clears, and that is where the wallets are rebalanced.
    app(UpdateSubscription::class)->update($billable->fresh(), ['plan_code' => 'starter']);
    app(UpdateSubscription::class)->applyPendingPlanChange($billable->fresh());

    expect($billable->fresh()->subscription_plan_code)->toBe('starter')
        ->and($overageLevels)->not->toBeEmpty()
        ->and(array_unique($overageLevels))->toBe([$baseline]);

    // And it is still recorded, so a declined payment stays recoverable.
    expect($billable->fresh()->getBillingSubscriptionMeta()['usage_overage_status'] ?? null)->toBe('pending');
});

it('posts an admin refund with no transaction open', function (): void {
    $billable = txBillable();
    $invoice = txPaidInvoice($billable, 2900);

    $levels = [];
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$levels) {
        if ($request instanceof CreatePaymentRefundRequest) {
            $levels[] = DB::transactionLevel();
        }

        return (object) ['id' => 're_tx'];
    });

    $baseline = DB::transactionLevel();

    app(RefundInvoiceService::class)->refund($invoice, [
        'amount_net' => 1000,
        'reason_code' => RefundReasonCode::Goodwill,
        'notify_user' => false,
    ]);

    expect($levels)->toBe([$baseline]);

    // And the booking still happened — the split may not cost the credit note.
    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(1)
        ->and((int) $invoice->fresh()->refunded_net)->toBe(1000);
});

it('still writes the credit note PDF', function (): void {
    $billable = txBillable();
    $invoice = txPaidInvoice($billable, 2900);

    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 're_tx_pdf']);

    // The render moved out of the transaction — it used to hold the invoice row lock and
    // the serial range, which is what made a lock-wait timeout against a concurrent refund
    // realistic. It still has to happen.
    $creditNote = app(RefundInvoiceService::class)->refund($invoice, [
        'amount_net' => 500,
        'reason_code' => RefundReasonCode::Goodwill,
        'notify_user' => false,
    ]);

    expect($creditNote->fresh()->pdf_path)->not->toBeNull();
});

it('posts a coupon period extension after the redemption commits', function (): void {
    $billable = txBillable();

    $coupon = app(\GraystackIT\MollieBilling\Services\Billing\CouponService::class)->create([
        'code' => 'PLUS7',
        'name' => 'Plus 7 days',
        'type' => \GraystackIT\MollieBilling\Enums\CouponType::PeriodExtension,
        'grant_duration_days' => 7,
    ]);

    $levels = [];
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$levels) {
        if ($request instanceof \Mollie\Api\Http\Requests\UpdateSubscriptionRequest) {
            $levels[] = DB::transactionLevel();
        }

        return (object) ['id' => 'sub_tx', 'status' => 'active'];
    });

    $baseline = DB::transactionLevel();

    app(\GraystackIT\MollieBilling\Services\Billing\CouponService::class)
        ->redeem($coupon, $billable->fresh(), []);

    // `redeem()` wraps the redemption row, the incremented counter and the local dates in
    // one transaction, and it dispatches CouponRedeemed inside it. A throw after this PATCH
    // — a listener, the insert itself — undid every local trace while Mollie kept the later
    // charge date: the customer got the free week, the campaign slot was still open, and
    // re-applying the code pushed the date a second time.
    expect($levels)->toBe([$baseline]);

    // And the extension is booked.
    expect((int) \GraystackIT\MollieBilling\Models\CouponRedemption::query()->count())->toBe(1)
        ->and($billable->fresh()->getBillingSubscriptionMeta()['next_charge_date_override'] ?? null)
        ->not->toBeNull();
});

it('posts a trial extension PATCH after the redemption commits', function (): void {
    $billable = txBillable();
    $billable->forceFill([
        'subscription_status' => SubscriptionStatus::Trial,
        'trial_ends_at' => BillingTime::nowUtc()->addDays(3),
    ])->save();

    $coupon = app(\GraystackIT\MollieBilling\Services\Billing\CouponService::class)->create([
        'code' => 'TRIAL14',
        'name' => 'Trial 14',
        'type' => \GraystackIT\MollieBilling\Enums\CouponType::TrialExtension,
        'trial_extension_days' => 14,
    ]);

    $levels = [];
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$levels) {
        if ($request instanceof \Mollie\Api\Http\Requests\UpdateSubscriptionRequest) {
            $levels[] = DB::transactionLevel();
        }

        return (object) ['id' => 'sub_tx', 'status' => 'active'];
    });

    $baseline = DB::transactionLevel();

    app(\GraystackIT\MollieBilling\Services\Billing\CouponService::class)
        ->redeem($coupon, $billable->fresh(), []);

    expect($levels)->toBe([$baseline])
        ->and($billable->fresh()->trial_ends_at->diffInDays(BillingTime::nowUtc()->addDays(17), false))
        ->toBeBetween(-1, 1);
});

/*
 * Reading a payment's refunds and chargebacks is a Mollie request like any other.
 *
 * The webhook handlers used to ask the SDK resource (`$payment->refunds()`,
 * `$payment->chargebacks()`), which sends through the client the payment was hydrated
 * with — past MollieCall, so the guard above never saw those reads. The payment here is a
 * real SDK resource, hydrated from the SDK's own sample (src/Fake/Responses/payment.json)
 * through MollieCall, and Mollie answers both the old route (`_links.refunds` /
 * `_links.chargebacks`, a DynamicGetRequest) and the new one (the typed list requests):
 * against the old code the read inside the transaction goes through unchecked.
 */
function txSdkPaymentWithCredits(): \Mollie\Api\Resources\Payment
{
    $sample = fn (string $name, string $id): array => json_decode(
        str_replace('{{ RESOURCE_ID }}', $id, \Mollie\Api\Fake\FakeResponseLoader::load($name)),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $payment = $sample('payment', 'tr_txlist');
    $payment['status'] = 'paid';
    $payment['amountRefunded'] = ['value' => '5.95', 'currency' => 'EUR'];
    $payment['amountChargedBack'] = ['value' => '5.95', 'currency' => 'EUR'];
    $payment['_links']['refunds'] = ['href' => 'https://api.mollie.com/v2/payments/tr_txlist/refunds', 'type' => 'application/hal+json'];
    $payment['_links']['chargebacks'] = ['href' => 'https://api.mollie.com/v2/payments/tr_txlist/chargebacks', 'type' => 'application/hal+json'];

    $list = fn (string $key, array $item): array => [
        'count' => 1,
        '_embedded' => [$key => [$item]],
        '_links' => ['self' => ['href' => '...', 'type' => 'application/hal+json'], 'previous' => null, 'next' => null],
    ];
    $refunds = $list('refunds', $sample('refund', 're_txlist'));
    $chargebacks = $list('chargebacks', $sample('chargeback', 'chb_txlist'));

    Mollie::fake([
        \Mollie\Api\Http\Requests\GetPaymentRequest::class => \Mollie\Api\Fake\MockResponse::ok($payment),
        \Mollie\Api\Http\Requests\DynamicGetRequest::class => fn (\Mollie\Api\Http\PendingRequest $r): \Mollie\Api\Fake\MockResponse => \Mollie\Api\Fake\MockResponse::ok(
            str_ends_with($r->getRequest()->resolveResourcePath(), '/chargebacks') ? $chargebacks : $refunds,
        ),
        \Mollie\Api\Http\Requests\GetPaginatedPaymentRefundsRequest::class => \Mollie\Api\Fake\MockResponse::ok($refunds),
        \Mollie\Api\Http\Requests\GetPaginatedPaymentChargebacksRequest::class => \Mollie\Api\Fake\MockResponse::ok($chargebacks),
    ]);

    return MollieCall::send(new \Mollie\Api\Http\Requests\GetPaymentRequest('tr_txlist'));
}

it('reads a payment\'s refunds through the guard', function (): void {
    $billable = txBillable();
    $payment = txSdkPaymentWithCredits();

    expect(fn () => DB::transaction(fn () => app(\GraystackIT\MollieBilling\Services\Webhook\RefundHandler::class)->handle($payment, $billable)))
        ->toThrow(LogicException::class, 'GetPaginatedPaymentRefundsRequest was made inside a database transaction');
});

it('reads a payment\'s chargebacks through the guard', function (): void {
    $billable = txBillable();
    $payment = txSdkPaymentWithCredits();

    expect(fn () => DB::transaction(fn () => app(\GraystackIT\MollieBilling\Services\Webhook\ChargebackHandler::class)->handle($payment, $billable)))
        ->toThrow(LogicException::class, 'GetPaginatedPaymentChargebacksRequest was made inside a database transaction');
});

it('reads both lists at the ambient depth, as typed requests', function (): void {
    $payment = txSdkPaymentWithCredits();
    $credits = app(\GraystackIT\MollieBilling\Support\MolliePaymentCredits::class);

    expect(collect($credits->refunds($payment))->pluck('id')->all())->toBe(['re_txlist'])
        ->and(collect($credits->chargebacks($payment))->pluck('id')->all())->toBe(['chb_txlist']);

    Mollie::assertSent(\Mollie\Api\Http\Requests\GetPaginatedPaymentRefundsRequest::class);
    Mollie::assertSent(\Mollie\Api\Http\Requests\GetPaginatedPaymentChargebacksRequest::class);
});
