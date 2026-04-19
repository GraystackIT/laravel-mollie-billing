<div class="flex flex-col items-center gap-2 pt-4">
    <flux:radio.group wire:model.live="interval" variant="segmented">
        <flux:radio value="monthly" :label="__('billing::checkout.monthly')" />
        <flux:radio value="yearly" :label="__('billing::checkout.yearly')" />
    </flux:radio.group>
    <flux:text class="text-xs mb-4">
        @if ($showsNet)
            {{ __('billing::checkout.prices_net') }}
        @else
            {{ __('billing::checkout.prices_incl_vat', ['rate' => rtrim(rtrim(number_format($vatRate, 1), '0'), '.'), 'country' => $this->countries()[$billing_country] ?? $billing_country]) }}
        @endif
    </flux:text>
</div>

<div class="grid gap-4 md:grid-cols-3">
    @foreach ($this->plans() as $code => $plan)
        @php
            $currencySymbol = config('mollie-billing.currency_symbol', '€');
            $netCents     = (int) ($plan['intervals'][$interval]['base_price_net'] ?? 0);
            $displayCents = $priceFormatter($netCents);
            $amount       = $displayCents / 100;
            $isSelected   = $plan_code === $code;
            $seatPriceNet = $plan['intervals'][$interval]['seat_price_net'] ?? null;
            $planUsages   = $this->planUsages($code, $interval);
            $planAddons   = $this->planAddons($code, $interval);
            $planFeatures = $this->planFeatures($code);
        @endphp

        <button
            type="button"
            wire:click="$set('plan_code', '{{ $code }}')"
            class="relative flex flex-col overflow-hidden rounded-lg border bg-white p-6 text-left transition hover:shadow-md dark:bg-white/2
                {{ $isSelected
                    ? 'border-accent ring-2 ring-accent/40 shadow-lg dark:border-accent dark:ring-accent/30'
                    : 'border-zinc-200 shadow-sm hover:-translate-y-0.5 dark:border-white/10 dark:hover:border-white/20' }}"
        >
            {{-- Top accent strip --}}
            <div class="absolute inset-x-0 top-0 h-1 {{ $isSelected ? 'bg-accent' : 'bg-transparent' }}"></div>

            {{-- ─── Content wrapper with consistent vertical rhythm ─── --}}
            <div class="flex flex-1 flex-col space-y-4">

                {{-- Plan name + selection indicator --}}
                <div class="flex items-start justify-between gap-2">
                    <div class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $plan['name'] }}</div>
                    @if ($isSelected)
                        <div class="flex size-6 shrink-0 items-center justify-center rounded-full bg-accent text-white shadow-sm">
                            <flux:icon.check class="size-3.5" />
                        </div>
                    @endif
                </div>

                {{-- Price + trial badge --}}
                <div>
                    <div class="flex items-baseline gap-1">
                        <span class="text-3xl font-bold tracking-tight tabular-nums">{{ $currencySymbol }}{{ number_format($amount, 0) }}</span>
                        <span class="text-sm text-zinc-500 dark:text-zinc-400">/{{ $interval === 'monthly' ? __('billing::checkout.per_month') : __('billing::checkout.per_year') }}</span>
                    </div>
                    @if (! empty($plan['trial_days']))
                        <div class="mt-2">
                            <flux:badge size="sm" color="lime">
                                {{ __('billing::checkout.trial_days', ['days' => $plan['trial_days']]) }}
                            </flux:badge>
                        </div>
                    @endif
                </div>

                <flux:separator />

                {{-- Quotas: seats + usages --}}
                <div class="space-y-2 text-sm">
                    {{-- Seats --}}
                    <div class="text-zinc-600 dark:text-zinc-300">
                        <div class="flex items-center gap-2">
                            <flux:icon.users class="size-4 shrink-0 text-zinc-400" />
                            <span>{{ __('billing::checkout.seats_included', ['count' => $plan['included_seats'] ?? 0]) }}</span>
                        </div>
                        @if ($seatPriceNet !== null)
                            <div class="ml-6 text-xs tabular-nums text-zinc-500 dark:text-zinc-400">{{ __('billing::checkout.extra_seat_cost', ['currency' => $currencySymbol, 'price' => number_format($priceFormatter($seatPriceNet) / 100, 2)]) }}</div>
                        @endif
                    </div>

                    {{-- Usages --}}
                    @foreach ($planUsages as $usageType => $usage)
                        <div class="text-zinc-600 dark:text-zinc-300">
                            <div class="flex items-center gap-2">
                                <flux:icon.chart-bar class="size-4 shrink-0 text-zinc-400" />
                                <span>{{ __('billing::checkout.usage_included', ['count' => number_format($usage['included']), 'type' => $usageType]) }}</span>
                            </div>
                            @if ($usage['overage_price'] !== null && $usage['overage_price'] > 0)
                                <div class="ml-6 text-xs tabular-nums text-zinc-500 dark:text-zinc-400">{{ __('billing::checkout.usage_overage', ['currency' => $currencySymbol, 'price' => number_format($priceFormatter($usage['overage_price']) / 100, 2), 'type' => $usageType]) }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>

                {{-- Features --}}
                @if (! empty($planFeatures))
                    <flux:separator />

                    <ul class="space-y-2 text-sm">
                        @foreach ($planFeatures as $feature)
                            <li class="flex items-start gap-2">
                                <flux:icon.check class="mt-0.5 size-4 shrink-0 text-emerald-500" />
                                <div>
                                    <span class="text-zinc-700 dark:text-zinc-200">{{ $feature['name'] }}</span>
                                    @if (! empty($feature['description']))
                                        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $feature['description'] }}</p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- Add-ons — pushed to bottom --}}
            @if (! empty($planAddons))
                <div class="mt-6 space-y-1.5 border-t border-zinc-200 pt-4 dark:border-white/10">
                    <div class="text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('billing::checkout.available_addons') }}</div>
                    @foreach ($planAddons as $addonCode => $addon)
                        <div class="flex items-baseline justify-between text-sm">
                            <span class="text-zinc-600 dark:text-zinc-300">{{ $addon['name'] }}</span>
                            <span class="text-xs tabular-nums text-zinc-500 dark:text-zinc-400">{{ __('billing::checkout.addon_price', ['currency' => $currencySymbol, 'price' => number_format($priceFormatter($addon['price_net']) / 100, 2), 'interval' => $interval === 'monthly' ? __('billing::checkout.per_month') : __('billing::checkout.per_year')]) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif

        </button>
    @endforeach
</div>
<flux:error name="plan_code" />

<div class="flex items-center justify-between pt-2">
    <flux:button wire:click="back" variant="ghost" icon="arrow-left">{{ __('billing::checkout.back') }}</flux:button>
    <flux:button wire:click="next" variant="primary" icon:trailing="arrow-right">{{ __('billing::checkout.continue') }}</flux:button>
</div>
    