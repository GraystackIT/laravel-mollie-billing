@php($plan = $this->selectedPlan())
@php($allowedAddons = (array) ($plan['allowed_addons'] ?? []))
@php($seatPrice = $plan['intervals'][$interval]['seat_price_net'] ?? null)

<div class="flex flex-col gap-6 mt-4">
    @if (! empty($allowedAddons))
        <section class="flex flex-col gap-3 rounded-xl border border-zinc-200/80 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/2">
            <div>
                <flux:heading size="sm">{{ __('billing::checkout.addons_heading') }}</flux:heading>
                <flux:text class="text-sm">{{ __('billing::checkout.addons_description') }}</flux:text>
            </div>
            <div class="flex flex-col gap-3 pt-2">
                @foreach ($allowedAddons as $addonCode)
                    @php($addon = $this->addons()[$addonCode] ?? null)
                    @if ($addon)
                        @php($addonPrice = number_format($priceFormatter((int) ($addon['intervals'][$interval]['price_net'] ?? 0)) / 100, 2))
                        <label class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200/80 bg-zinc-50 p-4 transition hover:border-zinc-300 hover:bg-white dark:border-white/10 dark:bg-white/2 dark:hover:border-white/30">
                            <div class="flex items-center gap-3">
                                <flux:checkbox wire:model="addon_codes" value="{{ $addonCode }}" />
                                <div>
                                    <div class="text-sm font-medium">{{ $addon['name'] }}</div>
                                </div>
                            </div>
                            <div class="text-sm tabular-nums text-zinc-600 dark:text-zinc-400">
                                €{{ $addonPrice }} / {{ $interval === 'monthly' ? __('billing::checkout.per_month') : __('billing::checkout.per_year') }}
                            </div>
                        </label>
                    @endif
                @endforeach
            </div>
        </section>
    @endif

    @if ($seatPrice !== null)
        <section class="rounded-xl border border-zinc-200/80 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/2">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0 flex-1">
                    <flux:heading size="sm">{{ __('billing::checkout.extra_seats') }}</flux:heading>
                    <flux:text class="text-sm">
                        {{ __('billing::checkout.seats_included', ['count' => $plan['included_seats'] ?? 0]) }}
                    </flux:text>
                    <flux:text class="text-sm">
                        {{ __('billing::checkout.extra_seat_price') }}
                        <strong class="font-semibold text-zinc-900 dark:text-white">€{{ number_format($priceFormatter((int) $seatPrice) / 100, 2) }}</strong>
                    </flux:text>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <flux:icon.loading wire:loading wire:target="extra_seats" variant="mini" class="size-4 text-zinc-400" />
                    <flux:input wire:model.live="extra_seats" :loading="false" type="number" min="0" max="100" class="w-28 text-right" />
                </div>
            </div>
        </section>
    @endif

    <flux:text class="text-xs text-center">
        @if ($showsNet)
            {{ __('billing::checkout.prices_net') }}
        @else
            {{ __('billing::checkout.prices_incl_vat', ['rate' => rtrim(rtrim(number_format($vatRate, 1), '0'), '.'), 'country' => $this->countries()[$billing_country] ?? $billing_country]) }}
        @endif
    </flux:text>
</div>

<div class="flex items-center justify-between pt-2">
    <flux:button wire:click="back" variant="ghost" icon="arrow-left">{{ __('billing::checkout.back') }}</flux:button>
    <flux:button wire:click="next" variant="primary" icon:trailing="arrow-right">{{ __('billing::checkout.continue') }}</flux:button>
</div>
