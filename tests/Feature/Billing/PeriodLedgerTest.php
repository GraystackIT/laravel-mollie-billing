<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Storage;
use Mollie\Laravel\Facades\Mollie;

/**
 * The books, across several periods.
 *
 * Every other test here pins one step. This one walks a subscription through renewals, an
 * overage charge, a coupon and the end of its life, and checks after each step that the
 * ledger still adds up: every invoice's parts agree with its header, the sum of what was
 * invoiced equals the sum of what Mollie was asked for, the wallet balance is explainable,
 * and the period has moved exactly one interval. Arithmetic that is individually plausible
 * can still drift across periods — that is what this looks for.
 */
class LedgerFakeWebhookController extends MollieWebhookController
{
    public static ?object $nextPayment = null;

    protected function fetchPayment(string $paymentId): object
    {
        return self::$nextPayment ?? throw new RuntimeException('No payment stub configured.');
    }
}

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
    config()->set('mollie-billing.currency', 'EUR');
    config()->set('mollie-billing.vat.seller_country', 'AT');

    LedgerFakeWebhookController::$nextPayment = null;
    app()->bind(MollieWebhookController::class, LedgerFakeWebhookController::class);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => ['analytics'],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 2900,
                'seat_price_net' => 500,
                'included_usages' => ['Tokens' => 1000],
                'usage_overage_prices' => ['Tokens' => 2],
            ],
        ],
    ]);
    config()->set('mollie-billing-plans.addons', [
        'analytics' => ['name' => 'Analytics', 'feature_keys' => [], 'intervals' => ['monthly' => ['price_net' => 900]]],
    ]);
    config()->set('mollie-billing-plans.usage_types', ['Tokens' => ['rollover' => false]]);

    // Renewals arrive through the fake controller, but an overage charge posts a payment of
    // its own — that is the one call that reaches Mollie here.
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturnUsing(fn () => (object) [
        'id' => 'tr_ledger_overage',
        'status' => 'open',
        'getCheckoutUrl' => null,
    ]);
});

/** Austria, 20% B2C, one paid extra seat and one addon: 2900 + 500 + 900 = 4300 net. */
function ledgerBillable(string $periodStart = '2026-01-01T09:00:00Z'): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Ledger Ltd',
        'email' => uniqid('ledger-').'@x.test',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
    ]);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::toUtc($periodStart),
        'active_addon_codes' => ['analytics'],
        'mollie_customer_id' => 'cst_ledger',
        'mollie_mandate_id' => 'mdt_ledger',
        'subscription_meta' => ['mollie_subscription_id' => 'sub_ledger', 'seat_count' => 2],
    ])->save();

    return $billable->refresh();
}

function deliverLedgerRenewal(TestBillable $billable, string $paymentId, string $paidAt, string $gross): void
{
    LedgerFakeWebhookController::$nextPayment = (object) [
        'id' => $paymentId,
        'status' => 'paid',
        'amount' => (object) ['value' => $gross, 'currency' => 'EUR'],
        'paidAt' => $paidAt,
        'subscriptionId' => 'sub_ledger',
        'countryCode' => 'AT',
        'metadata' => [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ];

    test()->postJson(route('billing.webhook'), ['id' => $paymentId])->assertStatus(200);
}

/** Every invoice's own parts have to agree: lines sum to the header, net + VAT = gross. */
function assertInvoicesInternallyConsistent(): void
{
    foreach (BillingInvoice::all() as $invoice) {
        expect((int) $invoice->amount_net + (int) $invoice->amount_vat)
            ->toBe((int) $invoice->amount_gross, "invoice {$invoice->serial_number}: net + VAT ≠ gross");

        $lines = (array) ($invoice->line_items ?? []);

        if ($lines === []) {
            continue;
        }

        $lineNet = array_sum(array_map(fn (array $l) => (int) ($l['amount_net'] ?? 0), $lines));

        expect($lineNet)->toBe(
            (int) $invoice->amount_net,
            "invoice {$invoice->serial_number}: line items sum to {$lineNet}, header says {$invoice->amount_net}",
        );
    }
}

it('books three consecutive renewals with an overage charge in between', function (): void {
    $billable = ledgerBillable();

    // 4300 net + 20% = 5160 gross.
    expect($billable->nextBillingDate()->toIso8601String())->toBe('2026-02-01T09:00:00+00:00');

    // ── Period 1 → 2 ────────────────────────────────────────────────────────────────
    deliverLedgerRenewal($billable, 'tr_ledger_1', '2026-02-01T09:00:05Z', '51.60');

    $first = BillingInvoice::query()->latest('id')->first();

    expect((int) $first->amount_net)->toBe(4300)
        ->and((int) $first->amount_vat)->toBe(860)
        ->and((int) $first->amount_gross)->toBe(5160)
        ->and($billable->refresh()->subscription_period_starts_at->toIso8601String())->toBe('2026-02-01T09:00:05+00:00')
        // The renewal recharges the quota it just billed for.
        ->and((int) $billable->getWallet('Tokens')->balanceInt)->toBe(1000);

    // 1037 tokens consumed: 37 over the included 1000.
    $billable->recordBillingUsage('Tokens', 1037);
    expect((int) $billable->fresh()->getWallet('Tokens')->balanceInt)->toBe(-37);

    // ── The overage charge: 37 × 2 = 74 net, +20% = 89 gross ────────────────────────
    app(ChargeUsageOverageDirectly::class)->handle($billable->fresh());

    $snapshot = $billable->fresh()->getBillingSubscriptionMeta()['usage_overage'] ?? [];

    expect((int) ($snapshot['amount_net'] ?? 0))->toBe(74)
        ->and((int) ($snapshot['amount_gross'] ?? 0))->toBe(89);

    // Settling it credits back exactly the units that were charged, not the whole debt.
    LedgerFakeWebhookController::$nextPayment = (object) [
        'id' => (string) $snapshot['payment_id'],
        'status' => 'paid',
        'amount' => (object) ['value' => '0.89', 'currency' => 'EUR'],
        'paidAt' => '2026-02-20T10:00:00Z',
        'subscriptionId' => null,
        'countryCode' => 'AT',
        'metadata' => [
            'type' => 'overage',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'line_items' => $snapshot['line_items'],
        ],
    ];
    $this->postJson(route('billing.webhook'), ['id' => (string) $snapshot['payment_id']])->assertStatus(200);

    expect((int) $billable->fresh()->getWallet('Tokens')->balanceInt)->toBe(0);

    $overageInvoice = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Overage)->sole();
    expect((int) $overageInvoice->amount_net)->toBe(74)
        ->and((int) $overageInvoice->amount_gross)->toBe(89);

    // ── Period 2 → 3 and 3 → 4: the anchor walks, it does not drift ─────────────────
    deliverLedgerRenewal($billable->refresh(), 'tr_ledger_2', '2026-03-01T09:00:05Z', '51.60');
    deliverLedgerRenewal($billable->refresh(), 'tr_ledger_3', '2026-04-01T09:00:05Z', '51.60');

    expect($billable->refresh()->subscription_period_starts_at->toIso8601String())->toBe('2026-04-01T09:00:05+00:00')
        ->and((int) $billable->getWallet('Tokens')->balanceInt)->toBe(1000);

    // Three renewals plus one overage charge, and every one of them adds up.
    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Subscription)->count())->toBe(3)
        ->and(BillingInvoice::query()->count())->toBe(4);

    assertInvoicesInternallyConsistent();

    // What was invoiced is what Mollie was asked for: 3 × 51.60 + 0.89.
    expect((int) BillingInvoice::query()->where('status', InvoiceStatus::Paid)->sum('amount_gross'))
        ->toBe(3 * 5160 + 89);
});

it('carries an unsettled overage debt into the next period instead of clearing it', function (): void {
    $billable = ledgerBillable();

    $billable->recordBillingUsage('Tokens', 0);
    app(\GraystackIT\MollieBilling\Services\Wallet\WalletUsageService::class)
        ->credit($billable, 'Tokens', 1000, 'seed');
    $billable->recordBillingUsage('Tokens', 1050);

    expect((int) $billable->fresh()->getWallet('Tokens')->balanceInt)->toBe(-50);

    // A charge exists but has not settled — `hasUnsettledOverageFor()` is what makes the
    // renewal preserve the debt, so the customer cannot renew their way out of it.
    app(ChargeUsageOverageDirectly::class)->handle($billable->fresh());

    deliverLedgerRenewal($billable->refresh(), 'tr_ledger_debt', '2026-02-01T09:00:05Z', '51.60');

    // 1000 fresh minus the 50 still owed.
    expect((int) $billable->fresh()->getWallet('Tokens')->balanceInt)->toBe(950);
});

it('applies an active recurring coupon to every renewal at the right amount', function (): void {
    $coupon = Coupon::create([
        'code' => 'LEDGER20',
        'name' => 'Ledger 20',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 20,
        'active' => true,
        'stackable' => false,
        'redemptions_count' => 1,
    ]);

    $billable = ledgerBillable();
    $meta = $billable->getBillingSubscriptionMeta();
    $meta['active_recurring_coupon'] = [
        'coupon_id' => $coupon->id,
        'code' => 'LEDGER20',
        'discount_type' => 'percentage',
        'discount_value' => 20,
        'base_amount_net' => 4300,
    ];
    $billable->forceFill(['subscription_meta' => $meta])->save();

    // 20% of 4300 = 860 off, so 3440 net + 688 VAT = 4128 gross.
    deliverLedgerRenewal($billable->refresh(), 'tr_ledger_coupon', '2026-02-01T09:00:05Z', '41.28');

    $invoice = BillingInvoice::query()->latest('id')->first();

    expect((int) $invoice->amount_net)->toBe(3440)
        ->and((int) $invoice->amount_vat)->toBe(688)
        ->and((int) $invoice->amount_gross)->toBe(4128);

    // The discount is a line on the invoice, not a silent reduction of the plan line.
    $kinds = array_map(fn (array $l) => (string) ($l['kind'] ?? ''), (array) $invoice->line_items);
    expect($kinds)->toContain('coupon');

    assertInvoicesInternallyConsistent();

    // And the redemption is recorded against this invoice, with the amount that was
    // actually discounted — otherwise per-billable limits and reporting drift.
    $redemption = CouponRedemption::query()->where('coupon_id', $coupon->id)->latest('id')->first();

    expect($redemption)->not->toBeNull()
        ->and((int) $redemption->discount_amount_net)->toBe(860)
        ->and((int) $redemption->invoice_id)->toBe((int) $invoice->id);
});

it('charges the prorated difference for a mid-period upgrade, not a full period', function (): void {
    config()->set('mollie-billing-plans.plans.enterprise', [
        'name' => 'Enterprise',
        'tier' => 3,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => ['analytics'],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 5900,
                'seat_price_net' => 500,
                'included_usages' => ['Tokens' => 5000],
                'usage_overage_prices' => ['Tokens' => 2],
            ],
        ],
    ]);

    // A 31-day period (1 Jan → 1 Feb) with the change on the 11th: 21 days remain, and the
    // current day counts as remaining, so the factor is 21/31.
    $billable = ledgerBillable('2026-01-01T09:00:00Z');
    $this->travelTo(BillingTime::toUtc('2026-01-11T12:00:00Z'));

    // The invoice that paid for the current period — the composer credits against it.
    BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_ledger_period',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 4300,
        'amount_vat' => 860,
        'amount_gross' => 5160,
        'serial_number' => 'IN-LEDGER-1',
        'period_start' => BillingTime::toUtc('2026-01-01T09:00:00Z'),
        'period_end' => BillingTime::toUtc('2026-02-01T09:00:00Z'),
        'line_items' => [
            ['kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1, 'unit_price_net' => 2900, 'amount_net' => 2900, 'vat_rate' => 20.0, 'vat_amount' => 580, 'amount_gross' => 3480, 'period_start' => '2026-01-01T09:00:00+00:00', 'period_end' => '2026-02-01T09:00:00+00:00'],
            ['kind' => 'seats', 'code' => null, 'label' => 'Extra seat', 'quantity' => 1, 'unit_price_net' => 500, 'amount_net' => 500, 'vat_rate' => 20.0, 'vat_amount' => 100, 'amount_gross' => 600, 'period_start' => '2026-01-01T09:00:00+00:00', 'period_end' => '2026-02-01T09:00:00+00:00'],
            ['kind' => 'addon', 'code' => 'analytics', 'label' => 'Analytics', 'quantity' => 1, 'unit_price_net' => 900, 'amount_net' => 900, 'vat_rate' => 20.0, 'vat_amount' => 180, 'amount_gross' => 1080, 'period_start' => '2026-01-01T09:00:00+00:00', 'period_end' => '2026-02-01T09:00:00+00:00'],
        ],
    ]);

    $preview = \GraystackIT\MollieBilling\Facades\MollieBilling::preview()
        ->previewUpdate($billable->fresh(), ['plan_code' => 'enterprise']);

    // pro+seat+addon = 4300, enterprise+seat+addon = 7300. The delta is 3000, and
    // 3000 × 21/31 = 2032.26 → 2032.
    expect($preview['prorataChargeNet'])->toBe(2032)
        ->and($preview['prorataFactor'])->toBe(round(21 / 31, 6));

    // And the charge the customer is actually asked for matches the quote.
    app(\GraystackIT\MollieBilling\Services\Billing\UpdateSubscription::class)
        ->update($billable->fresh(), ['plan_code' => 'enterprise']);

    $charge = BillingInvoice::query()
        ->where('invoice_kind', InvoiceKind::Subscription)
        ->where('mollie_payment_id', '!=', 'tr_ledger_period')
        ->latest('id')
        ->first();

    if ($charge !== null) {
        $chargeNet = array_sum(array_map(
            fn (array $l) => max(0, (int) ($l['amount_net'] ?? 0)),
            (array) $charge->line_items,
        ));

        // The composer prices line by line rather than off the aggregate delta, so allow a
        // rounding cent — but not a different period's worth of money.
        expect($chargeNet)->toBeGreaterThan(1900)
            ->and($chargeNet)->toBeLessThan(2200);
    }

    assertInvoicesInternallyConsistent();
});

it('ends a subscription at the period boundary and stops billing it', function (): void {
    $billable = ledgerBillable('2026-01-01T09:00:00Z');
    $this->travelTo(BillingTime::toUtc('2026-01-15T12:00:00Z'));

    // Graceful cancellation: access runs to the end of the period the customer paid for.
    $billable->fresh()->cancelBillingSubscription(false, 'ledger test');

    $cancelled = $billable->fresh();

    expect($cancelled->subscription_status)->toBe(SubscriptionStatus::Cancelled)
        ->and($cancelled->subscription_ends_at->toIso8601String())->toBe('2026-02-01T09:00:00+00:00')
        // Paid for, so still accessible during the grace period.
        ->and($cancelled->hasAccessibleBillingSubscription())->toBeTrue();

    // Past the boundary, the nightly pass expires it and clears the plan-scoped fields —
    // FeatureAccess resolves off the plan code, so leaving it behind keeps features alive.
    $this->travelTo(BillingTime::toUtc('2026-02-02T02:00:00Z'));
    app()->call([app(\GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob::class), 'handle']);

    $expired = $billable->fresh();

    expect($expired->subscription_status)->toBe(SubscriptionStatus::Expired)
        ->and($expired->subscription_plan_code)->toBeNull()
        ->and($expired->hasAccessibleBillingSubscription())->toBeFalse();

    // And no renewal is booked for a subscription that ended: a late delivery is reported,
    // not treated as a period the customer bought.
    deliverLedgerRenewal($billable->fresh(), 'tr_ledger_after_end', '2026-03-01T09:00:05Z', '51.60');

    expect($billable->fresh()->subscription_status)->toBe(SubscriptionStatus::Expired)
        ->and($billable->fresh()->subscription_period_starts_at->toIso8601String())
        ->toBe('2026-01-01T09:00:00+00:00');
});

it('ends a monthly period on the last day of a shorter month, not three days into the next', function (): void {
    // 31 January plus one calendar month is 3 March in PHP. Mollie charges a monthly
    // subscription on the last day of a month that has no 31st, so the two disagreed by
    // three days — in the direction that costs money twice over.
    $billable = ledgerBillable('2026-01-31T09:00:00Z');

    expect($billable->nextBillingDate()->toIso8601String())->toBe('2026-02-28T09:00:00+00:00');

    // The prorata window is the period, not a calendar month: 28 days, not 31.
    $days = \GraystackIT\MollieBilling\Support\BillingPolicy::prorataPeriodDays(
        $billable->getBillingPeriodStartsAt(),
        $billable->nextBillingDate(),
    );

    expect($days['total'])->toBe(28);

    // Which is what the prorata factor is measured against. On 14 February, 14 of 28 days
    // remain: a 3000 net delta is 1500, where the overflowing window priced it at 17/31 =
    // 1645 (and over-credited a downgrade by the same arithmetic).
    $this->travelTo(BillingTime::toUtc('2026-02-14T12:00:00Z'));

    expect(\GraystackIT\MollieBilling\Support\BillingPolicy::computeProrata(
        4300,
        7300,
        false,
        $billable->getBillingPeriodStartsAt(),
        $billable->nextBillingDate(),
    )['charge_net'])->toBe(1500);
});

it('ends a yearly period on 28 February when it started on a leap day', function (): void {
    $billable = ledgerBillable('2024-02-29T09:00:00Z');
    $billable->forceFill(['subscription_interval' => SubscriptionInterval::Yearly])->save();

    // 29 February plus a year overflowed to 1 March.
    expect($billable->fresh()->nextBillingDate()->toIso8601String())->toBe('2025-02-28T09:00:00+00:00');
});

it('books a reverse-charge renewal at zero VAT with net equal to gross', function (): void {
    $billable = ledgerBillable();

    // A validated VAT number in another member state: the supply is zero-rated and the
    // customer accounts for the tax. The invoice must say so, not carry 20% at 0.
    $billable->forceFill(['billing_country' => 'DE', 'tax_country_user' => 'DE', 'vat_number' => 'DE129273398'])->save();

    $validation = new \GraystackIT\MollieBilling\Models\BillingVatValidation;
    $validation->billable_type = $billable->getMorphClass();
    $validation->billable_id = $billable->getKey();
    $validation->vat_number = 'DE129273398';
    $validation->country_code = 'DE';
    $validation->valid = true;
    $validation->vies_response = [];
    $validation->checked_at = BillingTime::nowUtc();
    $validation->save();

    deliverLedgerRenewal($billable->fresh(), 'tr_ledger_rc', '2026-02-01T09:00:05Z', '43.00');

    $invoice = BillingInvoice::query()->latest('id')->first();

    expect((int) $invoice->amount_net)->toBe(4300)
        ->and((int) $invoice->amount_vat)->toBe(0)
        ->and((int) $invoice->amount_gross)->toBe(4300)
        // And the invoice records which validation it relied on — a tax auditor's question.
        ->and((int) $invoice->vat_validation_id)->toBe((int) $validation->getKey());

    assertInvoicesInternallyConsistent();
});

it('books what Mollie actually collected when the amount differs, and says so', function (): void {
    \Illuminate\Support\Facades\Event::fake([\GraystackIT\MollieBilling\Events\PaymentAmountMismatch::class]);

    $billable = ledgerBillable();

    // Expected 51.60; Mollie collected 51.00 — a stale amount at Mollie, say, after a
    // catalog price change that never reached the subscription.
    deliverLedgerRenewal($billable, 'tr_ledger_mismatch', '2026-02-01T09:00:05Z', '51.00');

    $invoice = BillingInvoice::query()->latest('id')->first();

    // The paid amount is the source of truth for the document — an invoice may not state
    // more than was collected — and the discrepancy is surfaced rather than smoothed over.
    expect((int) $invoice->amount_gross)->toBe(5100);

    \Illuminate\Support\Facades\Event::assertDispatched(\GraystackIT\MollieBilling\Events\PaymentAmountMismatch::class);

    assertInvoicesInternallyConsistent();
});

it('discounts a mid-period upgrade with a recurring coupon and records what it discounted', function (): void {
    config()->set('mollie-billing-plans.plans.enterprise', [
        'name' => 'Enterprise',
        'tier' => 3,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => ['analytics'],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 5900,
                'seat_price_net' => 500,
                'included_usages' => ['Tokens' => 5000],
                'usage_overage_prices' => ['Tokens' => 2],
            ],
        ],
    ]);

    $coupon = Coupon::create([
        'code' => 'UPGRADE25',
        'name' => 'Upgrade 25',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 25,
        'active' => true,
        'stackable' => false,
    ]);

    $billable = ledgerBillable('2026-01-01T09:00:00Z');
    $this->travelTo(BillingTime::toUtc('2026-01-11T12:00:00Z'));

    BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_ledger_coupon_period',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 4300,
        'amount_vat' => 860,
        'amount_gross' => 5160,
        'serial_number' => 'IN-LEDGER-C1',
        'period_start' => BillingTime::toUtc('2026-01-01T09:00:00Z'),
        'period_end' => BillingTime::toUtc('2026-02-01T09:00:00Z'),
        'line_items' => [
            ['kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1, 'unit_price_net' => 2900, 'amount_net' => 2900, 'vat_rate' => 20.0, 'vat_amount' => 580, 'amount_gross' => 3480, 'period_start' => '2026-01-01T09:00:00+00:00', 'period_end' => '2026-02-01T09:00:00+00:00'],
            ['kind' => 'seats', 'code' => null, 'label' => 'Extra seat', 'quantity' => 1, 'unit_price_net' => 500, 'amount_net' => 500, 'vat_rate' => 20.0, 'vat_amount' => 100, 'amount_gross' => 600, 'period_start' => '2026-01-01T09:00:00+00:00', 'period_end' => '2026-02-01T09:00:00+00:00'],
            ['kind' => 'addon', 'code' => 'analytics', 'label' => 'Analytics', 'quantity' => 1, 'unit_price_net' => 900, 'amount_net' => 900, 'vat_rate' => 20.0, 'vat_amount' => 180, 'amount_gross' => 1080, 'period_start' => '2026-01-01T09:00:00+00:00', 'period_end' => '2026-02-01T09:00:00+00:00'],
        ],
    ]);

    $preview = \GraystackIT\MollieBilling\Facades\MollieBilling::preview()
        ->previewUpdate($billable->fresh(), ['plan_code' => 'enterprise', 'coupon_codes' => ['UPGRADE25']]);

    // The two discounts are separate things and both have to be right.
    //
    // Due now: the new configuration is charged for the 21 remaining of 31 days
    // (7300 × 21/31 = 4946) and the old one credited for the same window
    // (4300 × 21/31 = 2914) — a delta of 2032 — and the coupon takes 25% of that delta,
    // 508, as its own line. So 4946 − 2914 − 508 = 1524, and `prorataChargeNet` is the
    // figure after the discount, matching `prorataTotalNet`.
    $lines = array_map(
        fn (array $l) => [(string) ($l['kind'] ?? ''), (int) ($l['amount_net'] ?? 0)],
        $preview['prorataLines'] ?? [],
    );

    expect($lines)->toContain(['plan', 3997])       // 5900 × 21/31
        ->and($lines)->toContain(['plan', -1965])   // −2900 × 21/31
        ->and($lines)->toContain(['coupon', -508])
        ->and(array_sum(array_column($lines, 1)))->toBe(1524)
        ->and($preview['prorataChargeNet'])->toBe(1524)
        ->and($preview['prorataTotalNet'])->toBe(1524);

    // Going forward: 25% of the *new* recurring net, which is a different number and must
    // not be confused with the one above.
    expect($preview['couponDiscountNet'])->toBe(1825)
        ->and($preview['newPriceNet'])->toBe(7300);

    $result = app(\GraystackIT\MollieBilling\Services\Billing\UpdateSubscription::class)
        ->update($billable->fresh(), ['plan_code' => 'enterprise', 'coupon_codes' => ['UPGRADE25']]);

    // An upgrade whose prorata is collected by Mollie is deferred: the plan does not switch
    // and — this is the part worth pinning — the recurring discount is NOT activated yet.
    // Writing the marker here would give the customer a standing discount off the back of a
    // payment that has not settled, and the redemption row would carry no invoice id.
    // Phase 2 does both once the charge clears (see ProrataChargePhaseTwoTest).
    expect($result['pendingPaymentConfirmation'])->toBeTrue()
        ->and($billable->fresh()->getBillingSubscriptionMeta())->not->toHaveKey('active_recurring_coupon')
        ->and($billable->fresh()->subscription_plan_code)->toBe('pro')
        // The intent is stored with the coupon, so Phase 2 can redeem it against the
        // invoice the charge produced.
        ->and($billable->fresh()->getBillingSubscriptionMeta()['pending_plan_change']['coupon_codes'] ?? [])
        ->toBe(['UPGRADE25']);

    assertInvoicesInternallyConsistent();
});

it('stamps a renewal invoice with the period it paid for, so a later downgrade can credit it', function (): void {
    config()->set('mollie-billing-plans.plans.starter', [
        'name' => 'Starter',
        'tier' => 1,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 900, 'seat_price_net' => null, 'included_usages' => ['Tokens' => 100]],
        ],
    ]);

    $billable = ledgerBillable('2026-01-01T09:00:00Z');

    deliverLedgerRenewal($billable, 'tr_ledger_window', '2026-02-01T09:04:12Z', '51.60');

    $invoice = BillingInvoice::query()->latest('id')->first();
    $fresh = $billable->fresh();

    // The invoice documents the period the money bought — 1 Feb to 1 Mar — not the one that
    // just expired. It used to carry [1 Jan, 1 Feb] because the anchor was only advanced
    // afterwards, and `currentPeriodLines()` then dropped every line of it: its period_end
    // was 28 days away from the billable's own, far past the one-day tolerance.
    expect($invoice->period_start->toIso8601String())->toBe('2026-02-01T09:04:12+00:00')
        ->and($invoice->period_end->toIso8601String())->toBe('2026-03-01T09:04:12+00:00')
        ->and($fresh->getBillingPeriodStartsAt()->toIso8601String())->toBe('2026-02-01T09:04:12+00:00');

    // Which is what makes the invoice findable — and a downgrade creditable.
    $candidates = BillingInvoice::currentPeriodLines($fresh, 'plan', 'pro');
    expect($candidates)->not->toBeEmpty();

    // Half way through the new period, dropping to a 900 plan: the 4300 just paid is
    // credited pro rata. With the invoice unfindable this produced a charge and no credit at
    // all, leaving the customer 21.50 net out of pocket.
    $this->travelTo(BillingTime::toUtc('2026-02-15T09:04:12Z'));

    $preview = \GraystackIT\MollieBilling\Facades\MollieBilling::preview()
        ->previewUpdate($fresh, ['plan_code' => 'starter', 'addons' => [], 'seats' => 1]);

    $credits = array_filter(
        array_map(fn (array $l) => (int) ($l['amount_net'] ?? 0), $preview['prorataLines'] ?? []),
        fn (int $net) => $net < 0,
    );

    expect($credits)->not->toBeEmpty()
        ->and(array_sum($credits))->toBeLessThan(-1500);
});

it('invoices an out-of-order renewal without booking it as a new period', function (): void {
    config()->set('mollie-billing-plans.usage_types', ['Tokens' => ['rollover' => true]]);

    $billable = ledgerBillable('2026-01-01T09:00:00Z');

    deliverLedgerRenewal($billable, 'tr_ledger_ooo_b', '2026-02-01T09:00:00Z', '51.60');

    $afterB = $billable->fresh();
    expect($afterB->subscription_period_starts_at->toIso8601String())->toBe('2026-02-01T09:00:00+00:00')
        ->and((int) $afterB->getWallet('Tokens')->balanceInt)->toBe(1000);

    // A delivery for an older payment arrives late. Idempotency here is per payment, so this
    // one has no invoice yet and used to run the whole body: the anchor went back a month —
    // after which the overage pass's "renews tomorrow" never matched again until the next
    // renewal, so that period's overage was never collected — and a rollover wallet was
    // credited a second full period on top, permanently.
    deliverLedgerRenewal($billable->fresh(), 'tr_ledger_ooo_a', '2026-01-01T09:00:00Z', '51.60');

    $afterA = $billable->fresh();

    expect($afterA->subscription_period_starts_at->toIso8601String())->toBe('2026-02-01T09:00:00+00:00')
        ->and((int) $afterA->getWallet('Tokens')->balanceInt)->toBe(1000);

    // The money did arrive, so the document exists either way.
    expect(BillingInvoice::query()->where('mollie_payment_id', 'tr_ledger_ooo_a')->count())->toBe(1);
});

it('does not hand back units when a plan-change overage settles', function (): void {
    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free',
        'tier' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => ['Tokens' => 10]],
        ],
    ]);
    config()->set('mollie-billing-plans.usage_types', ['Tokens' => ['rollover' => true]]);

    $billable = ledgerBillable('2026-01-01T09:00:00Z');
    $billable->forceFill(['active_addon_codes' => [], 'subscription_meta' => ['mollie_subscription_id' => 'sub_ledger', 'seat_count' => 1]])->save();

    app(\GraystackIT\MollieBilling\Services\Wallet\WalletUsageService::class)
        ->credit($billable->fresh(), 'Tokens', 1000, 'seed');
    $billable->fresh()->recordBillingUsage('Tokens', 900);

    // Half way through: the elapsed entitlement is 500, 900 was consumed, so 400 is excess.
    // The new plan includes 10, so 390 is charged — and the adjuster clamps the wallet to 0
    // in the same breath, because those units are already accounted for.
    $this->travelTo(BillingTime::toUtc('2026-01-16T09:00:00Z'));

    app(\GraystackIT\MollieBilling\Services\Wallet\WalletPlanChangeAdjuster::class)->adjust(
        $billable->fresh(),
        'pro',
        'monthly',
        'free',
        'monthly',
        BillingTime::toUtc('2026-01-01T09:00:00Z'),
        BillingTime::toUtc('2026-02-01T09:00:00Z'),
    );

    $snapshot = $billable->fresh()->getBillingSubscriptionMeta()['usage_overage'] ?? [];
    $charged = (int) ($snapshot['line_items'][0]['quantity'] ?? 0);

    expect((int) $billable->fresh()->getWallet('Tokens')->balanceInt)->toBe(0)
        ->and($charged)->toBeGreaterThan(0);

    // Settling it must NOT credit those units back: there is no negative balance to clear,
    // so the credit would land on 0 and hand the customer capacity they never bought — and a
    // rollover type would keep it for good.
    LedgerFakeWebhookController::$nextPayment = (object) [
        'id' => (string) $snapshot['payment_id'],
        'status' => 'paid',
        'amount' => (object) ['value' => '9.36', 'currency' => 'EUR'],
        'paidAt' => '2026-01-16T10:00:00Z',
        'subscriptionId' => null,
        'countryCode' => 'AT',
        'metadata' => [
            'type' => 'overage',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'line_items' => $snapshot['line_items'],
        ],
    ];
    $this->postJson(route('billing.webhook'), ['id' => (string) $snapshot['payment_id']])->assertStatus(200);

    expect((int) $billable->fresh()->getWallet('Tokens')->balanceInt)->toBe(0);
});

it('refuses a plan-change overage while a period-end charge is still settling', function (): void {
    $billable = ledgerBillable();

    $billable->recordBillingUsage('Tokens', 0);
    app(\GraystackIT\MollieBilling\Services\Wallet\WalletUsageService::class)
        ->credit($billable, 'Tokens', 1000, 'seed');
    $billable->fresh()->recordBillingUsage('Tokens', 1037);

    // The period-end charge for 37 units, still in flight.
    app(ChargeUsageOverageDirectly::class)->handle($billable->fresh());
    $inFlight = (string) ($billable->fresh()->getBillingSubscriptionMeta()['usage_overage']['payment_id'] ?? '');

    expect($inFlight)->not->toBe('');

    // A plan change now wants to charge its own excess. The snapshot is a single slot, and
    // overwriting it lost the in-flight charge's units while making the retry job — which
    // resolves the snapshot, not a payment id — charge the *new* line items a second time.
    $result = app(ChargeUsageOverageDirectly::class)->handleExplicit($billable->fresh(), [
        ['kind' => 'overage', 'type' => 'Tokens', 'code' => 'Tokens', 'label' => 'Tokens', 'quantity' => 27, 'unit_price_net' => 2, 'total_net' => 54],
    ]);

    expect($result->wasCharged())->toBeFalse()
        ->and((string) ($billable->fresh()->getBillingSubscriptionMeta()['usage_overage']['payment_id'] ?? ''))->toBe($inFlight);
});

it('keeps a Local rollover renewal from relabelling plan quota as purchased', function (): void {
    config()->set('mollie-billing-plans.usage_types', ['Tokens' => ['rollover' => true]]);

    $billable = ledgerBillable('2026-01-01T09:00:00Z');
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'active_addon_codes' => [],
    ])->save();

    $wallet = $billable->fresh()->getWallet('Tokens')
        ?? $billable->fresh()->createWallet(['name' => 'Tokens', 'slug' => 'Tokens']);

    // 500 bought units, all consumed: nothing purchased is left.
    app(\GraystackIT\MollieBilling\Services\Wallet\WalletUsageService::class)
        ->credit($billable->fresh(), 'Tokens', 500, 'coupon_credit');
    \GraystackIT\MollieBilling\Services\Wallet\WalletUsageService::addPurchasedBalance($billable->fresh()->getWallet('Tokens'), 500);
    $billable->fresh()->recordBillingUsage('Tokens', 500);

    expect((int) $billable->fresh()->getWallet('Tokens')->balanceInt)->toBe(0);

    // The Local renewal pass credits the new quota. A bare credit() left purchased_balance at
    // 500, so the fresh plan quota read as purchased — and purchased units survive every
    // later reset and inflate the target balance of the next plan change.
    $this->travelTo(BillingTime::toUtc('2026-01-31T02:00:00Z'));
    app()->call([app(\GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob::class), 'handle']);

    $fresh = $billable->fresh();

    expect((int) \GraystackIT\MollieBilling\Services\Wallet\WalletUsageService::getPurchasedBalance($fresh->getWallet('Tokens')))->toBe(0);
});

it('does not redeem a recurring coupon twice when a seat change defers its charge', function (): void {
    $coupon = Coupon::create([
        'code' => 'SEATS50',
        'name' => 'Seats 50',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
        'active' => true,
        'stackable' => false,
        'max_redemptions_per_billable' => 6,
    ]);

    $billable = ledgerBillable('2026-01-01T09:00:00Z');
    $billable->forceFill(['active_addon_codes' => []])->save();
    $this->travelTo(BillingTime::toUtc('2026-01-06T09:00:00Z'));

    // A seat change is not a plan change, so UpdateSubscription redeems in its own phase —
    // and it also raises a deferred Mollie charge for the prorated seat.
    app(\GraystackIT\MollieBilling\Services\Billing\UpdateSubscription::class)
        ->update($billable->fresh(), ['seats' => 3, 'coupon_codes' => ['SEATS50']]);

    $afterPhaseOne = $billable->fresh();
    $marker = $afterPhaseOne->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null;

    expect($marker)->not->toBeNull()
        ->and((int) $marker['base_amount_net'])->toBeGreaterThan(0);

    $basisAfterPhaseOne = (int) $marker['base_amount_net'];
    $redemptionsAfterPhaseOne = CouponRedemption::query()->where('coupon_id', $coupon->id)->count();

    // Phase 2 used to redeem the same code again off the back of the charge lines. That
    // fallback carries no recurring net, so `redeem()` rewrote the marker with
    // base_amount_net = 0 — after which computeMarkerDiscount() returned 0 for good and the
    // PATCH that follows put Mollie back to the full price. A 50%/12-month campaign stopped
    // discounting after one seat change, and the customer had burned two slots for it.
    $pending = $afterPhaseOne->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null;

    if ($pending !== null) {
        LedgerFakeWebhookController::$nextPayment = (object) [
            'id' => (string) ($pending['charge_payment_id'] ?? 'tr_ledger_seat_charge'),
            'status' => 'paid',
            'amount' => (object) ['value' => '1.00', 'currency' => 'EUR'],
            'paidAt' => '2026-01-06T10:00:00Z',
            'subscriptionId' => 'sub_ledger',
            'countryCode' => 'AT',
            'metadata' => [
                'type' => 'prorata_charge',
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
            ],
        ];

        $this->postJson(route('billing.webhook'), ['id' => (string) ($pending['charge_payment_id'] ?? 'tr_ledger_seat_charge')])
            ->assertStatus(200);
    }

    $fresh = $billable->fresh();
    $finalMarker = $fresh->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null;

    expect($finalMarker)->not->toBeNull()
        ->and((int) $finalMarker['base_amount_net'])->toBe($basisAfterPhaseOne)
        ->and(CouponRedemption::query()->where('coupon_id', $coupon->id)->count())->toBe($redemptionsAfterPhaseOne);

    // Which is the point: the discount still computes.
    expect(app(\GraystackIT\MollieBilling\Services\Billing\CouponService::class)
        ->computeMarkerDiscount($fresh, $basisAfterPhaseOne))->toBeGreaterThan(0);
});
