<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser\Matrix;

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingVatValidation;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\PlanWaiver;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Browser\BrowserTestCase;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The booking paths that do not run through the checkout component, in a real browser:
 * the upgrade from a free plan (plan screen), paid extras on a gifted plan (addons and
 * seats screens) and one-time products (products screen) — each crossed with the coupon
 * states it admits and with every VAT situation.
 */
class CheckoutOtherPathsMatrixTest extends BrowserTestCase
{
    use CheckoutMatrixWorld;

    private const VAT_CASES = ['AT', 'DE', 'RC', 'DOM', 'ADD'];

    /** A subscriber (active, mandate) in one of the VAT situations. */
    private function cmSubscriber(string $vatCase): TestBillable
    {
        $vat = self::cmVat($vatCase);

        if ($vatCase === 'ADD') {
            $this->cmConfig('mollie-billing.additional_countries', ['CH' => ['vat_rate' => 8.1, 'name' => 'Switzerland']]);
        }

        $this->setIpCountry($vat['country']);

        $billable = $this->makeActiveSubscriber('starter', 'monthly', [
            'billing_country' => $vat['country'],
            'tax_country_user' => $vat['country'],
            'tax_country_payment' => $vat['country'],
            'tax_country_ip' => $vat['country'],
            'vat_number' => $vat['vat'],
        ]);

        if ($vat['vat'] !== null) {
            BillingVatValidation::query()->create([
                'billable_type' => $billable->getMorphClass(), 'billable_id' => $billable->getKey(),
                'vat_number' => $vat['vat'], 'country_code' => substr($vat['vat'], 0, 2),
                'valid' => true, 'vies_response' => [], 'checked_at' => now(),
            ]);
        }

        return $billable->refresh();
    }

    // ── One-time product ───────────────────────────────────────────────────────

    /** @return array<string, array{0:string,1:string}> */
    public static function productCases(): array
    {
        $states = [
            'none', 'single-percent', 'single-fixed', 'single-full', 'stacked',
            'refused-expired', 'refused-inactive', 'refused-exhausted', 'refused-other-product',
            'refused-plan', 'refused-minimum', 'refused-not-stackable', 'refused-recurring',
        ];

        $cases = [];
        foreach ($states as $i => $state) {
            $vat = self::VAT_CASES[$i % 5];
            $cases["product × {$state} × {$vat}"] = [$state, $vat];
        }

        // States only this path has: every VAT situation.
        foreach (['stacked', 'single-full', 'refused-not-stackable', 'refused-other-product', 'refused-recurring'] as $state) {
            foreach (self::VAT_CASES as $vat) {
                $cases["product × {$state} × {$vat}"] = [$state, $vat];
            }
        }

        return $cases;
    }

    #[DataProvider('productCases')]
    public function test_a_one_time_product_shows_what_it_charges_and_invoices(string $state, string $vatCase): void
    {
        $vat = self::cmVat($vatCase);
        $rate = $vat['rate'];
        $billable = $this->cmSubscriber($vatCase);
        $price = 4900;
        $product = 'token-pack-500';

        $single = ['name' => 'Matrix', 'active' => true, 'type' => CouponType::SinglePayment];
        $create = fn (string $code, array $a) => $this->coupons()->create(array_merge($single, ['code' => $code], $a));

        /** @var list<string> $codes typed, in order */
        $codes = [];
        /** @var list<int> $discounts net discounts that apply, in order */
        $discounts = [];
        $error = null;

        switch ($state) {
            case 'single-percent':
                $create('P15', ['discount_type' => DiscountType::Percentage, 'discount_value' => 15]);
                $codes = ['P15'];
                $discounts = [735];
                break;
            case 'single-fixed':
                $create('F555', ['discount_type' => DiscountType::Fixed, 'discount_value' => 555]);
                $codes = ['F555'];
                $discounts = [555];
                break;
            case 'single-full':
                $create('FULL', ['discount_type' => DiscountType::Percentage, 'discount_value' => 100]);
                $codes = ['FULL'];
                $discounts = [4900];
                break;
            case 'stacked':
                // 1.50 + 1.50: at 19 % each line's VAT rounds to 0.29, the sum's to 0.57.
                $create('A150', ['discount_type' => DiscountType::Fixed, 'discount_value' => 150, 'stackable' => true]);
                $create('B150', ['discount_type' => DiscountType::Fixed, 'discount_value' => 150, 'stackable' => true]);
                $codes = ['A150', 'B150'];
                $discounts = [150, 150];
                break;
            case 'refused-not-stackable':
                $create('KEEP', ['discount_type' => DiscountType::Fixed, 'discount_value' => 300, 'stackable' => false]);
                $create('MORE', ['discount_type' => DiscountType::Fixed, 'discount_value' => 100, 'stackable' => true]);
                $codes = ['KEEP', 'MORE'];
                $discounts = [300];
                break;
            case 'refused-expired':
                $create('NOPE', ['discount_type' => DiscountType::Percentage, 'discount_value' => 15, 'valid_until' => now()->subDay()]);
                [$codes, $error] = [['NOPE'], 'coupon_expired'];
                break;
            case 'refused-inactive':
                $create('NOPE', ['discount_type' => DiscountType::Percentage, 'discount_value' => 15, 'active' => false]);
                [$codes, $error] = [['NOPE'], 'coupon_inactive'];
                break;
            case 'refused-exhausted':
                $create('NOPE', ['discount_type' => DiscountType::Percentage, 'discount_value' => 15, 'max_redemptions' => 1])->forceFill(['redemptions_count' => 1])->save();
                [$codes, $error] = [['NOPE'], 'coupon_exhausted'];
                break;
            case 'refused-other-product':
                $create('NOPE', ['discount_type' => DiscountType::Percentage, 'discount_value' => 15, 'applicable_products' => ['onboarding-session']]);
                [$codes, $error] = [['NOPE'], 'coupon_product_mismatch'];
                break;
            case 'refused-plan':
                $create('NOPE', ['discount_type' => DiscountType::Percentage, 'discount_value' => 15, 'applicable_plans' => ['pro']]);
                [$codes, $error] = [['NOPE'], 'coupon_plan_mismatch'];
                break;
            case 'refused-minimum':
                $create('NOPE', ['discount_type' => DiscountType::Percentage, 'discount_value' => 15, 'minimum_order_amount_net' => 4901]);
                [$codes, $error] = [['NOPE'], 'coupon_min_order'];
                break;
            case 'refused-recurring':
                $this->coupons()->create(['code' => 'NOPE', 'name' => 'Matrix', 'active' => true, 'type' => CouponType::Recurring,
                    'discount_type' => DiscountType::Percentage, 'discount_value' => 15, 'discount_periods' => 3]);
                [$codes, $error] = [['NOPE'], 'coupon_type_not_allowed_in_context'];
                break;
        }

        $lines = [$price, ...array_map(fn (int $d): int => -$d, $discounts)];
        $net = array_sum($lines);
        $gross = self::cmGross($lines, $rate);
        $priceGross = self::cmGross([$price], $rate);
        $what = "product × {$state} × {$vatCase}";

        $this->browse(function (Browser $browser) use ($billable, $product, $codes, $discounts, $error, $gross, $priceGross, $net, $state, $vat, $what): void {
            $this->actAs($browser, $billable, '/billing/products');
            $browser->waitForText('500 Token Pack', 15)->pause(400);

            $this->assertShownAmount($browser, 'product-price-'.$product, $priceGross, $what.': price on the card');

            $browser->script(sprintf('window.Flux.modal(%s).show()', json_encode('purchase-'.$product)));
            $input = 'input[wire\\:model="couponInputs.'.$product.'"]';
            $browser->waitFor('[wire\\:click="purchase(\''.$product.'\')"]', 10)->pause(400);

            foreach ($codes as $code) {
                if ($this->domCount($browser, $input) === 0) {
                    break; // a non-stackable coupon is applied: there is no field for a second one
                }

                $browser->type($input, $code);
                $this->clickWire($browser, '[wire\\:click="applyCoupon(\''.$product.'\')"]');
                $browser->pause(1200);
            }

            if ($error !== null) {
                $browser->assertSee(__('billing::checkout.'.$error));
                $this->assertSame(0, $this->domCount($browser, '[dusk="product-total-'.$product.'"]'), $what.': a refused code changes no amount');
            }

            if ($state === 'refused-not-stackable') {
                $this->assertSame(0, $this->domCount($browser, $input), $what.': no field for a second coupon');
            }

            if ($discounts !== []) {
                $this->assertShownAmount($browser, 'product-total-'.$product, $gross, $what.': amount to pay in the dialog');
            }

            $this->shot($browser, 'Kaufdialog');
            $this->clickWire($browser, '[wire\\:click="purchase(\''.$product.'\')"]');

            if ($net === 0) {
                // Fully covered: completed on the spot, no trip to Mollie.
                $browser->pause(2500);

                return;
            }

            $this->settleAtFakeMollie($browser, 'paid', $vat['mollie']);
            $browser->waitForText(__('billing::portal.return.title'), 20);
        });

        $invoice = BillingInvoice::query()->where('invoice_kind', InvoiceKind::OneTimeOrder)->sole();
        $items = collect($invoice->line_items);

        $this->assertSame($net, (int) $invoice->amount_net, $what.': invoice net');
        $this->assertSame($gross, (int) $invoice->amount_gross, $what.': invoice gross');
        $this->assertSame($gross - $net, (int) $invoice->amount_vat, $what.': invoice VAT');
        $this->assertSame($net, (int) $items->sum('amount_net'), $what);
        $this->assertSame($gross, (int) $items->sum('amount_gross'), $what);
        $this->assertNull($items->firstWhere('kind', 'adjustment'), $what.': adjustment line');
        $this->assertSame(count($discounts), CouponRedemption::query()->count(), $what.': redemptions');
        $this->assertSame(550, $billable->refresh()->remainingBillingQuota('Tokens'), $what.': the pack was credited');

        if ($net === 0) {
            $this->assertNull($invoice->mollie_payment_id, $what.': a fully covered order makes no payment');
            $this->assertSame(0, DB::table('fake_mollie_payments')->where('sequence_type', 'oneoff')->count(), $what);
        } else {
            $this->assertSame($gross, self::cmMinor($this->latestFakePayment()['amount_value']), $what.': amount at Mollie');
        }

        // A product is not a subscription: the existing one is untouched.
        $this->assertSame(1, DB::table('fake_mollie_subscriptions')->count(), $what);
        $this->assertSame('starter', $billable->subscription_plan_code, $what);
    }

    // ── Upgrade from a free plan ───────────────────────────────────────────────

    /** @return array<string, array{0:string,1:string}> */
    public static function upgradeCases(): array
    {
        $cases = [];
        foreach (['none', 'recurring-percent', 'recurring-fixed', 'recurring-date', 'refused-single', 'none', 'recurring-percent'] as $i => $state) {
            $vat = self::VAT_CASES[$i % 5];
            $cases["upgrade × {$state} × {$vat}"] = [$state, $vat];
        }

        // Every (coupon state, VAT situation) pair of the states that discount.
        foreach (['recurring-percent', 'recurring-fixed', 'recurring-date'] as $state) {
            foreach (self::VAT_CASES as $vat) {
                $cases["upgrade × {$state} × {$vat}"] = [$state, $vat];
            }
        }

        return $cases;
    }

    #[DataProvider('upgradeCases')]
    public function test_an_upgrade_from_a_free_plan_shows_what_it_charges_invoices_and_renews(string $state, string $vatCase): void
    {
        $this->cmCatalog();
        $vat = self::cmVat($vatCase);
        $rate = $vat['rate'];

        $billable = $this->cmCustomer($vatCase, [
            'subscription_source' => SubscriptionSource::Local,
            'subscription_status' => SubscriptionStatus::Active,
            'subscription_plan_code' => 'mx-free',
            'subscription_interval' => 'monthly',
            'subscription_period_starts_at' => now()->subDays(5),
        ]);

        // The plan screen admits recurring coupons only: a single_payment code is
        // answered at the field and the upgrade is priced without it.
        $refusedCode = $state === 'refused-single' ? $this->cmCoupon('single-percent')['code'] : null;
        $coupon = in_array($state, ['none', 'refused-single'], true) ? null : $this->cmCoupon($state);
        $lines = [2950];
        $discount = self::cmDiscount($coupon, 2950);
        $first = $discount > 0 ? [2950, -$discount] : [2950];
        $firstGross = self::cmGross($first, $rate);
        $recurring = $coupon !== null && $coupon['recurring'] ? $first : $lines;
        $recurringGross = self::cmGross($recurring, $rate);
        $what = "upgrade × {$state} × {$vatCase}";

        $this->browse(function (Browser $browser) use ($billable, $coupon, $refusedCode, $firstGross, $vat, $what): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitFor('[wire\\:click="previewFor(\'mx-team\')"]', 15);
            $this->clickWire($browser, '[wire\\:click="previewFor(\'mx-team\')"]');
            $browser->pause(1200);

            if ($coupon !== null) {
                $browser->waitFor('input[wire\\:model="couponInput"]', 10);
                $browser->type('couponInput', $coupon['code']);
                $this->clickWire($browser, '[wire\\:click="applyCoupon"]');
                $browser->waitForText($coupon['code'], 10)->pause(900);
            }

            if ($refusedCode !== null) {
                $browser->waitFor('input[wire\\:model="couponInput"]', 10);
                $browser->type('couponInput', $refusedCode);
                $this->clickWire($browser, '[wire\\:click="applyCoupon"]');
                $browser->waitForText(__('billing::checkout.coupon_type_not_allowed_in_context'), 10)->pause(500);
                $this->shot($browser, 'Upgrade: Einmal-Coupon abgelehnt');
            }

            $this->clickWire($browser, '[x-on\\:click*="applyChange"]');
            $this->waitForPresence($browser, '[dusk="upgrade-total"]', 15);
            $browser->pause(400);
            $this->shot($browser, 'Upgrade bestätigen');

            $this->assertShownAmount($browser, 'upgrade-total', $firstGross, $what.': total on the upgrade confirmation');

            // Inside a dialog WebDriver does not consider interactable: click it in the page.
            $browser->script('document.querySelector(\'[wire\\\\:click="confirmAndPay"]\').click()');
            $this->settleAtFakeMollie($browser, 'paid', $vat['mollie']);
            $browser->waitForText(__('billing::portal.return.title'), 20);
        });

        $billable->refresh();

        $this->assertSame($firstGross, self::cmMinor($this->latestFakePayment()['amount_value']), $what.': amount at Mollie');
        $this->cmAssertInvoice($this->cmNewestInvoice($billable), array_sum($first), $firstGross, $rate, $what.' (first invoice)');
        $this->assertSame(SubscriptionSource::Mollie, $billable->subscription_source, $what);
        $this->assertSame('mx-team', $billable->subscription_plan_code, $what);
        $this->assertSame($recurringGross, $this->cmSubscriptionMinor($billable), $what.': amount of the Mollie subscription');

        $renewalPaymentId = $this->renewSubscription($billable);
        $this->cmAssertInvoice(
            BillingInvoice::query()->where('mollie_payment_id', $renewalPaymentId)->first(),
            array_sum($recurring), $recurringGross, $rate, $what.' (renewal)',
        );
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function fullCoverageCases(): array
    {
        $cases = [];
        foreach (['recurring-full', 'recurring-full-date'] as $state) {
            foreach (self::VAT_CASES as $vat) {
                $cases["upgrade × {$state} × {$vat}"] = [$state, $vat];
            }
        }

        return $cases;
    }

    /**
     * A recurring code that covers the whole price: nothing is due today, so the plan
     * screen asks Mollie for a €0 authorisation — the paid plan needs a mandate — and the
     * activation is the checkout's own. Shown: 0.00 today, and when and what the first
     * charge is. Booked: the plan, an invoice over nothing, the full price at Mollie from
     * after the code.
     */
    #[DataProvider('fullCoverageCases')]
    public function test_an_upgrade_a_code_covers_in_full_goes_through_a_zero_authorisation(string $state, string $vatCase): void
    {
        $this->cmCatalog();
        $vat = self::cmVat($vatCase);
        $billable = $this->cmCustomer($vatCase, [
            'subscription_source' => SubscriptionSource::Local,
            'subscription_status' => SubscriptionStatus::Active,
            'subscription_plan_code' => 'mx-free',
            'subscription_interval' => 'monthly',
            'subscription_period_starts_at' => now()->subDays(5),
        ]);
        $coupon = $this->cmCoupon($state);
        $fullGross = self::cmGross([2950], $vat['rate']);
        $what = "upgrade × {$state} × {$vatCase}";

        $this->browse(function (Browser $browser) use ($billable, $coupon, $fullGross, $vat, $what): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitFor('[wire\\:click="previewFor(\'mx-team\')"]', 15);
            $this->clickWire($browser, '[wire\\:click="previewFor(\'mx-team\')"]');
            $browser->pause(1200)->waitFor('input[wire\\:model="couponInput"]', 10);
            $browser->type('couponInput', $coupon['code']);
            $this->clickWire($browser, '[wire\\:click="applyCoupon"]');
            $browser->waitForText($coupon['code'], 10)->pause(900);

            $this->clickWire($browser, '[x-on\\:click*="applyChange"]');
            $this->waitForPresence($browser, '[dusk="upgrade-total"]', 15);
            $browser->pause(400);

            // Today: nothing. Then: the full price, on a named day.
            $this->assertShownAmount($browser, 'upgrade-total', 0, $what.': nothing is due today');
            $notice = (string) $browser->script('return document.querySelector(\'[dusk="upgrade-first-charge"]\')?.textContent ?? ""')[0];
            $this->assertStringContainsString(BillingMoney::format($fullGross), $notice, $what.': the first charge is the full price');
            $this->assertStringContainsString(
                __('billing::portal.upgrade_authorisation_notice'),
                (string) $browser->script('return document.querySelector(\'[dusk="upgrade-authorisation-notice"]\')?.textContent ?? ""')[0],
                $what.': the customer is told about the short trip to the payment provider',
            );
            $this->shot($browser, 'Upgrade: heute 0,00, erste Abbuchung später');

            $browser->script('document.querySelector(\'[wire\\\\:click="confirmAndPay"]\').click()');
            $this->waitForPathContains($browser, '/fake-mollie/checkout/');
            $this->assertSame(0, self::cmMinor($this->latestFakePayment()['amount_value']), $what.': a €0 authorisation');
            $browser->waitFor('@fake-mollie-pay', 10)->click('@fake-mollie-pay');
            $browser->waitForText(__('billing::portal.return.title'), 20);
        });

        $billable->refresh();
        $invoice = $this->cmNewestInvoice($billable);

        $this->assertSame('mx-team', $billable->subscription_plan_code, $what);
        $this->assertSame(SubscriptionSource::Mollie, $billable->subscription_source, $what);
        $this->assertSame(SubscriptionStatus::Active, $billable->subscription_status, $what);
        $this->assertNotNull($invoice, $what.': the audit invoice');
        $this->assertSame(0, (int) $invoice->amount_gross, $what.': invoice over nothing');
        $this->assertSame(-2950, (int) collect($invoice->line_items)->where('kind', 'coupon')->sum('amount_net'), $what);
        $this->assertSame(1, CouponRedemption::query()->count(), $what.': redeemed once');
        $this->assertSame($fullGross, $this->cmSubscriptionMinor($billable), $what.': Mollie holds the full price');
        $this->assertGreaterThan(now()->addDays(60)->toDateString(), $this->cmSubscriptionStartDate($billable), $what.': first charge after the code');
    }

    public function test_an_abandoned_zero_authorisation_leaves_the_free_plan_as_it_was(): void
    {
        $this->cmCatalog();
        $billable = $this->cmCustomer('AT', [
            'subscription_source' => SubscriptionSource::Local,
            'subscription_status' => SubscriptionStatus::Active,
            'subscription_plan_code' => 'mx-free',
            'subscription_interval' => 'monthly',
            'subscription_period_starts_at' => now()->subDays(5),
        ]);
        $coupon = $this->cmCoupon('recurring-full');

        $this->browse(function (Browser $browser) use ($billable, $coupon): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitFor('[wire\\:click="previewFor(\'mx-team\')"]', 15);
            $this->clickWire($browser, '[wire\\:click="previewFor(\'mx-team\')"]');
            $browser->pause(1200)->waitFor('input[wire\\:model="couponInput"]', 10);
            $browser->type('couponInput', $coupon['code']);
            $this->clickWire($browser, '[wire\\:click="applyCoupon"]');
            $browser->waitForText($coupon['code'], 10)->pause(900);
            $this->clickWire($browser, '[x-on\\:click*="applyChange"]');
            $this->waitForPresence($browser, '[dusk="upgrade-total"]', 15);
            $browser->pause(400);
            $browser->script('document.querySelector(\'[wire\\\\:click="confirmAndPay"]\').click()');

            $this->settleAtFakeMollie($browser, 'canceled', 'AT');
            $browser->waitForText(__('billing::portal.return.failed_title'), 20)
                ->assertSee(__('billing::portal.return.failed_body_canceled'));
            $this->shot($browser, 'Upgrade: Autorisierung abgebrochen');
        });

        $billable->refresh();
        $this->assertSame('mx-free', $billable->subscription_plan_code);
        $this->assertSame(SubscriptionSource::Local, $billable->subscription_source);
        $this->assertSame(SubscriptionStatus::Active, $billable->subscription_status);
        $this->assertSame(0, CouponRedemption::query()->count(), 'the code is not spent');
        $this->assertSame(0, BillingInvoice::query()->count());
        $this->assertSame(0, DB::table('fake_mollie_subscriptions')->count());
    }

    /** Plan screen: pick Matrix Team, apply the code, open the confirmation, confirm. */
    private function cmConfirmFullCoverageUpgrade(Browser $browser, TestBillable $billable, string $code): void
    {
        $this->actAs($browser, $billable, '/billing/plan');
        $browser->waitFor('[wire\\:click="previewFor(\'mx-team\')"]', 15);
        $this->clickWire($browser, '[wire\\:click="previewFor(\'mx-team\')"]');
        $browser->pause(1200)->waitFor('input[wire\\:model="couponInput"]', 10);
        $browser->type('couponInput', $code);
        $this->clickWire($browser, '[wire\\:click="applyCoupon"]');
        $browser->waitForText($code, 10)->pause(900);
        $this->clickWire($browser, '[x-on\\:click*="applyChange"]');
        $this->waitForPresence($browser, '[dusk="upgrade-total"]', 15);
        $browser->pause(400);
        $this->assertShownAmount($browser, 'upgrade-total', 0, 'nothing is due today');
        $browser->script('document.querySelector(\'[wire\\\\:click="confirmAndPay"]\').click()');
        $this->waitForPathContains($browser, '/fake-mollie/checkout/');
    }

    private function cmFreeCustomer(): TestBillable
    {
        return $this->cmCustomer('AT', [
            'subscription_source' => SubscriptionSource::Local,
            'subscription_status' => SubscriptionStatus::Active,
            'subscription_plan_code' => 'mx-free',
            'subscription_interval' => 'monthly',
            'subscription_period_starts_at' => now()->subDays(5),
        ]);
    }

    public function test_a_gifted_plan_is_upgraded_under_a_fully_covering_code_through_the_plan_screen(): void
    {
        $this->cmCatalog();
        $billable = $this->cmCustomer('AT');
        $gift = $this->coupons()->accessGrantCoupon('GIFT-MX', 'starter', 'monthly', [], 90);
        $this->coupons()->redeem($gift, $billable, []);
        $this->assertNotNull($billable->refresh()->subscription_ends_at);
        $coupon = $this->cmCoupon('recurring-full');

        $this->browse(function (Browser $browser) use ($billable, $coupon): void {
            $this->cmConfirmFullCoverageUpgrade($browser, $billable, $coupon['code']);
            $this->assertSame(0, self::cmMinor($this->latestFakePayment()['amount_value']));
            $browser->waitFor('@fake-mollie-pay', 10)->click('@fake-mollie-pay');
            $browser->waitForText(__('billing::portal.return.title'), 20);
        });

        // Matrix Team 29.50 + 20 % = 35.40; the gift's end date went with the gift.
        $billable->refresh();
        $this->assertSame('mx-team', $billable->subscription_plan_code);
        $this->assertSame(SubscriptionSource::Mollie, $billable->subscription_source);
        $this->assertSame(SubscriptionStatus::Active, $billable->subscription_status);
        $this->assertNull($billable->subscription_ends_at);
        $this->assertSame(3540, $this->cmSubscriptionMinor($billable));
        $this->assertSame(0, (int) $this->cmNewestInvoice($billable)->amount_gross);
        $this->assertSame(1, CouponRedemption::query()->where('coupon_id', '!=', $gift->id)->count());
    }

    public function test_the_return_page_waits_for_an_upgrade_from_a_free_plan_instead_of_calling_it_paid(): void
    {
        $this->cmCatalog();
        $billable = $this->cmFreeCustomer();
        $coupon = $this->cmCoupon('recurring-full');

        $this->browse(function (Browser $browser) use ($billable, $coupon): void {
            $this->cmConfirmFullCoverageUpgrade($browser, $billable, $coupon['code']);

            // Back before anything was confirmed at Mollie: the free plan the customer
            // already has is not the result — the page waits.
            $browser->visit('/billing/return')->waitForText(__('billing::portal.return.processing_title'), 15)
                ->assertDontSee(__('billing::portal.return.title'));
            $this->shot($browser, 'Return-Seite: Upgrade noch offen');

            // The authorisation goes through in the background; the next tick shows it.
            $payment = $this->latestFakePayment();
            \Workbench\App\FakeMollie\FakeMollie::settle($payment['id'], 'paid', 'AT');
            $this->deliverWebhook($payment['id']);
            $browser->waitForText(__('billing::portal.return.title'), 20);
        });

        $this->assertSame('mx-team', $billable->refresh()->subscription_plan_code);
        $this->assertSame(SubscriptionSource::Mollie, $billable->subscription_source);
    }

    public function test_the_activation_retry_finishes_an_upgrade_mollie_refused_at_first(): void
    {
        $this->cmCatalog();
        $billable = $this->cmFreeCustomer();
        $coupon = $this->cmCoupon('recurring-full');
        \Workbench\App\Support\WorkbenchSetting::set('fail_create_subscription', '1');

        $this->browse(function (Browser $browser) use ($billable, $coupon): void {
            $this->cmConfirmFullCoverageUpgrade($browser, $billable, $coupon['code']);
            $browser->waitFor('@fake-mollie-pay', 10)->click('@fake-mollie-pay');

            // The mandate is there, the subscription is not: the page keeps waiting.
            $browser->waitForText(__('billing::portal.return.processing_title'), 20)->pause(2500)
                ->assertDontSee(__('billing::portal.return.title'));
            $this->shot($browser, 'Return-Seite: Aktivierung hängt');

            $billable->refresh();
            $paymentId = (string) $this->latestFakePayment()['id'];
            $this->assertSame('mx-free', $billable->subscription_plan_code, 'still on the free plan: no paid plan nobody pays for');
            $this->assertSame(SubscriptionSource::Local, $billable->subscription_source);
            $this->assertNotNull($billable->mollie_mandate_id, 'the mandate was captured');
            $this->assertArrayHasKey($paymentId, (array) ($billable->getBillingSubscriptionMeta()[\GraystackIT\MollieBilling\Support\ActivationRetry::META_KEY] ?? []));
            $this->assertSame(0, DB::table('fake_mollie_subscriptions')->count());

            // Mollie is back, and the retry runs.
            \Workbench\App\Support\WorkbenchSetting::set('fail_create_subscription', '0');
            app()->call([new \GraystackIT\MollieBilling\Jobs\RetrySubscriptionActivationJob($paymentId, $billable->getMorphClass(), $billable->getKey()), 'handle']);

            $browser->waitForText(__('billing::portal.return.title'), 30);
            $this->shot($browser, 'Return-Seite: nach der Wiederholung aktiviert');
        });

        $billable->refresh();
        $this->assertSame('mx-team', $billable->subscription_plan_code);
        $this->assertSame(SubscriptionSource::Mollie, $billable->subscription_source);
        $this->assertSame(SubscriptionStatus::Active, $billable->subscription_status);
        $this->assertSame(3540, $this->cmSubscriptionMinor($billable));
        $this->assertSame(1, DB::table('fake_mollie_subscriptions')->count());
        $this->assertSame(1, CouponRedemption::query()->count());
        $this->assertSame(1, BillingInvoice::query()->count());
    }

    // ── Paid extras on a gifted plan ───────────────────────────────────────────

    /** @return array<string, array{0:string,1:string}> */
    public static function giftCases(): array
    {
        $cases = [];
        foreach (['addon', 'seats', 'addon-with-gifted-addon'] as $i => $extra) {
            foreach (self::VAT_CASES as $v => $vat) {
                // Every extra with AT and one rotating case; every VAT case at least once.
                if ($v === 0 || ($v + $i) % 5 === 1 || ($extra === 'addon')) {
                    $cases["gift × {$extra} × {$vat}"] = [$extra, $vat];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('giftCases')]
    public function test_extras_on_a_gifted_plan_charge_the_extras_and_keep_the_plan_free(string $extra, string $vatCase): void
    {
        $this->cmCatalog();
        $vat = self::cmVat($vatCase);
        $rate = $vat['rate'];
        $billable = $this->cmCustomer($vatCase);

        $granted = $extra === 'addon-with-gifted-addon' ? ['softdrinks'] : [];
        $coupon = $this->coupons()->accessGrantCoupon('GIFT-MX', 'mx-team', 'monthly', $granted, 90);
        $this->coupons()->redeem($coupon, $billable, []);
        $giftEnds = $billable->refresh()->getBillingSubscriptionEndsAt();

        // What is paid for, line by line; the plan (and a gifted addon) is shown and waived.
        [$paid, $waived] = match ($extra) {
            'addon' => [[490], 2950],
            'seats' => [[555], 2950],
            // The CRM: 10.00 base, and 2 plan seats − 1 free = 1 × 3.33.
            'addon-with-gifted-addon' => [[1000, 333], 2950 + 490],
        };
        $net = array_sum($paid);
        $what = "gift × {$extra} × {$vatCase}";

        $this->browse(function (Browser $browser) use ($billable, $extra, $vat): void {
            if ($extra === 'seats') {
                $this->actAs($browser, $billable, '/billing/seats');
                $browser->waitFor('[wire\\:click="increment"]', 15)->pause(400);
                $this->clickWire($browser, '[wire\\:click="increment"]');
                $browser->pause(900);
                $this->shot($browser, 'Geschenkter Plan: ein Sitz dazu');
                $this->clickWire($browser, '[wire\\:click="syncSeats"]');
            } else {
                $addon = $extra === 'addon' ? 'softdrinks' : 'mx-crm';
                $this->actAs($browser, $billable, '/billing/addons');
                $browser->waitForText('Softdrinks', 15)->pause(400);
                $this->shot($browser, 'Geschenkter Plan: Addon dazu');
                $this->confirmInModal($browser, 'enable-addon-'.$addon, '[wire\\:click="enableAddon(\''.$addon.'\')"]');
            }

            $this->settleAtFakeMollie($browser, 'paid', $vat['mollie']);
            $browser->waitForText(__('billing::portal.return.title'), 20);
        });

        $billable->refresh();
        $invoice = $this->cmNewestInvoice($billable);
        $items = collect($invoice->line_items);
        $charged = self::cmMinor($this->latestFakePayment()['amount_value']);

        // The payment, the invoice and the subscription are one number: the extras.
        $this->assertSame($net, (int) $invoice->amount_net, $what.': invoice net');
        $this->assertSame($charged, (int) $invoice->amount_gross, $what.': invoice gross == amount at Mollie');
        $this->assertNull($items->firstWhere('kind', 'adjustment'), $what.': adjustment line');
        $this->assertSame(-$waived, (int) $items->where('kind', 'coupon')->sum('amount_net'), $what.': the gift is shown and taken off');
        $this->assertLessThanOrEqual(1, abs($charged - $net - (int) round($net * $rate / 100)), $what.': VAT at the rate of the country');
        if ($rate === 0.0) {
            $this->assertSame($net, $charged, $what.': reverse charge — no VAT');
        }

        $this->assertSame(SubscriptionSource::Mollie, $billable->subscription_source, $what);
        $this->assertSame('mx-team', $billable->subscription_plan_code, $what);
        $this->assertSame($charged, $this->cmSubscriptionMinor($billable), $what.': amount of the Mollie subscription');

        $marker = app(PlanWaiver::class)->marker($billable);
        $this->assertNotNull($marker, $what.': the gift lives on as a waiver');
        $this->assertSame($giftEnds->toDateString(), \Carbon\Carbon::parse((string) $marker['valid_until'])->toDateString(), $what);

        $renewalPaymentId = $this->renewSubscription($billable);
        $renewal = BillingInvoice::query()->where('mollie_payment_id', $renewalPaymentId)->first();

        $this->assertNotNull($renewal, $what.': renewal invoice');
        $this->assertSame($net, (int) $renewal->amount_net, $what.': renewal net');
        $this->assertSame($charged, (int) $renewal->amount_gross, $what.': renewal gross');
        $this->assertNull(collect($renewal->line_items)->firstWhere('kind', 'adjustment'), $what.': adjustment line on the renewal');
    }
}
