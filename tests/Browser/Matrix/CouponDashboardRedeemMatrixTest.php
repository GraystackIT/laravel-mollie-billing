<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser\Matrix;

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\PlanWaiver;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ChargeDeferral;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Browser\BrowserTestCase;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The dashboard's one "redeem a code" field: every coupon type typed in by a customer in
 * every state their subscription can be in — 7 × 7.
 *
 * Each case asserts the sentence the customer reads (the success message, or the reason
 * the code does not work here), what the redemption did to the books, and that the
 * "next charge" block underneath shows what will really be collected: its state, its
 * amount and discount line against `NextChargeSummary`, and amount and date against the
 * subscription the test Mollie holds.
 *
 * Pro 29.00 monthly, 19 % VAT → 34.51.
 */
class CouponDashboardRedeemMatrixTest extends BrowserTestCase
{
    use CouponWorkbench;

    private const TYPES = ['single', 'recurring', 'credits', 'trial', 'grant', 'period', 'waiver'];

    /** @return array<string, array{0: string, 1: string}> */
    public static function typeByState(): array
    {
        $cases = [];

        foreach (self::TYPES as $type) {
            foreach (self::COUPON_STATES as $state) {
                $cases["{$type} code, {$state}"] = [$type, $state];
            }
        }

        return $cases;
    }

    private function seedCodes(): void
    {
        $coupons = $this->coupons();
        $discount = ['discount_type' => DiscountType::Percentage];

        $coupons->create(['code' => 'MX-SINGLE', 'type' => CouponType::SinglePayment, 'discount_value' => 10] + $discount);
        $coupons->create(['code' => 'MX-RECURRING', 'type' => CouponType::Recurring, 'discount_value' => 20, 'discount_periods' => 3] + $discount);
        $coupons->create(['code' => 'MX-CREDITS', 'type' => CouponType::Credits, 'credits_payload' => ['Tokens' => 100]]);
        $coupons->create(['code' => 'MX-TRIAL', 'type' => CouponType::TrialExtension, 'trial_extension_days' => 7]);
        $coupons->accessGrantCoupon('MX-GRANT', 'pro', 'monthly', [], 30);
        $coupons->create(['code' => 'MX-PERIOD', 'type' => CouponType::PeriodExtension, 'grant_duration_days' => 14]);
        $coupons->create(['code' => 'MX-WAIVER', 'type' => CouponType::PlanWaiver, 'waiver_periods' => 2]);
    }

    private function day(\DateTimeInterface $at, TestBillable $billable): string
    {
        return (string) BillingTime::display(Carbon::instance($at), $billable)?->translatedFormat('d. M Y');
    }

    /**
     * What the customer is told, for one cell of the matrix.
     *
     * @return array{0: bool, 1: string} [redeemed, the sentence on screen]
     */
    private function expectation(string $type, string $state, TestBillable $billable): array
    {
        $ok = fn (string $code): array => [true, __('billing::portal.coupon_redeem_success', ['code' => $code])];
        $no = fn (string $key, array $replace = []): array => [false, __($key, $replace)];
        $mollie = in_array($state, ['trial', 'active', 'past_due', 'cancelled'], true);

        return match ($type) {
            // A one-off discount has nothing to attach to on any of these.
            'single' => $no('billing::portal.coupon_single_payment_checkout'),

            'recurring' => in_array($state, ['trial', 'active'], true)
                ? [true, __('billing::portal.coupon_recurring_applied_periods', [
                    'code' => 'MX-RECURRING',
                    'from' => $this->day($state === 'trial' ? $billable->getBillingTrialEndsAt() : $billable->nextBillingDate(), $billable),
                    'count' => 3,
                ])]
                : $no('billing::portal.coupon_recurring_needs_paid_subscription'),

            'credits' => $ok('MX-CREDITS'),

            'trial' => $state === 'trial'
                ? $ok('MX-TRIAL')
                : $no('billing::portal.coupon_requires_running_trial'),

            'grant' => match (true) {
                $state === 'none' => [true, __('billing::portal.coupon_grant_activated', [
                    'code' => 'MX-GRANT', 'plan' => 'Pro', 'until' => $this->day(now()->addDays(30), $billable),
                ])],
                $state === 'gifted' => [true, __('billing::portal.coupon_grant_activated', [
                    'code' => 'MX-GRANT', 'plan' => 'Pro',
                    'until' => $this->day($billable->getBillingSubscriptionEndsAt()->copy()->addDays(30), $billable),
                ])],
                $state === 'free' => $no('billing::portal.coupon_grant_other_plan'),
                default => $no('billing::portal.coupon_grant_has_paid_subscription'),
            },

            'period' => match ($state) {
                'trial', 'active', 'gifted' => $ok('MX-PERIOD'),
                'past_due' => $no('billing::checkout.coupon_too_close_to_charge'),
                default => $no('billing::checkout.coupon_requires_active_subscription'),
            },

            'waiver' => match ($state) {
                'trial', 'active', 'gifted', 'cancelled' => $ok('MX-WAIVER'),
                default => $no('billing::checkout.coupon_requires_active_subscription'),
            },
        };
    }

    #[DataProvider('typeByState')]
    public function test_a_code_typed_into_the_dashboard(string $type, string $state): void
    {
        $this->seedCodes();
        $billable = $this->couponBillableIn($state);
        $code = 'MX-'.strtoupper($type);

        [$redeemed, $sentence] = $this->expectation($type, $state, $billable);

        $before = [
            'next_charge' => $billable->nextBillingDate(),
            'trial_ends' => $billable->getBillingTrialEndsAt(),
            'ends_at' => $billable->getBillingSubscriptionEndsAt(),
            'mollie_amount' => $this->mollieSubscriptionAmount($billable),
            'mollie_date' => $this->mollieNextPaymentDate($billable),
            'tokens' => (int) ($billable->getWallet('Tokens')?->balanceInt ?? 0),
        ];

        $this->browse(function (Browser $browser) use ($billable, $code, $sentence, $type, $state): void {
            $this->actAs($browser, $billable, '/billing');
            $this->redeemOnDashboard($browser, $code);

            $browser->waitForText($sentence, 15)->pause(400);
            $this->shot($browser, "{$type} @ {$state}");

            // Whatever happened, the block underneath says what is collected next.
            $this->assertNextChargeMatchesBooks($browser, $billable, "{$type} code on a {$state} subscription");
        });

        $billable = $billable->fresh();

        expect(CouponRedemption::query()->count())->toBe(
            ($state === 'gifted' ? 1 : 0) + ($redeemed ? 1 : 0),
            'redemption rows after the code was typed in',
        );

        if (! $redeemed) {
            // A refusal is a refusal: nothing moved, here or at Mollie.
            expect($billable->nextBillingDate()?->toIso8601String())->toBe($before['next_charge']?->toIso8601String())
                ->and($billable->getBillingTrialEndsAt()?->toIso8601String())->toBe($before['trial_ends']?->toIso8601String())
                ->and($billable->getBillingSubscriptionEndsAt()?->toIso8601String())->toBe($before['ends_at']?->toIso8601String())
                ->and($this->mollieSubscriptionAmount($billable))->toBe($before['mollie_amount'])
                ->and($this->mollieNextPaymentDate($billable))->toBe($before['mollie_date'])
                ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('active_recurring_coupon');

            return;
        }

        $this->assertEffect($type, $state, $billable, $before);
    }

    /**
     * What a redeemed code did — in the books and at the test Mollie.
     *
     * @param  array<string, mixed>  $before
     */
    private function assertEffect(string $type, string $state, TestBillable $billable, array $before): void
    {
        $mollie = in_array($state, ['trial', 'active', 'cancelled'], true);

        switch ($type) {
            case 'credits':
                expect((int) $billable->getWallet('Tokens')->balanceInt)->toBe($before['tokens'] + 100);
                break;

            case 'trial':
                $expected = Carbon::instance($before['trial_ends'])->addDays(7);

                expect($billable->getBillingTrialEndsAt()->toDateString())->toBe($expected->toDateString())
                    ->and($this->mollieNextPaymentDate($billable))->toBe($expected->toDateString());
                break;

            case 'recurring':
                // 29.00 − 20 % = 23.20 net → 27.61 gross, on the date it was on.
                expect($billable->getBillingSubscriptionMeta()['active_recurring_coupon']['code'] ?? null)->toBe('MX-RECURRING')
                    ->and($this->mollieSubscriptionAmount($billable))->toBe('27.61')
                    ->and($this->mollieNextPaymentDate($billable))->toBe($before['mollie_date']);
                break;

            case 'grant':
                expect($billable->getBillingSubscriptionSource())->toBe(SubscriptionSource::Local->value)
                    ->and($billable->getBillingSubscriptionPlanCode())->toBe('pro')
                    ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
                    ->and($billable->getBillingSubscriptionEndsAt()->toDateString())->toBe(
                        ($state === 'gifted' ? Carbon::instance($before['ends_at']) : now())->addDays(30)->toDateString(),
                    );
                break;

            case 'period':
                if ($state === 'gifted') {
                    expect($billable->getBillingSubscriptionEndsAt()->toDateString())
                        ->toBe(Carbon::instance($before['ends_at'])->addDays(14)->toDateString());
                } elseif ($state === 'trial') {
                    $expected = Carbon::instance($before['trial_ends'])->addDays(14);

                    expect($billable->getBillingTrialEndsAt()->toDateString())->toBe($expected->toDateString())
                        ->and($this->mollieNextPaymentDate($billable))->toBe($expected->toDateString());
                } else {
                    $expected = Carbon::instance($before['next_charge'])->addDays(14);

                    expect($billable->nextBillingDate()->toDateString())->toBe($expected->toDateString())
                        ->and($this->mollieNextPaymentDate($billable))->toBe($expected->toDateString())
                        ->and($this->mollieSubscriptionAmount($billable))->toBe($before['mollie_amount']);
                }
                break;

            case 'waiver':
                if ($state === 'gifted') {
                    // Local: free time, two whole periods.
                    expect($billable->getBillingSubscriptionEndsAt()->toDateString())
                        ->toBe(Carbon::instance($before['ends_at'])->addMonthsNoOverflow(2)->toDateString());
                    break;
                }

                $marker = app(PlanWaiver::class)->marker($billable);
                expect($marker['max_applications'] ?? null)->toBe(2);

                if ($state === 'cancelled') {
                    // Mollie has cancelled the subscription: nothing to re-price until a resubscribe.
                    expect($this->mollieSubscriptionAmount($billable))->toBe($before['mollie_amount']);
                    break;
                }

                // Nothing but the plan is billed, so the charge is deferred by two whole
                // periods and Mollie keeps the full price behind that date.
                $from = $state === 'trial' ? $before['trial_ends'] : $before['next_charge'];
                $expected = Carbon::instance($from)->addMonthsNoOverflow(2);

                expect(ChargeDeferral::until($billable)?->toDateString())->toBe($expected->toDateString())
                    ->and($this->mollieNextPaymentDate($billable))->toBe($expected->toDateString())
                    ->and($this->mollieSubscriptionAmount($billable))->toBe(number_format(2900 * 1.19 / 100, 2, '.', ''));
                break;
        }

        expect($mollie || in_array($state, ['none', 'free', 'gifted', 'past_due'], true))->toBeTrue();
    }

    /**
     * A recurring discount on a subscription with a paid addon, followed through its first
     * renewal: the amount on the dashboard is the amount Mollie collects is the amount on
     * the invoice.
     */
    public function test_the_discount_shown_after_redeeming_is_what_the_next_renewal_books(): void
    {
        $this->seedCodes();
        $billable = $this->makeActiveSubscriber('pro', 'monthly', ['active_addon_codes' => ['softdrinks']]);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing');
            $this->redeemOnDashboard($browser, 'MX-RECURRING');
            $browser->waitForText('MX-RECURRING', 15)->pause(500);

            // Pro 29.00 + Softdrinks 4.90 = 33.90, − 20 % = 27.12 net → 32.27 gross.
            $shown = $this->nextChargeOnScreen($browser);
            $strip = static fn (string $v): string => str_replace([' ', "\u{00a0}", "\u{202f}"], '', $v);

            expect($shown['state'])->toBe('scheduled')
                ->and($strip((string) $shown['gross']))->toBe($strip(BillingMoney::format(3227)))
                ->and($strip($shown['text']))->toContain($strip(BillingMoney::format(678)))
                ->and($shown['text'])->toContain(__('billing::portal.next_charge.discount', ['code' => 'MX-RECURRING']));
            $this->shot($browser, 'Nächste Abbuchung mit Rabattzeile');
        });

        expect($this->mollieSubscriptionAmount($billable))->toBe('32.27');

        $this->renewSubscription($billable->fresh());

        $invoice = \GraystackIT\MollieBilling\Models\BillingInvoice::query()->latest('id')->first();
        $lines = collect($invoice->line_items);

        expect((int) $invoice->amount_gross)->toBe(3227)
            ->and((int) $invoice->amount_net)->toBe(2712)
            ->and((int) $lines->firstWhere('kind', 'coupon')['total_net'])->toBe(-678)
            ->and($lines->firstWhere('kind', 'adjustment'))->toBeNull()
            ->and(CouponRedemption::query()->where('discount_amount_net', 678)->count())->toBe(1);
    }
}
