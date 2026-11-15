<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Sandbox;

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\CreateSubscription;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Support\ChargeDeferral;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\GetSubscriptionRequest;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * What Mollie does with a subscription's charge date — measured, not assumed.
 *
 * `Support\ChargeDeferral` rests on one fact about the API that no fake can confirm: a
 * PATCH that carries no `startDate` leaves the date Mollie already holds. That is the
 * premise of the defect it fixes (an addon bought under a fully covering coupon stayed
 * free until the coupon ended), and the reason the fix has to send a date explicitly.
 */
class MollieChargeDateContractTest extends SandboxTestCase
{
    /**
     * @return array{0: TestBillable, 1: string, 2: array{customerId: string, mandateId: string}}
     */
    private function fullyCoveredSubscription(): array
    {
        config()->set('mollie-billing-plans.plans.pro', [
            'name' => 'Pro',
            'tier' => 2,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => ['base_price_net' => 1000, 'seat_price_net' => 500, 'included_usages' => []],
            ],
        ]);

        $ids = $this->sandboxCustomerWithMandate();

        /** @var TestBillable $billable */
        $billable = TestBillable::create([
            'name' => 'Sandbox Covered Co',
            'email' => 'sandbox-covered-'.bin2hex(random_bytes(3)).'@example.test',
        ]);

        $billable->forceFill([
            'subscription_source' => SubscriptionSource::Mollie,
            'subscription_status' => SubscriptionStatus::Active,
            'subscription_plan_code' => 'pro',
            'subscription_interval' => SubscriptionInterval::Monthly,
            'subscription_period_starts_at' => now(),
            'billing_country' => 'AT',
            'billing_street' => 'Teststrasse 1',
            'billing_postal_code' => '1010',
            'billing_city' => 'Wien',
            'mollie_customer_id' => $ids['customerId'],
            'mollie_mandate_id' => $ids['mandateId'],
            'subscription_meta' => ['seat_count' => 1],
        ])->save();

        $coupons = app(CouponService::class);
        $coupon = $coupons->create([
            'code' => 'SBX100-'.strtoupper(bin2hex(random_bytes(3))),
            'name' => 'Sandbox 100%',
            'type' => CouponType::Recurring,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 100,
            'max_redemptions_per_billable' => 3,
        ]);

        // The marker goes first, as on the real path: CreateSubscription reads it to
        // defer the start date past the coupon.
        $coupons->applyRecurringMarker($coupon, $billable->refresh(), 1000, 'monthly');

        app(CreateSubscription::class)->handle($billable->refresh(), [
            'plan_code' => 'pro',
            'interval' => 'monthly',
            'addon_codes' => [],
            'extra_seats' => 0,
            'recurring_discount_net' => 1000,
        ]);

        $billable->refresh();
        $subscriptionId = (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'];
        $this->trackMollieResource('subscription', $subscriptionId, $ids['customerId']);

        return [$billable, $subscriptionId, $ids];
    }

    private function mollieSubscription(string $customerId, string $subscriptionId): object
    {
        return Mollie::send(new GetSubscriptionRequest($customerId, $subscriptionId));
    }

    public function test_a_fully_covered_subscription_is_created_at_full_price_with_a_deferred_start(): void
    {
        [$billable, $subscriptionId, $ids] = $this->fullyCoveredSubscription();

        $deferred = ChargeDeferral::until($billable);
        $this->assertNotNull($deferred, 'The deferral must be stored when the subscription is created.');

        $subscription = $this->mollieSubscription($ids['customerId'], $subscriptionId);

        $this->assertSame('12.00', $subscription->amount->value, 'Full price: Mollie cannot hold 0.');
        $this->assertSame($deferred->toDateString(), (string) $subscription->startDate);
        $this->assertSame($deferred->toDateString(), (string) $subscription->nextPaymentDate);
    }

    public function test_a_patch_without_a_start_date_keeps_the_deferred_one(): void
    {
        [$billable, $subscriptionId, $ids] = $this->fullyCoveredSubscription();
        $deferred = ChargeDeferral::until($billable)->toDateString();

        // The raw request the package used to send for a seat added under the coupon.
        Mollie::send(new UpdateSubscriptionRequest(
            customerId: $ids['customerId'],
            subscriptionId: $subscriptionId,
            amount: new Money('EUR', '6.00'),
        ));

        $subscription = $this->mollieSubscription($ids['customerId'], $subscriptionId);

        $this->assertSame('6.00', $subscription->amount->value);
        $this->assertSame(
            $deferred,
            (string) $subscription->nextPaymentDate,
            'Mollie keeps the start date it is not sent — the premise of ChargeDeferral.',
        );
    }

    public function test_a_seat_added_under_the_coupon_is_charged_from_the_next_regular_date(): void
    {
        [$billable, $subscriptionId, $ids] = $this->fullyCoveredSubscription();

        $regular = ChargeDeferral::regularNextBillingDate($billable)->toDateString();
        $discount = app(CouponService::class)->computeMarkerDiscount($billable, 1500);

        app(MollieSubscriptionPatcher::class)->updateRecurringAmount(
            billable: $billable,
            planCode: 'pro',
            interval: 'monthly',
            addons: [],
            extraSeats: 1,
            couponDiscountNet: $discount,
        );

        $subscription = $this->mollieSubscription($ids['customerId'], $subscriptionId);

        // Only the seat: 500 net + 20 %.
        $this->assertSame('6.00', $subscription->amount->value);
        $this->assertSame($regular, (string) $subscription->nextPaymentDate);

        // The seat is written locally by the change that triggered the PATCH; with it in
        // place nothing describes the charge as deferred any more.
        $billable->refresh();
        $meta = $billable->getBillingSubscriptionMeta();
        $meta['seat_count'] = 2;
        $billable->forceFill(['subscription_meta' => $meta])->save();

        $this->assertNull(ChargeDeferral::until($billable->refresh()));
    }

    public function test_the_deferred_date_can_be_moved_later(): void
    {
        [$billable, $subscriptionId, $ids] = $this->fullyCoveredSubscription();

        $later = Carbon::instance(ChargeDeferral::until($billable))->addDays(14);

        app(MollieSubscriptionPatcher::class)->setNextChargeDate($billable, $later);

        $subscription = $this->mollieSubscription($ids['customerId'], $subscriptionId);

        $this->assertSame($later->toDateString(), (string) $subscription->nextPaymentDate);
        $this->assertSame('12.00', $subscription->amount->value, 'A date-only PATCH must not touch the amount.');
    }
}
