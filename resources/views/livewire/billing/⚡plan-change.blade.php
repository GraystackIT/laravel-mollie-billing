<?php

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use Livewire\Component;

new class extends Component {
    public string $selectedInterval = 'monthly';
    public ?string $selectedPlan = null;
    public array $preview = [];
    public bool $wasPending = false;
    public bool $dropExtraSeats = false;

    private function resolveBillable(): ?Billable
    {
        return MollieBilling::resolveBillable(request());
    }

    public function updatedSelectedInterval(): void
    {
        $this->preview = [];
        $this->selectedPlan = null;
        $this->dropExtraSeats = false;
    }

    public function previewFor(string $planCode, PreviewService $service): void
    {
        $this->selectedPlan = $planCode;
        $this->refreshPreview($service);
    }

    public function toggleDropExtraSeats(PreviewService $service): void
    {
        $this->dropExtraSeats = ! $this->dropExtraSeats;
        $this->refreshPreview($service);
    }

    private function refreshPreview(PreviewService $service): void
    {
        $billable = $this->resolveBillable();
        if (! $billable || ! $this->selectedPlan) {
            return;
        }

        $seats = null;
        if ($this->dropExtraSeats) {
            $includedSeats = app(SubscriptionCatalogInterface::class)->includedSeats($this->selectedPlan);
            $usedSeats = $billable->getUsedBillingSeats();
            $seats = max($usedSeats, $includedSeats);
        }

        $this->preview = $service->previewUpdate($billable, new \GraystackIT\MollieBilling\Services\Billing\SubscriptionUpdateRequest(
            planCode: $this->selectedPlan,
            interval: $this->selectedInterval,
            seats: $seats,
        ));
    }

    public function cancelScheduledChange(UpdateSubscription $service): void
    {
        $billable = $this->resolveBillable();
        if (! $billable) return;

        try {
            $service->cancelScheduledChange($billable);
            \Flux::toast(__('billing::portal.flash.scheduled_cancelled'), variant: 'success');
        } catch (\Throwable $e) {
            report($e);
            \Flux::toast(__('billing::portal.flash.error'), variant: 'danger');
        }
    }

    public function cancelPendingChange(UpdateSubscription $service): void
    {
        $billable = $this->resolveBillable();
        if (! $billable) return;

        try {
            $service->clearPendingPlanChange($billable);
            \Flux::toast(__('billing::portal.flash.pending_change_cancelled'), variant: 'success');
        } catch (\Throwable $e) {
            report($e);
            \Flux::toast(__('billing::portal.flash.error'), variant: 'danger');
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
            \Flux::toast(__('billing::portal.flash.plan_changed'), variant: 'success');
        } catch (\Throwable $e) {
            report($e);
            \Flux::toast(
                config('app.debug') ? __('billing::portal.flash.error').' ('.$e->getMessage().')' : __('billing::portal.flash.error'),
                variant: 'danger',
            );
        }
    }

    public function applyChange(UpdateSubscription $service, string $applyAt = 'immediate'): void
    {
        $billable = $this->resolveBillable();

        if (! $billable || ! $this->selectedPlan) {
            \Flux::toast(__('billing::portal.flash.error'), variant: 'danger');
            return;
        }

        try {
            $updateData = [
                'plan_code' => $this->selectedPlan,
                'interval' => $this->selectedInterval,
                'apply_at' => $applyAt,
            ];

            if ($this->dropExtraSeats) {
                $catalog = app(SubscriptionCatalogInterface::class);
                $updateData['seats'] = max(
                    $billable->getUsedBillingSeats(),
                    $catalog->includedSeats($this->selectedPlan),
                );
            }

            $result = $service->update($billable, $updateData);

            if (! empty($result['pendingPaymentConfirmation'])) {
                $this->preview = [];
                $this->selectedPlan = null;
                return;
            }

            if (! empty($result['scheduledFor'])) {
                $date = \Carbon\Carbon::parse($result['scheduledFor'])->translatedFormat('d. M Y');
                \Flux::toast(__('billing::portal.flash.plan_scheduled', ['date' => $date]), variant: 'success');
            } else {
                \Flux::toast(__('billing::portal.flash.plan_changed'), variant: 'success');
            }
            $this->preview = [];
            $this->selectedPlan = null;
        } catch (\Throwable $e) {
            report($e);
            \Flux::toast(
                config('app.debug') ? __('billing::portal.flash.error').' ('.$e->getMessage().')' : __('billing::portal.flash.error'),
                variant: 'danger',
            );
        }
    }

    public function with(): array
    {
        $billable = $this->resolveBillable();
        $scheduledChange = null;
        $pendingPlanChange = null;
        $planChangeFailed = null;

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

            $pendingPlanChange = $meta['pending_plan_change'] ?? null;

            if (! empty($meta['plan_change_failed_at'])) {
                $failedAt = \Carbon\Carbon::parse($meta['plan_change_failed_at']);
                if ($failedAt->isAfter(now()->subDay())) {
                    $planChangeFailed = [
                        'failed_at' => $failedAt->translatedFormat('d. M Y, H:i'),
                        'reason' => $meta['plan_change_failed_reason'] ?? null,
                    ];
                }
            }

            // Detect pending → resolved transition (webhook applied the change).
            if ($this->wasPending && $pendingPlanChange === null) {
                if ($planChangeFailed) {
                    \Flux::toast(__('billing::portal.flash.plan_change_failed'), variant: 'danger');
                } else {
                    \Flux::toast(__('billing::portal.flash.plan_changed'), variant: 'success');
                }
            }
            $this->wasPending = $pendingPlanChange !== null;
        }

        return [
            'billable' => $billable,
            'plans' => app(SubscriptionCatalogInterface::class)->allPlans(),
            'catalog' => app(SubscriptionCatalogInterface::class),
            'scheduledChange' => $scheduledChange,
            'pendingPlanChange' => $pendingPlanChange,
            'planChangeFailed' => $planChangeFailed,
        ];
    }
};

?>

<div class="space-y-6" @if($pendingPlanChange) wire:poll.5s @endif>
    {{-- Page header --}}
    <div>
        <flux:heading size="xl">{{ __('billing::portal.plan_change') }}</flux:heading>
        <flux:subheading>
            {{ __('billing::portal.plan_change_subtitle') }}
        </flux:subheading>
    </div>

    @if ($planChangeFailed)
        <flux:callout variant="danger" icon="exclamation-triangle">
            {{ __('billing::portal.flash.plan_change_failed') }}
        </flux:callout>
    @endif

    @if ($pendingPlanChange)
        <flux:callout icon="arrow-path" color="blue" inline>
            {{ __('billing::portal.pending_plan_change_notice', ['plan' => $catalog->planName($pendingPlanChange['plan_code'] ?? '') ?? ($pendingPlanChange['plan_code'] ?? '')]) }}
            <div class="mt-2">
                <flux:button size="sm" wire:click="cancelPendingChange">
                    {{ __('billing::portal.cancel_pending_change') }}
                </flux:button>
            </div>
        </flux:callout>
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
        $hasPending = $pendingPlanChange !== null;
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
                        <flux:separator class="mt-4"/>
                            <ul class="space-y-1.5 mt-4">
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
                    @elseif (! $hasScheduled && ! $hasPending)
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
            $isUpgrade = $preview['isUpgrade'] ?? false;
            $isDowngrade = $preview['isDowngrade'] ?? false;
            $planChanged = $preview['planChanged'] ?? false;
            $intervalChanged = $preview['intervalChanged'] ?? false;
            $previewErrors = $preview['errors'] ?? [];
            $hasBlockingErrors = !empty($previewErrors);
            $incompatibleAddons = $preview['incompatibleAddons'] ?? [];
            $planChangeMode = $preview['planChangeMode'] ?? 'user_choice';
            $showImmediateOption = in_array($planChangeMode, ['immediate', 'user_choice']);
            $showScheduledOption = in_array($planChangeMode, ['end_of_period', 'user_choice']);
            $usageOverageNet = $preview['usageOverageChargeNet'] ?? 0;
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

            {{-- Change details: two-column layout --}}
            <div class="border-t border-zinc-200/75 px-6 py-5 dark:border-zinc-700/50">
                @php
                    $hasUsageChanges = !empty($preview['usageChanges'] ?? []);
                    $hasSeats = ($preview['currentIncludedSeats'] ?? 0) > 0 || ($preview['newIncludedSeats'] ?? 0) > 0;
                @endphp
                <div class="grid gap-6 {{ $hasUsageChanges ? 'sm:grid-cols-2' : '' }}">

                    {{-- Left column: Plan, Interval, Seats --}}
                    <div class="space-y-4">
                        {{-- Plan --}}
                        <div class="flex items-start gap-3">
                            <div class="mt-0.5 flex size-8 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                <flux:icon.squares-2x2 class="size-4 text-zinc-500" />
                            </div>
                            <div>
                                <flux:subheading size="sm" class="text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.plan') }}</flux:subheading>
                                <flux:text class="mt-0.5 font-medium">
                                    @if ($planChanged)
                                        {{ __('billing::portal.preview_plan_from_to', ['from' => $preview['currentPlanName'], 'to' => $preview['newPlanName']]) }}
                                    @else
                                        {{ __('billing::portal.preview_no_plan_change', ['plan' => $preview['newPlanName']]) }}
                                    @endif
                                </flux:text>
                            </div>
                        </div>

                        {{-- Interval --}}
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

                        {{-- Seats --}}
                        @if ($hasSeats)
                            @php
                                $previewExtraSeats = $preview['extraSeatsCharged'] ?? 0;
                                $previewNewSeats = $preview['newSeats'] ?? 0;
                                $previewNewIncluded = $preview['newIncludedSeats'] ?? 0;
                                $previewSeatPrice = $preview['seatPriceNet'] ?? 0;
                            @endphp
                            <div class="flex items-start gap-3">
                                <div class="mt-0.5 flex size-8 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                    <flux:icon.users class="size-4 text-zinc-500" />
                                </div>
                                <div>
                                    <flux:subheading size="sm" class="text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.seats') }}</flux:subheading>
                                    <flux:text class="mt-0.5 font-medium">
                                        @if ($preview['currentIncludedSeats'] !== $previewNewIncluded)
                                            {{ __('billing::portal.preview_seats_from_to', ['from' => $preview['currentIncludedSeats'], 'to' => $previewNewIncluded]) }}
                                        @else
                                            {{ trans_choice('billing::portal.seats_included_count', $previewNewIncluded, ['count' => $previewNewIncluded]) }}
                                        @endif
                                    </flux:text>
                                    <flux:text class="text-xs text-zinc-400 dark:text-zinc-500">
                                        {{ __('billing::portal.preview_seats_used', ['count' => $preview['usedSeats'] ?? 0]) }}
                                    </flux:text>
                                    @if ($previewExtraSeats > 0)
                                        <div class="mt-2 space-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                                            <div>{{ __('billing::portal.preview_seats_total', [
                                                'total' => $previewNewSeats,
                                                'included' => $previewNewIncluded,
                                                'extra' => $previewExtraSeats,
                                            ]) }}</div>
                                            @if ($previewSeatPrice > 0)
                                                <div class="text-zinc-600 dark:text-zinc-300">
                                                    {{ __('billing::portal.preview_seats_extra_price', [
                                                        'price' => $currencySymbol . number_format($previewSeatPrice / 100, 2),
                                                        'interval' => __('billing::portal.interval_' . $selectedInterval),
                                                    ]) }}
                                                </div>
                                            @endif
                                        </div>
                                        <div class="mt-2">
                                            <flux:button size="xs" variant="subtle" wire:click="toggleDropExtraSeats" icon="{{ $dropExtraSeats ? 'arrow-uturn-left' : 'x-mark' }}">
                                                {{ $dropExtraSeats ? __('billing::portal.preview_seats_keep_extra') : __('billing::portal.preview_seats_drop_extra') }}
                                            </flux:button>
                                        </div>
                                    @elseif ($this->dropExtraSeats)
                                        <div class="mt-2 text-xs text-emerald-600 dark:text-emerald-400">
                                            {{ __('billing::portal.preview_seats_extra_dropped') }}
                                        </div>
                                        <div class="mt-2">
                                            <flux:button size="xs" variant="subtle" wire:click="toggleDropExtraSeats" icon="arrow-uturn-left">
                                                {{ __('billing::portal.preview_seats_keep_extra') }}
                                            </flux:button>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>

                    {{-- Right column: Usage changes --}}
                    @if ($hasUsageChanges)
                        <div class="space-y-4 sm:border-l sm:border-zinc-200/75 sm:pl-6 sm:dark:border-zinc-700/50">
                            @foreach (($preview['usageChanges'] ?? []) as $usageType => $usage)
                                <div class="flex items-start gap-3">
                                    <div class="mt-0.5 flex size-8 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                        <flux:icon.chart-bar class="size-4 text-zinc-500" />
                                    </div>
                                    <div class="min-w-0 flex-1">
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
                                                    <span class="text-amber-600 dark:text-amber-400">({{ number_format($usage['diff']) }})</span>
                                                @endif
                                            @else
                                                {{ number_format($usage['new']) }} ({{ __('billing::portal.preview_no_change') }})
                                            @endif
                                        </flux:text>

                                        {{-- Current usage stand --}}
                                        <flux:text class="text-xs text-zinc-400 dark:text-zinc-500">
                                            {{ __('billing::portal.preview_usage_current_stand', [
                                                'used' => number_format($usage['actually_used'] ?? 0),
                                                'quota' => number_format($usage['current'] ?? 0),
                                            ]) }}
                                        </flux:text>

                                        {{-- Prorated usage settlement details --}}
                                        @if ($usage['diff'] !== 0 || ($usage['excess'] ?? 0) > 0 || ($usage['actually_used'] ?? 0) > 0)
                                            <div class="mt-2 space-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                                                @if (($usage['prorated_old_quota'] ?? 0) > 0)
                                                    <div>{{ __('billing::portal.preview_usage_used', [
                                                        'used' => number_format($usage['actually_used'] ?? 0),
                                                        'quota' => number_format($usage['prorated_old_quota']),
                                                    ]) }}</div>
                                                @endif
                                                @if (($usage['excess'] ?? 0) > 0)
                                                    <div>
                                                        {{ __('billing::portal.preview_usage_excess', [
                                                            'excess' => number_format($usage['excess']),
                                                        ]) }}
                                                    </div>
                                                    @if (($usage['offset_by_new_plan'] ?? 0) > 0)
                                                        <div>
                                                            {{ __('billing::portal.preview_usage_offset', [
                                                                'count' => number_format($usage['offset_by_new_plan']),
                                                            ]) }}
                                                        </div>
                                                    @endif
                                                    @if (($usage['rollover_credits'] ?? 0) > 0)
                                                        <div>
                                                            {{ __('billing::portal.preview_usage_rollover', [
                                                                'count' => number_format($usage['rollover_credits']),
                                                            ]) }}
                                                        </div>
                                                    @endif
                                                @endif
                                                @if (($usage['excess'] ?? 0) > 0)
                                                    <div>
                                                        {{ __('billing::portal.preview_usage_effective', [
                                                            'quota' => number_format($usage['effective_new_quota'] ?? $usage['new'] ?? 0),
                                                        ]) }}
                                                    </div>
                                                @endif
                                                @if (($usage['unresolved_overage'] ?? 0) > 0)
                                                    <div class="font-medium text-amber-600 dark:text-amber-400">
                                                        {{ __('billing::portal.preview_usage_overage_charge', [
                                                            'count' => number_format($usage['unresolved_overage']),
                                                        ]) }}
                                                    </div>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                </div>
            </div>

            {{-- Warnings & Errors --}}
            @if ($hasBlockingErrors || !empty($incompatibleAddons))
                <div class="border-t border-zinc-200/75 px-6 py-4 space-y-3 dark:border-zinc-700/50">
                    @foreach ($previewErrors as $error)
                        @if (($error['type'] ?? '') === 'seats_exceed_plan')
                            <flux:callout variant="danger" icon="exclamation-triangle">
                                {{ __('billing::portal.error_seats_exceed_plan', [
                                    'used' => $error['used'],
                                    'included' => $error['included'],
                                    'remove' => $error['used'] - $error['included'],
                                ]) }}
                            </flux:callout>
                        @endif
                    @endforeach

                    @if (!empty($incompatibleAddons))
                        <flux:callout variant="warning" icon="exclamation-triangle">
                            {{ __('billing::portal.warning_addons_removed', [
                                'addons' => collect($incompatibleAddons)->map(fn ($code) => $catalog->addonName($code) ?? $code)->join(', '),
                            ]) }}
                        </flux:callout>
                    @endif
                </div>
            @endif

            {{-- Pricing --}}
            @if ($isUpgrade || $isDowngrade || $planChanged || $intervalChanged)
                <div class="border-t border-zinc-200/75 dark:border-zinc-700/50" x-data="{ applyAt: '{{ $showImmediateOption ? 'immediate' : 'end_of_period' }}' }">

                    <div class="grid gap-0 sm:grid-cols-2">

                        {{-- Left panel: Due now --}}
                        <div class="relative bg-accent/5 px-6 py-6 dark:bg-accent/10 sm:rounded-bl-xl">
                            <div class="absolute inset-x-0 top-0 h-0.5 bg-accent sm:inset-y-0 sm:left-auto sm:right-0 sm:h-auto sm:w-0.5"></div>

                            <div class="mb-4">
                                <div class="flex items-center gap-2">
                                    <flux:icon.bolt class="size-4 text-accent" />
                                    <span x-show="applyAt === 'immediate'" class="text-xs font-semibold tracking-wide text-accent uppercase">{{ __('billing::portal.preview_due_now') }}</span>
                                    <span x-show="applyAt === 'end_of_period'" x-cloak class="text-xs font-semibold tracking-wide text-accent uppercase">{{ __('billing::portal.preview_due_now') }}</span>
                                </div>
                            </div>

                            {{-- Immediate view --}}
                            <div x-show="applyAt === 'immediate'" x-cloak>
                                @php
                                    if ($intervalChanged) {
                                        $dueNowNewPlan = $preview['newPriceNet'] ?? 0;
                                        $dueNowCredit = $preview['prorataCreditNet'] ?? 0;
                                    } else {
                                        $dueNowNewPlan = (int) round(($preview['newPriceNet'] ?? 0) * ($preview['prorataFactor'] ?? 0));
                                        $dueNowCredit = $preview['currentPeriodCredit'] ?? 0;
                                    }
                                    $dueNowNet = $dueNowNewPlan - $dueNowCredit + $usageOverageNet;
                                    $isCredit = $dueNowNet < 0;
                                    $dueNowVatRate = (float) ($preview['vatRate'] ?? 0);
                                    $dueNowVat = (int) round(abs($dueNowNet) * $dueNowVatRate / 100);
                                    $dueNowGross = $dueNowNet + ($isCredit ? -$dueNowVat : $dueNowVat);
                                @endphp
                                <div class="space-y-2">
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="text-zinc-600 dark:text-zinc-300">{{ __('billing::portal.preview_prorata_new_plan') }}</span>
                                        <span class="tabular-nums font-medium text-zinc-800 dark:text-zinc-300">{{ $currencySymbol }}{{ number_format($dueNowNewPlan / 100, 2) }}</span>
                                    </div>
                                    @if ($dueNowCredit > 0)
                                        <div class="flex items-center justify-between text-sm">
                                            <span class="text-zinc-600 dark:text-zinc-300">{{ __('billing::portal.preview_prorata_credit') }}</span>
                                            <span class="tabular-nums font-medium text-emerald-600 dark:text-emerald-400">−{{ $currencySymbol }}{{ number_format($dueNowCredit / 100, 2) }}</span>
                                        </div>
                                    @endif

                                    @if ($usageOverageNet > 0)
                                        @foreach (($preview['usageChanges'] ?? []) as $usageType => $usage)
                                            @if (($usage['unresolved_overage'] ?? 0) > 0 && ($usage['overage_total_net'] ?? 0) > 0)
                                                <div class="flex items-center justify-between text-sm">
                                                    <span class="text-zinc-600 dark:text-zinc-300">{{ __('billing::portal.preview_usage_overage_line', ['type' => ucfirst($usageType)]) }}</span>
                                                    <span class="tabular-nums font-medium text-zinc-700 dark:text-zinc-200">{{ $currencySymbol }}{{ number_format($usage['overage_total_net'] / 100, 2) }}</span>
                                                </div>
                                            @endif
                                        @endforeach
                                    @endif

                                    <flux:separator class="my-2!" />

                                    <div class="flex items-center justify-between text-sm">
                                        <span class="text-zinc-500 dark:text-zinc-400">{{ __('billing::portal.net') }}</span>
                                        <span class="tabular-nums {{ $isCredit ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-600 dark:text-zinc-300' }}">{{ $isCredit ? '−' : '' }}{{ $currencySymbol }}{{ number_format(abs($dueNowNet) / 100, 2) }}</span>
                                    </div>
                                    @if ($dueNowVat > 0)
                                        <div class="flex items-center justify-between text-sm">
                                            <span class="text-zinc-500 dark:text-zinc-400">{{ __('billing::portal.vat') }} ({{ number_format($dueNowVatRate, 0) }}%)</span>
                                            <span class="tabular-nums {{ $isCredit ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-600 dark:text-zinc-300' }}">{{ $isCredit ? '−' : '' }}{{ $currencySymbol }}{{ number_format($dueNowVat / 100, 2) }}</span>
                                        </div>
                                    @endif

                                    <flux:separator class="my-2!" />

                                    <div class="flex items-center justify-between">
                                        <span class="text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ __('billing::portal.preview_total') }}</span>
                                        <span class="text-2xl font-bold tabular-nums {{ $isCredit ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-900 dark:text-white' }}">{{ $isCredit ? '−' : '' }}{{ $currencySymbol }}{{ number_format(abs($dueNowGross) / 100, 2) }}</span>
                                    </div>

                                    @if ($isCredit)
                                        <flux:text class="text-xs text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.preview_credit_note') }}</flux:text>
                                    @endif
                                </div>
                            </div>

                            {{-- End-of-period view: no additional costs --}}
                            <div x-show="applyAt === 'end_of_period'" x-cloak>
                                <div class="space-y-2">
                                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                        {{ __('billing::portal.preview_no_additional_costs') }}
                                    </flux:text>

                                    <flux:separator class="my-2!" />

                                    <div class="flex items-center justify-between">
                                        <span class="text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ __('billing::portal.preview_total') }}</span>
                                        <span class="text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $currencySymbol }}0.00</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Right panel: Recurring price --}}
                        <div class="bg-zinc-50/80 px-6 py-6 dark:bg-white/[0.02] sm:rounded-br-xl">
                            <div class="mb-4 flex items-center gap-2">
                                <flux:icon.arrow-path class="size-4 text-zinc-400 dark:text-zinc-500" />
                                <span class="text-xs font-semibold tracking-wide text-accent uppercase">{{ __('billing::portal.preview_recurring_price') }}</span>
                            </div>

                            <div class="space-y-2">
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

                                <div class="flex items-center justify-between text-sm">
                                    <span class="text-zinc-500">{{ __('billing::portal.net') }}</span>
                                    <span class="tabular-nums text-zinc-600 dark:text-zinc-300">{{ $currencySymbol }}{{ number_format(($preview['newPriceNet'] ?? 0) / 100, 2) }}</span>
                                </div>
                                @if (($preview['vatAmount'] ?? 0) > 0)
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="text-zinc-500">{{ __('billing::portal.vat') }} ({{ number_format($preview['vatRate'] ?? 0, 0) }}%)</span>
                                        <span class="tabular-nums text-zinc-600 dark:text-zinc-300">{{ $currencySymbol }}{{ number_format($preview['vatAmount'] / 100, 2) }}</span>
                                    </div>
                                @endif

                                <flux:separator class="my-2!" />

                                <div class="flex items-center justify-between">
                                    <span class="text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ __('billing::portal.gross') }}</span>
                                    <span class="text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $currencySymbol }}{{ number_format(($preview['grossTotal'] ?? 0) / 100, 2) }}</span>
                                </div>
                                <flux:text class="text-xs">{{ __('billing::portal.preview_recurring_from_next_period') }}</flux:text>
                            </div>
                        </div>
                    </div>

                    {{-- Action bar: Switch + Button --}}
                    <div class="border-t border-zinc-200/75 px-6 py-4 dark:border-zinc-700/50">
                        <div class="flex items-center justify-between">
                            {{-- Switcher (left) --}}
                            @if ($showImmediateOption && $showScheduledOption)
                                <div class="inline-flex rounded-lg bg-zinc-100 p-0.5 dark:bg-zinc-800">
                                    <button type="button"
                                        class="rounded-md px-3 py-1.5 text-sm font-medium transition"
                                        :class="applyAt === 'immediate' ? 'bg-white shadow text-zinc-900 dark:bg-zinc-700 dark:text-white' : 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200'"
                                        @click="applyAt = 'immediate'">
                                        {{ __('billing::portal.apply_immediately') }}
                                    </button>
                                    <button type="button"
                                        class="rounded-md px-3 py-1.5 text-sm font-medium transition"
                                        :class="applyAt === 'end_of_period' ? 'bg-white shadow text-zinc-900 dark:bg-zinc-700 dark:text-white' : 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200'"
                                        @click="applyAt = 'end_of_period'">
                                        {{ __('billing::portal.schedule_end_of_period') }}
                                    </button>
                                </div>
                            @else
                                <div></div>
                            @endif

                            {{-- Button (right) --}}
                            @if ($hasBlockingErrors)
                                <flux:button variant="primary" size="sm" disabled>
                                    {{ __('billing::portal.apply_now') }}
                                </flux:button>
                            @else
                                <flux:button variant="primary" size="sm"
                                    x-on:click="$wire.applyChange(applyAt)">
                                    <span x-show="applyAt === 'immediate'">{{ __('billing::portal.apply_immediately') }}</span>
                                    <span x-show="applyAt === 'end_of_period'" x-cloak>{{ __('billing::portal.schedule_end_of_period') }}</span>
                                </flux:button>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            {{-- Action button (when no pricing section) --}}
            @if (! ($isUpgrade || $isDowngrade || $planChanged || $intervalChanged))
                <div class="border-t border-zinc-200/75 px-6 py-4 dark:border-zinc-700/50">
                    <div class="flex justify-end">
                        @if ($hasBlockingErrors)
                            <flux:button variant="primary" size="sm" disabled>
                                {{ __('billing::portal.apply_now') }}
                            </flux:button>
                        @else
                            <flux:button variant="primary" size="sm" wire:click="applyChange('immediate')">
                                {{ __('billing::portal.apply_immediately') }}
                            </flux:button>
                        @endif
                    </div>
                </div>
            @endif
        </flux:card>
    @endif
</div>
