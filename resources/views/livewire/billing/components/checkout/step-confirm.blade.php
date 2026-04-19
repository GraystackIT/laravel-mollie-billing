@php($plan = $this->selectedPlan())

<div class="grid gap-4 lg:grid-cols-5">
    <section class="rounded-xl border border-zinc-200/80 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/2 lg:col-span-3">
        <div class="flex items-center gap-2">
            <flux:icon.building-office class="size-4 text-zinc-500" />
            <flux:heading size="sm">{{ __('billing::checkout.billing_details') }}</flux:heading>
        </div>
        <dl class="mt-4 space-y-3 text-sm">
            <div class="flex justify-between gap-6">
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('billing::checkout.company_name') }}</dt>
                <dd class="text-right font-medium">{{ $company_name }}</dd>
            </div>
            <div class="flex justify-between gap-6">
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('billing::checkout.address') }}</dt>
                <dd class="text-right font-medium">
                    {{ $billing_street }}<br>
                    {{ $billing_postal_code }} {{ $billing_city }}<br>
                    {{ $this->countries()[$billing_country] ?? $billing_country }}
                </dd>
            </div>
            @if (filled($vat_number))
                <div class="flex justify-between gap-6">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('billing::checkout.vat_number') }}</dt>
                    <dd class="text-right font-medium tabular-nums">{{ $vat_number }}</dd>
                </div>
            @endif
        </dl>
    </section>

    <section class="rounded-xl border border-zinc-200/80 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/2 lg:col-span-2">
        <div class="flex items-center gap-2">
            <flux:icon.credit-card class="size-4 text-zinc-500" />
            <flux:heading size="sm">{{ __('billing::checkout.order') }}</flux:heading>
        </div>
        <dl class="mt-4 space-y-3 text-sm">
            <div class="flex justify-between gap-6">
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('billing::checkout.plan') }}</dt>
                <dd class="text-right font-medium">{{ $plan['name'] ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-6">
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('billing::checkout.billing_interval') }}</dt>
                <dd class="text-right font-medium">{{ $interval === 'monthly' ? __('billing::checkout.monthly') : __('billing::checkout.yearly') }}</dd>
            </div>
            @if (! empty($addon_codes))
                <div class="flex justify-between gap-6">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('billing::checkout.addons_heading') }}</dt>
                    <dd class="text-right font-medium">
                        @foreach ($addon_codes as $addonCode)
                            @php($addon = $this->addons()[$addonCode] ?? null)
                            @if ($addon)
                                <div>{{ $addon['name'] }}</div>
                            @endif
                        @endforeach
                    </dd>
                </div>
            @endif
            @if ($extra_seats > 0)
                <div class="flex justify-between gap-6">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('billing::checkout.extra_seats') }}</dt>
                    <dd class="text-right font-medium tabular-nums">+{{ $extra_seats }}</dd>
                </div>
            @endif
        </dl>

        <div class="mt-6 space-y-3 border-t border-zinc-200 pt-4 dark:border-white/10">
            <div class="text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                {{ __('billing::checkout.coupon_code') }}
            </div>

            @if ($applied_coupon !== null)
                <div class="flex items-center justify-between gap-3 rounded-lg border border-emerald-300 bg-emerald-50/70 px-3 py-2 dark:border-emerald-400/30 dark:bg-emerald-500/10">
                    <div class="flex min-w-0 items-center gap-2">
                        <flux:icon.ticket class="size-4 shrink-0 text-emerald-700 dark:text-emerald-300" />
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium text-emerald-900 dark:text-emerald-100">{{ $applied_coupon['code'] }}</div>
                            @if (($applied_coupon['name'] ?? '') !== '' && $applied_coupon['name'] !== $applied_coupon['code'])
                                <div class="truncate text-xs text-emerald-800/80 dark:text-emerald-200/80">{{ $applied_coupon['name'] }}</div>
                            @endif
                        </div>
                    </div>
                    <flux:button
                        type="button"
                        wire:click="removeCoupon"
                        variant="ghost"
                        size="xs"
                        icon="x-mark"
                        :aria-label="__('billing::checkout.remove_coupon')"
                    />
                </div>
            @else
                <div class="flex flex-col gap-2">
                    <flux:input.group>
                        <flux:input
                            wire:model="coupon_input"
                            wire:keydown.enter.prevent="applyCoupon"
                            type="text"
                            :placeholder="__('billing::checkout.coupon_placeholder')"
                        />
                        <flux:button type="button" wire:click="applyCoupon" icon="check">
                            {{ __('billing::checkout.apply_coupon') }}
                        </flux:button>
                    </flux:input.group>
                    @if ($couponError)
                        <div class="text-xs text-red-600 dark:text-red-400">{{ $couponError }}</div>
                    @endif
                </div>
            @endif
        </div>

        <div class="mt-4 space-y-2 border-t border-zinc-200 pt-4 dark:border-white/10">
            @if ($applied_coupon !== null)
                <div class="flex items-baseline justify-between text-sm">
                    <span class="text-zinc-500 dark:text-zinc-400">{{ __('billing::checkout.subtotal') }}</span>
                    <span class="tabular-nums">€{{ number_format($this->subtotalNet() / 100, 2) }}</span>
                </div>
                <div class="flex items-baseline justify-between text-sm">
                    <span class="text-emerald-700 dark:text-emerald-400">
                        {{ __('billing::checkout.discount', ['code' => $applied_coupon['code']]) }}
                    </span>
                    <span class="tabular-nums text-emerald-700 dark:text-emerald-400">
                        −€{{ number_format(($applied_coupon['discount_net'] ?? 0) / 100, 2) }}
                    </span>
                </div>
            @endif
            <div class="flex items-baseline justify-between text-sm">
                <span class="text-zinc-500 dark:text-zinc-400">{{ __('billing::checkout.net') }}</span>
                <span class="tabular-nums">€{{ number_format($totals['net'] / 100, 2) }}</span>
            </div>
            @if ($totals['reverseCharge'])
                <div class="flex items-baseline justify-between text-sm">
                    <span class="text-zinc-500 dark:text-zinc-400">{{ __('billing::checkout.vat_label') }}</span>
                    <span class="tabular-nums text-emerald-700 dark:text-emerald-400">{{ __('billing::checkout.reverse_charge') }}</span>
                </div>
            @else
                <div class="flex items-baseline justify-between text-sm">
                    <span class="text-zinc-500 dark:text-zinc-400">
                        {{ __('billing::checkout.vat_with_rate', ['rate' => rtrim(rtrim(number_format($totals['rate'], 1), '0'), '.')]) }}
                    </span>
                    <span class="tabular-nums">€{{ number_format($totals['vat'] / 100, 2) }}</span>
                </div>
            @endif
            <div class="flex items-baseline justify-between border-t border-zinc-200 pt-3 dark:border-white/10">
                <span class="text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                    {{ __('billing::checkout.total') }}
                </span>
                <span class="text-2xl font-semibold tracking-tight tabular-nums">
                    €{{ number_format($totals['gross'] / 100, 2) }}
                </span>
            </div>
        </div>
    </section>
</div>

<flux:callout icon="shield-check" color="zinc" inline>
    {{ __('billing::checkout.redirect_notice') }}
</flux:callout>

<div class="flex items-center justify-between pt-2">
    <flux:button wire:click="back" variant="ghost" icon="arrow-left">{{ __('billing::checkout.back') }}</flux:button>
    <flux:button wire:click="submit" variant="primary" icon:trailing="arrow-right" :disabled="$processing" wire:loading.attr="disabled" wire:loading.class="opacity-50 cursor-not-allowed">
        <span wire:loading.remove wire:target="submit">{{ __('billing::checkout.confirm_and_pay') }}</span>
        <span wire:loading wire:target="submit">{{ __('billing::checkout.processing') }}</span>
    </flux:button>
</div>
