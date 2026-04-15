@php

use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use Livewire\Component;

new class extends Component {
    public mixed $billableId = null;
    public string $creditType = '';
    public int $creditUnits = 0;
    public string $creditReason = '';
    public ?string $flash = null;

    public function mount(mixed $billableId = null): void { $this->billableId = $billableId; }

    public function billable(): mixed
    {
        $class = config('mollie-billing.billable_model');
        return $class ? $class::find($this->billableId) : null;
    }

    public function credit(RefundInvoiceService $service): void
    {
        $b = $this->billable();
        if (! $b || ! $this->creditType || $this->creditUnits <= 0) return;
        $service->creditWalletOnly($b, $this->creditType, $this->creditUnits, $this->creditReason ?: 'admin credit');
        $this->flash = "Credited {$this->creditUnits} {$this->creditType}.";
        $this->reset(['creditType', 'creditUnits', 'creditReason']);
    }
};

@endphp

<div class="space-y-3 text-sm">
    @if ($flash)<div class="p-2 rounded bg-green-50 border border-green-200">{{ $flash }}</div>@endif
    @php $b = $this->billable(); @endphp
    @if ($b)
        <ul class="space-y-1">
            @forelse ($b->wallets ?? [] as $wallet)
                <li>{{ $wallet->slug }}: <strong>{{ $wallet->balanceInt }}</strong></li>
            @empty
                <li class="text-zinc-500">No wallets yet.</li>
            @endforelse
        </ul>
        <form wire:submit="credit" class="p-3 border rounded space-y-2 bg-zinc-50">
            <h3 class="font-medium">Credit wallet</h3>
            <input wire:model="creditType" placeholder="Usage type (e.g. tokens)" class="border rounded px-2 py-1 w-full">
            <input type="number" wire:model="creditUnits" placeholder="Units" class="border rounded px-2 py-1 w-full">
            <input wire:model="creditReason" placeholder="Reason" class="border rounded px-2 py-1 w-full">
            <flux:button size="sm" variant="primary">Credit</flux:button>
        </form>
    @endif
</div>
