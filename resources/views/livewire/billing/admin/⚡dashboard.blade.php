<?php

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Support\AdminKpiService;
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
        title="Billing Admin"
        subtitle="Revenue and operational overview at a glance."
    />

    <section class="space-y-3">
        <flux:heading size="sm" class="uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Revenue</flux:heading>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-mollie-billing::admin.stat
                label="MRR"
                :value="'€'.number_format($kpis['mrr'] / 100, 2)"
                icon="arrow-trending-up"
                hint="Monthly recurring revenue"
            />
            <x-mollie-billing::admin.stat
                label="ARR"
                :value="'€'.number_format($kpis['arr'] / 100, 2)"
                icon="chart-bar"
                hint="Annualised recurring revenue"
            />
            <x-mollie-billing::admin.stat
                label="Churn (30d)"
                :value="number_format($kpis['churn'] * 100, 1).'%'"
                icon="arrow-trending-down"
                :tone="$kpis['churn'] > 0.05 ? 'danger' : null"
            />
            <x-mollie-billing::admin.stat
                label="Trial → Paid"
                :value="number_format($kpis['trial_conversion'] * 100, 1).'%'"
                icon="check-badge"
                hint="Last 90 days"
            />
        </div>
    </section>

    <section class="space-y-3">
        <flux:heading size="sm" class="uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Operations</flux:heading>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-mollie-billing::admin.stat
                label="Past due"
                :value="$pastDueCount"
                :href="route('billing.admin.past_due.index')"
                icon="exclamation-triangle"
                :tone="$pastDueCount > 0 ? 'danger' : null"
                hint="Failed recurring payments"
            />
            <x-mollie-billing::admin.stat
                label="Country mismatches"
                :value="$openMismatches"
                :href="route('billing.admin.mismatches.index')"
                icon="globe-europe-africa"
                :tone="$openMismatches > 0 ? 'warning' : null"
                hint="Pending manual review"
            />
            <x-mollie-billing::admin.stat
                label="Scheduled changes"
                :value="$scheduledChanges"
                :href="route('billing.admin.scheduled_changes.index')"
                icon="calendar"
                hint="Within the next 7 days"
            />
            <x-mollie-billing::admin.stat
                label="Trials ending"
                :value="$trialsEndingSoon"
                icon="clock"
                :tone="$trialsEndingSoon > 0 ? 'warning' : null"
                hint="Within the next 3 days"
            />
        </div>
    </section>
</div>
