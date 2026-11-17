<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Sandbox;

use Carbon\Carbon;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\CreateSubscription;
use GraystackIT\MollieBilling\Services\Billing\PlanWaiver;
use GraystackIT\MollieBilling\Services\Billing\RedeemCouponFromPortal;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ChargeDeferral;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Http\Requests\GetSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * A recurring code on a running subscription, against the real API: what does Mollie hold
 * after the code is redeemed in the portal, and after it comes with a seat change?
 *
 * The feature suite asserts the PATCH the package builds; this reads the subscription
 * back with `GetSubscriptionRequest` and compares amount and next payment date.
 *
 * Pro 50.00 (2 seats included, 5.00 per extra seat), 20 % VAT: 60.00 in full, 48.00 with
 * 20 % off; with two extra seats 72.00 in full, 57.60 with 20 % off.
 */
class MollieRecurringCodeContractTest extends SandboxTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('mollie-billing-plans.plans.pro', [
            'name' => 'Pro',
            'tier' => 2,
            'included_seats' => 2,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => ['base_price_net' => 5000, 'seat_price_net' => 500, 'included_usages' => []],
            ],
        ]);
    }

    /**
     * A billable with a running Mollie subscription. Paying: ten days into a monthly
     * period, next charge at its end. Trial: the first charge at the end of the trial.
     *
     * @return array{0: TestBillable, 1: string, 2: string, 3: Carbon}
     */
    private function subscribed(SubscriptionStatus $status): array
    {
        $ids = $this->sandboxCustomerWithMandate('Sandbox Code Co');
        $firstCharge = $status === SubscriptionStatus::Trial
            ? Carbon::instance(BillingTime::nowUtc()->addDays(10))
            : Carbon::instance(BillingTime::addInterval(BillingTime::nowUtc()->subDays(10), 'monthly'));

        /** @var TestBillable $billable */
        $billable = TestBillable::create([
            'name' => 'Sandbox Code Co',
            'email' => 'sandbox-code-'.bin2hex(random_bytes(3)).'@example.test',
        ]);

        $billable->forceFill([
            'subscription_source' => SubscriptionSource::Mollie,
            'subscription_status' => $status,
            'subscription_plan_code' => 'pro',
            'subscription_interval' => SubscriptionInterval::Monthly,
            'subscription_period_starts_at' => $status === SubscriptionStatus::Trial
                ? BillingTime::nowUtc()->subDays(4)
                : BillingTime::subInterval($firstCharge, 'monthly'),
            'trial_ends_at' => $status === SubscriptionStatus::Trial ? $firstCharge : null,
            'active_addon_codes' => [],
            'billing_country' => 'AT',
            'billing_street' => 'Teststrasse 1',
            'billing_postal_code' => '1010',
            'billing_city' => 'Wien',
            'mollie_customer_id' => $ids['customerId'],
            'mollie_mandate_id' => $ids['mandateId'],
            'subscription_meta' => ['seat_count' => 2],
        ])->save();

        app(CreateSubscription::class)->handle($billable->refresh(), [
            'plan_code' => 'pro',
            'interval' => 'monthly',
            'addon_codes' => [],
            'extra_seats' => 0,
            'recurring_discount_net' => 0,
            ...($status === SubscriptionStatus::Trial
                ? ['trial_ends_at' => $firstCharge]
                : ['paid_period_ends_at' => $firstCharge]),
        ]);

        $billable->refresh();
        $subscriptionId = (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'];
        $this->trackMollieResource('subscription', $subscriptionId, $ids['customerId']);

        $this->assertSame('60.00', $this->mollie($ids['customerId'], $subscriptionId)->amount->value);

        return [$billable, $ids['customerId'], $subscriptionId, $firstCharge];
    }

    private function recurringCode(int $percent, int $periods): string
    {
        $code = 'SBXREC-'.strtoupper(bin2hex(random_bytes(3)));

        app(CouponService::class)->create([
            'code' => $code,
            'type' => CouponType::Recurring,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => $percent,
            'discount_periods' => $periods,
        ]);

        return $code;
    }

    private function mollie(string $customerId, string $subscriptionId): object
    {
        return Mollie::send(new GetSubscriptionRequest($customerId, $subscriptionId));
    }

    /** A PATCH that failed is queued, not thrown — so "nothing queued" is part of every assertion. */
    private function assertNothingQueued(TestBillable $billable): void
    {
        $pending = $billable->refresh()->getBillingSubscriptionMeta()['pending_subscription_patch'] ?? null;

        $this->assertNull($pending, 'A Mollie PATCH failed and was queued: '.json_encode($pending));
    }

    public function test_a_recurring_code_redeemed_in_the_portal_lowers_what_mollie_collects_and_keeps_the_date(): void
    {
        [$billable, $customerId, $subscriptionId, $nextCharge] = $this->subscribed(SubscriptionStatus::Active);

        $outcome = app(RedeemCouponFromPortal::class)->handle($billable, $this->recurringCode(20, 6));

        $this->assertTrue($outcome->redeemed, (string) $outcome->message);
        $this->assertNothingQueued($billable);

        $subscription = $this->mollie($customerId, $subscriptionId);

        $this->assertSame('48.00', $subscription->amount->value, 'Pro 50.00 − 20 % = 40.00 + 20 % VAT.');
        $this->assertSame($nextCharge->toDateString(), (string) $subscription->nextPaymentDate, 'An amount change must not move the date.');
        $this->assertNull(ChargeDeferral::until($billable->refresh()));
    }

    public function test_a_fully_covering_recurring_code_from_the_portal_parks_the_full_price_behind_the_free_period(): void
    {
        [$billable, $customerId, $subscriptionId, $nextCharge] = $this->subscribed(SubscriptionStatus::Active);

        $outcome = app(RedeemCouponFromPortal::class)->handle($billable, $this->recurringCode(100, 1));

        $this->assertTrue($outcome->redeemed, (string) $outcome->message);
        $this->assertNothingQueued($billable);

        $deferred = ChargeDeferral::until($billable->refresh());
        $subscription = $this->mollie($customerId, $subscriptionId);

        // Mollie cannot hold 0: the full price, first collected after the free charge.
        $this->assertNotNull($deferred);
        $this->assertSame('60.00', $subscription->amount->value);
        $this->assertSame($deferred->toDateString(), (string) $subscription->nextPaymentDate);
        $this->assertTrue($deferred->greaterThan($nextCharge), 'The next regular charge is the free one.');
        // One billing period after the free charge, on the subscription's day — the period
        // began ten days ago, so two steps of its cycle (since 17 November 2026 the code's
        // window counts billing periods, not 30-day blocks).
        $this->assertSame(
            \GraystackIT\MollieBilling\Support\BillingAnchor::advance(BillingTime::nowUtc()->subDays(10), 'monthly', 2)->toDateString(),
            (string) $subscription->nextPaymentDate,
        );
    }

    public function test_a_recurring_code_entered_with_a_seat_change_reaches_mollie_discounted_with_the_first_patch(): void
    {
        // During a trial the change costs nothing now, so the PATCH is all there is.
        [$billable, $customerId, $subscriptionId, $trialEnd] = $this->subscribed(SubscriptionStatus::Trial);

        // The re-price after the commit is the safety net for a PATCH that did not reach
        // Mollie. Switched off here, so what Mollie holds is what the change's own PATCH
        // sent — the first word, not the last. (Before the PATCH was priced from the
        // marker it is about to write, that was the full price, 72.00.)
        $repriced = new \stdClass();
        $repriced->count = 0;
        $this->app->bind(PlanWaiver::class, fn ($app) => new class($app->make(SubscriptionCatalogInterface::class), $repriced) extends PlanWaiver
        {
            public function __construct(SubscriptionCatalogInterface $catalog, private readonly \stdClass $repriced)
            {
                parent::__construct($catalog);
            }

            public function syncMollieOrQueue(\GraystackIT\MollieBilling\Contracts\Billable $billable, ?int $couponId = null): void
            {
                $this->repriced->count++;
            }
        });

        app(UpdateSubscription::class)->update($billable, [
            'seats' => 4,
            'coupon_codes' => [$this->recurringCode(20, 6)],
        ]);
        $this->assertNothingQueued($billable);

        $subscription = $this->mollie($customerId, $subscriptionId);

        $this->assertSame('57.60', $subscription->amount->value, '50.00 + 2 × 5.00 = 60.00, − 20 % = 48.00 + 20 % VAT.');
        $this->assertSame($trialEnd->toDateString(), (string) $subscription->nextPaymentDate);
        $this->assertSame(4, $billable->refresh()->getBillingSeatCount());
        $this->assertSame(0, $repriced->count, 'The change needed no re-price: its PATCH carried the discount.');
    }
}
