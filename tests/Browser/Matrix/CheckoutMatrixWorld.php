<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser\Matrix;

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingVatValidation;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Workbench\App\Support\WorkbenchSetting;

/**
 * The world of the checkout matrix: its own catalog entries, its VAT situations, its
 * coupon states, and the one chain every booking has to hold in a real browser —
 *
 *   amount on the confirm step == amount at the (fake) Mollie == invoice
 *                              == amount of the Mollie subscription == first renewal.
 *
 * The catalog entries are added per test through the cross-process config channel
 * (`config.<key>` rows read by WorkbenchServiceProvider), never by editing the shared
 * workbench catalog other suites count and click through.
 */
trait CheckoutMatrixWorld
{
    /** Change a config value for the test process AND the Dusk server. */
    protected function cmConfig(string $key, mixed $value): void
    {
        WorkbenchSetting::set('config.'.$key, json_encode($value));
        config([$key => $value]);
    }

    /**
     * The next visitor comes from a public IP that geolocates to `$country` (null: to
     * nowhere). A fresh address each time, because the lookup is cached per IP for a day.
     */
    protected function cmVisitorFrom(?string $country): void
    {
        static $n = 0;

        $this->setIpCountry($country);
        WorkbenchSetting::set('client_ip', '8.8.'.random_int(1, 250).'.'.(++$n));
    }

    /**
     * Plans and addons the matrix books. Prices are chosen so that VAT per line and VAT
     * on the summed net differ by a cent at 19 % and 20 % once two lines are involved.
     */
    protected function cmCatalog(): void
    {
        $this->cmConfig('mollie-billing-plans.features.mx-reports', ['name' => 'Matrix Reports', 'description' => 'Shown on the plan card.']);
        $this->cmConfig('mollie-billing-plans.features.mx-internal', ['name' => 'Matrix Internal Flag', 'description' => 'Never shown.', 'visible' => false]);

        $this->cmConfig('mollie-billing-plans.addons.mx-crm', [
            'name' => 'Matrix CRM',
            'feature_keys' => ['mx-reports'],
            'included_seats' => 1,
            'intervals' => [
                'monthly' => ['price_net' => 1000, 'seat_price_net' => 333],
                'yearly' => ['price_net' => 10000, 'seat_price_net' => 3333],
            ],
        ]);

        $this->cmConfig('mollie-billing-plans.plans.mx-team', [
            'name' => 'Matrix Team',
            'description' => 'Seats, addons and a per-seat addon.',
            'tier' => 5,
            'included_seats' => 2,
            'feature_keys' => ['mx-reports', 'mx-internal'],
            'allowed_addons' => ['softdrinks', 'mx-crm'],
            'intervals' => [
                'monthly' => ['base_price_net' => 2950, 'seat_price_net' => 555, 'included_usages' => ['Tokens' => 10], 'usage_overage_prices' => ['Tokens' => 7]],
                'yearly' => ['base_price_net' => 29950, 'seat_price_net' => 5555, 'included_usages' => ['Tokens' => 120], 'usage_overage_prices' => ['Tokens' => 7]],
            ],
        ]);

        $this->cmConfig('mollie-billing-plans.plans.mx-trial', [
            'name' => 'Matrix Trial',
            'description' => 'Seven days free.',
            'tier' => 6,
            'included_seats' => 1,
            'feature_keys' => ['mx-reports'],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => ['base_price_net' => 1950, 'seat_price_net' => null, 'trial_days' => 7, 'included_usages' => []],
            ],
        ]);

        $this->cmConfig('mollie-billing-plans.plans.mx-free', [
            'name' => 'Matrix Free',
            'description' => 'Costs nothing.',
            'tier' => 0,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []],
            ],
        ]);
    }

    /** @return array{country:string, rate:float, vat:?string, mollie:string} */
    protected static function cmVat(string $case): array
    {
        return match ($case) {
            'AT' => ['country' => 'AT', 'rate' => 20.0, 'vat' => null, 'mollie' => 'AT'],
            'DE' => ['country' => 'DE', 'rate' => 19.0, 'vat' => null, 'mollie' => 'DE'],
            // VIES-valid German business, Austrian seller: reverse charge.
            'RC' => ['country' => 'DE', 'rate' => 0.0, 'vat' => 'DE129273398', 'mollie' => 'DE'],
            // VIES-valid Austrian business, Austrian seller: domestic, VAT is charged.
            'DOM' => ['country' => 'AT', 'rate' => 20.0, 'vat' => 'ATU69934418', 'mollie' => 'AT'],
            // Sold to through `additional_countries`.
            'ADD' => ['country' => 'CH', 'rate' => 8.1, 'vat' => null, 'mollie' => 'CH'],
        };
    }

    /**
     * A customer with a complete address in one of the five VAT situations, and no
     * subscription.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function cmCustomer(string $vatCase, array $overrides = []): TestBillable
    {
        $vat = self::cmVat($vatCase);

        if ($vatCase === 'ADD') {
            $this->cmConfig('mollie-billing.additional_countries', ['CH' => ['vat_rate' => 8.1, 'name' => 'Switzerland']]);
        }

        // Payment and IP agree with the declared country, so no mismatch is flagged.
        $this->setIpCountry($vat['country']);

        $billable = $this->makeBillable(array_merge([
            'billing_street' => 'Musterweg 12',
            'billing_postal_code' => '10115',
            'billing_city' => 'Berlin',
            'billing_country' => $vat['country'],
            'tax_country_user' => $vat['country'],
            'tax_country_ip' => $vat['country'],
            'vat_number' => $vat['vat'],
        ], $overrides));

        if ($vat['vat'] !== null) {
            BillingVatValidation::query()->create([
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => $billable->getKey(),
                'vat_number' => $vat['vat'],
                'country_code' => substr($vat['vat'], 0, 2),
                'valid' => true,
                'vies_response' => [],
                'checked_at' => now(),
            ]);
        }

        return $billable->refresh();
    }

    /** A customer whose earlier subscription is over: mandate on file, no trial again. */
    protected function cmReturningCustomer(string $vatCase, SubscriptionStatus $status = SubscriptionStatus::Expired): TestBillable
    {
        $vat = self::cmVat($vatCase);
        $billable = $this->makeActiveSubscriber('starter', 'monthly', [
            'billing_country' => $vat['country'],
            'tax_country_user' => $vat['country'],
            'tax_country_payment' => $vat['country'],
            'tax_country_ip' => $vat['country'],
        ]);
        $id = $billable->getKey();
        $base = $this->cmCustomer($vatCase);
        // Keep the VAT fixture of cmCustomer(), on the subscriber that owns the mandate.
        BillingVatValidation::query()->where('billable_id', $base->getKey())->update(['billable_id' => $id]);
        $base->delete();

        $billable->forceFill([
            'vat_number' => $vat['vat'],
            'subscription_status' => $status,
            'subscription_plan_code' => $status === SubscriptionStatus::Expired ? null : 'starter',
            'subscription_interval' => SubscriptionInterval::Monthly,
            'subscription_ends_at' => now()->subDays(20),
            'subscription_period_starts_at' => now()->subDays(50),
            'subscription_meta' => $status === SubscriptionStatus::PastDue
                ? ['past_due_since' => now()->subDays(3)->toIso8601String()]
                : [],
        ])->save();

        DB::table('fake_mollie_subscriptions')->update(['status' => 'canceled', 'canceled_at' => now()]);

        return $billable->refresh();
    }

    // ── Arithmetic, independent of the package ─────────────────────────────────

    /** Gross for line nets with VAT rounded per line. A net of 0 is a gross of 0. */
    protected static function cmGross(array $lineNets, float $rate): int
    {
        if (array_sum($lineNets) === 0) {
            return 0;
        }

        return (int) array_sum(array_map(fn (int $n): int => $n + (int) round($n * $rate / 100), $lineNets));
    }

    protected static function cmDiscount(?array $coupon, int $net): int
    {
        if ($coupon === null) {
            return 0;
        }

        return $coupon['discount'] === 'percent'
            ? (int) round($net * $coupon['value'] / 100)
            : min($coupon['value'], $net);
    }

    // ── Coupons ────────────────────────────────────────────────────────────────

    /**
     * Create the coupon for a matrix coupon state.
     *
     * @return array{code:string, discount:string, value:int, recurring:bool, full:bool}|null
     *   null for states that discount nothing (none, refused codes)
     */
    protected function cmCoupon(string $state): ?array
    {
        $create = fn (array $attributes): Coupon => $this->coupons()->create(array_merge(
            ['code' => 'MX'.strtoupper(substr(md5($state), 0, 6)), 'name' => 'Matrix '.$state, 'active' => true],
            $attributes,
        ));

        $single = ['type' => CouponType::SinglePayment];
        $recurring = ['type' => CouponType::Recurring, 'max_redemptions_per_billable' => 1];

        [$coupon, $discount, $value] = match ($state) {
            'single-percent' => [$create($single + ['discount_type' => DiscountType::Percentage, 'discount_value' => 15]), 'percent', 15],
            'single-fixed' => [$create($single + ['discount_type' => DiscountType::Fixed, 'discount_value' => 555]), 'fixed', 555],
            'single-full' => [$create($single + ['discount_type' => DiscountType::Percentage, 'discount_value' => 100]), 'percent', 100],
            'recurring-percent' => [$create($recurring + ['discount_type' => DiscountType::Percentage, 'discount_value' => 15, 'discount_periods' => 3]), 'percent', 15],
            'recurring-fixed' => [$create($recurring + ['discount_type' => DiscountType::Fixed, 'discount_value' => 555, 'discount_periods' => 3]), 'fixed', 555],
            'recurring-date' => [$create($recurring + ['discount_type' => DiscountType::Percentage, 'discount_value' => 15, 'valid_until' => now()->addDays(100)]), 'percent', 15],
            'recurring-full' => [$create($recurring + ['discount_type' => DiscountType::Percentage, 'discount_value' => 100, 'discount_periods' => 3]), 'percent', 100],
            'recurring-full-date' => [$create($recurring + ['discount_type' => DiscountType::Percentage, 'discount_value' => 100, 'valid_until' => now()->addDays(100)]), 'percent', 100],
            'promo-link' => [$create($single + ['discount_type' => DiscountType::Percentage, 'discount_value' => 15, 'auto_apply_token' => 'mx-promo']), 'percent', 15],
            default => [null, '', 0],
        };

        if ($coupon === null) {
            return null;
        }

        return [
            'code' => (string) $coupon->code,
            'discount' => $discount,
            'value' => $value,
            'recurring' => str_starts_with($state, 'recurring'),
            'full' => $value === 100 && $discount === 'percent',
        ];
    }

    /**
     * A code the checkout has to refuse, and the sentence it has to refuse it with.
     *
     * @return array{code:string, message:string}
     */
    protected function cmRefusedCoupon(string $reason): array
    {
        $base = ['code' => 'NOPE', 'name' => 'Refused', 'active' => true, 'type' => CouponType::SinglePayment,
            'discount_type' => DiscountType::Percentage, 'discount_value' => 15];

        [$attributes, $key] = match ($reason) {
            'refused-expired' => [['valid_until' => now()->subDay()], 'coupon_expired'],
            'refused-inactive' => [['active' => false], 'coupon_inactive'],
            'refused-exhausted' => [['max_redemptions' => 1], 'coupon_exhausted'],
            'refused-plan' => [['applicable_plans' => ['business']], 'coupon_plan_mismatch'],
            'refused-interval' => [['applicable_intervals' => ['never']], 'coupon_interval_mismatch'],
            'refused-addon' => [['applicable_addons' => ['priority-support']], 'coupon_addon_mismatch'],
            'refused-product' => [['applicable_products' => ['token-pack-500']], 'coupon_product_mismatch'],
            'refused-minimum' => [['minimum_order_amount_net' => 9999999], 'coupon_min_order'],
        };

        $coupon = $this->coupons()->create(array_merge($base, $attributes));

        if ($reason === 'refused-exhausted') {
            $coupon->forceFill(['redemptions_count' => 1])->save();
        }

        return ['code' => 'NOPE', 'message' => __('billing::checkout.'.$key)];
    }

    // ── The checkout in the browser ────────────────────────────────────────────

    /**
     * Walk the checkout to its confirm step for a customer with an address on file.
     *
     * @param  list<string>  $addons
     */
    protected function cmWalkToConfirm(Browser $browser, TestBillable $billable, string $plan, string $interval = 'monthly', array $addons = [], int $extraSeats = 0): Browser
    {
        $this->actAs($browser, $billable, '/billing/checkout?plan='.$plan.'&interval='.$interval);

        // Billing step is locked (address on file): the plan step is open, plan preselected.
        $browser->waitFor('[wire\\:click="next"]', 15)->pause(500);
        $this->clickWire($browser, '[wire\\:click="next"]');
        $browser->pause(700);

        // The extras step only exists for a plan that sells addons or seats.
        if ($this->domCount($browser, '[wire\\:click="submit"]') === 0) {
            foreach ($addons as $addon) {
                $this->jsClick($browser, '[value="'.$addon.'"]');
            }

            if ($extraSeats > 0) {
                $browser->waitFor('input[type="number"]', 10);
                $browser->script('const i = document.querySelector(\'input[type="number"]\'); i.value = "'.$extraSeats.'"; i.dispatchEvent(new Event("input", {bubbles: true})); i.dispatchEvent(new Event("change", {bubbles: true}));');
                $browser->pause(900);
            }

            $this->clickWire($browser, '[wire\\:click="next"]');
        }

        return $browser->waitFor('[wire\\:click="submit"]', 15)->pause(500);
    }

    protected function cmApplyCoupon(Browser $browser, string $code): Browser
    {
        $browser->waitFor('[wire\\:click="applyCoupon"]', 15)->pause(300)->type('coupon_input', $code);
        $this->clickWire($browser, '[wire\\:click="applyCoupon"]');

        // Until the server has answered: the code is either applied (its remove button is
        // there) or refused (the field carries its reason). A fixed pause was too short
        // whenever the machine was busy.
        $browser->waitUsing(20, 150, fn (): bool => (bool) $browser->script(
            'return !!document.querySelector(\'[wire\\\\:click="removeCoupon"]\')'
            .' || [...document.querySelectorAll("div.text-xs.text-red-600")].some(e => e.textContent.trim() !== "");'
        )[0]);

        return $browser->pause(400);
    }

    protected function cmSubmitAndPay(Browser $browser, string $mollieCountry): Browser
    {
        $this->clickWire($browser, '[wire\\:click="submit"]');
        $this->settleAtFakeMollie($browser, 'paid', $mollieCountry);

        return $browser->waitForText(__('billing::portal.return.title'), 20);
    }

    // ── What the books say ─────────────────────────────────────────────────────

    protected static function cmMinor(string|float|int|null $value): int
    {
        return BillingMoney::minorFromMollieAmount((string) $value, 'EUR');
    }

    /** Amount the billable's Mollie subscription holds, in minor units. */
    protected function cmSubscriptionMinor(TestBillable $billable): ?int
    {
        $id = (string) ($billable->refresh()->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');
        $row = $id === '' ? null : DB::table('fake_mollie_subscriptions')->where('id', $id)->first();

        return $row === null ? null : self::cmMinor($row->amount_value);
    }

    protected function cmSubscriptionStartDate(TestBillable $billable): ?string
    {
        $id = (string) ($billable->refresh()->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');

        return $id === '' ? null : (string) DB::table('fake_mollie_subscriptions')->where('id', $id)->value('start_date');
    }

    /**
     * The invoice is its lines, taxed at one rate, with nothing reconciled into it.
     */
    protected function cmAssertInvoice(?BillingInvoice $invoice, int $net, int $gross, float $rate, string $what): void
    {
        expect($invoice)->not->toBeNull($what.': no invoice');

        $items = collect((array) $invoice->line_items);

        expect($items->firstWhere('kind', 'adjustment'))->toBeNull($what.': adjustment line on the invoice')
            ->and((int) $invoice->amount_net)->toBe($net, $what.': invoice net')
            ->and((int) $invoice->amount_gross)->toBe($gross, $what.': invoice gross')
            ->and((int) $invoice->amount_vat)->toBe($gross - $net, $what.': invoice VAT')
            ->and((int) $items->sum('amount_net'))->toBe($net, $what.': Σ line net')
            ->and((int) $items->sum('vat_amount'))->toBe($gross - $net, $what.': Σ line VAT');

        foreach ($items as $item) {
            expect(abs((float) $item['vat_rate'] - $rate))->toBeLessThan(0.0001, $what.': line VAT rate');
        }
    }

    protected function cmNewestInvoice(TestBillable $billable): ?BillingInvoice
    {
        return BillingInvoice::query()
            ->where('billable_id', $billable->getKey())
            ->where('invoice_kind', '!=', \GraystackIT\MollieBilling\Enums\InvoiceKind::Refund)
            ->orderByDesc('id')
            ->first();
    }
}
