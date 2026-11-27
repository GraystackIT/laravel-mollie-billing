<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser\Matrix;

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\AdminExtensionService;
use GraystackIT\MollieBilling\Services\Billing\PlanWaiver;
use GraystackIT\MollieBilling\Support\BillingAuditEntry;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ChargeDeferral;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Browser\BrowserTestCase;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The operator's subscription tab: every action in every state of a subscription in
 * which it is offered — and the proof that it is NOT offered in the others.
 *
 * Actions: extend the trial, extend by days, plan free for periods, extend the gift
 * (with what happens at its end), cancel at period end / at once (with a reason),
 * resubscribe, revoke a grant (with a reason). Each case asserts the flash the operator
 * reads, the books, what the test Mollie holds afterwards, and that the tab's "next
 * charge" block agrees with both.
 */
class AdminSubscriptionTabMatrixTest extends BrowserTestCase
{
    use CouponWorkbench;

    private const ACTIONS = [
        'extendTrial' => 'form[wire\\:submit="extendTrial"]',
        'extendSubscription' => 'form[wire\\:submit="extendSubscription"]',
        'giveFreePlanPeriods' => 'form[wire\\:submit="giveFreePlanPeriods"]',
        'extendGrant' => 'form[wire\\:submit="extendGrant"]',
        'cancelAtPeriodEnd' => '[wire\\:click="openCancel(false)"]',
        'cancelNow' => '[wire\\:click="openCancel(true)"]',
        'resubscribe' => '[wire\\:click="resubscribe"]',
    ];

    /** Which actions the tab offers in which state. */
    private const OFFERED = [
        'none' => [],
        'trial' => ['extendTrial', 'extendSubscription', 'giveFreePlanPeriods', 'cancelAtPeriodEnd', 'cancelNow'],
        'active' => ['extendSubscription', 'giveFreePlanPeriods', 'cancelAtPeriodEnd', 'cancelNow'],
        'free' => ['cancelAtPeriodEnd', 'cancelNow'],
        'gifted' => ['extendSubscription', 'giveFreePlanPeriods', 'extendGrant', 'cancelAtPeriodEnd', 'cancelNow'],
        'gift_with_extras' => ['extendSubscription', 'giveFreePlanPeriods', 'extendGrant', 'cancelAtPeriodEnd', 'cancelNow'],
        'past_due' => ['cancelAtPeriodEnd', 'cancelNow'],
        'cancelled' => ['resubscribe'],
    ];

    private function tabBillable(string $state): TestBillable
    {
        if ($state !== 'gift_with_extras') {
            return $this->couponBillableIn($state);
        }

        // A gift that lives on as a plan waiver: the tenant bought an addon on top.
        $billable = $this->makeActiveSubscriber('pro', 'monthly', ['active_addon_codes' => ['softdrinks']]);
        $coupon = $this->coupons()->accessGrantCoupon('SEED-KEPT-'.strtoupper(substr(uniqid(), -5)), 'pro', 'monthly', [], 90, PlanWaiver::ON_EXPIRY_END, 1);
        app(PlanWaiver::class)->grant($billable, $coupon, 'pro', 'monthly', [], now()->addDays(90), null, PlanWaiver::ON_EXPIRY_END);
        app(PlanWaiver::class)->syncMollie($billable->fresh());

        return $billable->fresh();
    }

    /** @return array<string, array{0: string}> */
    public static function states(): array
    {
        return array_combine(array_keys(self::OFFERED), array_map(fn (string $s): array => [$s], array_keys(self::OFFERED)));
    }

    #[DataProvider('states')]
    public function test_the_tab_offers_exactly_the_actions_that_can_work(string $state): void
    {
        $billable = $this->tabBillable($state);

        $this->browse(function (Browser $browser) use ($billable, $state): void {
            $this->loginAdmin($browser);
            $this->openSubscriptionTab($browser, $billable);
            expect($browser->driver->getPageSource())->not->toContain('<flux:');

            foreach (self::ACTIONS as $action => $selector) {
                expect($this->domCount($browser, $selector))->toBe(
                    in_array($action, self::OFFERED[$state], true) ? 1 : 0,
                    "{$action} on a {$state} subscription",
                );
            }

            $this->assertNextChargeMatchesBooks($browser, $billable, "subscription tab, {$state}");
            $this->shot($browser, "Abo-Tab: {$state}");
        });
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function actionInState(): array
    {
        $cases = [];

        foreach (self::OFFERED as $state => $actions) {
            foreach ($actions as $action) {
                $cases["{$action}, {$state}"] = [$action, $state];
            }
        }

        return $cases;
    }

    #[DataProvider('actionInState')]
    public function test_an_action_does_what_it_says(string $action, string $state): void
    {
        $billable = $this->tabBillable($state);

        $before = [
            'next_charge' => $billable->nextBillingDate(),
            'trial_ends' => $billable->getBillingTrialEndsAt(),
            'ends_at' => $billable->getBillingSubscriptionEndsAt(),
            'deferral' => ChargeDeferral::until($billable),
            'mollie_amount' => $this->mollieSubscriptionAmount($billable),
            'mollie_date' => $this->mollieNextPaymentDate($billable),
            'waiver_until' => app(PlanWaiver::class)->marker($billable)['valid_until'] ?? null,
        ];

        $trialUntil = Carbon::instance($before['trial_ends'] ?? now())->addDays(9)->startOfDay();

        $this->browse(function (Browser $browser) use ($billable, $action, $state, $trialUntil): void {
            $this->loginAdmin($browser);
            $this->openSubscriptionTab($browser, $billable);

            match ($action) {
                'extendTrial' => $this->submitForm($browser, 'extendTrial', ['trialUntil' => $trialUntil->format('Y-m-d')], [],
                    __('billing::admin.billables.subscription.flash.trial_extended', ['date' => \GraystackIT\MollieBilling\Support\BillingTime::formatUtc($trialUntil)])),
                'extendSubscription' => $this->submitForm($browser, 'extendSubscription', [], ['extendDays' => '14'],
                    __('billing::admin.billables.subscription.flash.extended_days', ['count' => 14])),
                'giveFreePlanPeriods' => $this->submitForm($browser, 'giveFreePlanPeriods', [], ['freePeriods' => '2'],
                    __('billing::admin.billables.subscription.flash.free_periods_given', ['count' => 2])),
                'extendGrant' => $this->submitForm($browser, 'extendGrant', ['grantOnExpiry' => 'continue_paid'], ['grantDays' => '30'],
                    __('billing::admin.billables.subscription.flash.grant_extended', ['count' => 30])),
                // Past due on an unpaid period: no access to run out, so the service turns
                // the cancel at period end into an immediate one, and the tab says so.
                'cancelAtPeriodEnd' => $this->cancelWithReason($browser, false, $state === 'past_due'),
                'cancelNow' => $this->cancelWithReason($browser, true),
                'resubscribe' => $this->resubscribe($browser),
            };

            $this->shot($browser, "{$action} @ {$state}");
            $this->assertNextChargeMatchesBooks($browser, $billable, "{$action} on a {$state} subscription");
        });

        $this->assertActionEffect($action, $state, $billable->fresh(), $before, $trialUntil);
    }

    /**
     * @param  array<string, mixed>  $set  Flux controls, set on the component
     * @param  array<string, string>  $type  plain inputs, typed into
     */
    private function submitForm(Browser $browser, string $form, array $set, array $type, string $flash): void
    {
        if ($set !== []) {
            $this->wireSet($browser, 'form[wire\\:submit="'.$form.'"]', $set);
        }

        foreach ($type as $model => $value) {
            $browser->type('input[wire\\:model="'.$model.'"]', $value)->pause(200);
        }

        $this->clickWire($browser, 'form[wire\\:submit="'.$form.'"] button[type="submit"]');
        $browser->waitForText($flash, 15)->pause(500);
    }

    private function cancelWithReason(Browser $browser, bool $immediately, bool $endsNow = false): void
    {
        $this->clickWire($browser, '[wire\\:click="openCancel('.($immediately ? 'true' : 'false').')"]');
        $browser->waitFor('textarea[name="cancelNote"]', 10)->pause(400);

        // Without a reason the modal refuses.
        $this->clickWire($browser, 'form[wire\\:submit="confirmCancel"] button[type="submit"]');
        $browser->waitForText(__('billing::admin.billables.subscription.flash.cancel_note_required'), 10);

        $browser->type('cancelNote', 'Matrix: asked for by the customer')->pause(300);
        $this->clickWire($browser, 'form[wire\\:submit="confirmCancel"] button[type="submit"]');
        $browser->waitForText(__('billing::admin.billables.subscription.flash.'.($immediately || $endsNow ? 'cancelled_now' : 'cancelled_grace')), 15)->pause(500);
    }

    private function resubscribe(Browser $browser): void
    {
        $this->clickWire($browser, '[wire\\:click="resubscribe"]');
        $browser->acceptDialog();
        $browser->waitForText(__('billing::admin.billables.subscription.flash.resubscribed'), 15)->pause(500);
    }

    /**
     * @param  array<string, mixed>  $before
     */
    private function assertActionEffect(string $action, string $state, TestBillable $billable, array $before, Carbon $trialUntil): void
    {
        $local = in_array($state, ['free', 'gifted'], true);

        switch ($action) {
            case 'extendTrial':
                expect($billable->getBillingTrialEndsAt()->toDateString())->toBe($trialUntil->toDateString())
                    ->and($this->mollieNextPaymentDate($billable))->toBe($trialUntil->toDateString())
                    ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Trial);
                break;

            case 'extendSubscription':
                expect(Coupon::query()->where('code', 'like', 'EXT-%')->count())->toBe(1)
                    ->and(CouponRedemption::query()->whereHas('coupon', fn ($q) => $q->where('code', 'like', 'EXT-%'))->sole()->grant_days_added)->toBe(14);

                if ($local) {
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
                        // A date-only PATCH: the amount is what it was.
                        ->and($this->mollieSubscriptionAmount($billable))->toBe($before['mollie_amount']);
                }
                break;

            case 'giveFreePlanPeriods':
                if ($local) {
                    expect($billable->getBillingSubscriptionEndsAt()->toDateString())
                        ->toBe(Carbon::instance($before['ends_at'])->addMonthsNoOverflow(2)->toDateString());
                    break;
                }

                $marker = app(PlanWaiver::class)->marker($billable);

                if ($state === 'gift_with_extras') {
                    // Appended to the gift: its end moves by two periods, the addon stays paid.
                    expect(Carbon::parse((string) $marker['valid_until'])->toDateString())
                        ->toBe(Carbon::parse((string) $before['waiver_until'])->addMonthsNoOverflow(2)->toDateString())
                        ->and($this->mollieSubscriptionAmount($billable))->toBe('5.83');
                    break;
                }

                // Plan only: two charges are skipped, Mollie keeps the full price behind the date.
                $expected = Carbon::instance($state === 'trial' ? $before['trial_ends'] : $before['next_charge'])->addMonthsNoOverflow(2);

                expect($marker['max_applications'])->toBe(2)
                    ->and(ChargeDeferral::until($billable)?->toDateString())->toBe($expected->toDateString())
                    ->and($this->mollieNextPaymentDate($billable))->toBe($expected->toDateString())
                    ->and($this->mollieSubscriptionAmount($billable))->toBe('34.51');
                break;

            case 'extendGrant':
                if ($local) {
                    expect($billable->getBillingSubscriptionEndsAt()->toDateString())
                        ->toBe(Carbon::instance($before['ends_at'])->addDays(30)->toDateString())
                        ->and($billable->getBillingSubscriptionMeta()['grant_on_expiry'])->toBe('continue_paid');
                    break;
                }

                $marker = app(PlanWaiver::class)->marker($billable);

                expect(Carbon::parse((string) $marker['valid_until'])->toDateString())
                    ->toBe(Carbon::parse((string) $before['waiver_until'])->addDays(30)->toDateString())
                    ->and($marker['on_expiry'])->toBe('continue_paid')
                    // Still the addon only: 4.90 + 19 %.
                    ->and($this->mollieSubscriptionAmount($billable))->toBe('5.83');
                break;

            case 'cancelAtPeriodEnd':
            case 'cancelNow':
                $row = BillingAuditEntry::model()::query()->where('event', 'subscription_cancelled')->latest('id')->first();

                expect($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Cancelled)
                    ->and($row?->properties['replace']['reason'] ?? null)->toBe('Matrix: asked for by the customer')
                    ->and((bool) ($row?->properties['replace']['immediately'] ?? null))->toBe($action === 'cancelNow' || $state === 'past_due');

                if ($action === 'cancelNow' || $state === 'past_due') {
                    // Past due: the period that was paid for is already over, so
                    // "at the period end" is now as well.
                    expect($billable->getBillingSubscriptionEndsAt()->isFuture())->toBeFalse();
                } else {
                    expect($billable->getBillingSubscriptionEndsAt()->isFuture())->toBeTrue();
                }

                if (! $local) {
                    $subscriptionId = (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'];
                    expect(DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->value('status'))->toBe('canceled');
                }
                break;

            case 'resubscribe':
                // A new Mollie subscription, first charge at the end of the period that was paid.
                expect($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
                    ->and($billable->getBillingSubscriptionEndsAt())->toBeNull()
                    ->and($this->mollieSubscriptionAmount($billable))->toBe('34.51')
                    ->and($this->mollieNextPaymentDate($billable))->toBe(Carbon::instance($before['ends_at'])->toDateString())
                    ->and(DB::table('fake_mollie_subscriptions')->count())->toBe(2);
                break;
        }
    }

    public function test_a_second_identical_extension_within_seconds_is_refused_on_screen(): void
    {
        $billable = $this->tabBillable('active');
        $before = $billable->nextBillingDate();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->loginAdmin($browser);
            $this->openSubscriptionTab($browser, $billable);

            $this->submitForm($browser, 'extendSubscription', [], ['extendDays' => '14'],
                __('billing::admin.billables.subscription.flash.extended_days', ['count' => 14]));

            // The double click: the same thing again, at once.
            $browser->type('input[wire\\:model="extendDays"]', '14')->pause(200);
            $this->clickWire($browser, 'form[wire\\:submit="extendSubscription"] button[type="submit"]');
            $browser->waitForText(__('billing::admin.billables.subscription.flash.extension_repeated'), 15);
            $this->shot($browser, 'Doppelte Verlängerung abgelehnt');
        });

        expect($billable->fresh()->nextBillingDate()->toDateString())->toBe(Carbon::instance($before)->addDays(14)->toDateString())
            ->and(CouponRedemption::query()->count())->toBe(1);
    }

    public function test_an_extension_that_cannot_work_is_refused_with_its_reason(): void
    {
        // Twenty hours before the charge: Mollie may already have created the payment.
        $billable = $this->makeActiveSubscriber('pro', 'monthly', [
            'subscription_period_starts_at' => now()->subMonthNoOverflow()->addHours(20),
        ]);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->loginAdmin($browser);
            $this->openSubscriptionTab($browser, $billable);

            // (A number below 1 never leaves the browser: the field has `min="1"`.)
            // 15, not the 14 the scenario before this one gives: the database is fresh
            // per test and billable ids repeat, the cache that holds the repeat guard is not.
            $browser->type('input[wire\\:model="extendDays"]', '15')->pause(200);
            $this->clickWire($browser, 'form[wire\\:submit="extendSubscription"] button[type="submit"]');
            $browser->waitForText(__('billing::admin.billables.subscription.flash.extension_refused', [
                'reason' => __('billing::admin.billables.subscription.extension_reason.too_close_to_charge'),
            ]), 15)->pause(300);
            $this->shot($browser, 'Verlängerung zu nah an der Abbuchung');
        });

        // Nothing left behind: no code anybody could redeem.
        expect(Coupon::query()->count())->toBe(0);
    }

    /**
     * Revoking, with the reason the audit trail keeps: free plan periods on a paying
     * subscription (Mollie returns to the full price), and a gift on a Local one (the
     * subscription ends).
     */
    #[DataProvider('revocable')]
    public function test_a_grant_is_revoked_with_a_reason(string $what): void
    {
        if ($what === 'free plan periods') {
            $billable = $this->makeActiveSubscriber('pro', 'monthly', ['active_addon_codes' => ['softdrinks']]);
            app(AdminExtensionService::class)->freePlanPeriods($billable, 3);
            expect($this->mollieSubscriptionAmount($billable))->toBe('5.83');
        } else {
            $billable = $this->couponBillableIn('gifted');
        }

        $redemption = CouponRedemption::query()->latest('id')->first();

        $this->browse(function (Browser $browser) use ($billable, $redemption, $what): void {
            $this->loginAdmin($browser);
            $this->openSubscriptionTab($browser, $billable);

            $this->clickWire($browser, '[wire\\:click="openRevoke('.$redemption->id.')"]');
            $browser->waitFor('textarea[name="revokeNote"]', 10)->pause(400);

            // Without a reason the prompt refuses — and says so inside the prompt, which
            // is where the operator is looking (the list of grants is at the bottom of
            // the page, the page's own error banner at the top).
            $this->clickWire($browser, 'form[wire\\:submit="revokeGrant"] button[type="submit"]');
            $browser->waitFor('form[wire\\:submit="revokeGrant"] [data-modal-error]', 10)
                ->assertSeeIn('form[wire\\:submit="revokeGrant"] [data-modal-error]', __('billing::admin.billables.subscription.flash.revoke_note_required'));

            $browser->type('revokeNote', 'Matrix: granted by mistake')->pause(300);
            $this->clickWire($browser, 'form[wire\\:submit="revokeGrant"] button[type="submit"]');
            $browser->waitForText(__('billing::admin.billables.subscription.flash.grant_revoked', [
                'reason' => __('billing::admin.billables.subscription.revoke_reason.admin_revoke'),
            ]), 15)->pause(500);
            $this->shot($browser, "Widerrufen: {$what}");

            // The row is gone from the list of what was given.
            expect($this->domCount($browser, '[wire\\:click="openRevoke('.$redemption->id.')"]'))->toBe(0);
            $this->assertNextChargeMatchesBooks($browser, $billable, "after revoking {$what}");
        });

        $billable = $billable->fresh();
        $redemption->refresh();

        expect($redemption->isRevoked())->toBeTrue()
            ->and($redemption->revoked_reason)->toBe('admin_revoke: Matrix: granted by mistake')
            // A one-off code is not put back on offer.
            ->and($redemption->coupon->fresh()->active)->toBeFalse();

        if ($what === 'free plan periods') {
            // Pro 29.00 + Softdrinks 4.90 = 33.90 + 19 % = 40.34.
            expect(app(PlanWaiver::class)->marker($billable))->toBeNull()
                ->and($this->mollieSubscriptionAmount($billable))->toBe('40.34');
        } else {
            expect($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Expired)
                ->and($billable->hasAccessibleBillingSubscription())->toBeFalse();
        }
    }

    /** @return array<string, array{0: string}> */
    public static function revocable(): array
    {
        return ['free plan periods' => ['free plan periods'], 'a gift' => ['a gift']];
    }

    public function test_a_period_extension_is_listed_but_cannot_be_revoked(): void
    {
        $billable = $this->tabBillable('active');
        app(AdminExtensionService::class)->extendByDays($billable, 14);
        $redemption = CouponRedemption::query()->sole();

        $this->browse(function (Browser $browser) use ($billable, $redemption): void {
            $this->loginAdmin($browser);
            $this->openSubscriptionTab($browser, $billable);

            $browser->assertSee(__('billing::admin.billables.subscription.mode_period_extension'));
            expect($this->domCount($browser, '[wire\\:click="openRevoke('.$redemption->id.')"]'))->toBe(0);
        });
    }
}
