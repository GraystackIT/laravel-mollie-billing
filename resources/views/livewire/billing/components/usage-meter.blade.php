<?php

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use Livewire\Attributes\Reactive;
use Livewire\Component;

new class extends Component {
    public string $type = '';
    public ?Billable $billable = null;

    public function mount(string $type): void
    {
        $this->type = $type;
        $this->billable = MollieBilling::resolveBillable(request());
    }
};

?>

<div class="p-3 border rounded text-sm">
    @php
        $included = $billable ? $billable->includedBillingQuota($type) : 0;
        $used = $billable ? $billable->usedBillingQuota($type) : 0;
        $percent = $included > 0 ? min(100, (int) round($used / $included * 100)) : 0;
        $threshold = (int) config('mollie-billing.usage_threshold_percent', 80);
        $color = $percent >= $threshold ? 'bg-amber-500' : 'bg-indigo-600';
    @endphp
    <div class="flex items-center justify-between mb-1">
        <span class="font-medium">{{ ucfirst($type) }}</span>
        <span class="text-zinc-500 text-xs">{{ $used }} / {{ $included }}</span>
    </div>
    <div class="h-2 rounded bg-zinc-200 overflow-hidden">
        <div class="h-full {{ $color }}" style="width: {{ $percent }}%"></div>
    </div>
</div>
