<?php

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use Livewire\Component;

new class extends Component {
    public string $applyAt = 'immediate';
    public string $selectedInterval = 'monthly';
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

<div class="space-y-10">
    {{-- Page header --}}
    <div class="mb-2">
        <flux:heading size="xl">{{ __('billing::portal.plan_change') }}</flux:heading>
        <flux:text class="mt-2 text-zinc-500 dark:text-zinc-400">
            {{ __('billing::portal.plan_change_subtitle') }}
        </flux:text>
    </div>

    @if ($flash)
        <flux:callout variant="secondary" icon="information-circle" x-init="$el.scrollIntoView({ behavior: 'smooth', block: 'center' })">{{ $flash }}</flux:callout>
    @endif

    {{-- Controls --}}
    <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
        <flux:radio.group wire:model.live="selectedInterval" variant="segmented">
            <flux:radio value="monthly" label="{{ __('billing::portal.interval_monthly') }}" />
            <flux:radio value="yearly" label="{{ __('billing::portal.interval_yearly') }}" />
        </flux:radio.group>

        <flux:radio.group wire:model.live="applyAt" variant="segmented">
            <flux:radio value="immediate" label="{{ __('billing::portal.apply_immediate') }}" />
            <flux:radio value="end_of_period" label="{{ __('billing::portal.apply_period_end') }}" />
        </flux:radio.group>
    </div>

    {{-- Plan cards — horizontal scroll, min-width per card --}}
    @php
        $currentPlanCode = $billable?->getBillingSubscriptionPlanCode();
        $currency = config('mollie-billing.currency', 'EUR');
        $currencySymbol = $currency === 'EUR' ? '€' : $currency;
        $planCount = count($plans);
    @endphp

    <div class="-mx-2 overflow-x-auto px-2 pb-4">
        <div class="inline-flex gap-6" style="min-width: min(100%, {{ $planCount * 20 }}rem)">
            @foreach ($plans as $code)
                @php
                    $isCurrent = $currentPlanCode === $code;
                    $isSelected = $selectedPlan === $code;
                    $price = $catalog->basePriceNet($code, $selectedInterval);
                    $features = $catalog->planFeatures($code);
                    $seats = $catalog->includedSeats($code);
                    $savings = $selectedInterval === 'yearly' ? $catalog->yearlySavingsPercent($code) : 0;
                    $isFree = $price === 0;
                @endphp

                <div class="w-80 shrink-0">
                    <flux:card
                        class="relative flex h-full flex-col overflow-hidden transition-shadow hover:shadow-lg {{ $isSelected ? 'ring-2 ring-accent shadow-lg' : '' }}"
                    >
                        {{-- Accent bar --}}
                        <div class="absolute inset-x-0 top-0 h-1 {{ $isCurrent ? 'bg-emerald-500' : ($isSelected ? 'bg-accent' : 'bg-zinc-200 dark:bg-zinc-700') }}"></div>

                        <div class="flex-1 space-y-5 pt-2">
                            {{-- Plan name + badge --}}
                            <div class="flex items-start justify-between gap-2">
                                <flux:heading size="lg">{{ $catalog->planName($code) ?? $code }}</flux:heading>
                                @if ($isCurrent)
                                    <flux:badge size="sm" color="lime">{{ __('billing::portal.current') }}</flux:badge>
                                @endif
                            </div>

                            {{-- Price --}}
                            <div class="space-y-1">
                                <div class="flex items-baseline gap-1">
                                    <span class="text-3xl font-bold tracking-tight">
                                        @if ($isFree)
                                            {{ __('billing::portal.free') }}
                                        @else
                                            {{ $currencySymbol }}{{ number_format($price / 100, 2) }}
                                        @endif
                                    </span>
                                    @unless ($isFree)
                                        <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $selectedInterval === 'monthly' ? __('billing::portal.per_month') : __('billing::portal.per_year') }}</span>
                                    @endunless
                                </div>
                                @if ($savings > 0)
                                    <flux:badge size="sm" color="lime" icon="arrow-trending-down">{{ __('billing::portal.save_yearly', ['percent' => round($savings)]) }}</flux:badge>
                                @endif
                            </div>

                            <flux:separator />

                            {{-- Included info --}}
                            <div class="space-y-3 text-sm">
                                @if ($seats > 0)
                                    <div class="flex items-center gap-2.5 text-zinc-700 dark:text-zinc-300">
                                        <flux:icon.users class="size-4 text-zinc-400" />
                                        <span>{{ trans_choice('billing::portal.seats_included', $seats, ['count' => $seats]) }}</span>
                                    </div>
                                @endif

                                @if (count($features) > 0)
                                    <ul class="space-y-2">
                                        @foreach ($features as $feature)
                                            <li class="flex items-start gap-2.5">
                                                <flux:icon.check-circle class="mt-0.5 size-4 shrink-0 text-emerald-500" />
                                                <span class="text-zinc-700 dark:text-zinc-300">{{ $catalog->featureName($feature) }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        </div>

                        {{-- Action --}}
                        <div class="mt-8">
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
                </div>
            @endforeach
        </div>
    </div>

    {{-- Preview panel --}}
    @if ($selectedPlan && !empty($preview))
        <flux:card class="space-y-5">
            <div class="flex items-center gap-3">
                <div class="flex size-10 items-center justify-center rounded-full bg-accent/10">
                    <flux:icon.receipt-percent class="size-5 text-accent" />
                </div>
                <flux:heading size="lg">
                    {{ __('billing::portal.preview_for', ['plan' => $catalog->planName($selectedPlan) ?? $selectedPlan, 'interval' => $selectedInterval]) }}
                </flux:heading>
            </div>

            <dl class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach (['newPriceNet' => __('billing::portal.net'), 'vatAmount' => __('billing::portal.vat'), 'couponDiscountNet' => __('billing::portal.discount'), 'grossTotal' => __('billing::portal.gross')] as $key => $label)
                    @if (isset($preview[$key]) && $preview[$key] != 0)
                        <div class="flex items-center justify-between py-3 text-sm {{ $key === 'grossTotal' ? 'font-semibold text-base' : '' }}">
                            <dt class="{{ $key === 'grossTotal' ? '' : 'text-zinc-500 dark:text-zinc-400' }}">{{ $label }}</dt>
                            <dd class="tabular-nums {{ $key === 'grossTotal' ? '' : 'font-medium' }}">
                                {{ $currencySymbol }}{{ number_format(($preview[$key] ?? 0) / 100, 2) }}
                            </dd>
                        </div>
                    @endif
                @endforeach
            </dl>

            <div class="flex justify-end pt-2">
                <flux:button variant="primary" size="sm" wire:click="applyChange">
                    {{ $applyAt === 'end_of_period' ? __('billing::portal.schedule_change') : __('billing::portal.apply_now') }}
                </flux:button>
            </div>
        </flux:card>
    @endif
</div>
