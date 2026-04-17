<div class="flex flex-col items-center gap-2">
    <flux:radio.group wire:model.live="interval" variant="segmented">
        <flux:radio value="monthly" :label="__('billing::checkout.monthly')" />
        <flux:radio value="yearly" :label="__('billing::checkout.yearly')" />
    </flux:radio.group>
    <flux:text class="text-xs">
        @if ($showsNet)
            {{ __('billing::checkout.prices_net') }}
        @else
            {{ __('billing::checkout.prices_incl_vat', ['rate' => rtrim(rtrim(number_format($vatRate, 1), '0'), '.'), 'country' => $this->countries()[$billing_country] ?? $billing_country]) }}
        @endif
    </flux:text>
</div>

<div class="grid gap-4 md:grid-cols-3">
    @foreach ($this->plans() as $code => $plan)
        @php($netCents = (int) ($plan['intervals'][$interval]['base_price_net'] ?? 0))
        @php($displayCents = $priceFormatter($netCents))
        @php($amount = $displayCents / 100)
        @php($isSelected = $plan_code === $code)
        <button
            type="button"
            wire:click="$set('plan_code', '{{ $code }}')"
            class="group relative flex flex-col items-start gap-4 rounded-xl border p-6 text-left transition duration-200
                {{ $isSelected
                    ? 'border-accent bg-accent/5 ring-2 ring-accent/40 dark:border-accent dark:bg-accent/8 dark:ring-accent/30'
                    : 'border-zinc-200 bg-white hover:-translate-y-0.5 hover:border-zinc-400 hover:shadow-md dark:border-white/10 dark:bg-white/2 dark:hover:border-white/30 dark:hover:bg-white/4' }}"
        >
            <div class="flex w-full items-start justify-between">
                <div class="flex flex-col gap-1">
                    <span class="text-xs font-medium uppercase tracking-wider {{ $isSelected ? 'text-accent dark:text-accent' : 'text-zinc-500 dark:text-zinc-400' }}">
                        {{ __('billing::checkout.tier', ['tier' => $plan['tier'] ?? 1]) }}
                    </span>
                    <span class="text-lg font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $plan['name'] }}</span>
                </div>
                @if (! empty($plan['trial_days']))
                    <span class="rounded-full border border-amber-300 bg-amber-50 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wider text-amber-700 dark:border-amber-400/40 dark:bg-amber-400/10 dark:text-amber-300">
                        {{ __('billing::checkout.trial_days', ['days' => $plan['trial_days']]) }}
                    </span>
                @endif
            </div>

            <div class="flex items-baseline gap-1">
                <span class="text-4xl font-semibold tracking-tight tabular-nums text-zinc-900 dark:text-white">€{{ number_format($amount, 0) }}</span>
                <span class="text-sm text-zinc-500 dark:text-zinc-400">
                    /{{ $interval === 'monthly' ? __('billing::checkout.per_month') : __('billing::checkout.per_year') }}
                </span>
            </div>

            <div class="text-sm text-zinc-600 dark:text-zinc-300">
                {{ __('billing::checkout.seats_included', ['count' => $plan['included_seats'] ?? 0]) }}
            </div>

            @php($planFeatures = $this->planFeatures($code))
            @if (! empty($planFeatures))
                <ul class="flex w-full flex-col gap-2 border-t border-zinc-200 pt-4 dark:border-white/10">
                    @foreach ($planFeatures as $feature)
                        <li class="flex items-start gap-2 text-sm text-zinc-700 dark:text-zinc-200">
                            <flux:icon.check class="mt-0.5 size-4 shrink-0 {{ $isSelected ? 'text-accent' : 'text-zinc-400 dark:text-zinc-500' }}" />
                            <span class="flex flex-col">
                                <span class="font-medium">{{ $feature['name'] }}</span>
                                @if (! empty($feature['description']))
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $feature['description'] }}</span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($isSelected)
                <div class="absolute right-3 top-3 flex size-6 items-center justify-center rounded-full bg-accent text-white shadow-sm">
                    <flux:icon.check class="size-4" />
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
