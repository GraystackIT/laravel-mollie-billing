<?php

use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    /** Locked: the credit always lands on the billable whose tab is open. */
    #[Locked]
    public mixed $billableId = null;
    public string $creditType = '';
    public int $creditUnits = 0;
    public string $creditReason = '';
    public ?string $flash = null;
    public ?string $error = null;

    // Container-injected parameter first: an optional parameter ahead of a required
    // one is deprecated in PHP 8.3. Livewire resolves mount arguments by name and
    // type, so the order is free.
    public function mount(SubscriptionCatalogInterface $catalog, mixed $billableId = null): void
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

    public function credit(WalletUsageService $service, SubscriptionCatalogInterface $catalog): void
    {
        $this->flash = $this->error = null;
        $b = $this->billable();
        if (! $b) return;
        if (! $this->creditType) {
            $this->error = __('billing::admin.billables.wallet.flash.pick_usage_type');
            return;
        }

        // An unknown usage type is not a no-op: resolveWallet() creates a wallet for
        // whatever slug it is handed, and nothing in the catalog will ever charge,
        // recharge or reset that wallet again.
        if (! in_array($this->creditType, $catalog->allUsageTypes(), true)) {
            $this->error = __('billing::admin.billables.wallet.flash.unknown_usage_type', ['type' => $this->creditType]);
            return;
        }

        if ($this->creditUnits <= 0) {
            $this->error = __('billing::admin.billables.wallet.flash.units_positive');
            return;
        }
        $service->credit($b, $this->creditType, $this->creditUnits, $this->creditReason ?: __('billing::admin.billables.wallet.default_reason'));

        // Register as purchased balance, exactly like the Credits-coupon path: a
        // renewal with rollover disabled rebuilds the wallet from quota + purchased
        // remaining, so an unregistered goodwill credit would silently evaporate.
        if ($b instanceof Model) {
            $wallet = $b->getWallet($this->creditType);
            if ($wallet !== null) {
                WalletUsageService::addPurchasedBalance($wallet, $this->creditUnits);
            }
        }

        $this->flash = __('billing::admin.billables.wallet.flash.credited', [
            'units' => $this->creditUnits,
            'type' => $this->creditType,
        ]);
        $this->reset(['creditUnits', 'creditReason']);
    }
};

?>

<div class="space-y-4">
    <x-mollie-billing::admin.flash :success="$flash" :error="$error" />

    @php $b = $this->billable(); @endphp
    @if ($b)
        <div class="grid gap-6 lg:grid-cols-2">
            <x-mollie-billing::admin.section :title="__('billing::admin.billables.wallet.balances_title')" :description="__('billing::admin.billables.wallet.balances_description')">
                @php $wallets = $b->wallets ?? []; @endphp
                @if (empty($wallets) || (is_countable($wallets) && count($wallets) === 0))
                    <x-mollie-billing::admin.empty
                        icon="wallet"
                        :title="__('billing::admin.billables.wallet.empty_title')"
                        :description="__('billing::admin.billables.wallet.empty_description')"
                    />
                @else
                    <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($wallets as $wallet)
                            <li class="flex items-center justify-between py-2">
                                <div>
                                    <div class="font-mono text-sm">{{ $wallet->slug }}</div>
                                    @if ($wallet->balanceInt < 0)
                                        <flux:text size="xs" class="text-red-600 dark:text-red-400">{{ __('billing::admin.billables.wallet.overage') }}</flux:text>
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

            <x-mollie-billing::admin.section :title="__('billing::admin.billables.wallet.credit_title')" :description="__('billing::admin.billables.wallet.credit_description')">
                <form wire:submit="credit" class="space-y-3">
                    <flux:select wire:model="creditType" :label="__('billing::admin.billables.wallet.usage_type')" :description="__('billing::admin.billables.wallet.usage_type_description')">
                        @foreach ($usageTypes as $type)
                            <flux:select.option value="{{ $type }}">{{ $type }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input
                        type="number"
                        wire:model="creditUnits"
                        :label="__('billing::admin.billables.wallet.units')"
                        :description="__('billing::admin.billables.wallet.units_description')"
                        placeholder="100"
                        min="1"
                    />
                    <flux:input
                        wire:model="creditReason"
                        :label="__('billing::admin.billables.wallet.reason')"
                        :description="__('billing::admin.billables.wallet.reason_description')"
                        :placeholder="__('billing::admin.billables.wallet.reason_placeholder')"
                    />
                    <flux:button type="submit" size="sm" variant="primary" icon="plus">{{ __('billing::admin.billables.wallet.submit') }}</flux:button>
                </form>
            </x-mollie-billing::admin.section>
        </div>
    @endif
</div>
