<?php

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use Livewire\Component;

new class extends Component {
    public ?Billable $billable = null;
    public string $applyAt = 'immediate';
    public array $preview = [];
    public ?string $selectedPlan = null;
    public string $selectedInterval = 'monthly';
    public ?string $flash = null;

    public function mount(): void
    {
        $this->billable = MollieBilling::resolveBillable(request());
    }

    public function previewFor(string $planCode, string $interval, PreviewService $service): void
    {
        $this->selectedPlan = $planCode;
        $this->selectedInterval = $interval;
        if ($this->billable) {
            $this->preview = $service->previewPlanChange($this->billable, $planCode, $interval);
        }
    }

    public function commit(UpdateSubscription $service): void
    {
        if (! $this->billable || ! $this->selectedPlan) return;
        try {
            $service->update($this->billable, [
                'plan_code' => $this->selectedPlan,
                'interval' => $this->selectedInterval,
                'apply_at' => $this->applyAt,
            ]);
            $this->flash = 'Plan changed.';
        } catch (\Throwable $e) {
            $this->flash = 'Error: '.$e->getMessage();
        }
    }

    public function with(): array
    {
        return ['plans' => app(SubscriptionCatalogInterface::class)->allPlans()];
    }
};

?>

<div class="p-6 space-y-4">
    <h1 class="text-xl font-semibold">Change plan</h1>
    @if ($flash)<div class="p-3 rounded bg-green-50 border border-green-200 text-sm">{{ $flash }}</div>@endif
    <div class="flex gap-3 text-sm">
        <label><input type="radio" wire:model.live="applyAt" value="immediate"> Apply immediately</label>
        <label><input type="radio" wire:model.live="applyAt" value="end_of_period"> Apply at period end</label>
    </div>
    <div class="flex gap-3 text-sm">
        <label><input type="radio" wire:model.live="selectedInterval" value="monthly"> Monthly</label>
        <label><input type="radio" wire:model.live="selectedInterval" value="yearly"> Yearly</label>
    </div>
    <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
        @foreach ($plans as $code)
            @php $cat = app(SubscriptionCatalogInterface::class); @endphp
            <div class="p-4 border rounded space-y-1">
                <div class="font-semibold">{{ $cat->planName($code) ?? $code }}</div>
                <div class="text-sm text-zinc-600">{{ number_format($cat->basePriceNet($code, $selectedInterval) / 100, 2) }} / {{ $selectedInterval }}</div>
                <button wire:click="previewFor('{{ $code }}', '{{ $selectedInterval }}')" class="px-3 py-1 border rounded text-sm">Preview</button>
            </div>
        @endforeach
    </div>

    @if ($selectedPlan && !empty($preview))
        <section class="p-4 border rounded bg-zinc-50 space-y-1 text-sm">
            <div class="font-semibold">Preview for {{ $selectedPlan }} ({{ $selectedInterval }})</div>
            <pre class="text-xs overflow-x-auto">{{ json_encode($preview, JSON_PRETTY_PRINT) }}</pre>
            <button wire:click="commit" class="px-3 py-1.5 rounded bg-indigo-600 text-white">
                {{ $applyAt === 'end_of_period' ? 'Schedule change' : 'Apply now' }}
            </button>
        </section>
    @endif
</div>
