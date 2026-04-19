<?php

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use Livewire\Attributes\Reactive;
use Livewire\Component;

new class extends Component {
    public string $type = '';

    public function mount(string $type): void
    {
        $this->type = $type;
    }

    private function resolveBillable(): ?Billable
    {
        return MollieBilling::resolveBillable(request());
    }
};

?>

<div class="rounded-lg border border-zinc-200/80 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/2">
    @php
        $billable = $this->resolveBillable();
        $included = $billable ? $billable->includedBillingQuota($type) : 0;
        $used = $billable ? $billable->usedBillingQuota($type) : 0;
        $percent = $included > 0 ? min(100, (int) round($used / $included * 100)) : 0;
        $threshold = (int) config('mollie-billing.usage_threshold_percent', 80);
        $color = $percent >= 100 ? 'bg-red-500' : ($percent >= $threshold ? 'bg-amber-500' : 'bg-emerald-500');
    @endphp
    <div class="flex items-center justify-between mb-2">
        <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ ucfirst($type) }}</span>
        <span class="text-zinc-500 dark:text-zinc-400 text-xs tabular-nums">{{ $used }} / {{ $included }}</span>
    </div>
    <div class="h-2 rounded-full bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
        <div class="h-full rounded-full transition-all duration-500 {{ $color }}" style="width: {{ $percent }}%"></div>
    </div>
</div>
