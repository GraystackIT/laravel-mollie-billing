<?php

use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use Livewire\Component;

new class extends Component {
    public mixed $billableId = null;
    public string $creditType = '';
    public int $creditUnits = 0;
    public string $creditReason = '';
    public ?string $flash = null;
    public ?string $error = null;

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

    public function credit(WalletUsageService $service): void
    {
        $this->flash = $this->error = null;
        $b = $this->billable();
        if (! $b) return;
        if (! $this->creditType) {
            $this->error = 'Pick a usage type.';
            return;
        }
        if ($this->creditUnits <= 0) {
            $this->error = 'Units must be greater than zero.';
            return;
        }
        $service->credit($b, $this->creditType, $this->creditUnits, $this->creditReason ?: 'admin credit');
        $this->flash = "Credited {$this->creditUnits} {$this->creditType}.";
        $this->reset(['creditUnits', 'creditReason']);
    }
};

?>

<div class="space-y-4">
    <x-mollie-billing::admin.flash :success="$flash" :error="$error" />

    @php $b = $this->billable(); @endphp
    @if ($b)
        <div class="grid gap-6 lg:grid-cols-2">
            <x-mollie-billing::admin.section title="Balances" description="Usage-type wallets held by this billable.">
                @php $wallets = $b->wallets ?? []; @endphp
                @if (empty($wallets) || (is_countable($wallets) && count($wallets) === 0))
                    <x-mollie-billing::admin.empty
                        icon="wallet"
                        title="No wallets yet"
                        description="Wallets are created automatically on the first usage event."
                    />
                @else
                    <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($wallets as $wallet)
                            <li class="flex items-center justify-between py-2">
                                <div>
                                    <div class="font-mono text-sm">{{ $wallet->slug }}</div>
                                    @if ($wallet->balanceInt < 0)
                                        <flux:text size="xs" class="text-red-600 dark:text-red-400">Overage — balance is negative</flux:text>
                                    @endif
                                </div>
                                <flux:badge size="sm" :color="$wallet->balanceInt < 0 ? 'red' : ($wallet->balanceInt > 0 ? 'emerald' : 'zinc')" class="tabular-nums">
                                    {{ number_format($wallet->balanceInt) }}
                                </flux:badge>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-mollie-billing::admin.section>

            <x-mollie-billing::admin.section title="Credit wallet" description="Add units to the billable's wallet without charging.">
                <form wire:submit="credit" class="space-y-3">
                    <flux:select wire:model="creditType" label="Usage type" description="Which wallet to credit.">
                        @foreach ($usageTypes as $type)
                            <flux:select.option value="{{ $type }}">{{ $type }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input
                        type="number"
                        wire:model="creditUnits"
                        label="Units"
                        description="Whole units to add. Must be greater than zero."
                        placeholder="100"
                        min="1"
                    />
                    <flux:input
                        wire:model="creditReason"
                        label="Reason"
                        description="Shown in the audit log. Defaults to &quot;admin credit&quot;."
                        placeholder="Goodwill credit"
                    />
                    <flux:button type="submit" size="sm" variant="primary" icon="plus">Credit</flux:button>
                </form>
            </x-mollie-billing::admin.section>
        </div>
    @endif
</div>
