@props([
    'value' => null,
    'size' => 'sm',
])

@if ($value === null)
    <flux:text class="text-zinc-400">—</flux:text>
@elseif ($value instanceof \UnitEnum && method_exists($value, 'label'))
    <flux:badge :color="method_exists($value, 'color') ? $value->color() : 'zinc'" :size="$size">{{ $value->label() }}</flux:badge>
@elseif ($value instanceof \UnitEnum)
    <flux:badge color="zinc" :size="$size">{{ $value->value ?? $value->name }}</flux:badge>
@else
    <flux:badge color="zinc" :size="$size">{{ $value }}</flux:badge>
@endif
