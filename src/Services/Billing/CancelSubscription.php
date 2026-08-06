<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\SubscriptionCancelled;
use GraystackIT\MollieBilling\Events\SubscriptionChangeCancelled;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Notifications\SubscriptionCancelledNotification;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

class CancelSubscription
{
    public function __construct(
        private readonly ChargeUsageOverageDirectly $overageService,
    ) {
    }

    public function handle(Billable $billable, bool $immediately = false): void
    {
        /** @var Model&Billable $billable */
        $isLocal = $billable->getBillingSubscriptionSource() === SubscriptionSource::Local->value
            || $billable->getBillingSubscriptionSource() === null
            || $billable->getBillingSubscriptionSource() === SubscriptionSource::None->value;

        $previousEndsAt = $billable->getBillingSubscriptionEndsAt();
        $meta = $billable->getBillingSubscriptionMeta();
        $scheduledChange = $meta['scheduled_change'] ?? null;

        // Both graceful branches below always leave a non-null `subscription_ends_at`,
        // which erases the difference between "this plan never expired" and "this was a
        // 30-day grant". ResubscribeSubscription needs that distinction to decide
        // whether resuming may clear the expiry.
        $meta['cancelled_from'] = ['ends_at' => $previousEndsAt?->toIso8601String()];

        // A change scheduled for the next period must not survive the cancellation:
        // the period-end dispatcher does not look at the status, so it would rewrite
        // plan/addon codes, rebalance wallets and — with excess usage and a live
        // mandate — charge overage against a customer who has already left.
        unset($meta['scheduled_change']);

        if ($isLocal) {
            $endsAt = $immediately
                ? BillingTime::nowUtc()
                : $this->graceBoundary($billable, $previousEndsAt ?? BillingTime::nowUtc()->addDays(30));

            $billable->forceFill([
                'subscription_status' => SubscriptionStatus::Cancelled,
                'subscription_ends_at' => $endsAt,
                'subscription_meta' => $meta,
                'scheduled_change_at' => null,
            ])->save();

            $this->announceCancelledScheduledChange($billable, $scheduledChange);

            SubscriptionCancelled::dispatch($billable, $immediately);

            return;
        }

        // Mollie subscription path.
        $customerId = $billable->getMollieCustomerId();
        $subscriptionId = $meta['mollie_subscription_id'] ?? null;

        if ($customerId !== null && $subscriptionId !== null) {
            $meta = $this->cancelAtMollie($billable, $meta, $customerId, (string) $subscriptionId);
        }

        $billable->forceFill([
            'subscription_status' => SubscriptionStatus::Cancelled,
            'subscription_ends_at' => $immediately
                ? BillingTime::nowUtc()
                : $this->graceBoundary($billable, $billable->nextBillingDate() ?? BillingTime::nowUtc()->addDays(30)),
            'subscription_meta' => $meta,
            'scheduled_change_at' => null,
        ])->save();

        if ($immediately) {
            try {
                $this->overageService->handle($billable);
            } catch (\Throwable $e) {
                // Overage charge failures are surfaced via OverageChargeFailed event in the service.
            }
        }

        $this->announceCancelledScheduledChange($billable, $scheduledChange);

        SubscriptionCancelled::dispatch($billable, $immediately);

        $recipients = MollieBilling::notifyBillingAdmins($billable);
        if (! empty($recipients)) {
            Notification::send($recipients, MollieBilling::resolveNotification(SubscriptionCancelledNotification::class, $billable));
        }
    }

    /**
     * End of the window the customer is actually entitled to keep.
     *
     * During a trial nothing has been paid: `subscription_period_starts_at` is the
     * mandate-capture time, so the caller's default (`nextBillingDate()`) would hand
     * out a full unpaid interval as grace — roughly a year on a yearly plan cancelled
     * on day two, which no automatic pass ever reclaims because the trial job only
     * looks at status `Trial`. The trial end is the real boundary.
     */
    private function graceBoundary(Billable $billable, CarbonInterface $default): CarbonInterface
    {
        $trialEndsAt = $billable->getBillingTrialEndsAt();

        if ($trialEndsAt === null || ! $trialEndsAt->isFuture()) {
            return $default;
        }

        return $trialEndsAt->lessThan($default) ? $trialEndsAt->copy() : $default;
    }

    /**
     * Cancel the Mollie subscription, distinguishing the two failure modes a single
     * blanket catch used to collapse into one.
     *
     * "Already gone" (404/410, i.e. Mollie has no such subscription) is the benign
     * case: nothing left to cancel. Anything else — network, 5xx, auth — means Mollie
     * may keep charging a customer whose access ends here, and the recurring webhook
     * would book those charges as ordinary renewals. That case is recorded as a
     * `pending_subscription_cancel` marker (same shape convention as
     * `pending_subscription_patch`) and reported to the admins.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function cancelAtMollie(Billable $billable, array $meta, string $customerId, string $subscriptionId): array
    {
        try {
            Mollie::send(new CancelSubscriptionRequest($customerId, $subscriptionId));

            unset($meta['pending_subscription_cancel']);

            return $meta;
        } catch (ApiException $e) {
            if (in_array($e->getCode(), [404, 410], true)) {
                unset($meta['pending_subscription_cancel']);

                return $meta;
            }

            $error = $e->getMessage();
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        Log::error('Mollie subscription cancel failed — local state is cancelled but Mollie may keep charging', [
            'billable' => $billable instanceof Model ? $billable->getKey() : null,
            'mollie_subscription_id' => $subscriptionId,
            'error' => $error,
        ]);

        $meta['pending_subscription_cancel'] = [
            'mollie_customer_id' => $customerId,
            'mollie_subscription_id' => $subscriptionId,
            'first_attempt_at' => BillingTime::nowUtc()->toIso8601String(),
            'last_error' => $error,
        ];

        $admins = MollieBilling::notifyAdmin();
        $admins = is_array($admins) ? $admins : iterator_to_array($admins);
        if ($admins !== []) {
            Notification::send($admins, MollieBilling::resolveNotification(
                AdminPlanChangeFailedNotification::class,
                'Mollie subscription cancel failed — the subscription may still be charging',
                $meta['pending_subscription_cancel'] + [
                    'billable_type' => $billable instanceof Model ? $billable->getMorphClass() : null,
                    'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
                ],
            ));
        }

        return $meta;
    }

    private function announceCancelledScheduledChange(Billable $billable, mixed $scheduledChange): void
    {
        if ($scheduledChange === null) {
            return;
        }

        event(new SubscriptionChangeCancelled($billable, (array) $scheduledChange));
    }
}
