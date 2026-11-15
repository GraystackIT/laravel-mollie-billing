@php
    // Never offer an interval no selectable plan is sold in: an unpriced
    // (plan, interval) pair costs 0, and the zero-amount checkout branch reads
    // that as a free plan. With a plan already chosen the toggle narrows to that
    // plan's own intervals; before then it shows any interval some plan offers.
    $offeredIntervals = $this->offeredIntervals($plan_code);
@endphp

<div class="flex flex-col items-center gap-2 pt-4">
    @if (count($offeredIntervals) > 1)
        <flux:radio.group wire:model.live="interval" variant="segmented">
            @foreach ($offeredIntervals as $offered)
                <flux:radio :value="$offered" :label="__('billing::checkout.' . $offered)" />
            @endforeach
        </flux:radio.group>
    @endif
    <flux:text class="text-xs mb-4">
        @if ($showsNet)
            {{ __('billing::checkout.prices_net') }}
        @else
            {{ __('billing::checkout.prices_incl_vat', ['rate' => rtrim(rtrim(number_format($vatRate, 1), '0'), '.'), 'country' => $this->countries()[$billing_country] ?? $billing_country]) }}
        @endif
    </flux:text>
</div>

@php
    $plans          = $this->plans();
    $planCount      = count($plans);

    // One line per entitlement: the included amount stays in prose on the left,
    // the per-extra-unit price is right-aligned on the SAME line (it used to sit
    // on a second, indented line and doubled the height of every card).
    // Deliberately plain flex utilities — an arbitrary `grid-cols-[…]` template
    // silently degrades to a single column when the app's CSS build has not
    // generated it, which turns the rows into three stacked lines.
    $quotaRowClass  = 'flex items-baseline justify-between gap-3 text-zinc-600 dark:text-zinc-300';
    $quotaPriceClass = 'shrink-0 text-xs tabular-nums text-zinc-500 dark:text-zinc-400';
@endphp

@if ($planCount === 1)
    @php
        $code         = array_key_first($plans);
        $plan         = $plans[$code];
        $netCents     = (int) ($plan['intervals'][$interval]['base_price_net'] ?? 0);
        $displayCents = $priceFormatter($netCents);
        $seatPriceNet = $plan['intervals'][$interval]['seat_price_net'] ?? null;
        $planUsages   = $this->planUsages($code, $interval);
        $planAddons   = $this->planAddons($code, $interval);
        $planFeatures = $this->planFeatures($code);
        $trialDays    = $this->trialApplies($code, $interval) ? $this->planTrialDays($code, $interval) : 0;
    @endphp

    {{-- Single-plan layout: horizontal card, full width --}}
    <div class="relative overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/2">
        <div class="absolute inset-x-0 top-0 h-1 bg-accent"></div>

        <div class="grid gap-6 p-6 md:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)] md:gap-10 md:p-8">
            {{-- Left: identity + price --}}
            <div class="flex flex-col justify-center gap-4 border-b border-zinc-200 pb-8 pr-0 md:border-b-0 md:border-r md:pb-0 md:pr-12 lg:pr-16 dark:border-white/10">
                <div class="text-2xl font-semibold text-zinc-900 dark:text-white">{{ $plan['name'] }}</div>

                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-2">
                    <div class="flex items-baseline gap-1.5">
                        <span class="text-5xl font-bold tracking-tight tabular-nums">{{ $money($displayCents) }}</span>
                        <span class="text-base text-zinc-500 dark:text-zinc-400">/{{ $interval === 'monthly' ? __('billing::checkout.per_month') : __('billing::checkout.per_year') }}</span>
                    </div>
                    @if ($trialDays > 0)
                        <flux:badge size="sm" color="lime" class="ms-auto self-center">
                            {{ __('billing::checkout.trial_days', ['days' => $trialDays]) }}
                        </flux:badge>
                    @endif
                </div>
            </div>

            {{-- Right: quotas + features + addons --}}
            <div class="space-y-5">
                {{-- Included quotas: one line each, extra-unit price right-aligned --}}
                <div class="max-w-md space-y-1.5 text-sm">
                    <div class="{{ $quotaRowClass }}">
                        <span>{{ trans_choice('billing::checkout.seats_included', $plan['included_seats'] ?? 0, ['count' => $plan['included_seats'] ?? 0]) }}</span>
                        @if ($seatPriceNet !== null && $seatPriceNet > 0)
                            <span class="{{ $quotaPriceClass }}" title="{{ __('billing::checkout.extra_seat_cost', ['currency' => $currencySymbol, 'price' => $moneyNumber($priceFormatter($seatPriceNet))]) }}">{{ __('billing::checkout.quota_extra_price', ['currency' => $currencySymbol, 'price' => $moneyNumber($priceFormatter($seatPriceNet)), 'unit' => __('billing::checkout.seat_unit')]) }}</span>
                        @endif
                    </div>

                    @foreach ($planUsages as $usage)
                        <div class="{{ $quotaRowClass }}">
                            <span>{{ __('billing::checkout.usage_included', ['count' => number_format($usage['included']), 'type' => $usage['label']]) }}</span>
                            @if (($usage['overage_price'] ?? 0) > 0)
                                <span class="{{ $quotaPriceClass }}" title="{{ __('billing::checkout.usage_overage', ['currency' => $currencySymbol, 'price' => $moneyNumber($priceFormatter($usage['overage_price'])), 'type' => $usage['label']]) }}">{{ __('billing::checkout.quota_extra_price', ['currency' => $currencySymbol, 'price' => $moneyNumber($priceFormatter($usage['overage_price'])), 'unit' => $usage['label']]) }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>

                {{-- Features --}}
                @if (! empty($planFeatures))
                    <flux:separator />

                    <ul class="grid gap-2 text-sm sm:grid-cols-2">
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

                {{-- Add-ons --}}
                @if (! empty($planAddons))
                    <flux:separator />

                    <div class="space-y-1.5">
                        <div class="text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('billing::checkout.available_addons') }}</div>
                        <div class="grid gap-x-6 gap-y-1 sm:grid-cols-2">
                            @foreach ($planAddons as $addon)
                                <div class="flex items-baseline justify-between gap-3 text-sm">
                                    <span class="text-zinc-600 dark:text-zinc-300" @if (! empty($addon['description'])) title="{{ $addon['description'] }}" @endif>{{ $addon['name'] }}</span>
                                    <span class="shrink-0 text-xs tabular-nums text-zinc-500 dark:text-zinc-400">{{ __('billing::checkout.addon_price', ['currency' => $currencySymbol, 'price' => $moneyNumber($priceFormatter($addon['price_net'])), 'interval' => $interval === 'monthly' ? __('billing::checkout.per_month') : __('billing::checkout.per_year')]) }}@if (($addon['seat_price_net'] ?? null) !== null && $addon['seat_price_net'] > 0) {{ __('billing::checkout.addon_seat_price', ['price' => $currencySymbol.$moneyNumber($priceFormatter($addon['seat_price_net']))]) }}@endif</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
@else
    @php
        $gridClass = $planCount === 2
            ? 'grid gap-4 sm:grid-cols-2 mx-auto w-full max-w-3xl'
            : 'grid gap-4 md:grid-cols-3';
    @endphp

    <div class="{{ $gridClass }}">
        @foreach ($plans as $code => $plan)
            @php
                $netCents     = (int) ($plan['intervals'][$interval]['base_price_net'] ?? 0);
                $displayCents = $priceFormatter($netCents);
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
                            <span class="text-3xl font-bold tracking-tight tabular-nums">{{ $money($displayCents) }}</span>
                            <span class="text-sm text-zinc-500 dark:text-zinc-400">/{{ $interval === 'monthly' ? __('billing::checkout.per_month') : __('billing::checkout.per_year') }}</span>
                        </div>
                        @php($trialDays = $this->trialApplies($code, $interval) ? $this->planTrialDays($code, $interval) : 0)
                        @if ($trialDays > 0)
                            <div class="mt-2">
                                <flux:badge size="sm" color="lime">
                                    {{ __('billing::checkout.trial_days', ['days' => $trialDays]) }}
                                </flux:badge>
                            </div>
                        @endif
                    </div>

                    <flux:separator />

                    {{-- Included quotas: one line each, extra-unit price right-aligned --}}
                    <div class="space-y-1.5 text-sm">
                        <div class="{{ $quotaRowClass }}">
                            <span>{{ trans_choice('billing::checkout.seats_included', $plan['included_seats'] ?? 0, ['count' => $plan['included_seats'] ?? 0]) }}</span>
                            @if ($seatPriceNet !== null && $seatPriceNet > 0)
                                <span class="{{ $quotaPriceClass }}" title="{{ __('billing::checkout.extra_seat_cost', ['currency' => $currencySymbol, 'price' => $moneyNumber($priceFormatter($seatPriceNet))]) }}">{{ __('billing::checkout.quota_extra_price', ['currency' => $currencySymbol, 'price' => $moneyNumber($priceFormatter($seatPriceNet)), 'unit' => __('billing::checkout.seat_unit')]) }}</span>
                            @endif
                        </div>

                        @foreach ($planUsages as $usage)
                            <div class="{{ $quotaRowClass }}">
                                <span>{{ __('billing::checkout.usage_included', ['count' => number_format($usage['included']), 'type' => $usage['label']]) }}</span>
                                @if (($usage['overage_price'] ?? 0) > 0)
                                    <span class="{{ $quotaPriceClass }}" title="{{ __('billing::checkout.usage_overage', ['currency' => $currencySymbol, 'price' => $moneyNumber($priceFormatter($usage['overage_price'])), 'type' => $usage['label']]) }}">{{ __('billing::checkout.quota_extra_price', ['currency' => $currencySymbol, 'price' => $moneyNumber($priceFormatter($usage['overage_price'])), 'unit' => $usage['label']]) }}</span>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    {{-- Features: names only. Descriptions would stack several
                         lines per row and make the cards impossible to compare —
                         they stay available on hover and on the confirm step. --}}
                    @if (! empty($planFeatures))
                        <flux:separator />

                        <ul class="space-y-1.5 text-sm">
                            @foreach ($planFeatures as $feature)
                                <li class="flex items-start gap-2">
                                    <flux:icon.check class="mt-0.5 size-4 shrink-0 text-emerald-500" />
                                    <span class="text-zinc-700 dark:text-zinc-200" @if (! empty($feature['description'])) title="{{ $feature['description'] }}" @endif>{{ $feature['name'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                {{-- Add-ons — pushed to bottom. Always the complete list: an app
                     may not hide sellable options behind a "+N more" counter. --}}
                @if (! empty($planAddons))
                    <div class="mt-6 space-y-1.5 border-t border-zinc-200 pt-4 dark:border-white/10">
                        <div class="text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('billing::checkout.available_addons') }}</div>
                        @foreach ($planAddons as $addon)
                            <div class="flex items-baseline justify-between gap-3 text-sm">
                                <span class="text-zinc-600 dark:text-zinc-300" @if (! empty($addon['description'])) title="{{ $addon['description'] }}" @endif>{{ $addon['name'] }}</span>
                                <span class="shrink-0 text-xs tabular-nums text-zinc-500 dark:text-zinc-400">{{ __('billing::checkout.addon_price', ['currency' => $currencySymbol, 'price' => $moneyNumber($priceFormatter($addon['price_net'])), 'interval' => $interval === 'monthly' ? __('billing::checkout.per_month') : __('billing::checkout.per_year')]) }}@if (($addon['seat_price_net'] ?? null) !== null && $addon['seat_price_net'] > 0) {{ __('billing::checkout.addon_seat_price', ['price' => $currencySymbol.$moneyNumber($priceFormatter($addon['seat_price_net']))]) }}@endif</span>
                            </div>
                        @endforeach
                    </div>
                @endif

            </button>
        @endforeach
    </div>
@endif
<flux:error name="plan_code" />

<div class="flex items-center justify-between pt-2">
    @if ($billing_locked && $customStepCount === 0)
        <div></div>
    @else
        <flux:button wire:click="back" variant="ghost" icon="arrow-left">{{ __('billing::checkout.back') }}</flux:button>
    @endif
    <flux:button wire:click="next" variant="primary" icon:trailing="arrow-right">{{ __('billing::checkout.continue') }}</flux:button>
</div>
