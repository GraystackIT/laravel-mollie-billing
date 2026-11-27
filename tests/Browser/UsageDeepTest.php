<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Notifications\OverageBillingFailedNotification;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\MollieCall;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Browser\Matrix\OperationsWorkbench;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Dusk\Browser;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Workbench\App\FakeMollie\FakeMollie;
use Workbench\App\Support\WorkbenchSetting;

/**
 * Metered usage off the straight way: a dunning ladder against a Mollie that honours
 * idempotency keys, a product purchase abandoned on the hosted page, a token pack bought
 * while an overage charge is still travelling, a downgrade with usage above the new quota
 * while another overage charge is in flight, and a trial ended early with overage in the
 * wallet — with the nightly sweep's charge before, during and without it.
 *
 * Every scenario runs against a fake Mollie that honours idempotency keys as the sandbox
 * measured the real API does — the fake's default since 26 November 2026, before that switched
 * on here alone (`honour_idempotency_keys`): a key seen within the hour is answered with the
 * payment it created, a key reused with other parameters is refused. A fake that ignored keys
 * let a retry that reused its predecessor's key look like a new payment, which the real API
 * never makes.
 *
 * The world starts the evening before a renewal (10 March 2027, 08:00 UTC; the period began
 * 11 February at 09:00 and renews 11 March), so the nightly sweep is the real
 * `PrepareUsageOverageJob` and not a call into the charge service.
 */
class UsageDeepTest extends BrowserTestCase
{
    use OperationsWorkbench;

    private const EVENING_BEFORE_RENEWAL = '2027-03-10 08:00';

    private const PERIOD_START = '2027-02-11 09:00';

    private const RENEWAL_DAY = '2027-03-11';

    protected function tearDown(): void
    {
        WorkbenchSetting::forget('hold_mollie_request');
        $this->setRecurringOutcome('paid');
        $this->setIpCountry(null);
        MollieBilling::notifyBillingAdminsUsing(fn (): array => []);

        parent::tearDown();
    }

    // ── The fake itself ──────────────────────────────────────────────────────

    /**
     * The lever the scenarios below rely on, pinned on its own: the same key and body within
     * the hour is the same payment; the same key with another amount is refused; after the
     * hour the key is free again.
     */
    public function test_the_fake_mollie_honours_idempotency_keys_as_the_sandbox_measured(): void
    {
        $this->travelSharedClockTo(self::EVENING_BEFORE_RENEWAL);

        $request = static fn (string $value): CreatePaymentRequest => new CreatePaymentRequest(
            description: 'Idempotency lever',
            amount: new Money('EUR', $value),
            metadata: ['type' => 'overage'],
            sequenceType: 'recurring',
            mandateId: 'mdt_wb_x',
            customerId: 'cst_wb_x',
        );

        $first = MollieCall::send($request('1.00'), 'lever-key');
        $again = MollieCall::send($request('1.00'), 'lever-key');

        $this->assertSame((string) $first->id, (string) $again->id, 'a repeated key is answered with the first payment');
        $this->assertSame(1, DB::table('fake_mollie_payments')->count());
        $this->assertSame('1', WorkbenchSetting::get('idempotency_replays'));

        $refused = false;

        try {
            MollieCall::send($request('2.00'), 'lever-key');
        } catch (\Mollie\Api\Exceptions\ApiException) {
            $refused = true;
        }

        $this->assertTrue($refused, 'a reused key with another amount is refused');
        $this->assertSame(1, DB::table('fake_mollie_payments')->count());

        // Without a caller key every request is its own (the SDK's random key).
        MollieCall::send($request('1.00'));
        $this->assertSame(2, DB::table('fake_mollie_payments')->count());

        // Mollie keeps a key for one hour.
        $this->travelSharedClockTo(Carbon::parse(self::EVENING_BEFORE_RENEWAL, 'UTC')->addMinutes(61));
        $later = MollieCall::send($request('1.00'), 'lever-key');
        $this->assertNotSame((string) $first->id, (string) $later->id);
    }

    // ── Gap 20: the dunning ladder, three distinct payments ──────────────────

    /**
     * 25 SMS over Starter's quota, the sweep charges them, the card refuses. Each retry has
     * to be a *new* payment at Mollie — its key names the payment before it — so the cycle
     * makes three attempts and only then tells the customer and shows the debt as open.
     */
    public function test_the_overage_dunning_ladder_asks_mollie_for_a_new_payment_on_every_attempt(): void
    {
        Notification::fake();
        MollieBilling::notifyBillingAdminsUsing(fn ($b): array => [$b]);

        $this->travelSharedClockTo(self::EVENING_BEFORE_RENEWAL);
        $billable = $this->subscriberRenewingTomorrow('starter');

        app(WalletUsageService::class)->debit($billable->fresh(), 'SMS', 45);
        $this->setRecurringOutcome('failed');

        $this->runNightlySweep();

        // 25 × 18 ct = 4.50 net, 5.36 gross.
        $p1 = $this->soleOveragePayment();
        $this->assertSame('failed', $p1['status']);
        $this->assertSame('5.36', $p1['amount_value']);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/usage');
            $browser->waitFor('[dusk="usage-meter-SMS"]', 15)->pause(400);
            $this->assertSame(0, $this->domCount($browser, '[dusk="outstanding-overage"]'), 'one charge made, nothing open yet');
        });

        // Mollie reports the failure: the retry asks for the second payment …
        $this->assertSame(200, $this->deliverWebhook($p1['id']));

        $payments = $this->overagePayments();
        $this->assertCount(2, $payments, 'the retry created a second payment');
        $this->assertNotSame($p1['id'], $payments[1]['id'], 'not p1 replayed');
        $this->assertSame(0, (int) WorkbenchSetting::get('idempotency_replays', '0'), 'no key was reused');

        $this->browse(function (Browser $browser): void {
            $browser->refresh()->waitFor('[dusk="usage-meter-SMS"]', 15)->pause(400);
            $this->assertSame(0, $this->domCount($browser, '[dusk="outstanding-overage"]'), 'two attempts: still in dunning');
        });
        Notification::assertNotSentTo($billable->fresh(), OverageBillingFailedNotification::class);

        $this->assertSame(200, $this->deliverWebhook($payments[1]['id']));
        $payments = $this->overagePayments();
        $this->assertCount(3, $payments);

        $this->assertSame(200, $this->deliverWebhook($payments[2]['id']));

        // Three distinct failed payments, one per key, each key naming the payment before it.
        $payments = $this->overagePayments();
        $this->assertCount(3, $payments, 'the third failure ends the cycle without a fourth payment');
        $this->assertCount(3, array_unique(array_column($payments, 'id')));
        $this->assertSame(['failed', 'failed', 'failed'], array_column($payments, 'status'));
        $this->assertSame(['5.36', '5.36', '5.36'], array_column($payments, 'amount_value'));

        $keys = $this->idempotencyKeysByPayment();
        $this->assertCount(3, array_unique(array_map(fn (array $p): string => $keys[$p['id']] ?? '', $payments)));
        $this->assertStringNotContainsString(':after:', $keys[$payments[0]['id']]);
        $this->assertStringEndsWith(':after:'.$payments[0]['id'], $keys[$payments[1]['id']]);
        $this->assertStringEndsWith(':after:'.$payments[1]['id'], $keys[$payments[2]['id']]);
        $this->assertSame(0, (int) WorkbenchSetting::get('idempotency_replays', '0'));
        $this->assertSame(0, (int) WorkbenchSetting::get('idempotency_conflicts', '0'));

        $meta = $billable->fresh()->getBillingSubscriptionMeta();
        $this->assertSame(ChargeUsageOverageDirectly::STATUS_FAILED, $meta['usage_overage_status'] ?? null);
        $this->assertSame(SubscriptionStatus::PastDue, $billable->fresh()->getBillingSubscriptionStatus());
        $this->assertSame(0, BillingInvoice::query()->where('invoice_kind', InvoiceKind::Overage->value)->count());

        Notification::assertSentTo($billable->fresh(), OverageBillingFailedNotification::class);
        Notification::assertSentTimes(OverageBillingFailedNotification::class, 1);

        $this->browse(function (Browser $browser): void {
            $browser->refresh()->waitFor('[dusk="outstanding-overage"]', 15)->pause(400);

            $this->assertStringContainsString(
                $this->opsNormaliseMoney(BillingMoney::format(536)),
                $this->opsNormaliseMoney($browser->text('[dusk="outstanding-overage"]')),
            );
            $this->shot($browser, 'Three refusals: the debt is open');
        });
    }

    // ── Gap 21: a product purchase abandoned or refused at Mollie ────────────

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function abandonedPurchases(): array
    {
        return [
            'token pack cancelled, with a code' => ['token-pack-500', 'canceled', true],
            'token pack declined' => ['token-pack-500', 'failed', false],
            'once-only product cancelled' => ['onboarding-session', 'canceled', false],
            'once-only product expired, with a code' => ['onboarding-session', 'expired', true],
        ];
    }

    #[DataProvider('abandonedPurchases')]
    public function test_a_product_purchase_that_is_not_paid_books_nothing_and_stays_on_offer(string $product, string $outcome, bool $withCode): void
    {
        $billable = $this->opsSubscriber('starter');
        $tokensBefore = $billable->fresh()->remainingBillingQuota('Tokens');

        if ($withCode) {
            $this->coupons()->create([
                'code' => 'ONEOFF20',
                'name' => 'Product 20 %',
                'type' => CouponType::SinglePayment,
                'discount_type' => DiscountType::Percentage,
                'discount_value' => 20,
                'applicable_products' => [$product],
                'max_redemptions_per_billable' => 1,
            ]);
        }

        $this->browse(function (Browser $browser) use ($billable, $product, $outcome, $withCode): void {
            $this->actAs($browser, $billable, '/billing/products');
            $browser->waitFor('[dusk="product-price-'.$product.'"]', 15)->pause(400);

            $purchase = '[wire\\:click="purchase(\''.$product.'\')"]';

            if ($withCode) {
                $input = 'input[wire\\:model="couponInputs.'.$product.'"]';
                $browser->script(sprintf('window.Flux.modal(%s).show()', json_encode('purchase-'.$product)));
                $browser->waitFor($input, 10)->pause(300)->type($input, 'ONEOFF20');
                $this->clickWire($browser, '[wire\\:click="applyCoupon(\''.$product.'\')"]');
                $browser->waitFor('[dusk="product-total-'.$product.'"]', 10)->pause(300);
                $this->shot($browser, 'Code applied before paying');
                $this->clickWire($browser, $purchase);
            } else {
                $this->confirmInModal($browser, 'purchase-'.$product, $purchase);
            }

            $this->settleAtFakeMollie($browser, $outcome);

            // The return page says what happened — never "Payment received".
            $browser->waitForText(__('billing::portal.return.failed_title'), 20)->pause(300);
            $browser->assertSee(__('billing::portal.return.failed_body_'.$outcome))
                ->assertDontSee(__('billing::portal.return.title'))
                ->assertDontSee(__('billing::portal.return.body_products'));
            $this->shot($browser, 'Return page: '.$outcome);

            // Back to the catalog with the page's own button: still on offer.
            $browser->clickAtXPath('//a[contains(normalize-space(.), '.json_encode(__('billing::portal.products.back_to_products')).')]');
            $this->waitForPathContains($browser, '/billing/products');
            $browser->waitFor('[dusk="product-price-'.$product.'"]', 15)->pause(300);
            $this->assertSame(1, $this->domCount($browser, $purchase), 'the product is still offered');
            $this->shot($browser, 'Still on offer');
        });

        $payment = $this->latestFakePayment();
        $this->assertSame($outcome, $payment['status']);
        $this->assertSame('one_time_order', json_decode((string) $payment['metadata'], true)['type'] ?? null);

        $billable = $billable->fresh();
        $this->assertSame(0, BillingInvoice::query()->where('invoice_kind', 'one_time_order')->count(), 'no invoice for an unpaid order');
        $this->assertSame(0, BillingInvoice::query()->count());
        $this->assertSame($tokensBefore, $billable->remainingBillingQuota('Tokens'), 'no units credited');
        $this->assertSame(0, $billable->purchasedBillingCredits('Tokens'));
        $this->assertSame(0, CouponRedemption::query()->count(), 'no redemption recorded');
        $this->assertTrue(in_array($product, $billable->availableBillingProducts(), true));

        if ($withCode) {
            $this->assertSame(0, (int) Coupon::query()->where('code', 'ONEOFF20')->value('redemptions_count'));
        }

        // Mollie's webhook for the unpaid payment (it reports every status change) books
        // nothing either, and answers 200.
        $this->assertSame(200, $this->deliverWebhook($payment['id']));
        $this->assertSame(0, BillingInvoice::query()->count());
        $this->assertSame($tokensBefore, $billable->fresh()->remainingBillingQuota('Tokens'));
        $this->assertSame(0, CouponRedemption::query()->count());

        // And the code is still there to be used for the purchase that is paid.
        $this->browse(function (Browser $browser) use ($product, $withCode): void {
            $browser->visit('/billing/products')->waitFor('[dusk="product-price-'.$product.'"]', 15)->pause(300);

            if ($withCode) {
                $input = 'input[wire\\:model="couponInputs.'.$product.'"]';
                $browser->script(sprintf('window.Flux.modal(%s).show()', json_encode('purchase-'.$product)));
                $browser->waitFor($input, 10)->pause(300)->type($input, 'ONEOFF20');
                $this->clickWire($browser, '[wire\\:click="applyCoupon(\''.$product.'\')"]');
                $browser->waitFor('[dusk="product-total-'.$product.'"]', 10)->pause(300);
                $this->clickWire($browser, '[wire\\:click="purchase(\''.$product.'\')"]');
            } else {
                $this->confirmInModal($browser, 'purchase-'.$product, '[wire\\:click="purchase(\''.$product.'\')"]');
            }

            $this->settleAtFakeMollie($browser, 'paid');
            $browser->waitForText(__('billing::portal.return.title'), 20);
        });

        $this->assertSame(1, BillingInvoice::query()->where('invoice_kind', 'one_time_order')->count());
        $this->assertSame($withCode ? 1 : 0, CouponRedemption::query()->count());
    }

    // ── Gap 22: a token pack bought while an overage charge is travelling ────

    /**
     * 40 Tokens over Starter's quota, charged by SEPA the night before the renewal and still
     * open. The customer buys 500 Tokens meanwhile. Settling the overage pays the 40 off;
     * the 500 bought stay whole, and the renewal adds the plan's 50 on top of them.
     */
    public function test_a_token_pack_bought_while_an_overage_charge_is_open_stays_whole(): void
    {
        $this->travelSharedClockTo(self::EVENING_BEFORE_RENEWAL);
        $billable = $this->subscriberRenewingTomorrow('starter');

        app(WalletUsageService::class)->debit($billable->fresh(), 'Tokens', 90);
        $this->setRecurringOutcome('open');
        $this->runNightlySweep();

        // 40 × 12 ct = 4.80 net, 5.71 gross — open, as SEPA is for days.
        $overage = $this->soleOveragePayment();
        $this->assertSame('open', $overage['status']);
        $this->assertSame('5.71', $overage['amount_value']);
        $this->assertSame(-40, (int) $billable->fresh()->getWallet('Tokens')->balanceInt);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/products');
            $browser->waitForText('500 Token Pack', 15)->pause(400);

            $this->confirmInModal($browser, 'purchase-token-pack-500', '[wire\\:click="purchase(\'token-pack-500\')"]');
            $this->settleAtFakeMollie($browser, 'paid');
            $browser->waitForText(__('billing::portal.return.title'), 20);
            $this->shot($browser, 'Token pack paid while the overage is open');
        });

        $this->assertSame(1, BillingInvoice::query()->where('invoice_kind', 'one_time_order')->count());
        $this->assertSame(460, $billable->fresh()->remainingBillingQuota('Tokens'), 'the open debt is still on the wallet');

        // The SEPA payment arrives.
        FakeMollie::settle($overage['id'], 'paid');
        $this->assertSame(200, $this->deliverWebhook($overage['id']));

        $invoice = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Overage->value)->sole();
        $line = collect((array) $invoice->line_items)->firstWhere('type', 'Tokens') ?? ((array) $invoice->line_items)[0];
        $this->assertSame(40, (int) $line['quantity'], 'the overage invoice is for the 40 units used');
        $this->assertSame(480, (int) $invoice->amount_net);
        $this->assertSame($overage['id'], $invoice->mollie_payment_id);

        $billable = $billable->fresh();
        $this->assertSame(500, $billable->remainingBillingQuota('Tokens'));
        $this->assertSame(500, $billable->purchasedBillingCredits('Tokens'));
        $this->assertSame(1, count($this->overagePayments()), 'nothing charged twice');

        $this->browse(function (Browser $browser): void {
            $browser->visit('/billing/usage')->waitFor('[dusk="usage-meter-Tokens-purchased"]', 15)->pause(400);

            $this->assertSame(
                __('billing::portal.usage_purchased_remaining', ['remaining' => '500', 'total' => '500']),
                trim($browser->text('[dusk="usage-meter-Tokens-purchased"]')),
            );
            $this->assertStringContainsString(
                __('billing::portal.usage_plan_remaining', ['remaining' => '0', 'total' => '50']),
                $browser->text('[dusk="usage-meter-Tokens-plan"]'),
            );
            $this->assertSame(0, $this->domCount($browser, '[dusk="outstanding-overage"]'));
            $this->shot($browser, 'Overage paid: 500 bought units intact');
        });

        // The renewal: the plan's 50 come on top, the 500 bought stay.
        $this->travelSharedClockTo(self::RENEWAL_DAY.' 10:00');
        $this->setRecurringOutcome('paid');
        $this->renewSubscription($billable->fresh());

        $billable = $billable->fresh();
        $this->assertSame(550, $billable->remainingBillingQuota('Tokens'));
        $this->assertSame(500, $billable->purchasedBillingCredits('Tokens'));
        $this->assertSame(1, count($this->overagePayments()), 'the renewal charged no overage');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/usage');
            $browser->waitFor('[dusk="usage-meter-Tokens-purchased"]', 15)->pause(400);

            $this->assertSame(
                __('billing::portal.usage_purchased_remaining', ['remaining' => '500', 'total' => '500']),
                trim($browser->text('[dusk="usage-meter-Tokens-purchased"]')),
            );
            $this->assertStringContainsString(
                __('billing::portal.usage_plan_remaining', ['remaining' => '50', 'total' => '50']),
                $browser->text('[dusk="usage-meter-Tokens-plan"]'),
            );
            $this->shot($browser, 'After the renewal: 50 included + 500 bought');
        });
    }

    // ── Gap 23: a downgrade with excess while another overage charge travels ─

    /**
     * Pro → Starter with SMS used beyond what Starter includes, while the sweep's charge for
     * the previous period is still open (SEPA). The slot is taken, so the excess cannot be
     * charged at once: it stays on the wallet as debt, with the price it was quoted at —
     * Pro's 15 ct, not Starter's 18 ct — and the next sweep bills it at that price.
     */
    public function test_a_downgrade_with_excess_while_an_overage_charge_is_open_keeps_the_old_price(): void
    {
        $this->travelSharedClockTo(self::EVENING_BEFORE_RENEWAL);
        $billable = $this->subscriberRenewingTomorrow('pro');

        // 30 SMS over Pro's 50, charged by the sweep and left open.
        app(WalletUsageService::class)->debit($billable->fresh(), 'SMS', 80);
        $this->setRecurringOutcome('open');
        $this->runNightlySweep();

        $previous = $this->soleOveragePayment();
        $this->assertSame('open', $previous['status']);

        // The renewal the next morning: the open debt is carried, 50 − 30 = 20 left.
        $this->travelSharedClockTo(self::RENEWAL_DAY.' 10:00');
        $this->setRecurringOutcome('paid');
        $this->renewSubscription($billable->fresh());
        $this->assertSame(20, (int) $billable->fresh()->getWallet('SMS')->balanceInt);

        // 40 more in the new period: 20 beyond the quota.
        app(WalletUsageService::class)->debit($billable->fresh(), 'SMS', 40);
        $this->assertSame(-20, (int) $billable->fresh()->getWallet('SMS')->balanceInt);

        $shown = [];

        $this->browse(function (Browser $browser) use ($billable, &$shown): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitFor('[wire\\:click="previewFor(\'starter\')"]', 15)->pause(400);
            $this->clickWire($browser, '[wire\\:click="previewFor(\'starter\')"]');
            $browser->waitFor('[dusk="seats-after-change"]', 15)->pause(400);

            // The excess is priced at Pro's 15 ct, not Starter's 18 ct.
            $this->waitForPresence($browser, '[dusk="prorata-overage-SMS"]', 15);
            $shown = [
                'units' => (int) $browser->attribute('[dusk="prorata-overage-SMS"]', 'data-units'),
                'price' => (int) $browser->attribute('[dusk="prorata-overage-SMS"]', 'data-unit-price-net'),
                'text' => $browser->text('[dusk="prorata-overage-SMS-units"]'),
            ];

            // ...and the screen says it is not collected with the change: the slot is taken
            // by the open payment, so the next debit carries it (decision 8, 26 Nov 2026).
            $this->assertSame(
                __('billing::portal.preview_usage_overage_after_charge_in_flight'),
                trim($browser->text('[dusk="overage-after-charge-in-flight"]')),
            );
            $this->shot($browser, 'Downgrade preview: excess at the old price');

            $this->clickWire($browser, '[x-on\\:click*="applyChange"]');

            // The change's own charge (Starter's rest of the period, Pro's rest credited
            // back) is created paid at the fake; its webhook is what applies the change.
            $chargeId = null;
            $browser->waitUsing(20, 200, function () use ($billable, &$chargeId): bool {
                $chargeId = $billable->fresh()->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'] ?? null;

                return is_string($chargeId) && $chargeId !== '';
            });
            $this->assertCount(1, $this->overagePayments(), 'the change asked for no overage payment of its own');
            $this->assertSame(200, $this->deliverWebhook($chargeId));
            $this->assertSame('starter', $billable->fresh()->getBillingSubscriptionPlanCode());

            $browser->visit('/billing/plan')->waitForText('Starter', 15)->pause(400);
            $this->shot($browser, 'Downgraded to Starter');
        });

        // 40 used in the new period against Starter's 20: 20 units. Not 50 — the 30 the open
        // payment is collecting are last period's and already being paid.
        $this->assertSame(20, $shown['units'], 'the preview quotes what the change bills');
        $this->assertSame(15, $shown['price'], 'at Pro\'s unit price');
        $this->assertSame(
            $this->opsNormaliseMoney($shown['units'].'× '.BillingMoney::format(15)),
            $this->opsNormaliseMoney($shown['text']),
        );

        // The slot is taken by the open charge: nothing new was asked of Mollie, and the
        // excess is on the wallet as debt, with its price.
        $this->assertCount(1, $this->overagePayments(), 'no second overage payment while one is open');

        $wallet = $billable->fresh()->getWallet('SMS');
        $this->assertSame(-(30 + $shown['units']), (int) $wallet->balanceInt, 'the open 30 and the excess are both debt');
        $this->assertSame([['quantity' => $shown['units'], 'unit_price_net' => 15]], WalletUsageService::getDeferredOverage($wallet));

        // The open payment settles: it pays its 30.
        FakeMollie::settle($previous['id'], 'paid');
        $this->assertSame(200, $this->deliverWebhook($previous['id']));

        $first = BillingInvoice::query()->where('mollie_payment_id', $previous['id'])->sole();
        $this->assertSame(450, (int) $first->amount_net);
        $this->assertSame(-$shown['units'], (int) $billable->fresh()->getWallet('SMS')->balanceInt);

        // The night before the next renewal, the sweep bills the excess at the price shown.
        $this->travelSharedClockTo('2027-04-10 08:00');
        $this->assertTrue($billable->fresh()->nextBillingDate()?->isSameDay(Carbon::parse('2027-04-11', 'UTC')) ?? false);
        $this->runNightlySweep();

        $payments = $this->overagePayments();
        $this->assertCount(2, $payments);
        $this->assertSame(200, $this->deliverWebhook($payments[1]['id']));

        $invoice = BillingInvoice::query()->where('mollie_payment_id', $payments[1]['id'])->sole();
        $lines = array_values(array_filter((array) $invoice->line_items, fn ($l): bool => is_array($l) && ($l['kind'] ?? null) === 'overage'));

        $this->assertCount(1, $lines, 'one line: the deferred excess, nothing at Starter\'s price');
        $this->assertTrue((bool) ($lines[0]['deferred_price'] ?? false));
        $this->assertSame(15, (int) $lines[0]['unit_price_net']);
        $this->assertSame($shown['units'], (int) $lines[0]['quantity']);
        $this->assertSame($shown['units'] * 15, (int) $invoice->amount_net);
        $this->assertSame(0, (int) $billable->fresh()->getWallet('SMS')->balanceInt);
        $this->assertSame([], WalletUsageService::getDeferredOverage($billable->fresh()->getWallet('SMS')));

        $this->browse(function (Browser $browser) use ($billable, $invoice): void {
            $this->actAs($browser, $billable, '/billing/invoices');
            $browser->waitFor('[dusk="invoice-row-'.$invoice->id.'"]', 15)->pause(400);
            $this->assertShownAmount($browser, 'invoice-'.$invoice->id.'-net', (int) $invoice->amount_net);
            $this->shot($browser, 'The excess invoiced at Pro\'s price');
        });
    }

    // ── Gap 24: a trial ended early on the dashboard with overage on the wallet ─

    /** @return array<string, array{0: string}> */
    public static function chargesAroundTheCancel(): array
    {
        return [
            'no charge in flight' => ['none'],
            'the sweep charged the night before' => ['before'],
            'the sweep charges while the cancel is at Mollie' => ['during'],
        ];
    }

    /**
     * The customer ends their trial on the dashboard — an immediate cancellation, overage pass
     * included — with 20 SMS beyond the quota. Whether the nightly sweep's charge came before
     * the click, lands while the click waits on Mollie's cancel, or never came, Mollie is
     * asked for exactly one overage payment, and its invoice states the period the usage was in.
     */
    #[DataProvider('chargesAroundTheCancel')]
    public function test_ending_a_trial_with_overage_collects_it_exactly_once(string $sweep): void
    {
        $this->travelSharedClockTo(self::EVENING_BEFORE_RENEWAL);

        // A trial longer than the interval (decisions: "Grace on cancellation during a trial
        // is the trial end"), so the night before the period boundary falls inside it.
        $billable = $this->subscriberRenewingTomorrow('pro', [
            'subscription_status' => SubscriptionStatus::Trial,
            'trial_ends_at' => Carbon::parse('2027-03-20 09:00', 'UTC'),
        ]);
        $periodStart = $billable->getBillingPeriodStartsAt()->toIso8601String();
        $subscriptionId = (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'];

        // 20 SMS over Pro's 50: 20 × 15 ct = 3.00 net, 3.57 gross.
        app(WalletUsageService::class)->debit($billable->fresh(), 'SMS', 70);

        if ($sweep === 'before') {
            $this->setRecurringOutcome('open');
            $this->runNightlySweep();
            $this->assertCount(1, $this->overagePayments());
        }

        $this->browse(function (Browser $browser) use ($billable, $sweep): void {
            $this->actAs($browser, $billable, '/billing');
            $browser->waitForText(__('billing::portal.end_trial'), 15)->pause(400);
            $this->shot($browser, 'Trial with overage');

            if ($sweep === 'during') {
                // Mollie's cancel is held in the server; the sweep runs meanwhile.
                $this->setRecurringOutcome('open');
                WorkbenchSetting::set('hold_mollie_request', 'CancelSubscriptionRequest');
            }

            $this->confirmInModal($browser, 'end-trial', '[wire\\:click="endTrial"]');

            if ($sweep === 'during') {
                $browser->waitUsing(20, 100, fn (): bool => WorkbenchSetting::get('mollie_request_held') === 'CancelSubscriptionRequest');
                $this->runNightlySweep();
                $this->assertCount(1, $this->overagePayments(), 'the sweep charged while the cancel waits');

                // Into the next second before Mollie answers: a second charge would otherwise
                // start a cycle stamped with the sweep's very second and carry its key, and
                // Mollie's replay — not the in-flight guard — would be what stopped it.
                $browser->pause(1500);
                WorkbenchSetting::forget('hold_mollie_request');
            }

            $browser->waitForText(__('billing::portal.flash.trial_ended'), 30)->pause(500);
            $this->shot($browser, 'Trial ended');
        });

        $billable = $billable->fresh();
        $this->assertSame(SubscriptionStatus::Cancelled, $billable->getBillingSubscriptionStatus());
        $this->assertFalse($billable->hasAccessibleBillingSubscription());
        $this->assertSame('canceled', DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->value('status'));

        // Exactly one overage payment, whoever created it.
        $payments = $this->overagePayments();
        $this->assertCount(1, $payments, 'one overage payment, not one per path');
        $this->assertSame('3.57', $payments[0]['amount_value']);

        if ($payments[0]['status'] !== 'paid') {
            FakeMollie::settle($payments[0]['id'], 'paid');
        }

        $this->assertSame(200, $this->deliverWebhook($payments[0]['id']));
        $this->assertCount(1, $this->overagePayments());

        $invoice = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Overage->value)->sole();
        $this->assertSame($payments[0]['id'], $invoice->mollie_payment_id);
        $this->assertSame(300, (int) $invoice->amount_net);
        $this->assertSame(357, (int) $invoice->amount_gross);
        // The period the usage was in starts with the trial's running period and ends with
        // the day the trial was ended (docs/decisions.md, "The overage final invoice of an
        // immediate cancellation states the real end"). Ended the evening before the
        // boundary, that is the boundary's own day either way: 11 March 00:00 when the
        // cancel charged it, the boundary itself when the sweep charged it before the click.
        $this->assertSame(Carbon::parse($periodStart)->toDateString(), $invoice->period_start?->toDateString());
        $this->assertSame(self::RENEWAL_DAY, $invoice->period_end?->utc()->toDateString());
        $this->assertSame(0, (int) $billable->fresh()->getWallet('SMS')->balanceInt);

        $this->browse(function (Browser $browser) use ($billable, $invoice): void {
            $this->actAs($browser, $billable, '/billing/invoices');
            $browser->waitFor('[dusk="invoice-row-'.$invoice->id.'"]', 15)->pause(400);
            $this->assertStringContainsString(InvoiceKind::Overage->label(), $browser->text('[dusk="invoice-row-'.$invoice->id.'"]'));
            $this->assertShownAmount($browser, 'invoice-'.$invoice->id.'-gross', 357);
            $this->assertSame(1, $this->domCount($browser, '[dusk^="invoice-row-"]'), 'one invoice: the overage');
            $this->shot($browser, 'Overage invoice after the trial ended');
        });
    }

    // ── A downgrade with excess and no mandate ───────────────────────────────

    /**
     * Pro → Starter with SMS used beyond what Starter can absorb, and no mandate on file. The
     * excess is charged with the change through the mandate, so without one the change is
     * refused — it is not "charged later". The plan screen says so and offers no button;
     * nothing is charged and the plan stays (26 November 2026).
     */
    public function test_a_downgrade_with_excess_and_no_mandate_is_refused_on_the_plan_screen(): void
    {
        $this->travelSharedClockTo(self::EVENING_BEFORE_RENEWAL);
        $billable = $this->subscriberRenewingTomorrow('pro');

        app(WalletUsageService::class)->debit($billable->fresh(), 'SMS', 100);
        $billable->forceFill(['mollie_mandate_id' => null])->save();

        $paymentsBefore = DB::table('fake_mollie_payments')->count();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitFor('[wire\\:click="previewFor(\'starter\')"]', 15)->pause(400);
            $this->clickWire($browser, '[wire\\:click="previewFor(\'starter\')"]');

            $this->waitForPresence($browser, '[dusk="change-refused-overage-needs-mandate"]', 15);
            $this->assertSame(
                __('billing::portal.error_usage_overage_requires_mandate'),
                trim($browser->text('[dusk="change-refused-overage-needs-mandate"]')),
            );
            $this->assertSame(0, count($browser->elements('[x-on\\:click*="applyChange"]')), 'no button that would only end in an error');
            $this->shot($browser, 'Downgrade with excess, no mandate: refused');
        });

        $this->assertSame('pro', $billable->fresh()->getBillingSubscriptionPlanCode());
        $this->assertSame($paymentsBefore, DB::table('fake_mollie_payments')->count(), 'nothing was charged');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * A subscriber whose period began 11 February 09:00 and renews tomorrow, 11 March —
     * on the books and at the fake Mollie alike.
     */
    private function subscriberRenewingTomorrow(string $plan, array $overrides = []): TestBillable
    {
        $billable = $this->opsSubscriber($plan);

        $billable->forceFill(array_merge([
            'subscription_period_starts_at' => Carbon::parse(self::PERIOD_START, 'UTC'),
        ], $overrides))->save();

        DB::table('fake_mollie_subscriptions')
            ->where('id', (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'])
            ->update(['start_date' => '2027-02-11', 'next_payment_date' => self::RENEWAL_DAY]);

        $billable = $billable->fresh();
        $this->assertSame(self::RENEWAL_DAY, $billable->nextBillingDate()?->toDateString(), 'the world renews tomorrow');

        return $billable;
    }

    private function runNightlySweep(): void
    {
        app()->call([new PrepareUsageOverageJob, 'handle']);
    }

    /** @return list<array<string, mixed>> overage payments at the fake, oldest first */
    private function overagePayments(): array
    {
        return DB::table('fake_mollie_payments')
            ->orderByRaw('CAST(SUBSTR(id, 7) AS INTEGER)')
            ->get()
            ->map(fn ($row): array => (array) $row)
            ->filter(fn (array $row): bool => (json_decode((string) $row['metadata'], true)['type'] ?? null) === 'overage')
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function soleOveragePayment(): array
    {
        $payments = $this->overagePayments();
        $this->assertCount(1, $payments);

        return $payments[0];
    }

    /** @return array<string, string> payment id → the caller key that created it */
    private function idempotencyKeysByPayment(): array
    {
        return DB::table('workbench_settings')->where('key', 'like', 'idem.%')->pluck('value')
            ->map(fn ($value): array => (array) json_decode((string) $value, true))
            ->filter(fn (array $entry): bool => str_starts_with((string) ($entry['key'] ?? ''), 'usage-overage:'))
            ->mapWithKeys(fn (array $entry): array => [(string) $entry['payment_id'] => (string) $entry['key']])
            ->all();
    }
}
