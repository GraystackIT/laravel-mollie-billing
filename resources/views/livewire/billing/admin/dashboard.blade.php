<?php

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Support\AdminKpiService;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingRoute;
use Livewire\Component;

new class extends Component {
    public array $kpis = [];
    public int $pastDueCount = 0;
    public int $openMismatches = 0;
    public int $scheduledChanges = 0;
    public int $trialsEndingSoon = 0;

    public function mount(AdminKpiService $kpiService): void
    {
        $billableClass = config('mollie-billing.billable_model');

        $this->kpis = [
            'mrr' => $kpiService->mrr(),
            'arr' => $kpiService->arr(),
            'active' => $kpiService->activeSubscriptionsByStatus(),
            'churn' => $kpiService->churnRate(30),
            'trial_conversion' => $kpiService->trialConversionRate(90),
            'open_overage' => $kpiService->openOverageCharges(),
        ];

        $this->openMismatches = BillingCountryMismatch::query()
            ->where('status', CountryMismatchStatus::Pending)
            ->count();

        if ($billableClass && class_exists($billableClass)) {
            $this->pastDueCount = (int) $billableClass::query()
                ->where('subscription_status', 'past_due')->count();

            $this->scheduledChanges = (int) $billableClass::query()
                ->whereNotNull('scheduled_change_at')
                ->where('scheduled_change_at', '<=', now()->addWeek())
                ->count();

            $this->trialsEndingSoon = (int) $billableClass::query()
                ->whereBetween('trial_ends_at', [now(), now()->addDays(3)])
                ->count();
        }
    }
};

?>

<div class="space-y-8">
    <x-mollie-billing::admin.page-header
        :title="__('billing::admin.dashboard.title')"
        :subtitle="__('billing::admin.dashboard.subtitle')"
    />

    <section class="space-y-3">
        <flux:heading size="sm" class="uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('billing::admin.dashboard.revenue') }}</flux:heading>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-mollie-billing::admin.stat
                :label="__('billing::admin.dashboard.mrr')"
                :value="BillingMoney::format((int) $kpis['mrr'])"
                icon="arrow-trending-up"
                :hint="__('billing::admin.dashboard.mrr_hint')"
            />
            <x-mollie-billing::admin.stat
                :label="__('billing::admin.dashboard.arr')"
                :value="BillingMoney::format((int) $kpis['arr'])"
                icon="chart-bar"
                :hint="__('billing::admin.dashboard.arr_hint')"
            />
            <x-mollie-billing::admin.stat
                :label="__('billing::admin.dashboard.churn')"
                :value="number_format($kpis['churn'] * 100, 1).'%'"
                icon="arrow-trending-down"
                :tone="$kpis['churn'] > 0.05 ? 'danger' : null"
            />
            <x-mollie-billing::admin.stat
                :label="__('billing::admin.dashboard.trial_conversion')"
                :value="number_format($kpis['trial_conversion'] * 100, 1).'%'"
                icon="check-badge"
                :hint="__('billing::admin.dashboard.trial_conversion_hint')"
            />
        </div>
    </section>

    <section class="space-y-3">
        <flux:heading size="sm" class="uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('billing::admin.dashboard.operations') }}</flux:heading>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-mollie-billing::admin.stat
                :label="__('billing::admin.dashboard.past_due')"
                :value="$pastDueCount"
                :href="route(BillingRoute::admin('past_due.index'))"
                icon="exclamation-triangle"
                :tone="$pastDueCount > 0 ? 'danger' : null"
                :hint="__('billing::admin.dashboard.past_due_hint')"
            />
            <x-mollie-billing::admin.stat
                :label="__('billing::admin.dashboard.mismatches')"
                :value="$openMismatches"
                :href="route(BillingRoute::admin('mismatches.index'))"
                icon="globe-europe-africa"
                :tone="$openMismatches > 0 ? 'warning' : null"
                :hint="__('billing::admin.dashboard.mismatches_hint')"
            />
            <x-mollie-billing::admin.stat
                :label="__('billing::admin.dashboard.scheduled_changes')"
                :value="$scheduledChanges"
                :href="route(BillingRoute::admin('scheduled_changes.index'))"
                icon="calendar"
                :hint="__('billing::admin.dashboard.scheduled_changes_hint')"
            />
            <x-mollie-billing::admin.stat
                :label="__('billing::admin.dashboard.trials_ending')"
                :value="$trialsEndingSoon"
                icon="clock"
                :tone="$trialsEndingSoon > 0 ? 'warning' : null"
                :hint="__('billing::admin.dashboard.trials_ending_hint')"
            />
        </div>
    </section>
</div>
