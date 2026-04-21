<?php

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use Livewire\Component;

new class extends Component {
    public ?int $seatCount = 0;
    public ?string $flash = null;
    public bool $flashSuccess = true;

    public function mount(): void
    {
        $billable = $this->resolveBillable();
        if ($billable) {
            $this->seatCount = $billable->getBillingSeatCount();
        }
    }

    private function resolveBillable(): ?Billable
    {
        return MollieBilling::resolveBillable(request());
    }

    private function ensureMinSeats(): void
    {
        $min = $this->minSeats();
        if ($this->seatCount === null || $this->seatCount < $min) {
            $this->seatCount = $min;
        }
    }

    public function increment(): void
    {
        $this->ensureMinSeats();
        $this->seatCount++;
    }

    public function decrement(): void
    {
        $this->ensureMinSeats();
        $min = $this->minSeats();
        if ($this->seatCount > $min) {
            $this->seatCount--;
        }
    }

    private function minSeats(): int
    {
        $billable = $this->resolveBillable();
        $planCode = $billable?->getBillingSubscriptionPlanCode();

        return $planCode ? app(SubscriptionCatalogInterface::class)->includedSeats($planCode) : 0;
    }

    public function syncSeats(): void
    {
        $billable = $this->resolveBillable();
        if (! $billable) return;

        $this->ensureMinSeats();

        try {
            $billable->syncBillingSeats($this->seatCount);
            $this->flash = __('billing::portal.seats_flash.synced');
            $this->flashSuccess = true;
        } catch (\Throwable $e) {
            report($e);
            $this->flash = __('billing::portal.flash.error');
            $this->flashSuccess = false;
        }
    }

    public function with(): array
    {
        $billable = $this->resolveBillable();
        $catalog = app(SubscriptionCatalogInterface::class);
        $planCode = $billable?->getBillingSubscriptionPlanCode();
        $interval = $billable?->getBillingSubscriptionInterval() ?? 'monthly';
        $currency = config('mollie-billing.currency', 'EUR');
        $currencySymbol = $currency === 'EUR' ? '€' : $currency;

        $includedSeats = $planCode ? $catalog->includedSeats($planCode) : 0;
        $seatPrice = $planCode ? $catalog->seatPriceNet($planCode, $interval) : null;
        $usedSeats = $billable?->getUsedBillingSeats() ?? 0;
        $extraSeats = max(0, ($this->seatCount ?? $includedSeats) - $includedSeats);

        return [
            'billable' => $billable,
            'includedSeats' => $includedSeats,
            'seatPrice' => $seatPrice,
            'usedSeats' => $usedSeats,
            'extraSeats' => $extraSeats,
            'extraCost' => $seatPrice !== null ? $extraSeats * $seatPrice : null,
            'interval' => $interval,
            'currencySymbol' => $currencySymbol,
            'hasSeats' => $seatPrice !== null || $includedSeats > 0,
        ];
    }
};

?>

@php
    $currentSeats = $this->seatCount ?? $includedSeats;
    $utilizationPercent = $currentSeats > 0 ? min(100, (int) round(($usedSeats ?? 0) / $currentSeats * 100)) : 0;
    $isHighUtilization = $utilizationPercent >= 80 && $utilizationPercent < 100;
    $isFullUtilization = $utilizationPercent >= 100;
    $savedSeatCount = $billable?->getBillingSeatCount() ?? 0;
    $hasChanges = $this->seatCount !== null && $this->seatCount !== $savedSeatCount;
    $hasPendingPlanChange = $billable?->hasPendingBillingPlanChange() ?? false;
@endphp

<div class="space-y-6">
    {{-- Page header --}}
    <div>
        <flux:heading size="xl">{{ __('billing::portal.seats') }}</flux:heading>
        <flux:subheading>
            {{ __('billing::portal.seats_subtitle') }}
        </flux:subheading>
    </div>

    @if ($flash)
        <flux:callout icon="{{ $flashSuccess ? 'check-circle' : 'exclamation-triangle' }}" color="{{ $flashSuccess ? 'lime' : 'red' }}" x-init="$el.scrollIntoView({ behavior: 'smooth', block: 'center' })" inline>{{ $flash }}</flux:callout>
    @endif

    @if ($hasPendingPlanChange)
        <flux:callout icon="arrow-path" color="blue" inline>
            {{ __('billing::portal.pending_change_blocks_modifications') }}
        </flux:callout>
    @endif

    @if (! $billable)
        <flux:callout variant="warning" icon="exclamation-triangle">
            {{ __('billing::portal.no_billable') }}
        </flux:callout>
    @elseif (! $hasSeats)
        <flux:callout icon="information-circle" color="zinc" inline>
            {{ __('billing::portal.seats_not_available') }}
        </flux:callout>
    @else
        {{-- Stat cards --}}
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {{-- Total seats --}}
            <flux:card class="p-5!">
                <div class="flex items-start justify-between gap-2">
                    <flux:subheading>{{ __('billing::portal.seats_current') }}</flux:subheading>
                    <div class="flex size-8 items-center justify-center rounded-lg bg-accent/10">
                        <flux:icon.users class="size-4 text-accent" />
                    </div>
                </div>
                <div class="mt-3">
                    <span class="text-3xl font-bold tabular-nums tracking-tight">{{ $currentSeats }}</span>
                </div>
            </flux:card>

            {{-- Included --}}
            <flux:card class="p-5!">
                <div class="flex items-start justify-between gap-2">
                    <flux:subheading>{{ __('billing::portal.seats_included') }}</flux:subheading>
                    <div class="flex size-8 items-center justify-center rounded-lg bg-emerald-500/10">
                        <flux:icon.check-badge class="size-4 text-emerald-500" />
                    </div>
                </div>
                <div class="mt-3">
                    <span class="text-3xl font-bold tabular-nums tracking-tight">{{ $includedSeats }}</span>
                </div>
                
            </flux:card>

            {{-- In use --}}
            <flux:card class="p-5!">
                <div class="flex items-start justify-between gap-2">
                    <flux:subheading>{{ __('billing::portal.seats_in_use') }}</flux:subheading>
                    <div class="flex size-8 items-center justify-center rounded-lg {{ $isFullUtilization ? 'bg-red-500/10' : ($isHighUtilization ? 'bg-amber-400/10' : 'bg-zinc-100 dark:bg-zinc-800') }}">
                        <flux:icon.user-circle class="size-4 {{ $isFullUtilization ? 'text-red-500' : ($isHighUtilization ? 'text-amber-500' : 'text-zinc-400') }}" />
                    </div>
                </div>
                <div class="mt-3 flex items-baseline gap-1.5">
                    <span class="text-3xl font-bold tabular-nums tracking-tight">{{ $usedSeats }}</span>
                    <span class="text-sm text-zinc-400">/ {{ $currentSeats }}</span>
                </div>
                <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                    <div
                        class="h-full rounded-full transition-all duration-500 {{ $isFullUtilization ? 'bg-red-500' : ($isHighUtilization ? 'bg-amber-400' : 'bg-emerald-500') }}"
                        style="width: {{ $utilizationPercent }}%"
                    ></div>
                </div>
            </flux:card>

            {{-- Price per extra seat --}}
            @if ($seatPrice !== null)
                <flux:card class="p-5!">
                    <div class="flex items-start justify-between gap-2">
                        <flux:subheading>{{ __('billing::portal.seats_price_per_seat') }}</flux:subheading>
                        <div class="flex size-8 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
                            <flux:icon.currency-euro class="size-4 text-zinc-400" />
                        </div>
                    </div>
                    <div class="mt-3">
                        <span class="text-3xl font-bold tabular-nums tracking-tight">{{ $currencySymbol }}{{ number_format($seatPrice / 100, 2) }}</span>
                    </div>
                    <flux:text class="mt-1 text-xs text-zinc-400">{{ $interval === 'monthly' ? __('billing::portal.per_month') : __('billing::portal.per_year') }} · {{ __('billing::portal.prices_excl_vat') }}</flux:text>
                </flux:card>
            @endif
        </div>

        {{-- Adjust seats --}}
        <flux:card class="relative overflow-hidden p-0!">
            <div class="absolute inset-x-0 top-0 h-1 {{ $hasChanges ? 'bg-accent' : 'bg-transparent' }}"></div>

            <div class="px-6 pb-4 pt-8">
                <div class="flex items-center gap-3">
                    <div class="flex size-10 items-center justify-center rounded-full bg-accent/10">
                        <flux:icon.adjustments-horizontal class="size-5 text-accent" />
                    </div>
                    <div>
                        <flux:heading size="lg">{{ __('billing::portal.seats_adjust') }}</flux:heading>
                        <flux:text class="text-sm text-zinc-500">{{ __('billing::portal.seats_adjust_subtitle') }}</flux:text>
                    </div>
                </div>
            </div>

            <div class="border-t border-zinc-200/75 bg-zinc-50/50 px-6 py-6 dark:border-zinc-700/50 dark:bg-white/[0.02]">
                <div class="flex flex-col gap-6 sm:flex-row sm:items-start sm:justify-between">
                    {{-- Stepper --}}
                    <div class="space-y-3">
                        <flux:subheading size="sm" class="text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.seats_current') }}</flux:subheading>
                        <div class="flex items-center gap-1">
                            <flux:button size="sm" variant="ghost" icon="minus"
                                wire:click="decrement"
                                :disabled="$currentSeats <= $includedSeats || $hasPendingPlanChange"
                                class="rounded-r-none"
                            />
                            <flux:input type="number" wire:model.live="seatCount" :min="$includedSeats" :disabled="$hasPendingPlanChange" class="w-20 text-center tabular-nums rounded-none! border-x-0!" x-on:blur="if (!$el.value || parseInt($el.value) < {{ $includedSeats }}) { $wire.set('seatCount', {{ $includedSeats }}) }" />
                            <flux:button size="sm" variant="ghost" icon="plus"
                                wire:click="increment"
                                :disabled="$hasPendingPlanChange"
                                class="rounded-l-none"
                            />
                        </div>
                    </div>

                    {{-- Cost breakdown --}}
                    @if ($seatPrice !== null)
                        <div class="flex-1 max-w-sm">
                            <flux:subheading size="sm" class="mb-3 text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.seats_cost_summary') }}</flux:subheading>
                            <div class="space-y-2">
                                <div class="flex items-center justify-between text-sm">
                                    <span class="text-zinc-600 dark:text-zinc-300">{{ trans_choice('billing::portal.seats_included_label', $includedSeats, ['count' => $includedSeats]) }}</span>
                                    <span class="tabular-nums text-zinc-400">{{ __('billing::portal.free') }}</span>
                                </div>
                                @if ($extraSeats > 0)
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="text-zinc-600 dark:text-zinc-300">
                                            {{ $extraSeats }} {{ __('billing::portal.seats_extra') }}
                                            <span class="text-zinc-400">&times; {{ $currencySymbol }}{{ number_format($seatPrice / 100, 2) }}</span>
                                        </span>
                                        <span class="tabular-nums font-medium text-zinc-700 dark:text-zinc-200">{{ $currencySymbol }}{{ number_format($extraCost / 100, 2) }}</span>
                                    </div>
                                @endif
                                <flux:separator class="my-1!" />
                                <div class="flex items-center justify-between">
                                    <span class="font-medium text-zinc-700 dark:text-zinc-200">{{ __('billing::portal.seats_total_cost') }}</span>
                                    <span class="text-lg font-bold tabular-nums text-zinc-900 dark:text-white">{{ $currencySymbol }}{{ number_format(($extraCost ?? 0) / 100, 2) }}</span>
                                </div>
                                <flux:text class="text-xs text-zinc-400">{{ $interval === 'monthly' ? __('billing::portal.per_month') : __('billing::portal.per_year') }} · {{ __('billing::portal.prices_excl_vat') }}</flux:text>
                            </div>
                        </div>
                    @endif
                </div>

                @if ($usedSeats > $currentSeats)
                    <flux:callout icon="exclamation-triangle" color="amber" class="mt-5" inline>
                        {{ __('billing::portal.seats_warning_below_used', ['used' => $usedSeats]) }}
                    </flux:callout>
                @endif

                {{-- Action --}}
                <div class="mt-5 flex justify-end">
                    <flux:button variant="primary" size="sm" wire:click="syncSeats" :disabled="! $hasChanges || $hasPendingPlanChange">
                        {{ __('billing::portal.seats_save') }}
                    </flux:button>
                </div>
            </div>
        </flux:card>
    @endif
</div>
