<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Testing;

use Carbon\Carbon;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Test-only helpers that push a Billable into a specific state without going through services.
 * Do NOT use in production code — they bypass state-machine guards.
 */
class BillableStateHelper
{
    public static function putOnTrial(Billable $billable, int $days): void
    {
        self::force($billable, [
            'subscription_status' => SubscriptionStatus::Trial,
            'trial_ends_at' => Carbon::now()->addDays($days),
        ]);
    }

    public static function putPastDue(Billable $billable): void
    {
        self::force($billable, ['subscription_status' => SubscriptionStatus::PastDue]);
    }

    public static function expireTrial(Billable $billable): void
    {
        self::force($billable, [
            'subscription_status' => SubscriptionStatus::Expired,
            'trial_ends_at' => Carbon::now()->subDay(),
        ]);
    }

    public static function scheduleDowngrade(Billable $billable, string $planCode, Carbon $at): void
    {
        self::force($billable, [
            'scheduled_change_at' => $at,
            'subscription_meta' => array_merge($billable->getBillingSubscriptionMeta(), [
                'scheduled_change' => ['plan_code' => $planCode, 'scheduled_at' => $at->toIso8601String()],
            ]),
        ]);
    }

    public static function setSource(Billable $billable, SubscriptionSource $source): void
    {
        self::force($billable, ['subscription_source' => $source]);
    }

    private static function force(Billable $billable, array $attributes): void
    {
        if ($billable instanceof Model) {
            $billable->forceFill($attributes)->save();
        }
    }
}
