<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\TrialStarted;
use Illuminate\Database\Eloquent\Model;

class ActivateSubscription
{
    public function __construct(
        private readonly ActivateLocalSubscription $local,
    ) {
    }

    /**
     * @param  array<int, string>  $addonCodes
     */
    public function handle(
        Billable $billable,
        string $planCode,
        string $interval,
        int $trialDays = 0,
        array $addonCodes = [],
    ): void {
        if ($trialDays > 0) {
            /** @var Model&Billable $billable */
            $billable->forceFill([
                'trial_ends_at' => now()->addDays($trialDays),
                'subscription_status' => SubscriptionStatus::Trial,
            ])->save();

            $this->local->handle($billable, $planCode, $interval, $addonCodes, 0);

            // Local activation flips status to Active — restore Trial state for the trial window.
            $billable->forceFill(['subscription_status' => SubscriptionStatus::Trial])->save();

            TrialStarted::dispatch($billable, $planCode, $trialDays);

            return;
        }

        $this->local->handle($billable, $planCode, $interval, $addonCodes, 0);
    }
}
