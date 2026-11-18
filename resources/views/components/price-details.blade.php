@props(['net', 'netLabel' => null, 'showBoth' => false, 'align' => 'end'])

@if ($showBoth)
    <span {{ $attributes->class(['inline-flex flex-col', 'items-start' => $align === 'start', 'items-end' => $align !== 'start']) }}>
        <span class="font-semibold text-zinc-900 dark:text-white">
            {{ $netLabel ?? \GraystackIT\MollieBilling\Support\BillingMoney::format($net) }}
            <span class="text-xs font-normal text-zinc-500 dark:text-zinc-400">{{ __('billing::portal.price_excl_vat') }}</span>
        </span>
        <span class="text-xs font-normal tabular-nums text-zinc-500 dark:text-zinc-400">{{ $slot }} {{ __('billing::portal.price_incl_vat') }}</span>
    </span>
@else
    {{ $slot }}
@endif
