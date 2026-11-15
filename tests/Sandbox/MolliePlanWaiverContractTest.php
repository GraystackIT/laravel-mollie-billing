<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Sandbox;

use Carbon\Carbon;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\AdminExtensionService;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\CreateSubscription;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\PlanWaiver;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ChargeDeferral;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Http\Requests\GetSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * A plan waiver against the real API: does Mollie hold the amount and the next payment
 * date the package believes it holds, after every step that changes either?
 *
 * The feature suite asserts the requests the package builds. This asserts what Mollie
 * made of them — read back with `GetSubscriptionRequest` after each step and compared
 * with the local answer (`ChargeDeferral`, `nextBillingDate()`, the marker).
 *
 * Pro 50.00 (2 seats included, 5.00 per extra seat) + SSO 15.00, 20 % VAT:
 * plan only 60.00 · plan + SSO 78.00 · SSO alone 18.00.
 */
class MolliePlanWaiverContractTest extends SandboxTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('mollie-billing-plans.plans.pro', [
            'name' => 'Pro',
            'tier' => 2,
            'included_seats' => 2,
            'feature_keys' => [],
            'allowed_addons' => ['sso'],
            'intervals' => [
                'monthly' => ['base_price_net' => 5000, 'seat_price_net' => 500, 'included_usages' => []],
            ],
        ]);
        config()->set('mollie-billing-plans.addons.sso', [
            'name' => 'SSO',
            'feature_keys' => [],
            'intervals' => ['monthly' => ['price_net' => 1500]],
        ]);
    }

    /**
     * A billable ten days into a paid monthly period, without a Mollie subscription yet.
     *
     * @param  list<string>  $addons
     * @return array{0: TestBillable, 1: array{customerId: string, mandateId: string}, 2: Carbon}
     */
    private function billableMidPeriod(array $addons): array
    {
        $ids = $this->sandboxCustomerWithMandate('Sandbox Waiver Co');
        $periodEnd = Carbon::instance(BillingTime::addInterval(BillingTime::nowUtc()->subDays(10), 'monthly'));

        /** @var TestBillable $billable */
        $billable = TestBillable::create([
            'name' => 'Sandbox Waiver Co',
            'email' => 'sandbox-waiver-'.bin2hex(random_bytes(3)).'@example.test',
        ]);

        $billable->forceFill([
            'subscription_source' => SubscriptionSource::Mollie,
            'subscription_status' => SubscriptionStatus::Active,
            'subscription_plan_code' => 'pro',
            'subscription_interval' => SubscriptionInterval::Monthly,
            'subscription_period_starts_at' => BillingTime::subInterval($periodEnd, 'monthly'),
            'active_addon_codes' => $addons,
            'billing_country' => 'AT',
            'billing_street' => 'Teststrasse 1',
            'billing_postal_code' => '1010',
            'billing_city' => 'Wien',
            'mollie_customer_id' => $ids['customerId'],
            'mollie_mandate_id' => $ids['mandateId'],
            'subscription_meta' => ['seat_count' => 2],
        ])->save();

        return [$billable->refresh(), $ids, $periodEnd];
    }

    /**
     * Create the Mollie subscription the way a resubscribe or an upgrade does: priced
     * from whatever marker the billable carries, first charge at the end of the paid
     * period.
     *
     * @param  list<string>  $addons
     */
    private function createSubscription(TestBillable $billable, array $addons, Carbon $periodEnd, string $customerId): string
    {
        $net = SubscriptionAmount::net(app(SubscriptionCatalogInterface::class), $billable, 'pro', 'monthly', 2, $addons);

        app(CreateSubscription::class)->handle($billable, [
            'plan_code' => 'pro',
            'interval' => 'monthly',
            'addon_codes' => $addons,
            'extra_seats' => 0,
            'recurring_discount_net' => app(CouponService::class)->computeMarkerDiscount($billable, $net, [
                'plan_code' => 'pro',
                'interval' => 'monthly',
                'addon_codes' => $addons,
                'seats' => 2,
            ]),
            'paid_period_ends_at' => $periodEnd,
        ]);

        $billable->refresh();
        $subscriptionId = (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'];
        $this->trackMollieResource('subscription', $subscriptionId, $customerId);

        return $subscriptionId;
    }

    private function gift(TestBillable $billable, int $days, string $onExpiry = PlanWaiver::ON_EXPIRY_CONTINUE_PAID): void
    {
        $coupon = app(CouponService::class)->accessGrantCoupon(
            'SBXGIFT-'.strtoupper(bin2hex(random_bytes(3))), 'pro', 'monthly', [], $days, $onExpiry, 1,
        );

        app(PlanWaiver::class)->grant(
            $billable, $coupon, 'pro', 'monthly', [], BillingTime::nowUtc()->addDays($days), null, $onExpiry,
        );
    }

    private function mollieSubscription(string $customerId, string $subscriptionId): object
    {
        return Mollie::send(new GetSubscriptionRequest($customerId, $subscriptionId));
    }

    /** A PATCH that failed is queued, not thrown — so "nothing queued" is part of every assertion. */
    private function assertNothingQueued(TestBillable $billable): void
    {
        $pending = $billable->refresh()->getBillingSubscriptionMeta()['pending_subscription_patch'] ?? null;

        $this->assertNull($pending, 'A Mollie PATCH failed and was queued: '.json_encode($pending));
    }

    public function test_a_subscription_created_under_a_waiver_holds_the_extras_only_on_the_regular_date(): void
    {
        [$billable, $ids, $periodEnd] = $this->billableMidPeriod(['sso']);

        // The gift first, as on the real path: the subscription is priced from the marker.
        $this->gift($billable, 90);
        $subscriptionId = $this->createSubscription($billable->refresh(), ['sso'], $periodEnd, $ids['customerId']);

        $billable->refresh();
        $subscription = $this->mollieSubscription($ids['customerId'], $subscriptionId);

        $this->assertSame('18.00', $subscription->amount->value, 'The plan is waived: SSO 15.00 + 20 %.');
        $this->assertSame($periodEnd->toDateString(), (string) $subscription->startDate);
        $this->assertSame($periodEnd->toDateString(), (string) $subscription->nextPaymentDate);
        $this->assertSame($periodEnd->toDateString(), $billable->nextBillingDate()->toDateString());
        $this->assertNull(ChargeDeferral::until($billable), 'Something is billed, so nothing is deferred.');
    }

    public function test_sync_after_extending_and_after_removing_a_partly_covering_waiver(): void
    {
        [$billable, $ids, $periodEnd] = $this->billableMidPeriod(['sso']);
        $subscriptionId = $this->createSubscription($billable, ['sso'], $periodEnd, $ids['customerId']);

        $this->assertSame('78.00', $this->mollieSubscription($ids['customerId'], $subscriptionId)->amount->value);

        // Two free plan periods from the admin panel.
        $redemption = app(AdminExtensionService::class)->freePlanPeriods($billable->refresh(), 2);
        $this->assertNothingQueued($billable);

        $subscription = $this->mollieSubscription($ids['customerId'], $subscriptionId);
        $this->assertSame('18.00', $subscription->amount->value);
        $this->assertSame($periodEnd->toDateString(), (string) $subscription->nextPaymentDate, 'An amount change must not move the date.');

        // Extended by one more period: same amount, same date, a longer marker.
        app(PlanWaiver::class)->extendByPeriods($billable->refresh(), 1);
        app(PlanWaiver::class)->syncMollie($billable->refresh());

        $subscription = $this->mollieSubscription($ids['customerId'], $subscriptionId);
        $this->assertSame('18.00', $subscription->amount->value);
        $this->assertSame($periodEnd->toDateString(), (string) $subscription->nextPaymentDate);
        $this->assertSame(3, app(PlanWaiver::class)->marker($billable->refresh())['max_applications']);

        // Revoked: the plan is chargeable again, from the same regular date.
        app(CouponService::class)->revokeGrant($redemption, 'sandbox');
        $this->assertNothingQueued($billable);

        $subscription = $this->mollieSubscription($ids['customerId'], $subscriptionId);
        $this->assertSame('78.00', $subscription->amount->value);
        $this->assertSame($periodEnd->toDateString(), (string) $subscription->nextPaymentDate);
        $this->assertNull(app(PlanWaiver::class)->marker($billable->refresh()));
    }

    public function test_a_fully_covering_waiver_defers_the_charge_by_whole_periods_and_an_addon_brings_it_back(): void
    {
        [$billable, $ids, $periodEnd] = $this->billableMidPeriod([]);
        $subscriptionId = $this->createSubscription($billable, [], $periodEnd, $ids['customerId']);

        $subscription = $this->mollieSubscription($ids['customerId'], $subscriptionId);
        $this->assertSame('60.00', $subscription->amount->value);
        $this->assertSame($periodEnd->toDateString(), (string) $subscription->nextPaymentDate);

        // Two free plan periods and nothing else billed: Mollie cannot hold 0, so the
        // full price stays and the two next charges are skipped.
        app(AdminExtensionService::class)->freePlanPeriods($billable->refresh(), 2);
        $this->assertNothingQueued($billable);

        $expected = $periodEnd->copy()->addMonthsNoOverflow(2);
        $deferred = ChargeDeferral::until($billable->refresh());

        $subscription = $this->mollieSubscription($ids['customerId'], $subscriptionId);
        $this->assertNotNull($deferred);
        $this->assertSame($expected->toDateString(), $deferred->toDateString());
        $this->assertSame('60.00', $subscription->amount->value);
        $this->assertSame($deferred->toDateString(), (string) $subscription->nextPaymentDate);

        // The tenant buys SSO while the plan is free: charged from the next regular date.
        $net = SubscriptionAmount::net(app(SubscriptionCatalogInterface::class), $billable, 'pro', 'monthly', 2, ['sso']);
        $discount = app(CouponService::class)->computeMarkerDiscount($billable, $net, [
            'plan_code' => 'pro', 'interval' => 'monthly', 'addon_codes' => ['sso'], 'seats' => 2,
        ]);

        app(MollieSubscriptionPatcher::class)->updateRecurringAmount(
            billable: $billable,
            planCode: 'pro',
            interval: 'monthly',
            addons: ['sso'],
            extraSeats: 0,
            couponDiscountNet: $discount,
        );
        $billable->forceFill(['active_addon_codes' => ['sso']])->save();

        $subscription = $this->mollieSubscription($ids['customerId'], $subscriptionId);
        $this->assertSame('18.00', $subscription->amount->value);
        $this->assertSame($periodEnd->toDateString(), (string) $subscription->nextPaymentDate);
        $this->assertNull(ChargeDeferral::until($billable->refresh()));
        $this->assertSame($periodEnd->toDateString(), $billable->nextBillingDate()->toDateString());

        // And removed again: fully covered, back behind the waiver's end.
        $billable->forceFill(['active_addon_codes' => []])->save();
        app(PlanWaiver::class)->syncMollie($billable->refresh());

        $subscription = $this->mollieSubscription($ids['customerId'], $subscriptionId);
        $this->assertSame('60.00', $subscription->amount->value);
        $this->assertSame($expected->toDateString(), (string) $subscription->nextPaymentDate);
        $this->assertSame($expected->toDateString(), ChargeDeferral::until($billable->refresh())?->toDateString());
    }

    public function test_extending_a_deferred_subscription_by_days_moves_mollies_date_by_exactly_those_days(): void
    {
        [$billable, $ids, $periodEnd] = $this->billableMidPeriod([]);
        $subscriptionId = $this->createSubscription($billable, [], $periodEnd, $ids['customerId']);

        app(AdminExtensionService::class)->freePlanPeriods($billable->refresh(), 1);
        $this->assertNothingQueued($billable);

        $deferred = Carbon::instance(ChargeDeferral::until($billable->refresh()));
        $this->assertSame($periodEnd->copy()->addMonthNoOverflow()->toDateString(), $deferred->toDateString());

        app(AdminExtensionService::class)->extendByDays($billable->refresh(), 14);
        $this->assertNothingQueued($billable);

        $billable->refresh();
        $subscription = $this->mollieSubscription($ids['customerId'], $subscriptionId);

        $this->assertSame($deferred->copy()->addDays(14)->toDateString(), (string) $subscription->nextPaymentDate);
        $this->assertSame($deferred->copy()->addDays(14)->toDateString(), ChargeDeferral::until($billable)->toDateString());
        $this->assertSame('60.00', $subscription->amount->value, 'A date-only PATCH must not touch the amount.');
        // The billing period keeps its own cycle underneath the deferred charge.
        $this->assertSame($periodEnd->toDateString(), $billable->nextBillingDate()->toDateString());

        // A later re-price must not take the days back.
        app(PlanWaiver::class)->syncMollie($billable);

        $this->assertSame(
            $deferred->copy()->addDays(14)->toDateString(),
            (string) $this->mollieSubscription($ids['customerId'], $subscriptionId)->nextPaymentDate,
        );
    }

    public function test_a_fully_covering_gift_resumes_on_the_renewal_after_its_end_and_follows_an_extension(): void
    {
        [$billable, $ids, $periodEnd] = $this->billableMidPeriod([]);

        // Ends in 40 days: past the renewal in ~20, before the one in ~51.
        $this->gift($billable, 40);
        $subscriptionId = $this->createSubscription($billable->refresh(), [], $periodEnd, $ids['customerId']);

        $billable->refresh();
        $first = $periodEnd->copy()->addMonthNoOverflow();
        $subscription = $this->mollieSubscription($ids['customerId'], $subscriptionId);

        $this->assertSame('60.00', $subscription->amount->value);
        $this->assertSame($first->toDateString(), (string) $subscription->nextPaymentDate);
        $this->assertSame($first->toDateString(), ChargeDeferral::until($billable)?->toDateString());

        // 25 days more: the gift now covers the renewal in ~51 days as well.
        app(AdminExtensionService::class)->extendGrant($billable, 25);
        $this->assertNothingQueued($billable);

        $second = $periodEnd->copy()->addMonthsNoOverflow(2);
        $subscription = $this->mollieSubscription($ids['customerId'], $subscriptionId);

        $this->assertSame($second->toDateString(), (string) $subscription->nextPaymentDate);
        $this->assertSame($second->toDateString(), ChargeDeferral::until($billable->refresh())?->toDateString());

        // Taken back: the full price returns on the regular date, not at once.
        app(PlanWaiver::class)->remove($billable);
        app(PlanWaiver::class)->syncMollie($billable->refresh());

        $subscription = $this->mollieSubscription($ids['customerId'], $subscriptionId);

        $this->assertSame('60.00', $subscription->amount->value);
        $this->assertSame($periodEnd->toDateString(), (string) $subscription->nextPaymentDate);
        $this->assertNull(ChargeDeferral::until($billable->refresh()));
    }
}
