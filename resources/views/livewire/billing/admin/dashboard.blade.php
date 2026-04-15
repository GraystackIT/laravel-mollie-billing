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

<div class="space-y-6">
    <flux:heading size="xl">Billing Admin</flux:heading>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <flux:card>
            <flux:text size="xs" class="uppercase text-zinc-500">MRR</flux:text>
            <flux:heading size="lg" class="mt-1">{{ number_format($kpis['mrr'] / 100, 2) }}</flux:heading>
        </flux:card>
        <flux:card>
            <flux:text size="xs" class="uppercase text-zinc-500">ARR</flux:text>
            <flux:heading size="lg" class="mt-1">{{ number_format($kpis['arr'] / 100, 2) }}</flux:heading>
        </flux:card>
        <flux:card>
            <flux:text size="xs" class="uppercase text-zinc-500">Churn (30d)</flux:text>
            <flux:heading size="lg" class="mt-1">{{ number_format($kpis['churn'] * 100, 1) }}%</flux:heading>
        </flux:card>
        <flux:card>
            <flux:text size="xs" class="uppercase text-zinc-500">Trial → Paid</flux:text>
            <flux:heading size="lg" class="mt-1">{{ number_format($kpis['trial_conversion'] * 100, 1) }}%</flux:heading>
        </flux:card>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <a href="{{ route('billing.admin.past_due.index') }}" class="block">
            <flux:card class="hover:shadow-md transition">
                <flux:text size="xs" class="uppercase text-zinc-500">Past due</flux:text>
                <flux:heading size="lg" class="mt-1">{{ $pastDueCount }}</flux:heading>
            </flux:card>
        </a>
        <a href="{{ route('billing.admin.mismatches.index') }}" class="block">
            <flux:card class="hover:shadow-md transition">
                <flux:text size="xs" class="uppercase text-zinc-500">Country mismatches</flux:text>
                <flux:heading size="lg" class="mt-1">{{ $openMismatches }}</flux:heading>
            </flux:card>
        </a>
        <a href="{{ route('billing.admin.scheduled_changes.index') }}" class="block">
            <flux:card class="hover:shadow-md transition">
                <flux:text size="xs" class="uppercase text-zinc-500">Scheduled changes</flux:text>
                <flux:heading size="lg" class="mt-1">{{ $scheduledChanges }}</flux:heading>
            </flux:card>
        </a>
        <flux:card>
            <flux:text size="xs" class="uppercase text-zinc-500">Trials ending ≤ 3d</flux:text>
            <flux:heading size="lg" class="mt-1">{{ $trialsEndingSoon }}</flux:heading>
        </flux:card>
    </div>
</div>
