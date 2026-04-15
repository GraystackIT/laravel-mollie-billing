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

<div class="p-6 space-y-6">
    <flux:heading size="xl">Billing Admin</flux:heading>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="p-4 rounded-lg border bg-white dark:bg-zinc-800">
            <div class="text-xs uppercase text-zinc-500">MRR</div>
            <div class="text-xl font-semibold mt-1">{{ number_format($kpis['mrr'] / 100, 2) }}</div>
        </div>
        <div class="p-4 rounded-lg border bg-white dark:bg-zinc-800">
            <div class="text-xs uppercase text-zinc-500">ARR</div>
            <div class="text-xl font-semibold mt-1">{{ number_format($kpis['arr'] / 100, 2) }}</div>
        </div>
        <div class="p-4 rounded-lg border bg-white dark:bg-zinc-800">
            <div class="text-xs uppercase text-zinc-500">Churn (30d)</div>
            <div class="text-xl font-semibold mt-1">{{ number_format($kpis['churn'] * 100, 1) }}%</div>
        </div>
        <div class="p-4 rounded-lg border bg-white dark:bg-zinc-800">
            <div class="text-xs uppercase text-zinc-500">Trial → Paid</div>
            <div class="text-xl font-semibold mt-1">{{ number_format($kpis['trial_conversion'] * 100, 1) }}%</div>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <a href="{{ route('billing.admin.past_due.index') }}" class="p-4 rounded-lg border bg-white dark:bg-zinc-800 hover:shadow">
            <div class="text-xs uppercase text-zinc-500">Past due</div>
            <div class="text-xl font-semibold mt-1">{{ $pastDueCount }}</div>
        </a>
        <a href="{{ route('billing.admin.mismatches.index') }}" class="p-4 rounded-lg border bg-white dark:bg-zinc-800 hover:shadow">
            <div class="text-xs uppercase text-zinc-500">Country mismatches</div>
            <div class="text-xl font-semibold mt-1">{{ $openMismatches }}</div>
        </a>
        <a href="{{ route('billing.admin.scheduled_changes.index') }}" class="p-4 rounded-lg border bg-white dark:bg-zinc-800 hover:shadow">
            <div class="text-xs uppercase text-zinc-500">Scheduled changes</div>
            <div class="text-xl font-semibold mt-1">{{ $scheduledChanges }}</div>
        </a>
        <div class="p-4 rounded-lg border bg-white dark:bg-zinc-800">
            <div class="text-xs uppercase text-zinc-500">Trials ending ≤ 3d</div>
            <div class="text-xl font-semibold mt-1">{{ $trialsEndingSoon }}</div>
        </div>
    </div>

    <nav class="flex flex-wrap gap-2 text-sm">
        <a href="{{ route('billing.admin.coupons.index') }}" class="px-3 py-1.5 rounded border">Coupons</a>
        <a href="{{ route('billing.admin.billables.index') }}" class="px-3 py-1.5 rounded border">Billables</a>
        <a href="{{ route('billing.admin.refunds.index') }}" class="px-3 py-1.5 rounded border">Refunds</a>
        <a href="{{ route('billing.admin.oss.index') }}" class="px-3 py-1.5 rounded border">OSS export</a>
        <a href="{{ route('billing.admin.bulk.index') }}" class="px-3 py-1.5 rounded border">Bulk actions</a>
    </nav>
</div>
