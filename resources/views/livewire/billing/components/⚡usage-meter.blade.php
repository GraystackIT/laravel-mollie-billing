<?php

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use Livewire\Component;

new class extends Component {
    public string $type = '';

    public string $label = '';
    public ?int $propIncluded = null;
    public ?int $propBalance = null;
    public ?int $propPurchasedBalance = null;

    public function mount(
        string $type,
        ?string $label = null,
        ?int $included = null,
        ?int $balance = null,
        ?int $purchasedBalance = null,
    ): void {
        $this->type = $type;
        $this->label = $label ?? ucfirst($type);
        $this->propIncluded = $included;
        $this->propBalance = $balance;
        $this->propPurchasedBalance = $purchasedBalance;
    }

    public function data(): array
    {
        if ($this->propIncluded !== null) {
            $included = $this->propIncluded;
            $balance = $this->propBalance ?? 0;
            $purchasedBalance = $this->propPurchasedBalance ?? 0;
        } else {
            $billable = MollieBilling::resolveBillable(request());
            $included = $billable ? $billable->includedBillingQuota($this->type) : 0;
            $wallet = ($billable instanceof \Illuminate\Database\Eloquent\Model)
                ? $billable->getWallet($this->type)
                : null;
            $balance = (int) ($wallet?->balanceInt ?? 0);
            $purchasedBalance = $wallet !== null ? WalletUsageService::getPurchasedBalance($wallet) : 0;
        }

        $threshold = (int) config('mollie-billing.usage_threshold_percent', 80);
        $overage = $balance < 0 ? abs($balance) : 0;

        // Separate purchased from plan balance.
        $purchasedRemaining = WalletUsageService::computePurchasedRemaining($purchasedBalance, $balance);
        $planOnlyBalance = $balance - $purchasedRemaining;

        $planUsed = max(0, $included - $planOnlyBalance);
        $purchasedUsed = max(0, $purchasedBalance - $purchasedRemaining);

        $remainingIncluded = max(0, $included - $planUsed);
        $remainingPurchased = $purchasedRemaining;

        // Total capacity = included + all purchased.
        $totalCapacity = $included + $purchasedBalance;

        // Four bar segments:
        // [plan consumed | remaining included (empty) | purchased consumed | remaining purchased]
        if ($totalCapacity > 0) {
            $planUsedWidth = min(100, round($planUsed / $totalCapacity * 100, 1));
            $remainingIncludedWidth = round($remainingIncluded / $totalCapacity * 100, 1);
            $purchasedUsedWidth = round($purchasedUsed / $totalCapacity * 100, 1);
            $remainingPurchasedWidth = round($remainingPurchased / $totalCapacity * 100, 1);
        } else {
            $planUsedWidth = 0;
            $remainingIncludedWidth = 0;
            $purchasedUsedWidth = 0;
            $remainingPurchasedWidth = 0;
        }

        // Warning/danger based on plan quota usage only.
        $usedPercent = $included > 0 ? (int) round($planUsed / $included * 100) : 0;
        $isWarning = $usedPercent >= $threshold && $usedPercent < 100;
        $isDanger = $usedPercent >= 100;
        $planUsedColor = $isDanger ? 'bg-red-500' : ($isWarning ? 'bg-amber-500' : 'bg-emerald-500');

        return compact(
            'included', 'overage', 'purchasedBalance',
            'planUsed', 'purchasedUsed', 'remainingIncluded', 'remainingPurchased',
            'planUsedWidth', 'remainingIncludedWidth', 'purchasedUsedWidth', 'remainingPurchasedWidth',
            'usedPercent', 'isWarning', 'isDanger', 'planUsedColor',
        );
    }
};

?>

@php $m = $this->data(); @endphp

<div class="rounded-lg border border-zinc-200/80 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/2">
    {{-- Header --}}
    <div class="flex items-center justify-between mb-3">
        <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $label }}</span>
        @if ($m['overage'] > 0)
            <flux:badge size="sm" color="red">+{{ number_format($m['overage']) }} {{ __('billing::portal.overage') }}</flux:badge>
        @endif
    </div>

    {{-- Stacked bar: [plan consumed | remaining included | purchased consumed | remaining purchased] --}}
    <div class="h-2.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800 flex">
        {{-- Plan consumed --}}
        @if ($m['planUsedWidth'] > 0)
            <div class="h-full transition-all duration-500 {{ $m['planUsedColor'] }} rounded-l-full"
                 style="width: {{ $m['planUsedWidth'] }}%"></div>
        @endif
        {{-- Remaining included (background/empty) --}}
        @if ($m['remainingIncludedWidth'] > 0)
            <div class="h-full bg-zinc-100 dark:bg-zinc-800"
                 style="width: {{ $m['remainingIncludedWidth'] }}%"></div>
        @endif
        {{-- Purchased consumed --}}
        @if ($m['purchasedUsedWidth'] > 0)
            <div class="h-full transition-all duration-500 bg-sky-500 dark:bg-sky-600"
                 style="width: {{ $m['purchasedUsedWidth'] }}%"></div>
        @endif
        {{-- Remaining purchased --}}
        @if ($m['remainingPurchasedWidth'] > 0)
            <div class="h-full transition-all duration-500 bg-sky-200 dark:bg-sky-900/50 rounded-r-full"
                 style="width: {{ $m['remainingPurchasedWidth'] }}%"></div>
        @endif
    </div>

    {{-- Footer --}}
    <div class="mt-2 flex items-center justify-between text-xs tabular-nums">
        <span class="text-zinc-400">{{ __('billing::portal.usage_plan_remaining', ['remaining' => number_format($m['remainingIncluded']), 'total' => number_format($m['included'])]) }}</span>
        @if ($m['purchasedBalance'] > 0)
            <span class="text-sky-500">{{ __('billing::portal.usage_purchased_remaining', ['remaining' => number_format($m['remainingPurchased']), 'total' => number_format($m['purchasedBalance'])]) }}</span>
        @endif
    </div>
</div>
