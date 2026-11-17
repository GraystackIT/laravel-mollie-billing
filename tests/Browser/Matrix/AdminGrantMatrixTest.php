<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser\Matrix;

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\PlanWaiver;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Browser\BrowserTestCase;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Giving the product away from the admin panel, and what the tenant can then do with it.
 *
 * First the "issue grant" screen in each of its shapes; then the gifted tenant's own way:
 * buying seats and an addon on top (paying for those only, at the test Mollie), the
 * renewals that follow, the operator revoking the gift, and both endings a gift can have
 * — the subscription ends with it, or the plan becomes chargeable.
 *
 * Pro 29.00 (3 seats included, 9.90 per extra seat), Softdrinks 4.90, Priority Support
 * 9.90, 19 % VAT.
 */
class AdminGrantMatrixTest extends BrowserTestCase
{
    use CouponWorkbench;

    private const FORM = 'form[wire\\:submit="issue"]';

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function grants(): array
    {
        return [
            'the plan, ending with the gift' => [[
                'state' => 'none', 'mode' => 'full', 'addons' => [], 'days' => 30, 'on_expiry' => 'end',
            ]],
            'the plan with an addon, continuing as a paid plan' => [[
                'state' => 'none', 'mode' => 'full', 'addons' => ['softdrinks'], 'days' => 60, 'on_expiry' => 'continue_paid',
            ]],
            'only an addon, onto a free plan' => [[
                'state' => 'free', 'mode' => 'addon', 'addons' => ['softdrinks'], 'days' => null, 'on_expiry' => null,
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $set
     */
    private function issueGrant(Browser $browser, TestBillable $billable, array $set): void
    {
        $browser->visit('/billing/admin/billables/'.$billable->getKey().'/grant')
            ->waitFor(self::FORM, 15)->pause(500);
        expect($browser->driver->getPageSource())->not->toContain('<flux:');

        $this->wireSet($browser, self::FORM, $set);
        $this->shot($browser, 'Grant ausstellen');

        $this->clickWire($browser, self::FORM.' button[type="submit"]');
        $browser->acceptDialog();
    }

    /**
     * @param  array<string, mixed>  $case
     */
    #[DataProvider('grants')]
    public function test_the_operator_issues_a_grant(array $case): void
    {
        $billable = $this->couponBillableIn($case['state']);

        $this->browse(function (Browser $browser) use ($billable, $case): void {
            $this->loginAdmin($browser);

            $this->issueGrant($browser, $billable, $case['mode'] === 'full'
                ? ['mode' => 'full', 'planCode' => 'pro', 'interval' => 'monthly', 'durationDays' => $case['days'], 'onExpiry' => $case['on_expiry'], 'addonCodes' => $case['addons']]
                : ['mode' => 'addon', 'addonCodes' => $case['addons']]);

            // Back on the tenant's page, with the grant listed.
            $browser->waitForText($billable->name, 15)->pause(600);
            $this->waitForPresence($browser, '[wire\\:click^="openRevoke("]');
            $this->assertNextChargeMatchesBooks($browser, $billable, 'after issuing the grant');
            $this->shot($browser, 'Grant ausgestellt');
        });

        $billable = $billable->fresh();
        $coupon = Coupon::query()->where('code', 'like', 'GRANT-%')->sole();

        // Made for this tenant: it works once.
        expect((int) $coupon->max_redemptions)->toBe(1)
            ->and($coupon->redemptions_count)->toBe(1)
            ->and($billable->getActiveBillingAddonCodes())->toBe($case['addons'])
            ->and($billable->getBillingSubscriptionSource())->toBe(SubscriptionSource::Local->value);

        if ($case['mode'] === 'full') {
            expect($billable->getBillingSubscriptionPlanCode())->toBe('pro')
                ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
                ->and($billable->getBillingSubscriptionEndsAt()->toDateString())->toBe(now()->addDays($case['days'])->toDateString())
                ->and($coupon->grant_on_expiry)->toBe($case['on_expiry']);
        } else {
            expect($billable->getBillingSubscriptionPlanCode())->toBe('coupon-free')
                ->and($billable->getBillingSubscriptionEndsAt())->toBeNull()
                ->and($coupon->grant_plan_code)->toBeNull();
        }

        // What the tenant sees.
        $this->browse(function (Browser $browser) use ($billable, $case): void {
            $this->actAs($browser, $billable, '/billing');
            $browser->waitForText($case['mode'] === 'full' ? 'Pro' : 'Free Forever', 15)->pause(400);
            $this->assertNextChargeMatchesBooks($browser, $billable, 'tenant dashboard after the grant');
            $this->shot($browser, 'Dashboard des beschenkten Mandanten');
        });
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function refusedGrants(): array
    {
        return [
            'the plan, for a tenant who pays for one' => ['active', 'full', 'billing::admin.grants.error_conflicts_mollie'],
            'only an addon, for a tenant who pays' => ['active', 'addon', 'billing::admin.grants.error_conflicts_mollie'],
            'only an addon, for a tenant without a subscription' => ['none', 'addon', 'billing::admin.grants.error_requires_local'],
            'another plan than the free one the tenant is on' => ['free', 'full', 'billing::admin.grants.error_mismatch'],
        ];
    }

    #[DataProvider('refusedGrants')]
    public function test_a_grant_that_cannot_apply_is_refused_and_leaves_no_code(string $state, string $mode, string $message): void
    {
        $billable = $this->couponBillableIn($state);
        $before = [$billable->getBillingSubscriptionSource(), $billable->getBillingSubscriptionPlanCode(), $billable->getActiveBillingAddonCodes()];

        $this->browse(function (Browser $browser) use ($billable, $mode, $message): void {
            $this->loginAdmin($browser);

            $this->issueGrant($browser, $billable, $mode === 'full'
                ? ['mode' => 'full', 'planCode' => 'pro', 'interval' => 'monthly', 'durationDays' => 30]
                : ['mode' => 'addon', 'addonCodes' => ['softdrinks']]);

            $browser->waitForText(__($message), 15);
            $this->shot($browser, 'Grant abgelehnt');
        });

        $billable = $billable->fresh();

        expect(Coupon::query()->count())->toBe(0)
            ->and(CouponRedemption::query()->count())->toBe(0)
            ->and([$billable->getBillingSubscriptionSource(), $billable->getBillingSubscriptionPlanCode(), $billable->getActiveBillingAddonCodes()])->toBe($before);
    }

    /** A tenant on a gift of Pro, given through the service the admin screen calls. */
    private function giftedTenant(int $days, string $onExpiry, array $grantedAddons = []): TestBillable
    {
        $billable = $this->couponBillableIn('none');
        $coupon = $this->coupons()->accessGrantCoupon('GRANT-MX'.strtoupper(substr(uniqid(), -5)), 'pro', 'monthly', $grantedAddons, $days, $onExpiry, 1);
        $this->coupons()->redeem($coupon, $billable, []);

        return $billable->fresh();
    }

    /**
     * The gifted tenant buys two extra seats: pays for the seats only, the plan stays
     * free at the renewal, and the operator's revoke makes the plan chargeable.
     */
    public function test_a_gifted_tenant_buys_seats_then_the_gift_is_revoked(): void
    {
        $billable = $this->giftedTenant(90, PlanWaiver::ON_EXPIRY_END);
        $gift = CouponRedemption::query()->sole();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/seats');
            $browser->waitFor('[wire\\:click="increment"]', 15)->pause(400);

            $this->clickWire($browser, '[wire\\:click="increment"]');
            $browser->pause(700);
            $this->clickWire($browser, '[wire\\:click="increment"]');
            $browser->pause(700);

            // Two seats at 9.90: 19.80 net, 23.56 gross — that, and not the plan, is quoted
            // (the page quotes what leaves the account).
            $this->assertShownAmount($browser, 'seats-extra-cost', 2356, 'extra seats quoted to a gifted tenant');
            $this->shot($browser, 'Geschenkter Plan: zwei Sitze dazu');

            $this->clickWire($browser, '[wire\\:click="syncSeats"]');
            $this->settleAtFakeMollie($browser, 'paid');
            $browser->waitForText(__('billing::portal.return.title'), 15);

            $browser->visit('/billing')->waitForText('Pro', 15)->pause(500);
            $this->assertNextChargeMatchesBooks($browser, $billable, 'gifted tenant with two paid seats');
            $this->shot($browser, 'Nächste Abbuchung: nur die Sitze');
        });

        $billable = TestBillable::query()->findOrFail($billable->getKey());
        $first = BillingInvoice::query()->oldest('id')->first();

        expect($billable->getBillingSubscriptionSource())->toBe('mollie')
            ->and($billable->getExtraBillingSeats())->toBe(2)
            ->and((int) $first->amount_net)->toBe(1980)
            ->and((int) $first->amount_gross)->toBe(2356)
            ->and($this->latestFakePayment()['amount_value'])->toBe('23.56')
            ->and($this->mollieSubscriptionAmount($billable))->toBe('23.56')
            ->and(app(PlanWaiver::class)->marker($billable))->not->toBeNull();

        // The renewal: the seats again, the plan shown and taken off.
        $this->renewSubscription($billable);
        $renewal = BillingInvoice::query()->latest('id')->first();
        $lines = collect($renewal->line_items);

        expect((int) $renewal->amount_net)->toBe(1980)
            ->and((int) $renewal->amount_gross)->toBe(2356)
            ->and((int) $lines->firstWhere('kind', 'coupon')['total_net'])->toBe(-2900)
            ->and($lines->firstWhere('kind', 'adjustment'))->toBeNull();

        // The operator takes the gift back.
        $this->browse(function (Browser $browser) use ($billable, $gift): void {
            $this->loginAdmin($browser);
            $this->openSubscriptionTab($browser, $billable);

            $this->clickWire($browser, '[wire\\:click="openRevoke('.$gift->id.')"]');
            $browser->waitFor('textarea[name="revokeNote"]', 10)->pause(400)
                ->type('revokeNote', 'Matrix: gift withdrawn')->pause(300);
            $this->clickWire($browser, 'form[wire\\:submit="revokeGrant"] button[type="submit"]');
            $browser->waitForText(__('billing::admin.billables.subscription.flash.grant_revoked', [
                'reason' => __('billing::admin.billables.subscription.revoke_reason.admin_revoke'),
            ]), 15)->pause(500);

            $this->assertNextChargeMatchesBooks($browser, $billable, 'after the gift was revoked');
            $this->shot($browser, 'Geschenk widerrufen: Plan wird berechnet');
        });

        // Plan 29.00 + seats 19.80 = 48.80 + 19 % = 58.07, and the tenant keeps the subscription.
        $billable = $billable->fresh();

        expect(app(PlanWaiver::class)->marker($billable))->toBeNull()
            ->and($this->mollieSubscriptionAmount($billable))->toBe('58.07')
            ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active);

        $this->renewSubscription($billable);
        $full = BillingInvoice::query()->latest('id')->first();

        expect((int) $full->amount_net)->toBe(4880)
            ->and((int) $full->amount_gross)->toBe(5807)
            ->and(collect($full->line_items)->firstWhere('kind', 'coupon'))->toBeNull();
    }

    /**
     * A gift that included an addon: the tenant buys a second addon and pays for that one
     * only — now and at the renewal.
     */
    public function test_an_addon_that_came_with_the_gift_stays_free_next_to_a_bought_one(): void
    {
        $billable = $this->giftedTenant(90, PlanWaiver::ON_EXPIRY_END, ['softdrinks']);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/addons');
            $browser->waitForText('Priority Support', 15)->pause(400);

            $this->confirmInModal($browser, 'enable-addon-priority-support', '[wire\\:click="enableAddon(\'priority-support\')"]');
            $this->settleAtFakeMollie($browser, 'paid');
            $browser->waitForText(__('billing::portal.return.title'), 15);

            $browser->visit('/billing')->waitForText('Pro', 15)->pause(500);
            $this->assertNextChargeMatchesBooks($browser, $billable, 'gift with a granted and a bought addon');
            $this->shot($browser, 'Geschenktes und gekauftes Addon');
        });

        $billable = TestBillable::query()->findOrFail($billable->getKey());
        $first = BillingInvoice::query()->oldest('id')->first();
        $marker = app(PlanWaiver::class)->marker($billable);

        // Priority Support 9.90 + 19 % = 11.78. Plan and Softdrinks are the gift.
        expect((int) $first->amount_net)->toBe(990)
            ->and((int) $first->amount_gross)->toBe(1178)
            ->and($this->latestFakePayment()['amount_value'])->toBe('11.78')
            ->and($this->mollieSubscriptionAmount($billable))->toBe('11.78')
            ->and($marker['addon_codes'])->toBe(['softdrinks'])
            ->and((int) $marker['base_amount_net'])->toBe(3390);

        $this->renewSubscription($billable);
        $renewal = BillingInvoice::query()->latest('id')->first();

        expect((int) $renewal->amount_net)->toBe(990)
            ->and((int) collect($renewal->line_items)->firstWhere('kind', 'coupon')['total_net'])->toBe(-3390)
            ->and(collect($renewal->line_items)->firstWhere('kind', 'adjustment'))->toBeNull();
    }

    /**
     * How a gift ends once extras were bought on top. `end`: the subscription is
     * cancelled with the last covered period, nothing is charged for the plan.
     * `continue_paid`: the plan is charged from the first renewal after the gift.
     */
    #[DataProvider('endings')]
    public function test_a_gift_with_extras_ends_the_way_it_was_given(string $onExpiry): void
    {
        // Twenty days: the gift does not reach the first renewal.
        $billable = $this->giftedTenant(20, $onExpiry);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/addons');
            $browser->waitForText('Softdrinks', 15)->pause(400);

            $this->confirmInModal($browser, 'enable-addon-softdrinks', '[wire\\:click="enableAddon(\'softdrinks\')"]');
            $this->settleAtFakeMollie($browser, 'paid');
            $browser->waitForText(__('billing::portal.return.title'), 15);

            $browser->visit('/billing')->waitForText('Pro', 15)->pause(500);
            $this->assertNextChargeMatchesBooks($browser, $billable, 'gift ending before the first renewal');
            $this->shot($browser, 'Geschenk endet vor der ersten Verlängerung');
        });

        $billable = TestBillable::query()->findOrFail($billable->getKey());

        // Either way the first payment was the addon only.
        expect((int) BillingInvoice::query()->oldest('id')->first()->amount_gross)->toBe(583);

        if ($onExpiry === PlanWaiver::ON_EXPIRY_END) {
            // Cancelled to the end of the period the addon was paid for; Mollie has no
            // subscription left that could charge the plan.
            expect($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Cancelled)
                ->and($billable->getBillingSubscriptionEndsAt()->toDateString())->toBe(Carbon::now()->addMonthNoOverflow()->toDateString())
                ->and(\Illuminate\Support\Facades\DB::table('fake_mollie_subscriptions')->where('status', 'active')->count())->toBe(0);

            return;
        }

        // Continues as a paid plan: Mollie already holds the full price for the renewal
        // that follows the gift. Plan 29.00 + Softdrinks 4.90 = 33.90 + 19 % = 40.34.
        expect($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
            ->and($this->mollieSubscriptionAmount($billable))->toBe('40.34');

        $this->renewSubscription($billable);
        $renewal = BillingInvoice::query()->latest('id')->first();

        expect((int) $renewal->amount_net)->toBe(3390)
            ->and((int) $renewal->amount_gross)->toBe(4034)
            ->and(collect($renewal->line_items)->firstWhere('kind', 'adjustment'))->toBeNull();
    }

    /** @return array<string, array{0: string}> */
    public static function endings(): array
    {
        return ['the subscription ends' => [PlanWaiver::ON_EXPIRY_END], 'the plan becomes chargeable' => [PlanWaiver::ON_EXPIRY_CONTINUE_PAID]];
    }
}
