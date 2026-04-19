<?php

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use Livewire\Component;

new class extends Component {
    public string $applyAt;
    public string $selectedInterval = 'monthly';

    public function mount(): void
    {
        $this->applyAt = config('mollie-billing.prorata_enabled') ? 'immediate' : 'end_of_period';
    }
    public ?string $selectedPlan = null;
    public array $preview = [];
    public ?string $flash = null;

    private function resolveBillable(): ?Billable
    {
        return MollieBilling::resolveBillable(request());
    }

    public function updatedSelectedInterval(): void
    {
        $this->preview = [];
        $this->selectedPlan = null;
    }

    public function previewFor(string $planCode, PreviewService $service): void
    {
        $this->selectedPlan = $planCode;
        $billable = $this->resolveBillable();
        if ($billable) {
            $this->preview = $service->previewPlanChange($billable, $planCode, $this->selectedInterval);
        }
    }

    public function applyChange(UpdateSubscription $service): void
    {
        $billable = $this->resolveBillable();

        if (! $billable || ! $this->selectedPlan) {
            $this->flash = __('billing::portal.flash.error');
            return;
        }

        try {
            $service->update($billable, [
                'plan_code' => $this->selectedPlan,
                'interval' => $this->selectedInterval,
                'apply_at' => $this->applyAt,
            ]);
            $this->flash = __('billing::portal.flash.plan_changed');
            $this->preview = [];
            $this->selectedPlan = null;
        } catch (\Throwable $e) {
            report($e);
            $this->flash = __('billing::portal.flash.error');
        }
    }

    public function with(): array
    {
        return [
            'billable' => $this->resolveBillable(),
            'plans' => app(SubscriptionCatalogInterface::class)->allPlans(),
            'catalog' => app(SubscriptionCatalogInterface::class),
        ];
    }
};

?>

<div class="space-y-6">
    {{-- Page header --}}
    <div>
        <flux:heading size="xl">{{ __('billing::portal.plan_change') }}</flux:heading>
        <flux:subheading>
            {{ __('billing::portal.plan_change_subtitle') }}
        </flux:subheading>
    </div>

    @if ($flash)
        <flux:callout variant="secondary" icon="information-circle" x-init="$el.scrollIntoView({ behavior: 'smooth', block: 'center' })">{{ $flash }}</flux:callout>
    @endif

    {{-- Controls --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <flux:radio.group wire:model.live="selectedInterval" variant="segmented">
            <flux:radio value="monthly" label="{{ __('billing::portal.interval_monthly') }}" />
            <flux:radio value="yearly" label="{{ __('billing::portal.interval_yearly') }}" />
        </flux:radio.group>
    </div>

    @php
        $currentPlanCode = $billable?->getBillingSubscriptionPlanCode();
        $currentInterval = $billable?->getBillingSubscriptionInterval();
        $currency = config('mollie-billing.currency', 'EUR');
        $currencySymbol = $currency === 'EUR' ? '€' : $currency;
        $planCount = count($plans);
    @endphp

    {{-- Plan cards — responsive grid: ≤3 single row, >3 wraps symmetrically --}}
    @php
        $cols = $planCount <= 3 ? $planCount : (int) ceil($planCount / 2);
    @endphp

    <div class="grid gap-4" style="grid-template-columns: repeat({{ $cols }}, minmax(0, 1fr))">
        @foreach ($plans as $code)
            @php
                $isCurrent = $currentPlanCode === $code && $currentInterval === $selectedInterval;
                $isSelected = $selectedPlan === $code;
                $price = $catalog->basePriceNet($code, $selectedInterval);
                $features = $catalog->planFeatures($code);
                $seats = $catalog->includedSeats($code);
                $savings = $selectedInterval === 'yearly' ? $catalog->yearlySavingsPercent($code) : 0;
                $isFree = $price === 0;
            @endphp

            <flux:card
                class="relative flex flex-col overflow-hidden transition {{ $isSelected ? 'ring-2 ring-accent shadow-lg' : 'hover:shadow-md' }}"
            >
                {{-- Top accent strip --}}
                <div class="absolute inset-x-0 top-0 h-1 {{ $isCurrent ? 'bg-emerald-500' : ($isSelected ? 'bg-accent' : 'bg-transparent') }}"></div>

                <div class="flex-1 space-y-4 pt-2">
                    {{-- Plan name + badge --}}
                    <div class="flex items-start justify-between gap-2">
                        <flux:heading size="lg">{{ $catalog->planName($code) ?? $code }}</flux:heading>
                        @if ($isCurrent)
                            <flux:badge size="sm" color="lime">{{ __('billing::portal.current') }}</flux:badge>
                        @endif
                    </div>

                    {{-- Price block --}}
                    <div class="space-y-1.5">
                        <div class="flex items-baseline gap-1">
                            <span class="text-3xl font-bold tracking-tight">
                                @if ($isFree)
                                    {{ __('billing::portal.free') }}
                                @else
                                    {{ $currencySymbol }}{{ number_format($price / 100, 2) }}
                                @endif
                            </span>
                            @unless ($isFree)
                                <span class="text-sm text-zinc-400 dark:text-zinc-500">{{ $selectedInterval === 'monthly' ? __('billing::portal.per_month') : __('billing::portal.per_year') }}</span>
                            @endunless
                        </div>
                        @if ($savings > 0)
                            <flux:badge size="sm" color="lime" icon="arrow-trending-down">{{ __('billing::portal.save_yearly', ['percent' => round($savings)]) }}</flux:badge>
                        @endif
                    </div>

                    <flux:separator />

                    {{-- Included info --}}
                    <div class="space-y-2.5 text-sm">
                        @if ($seats > 0)
                            <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-300">
                                <flux:icon.users class="size-4 shrink-0 text-zinc-400" />
                                <span>{{ trans_choice('billing::portal.seats_included', $seats, ['count' => $seats]) }}</span>
                            </div>
                        @endif

                        @if (count($features) > 0)
                            <ul class="space-y-1.5">
                                @foreach ($features as $feature)
                                    <li class="flex items-start gap-2">
                                        <flux:icon.check class="mt-0.5 size-4 shrink-0 text-emerald-500" />
                                        <span class="text-zinc-600 dark:text-zinc-300">{{ $catalog->featureName($feature) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>

                {{-- Action --}}
                <div class="mt-6">
                    @if ($isSelected)
                        <flux:button class="w-full" variant="filled" disabled>
                            <flux:icon.check class="size-4" />
                            {{ __('billing::portal.selected') }}
                        </flux:button>
                    @elseif ($isCurrent)
                        <flux:button class="w-full" variant="ghost" disabled>
                            {{ __('billing::portal.current') }}
                        </flux:button>
                    @else
                        <flux:button class="w-full" variant="primary" wire:click="previewFor('{{ $code }}')">
                            {{ __('billing::portal.select') }}
                        </flux:button>
                    @endif
                </div>
            </flux:card>
        @endforeach
    </div>

    {{-- Preview panel --}}
    @if ($selectedPlan && !empty($preview))
        @php
            $isUpgrade = ($preview['diffNet'] ?? 0) > 0;
            $isDowngrade = ($preview['diffNet'] ?? 0) < 0;
            $planChanged = $preview['planChanged'] ?? false;
            $intervalChanged = $preview['intervalChanged'] ?? false;
        @endphp

        <flux:card class="relative overflow-hidden p-0!">
            <div class="absolute inset-x-0 top-0 h-1 bg-accent"></div>

            {{-- Header --}}
            <div class="flex flex-col gap-4 px-6 pb-4 pt-8 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3">
                    <div class="flex size-10 items-center justify-center rounded-full bg-accent/10">
                        <flux:icon.receipt-percent class="size-5 text-accent" />
                    </div>
                    <div>
                        <flux:heading size="lg">{{ __('billing::portal.preview') }}</flux:heading>
                        <flux:text class="text-sm text-zinc-500">
                            {{ __('billing::portal.preview_change_summary') }}
                        </flux:text>
                    </div>
                </div>
                @if ($isUpgrade)
                    <flux:badge color="lime" icon="arrow-trending-up">{{ __('billing::portal.preview_upgrade') }}</flux:badge>
                @elseif ($isDowngrade)
                    <flux:badge color="amber" icon="arrow-trending-down">{{ __('billing::portal.preview_downgrade') }}</flux:badge>
                @else
                    <flux:badge color="zinc" icon="minus">{{ __('billing::portal.preview_no_change') }}</flux:badge>
                @endif
            </div>

            {{-- Change details --}}
            <div class="border-t border-zinc-200/75 px-6 py-5 dark:border-zinc-700/50">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {{-- Plan change --}}
                    @if ($planChanged)
                        <div class="flex items-start gap-3">
                            <div class="mt-0.5 flex size-8 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                <flux:icon.squares-2x2 class="size-4 text-zinc-500" />
                            </div>
                            <div>
                                <flux:subheading size="sm" class="text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.current_plan') }}</flux:subheading>
                                <flux:text class="mt-0.5 font-medium">
                                    {{ __('billing::portal.preview_plan_from_to', ['from' => $preview['currentPlanName'], 'to' => $preview['newPlanName']]) }}
                                </flux:text>
                            </div>
                        </div>
                    @endif

                    {{-- Interval change --}}
                    <div class="flex items-start gap-3">
                        <div class="mt-0.5 flex size-8 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
                            <flux:icon.calendar class="size-4 text-zinc-500" />
                        </div>
                        <div>
                            <flux:subheading size="sm" class="text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.interval') }}</flux:subheading>
                            <flux:text class="mt-0.5 font-medium">
                                @if ($intervalChanged)
                                    {{ __('billing::portal.preview_interval_change', ['from' => __('billing::portal.interval_' . $preview['currentInterval']), 'to' => __('billing::portal.interval_' . $preview['newInterval'])]) }}
                                @else
                                    {{ __('billing::portal.preview_no_interval_change', ['interval' => __('billing::portal.interval_' . $preview['newInterval'])]) }}
                                @endif
                            </flux:text>
                        </div>
                    </div>

                    {{-- Seats change --}}
                    @if (($preview['currentIncludedSeats'] ?? 0) > 0 || ($preview['newIncludedSeats'] ?? 0) > 0)
                        <div class="flex items-start gap-3">
                            <div class="mt-0.5 flex size-8 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                <flux:icon.users class="size-4 text-zinc-500" />
                            </div>
                            <div>
                                <flux:subheading size="sm" class="text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.seats') }}</flux:subheading>
                                <flux:text class="mt-0.5 font-medium">
                                    @if ($preview['currentIncludedSeats'] !== $preview['newIncludedSeats'])
                                        {{ __('billing::portal.preview_seats_from_to', ['from' => $preview['currentIncludedSeats'], 'to' => $preview['newIncludedSeats']]) }}
                                    @else
                                        {{ trans_choice('billing::portal.seats_included', $preview['newIncludedSeats'], ['count' => $preview['newIncludedSeats']]) }}
                                    @endif
                                </flux:text>
                                <flux:text class="text-xs text-zinc-400 dark:text-zinc-500">
                                    {{ __('billing::portal.preview_seats_used', ['count' => $preview['usedSeats'] ?? 0]) }}
                                </flux:text>
                            </div>
                        </div>
                    @endif

                    {{-- Usage changes --}}
                    @foreach (($preview['usageChanges'] ?? []) as $usageType => $usage)
                        <div class="flex items-start gap-3">
                            <div class="mt-0.5 flex size-8 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                <flux:icon.chart-bar class="size-4 text-zinc-500" />
                            </div>
                            <div>
                                <flux:subheading size="sm" class="text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.usage') }} · {{ ucfirst($usageType) }}</flux:subheading>
                                <flux:text class="mt-0.5 font-medium">
                                    @if ($usage['diff'] !== 0)
                                        {{ __('billing::portal.preview_usage_from_to', [
                                            'from' => $usage['current'] > 0 ? number_format($usage['current']) : '0',
                                            'to' => $usage['new'] > 0 ? number_format($usage['new']) : '0',
                                        ]) }}
                                        @if ($usage['diff'] > 0)
                                            <span class="text-emerald-600 dark:text-emerald-400">(+{{ number_format($usage['diff']) }})</span>
                                        @else
                                            <span class="text-red-600 dark:text-red-400">({{ number_format($usage['diff']) }})</span>
                                        @endif
                                    @else
                                        {{ number_format($usage['new']) }} ({{ __('billing::portal.preview_no_change') }})
                                    @endif
                                </flux:text>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Pricing --}}
            <div class="border-t border-zinc-200/75 bg-zinc-50/50 px-6 py-5 dark:border-zinc-700/50 dark:bg-white/[0.02]">
                <flux:subheading size="sm" class="mb-3 text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.preview_pricing') }}</flux:subheading>

                <div class="space-y-2">
                    {{-- Line items --}}
                    @foreach (($preview['lineItems'] ?? []) as $item)
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-zinc-600 dark:text-zinc-300">
                                {{ $item['label'] }}
                                @if (($item['quantity'] ?? 1) > 1)
                                    <span class="text-zinc-400">× {{ $item['quantity'] }}</span>
                                @endif
                            </span>
                            <span class="tabular-nums font-medium {{ ($item['total_net'] ?? 0) < 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-700 dark:text-zinc-200' }}">
                                {{ ($item['total_net'] ?? 0) < 0 ? '−' : '' }}{{ $currencySymbol }}{{ number_format(abs($item['total_net'] ?? 0) / 100, 2) }}
                            </span>
                        </div>
                    @endforeach

                    <flux:separator class="my-2!" />

                    {{-- Net / VAT / Gross --}}
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-zinc-500">{{ __('billing::portal.net') }}</span>
                        <span class="tabular-nums text-zinc-600 dark:text-zinc-300">{{ $currencySymbol }}{{ number_format(($preview['newPriceNet'] ?? 0) / 100, 2) }}</span>
                    </div>
                    @if (($preview['couponDiscountNet'] ?? 0) > 0)
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-zinc-500">{{ __('billing::portal.discount') }}</span>
                            <span class="tabular-nums text-emerald-600 dark:text-emerald-400">−{{ $currencySymbol }}{{ number_format($preview['couponDiscountNet'] / 100, 2) }}</span>
                        </div>
                    @endif
                    @if (($preview['vatAmount'] ?? 0) > 0)
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-zinc-500">{{ __('billing::portal.vat') }} ({{ number_format($preview['vatRate'] ?? 0, 0) }}%)</span>
                            <span class="tabular-nums text-zinc-600 dark:text-zinc-300">{{ $currencySymbol }}{{ number_format($preview['vatAmount'] / 100, 2) }}</span>
                        </div>
                    @endif

                    <flux:separator class="my-2!" />

                    {{-- Gross total --}}
                    <div class="flex items-center justify-between">
                        <span class="font-medium text-zinc-700 dark:text-zinc-200">
                            {{ ($preview['prorataChargeNet'] ?? 0) > 0 ? __('billing::portal.preview_due_now') : __('billing::portal.gross') }}
                        </span>
                        <span class="text-lg font-bold tabular-nums text-zinc-900 dark:text-white">{{ $currencySymbol }}{{ number_format(($preview['grossTotal'] ?? 0) / 100, 2) }}</span>
                    </div>

                    {{-- Prorata note --}}
                    @if (($preview['prorataChargeNet'] ?? 0) > 0)
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-zinc-500">
                                @if ($preview['appliesAt'] !== 'immediate')
                                    {{ __('billing::portal.preview_recurring', ['date' => \Carbon\Carbon::parse($preview['appliesAt'])->translatedFormat('d. M Y')]) }}
                                @else
                                    {{ __('billing::portal.preview_recurring_immediately') }}
                                @endif
                            </span>
                            <span class="tabular-nums text-zinc-600 dark:text-zinc-300">{{ $currencySymbol }}{{ number_format(($preview['grossTotal'] ?? 0) / 100, 2) }}</span>
                        </div>
                    @endif
                </div>

                {{-- Action button --}}
                <div class="mt-5 flex justify-end">
                    <flux:button variant="primary" size="sm" wire:click="applyChange">
                        {{ $applyAt === 'end_of_period' ? __('billing::portal.schedule_change') : __('billing::portal.apply_now') }}
                    </flux:button>
                </div>
            </div>
        </flux:card>
    @endif
</div>
