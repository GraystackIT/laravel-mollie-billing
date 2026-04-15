@props([
    'value' => null,
    'size' => 'sm',
])

@php
    $label = \GraystackIT\MollieBilling\Support\EnumLabels::label($value);
    $color = \GraystackIT\MollieBilling\Support\EnumLabels::color($value);
@endphp

@if ($value === null || $label === '—')
    <flux:text class="text-zinc-400">—</flux:text>
@else
    <flux:badge :color="$color" :size="$size">{{ $label }}</flux:badge>
@endif
