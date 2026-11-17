<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser\Matrix;

use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Browser\BrowserTestCase;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A coupon from the operator's form to the customer's invoice.
 *
 * Each scenario starts in the admin panel — the coupon is created through the form, not
 * through the service — and ends at the place a customer redeems that kind of code:
 * checkout, the product page, the seats page, the addon page. What is compared is the
 * amount on screen, the amount the test Mollie was asked for, the invoice that was
 * booked, and what the next renewal then collects.
 *
 * (The dashboard's own field — every type in every state — is
 * CouponDashboardRedeemMatrixTest; a recurring code on a plan change is
 * CouponMidCycleTest.)
 *
 * Starter 9.90, Pro 29.00 (3 seats, 9.90 per extra), Softdrinks 4.90, 500 Token Pack
 * 49.00; 19 % VAT.
 */
class CouponEndToEndMatrixTest extends BrowserTestCase
{
    use CouponWorkbench;

    private const FORM = 'form[wire\\:submit="save"]';

    /** What the test Mollie was asked for by the pro-rata charge of the scenario. */
    private string $prorataAmount = '';

    /**
     * Create a coupon through the admin form.
     *
     * @param  array<string, mixed>  $set  Flux controls
     * @param  array<string, string>  $typeInto  plain inputs
     */
    private function operatorCreates(Browser $browser, string $code, array $set, array $typeInto): void
    {
        $this->loginAdmin($browser);
        $browser->visit('/billing/admin/coupons/create')
            ->waitForText(__('billing::admin.coupons.create.title'), 15)->pause(500);

        $this->wireSet($browser, self::FORM, $set);
        $browser->type('input[wire\\:model="code"]', $code);

        foreach ($typeInto as $model => $value) {
            $browser->waitFor('input[wire\\:model="'.$model.'"]', 10)->type('input[wire\\:model="'.$model.'"]', $value);
        }

        $browser->pause(200);
        $this->clickWire($browser, self::FORM.' button[type="submit"]');
        $browser->waitForText(__('billing::admin.coupons.create.created', ['code' => $code]), 15);

        expect(Coupon::query()->where('code', $code)->exists())->toBeTrue();
    }

    private function walkCheckoutToConfirm(Browser $browser, TestBillable $billable, string $plan): void
    {
        $this->actAs($browser, $billable, '/billing/checkout');

        $browser->waitForText('Starter', 15);
        $browser->click('[wire\\:click*="\''.$plan.'\'"]')->pause(600);
        $this->clickWire($browser, '[wire\\:click="next"]');

        $browser->waitForText('Softdrinks', 10);
        $this->clickWire($browser, '[wire\\:click="next"]');

        $browser->waitFor('[wire\\:click="submit"]', 10)->pause(400);
    }

    /**
     * Wait for the pro-rata charge of the change just made — a payment newer than
     * `$previousPaymentId`, since the renewal that seeded the period is a recurring
     * payment too — and settle it.
     */
    private function waitForRecurringCharge(Browser $browser, string $previousPaymentId): string
    {
        // Looked up by "not the renewal", not by "newest": the two processes stamp
        // `created_at` each on their own clock string, and ordering by it is not reliable.
        $find = fn (): ?object => \Illuminate\Support\Facades\DB::table('fake_mollie_payments')
            ->where('id', '!=', $previousPaymentId)
            ->where('sequence_type', 'recurring')
            ->first();

        $browser->waitUsing(20, 200, fn (): bool => $find() !== null, 'Waited for the prorata charge to appear at Mollie.');

        $payment = $find();
        $this->deliverWebhook((string) $payment->id);

        return (string) $payment->amount_value;
    }

    /** @return array<string, array{0: string, 1: array<string, string>, 2: array<string, int>, 3: string}> */
    public static function checkoutDiscounts(): array
    {
        return [
            // 9.90 − 3.00 = 6.90 net → 8.21 gross; every renewal at the full 11.78.
            'once, fixed amount' => [
                'single_payment', ['discount_type' => 'fixed'], ['discount_value' => '300'],
                ['discount' => 300, 'net' => 690, 'gross' => 821, 'renewal_gross' => 1178], '11.78',
            ],
            // 9.90 − 25 % (2.48) = 7.42 net → 8.83 gross, now and at the renewal.
            'on every renewal, percentage' => [
                'recurring', ['discount_type' => 'percentage'], ['discount_value' => '25', 'discount_periods' => '3'],
                ['discount' => 248, 'net' => 742, 'gross' => 883, 'renewal_gross' => 883], '8.83',
            ],
        ];
    }

    /**
     * @param  array<string, string>  $set
     * @param  array<string, string>  $typeInto
     * @param  array<string, int>  $money
     */
    #[DataProvider('checkoutDiscounts')]
    public function test_a_discount_created_by_the_operator_is_what_checkout_charges(string $type, array $set, array $typeInto, array $money, string $mollieRecurring): void
    {
        $billable = $this->couponBillableIn('none');

        $this->browse(function (Browser $browser) use ($billable, $type, $set, $typeInto, $money): void {
            $this->operatorCreates($browser, 'E2E-CHECKOUT', ['intent' => 'discount', 'type' => $type] + $set, $typeInto);

            $this->walkCheckoutToConfirm($browser, $billable, 'starter');
            $browser->type('coupon_input', 'e2e-checkout');
            $this->clickWire($browser, '[wire\\:click="applyCoupon"]');
            $browser->waitForText('E2E-CHECKOUT', 10)->pause(400);

            $this->assertShownAmount($browser, 'confirm-subtotal', 990, 'subtotal before the code');
            $this->assertShownAmount($browser, 'confirm-discount', $money['discount'], 'the discount the code gives');
            $this->assertShownAmount($browser, 'confirm-net', $money['net'], 'net after the code');
            $this->assertShownAmount($browser, 'confirm-total', $money['gross'], 'what the customer agrees to pay');
            $this->shot($browser, "Checkout mit {$type}");

            $this->clickWire($browser, '[wire\\:click="submit"]');
            $this->settleAtFakeMollie($browser, 'paid');
            $browser->waitForText(__('billing::portal.return.title'), 15);

            $browser->visit('/billing')->waitForText('Starter', 15)->pause(500);
            $this->assertNextChargeMatchesBooks($browser, $billable, "dashboard after a {$type} checkout");
        });

        $billable = $billable->fresh();
        $first = BillingInvoice::query()->sole();

        // Screen == Mollie == invoice.
        expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
            ->and((int) $first->amount_gross)->toBe($money['gross'])
            ->and((int) $first->amount_net)->toBe($money['net'])
            ->and((int) collect($first->line_items)->firstWhere('kind', 'coupon')['total_net'])->toBe(-$money['discount'])
            ->and(CouponRedemption::query()->sole()->discount_amount_net)->toBe($money['discount'])
            ->and($this->mollieSubscriptionAmount($billable))->toBe($mollieRecurring);

        // … and the next renewal.
        $this->renewSubscription($billable);
        $renewal = BillingInvoice::query()->latest('id')->first();

        expect((int) $renewal->amount_gross)->toBe($money['renewal_gross'])
            ->and(collect($renewal->line_items)->firstWhere('kind', 'adjustment'))->toBeNull();
    }

    public function test_a_discount_restricted_to_a_product_is_what_the_product_page_charges(): void
    {
        $billable = $this->couponBillableIn('active');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->operatorCreates($browser, 'E2E-PACK', [
                'intent' => 'discount', 'type' => 'single_payment', 'discount_type' => 'percentage',
                'applicable_products' => ['token-pack-500'],
            ], ['discount_value' => '20']);

            $this->actAs($browser, $billable, '/billing/products');
            $browser->waitForText('500 Token Pack', 15)->pause(400);

            $browser->script('window.Flux.modal("purchase-token-pack-500").show()');
            $browser->waitFor('input[wire\\:model="couponInputs.token-pack-500"]', 10)->pause(400)
                ->type('input[wire\\:model="couponInputs.token-pack-500"]', 'E2E-PACK');
            $this->clickWire($browser, '[wire\\:click="applyCoupon(\'token-pack-500\')"]');
            $browser->waitForText('E2E-PACK', 10)->pause(500);
            $this->shot($browser, 'Produktkauf mit Code');

            $this->clickWire($browser, '[wire\\:click="purchase(\'token-pack-500\')"]');
            $this->settleAtFakeMollie($browser, 'paid');
            $browser->waitForText(__('billing::portal.return.title'), 15);
        });

        // 49.00 − 20 % = 39.20 net → 46.65 gross.
        $invoice = BillingInvoice::query()->where('invoice_kind', 'one_time_order')->sole();

        expect((int) $invoice->amount_net)->toBe(3920)
            ->and((int) $invoice->amount_gross)->toBe(4665)
            ->and($this->latestFakePayment()['amount_value'])->toBe('46.65')
            ->and(CouponRedemption::query()->sole()->discount_amount_net)->toBe(980)
            // A one-off discount: the subscription's own price is untouched.
            ->and($this->mollieSubscriptionAmount($billable))->toBe('34.51');

        // The same code is not a subscription discount: the dashboard says what it is for.
        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing');
            $this->redeemOnDashboard($browser, 'E2E-PACK');
            $browser->waitForText(__('billing::checkout.coupon_per_billable_limit_reached'), 15);
        });
    }

    /** @return array<string, array{0: string, 1: string, 2: int}> */
    public static function midCyclePlaces(): array
    {
        return [
            // Pro 29.00 + two seats 19.80 = 48.80, − 20 % = 39.04 net → 46.46 gross.
            'two extra seats' => ['seats', '46.46', 4646],
            // Pro 29.00 + Softdrinks 4.90 = 33.90, − 20 % = 27.12 net → 32.27 gross.
            'an addon' => ['addon', '32.27', 3227],
        ];
    }

    #[DataProvider('midCyclePlaces')]
    public function test_a_recurring_discount_entered_with_a_change_is_what_mollie_then_collects(string $place, string $mollieAmount, int $renewalGross): void
    {
        $billable = $this->couponBillableIn('active');

        // A paid period, so the change has something to pro-rate against.
        $this->renewSubscription($billable);
        $billable->refresh();

        $renewalPaymentId = (string) $this->latestFakePayment()['id'];

        $this->browse(function (Browser $browser) use ($billable, $place, $renewalPaymentId): void {
            $this->operatorCreates($browser, 'E2E-REC20', [
                'intent' => 'discount', 'type' => 'recurring', 'discount_type' => 'percentage',
            ], ['discount_value' => '20', 'discount_periods' => '6']);

            if ($place === 'seats') {
                $this->actAs($browser, $billable, '/billing/seats');
                $browser->waitFor('[wire\\:click="increment"]', 15)->pause(400);
                $this->clickWire($browser, '[wire\\:click="increment"]');
                $browser->pause(700);
                $this->clickWire($browser, '[wire\\:click="increment"]');
                $browser->pause(700);

                $browser->waitFor('input[wire\\:model="couponInput"]', 10)->type('input[wire\\:model="couponInput"]', 'E2E-REC20');
                $this->clickWire($browser, '[wire\\:click="applyCoupon"]');
                $browser->waitForText('E2E-REC20', 10)->pause(700);
                $this->shot($browser, 'Sitze mit Recurring-Code');

                $this->clickWire($browser, '[wire\\:click="syncSeats"]');
            } else {
                $this->actAs($browser, $billable, '/billing/addons');
                $browser->waitForText('Softdrinks', 15)->pause(400);

                $browser->script('window.Flux.modal("enable-addon-softdrinks").show()');
                $browser->waitFor('input[wire\\:model="couponInputs.softdrinks"]', 10)->pause(400)
                    ->type('input[wire\\:model="couponInputs.softdrinks"]', 'E2E-REC20');
                $this->clickWire($browser, '[wire\\:click="applyCoupon(\'softdrinks\')"]');
                $browser->waitForText('E2E-REC20', 10)->pause(700);
                $this->shot($browser, 'Addon mit Recurring-Code');

                $this->clickWire($browser, '[wire\\:click="enableAddon(\'softdrinks\')"]');
            }

            $this->prorataAmount = $this->waitForRecurringCharge($browser, $renewalPaymentId);

            $browser->visit('/billing')->waitForText('Pro', 15)->pause(500);
            $this->assertNextChargeMatchesBooks($browser, $billable, "dashboard after {$place} with a recurring code");
            $this->shot($browser, 'Nächste Abbuchung mit Rabatt');
        });

        $billable = $billable->fresh();
        $prorata = BillingInvoice::query()->latest('id')->first();

        // The pro-rata charge Mollie took is the invoice that was booked for it …
        expect(number_format(((int) $prorata->amount_gross) / 100, 2, '.', ''))->toBe($this->prorataAmount)
            ->and($billable->getBillingSubscriptionMeta()['active_recurring_coupon']['code'] ?? null)->toBe('E2E-REC20')
            // … and the subscription now carries the discounted price for every renewal.
            ->and($this->mollieSubscriptionAmount($billable))->toBe($mollieAmount);

        $this->renewSubscription($billable);
        $renewal = BillingInvoice::query()->latest('id')->first();

        expect((int) $renewal->amount_gross)->toBe($renewalGross)
            ->and(collect($renewal->line_items)->firstWhere('kind', 'coupon'))->not->toBeNull()
            ->and(collect($renewal->line_items)->firstWhere('kind', 'adjustment'))->toBeNull();
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, string>, 3: string}> */
    public static function restrictedCodes(): array
    {
        return [
            'for another plan' => ['single_payment', ['discount_type' => 'percentage', 'applicable_plans' => ['pro']], ['discount_value' => '10'], 'billing::checkout.coupon_plan_mismatch'],
            'for another interval' => ['single_payment', ['discount_type' => 'percentage', 'applicable_intervals' => ['yearly']], ['discount_value' => '10'], 'billing::checkout.coupon_interval_mismatch'],
            'only with an addon' => ['single_payment', ['discount_type' => 'percentage', 'applicable_addons' => ['softdrinks']], ['discount_value' => '10'], 'billing::checkout.coupon_addon_mismatch'],
            'only for a product' => ['single_payment', ['discount_type' => 'percentage', 'applicable_products' => ['token-pack-500']], ['discount_value' => '10'], 'billing::checkout.coupon_product_mismatch'],
            'below its minimum order' => ['single_payment', ['discount_type' => 'percentage'], ['discount_value' => '10', 'minimum_order_amount_net' => '5000'], 'billing::checkout.coupon_min_order'],
            'not valid yet' => ['single_payment', ['discount_type' => 'percentage', 'valid_from' => '2031-01-01T00:00'], ['discount_value' => '10'], 'billing::checkout.coupon_not_yet_valid'],
            'expired' => ['single_payment', ['discount_type' => 'percentage', 'valid_until' => '2026-01-01T00:00'], ['discount_value' => '10'], 'billing::checkout.coupon_expired'],
            'switched off' => ['single_payment', ['discount_type' => 'percentage', 'active' => false], ['discount_value' => '10'], 'billing::checkout.coupon_inactive'],
            'a type checkout does not take' => ['plan_waiver', ['intent' => 'free_time'], ['waiver_periods' => '2'], 'billing::checkout.coupon_type_not_allowed_in_context'],
        ];
    }

    /**
     * Each restriction the form offers, set by the operator and then met by a customer
     * whose order does not satisfy it: Starter, monthly, no addon.
     *
     * @param  array<string, mixed>  $set
     * @param  array<string, string>  $typeInto
     */
    #[DataProvider('restrictedCodes')]
    public function test_a_restriction_set_by_the_operator_refuses_the_order_it_excludes(string $type, array $set, array $typeInto, string $reason): void
    {
        $billable = $this->couponBillableIn('none');

        $this->browse(function (Browser $browser) use ($billable, $type, $set, $typeInto, $reason): void {
            $this->operatorCreates($browser, 'E2E-NARROW', ['intent' => 'discount'] + $set + ['type' => $type], $typeInto);

            $this->walkCheckoutToConfirm($browser, $billable, 'starter');
            $browser->type('coupon_input', 'E2E-NARROW');
            $this->clickWire($browser, '[wire\\:click="applyCoupon"]');
            $browser->waitForText(__($reason), 15);

            // Refused means refused: the total is the undiscounted one.
            $this->assertShownAmount($browser, 'confirm-total', 1178, 'total after a refused code');
            $this->shot($browser, 'Abgelehnt am Checkout');
        });

        expect(CouponRedemption::query()->count())->toBe(0);
    }

    public function test_a_campaign_limit_and_a_per_customer_limit_set_by_the_operator_hold(): void
    {
        $first = $this->couponBillableIn('active');
        $second = $this->couponBillableIn('active');

        $this->browse(function (Browser $browser) use ($first, $second): void {
            $this->operatorCreates($browser, 'E2E-TWICE', ['intent' => 'credits'], [
                'credits_payload.Tokens' => '10',
                'max_redemptions' => '3',
                'max_redemptions_per_billable' => '2',
            ]);

            // The first customer may redeem it twice, and not a third time.
            $this->actAs($browser, $first, '/billing');

            foreach ([1, 2] as $time) {
                $this->redeemOnDashboard($browser, 'E2E-TWICE');
                $browser->waitForText(__('billing::portal.coupon_redeem_success', ['code' => 'E2E-TWICE']), 15)->pause(300);
                $browser->refresh()->pause(600);
            }

            $this->redeemOnDashboard($browser, 'E2E-TWICE');
            $browser->waitForText(__('billing::checkout.coupon_per_billable_limit_reached'), 15);

            // The second customer gets the third and last redemption of the campaign.
            $this->actAs($browser, $second, '/billing');
            $this->redeemOnDashboard($browser, 'E2E-TWICE');
            $browser->waitForText(__('billing::portal.coupon_redeem_success', ['code' => 'E2E-TWICE']), 15)->pause(300);
            $browser->refresh()->pause(600);

            $this->redeemOnDashboard($browser, 'E2E-TWICE');
            $browser->waitForText(__('billing::checkout.coupon_exhausted'), 15);
            $this->shot($browser, 'Kampagne ausgeschöpft');
        });

        expect(Coupon::query()->where('code', 'E2E-TWICE')->sole()->redemptions_count)->toBe(3)
            ->and((int) $first->fresh()->getWallet('Tokens')->balanceInt)->toBe(120)
            ->and((int) $second->fresh()->getWallet('Tokens')->balanceInt)->toBe(110);
    }

    public function test_a_campaign_link_applies_the_operators_code_without_showing_it_being_typed(): void
    {
        $billable = $this->couponBillableIn('none');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->operatorCreates($browser, 'E2E-LINK', [
                'intent' => 'discount', 'type' => 'single_payment', 'discount_type' => 'percentage',
                'auto_apply_token' => 'spring-link',
            ], ['discount_value' => '50']);

            // The promotion link parks the code; checkout picks it up.
            $this->actAs($browser, $billable, route(\GraystackIT\MollieBilling\Support\BillingRoute::promotion(), ['token' => 'spring-link'], false));
            $this->waitForPathContains($browser, 'checkout');

            $browser->waitForText('Starter', 15);
            $browser->click('[wire\\:click*="\'starter\'"]')->pause(600);
            $this->clickWire($browser, '[wire\\:click="next"]');
            $browser->waitForText('Softdrinks', 10);
            $this->clickWire($browser, '[wire\\:click="next"]');
            $browser->waitFor('[wire\\:click="submit"]', 10)->pause(400);

            // 9.90 − 50 % = 4.95 net → 5.89 gross, without anything typed in.
            $browser->assertSee('E2E-LINK');
            $this->assertShownAmount($browser, 'confirm-total', 589, 'total with the campaign code applied');
            $this->shot($browser, 'Kampagnen-Link: Code vorbelegt');

            $this->clickWire($browser, '[wire\\:click="submit"]');
            $this->settleAtFakeMollie($browser, 'paid');
            $browser->waitForText(__('billing::portal.return.title'), 15);
        });

        expect((int) BillingInvoice::query()->sole()->amount_gross)->toBe(589)
            ->and($this->latestFakePayment()['amount_value'])->toBe('5.89');
    }

    /**
     * "Stackable", as the operator ticks it: two stackable codes combine on one order,
     * each applied to what the previous one left; a code that is not stackable closes
     * the field.
     */
    public function test_stackable_codes_combine_and_a_non_stackable_one_stands_alone(): void
    {
        $billable = $this->couponBillableIn('active');

        $this->browse(function (Browser $browser) use ($billable): void {
            foreach (['E2E-STACK-A' => true, 'E2E-STACK-B' => true, 'E2E-ALONE' => false] as $code => $stackable) {
                $this->operatorCreates($browser, $code, [
                    'intent' => 'discount', 'type' => 'single_payment', 'discount_type' => 'percentage', 'stackable' => $stackable,
                ], ['discount_value' => '10']);
            }

            $input = 'input[wire\\:model="couponInputs.token-pack-500"]';
            $apply = '[wire\\:click="applyCoupon(\'token-pack-500\')"]';

            // Not stackable: once it is applied there is no field for a second code.
            $this->actAs($browser, $billable, '/billing/products');
            $browser->waitForText('500 Token Pack', 15)->pause(400);
            $browser->script('window.Flux.modal("purchase-token-pack-500").show()');
            $browser->waitFor($input, 10)->pause(400)->type($input, 'E2E-ALONE');
            $this->clickWire($browser, $apply);
            $browser->waitForText('E2E-ALONE', 10)->pause(600);
            expect($this->domCount($browser, $input))->toBe(0);
            $this->shot($browser, 'Nicht stapelbar: kein zweites Feld');

            // Stackable: both are taken.
            $browser->visit('/billing/products')->waitForText('500 Token Pack', 15)->pause(400);
            $browser->script('window.Flux.modal("purchase-token-pack-500").show()');

            foreach (['E2E-STACK-A', 'E2E-STACK-B'] as $code) {
                $browser->waitFor($input, 10)->pause(400)->type($input, $code);
                $this->clickWire($browser, $apply);
                $browser->waitForText($code, 10)->pause(600);
            }
            $this->shot($browser, 'Zwei stapelbare Codes');

            $this->clickWire($browser, '[wire\\:click="purchase(\'token-pack-500\')"]');
            $this->settleAtFakeMollie($browser, 'paid');
            $browser->waitForText(__('billing::portal.return.title'), 15);
        });

        // 49.00 − 10 % = 44.10, − 10 % of that = 39.69 net → 47.23 gross.
        $invoice = BillingInvoice::query()->where('invoice_kind', 'one_time_order')->sole();

        expect((int) $invoice->amount_net)->toBe(3969)
            ->and((int) $invoice->amount_gross)->toBe(4723)
            ->and($this->latestFakePayment()['amount_value'])->toBe('47.23')
            ->and(CouponRedemption::query()->count())->toBe(2);
    }
}
