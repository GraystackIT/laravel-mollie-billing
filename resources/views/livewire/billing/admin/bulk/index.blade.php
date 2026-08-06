<?php

use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

new class extends Component {
    public string $planFilter = '';
    public int $creditUnits = 0;
    public string $creditType = '';
    public string $creditReason = '';
    public int $trialDays = 7;
    public ?string $flash = null;
    public ?string $error = null;

    /**
     * Upper bounds for a batch that touches every billable on the plan at once.
     * They exist against the mistyped order of magnitude, not against the operator:
     * a credit is real balance the customer can spend, and a trial extension PATCHes
     * every matching Mollie subscription's start date.
     */
    private const MAX_CREDIT_UNITS = 1_000_000;

    private const MAX_TRIAL_DAYS = 365;

    private const MAX_REASON_LENGTH = 200;

    public function mount(SubscriptionCatalogInterface $catalog): void
    {
        $plans = $catalog->allPlans();
        $usageTypes = $catalog->allUsageTypes();

        $this->planFilter = $plans[0] ?? '';
        $this->creditType = $usageTypes[0] ?? '';
    }

    public function with(SubscriptionCatalogInterface $catalog): array
    {
        return [
            'planOptions' => collect($catalog->allPlans())
                ->mapWithKeys(fn (string $code) => [$code => $catalog->planName($code) ?: $code])
                ->all(),
            'usageTypes' => $catalog->allUsageTypes(),
            'maxCreditUnits' => self::MAX_CREDIT_UNITS,
            'maxTrialDays' => self::MAX_TRIAL_DAYS,
            'maxReasonLength' => self::MAX_REASON_LENGTH,
        ];
    }

    public function creditWallets(WalletUsageService $service, SubscriptionCatalogInterface $catalog): void
    {
        $this->flash = $this->error = null;
        $class = config('mollie-billing.billable_model');
        if (! $class) { $this->error = __('billing::admin.bulk.no_model'); return; }
        if ($this->creditUnits <= 0) { $this->error = __('billing::admin.bulk.units_positive'); return; }
        if ($this->creditUnits > self::MAX_CREDIT_UNITS) {
            $this->error = __('billing::admin.bulk.units_too_large', ['max' => self::MAX_CREDIT_UNITS]);
            return;
        }
        if ($this->creditType === '') { $this->error = __('billing::admin.bulk.select_usage_type'); return; }

        // An unknown usage type is not a no-op: resolveWallet() creates a wallet for
        // whatever slug it is handed, and nothing in the catalog will ever charge,
        // recharge or reset that wallet again.
        if (! in_array($this->creditType, $catalog->allUsageTypes(), true)) {
            $this->error = __('billing::admin.bulk.unknown_usage_type', ['type' => $this->creditType]);
            return;
        }

        $reason = trim($this->creditReason);
        if ($reason === '') { $this->error = __('billing::admin.bulk.reason_required'); return; }
        if (mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            $this->error = __('billing::admin.bulk.reason_too_long', ['max' => self::MAX_REASON_LENGTH]);
            return;
        }

        [$count, $failed] = $this->runBatch(
            $class::query()->where('subscription_plan_code', $this->planFilter),
            function ($b) use ($service, $reason): void {
                $service->credit($b, $this->creditType, $this->creditUnits, $reason);

                // Register as purchased balance, exactly like the Credits-coupon path:
                // otherwise a renewal with rollover disabled discards the credit.
                if ($b instanceof Model) {
                    $wallet = $b->getWallet($this->creditType);
                    if ($wallet !== null) {
                        WalletUsageService::addPurchasedBalance($wallet, $this->creditUnits);
                    }
                }
            },
        );

        $this->report($count, $failed, 'credited', 'credited_partial', 'no_matching_billables');
    }

    public function extendTrials(): void
    {
        $this->flash = $this->error = null;
        $class = config('mollie-billing.billable_model');
        if (! $class) { $this->error = __('billing::admin.bulk.no_model'); return; }

        if ($this->trialDays < 1) { $this->error = __('billing::admin.bulk.trial_days_positive'); return; }
        if ($this->trialDays > self::MAX_TRIAL_DAYS) {
            $this->error = __('billing::admin.bulk.trial_days_too_large', ['max' => self::MAX_TRIAL_DAYS]);
            return;
        }

        $until = BillingTime::nowUtc()->addDays($this->trialDays);

        // Trials only. extendBillingTrialUntil() flips the status to `trial` and, for
        // Mollie-source subscriptions, PATCHes the live subscription's startDate — run
        // unfiltered it rewrites the billing schedule of every paying customer on the plan.
        $query = $class::query()
            ->where('subscription_plan_code', $this->planFilter)
            ->where(fn ($w) => $w
                ->where('subscription_status', SubscriptionStatus::Trial->value)
                ->orWhere('trial_ends_at', '>', BillingTime::nowUtc()));

        [$count, $failed] = $this->runBatch($query, fn ($b) => $b->extendBillingTrialUntil($until));

        $this->report($count, $failed, 'extended', 'extended_partial', 'no_matching_trials');
    }

    /**
     * Walk the batch row by row. Each row does its own Mollie roundtrip, so one
     * failure must not abort the rest — and the caller has to be able to say how
     * far it got instead of leaving the operator guessing.
     *
     * @return array{0: int, 1: int} applied and failed row counts
     */
    private function runBatch(mixed $query, callable $apply): array
    {
        $count = 0;
        $failed = 0;

        $query->chunkById(100, function ($rows) use ($apply, &$count, &$failed): void {
            foreach ($rows as $b) {
                try {
                    $apply($b);
                    $count++;
                } catch (\Throwable $e) {
                    report($e);
                    $failed++;
                }
            }
        });

        return [$count, $failed];
    }

    private function report(int $count, int $failed, string $okKey, string $partialKey, string $emptyKey): void
    {
        if ($failed > 0) {
            $this->error = __('billing::admin.bulk.'.$partialKey, ['count' => $count, 'failed' => $failed]);

            return;
        }

        if ($count === 0) {
            $this->error = __('billing::admin.bulk.'.$emptyKey);

            return;
        }

        $this->flash = __('billing::admin.bulk.'.$okKey, ['count' => $count]);
    }
};

?>

<div class="space-y-6">
    <x-mollie-billing::admin.page-header
        :title="__('billing::admin.bulk.title')"
        :subtitle="__('billing::admin.bulk.subtitle')"
    />

    <x-mollie-billing::admin.flash :success="$flash" :error="$error" />

    <x-mollie-billing::admin.section :title="__('billing::admin.bulk.target_title')" :description="__('billing::admin.bulk.target_description')">
        <flux:select wire:model.live="planFilter" :label="__('billing::admin.common.plan')">
            @foreach ($planOptions as $code => $name)
                <flux:select.option value="{{ $code }}">{{ $name }}</flux:select.option>
            @endforeach
        </flux:select>
    </x-mollie-billing::admin.section>

    <x-mollie-billing::admin.section :title="__('billing::admin.bulk.credit_title')" :description="__('billing::admin.bulk.credit_description')">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <flux:select wire:model="creditType" :label="__('billing::admin.bulk.usage_type')" :description="__('billing::admin.bulk.usage_type_description')">
                @foreach ($usageTypes as $type)
                    <flux:select.option value="{{ $type }}">{{ $type }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input
                type="number"
                wire:model="creditUnits"
                :label="__('billing::admin.bulk.units')"
                :description="__('billing::admin.bulk.units_description')"
                placeholder="100"
                min="1"
                :max="$maxCreditUnits"
            />
        </div>

        <flux:input
            wire:model="creditReason"
            :label="__('billing::admin.bulk.reason')"
            :badge="__('billing::admin.common.required')"
            :description="__('billing::admin.bulk.reason_description')"
            :placeholder="__('billing::admin.bulk.reason_placeholder')"
            :maxlength="$maxReasonLength"
        />

        <div class="flex justify-end">
            <flux:button wire:click="creditWallets" size="sm" variant="primary" icon="plus" wire:confirm="{{ __('billing::admin.bulk.credit_all_confirm') }}">{{ __('billing::admin.bulk.credit_all') }}</flux:button>
        </div>
    </x-mollie-billing::admin.section>

    <x-mollie-billing::admin.section :title="__('billing::admin.bulk.trials_title')" :description="__('billing::admin.bulk.trials_description')">
        <flux:input
            type="number"
            wire:model="trialDays"
            :label="__('billing::admin.bulk.trial_days')"
            :description="__('billing::admin.bulk.trial_days_description')"
            placeholder="7"
            :suffix="__('billing::admin.common.days')"
            min="1"
            :max="$maxTrialDays"
        />

        <div class="flex justify-end">
            <flux:button wire:click="extendTrials" size="sm" variant="primary" icon="clock" wire:confirm="{{ __('billing::admin.bulk.extend_all_confirm') }}">{{ __('billing::admin.bulk.extend_all') }}</flux:button>
        </div>
    </x-mollie-billing::admin.section>
</div>
