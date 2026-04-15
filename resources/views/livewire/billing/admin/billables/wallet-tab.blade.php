<?php

use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use Livewire\Component;

new class extends Component {
    public mixed $billableId = null;
    public string $creditType = '';
    public int $creditUnits = 0;
    public string $creditReason = '';
    public ?string $flash = null;

    public function mount(mixed $billableId = null, SubscriptionCatalogInterface $catalog): void
    {
        $this->billableId = $billableId;
        $this->creditType = $catalog->allUsageTypes()[0] ?? '';
    }

    public function with(SubscriptionCatalogInterface $catalog): array
    {
        return ['usageTypes' => $catalog->allUsageTypes()];
    }

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
        $this->reset(['creditUnits', 'creditReason']);
    }
};

?>

<div class="space-y-3 text-sm">
    @if ($flash)
        <flux:callout variant="success" inline>{{ $flash }}</flux:callout>
    @endif
    @php $b = $this->billable(); @endphp
    @if ($b)
        <ul class="space-y-1">
            @forelse ($b->wallets ?? [] as $wallet)
                <li>{{ $wallet->slug }}: <strong>{{ $wallet->balanceInt }}</strong></li>
            @empty
                <li class="text-zinc-500">No wallets yet.</li>
            @endforelse
        </ul>
        <flux:card>
            <form wire:submit="credit" class="space-y-3">
                <flux:heading size="md">Credit wallet</flux:heading>
                <flux:select wire:model="creditType" label="Usage type">
                    @foreach ($usageTypes as $type)
                        <flux:select.option value="{{ $type }}">{{ $type }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input type="number" wire:model="creditUnits" label="Units" />
                <flux:input wire:model="creditReason" label="Reason" />
                <flux:button type="submit" size="sm" variant="primary">Credit</flux:button>
            </form>
        </flux:card>
    @endif
</div>
