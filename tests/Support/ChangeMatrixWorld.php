<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Support;

use Carbon\Carbon;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\PlanChangeIntent;
use GraystackIT\MollieBilling\Services\Billing\PlanWaiver;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\ChargeDeferral;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * A small world for driving one subscription through a change, end to end.
 *
 * It stands in for Mollie with exactly what Mollie keeps: the amount and metadata last
 * sent with a subscription PATCH, the payments it was asked to create and the refunds
 * posted against them. Everything on our side is the real code — `UpdateSubscription`,
 * the composer, the real patcher, and the real webhook route for every payment that
 * settles — so a test can ask the one question that matters about a change:
 *
 *   what the preview showed == what was booked == what Mollie now holds == what the
 *   next renewal is invoiced at.
 *
 * Catalog (net, monthly / yearly ×10), AT at 20 %:
 *   free 0 (no seats sold) · starter 20.00 · pro 50.00 · enterprise 200.00 — 2 seats
 *   included each, 5.00 per extra seat
 *   addons: storage 9.00 · sso 15.00 · crm 20.00 + 5.00/seat with 1 free seat
 *
 * Period 1 Jan 2026 09:00 → 1 Feb 09:00 (31 days). `MID` is 11 Jan: 21 days left.
 */
final class ChangeMatrixWorld
{
    public const PERIOD_START = '2026-01-01 09:00:00';

    public const MID = '2026-01-11 12:00:00';

    /** What Mollie holds for the subscription: gross value as a string, or null. */
    public static ?string $heldValue = null;

    /** @var array<string, mixed> */
    public static array $heldMetadata = [];

    public static mixed $heldStartDate = null;

    public static bool $cancelled = false;

    /**
     * Whether Mollie has charged the subscription it holds. From then on it refuses a new
     * start date (422 "…already charged", measured 17 November 2026 — see
     * tests/Sandbox/MollieChargedSubscriptionContractTest), so the package has to replace
     * the subscription to move its next charge.
     */
    public static bool $charged = false;

    /** The subscription Mollie holds; a replacement gets a new id. */
    public static string $subscriptionId = 'sub_cm';

    public static ?string $heldDescription = null;

    public static mixed $heldInterval = null;

    /** How many subscriptions were created (a replacement is one). */
    public static int $subscriptionsCreated = 0;

    /** @var array<string, array{value: string, metadata: array<string, mixed>}> */
    public static array $payments = [];

    /** @var array<string, list<object>> refunds per payment id */
    public static array $refunds = [];

    /** @var list<object> every request sent, in order */
    public static array $sent = [];

    public static int $sequence = 0;

    public static bool $failPatches = false;

    /** Mollie refuses every refund while this is set. */
    public static bool $failRefunds = false;

    /** Mollie refuses to create a payment while this is set. */
    public static bool $failPayments = false;

    /** Billing country of the next subscription, and its VAT number (reverse charge). */
    public static string $country = 'AT';

    public static ?string $vatNumber = null;

    /** @var array<string, string> what Mollie answers when a payment is looked up */
    public static array $paymentStatus = [];

    /** @var array<string, bool> payments Mollie still lets us cancel (a SEPA debit not yet submitted) */
    public static array $cancelable = [];

    /** Mollie creates the next payment and then the answer is lost (a timeout). */
    public static bool $acceptThenTimeout = false;

    /** Looking a single payment up fails while this is set. */
    public static bool $failLookups = false;

    /** Listing the customer's payments fails while this is set. */
    public static bool $failPaymentLists = false;

    public static function boot(): void
    {
        Storage::fake('local');
        Notification::fake();
        config()->set('mollie-billing.invoices.disk', 'local');
        config()->set('mollie-billing.vat.seller_country', 'AT');

        self::$heldValue = null;
        self::$heldMetadata = [];
        self::$heldStartDate = null;
        self::$cancelled = false;
        self::$charged = false;
        self::$subscriptionId = 'sub_cm';
        self::$heldDescription = null;
        self::$heldInterval = null;
        self::$subscriptionsCreated = 0;
        self::$payments = [];
        self::$refunds = [];
        self::$sent = [];
        self::$sequence = 0;
        self::$failPatches = false;
        self::$failRefunds = false;
        self::$failPayments = false;
        self::$country = 'AT';
        self::$vatNumber = null;
        self::$paymentStatus = [];
        self::$cancelable = [];
        self::$acceptThenTimeout = false;
        self::$failLookups = false;
        self::$failPaymentLists = false;
        ChangeMatrixWebhookController::$nextPayment = null;

        app()->bind(MollieWebhookController::class, ChangeMatrixWebhookController::class);

        $intervals = fn (int $base, ?int $seat = 500): array => [
            'monthly' => ['base_price_net' => $base, 'seat_price_net' => $seat, 'included_usages' => []],
            'yearly' => ['base_price_net' => $base * 10, 'seat_price_net' => $seat === null ? null : $seat * 10, 'included_usages' => []],
        ];
        $all = ['storage', 'sso', 'crm', 'badge'];

        config()->set('mollie-billing-plans.plans', [
            'free' => ['name' => 'Free', 'tier' => 0, 'included_seats' => 2, 'feature_keys' => [], 'allowed_addons' => $all, 'intervals' => $intervals(0, null)],
            'starter' => ['name' => 'Starter', 'tier' => 1, 'included_seats' => 2, 'feature_keys' => [], 'allowed_addons' => $all, 'intervals' => $intervals(2000)],
            'pro' => ['name' => 'Pro', 'tier' => 2, 'included_seats' => 2, 'feature_keys' => [], 'allowed_addons' => $all, 'intervals' => $intervals(5000)],
            'enterprise' => ['name' => 'Enterprise', 'tier' => 3, 'included_seats' => 2, 'feature_keys' => [], 'allowed_addons' => $all, 'intervals' => $intervals(20000)],
        ]);
        config()->set('mollie-billing-plans.addons', [
            'storage' => ['name' => 'Storage', 'feature_keys' => [], 'intervals' => ['monthly' => ['price_net' => 900], 'yearly' => ['price_net' => 9000]]],
            'sso' => ['name' => 'SSO', 'feature_keys' => [], 'intervals' => ['monthly' => ['price_net' => 1500], 'yearly' => ['price_net' => 15000]]],
            // Bills nothing.
            'badge' => ['name' => 'Badge', 'feature_keys' => [], 'intervals' => ['monthly' => ['price_net' => 0], 'yearly' => ['price_net' => 0]]],
            'crm' => ['name' => 'CRM', 'feature_keys' => [], 'included_seats' => 1, 'intervals' => [
                'monthly' => ['price_net' => 2000, 'seat_price_net' => 500],
                'yearly' => ['price_net' => 20000, 'seat_price_net' => 5000],
            ]],
        ]);

        Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
        Mollie::shouldReceive('send')->andReturnUsing(fn (object $request): object => self::mollie($request));
    }

    /** What Mollie does with a request. */
    public static function mollie(object $request): object
    {
        self::$sent[] = $request;
        $id = ++self::$sequence;

        if ($request instanceof UpdateSubscriptionRequest) {
            if (self::$failPatches) {
                throw new \RuntimeException('Mollie is unreachable');
            }

            if (self::$cancelled) {
                throw self::refusal('The subscription has been cancelled.');
            }

            $requested = self::property($request, 'startDate');
            if ($requested !== null && self::$charged
                && (self::$heldStartDate === null || (string) $requested != (string) self::$heldStartDate)) {
                throw self::refusal('The start date cannot be changed when the subscription is already charged.');
            }

            $amount = self::property($request, 'amount');
            if ($amount !== null) {
                self::$heldValue = (string) $amount->value;
            }
            $metadata = self::property($request, 'metadata');
            if (is_array($metadata)) {
                self::$heldMetadata = $metadata;
            }
            $startDate = self::property($request, 'startDate');
            if ($startDate !== null) {
                self::$heldStartDate = $startDate;
            }
            $description = self::property($request, 'description');
            if ($description !== null) {
                self::$heldDescription = (string) $description;
            }
            $interval = self::property($request, 'interval');
            if ($interval !== null) {
                self::$heldInterval = (string) $interval;
            }

            return (object) ['id' => self::$subscriptionId];
        }

        if ($request instanceof CancelSubscriptionRequest) {
            if (self::$cancelled) {
                throw self::refusal('The subscription has been cancelled.');
            }

            self::$cancelled = true;

            return (object) ['id' => self::$subscriptionId, 'status' => 'canceled'];
        }

        if ($request instanceof \Mollie\Api\Http\Requests\CreateSubscriptionRequest) {
            if (! self::$cancelled && self::$heldDescription !== null
                && self::$heldDescription === (string) self::property($request, 'description')) {
                throw self::refusal('A subscription with the same description already exists for this customer.');
            }

            self::$subscriptionsCreated++;
            self::$subscriptionId = 'sub_cm_'.(self::$subscriptionsCreated + 1);
            self::$heldValue = (string) self::property($request, 'amount')->value;
            self::$heldMetadata = (array) self::property($request, 'metadata');
            self::$heldStartDate = self::property($request, 'startDate');
            self::$heldDescription = (string) self::property($request, 'description');
            self::$heldInterval = (string) self::property($request, 'interval');
            self::$cancelled = false;
            self::$charged = false;

            return (object) ['id' => self::$subscriptionId, 'status' => 'active'];
        }

        if ($request instanceof \Mollie\Api\Http\Requests\GetSubscriptionRequest) {
            return self::heldSubscription();
        }

        if ($request instanceof \Mollie\Api\Http\Requests\GetPaginatedSubscriptionsRequest) {
            return self::$heldValue === null ? [] : [self::heldSubscription()];
        }

        if ($request instanceof CreatePaymentRequest) {
            if (self::$failPayments) {
                throw new \RuntimeException('Mollie refused the payment');
            }

            $paymentId = 'tr_cm_'.$id;
            self::$payments[$paymentId] = [
                'value' => (string) self::property($request, 'amount')->value,
                'metadata' => (array) self::property($request, 'metadata'),
            ];

            if (self::$acceptThenTimeout) {
                self::$acceptThenTimeout = false;

                throw new \RuntimeException('Mollie did not answer in time');
            }

            return (object) ['id' => $paymentId, 'status' => 'open'];
        }

        if ($request instanceof CreatePaymentRefundRequest) {
            if (self::$failRefunds) {
                throw new \RuntimeException('Mollie refused the refund');
            }

            $refund = (object) [
                'id' => 're_cm_'.$id,
                'status' => 'pending',
                'amount' => self::property($request, 'amount'),
            ];
            self::$refunds[(string) self::property($request, 'paymentId')][] = $refund;

            return $refund;
        }

        if ($request instanceof \Mollie\Api\Http\Requests\GetPaginatedCustomerPaymentsRequest) {
            if (self::$failPaymentLists) {
                throw new \RuntimeException('Mollie is unreachable');
            }

            $list = [];
            foreach (array_reverse(self::$payments, true) as $paymentId => $payment) {
                $list[] = (object) [
                    'id' => $paymentId,
                    'status' => self::$paymentStatus[$paymentId] ?? 'pending',
                    'isCancelable' => self::$cancelable[$paymentId] ?? false,
                    'amount' => (object) ['value' => $payment['value'], 'currency' => 'EUR'],
                    'metadata' => (object) $payment['metadata'],
                ];
            }

            return (object) ['data' => $list];
        }

        if ($request instanceof \Mollie\Api\Http\Requests\GetPaymentRequest) {
            if (self::$failLookups) {
                throw new \RuntimeException('Mollie is unreachable');
            }

            $paymentId = (string) self::property($request, 'id');

            return (object) [
                'id' => $paymentId,
                'status' => self::$paymentStatus[$paymentId] ?? 'pending',
                'isCancelable' => self::$cancelable[$paymentId] ?? false,
            ];
        }

        if ($request instanceof \Mollie\Api\Http\Requests\CancelPaymentRequest) {
            $paymentId = (string) self::property($request, 'id');
            self::$paymentStatus[$paymentId] = 'canceled';

            return (object) ['id' => $paymentId, 'status' => 'canceled'];
        }

        return (object) ['id' => 'obj_cm_'.$id, 'status' => 'open'];
    }

    /** A 422 as Mollie words it — the detail is what the package reads. */
    private static function refusal(string $detail): \Mollie\Api\Exceptions\ApiException
    {
        return MollieApiExceptionFactory::withStatus(422, 'Error executing API call (422: Unprocessable Entity): '.$detail);
    }

    /** The subscription Mollie holds, as a lookup answers it. */
    private static function heldSubscription(): object
    {
        $start = self::$heldStartDate !== null ? Carbon::parse((string) self::$heldStartDate, 'UTC')->toDateString() : null;

        return (object) [
            'id' => self::$subscriptionId,
            'status' => self::$cancelled ? 'canceled' : 'active',
            'amount' => (object) ['value' => self::$heldValue, 'currency' => 'EUR'],
            'interval' => self::$heldInterval ?? '1 month',
            'description' => self::$heldDescription,
            'metadata' => (object) self::$heldMetadata,
            'mandateId' => 'mdt_cm',
            'webhookUrl' => 'https://example.test/billing/webhook',
            'startDate' => $start,
            'nextPaymentDate' => self::$cancelled ? null : $start,
        ];
    }

    private static function property(object $request, string $name): mixed
    {
        $class = new \ReflectionClass($request);

        while ($class !== false) {
            if ($class->hasProperty($name)) {
                $property = $class->getProperty($name);
                $property->setAccessible(true);

                return $property->getValue($request);
            }
            $class = $class->getParentClass();
        }

        return null;
    }

    // ── Setting up ──────────────────────────────────────────────────────────

    /**
     * An Active Mollie subscription whose current period (from PERIOD_START) was paid for
     * by a real renewal, under the given discount state. The clock is left at MID.
     *
     * Discount states: none | pct50 | fixed | pct50-last | full100 | waiver-date-end |
     * waiver-date-paid | waiver-periods | waiver-gift (the gift includes `sso`).
     *
     * @param  array<int, string>  $addons
     */
    public static function subscription(
        string $discount = 'none',
        string $plan = 'pro',
        int $seats = 2,
        array $addons = [],
        string $interval = 'monthly',
        string $at = self::MID,
        int $used = 0,
    ): TestBillable {
        $start = Carbon::parse(self::PERIOD_START, 'UTC');
        $previousStart = $interval === 'yearly' ? $start->copy()->subYear() : $start->copy()->subMonth();

        test()->travelTo($previousStart->copy()->addMinutes(5));

        if (str_starts_with($discount, 'waiver-gift') && ! in_array('sso', $addons, true)) {
            $addons[] = 'sso';
        }

        /** @var TestBillable $billable */
        $billable = TestBillable::create([
            'name' => 'Change Matrix Co',
            'email' => uniqid('change-matrix-').'@x.test',
            'billing_country' => self::$country,
            'tax_country_user' => self::$country,
            'vat_number' => self::$vatNumber,
        ]);

        $billable->forceFill([
            'mollie_customer_id' => 'cst_cm',
            'mollie_mandate_id' => 'mdt_cm',
            'subscription_source' => SubscriptionSource::Mollie,
            'subscription_status' => SubscriptionStatus::Active,
            'subscription_plan_code' => $plan,
            'subscription_interval' => SubscriptionInterval::from($interval),
            'subscription_period_starts_at' => $previousStart,
            'active_addon_codes' => array_values($addons),
            // `$used`: seats with a member on them. A plan change lands on those (or on
            // what the new plan includes), so a test that wants seats carried across one
            // says how many are in use.
            'subscription_meta' => ['mollie_subscription_id' => 'sub_cm', 'seat_count' => $seats, 'used_seats' => $used],
        ])->save();
        $billable = $billable->refresh();

        if (self::$vatNumber !== null) {
            // A VIES-validated VAT number: reverse charge wherever the country is not the
            // seller's own.
            $validation = new \GraystackIT\MollieBilling\Models\BillingVatValidation;
            $validation->billable_type = $billable->getMorphClass();
            $validation->billable_id = $billable->getKey();
            $validation->vat_number = self::$vatNumber;
            $validation->country_code = self::$country;
            $validation->valid = true;
            $validation->vies_response = [];
            $validation->checked_at = now();
            $validation->save();
            $billable = $billable->refresh();
        }

        self::applyDiscount($billable, $discount, $plan, $interval, $seats, $addons);
        $billable = $billable->refresh();

        // Mollie holds whatever the package would have sent for this configuration.
        self::syncMollie($billable);

        if (self::recurringNet($billable) === 0) {
            // Nothing is collected while the discount covers everything (a 100 % coupon,
            // or a waiver with no paid extras) — the charge is deferred — so the running
            // period has no paying invoice.
            $billable->forceFill(['subscription_period_starts_at' => $start])->save();
        } else {
            test()->travelTo($start->copy()->addSeconds(30));
            self::renew($billable, $start->toIso8601String());
        }

        test()->travelTo(Carbon::parse($at, 'UTC'));

        return $billable->refresh();
    }

    private static function applyDiscount(TestBillable $billable, string $discount, string $plan, string $interval, int $seats, array $addons): void
    {
        if ($discount === 'none') {
            return;
        }

        $coupons = app(CouponService::class);
        $net = SubscriptionAmount::net(app(SubscriptionCatalogInterface::class), $billable, $plan, $interval, $seats, $addons);
        $code = strtoupper('CM'.bin2hex(random_bytes(3)));

        if (str_starts_with($discount, 'waiver')) {
            // `waiver-gift`, `waiver-gift-paid`, `waiver-gift-periods`: the gift includes SSO.
            $granted = str_starts_with($discount, 'waiver-gift') ? ['sso'] : [];
            $coupon = $coupons->accessGrantCoupon($code, $plan, $interval, $granted, 90);

            app(PlanWaiver::class)->grant(
                $billable,
                $coupon,
                $plan,
                $interval,
                $granted,
                // Past the next renewal either way, so that renewal is still one under the
                // waiver: 75 days on a monthly cycle, 440 on a yearly one.
                str_ends_with($discount, '-periods') ? null : Carbon::parse(self::PERIOD_START, 'UTC')->addDays($interval === 'yearly' ? 440 : 75),
                str_ends_with($discount, '-periods') ? 4 : null,
                str_ends_with($discount, '-paid') ? PlanWaiver::ON_EXPIRY_CONTINUE_PAID : PlanWaiver::ON_EXPIRY_END,
            );

            return;
        }

        $coupon = $coupons->create([
            'code' => $code,
            'name' => $discount,
            'type' => CouponType::Recurring,
            'discount_type' => $discount === 'fixed' ? DiscountType::Fixed : DiscountType::Percentage,
            'discount_value' => match ($discount) {
                'fixed' => 1000,
                'full100' => 100,
                default => 50,
            },
            // `pct50-last`: the renewal that paid the running period was the last one. The
            // marker is written at the start of the previous period, and its periods are
            // billing periods (since 17 November 2026): that one and the running one. (With
            // 1 the 30-day window of before reached a day past the renewal.)
            'discount_periods' => $discount === 'pct50-last' ? 2 : 12,
        ]);

        $coupons->applyRecurringMarker($coupon, $billable, $net, $interval);

        if ($discount === 'full100') {
            $billable->refresh();
            $meta = $billable->getBillingSubscriptionMeta();
            $meta[ChargeDeferral::META_KEY] = Carbon::parse((string) $meta['active_recurring_coupon']['valid_until'])->addDay()->toIso8601String();
            $billable->forceFill(['subscription_meta' => $meta])->save();
        }
    }

    /**
     * A subscription in its trial: mandate captured on PERIOD_START, trial until 15 Jan
     * 09:00, nothing paid yet. The clock is left at 5 Jan 12:00.
     *
     * @param  array<int, string>  $addons
     */
    public static function trial(string $plan = 'pro', int $seats = 2, array $addons = [], string $discount = 'none'): TestBillable
    {
        $start = Carbon::parse(self::PERIOD_START, 'UTC');
        test()->travelTo($start->copy()->addMinutes(5));

        /** @var TestBillable $billable */
        $billable = TestBillable::create([
            'name' => 'Change Matrix Trial Co',
            'email' => uniqid('change-matrix-trial-').'@x.test',
            'billing_country' => self::$country,
            'tax_country_user' => self::$country,
            'vat_number' => self::$vatNumber,
        ]);

        $billable->forceFill([
            'mollie_customer_id' => 'cst_cm',
            'mollie_mandate_id' => 'mdt_cm',
            'subscription_source' => SubscriptionSource::Mollie,
            'subscription_status' => SubscriptionStatus::Trial,
            'subscription_plan_code' => $plan,
            'subscription_interval' => SubscriptionInterval::Monthly,
            'subscription_period_starts_at' => $start,
            'trial_ends_at' => $start->copy()->addDays(14),
            'active_addon_codes' => array_values($addons),
            'subscription_meta' => ['mollie_subscription_id' => 'sub_cm', 'seat_count' => $seats],
        ])->save();

        $billable = $billable->refresh();
        self::applyDiscount($billable, $discount, $plan, 'monthly', $seats, $addons);
        $billable = $billable->refresh();
        self::syncMollie($billable);

        test()->travelTo(Carbon::parse('2026-01-05 12:00:00', 'UTC'));

        return $billable->refresh();
    }

    /**
     * Mark the running subscription past due: the renewal on 1 Feb failed. The clock is
     * left at 3 Feb 12:00, with the period anchor still on the unpaid period's start.
     */
    public static function failRenewal(TestBillable $billable): TestBillable
    {
        test()->travelTo(Carbon::parse('2026-02-03 12:00:00', 'UTC'));

        $meta = $billable->refresh()->getBillingSubscriptionMeta();
        $meta['past_due_since'] = '2026-02-01T09:00:00+00:00';
        $meta['payment_failure'] = ['payment_id' => 'tr_cm_failed', 'failed_at' => '2026-02-01T09:00:00+00:00'];
        // The failed renewal was a charge Mollie created.
        self::$charged = true;

        $billable->forceFill([
            'subscription_status' => SubscriptionStatus::PastDue,
            'subscription_meta' => $meta,
        ])->save();

        return $billable->refresh();
    }

    /** Settle the prorata payment that is pending on the billable, through the webhook. */
    public static function settle(TestBillable $billable, string $status = 'paid', int $expectHttp = 200): ?string
    {
        $pending = $billable->refresh()->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null;

        if (! is_array($pending) || empty($pending['charge_payment_id'])) {
            return null;
        }

        $paymentId = (string) $pending['charge_payment_id'];
        $value = (string) (self::$payments[$paymentId]['value'] ?? '0.00');

        self::deliver($billable, $paymentId, $status, $value, self::$payments[$paymentId]['metadata'] ?? ['type' => 'prorata_charge'], expectHttp: $expectHttp);
        $billable->refresh();

        return $value;
    }

    /** Have the package send Mollie the amount of the billable's current configuration. */
    public static function syncMollie(TestBillable $billable): void
    {
        $addons = array_fill_keys($billable->getActiveBillingAddonCodes(), 1);

        app(MollieSubscriptionPatcher::class)->updateForIntent($billable, new PlanChangeIntent(
            billable: $billable,
            currentPlan: (string) $billable->getBillingSubscriptionPlanCode(),
            newPlan: (string) $billable->getBillingSubscriptionPlanCode(),
            currentInterval: (string) $billable->getBillingSubscriptionInterval(),
            newInterval: (string) $billable->getBillingSubscriptionInterval(),
            currentSeats: $billable->getBillingSeatCount(),
            newSeats: $billable->getBillingSeatCount(),
            currentAddons: $addons,
            newAddons: $addons,
        ));
    }

    // ── Things that happen ──────────────────────────────────────────────────

    /** Deliver a payment to the real webhook route. */
    public static function deliver(TestBillable $billable, string $paymentId, string $status, string $value, array $metadata, ?string $paidAt = null, bool $recurring = false, int $expectHttp = 200): void
    {
        ChangeMatrixWebhookController::$nextPayment = new ChangeMatrixPayment([
            'id' => $paymentId,
            'status' => $status,
            'amount' => (object) ['value' => $value, 'currency' => 'EUR'],
            'paidAt' => $status === 'paid' ? ($paidAt ?? now('UTC')->toIso8601String()) : null,
            'subscriptionId' => $recurring ? self::$subscriptionId : null,
            'countryCode' => (string) ($billable->getBillingCountry() ?? 'AT'),
            'details' => $status === 'paid' ? null : (object) ['failureReason' => 'insufficient_funds'],
            'metadata' => $metadata + [
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
            ],
        ]);

        test()->postJson(route('billing.webhook'), ['id' => $paymentId])->assertStatus($expectHttp);
    }

    /**
     * The recurring payment Mollie creates from what it holds, settling at `$paidAt`
     * (default: the billable's next billing date). Returns its invoice.
     */
    public static function renew(TestBillable $billable, ?string $paidAt = null): BillingInvoice
    {
        $billable->refresh();
        $paidAt ??= $billable->nextBillingDate()->toIso8601String();

        if (Carbon::parse($paidAt)->greaterThan(now('UTC'))) {
            test()->travelTo(Carbon::parse($paidAt)->addSeconds(30));
        }

        $paymentId = 'tr_cm_renewal_'.(++self::$sequence);
        self::$charged = true;

        self::deliver($billable, $paymentId, 'paid', (string) self::$heldValue, self::$heldMetadata, $paidAt, recurring: true);

        return BillingInvoice::query()->where('mollie_payment_id', $paymentId)->firstOrFail();
    }

    /**
     * Preview a change, apply it through `UpdateSubscription::update()`, and settle the
     * prorata charge it created (if any) as `paid` or `failed` through the webhook.
     *
     * @param  array<string, mixed>  $payload
     * @return array{preview: array<string, mixed>, result: array<string, mixed>, booked: list<array{0: string, 1: ?string, 2: int}>, previewed: list<array{0: string, 1: ?string, 2: int}>, charged: ?string, settled: ?string}
     */
    public static function change(TestBillable $billable, array $payload, ?string $settle = 'paid'): array
    {
        $billable->refresh();
        $preview = app(PreviewService::class)->previewUpdate($billable, $payload);
        $lastInvoiceId = (int) BillingInvoice::query()->max('id');

        $result = app(UpdateSubscription::class)->update($billable, $payload);

        $billable->refresh();
        $pending = $billable->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null;
        $charged = null;
        $settled = null;

        if (is_array($pending) && ! empty($pending['charge_payment_id']) && $settle !== null) {
            $paymentId = (string) $pending['charge_payment_id'];
            $charged = self::$payments[$paymentId]['value'] ?? null;
            $settled = $settle;

            self::deliver($billable, $paymentId, $settle, (string) $charged, self::$payments[$paymentId]['metadata'] ?? ['type' => 'prorata_charge']);
        } elseif (is_array($pending) && ! empty($pending['charge_payment_id'])) {
            $charged = self::$payments[(string) $pending['charge_payment_id']]['value'] ?? null;
        }

        $billable->refresh();

        return [
            'preview' => $preview,
            'result' => $result,
            'booked' => self::bookedSince($billable, $lastInvoiceId),
            'previewed' => self::normalise(array_map(
                fn (array $l): array => [(string) $l['kind'], $l['code'] ?? null, (int) $l['amount_net']],
                $preview['prorataLines'] ?? [],
            )),
            'charged' => $charged,
            'settled' => $settled,
        ];
    }

    /**
     * Every prorata line booked on an invoice since `$afterId`: [kind, code, net].
     *
     * @return list<array{0: string, 1: ?string, 2: int}>
     */
    public static function bookedSince(TestBillable $billable, int $afterId): array
    {
        $lines = [];

        foreach (BillingInvoice::query()->where('billable_id', $billable->getKey())->where('id', '>', $afterId)->orderBy('id')->get() as $invoice) {
            foreach ((array) $invoice->line_items as $line) {
                $lines[] = [(string) ($line['kind'] ?? ''), $line['code'] ?? null, (int) ($line['amount_net'] ?? 0)];
            }
        }

        return self::normalise($lines);
    }

    /**
     * @param  list<array{0: string, 1: ?string, 2: int}>  $lines
     * @return list<array{0: string, 1: ?string, 2: int}>
     */
    public static function normalise(array $lines): array
    {
        // Zero lines (a coupon-covered credit) carry no money and are not booked.
        $lines = array_values(array_filter($lines, fn (array $l): bool => $l[2] !== 0));
        usort($lines, fn (array $a, array $b): int => [$a[0], (string) $a[1], $a[2]] <=> [$b[0], (string) $b[1], $b[2]]);

        return $lines;
    }

    // ── Reading the world ───────────────────────────────────────────────────

    public static function heldGross(): ?int
    {
        return self::$heldValue === null ? null : BillingMoney::minorFromMollieAmount(self::$heldValue, 'EUR');
    }

    /** Net over a list of [kind, code, net] lines, optionally one sign only. */
    public static function sum(array $lines, ?string $sign = null): int
    {
        $total = 0;
        foreach ($lines as $line) {
            if ($sign === '+' && $line[2] < 0 || $sign === '-' && $line[2] > 0) {
                continue;
            }
            $total += $line[2];
        }

        return $total;
    }

    /** Net the billable paid for the running period (0 when nothing was collected). */
    public static function paidForPeriod(TestBillable $billable): int
    {
        return (int) (BillingInvoice::query()
            ->where('billable_id', $billable->getKey())
            ->whereNotNull('mollie_payment_id')
            ->latest('id')
            ->first()?->amount_net ?? 0);
    }

    /** Everything invoiced for the billable minus everything credited back, net. */
    public static function netCollected(TestBillable $billable): int
    {
        return (int) BillingInvoice::query()->where('billable_id', $billable->getKey())->sum('amount_net');
    }

    /** What the billable's recurring charge is: list net minus the running discount. */
    public static function recurringNet(TestBillable $billable): int
    {
        $billable->refresh();
        $net = SubscriptionAmount::net(
            app(SubscriptionCatalogInterface::class),
            $billable,
            (string) $billable->getBillingSubscriptionPlanCode(),
            (string) $billable->getBillingSubscriptionInterval(),
            $billable->getBillingSeatCount(),
            $billable->getActiveBillingAddonCodes(),
        );

        return max(0, $net - app(CouponService::class)->computeMarkerDiscount($billable, $net));
    }

    /**
     * No invoice was credited beyond what it collected.
     */
    public static function assertNothingOverRefunded(TestBillable $billable): void
    {
        foreach (BillingInvoice::query()->where('billable_id', $billable->getKey())->whereNotNull('mollie_payment_id')->get() as $invoice) {
            $credited = 0;

            foreach (BillingInvoice::query()->where('billable_id', $billable->getKey())->where('invoice_kind', InvoiceKind::Refund)->get() as $note) {
                foreach ((array) $note->line_items as $line) {
                    if ((int) ($line['parent_invoice_id'] ?? 0) === (int) $invoice->id) {
                        $credited += abs((int) ($line['amount_net'] ?? 0));
                    }
                }
            }

            expect($credited)->toBeLessThanOrEqual(
                (int) $invoice->amount_net,
                "invoice {$invoice->id} collected {$invoice->amount_net} net and was credited {$credited}",
            );

            $refundedGross = 0;
            foreach (self::$refunds[(string) $invoice->mollie_payment_id] ?? [] as $refund) {
                $refundedGross += BillingMoney::minorFromMollieAmount((string) $refund->amount->value, 'EUR');
            }

            expect($refundedGross)->toBeLessThanOrEqual(
                (int) $invoice->amount_gross,
                "payment {$invoice->mollie_payment_id} collected {$invoice->amount_gross} gross and was refunded {$refundedGross}",
            );
        }
    }
}

/** A Mollie payment as the webhook sees it, including the live `refunds()` lookup. */
#[\AllowDynamicProperties]
final class ChangeMatrixPayment
{
    public function __construct(array $attributes)
    {
        foreach ($attributes as $key => $value) {
            $this->{$key} = $value;
        }
    }

    /** @return list<object> */
    public function refunds(): array
    {
        return ChangeMatrixWorld::$refunds[(string) $this->id] ?? [];
    }
}

final class ChangeMatrixWebhookController extends MollieWebhookController
{
    public static ?object $nextPayment = null;

    protected function fetchPayment(string $paymentId): object
    {
        return self::$nextPayment ?? throw new \RuntimeException('No payment stub configured.');
    }
}
