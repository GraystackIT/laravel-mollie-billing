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
    public bool $flashError = false;

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

    public function cancelScheduledChange(UpdateSubscription $service): void
    {
        $billable = $this->resolveBillable();
        if (! $billable) return;

        try {
            $service->cancelScheduledChange($billable);
            $this->flash = __('billing::portal.flash.scheduled_cancelled');
            $this->flashError = false;
        } catch (\Throwable $e) {
            report($e);
            $this->flash = __('billing::portal.flash.error');
            $this->flashError = true;
        }
    }

    public function applyScheduledNow(UpdateSubscription $service): void
    {
        $billable = $this->resolveBillable();
        if (! $billable) return;

        $meta = $billable->getBillingSubscriptionMeta();
        $sc = $meta['scheduled_change'] ?? null;
        if (! $sc) return;

        try {
            $service->cancelScheduledChange($billable);
            $service->update($billable, [
                'plan_code' => $sc['plan_code'] ?? null,
                'interval' => $sc['interval'] ?? null,
                'seats' => $sc['seats'] ?? null,
                'addons' => $sc['addons'] ?? null,
                'coupon_code' => $sc['coupon_code'] ?? null,
                'apply_at' => 'immediate',
            ]);
            $this->flash = __('billing::portal.flash.plan_changed');
            $this->flashError = false;
        } catch (\Throwable $e) {
            report($e);
            $this->flash = config('app.debug')
                ? __('billing::portal.flash.error').' ('.$e->getMessage().')'
                : __('billing::portal.flash.error');
            $this->flashError = true;
        }
    }

    public function applyChange(UpdateSubscription $service, bool $immediate = false): void
    {
        $billable = $this->resolveBillable();

        if (! $billable || ! $this->selectedPlan) {
            $this->flash = __('billing::portal.flash.error');
            $this->flashError = true;
            return;
        }

        try {
            $result = $service->update($billable, [
                'plan_code' => $this->selectedPlan,
                'interval' => $this->selectedInterval,
                'apply_at' => $immediate ? 'immediate' : $this->applyAt,
            ]);

            if (! empty($result['scheduledFor'])) {
                $date = \Carbon\Carbon::parse($result['scheduledFor'])->translatedFormat('d. M Y');
                $this->flash = __('billing::portal.flash.plan_scheduled', ['date' => $date]);
            } else {
                $this->flash = __('billing::portal.flash.plan_changed');
            }
            $this->flashError = false;
            $this->preview = [];
            $this->selectedPlan = null;
        } catch (\Throwable $e) {
            report($e);
            $this->flash = config('app.debug')
                ? __('billing::portal.flash.error').' ('.$e->getMessage().')'
                : __('billing::portal.flash.error');
            $this->flashError = true;
        }
    }

    public function with(): array
    {
        $billable = $this->resolveBillable();
        $scheduledChange = null;

        if ($billable) {
            $meta = $billable->getBillingSubscriptionMeta();
            $sc = $meta['scheduled_change'] ?? null;
            if ($sc !== null) {
                $scheduledChange = [
                    'plan_code' => $sc['plan_code'] ?? null,
                    'interval' => $sc['interval'] ?? null,
                    'scheduled_at' => isset($sc['scheduled_at'])
                        ? \Carbon\Carbon::parse($sc['scheduled_at'])->translatedFormat('d. M Y')
                        : null,
                ];
            }
        }

        return [
            'billable' => $billable,
            'plans' => app(SubscriptionCatalogInterface::class)->allPlans(),
            'catalog' => app(SubscriptionCatalogInterface::class),
            'scheduledChange' => $scheduledChange,
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
        <flux:callout variant="{{ $flashError ? 'danger' : 'success' }}" icon="{{ $flashError ? 'exclamation-triangle' : 'check-circle' }}" x-init="$el.scrollIntoView({ behavior: 'smooth', block: 'center' })">{{ $flash }}</flux:callout>
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

    @php
        $hasScheduled = $scheduledChange !== null;
        $scheduledPlanCode = $scheduledChange['plan_code'] ?? null;
        $scheduledInterval = $scheduledChange['interval'] ?? null;
    @endphp

    <div class="grid gap-4" style="grid-template-columns: repeat({{ $cols }}, minmax(0, 1fr))">
        @foreach ($plans as $code)
            @php
                $isCurrent = $currentPlanCode === $code && $currentInterval === $selectedInterval;
                $isScheduledTarget = $hasScheduled && $scheduledPlanCode === $code && $scheduledInterval === $selectedInterval;
                $isSelected = $selectedPlan === $code;
                $price = $catalog->basePriceNet($code, $selectedInterval);
                $features = $catalog->planFeatures($code);
                $seats = $catalog->includedSeats($code);
                $savings = $selectedInterval === 'yearly' ? $catalog->yearlySavingsPercent($code) : 0;
                $isFree = $price === 0;
            @endphp

            <flux:card
                class="relative flex flex-col overflow-hidden transition {{ $isSelected ? 'ring-2 ring-accent shadow-lg' : ($isScheduledTarget ? 'ring-2 ring-amber-400 shadow-lg' : 'hover:shadow-md') }}"
            >
                {{-- Top accent strip --}}
                @if ($isCurrent || $isScheduledTarget || $isSelected)
                    <div class="absolute inset-x-0 top-0 h-1.5 {{ $isCurrent ? 'bg-emerald-500' : ($isScheduledTarget ? 'bg-amber-500' : 'bg-accent') }}"></div>
                @endif

                <div class="flex-1 space-y-4 pt-2">
                    {{-- Plan name + badge --}}
                    <div class="flex items-start justify-between gap-2">
                        <flux:heading size="lg">{{ $catalog->planName($code) ?? $code }}</flux:heading>
                        @if ($isCurrent)
                            <flux:badge size="sm" color="lime">{{ __('billing::portal.current') }}</flux:badge>
                        @elseif ($isScheduledTarget)
                            <flux:badge size="sm" color="amber">{{ __('billing::portal.scheduled') }}</flux:badge>
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
                            <flux:badge size="sm" color="lime" icon="arrow-trending-down" class="mt-2 mb-2">{{ __('billing::portal.save_yearly', ['percent' => round($savings)]) }}</flux:badge>
                        @endif
                        @unless ($isFree)
                            <flux:text class="text-xs text-zinc-400">{{ __('billing::portal.prices_excl_vat') }}</flux:text>
                        @endunless
                    </div>

                    <flux:separator />

                    {{-- Included info --}}
                    <div class="space-y-2.5 text-sm">
                        @if ($seats > 0)
                            <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-300">
                                <flux:icon.users class="size-4 shrink-0 text-zinc-400" />
                                <span>{{ trans_choice('billing::portal.seats_included_count', $seats, ['count' => $seats]) }}</span>
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
                <div class="mt-6 space-y-2">
                    @if ($isScheduledTarget)
                        {{-- Scheduled date info --}}
                        @if ($scheduledChange['scheduled_at'])
                            <flux:text class="text-center text-xs text-amber-600 dark:text-amber-400">
                                {{ __('billing::portal.scheduled_change_on', ['date' => $scheduledChange['scheduled_at']]) }}
                            </flux:text>
                        @endif
                        <flux:button.group class="w-full">
                            <flux:button class="flex-1" size="sm" wire:click="cancelScheduledChange">
                                <span class="text-amber-600">{{ __('billing::portal.cancel_scheduled_change') }}</span>
                            </flux:button>
                            <flux:dropdown position="bottom end">
                                <flux:button size="sm" icon="chevron-down" />
                                <flux:menu>
                                    <flux:menu.item icon="bolt" wire:click="applyScheduledNow">
                                        {{ __('billing::portal.apply_now') }}
                                    </flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </flux:button.group>
                    @elseif ($isSelected)
                        <flux:button class="w-full" variant="filled" disabled>
                            <flux:icon.check class="size-4" />
                            {{ __('billing::portal.selected') }}
                        </flux:button>
                    @elseif ($isCurrent)
                        <flux:button class="w-full" variant="ghost" disabled>
                            {{ __('billing::portal.current') }}
                        </flux:button>
                    @elseif (! $hasScheduled)
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
                                        {{ trans_choice('billing::portal.seats_included_count', $preview['newIncludedSeats'], ['count' => $preview['newIncludedSeats']]) }}
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
                    @if (! $isUpgrade && $applyAt === 'end_of_period')
                        <flux:button.group>
                            <flux:button variant="primary" size="sm" wire:click="applyChange">
                                {{ __('billing::portal.schedule_change') }}
                            </flux:button>
                            <flux:dropdown position="bottom end">
                                <flux:button variant="primary" size="sm" icon="chevron-down" />
                                <flux:menu>
                                    <flux:menu.item icon="bolt" wire:click="applyChange(true)">
                                        {{ __('billing::portal.apply_now') }}
                                    </flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </flux:button.group>
                    @else
                        <flux:button variant="primary" size="sm" wire:click="applyChange">
                            @if ($isUpgrade)
                                {{ __('billing::portal.upgrade_now') }}
                            @else
                                {{ __('billing::portal.apply_now') }}
                            @endif
                        </flux:button>
                    @endif
                </div>
            </div>
        </flux:card>
    @endif
</div>
