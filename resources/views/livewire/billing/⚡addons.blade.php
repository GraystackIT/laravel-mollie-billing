<?php

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use Livewire\Component;

new class extends Component {
    public bool $polling = false;
    public ?string $pendingAddon = null;
    public ?string $pendingAction = null;

    private function resolveBillable(): ?Billable
    {
        return MollieBilling::resolveBillable(request());
    }

    public function enableAddon(string $addonCode): void
    {
        $billable = $this->resolveBillable();
        if (! $billable) return;

        try {
            $billable->enableBillingAddon($addonCode);
            $this->pendingAddon = $addonCode;
            $this->pendingAction = 'enabled';
            $this->polling = true;
        } catch (\Throwable $e) {
            report($e);
            \Flux::toast(__('billing::portal.flash.error'), variant: 'danger');
        }
    }

    public function disableAddon(string $addonCode): void
    {
        $billable = $this->resolveBillable();
        if (! $billable) return;

        try {
            $billable->disableBillingAddon($addonCode);
            $this->pendingAddon = $addonCode;
            $this->pendingAction = 'disabled';
            $this->polling = true;
        } catch (\Throwable $e) {
            report($e);
            \Flux::toast(__('billing::portal.flash.error'), variant: 'danger');
        }
    }

    public function pollForChange(): void
    {
        $billable = $this->resolveBillable();
        if (! $billable) return;

        $meta = $billable->getBillingSubscriptionMeta();

        if (empty($meta['pending_plan_change'])) {
            $this->polling = false;
            $name = $this->pendingAddon
                ? (app(SubscriptionCatalogInterface::class)->addonName($this->pendingAddon) ?? $this->pendingAddon)
                : null;
            $message = $name
                ? __("billing::portal.addons_flash.{$this->pendingAction}", ['addon' => $name])
                : __('billing::portal.flash.plan_changed');
            \Flux::toast($message, variant: 'success');
            $this->pendingAddon = null;
            $this->pendingAction = null;
        }
    }

    public function with(): array
    {
        $billable = $this->resolveBillable();
        $catalog = app(SubscriptionCatalogInterface::class);
        $interval = $billable?->getBillingSubscriptionInterval() ?? 'monthly';
        $planCode = $billable?->getBillingSubscriptionPlanCode();
        $activeAddons = $billable?->getActiveBillingAddonCodes() ?? [];
        $currency = config('mollie-billing.currency', 'EUR');
        $currencySymbol = $currency === 'EUR' ? '€' : $currency;

        $addons = [];
        foreach ($catalog->allAddons() as $code) {
            $allowed = $planCode ? $catalog->planAllowsAddon($planCode, $code) : false;
            $addons[] = [
                'code' => $code,
                'name' => $catalog->addonName($code) ?? $code,
                'price' => $catalog->addonPriceNet($code, $interval),
                'features' => $catalog->addonFeatures($code),
                'isActive' => in_array($code, $activeAddons, true),
                'isAllowed' => $allowed,
            ];
        }

        return [
            'billable' => $billable,
            'addons' => $addons,
            'interval' => $interval,
            'currencySymbol' => $currencySymbol,
            'catalog' => $catalog,
        ];
    }
};

?>

<div class="space-y-6" @if ($polling) wire:poll.3s="pollForChange" @endif>
    {{-- Page header --}}
    <div>
        <flux:heading size="xl">{{ __('billing::portal.addons') }}</flux:heading>
        <flux:subheading>
            {{ __('billing::portal.addons_subtitle') }}
        </flux:subheading>
    </div>

    @if ($polling)
        <flux:callout icon="arrow-path" color="blue" inline>{{ __('billing::portal.flash.plan_change_pending_payment') }}</flux:callout>
    @endif

    @if (! $billable)
        <flux:callout variant="warning" icon="exclamation-triangle">
            {{ __('billing::portal.no_billable') }}
        </flux:callout>
    @elseif (empty($addons))
        <flux:callout icon="information-circle" color="zinc" inline>
            {{ __('billing::portal.addons_none_available') }}
        </flux:callout>
    @else
        <div class="space-y-4">
            @foreach ($addons as $addon)
                <flux:card class="relative p-0! hover:shadow-md transition">
                    <div class="flex flex-col gap-4 px-6 py-6 sm:flex-row sm:items-start sm:justify-between">
                        {{-- Left: info --}}
                        <div class="flex items-start gap-4">
                            <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                                <flux:icon.puzzle-piece class="size-5 text-zinc-400" />
                            </div>
                            <div class="space-y-1">
                                <div class="flex items-center gap-3">
                                    <flux:heading size="lg">{{ $addon['name'] }}</flux:heading>
                                </div>
                                @if (count($addon['features']) > 0)
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach ($addon['features'] as $feature)
                                            <span class="inline-flex items-center gap-1 text-sm text-zinc-500 dark:text-zinc-400">
                                                <flux:icon.check class="size-3.5 text-emerald-500" />
                                                {{ $catalog->featureName($feature) }}
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>

                        {{-- Right: price + action --}}
                        <div class="flex items-center gap-6 sm:shrink-0">
                            @if ($addon['isActive'])
                                <flux:badge size="sm" color="lime">{{ __('billing::portal.active') }}</flux:badge>
                            @endif
                            <div class="text-right">
                                <div class="flex items-baseline gap-1">
                                    <span class="text-2xl font-bold tabular-nums tracking-tight">{{ $currencySymbol }}{{ number_format($addon['price'] / 100, 2) }}</span>
                                    <span class="text-sm text-zinc-400">{{ $interval === 'monthly' ? __('billing::portal.per_month') : __('billing::portal.per_year') }}</span>
                                </div>
                                <flux:text class="text-xs text-zinc-400">{{ __('billing::portal.prices_excl_vat') }}</flux:text>
                            </div>

                            <div class="w-32">
                                @if ($addon['isActive'])
                                    <flux:modal.trigger name="disable-addon-{{ $addon['code'] }}">
                                        <flux:button class="w-full" size="sm" variant="ghost" icon="x-circle">
                                            {{ __('billing::portal.addons_disable') }}
                                        </flux:button>
                                    </flux:modal.trigger>
                                @elseif ($addon['isAllowed'])
                                    <flux:modal.trigger name="enable-addon-{{ $addon['code'] }}">
                                        <flux:button class="w-full" size="sm" variant="primary">
                                            {{ __('billing::portal.addons_enable') }}
                                        </flux:button>
                                    </flux:modal.trigger>
                                @else
                                    <flux:button class="w-full" size="sm" variant="ghost" disabled>
                                        {{ __('billing::portal.addons_not_available') }}
                                    </flux:button>
                                @endif
                            </div>
                        </div>
                    </div>
                </flux:card>

                {{-- Enable confirm modal --}}
                @if (! $addon['isActive'] && $addon['isAllowed'])
                    <flux:modal name="enable-addon-{{ $addon['code'] }}" class="max-w-md">
                        <div class="space-y-6">
                            <div class="space-y-2">
                                <flux:heading size="lg">{{ __('billing::portal.addons_enable_confirm.title') }}</flux:heading>
                                <flux:text>{{ __('billing::portal.addons_enable_confirm.body', ['addon' => $addon['name'], 'price' => $currencySymbol . number_format($addon['price'] / 100, 2), 'interval' => $interval === 'monthly' ? __('billing::portal.per_month') : __('billing::portal.per_year')]) }}</flux:text>
                            </div>
                            <div class="flex justify-end gap-2">
                                <flux:modal.close>
                                    <flux:button variant="ghost">{{ __('billing::portal.addons_enable_confirm.cancel') }}</flux:button>
                                </flux:modal.close>
                                <flux:button variant="primary" wire:click="enableAddon('{{ $addon['code'] }}')" x-on:click="$flux.modal('enable-addon-{{ $addon['code'] }}').close()">
                                    {{ __('billing::portal.addons_enable_confirm.confirm') }}
                                </flux:button>
                            </div>
                        </div>
                    </flux:modal>
                @endif

                {{-- Disable confirm modal --}}
                @if ($addon['isActive'])
                    <flux:modal name="disable-addon-{{ $addon['code'] }}" class="max-w-md">
                        <div class="space-y-6">
                            <div class="space-y-2">
                                <flux:heading size="lg">{{ __('billing::portal.addons_disable_confirm.title') }}</flux:heading>
                                <flux:text>{{ __('billing::portal.addons_disable_confirm.body', ['addon' => $addon['name']]) }}</flux:text>
                            </div>
                            <div class="flex justify-end gap-2">
                                <flux:modal.close>
                                    <flux:button variant="ghost">{{ __('billing::portal.addons_disable_confirm.keep') }}</flux:button>
                                </flux:modal.close>
                                <flux:button variant="danger" wire:click="disableAddon('{{ $addon['code'] }}')" x-on:click="$flux.modal('disable-addon-{{ $addon['code'] }}').close()">
                                    {{ __('billing::portal.addons_disable_confirm.confirm') }}
                                </flux:button>
                            </div>
                        </div>
                    </flux:modal>
                @endif
            @endforeach
        </div>
    @endif
</div>
