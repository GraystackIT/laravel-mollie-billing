@php

use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use Livewire\Component;

new class extends Component {
    public int $step = 1;
    public ?string $planCode = null;
    public string $interval = 'monthly';
    public array $addonCodes = [];
    public int $seats = 1;

    public function next(): void { $this->step = min(4, $this->step + 1); }
    public function back(): void { $this->step = max(1, $this->step - 1); }

    public function proceed(): void
    {
        session()->put('billing.checkout_draft', [
            'plan_code' => $this->planCode,
            'interval' => $this->interval,
            'addon_codes' => $this->addonCodes,
            'extra_seats' => max(0, $this->seats - 1),
        ]);
        $this->redirectRoute('billing.checkout');
    }

    public function with(): array
    {
        $cat = app(SubscriptionCatalogInterface::class);
        return [
            'plans' => $cat->allPlans(),
            'catalog' => $cat,
        ];
    }
};

@endphp

<div class="p-6 space-y-4 max-w-3xl">
    <h1 class="text-xl font-semibold">Choose your plan</h1>
    <div class="text-sm text-zinc-500">Step {{ $step }} of 4</div>

    @if ($step === 1)
        <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
            @foreach ($plans as $code)
                <label class="p-4 border rounded cursor-pointer {{ $planCode === $code ? 'ring-2 ring-indigo-600' : '' }}">
                    <input type="radio" wire:model.live="planCode" value="{{ $code }}" class="sr-only">
                    <div class="font-semibold">{{ $catalog->planName($code) ?? $code }}</div>
                    <div class="text-sm text-zinc-600 mt-1">{{ number_format($catalog->basePriceNet($code, $interval) / 100, 2) }} / {{ $interval }}</div>
                </label>
            @endforeach
        </div>
        <div class="flex gap-3 text-sm">
            <label><input type="radio" wire:model.live="interval" value="monthly"> Monthly</label>
            <label><input type="radio" wire:model.live="interval" value="yearly"> Yearly</label>
        </div>
    @elseif ($step === 2)
        <h2 class="font-medium">Addons (optional)</h2>
        <p class="text-sm text-zinc-500">Select addons to enable.</p>
        @foreach (config('mollie-billing-plans.addons', []) as $code => $cfg)
            <label class="block p-3 border rounded">
                <input type="checkbox" wire:model.live="addonCodes" value="{{ $code }}"> {{ $cfg['name'] ?? $code }}
            </label>
        @endforeach
    @elseif ($step === 3)
        <div>
            <label>Seats</label>
            <input type="number" min="1" wire:model.live="seats" class="border rounded px-2 py-1 w-24">
        </div>
    @else
        <div class="p-4 border rounded bg-zinc-50 text-sm space-y-1">
            <div><strong>Plan:</strong> {{ $planCode }} ({{ $interval }})</div>
            <div><strong>Addons:</strong> {{ implode(', ', $addonCodes) ?: '—' }}</div>
            <div><strong>Seats:</strong> {{ $seats }}</div>
            <button wire:click="proceed" class="px-4 py-2 rounded bg-indigo-600 text-white mt-3">Continue to billing details</button>
        </div>
    @endif

    <div class="flex gap-2 pt-3">
        <button wire:click="back" @disabled($step === 1) class="px-3 py-1 border rounded">Back</button>
        <button wire:click="next" @disabled($step === 4 || ! $planCode) class="px-3 py-1 border rounded">Next</button>
    </div>
</div>
