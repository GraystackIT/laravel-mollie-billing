<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Laravel\Dusk\Browser;

/**
 * Every coupon type at the point of sale, and every way a code can be refused.
 *
 * `CouponCheckoutTest` covers the two happy paths that move money (a percentage off the
 * first payment, and 100 % riding the mandate-only flow). This one completes the matrix:
 * the types the checkout accepts and what the customer reads for each, the two types it
 * cannot accept, and the seven refusals — each with the *specific* reason, because
 * "Coupon could not be applied" for an expired code sends the customer to support instead
 * of to a working code.
 *
 * The assertions are deliberately about what is on screen. The service layer's arithmetic
 * is pinned in the feature suite; what cannot be checked there is whether the number the
 * customer agrees to is the number that gets charged.
 */
class CouponCatalogCheckoutTest extends BrowserTestCase
{
    private function walkToConfirm(Browser $browser, TestBillable $billable, string $plan = 'starter'): void
    {
        $this->actAs($browser, $billable, '/billing/checkout');

        $browser->waitForText('Starter', 10);
        $browser->click('[wire\\:click*="\''.$plan.'\'"]')->pause(600);
        $this->clickWire($browser, '[wire\\:click="next"]');

        $browser->waitForText('Softdrinks', 10);
        $this->clickWire($browser, '[wire\\:click="next"]');

        $browser->waitFor('[wire\\:click="submit"]', 10)->pause(400);
    }

    private function enterCode(Browser $browser, string $code): void
    {
        $browser->type('coupon_input', $code);
        $this->clickWire($browser, '[wire\\:click="applyCoupon"]');
        $browser->pause(600);
    }

    private function addressedBillable(): TestBillable
    {
        return $this->makeBillable([
            'billing_street' => 'Musterweg 12',
            'billing_postal_code' => '10115',
            'billing_city' => 'Berlin',
        ]);
    }

    /**
     * A recurring coupon discounts the first payment *and* every renewal, and the checkout
     * has to say which of the two the number on screen is — a customer who reads "50 % off"
     * and means "once" is a chargeback waiting to happen.
     */
    public function test_a_recurring_coupon_shows_the_discount_it_will_keep_applying(): void
    {
        $this->coupons()->create([
            'code' => 'ALWAYS25',
            'name' => 'A quarter off, every month',
            'type' => CouponType::Recurring,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 25,
            'max_redemptions_per_billable' => 6,
        ]);

        $billable = $this->addressedBillable();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->walkToConfirm($browser, $billable);

            $this->enterCode($browser, 'ALWAYS25');
            $browser->waitForText('ALWAYS25', 10);

            // 990 net, 25 % off is round(247.5) = 248, leaving 742 net and 883 gross at 19 %.
            $this->assertShownAmount($browser, 'confirm-subtotal', 990, 'subtotal before the code');
            $this->assertShownAmount($browser, 'confirm-discount', 248, 'the discount the code gives');
            $this->assertShownAmount($browser, 'confirm-net', 742, 'net after the code');
            $this->assertShownAmount($browser, 'confirm-total', 883, 'what the customer agrees to pay');
            $this->shot($browser, 'Recurring-Code: Rabatt und Endsumme sichtbar');

            $this->clickWire($browser, '[wire\\:click="submit"]');
            $this->settleAtFakeMollie($browser, 'paid');

            $browser->waitForText(__('billing::portal.return.title'), 15);
        });

        $billable->refresh();
        $invoice = BillingInvoice::query()->sole();

        // What was shown is what was booked.
        expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
            ->and((int) $invoice->amount_net)->toBe(742)
            ->and((int) $invoice->amount_gross)->toBe(883)
            ->and(collect($invoice->line_items)->firstWhere('kind', 'coupon'))->not->toBeNull()
            // And the marker that keeps the discount alive for the promised periods.
            ->and($billable->getBillingSubscriptionMeta()['active_recurring_coupon']['code'] ?? null)
            ->toBe('ALWAYS25');
    }

    /**
     * An access grant is not a discount — it activates a local subscription instead of
     * charging one. Nothing may be collected, and the customer must not be shown a payment
     * step that then does nothing.
     */
    public function test_an_access_grant_activates_without_taking_money(): void
    {
        $this->coupons()->create([
            'code' => 'PARTNER',
            'name' => 'Partner access',
            'type' => CouponType::AccessGrant,
            'grant_plan_code' => 'starter',
            'grant_interval' => 'monthly',
            'grant_duration_days' => 90,
        ]);

        $billable = $this->addressedBillable();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->walkToConfirm($browser, $billable);

            $this->enterCode($browser, 'PARTNER');
            $browser->waitForText('PARTNER', 10);
            $this->shot($browser, 'Access-Grant: Summe auf 0,00 €');

            $this->clickWire($browser, '[wire\\:click="submit"]');

            // No Mollie step at all — the grant is local.
            $this->waitForPathContains($browser, '/billing');
            $browser->waitForText('Starter', 10);
            $this->shot($browser, 'Grant aktiv, nichts abgebucht');
        });

        $billable->refresh();

        expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
            ->and($billable->subscription_source)->toBe(SubscriptionSource::Local)
            ->and(BillingInvoice::query()->count())->toBe(0)
            ->and(CouponRedemption::query()->count())->toBe(1);
    }

    /**
     * Credits and period extensions are real coupon types that simply have nothing to act
     * on at signup: there is no wallet to fill before the plan exists, and no period to
     * extend. Refusing them here with a type-specific message is the whole point — the
     * customer keeps the code and can redeem it from the portal a minute later.
     */
    public function test_a_credits_coupon_is_refused_at_signup_with_a_reason(): void
    {
        $this->coupons()->create([
            'code' => 'BONUS500',
            'name' => 'Extra credits',
            'type' => CouponType::Credits,
            'credits_payload' => ['Tokens' => 500],
        ]);

        $billable = $this->addressedBillable();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->walkToConfirm($browser, $billable);

            $this->enterCode($browser, 'BONUS500');
            $browser->waitForText(__('billing::checkout.coupon_type_not_allowed_in_context'), 10);
            $this->shot($browser, 'Credits-Code am Checkout: Typ hier nicht einlösbar');
        });

        // Not consumed — it is still worth something in the portal.
        expect(CouponRedemption::query()->count())->toBe(0);
    }

    public function test_a_period_extension_coupon_is_refused_at_signup_with_a_reason(): void
    {
        $this->coupons()->create([
            'code' => 'PLUS30',
            'name' => 'Thirty days on us',
            'type' => CouponType::PeriodExtension,
            'grant_duration_days' => 30,
        ]);

        $billable = $this->addressedBillable();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->walkToConfirm($browser, $billable);

            $this->enterCode($browser, 'PLUS30');
            $browser->waitForText(__('billing::checkout.coupon_type_not_allowed_in_context'), 10);
            $this->shot($browser, 'Period-Extension am Checkout: abgelehnt');
        });

        expect(CouponRedemption::query()->count())->toBe(0);
    }

    /**
     * The refusals, one screen each. Every one of these used to be reachable, and every one
     * of them has a distinct message — a customer told "expired" tries another code, a
     * customer told "could not be applied" writes to support.
     */
    public function test_every_refusal_names_its_own_reason(): void
    {
        $this->coupons()->create([
            'code' => 'SLEEPING',
            'name' => 'Deactivated',
            'type' => CouponType::SinglePayment,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 10,
            'active' => false,
        ]);

        $this->coupons()->create([
            'code' => 'LASTYEAR',
            'name' => 'Expired',
            'type' => CouponType::SinglePayment,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 10,
            'valid_until' => now()->subDay(),
        ]);

        $this->coupons()->create([
            'code' => 'NEXTYEAR',
            'name' => 'Not yet valid',
            'type' => CouponType::SinglePayment,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 10,
            'valid_from' => now()->addMonth(),
        ]);

        $exhausted = $this->coupons()->create([
            'code' => 'ALLGONE',
            'name' => 'Fully redeemed',
            'type' => CouponType::SinglePayment,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 10,
            'max_redemptions' => 1,
        ]);
        $exhausted->forceFill(['redemptions_count' => 1])->save();

        $this->coupons()->create([
            'code' => 'PROONLY',
            'name' => 'Pro plan only',
            'type' => CouponType::SinglePayment,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 10,
            'applicable_plans' => ['pro'],
        ]);

        $this->coupons()->create([
            'code' => 'BIGORDER',
            'name' => 'Minimum order',
            'type' => CouponType::SinglePayment,
            'discount_type' => DiscountType::Fixed,
            'discount_value' => 500,
            'minimum_order_amount_net' => 50000,
        ]);

        $this->coupons()->create([
            'code' => 'TOKENSONLY',
            'name' => 'Metered usage only',
            'type' => CouponType::SinglePayment,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 10,
            'applicable_usages' => ['Tokens'],
        ]);

        $billable = $this->addressedBillable();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->walkToConfirm($browser, $billable, 'starter');

            foreach ([
                'NOSUCHCODE' => 'coupon_not_found',
                'SLEEPING' => 'coupon_inactive',
                'LASTYEAR' => 'coupon_expired',
                'NEXTYEAR' => 'coupon_not_yet_valid',
                'ALLGONE' => 'coupon_exhausted',
                'PROONLY' => 'coupon_plan_mismatch',
                'BIGORDER' => 'coupon_min_order',
                // Set, and therefore a positive requirement no order here can satisfy — the
                // field used to be read nowhere at all, which made "softdrinks only" mean
                // "everything".
                'TOKENSONLY' => 'coupon_usage_mismatch',
            ] as $code => $translationKey) {
                $this->enterCode($browser, $code);
                $browser->waitForText(__('billing::checkout.'.$translationKey), 10);
            }

            $this->shot($browser, 'Letzte Ablehnung: Grund benannt');

            // And the checkout is still usable after eight refusals — the total is the
            // undiscounted one and submit works.
            $this->assertShownAmount($browser, 'confirm-total', 1178, 'undiscounted total after eight refusals');
            $this->clickWire($browser, '[wire\\:click="submit"]');
            $this->settleAtFakeMollie($browser, 'paid');
            $browser->waitForText(__('billing::portal.return.title'), 15);
        });

        $invoice = BillingInvoice::query()->sole();

        expect((int) $invoice->amount_gross)->toBe(1178)
            ->and(collect($invoice->line_items)->firstWhere('kind', 'coupon'))->toBeNull()
            ->and(CouponRedemption::query()->count())->toBe(0);
    }

    /**
     * Once a code is applied there is no second input to type into — by design. So the
     * scenario worth pinning is the one the customer actually walks: the applied code and
     * its discount are visible, removing it puts the full price back, and the same code can
     * then be re-applied to the same number.
     */
    public function test_an_applied_code_can_be_removed_and_reapplied_to_the_same_total(): void
    {
        $this->coupons()->create([
            'code' => 'FIRST10',
            'name' => 'Ten percent',
            'type' => CouponType::SinglePayment,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 10,
        ]);

        $billable = $this->addressedBillable();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->walkToConfirm($browser, $billable);

            $this->assertShownAmount($browser, 'confirm-total', 1178, 'full price before any code');

            $this->enterCode($browser, 'FIRST10');
            $browser->waitForText('FIRST10', 10);

            // 990 − 99 = 891 net → 1060 gross.
            $this->assertShownAmount($browser, 'confirm-discount', 99);
            $this->assertShownAmount($browser, 'confirm-total', 1060, 'discounted total');
            $this->shot($browser, 'Code angewendet: 10 % runter');

            // No second input while one is applied — the customer removes first.
            $browser->assertMissing('input[wire\:model="coupon_input"]');

            $this->clickWire($browser, '[wire\:click="removeCoupon"]');
            $browser->waitUntilMissingText('FIRST10', 10);
            $this->assertShownAmount($browser, 'confirm-total', 1178, 'full price restored on removal');
            $this->shot($browser, 'Code entfernt: Summe zurück auf Vollpreis');

            // And back again, to the same number — removal must not consume the code.
            $this->enterCode($browser, 'FIRST10');
            $browser->waitForText('FIRST10', 10);
            $this->assertShownAmount($browser, 'confirm-total', 1060, 'same total on re-apply');

            $this->clickWire($browser, '[wire\:click="submit"]');
            $this->settleAtFakeMollie($browser, 'paid');
            $browser->waitForText(__('billing::portal.return.title'), 15);
        });

        $invoice = BillingInvoice::query()->sole();

        // Applied twice on screen, redeemed once.
        expect((int) $invoice->amount_gross)->toBe(1060)
            ->and(CouponRedemption::query()->count())->toBe(1);
    }
}
