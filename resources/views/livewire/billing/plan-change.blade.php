<?php

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use Livewire\Component;

new class extends Component {
    public ?Billable $billable = null;
    public string $applyAt = 'immediate';
    public string $selectedInterval = 'monthly';
    public ?string $selectedPlan = null;
    public array $preview = [];
    public ?string $flash = null;

    public function mount(): void
    {
        $this->billable = MollieBilling::resolveBillable(request());
    }

    public function previewFor(string $planCode, PreviewService $service): void
    {
        $this->selectedPlan = $planCode;
        if ($this->billable) {
            $this->preview = $service->previewPlanChange($this->billable, $planCode, $this->selectedInterval);
        }
    }

    public function commit(UpdateSubscription $service): void
    {
        if (! $this->billable || ! $this->selectedPlan) return;
        try {
            $service->update($this->billable, [
                'plan_code' => $this->selectedPlan,
                'interval' => $this->selectedInterval,
                'apply_at' => $this->applyAt,
            ]);
            $this->flash = __('billing::portal.flash.plan_changed');
            $this->preview = [];
            $this->selectedPlan = null;
        } catch (\Throwable $e) {
            $this->flash = $e->getMessage();
        }
    }

    public function with(): array
    {
        return [
            'plans' => app(SubscriptionCatalogInterface::class)->allPlans(),
            'catalog' => app(SubscriptionCatalogInterface::class),
        ];
    }
};

?>

<div class="space-y-6">
    <flux:heading size="xl">{{ __('billing::portal.plan_change') }}</flux:heading>

    @if ($flash)
        <flux:callout variant="secondary" icon="information-circle">{{ $flash }}</flux:callout>
    @endif

    <flux:card class="space-y-4">
        <flux:radio.group wire:model.live="selectedInterval" variant="segmented" label="{{ __('billing::portal.interval') }}">
            <flux:radio value="monthly" label="{{ __('billing::portal.interval_monthly') }}" />
            <flux:radio value="yearly" label="{{ __('billing::portal.interval_yearly') }}" />
        </flux:radio.group>

        <flux:radio.group wire:model.live="applyAt" variant="segmented" label="{{ __('billing::portal.apply_at') }}">
            <flux:radio value="immediate" label="{{ __('billing::portal.apply_immediate') }}" />
            <flux:radio value="end_of_period" label="{{ __('billing::portal.apply_period_end') }}" />
        </flux:radio.group>
    </flux:card>

    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        @foreach ($plans as $code)
            <flux:card class="space-y-3">
                <div>
                    <flux:heading size="lg">{{ $catalog->planName($code) ?? $code }}</flux:heading>
                    <flux:text size="sm" class="text-zinc-500">
                        {{ number_format($catalog->basePriceNet($code, $selectedInterval) / 100, 2) }} / {{ $selectedInterval }}
                    </flux:text>
                </div>
                <flux:button size="sm" variant="primary" wire:click="previewFor('{{ $code }}')">
                    {{ __('billing::portal.preview') }}
                </flux:button>
            </flux:card>
        @endforeach
    </div>

    @if ($selectedPlan && !empty($preview))
        <flux:card class="space-y-3">
            <flux:heading size="lg">
                {{ __('billing::portal.preview_for', ['plan' => $catalog->planName($selectedPlan) ?? $selectedPlan, 'interval' => $selectedInterval]) }}
            </flux:heading>
            <dl class="grid grid-cols-2 gap-2 text-sm">
                @foreach (['netTotal' => __('billing::portal.net'), 'vatTotal' => __('billing::portal.vat'), 'grossTotal' => __('billing::portal.gross'), 'discountTotal' => __('billing::portal.discount')] as $key => $label)
                    @if (isset($preview[$key]))
                        <dt class="text-zinc-500">{{ $label }}</dt>
                        <dd class="font-medium text-right">{{ number_format(($preview[$key] ?? 0) / 100, 2) }}</dd>
                    @endif
                @endforeach
            </dl>
            <div class="flex justify-end">
                <flux:button variant="primary" wire:click="commit">
                    {{ $applyAt === 'end_of_period' ? __('billing::portal.schedule_change') : __('billing::portal.apply_now') }}
                </flux:button>
            </div>
        </flux:card>
    @endif
</div>
