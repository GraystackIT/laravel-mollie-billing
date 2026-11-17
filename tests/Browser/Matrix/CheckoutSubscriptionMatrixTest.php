<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser\Matrix;

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Tests\Browser\BrowserTestCase;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The checkout, in a real browser, for every booking path it serves crossed with every
 * coupon state and every VAT situation — all admissible pairs.
 *
 * Every case reads the total off the confirm step and holds it against what arrives at
 * the (fake) Mollie, the invoice the webhook writes, the amount of the Mollie
 * subscription it creates, and the invoice of the first renewal.
 */
class CheckoutSubscriptionMatrixTest extends BrowserTestCase
{
    use CheckoutMatrixWorld;

    /** Booking path => [plan, interval, addons, extra seats, line nets]. */
    private const PATHS = [
        'first' => ['starter', 'monthly', [], 0, [990]],
        'seats' => ['mx-team', 'monthly', [], 2, [2950, 1110]],
        'addon' => ['mx-team', 'monthly', ['softdrinks'], 0, [2950, 490]],
        // 3 seats in all; the CRM gives 1 away and charges 2 × 3.33.
        'per-seat-addon' => ['mx-team', 'monthly', ['mx-crm'], 1, [2950, 555, 1000, 666]],
        'yearly' => ['mx-team', 'yearly', ['softdrinks'], 0, [29950, 4900]],
        'rebook' => ['mx-trial', 'monthly', [], 0, [1950]],
        'trial' => ['mx-trial', 'monthly', [], 0, [1950]],
        'free' => ['mx-free', 'monthly', [], 0, [0]],
    ];

    private const DISCOUNTING = [
        'single-percent', 'single-fixed', 'single-full',
        'recurring-percent', 'recurring-fixed', 'recurring-date', 'recurring-full', 'recurring-full-date',
        'promo-link',
    ];

    private const REFUSED = [
        'refused-expired', 'refused-inactive', 'refused-exhausted', 'refused-plan', 'refused-interval',
        'refused-addon', 'refused-product', 'refused-minimum',
    ];

    private const VAT_CASES = ['AT', 'DE', 'RC', 'DOM', 'ADD'];

    /** Which coupon states a booking path admits. */
    private static function couponsFor(string $path): array
    {
        return match ($path) {
            // A free order has nothing to discount: a code is ignored, not refused.
            'free' => ['none', 'promo-link'],
            // A trial collects no first payment: a first-payment coupon is refused there.
            'trial' => ['none', 'recurring-percent', 'recurring-fixed', 'recurring-date', 'recurring-full', 'trial-extension', 'single-on-trial'],
            'first' => ['none', ...self::DISCOUNTING, ...self::REFUSED, 'stacked', 'grant-full'],
            'addon' => ['none', ...self::DISCOUNTING, ...self::REFUSED, 'stacked', 'grant-addons'],
            default => ['none', ...self::DISCOUNTING, ...self::REFUSED, 'stacked'],
        };
    }

    /**
     * Every admissible (path, coupon) pair once, with the VAT case rotated so that every
     * (path, VAT) and every (coupon, VAT) pair occurs as well; pairs the rotation misses
     * are added at the end.
     *
     * @return array<string, array{0:string,1:string,2:string}>
     */
    public static function cases(): array
    {
        $cases = [];
        $seen = ['pv' => [], 'cv' => []];
        $paths = array_keys(self::PATHS);

        foreach ($paths as $p => $path) {
            foreach (self::couponsFor($path) as $c => $coupon) {
                $vat = self::VAT_CASES[($p + $c) % count(self::VAT_CASES)];
                $cases["{$path} × {$coupon} × {$vat}"] = [$path, $coupon, $vat];
                $seen['pv'][$path.'|'.$vat] = true;
                $seen['cv'][$coupon.'|'.$vat] = true;
            }
        }

        foreach ($paths as $path) {
            foreach (self::couponsFor($path) as $coupon) {
                foreach (self::VAT_CASES as $vat) {
                    if (isset($seen['pv'][$path.'|'.$vat]) && isset($seen['cv'][$coupon.'|'.$vat])) {
                        continue;
                    }

                    $cases["{$path} × {$coupon} × {$vat}"] = [$path, $coupon, $vat];
                    $seen['pv'][$path.'|'.$vat] = true;
                    $seen['cv'][$coupon.'|'.$vat] = true;
                }
            }
        }

        return $cases;
    }

    public function test_the_matrix_covers_every_admissible_pair(): void
    {
        $cases = array_values(self::cases());
        $has = fn (int $a, string $x, int $b, string $y): bool => collect($cases)->contains(fn (array $case): bool => $case[$a] === $x && $case[$b] === $y);

        foreach (array_keys(self::PATHS) as $path) {
            foreach (self::couponsFor($path) as $coupon) {
                $this->assertTrue($has(0, $path, 1, $coupon), "missing pair {$path} × {$coupon}");

                foreach (self::VAT_CASES as $vat) {
                    $this->assertTrue($has(1, $coupon, 2, $vat), "missing pair {$coupon} × {$vat}");
                }
            }

            foreach (self::VAT_CASES as $vat) {
                $this->assertTrue($has(0, $path, 2, $vat), "missing pair {$path} × {$vat}");
            }
        }
    }

    #[DataProvider('cases')]
    public function test_shown_equals_charged_equals_invoiced_equals_subscription_equals_renewal(string $path, string $couponState, string $vatCase): void
    {
        [$plan, $interval, $addons, $extraSeats, $lines] = self::PATHS[$path];
        $vat = self::cmVat($vatCase);
        $rate = $vat['rate'];

        $this->cmCatalog();
        $billable = $path === 'rebook' ? $this->cmReturningCustomer($vatCase) : $this->cmCustomer($vatCase);

        // ── The coupon state ───────────────────────────────────────────────────
        $coupon = null;       // discounting coupon, if one applies
        $refused = null;      // code + sentence, if the checkout must refuse one
        $typed = null;        // what is typed into the field
        $second = null;       // a second code that must be refused (stacking)
        $grantDays = null;

        if (str_starts_with($couponState, 'refused-')) {
            $refused = $this->cmRefusedCoupon($couponState);
            $typed = $refused['code'];
        } elseif ($couponState === 'single-on-trial') {
            $refused = ['code' => $this->cmCoupon('single-percent')['code'], 'message' => __('billing::checkout.coupon_not_with_trial')];
            $typed = $refused['code'];
        } elseif ($couponState === 'stacked') {
            $coupon = $this->cmCoupon('single-percent');
            $typed = $coupon['code'];
            $this->coupons()->create(['code' => 'SECOND', 'name' => 'Second', 'type' => CouponType::SinglePayment, 'discount_type' => DiscountType::Fixed, 'discount_value' => 100, 'stackable' => true]);
            $second = 'SECOND';
        } elseif ($couponState === 'trial-extension') {
            $this->coupons()->create(['code' => 'PLUS9', 'name' => 'Nine more days', 'type' => CouponType::TrialExtension, 'trial_extension_days' => 9]);
            $typed = 'PLUS9';
        } elseif ($couponState === 'grant-full' || $couponState === 'grant-addons') {
            $this->coupons()->accessGrantCoupon('GIFT30', $plan, $interval, $addons, 30);
            $typed = 'GIFT30';
            $grantDays = 30;
        } elseif ($couponState !== 'none') {
            $coupon = $this->cmCoupon($couponState);
            $typed = $couponState === 'promo-link' ? null : $coupon['code'];
        }

        // ── What has to come out ───────────────────────────────────────────────
        $net = array_sum($lines);
        $discount = $path === 'free' ? 0 : self::cmDiscount($coupon, $net);
        $discounted = $discount > 0 ? [...$lines, -$discount] : $lines;

        $shownGross = $grantDays !== null ? 0 : self::cmGross($discounted, $rate);
        $fullGross = self::cmGross($lines, $rate);
        $isTrial = $path === 'trial';
        // The dated coupon runs for another 100 days: it still covers the next monthly
        // charge, but not the next yearly one — that subscription is created at full price.
        $endsBeforeNextCharge = $couponState === 'recurring-date' && $interval === 'yearly';
        $recurringDiscounted = $coupon !== null && $coupon['recurring'] && ! $coupon['full'] && ! $endsBeforeNextCharge;
        $deferred = $coupon !== null && $coupon['recurring'] && $coupon['full'];
        $subscriptionGross = $recurringDiscounted ? self::cmGross($discounted, $rate) : $fullGross;
        $subscriptionNet = $recurringDiscounted ? $net - $discount : $net;

        $this->browse(function (Browser $browser) use ($billable, $plan, $interval, $addons, $extraSeats, $couponState, $typed, $refused, $second, $shownGross, $fullGross, $vat, $path, $grantDays): void {
            if ($couponState === 'promo-link') {
                // The link first, in the customer's own session — then the checkout.
                $this->actAs($browser, $billable, '/promotion/mx-promo');
                $browser->pause(500);
                $browser->visit('/billing/checkout?plan='.$plan.'&interval='.$interval);
                $browser->waitFor('[wire\\:click="next"]', 15)->pause(500);
                $this->clickWire($browser, '[wire\\:click="next"]');
                $browser->pause(700);

                if ($this->domCount($browser, '[wire\\:click="submit"]') === 0) {
                    foreach ($addons as $addon) {
                        $this->jsClick($browser, '[value="'.$addon.'"]');
                    }
                    if ($extraSeats > 0) {
                        $browser->script('const i = document.querySelector(\'input[type="number"]\'); i.value = "'.$extraSeats.'"; i.dispatchEvent(new Event("input", {bubbles: true})); i.dispatchEvent(new Event("change", {bubbles: true}));');
                        $browser->pause(900);
                    }
                    $this->clickWire($browser, '[wire\\:click="next"]');
                }

                $browser->waitFor('[wire\\:click="submit"]', 15)->pause(500);
            } else {
                $this->cmWalkToConfirm($browser, $billable, $plan, $interval, $addons, $extraSeats);
            }

            if ($typed !== null) {
                $this->cmApplyCoupon($browser, $typed);
            }

            if ($refused !== null) {
                // Refused with its reason, and nothing came off the price.
                $browser->assertSee($refused['message']);
                $this->assertSame(0, $this->domCount($browser, '[dusk="confirm-discount"]'), 'a refused code shows no discount');
            }

            if ($second !== null) {
                // A subscription checkout takes one coupon: the field is gone once one is
                // applied, so a second code cannot be entered at all.
                $this->assertSame(0, $this->domCount($browser, 'input[name="coupon_input"]'), 'no second coupon field');
            }

            $this->assertShownAmount($browser, 'confirm-total', $shownGross, "{$path} × {$couponState}: total on the confirm step");
            $this->shot($browser, 'Zusammenfassung');

            if ($path === 'free' || $grantDays !== null) {
                // Nothing to pay: no trip to Mollie.
                $this->clickWire($browser, '[wire\\:click="submit"]');
                $this->waitForPathContains($browser, '/billing', 20);
                $browser->pause(800);

                return;
            }

            $this->cmSubmitAndPay($browser, $vat['mollie']);
        });

        $billable->refresh();
        $what = "{$path} × {$couponState} × {$vatCase}";

        // ── Free plan / gift: local, nothing charged ───────────────────────────
        if ($path === 'free' || $grantDays !== null) {
            $this->assertSame(0, \Illuminate\Support\Facades\DB::table('fake_mollie_payments')->where('created_at', '>=', $billable->created_at)->where('sequence_type', 'first')->count(), $what.': no payment at Mollie');
            $this->assertSame(SubscriptionSource::Local, $billable->subscription_source, $what);
            $this->assertSame(SubscriptionStatus::Active, $billable->subscription_status, $what);
            $this->assertSame($plan, $billable->subscription_plan_code, $what);
            $this->assertEqualsCanonicalizing($addons, $billable->getActiveBillingAddonCodes(), $what);
            $this->assertNull($this->cmNewestInvoice($billable), $what.': no invoice');

            if ($grantDays !== null) {
                $this->assertSame(now()->addDays($grantDays)->toDateString(), $billable->subscription_ends_at->toDateString(), $what.': the gift ends when it says');
                $this->assertSame(1, CouponRedemption::query()->count(), $what);
            } else {
                $this->assertNull($billable->subscription_ends_at, $what);
                $this->assertSame(0, CouponRedemption::query()->count(), $what.': a code on a free order is not spent');
            }

            return;
        }

        // ── The first payment ──────────────────────────────────────────────────
        $payment = $this->latestFakePayment();
        $firstGross = $isTrial ? 0 : $shownGross;
        $this->assertSame($firstGross, self::cmMinor($payment['amount_value']), $what.': amount at Mollie');

        if ($isTrial) {
            $this->assertSame(SubscriptionStatus::Trial, $billable->subscription_status, $what);
            $this->assertNull($this->cmNewestInvoice($billable), $what.': a trial books no invoice');

            $days = $couponState === 'trial-extension' ? 16 : 7;
            $this->assertSame(now()->addDays($days)->toDateString(), $billable->trial_ends_at->toDateString(), $what.': trial end');
        } else {
            $this->assertSame(SubscriptionStatus::Active, $billable->subscription_status, $what);
            $this->cmAssertInvoice($this->cmNewestInvoice($billable), $net - $discount, $firstGross, $rate, $what.' (first invoice)');
        }

        $this->assertSame(SubscriptionSource::Mollie, $billable->subscription_source, $what);
        $this->assertSame($plan, $billable->subscription_plan_code, $what);
        $this->assertSame($interval, $billable->getBillingSubscriptionInterval(), $what);
        $this->assertEqualsCanonicalizing($addons, $billable->getActiveBillingAddonCodes(), $what);
        $this->assertSame($extraSeats, $billable->getExtraBillingSeats(), $what);

        if ($refused !== null || $couponState === 'none') {
            $this->assertSame(0, CouponRedemption::query()->count(), $what.': nothing redeemed');
        }

        // ── The Mollie subscription ────────────────────────────────────────────
        $this->assertSame($subscriptionGross, $this->cmSubscriptionMinor($billable), $what.': amount of the Mollie subscription');

        if ($deferred) {
            // Mollie cannot hold 0: it holds the full price and starts charging after
            // the coupon. Forcing a charge now would not be a renewal Mollie makes.
            $this->assertGreaterThan(now()->addDays(60)->toDateString(), $this->cmSubscriptionStartDate($billable), $what.': first charge is deferred past the coupon');

            return;
        }

        // ── The first renewal (for a trial: the first charge) ──────────────────
        $before = BillingInvoice::query()->count();
        $renewalPaymentId = $this->renewSubscription($billable);
        $renewal = BillingInvoice::query()->where('mollie_payment_id', $renewalPaymentId)->first();

        $this->assertSame($before + 1, BillingInvoice::query()->count(), $what.': the renewal wrote one invoice');
        $this->cmAssertInvoice($renewal, $subscriptionNet, $subscriptionGross, $rate, $what.' (renewal)');
        $this->assertSame(SubscriptionStatus::Active, $billable->refresh()->subscription_status, $what.': active after the renewal');
    }
}
