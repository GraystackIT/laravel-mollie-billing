@props([
    'label',
    'value',
    'href' => null,
    'tone' => null,
    'hint' => null,
    'icon' => null,
])

@php
    $toneClasses = match ($tone) {
        'danger' => 'text-red-600 dark:text-red-400',
        'warning' => 'text-amber-600 dark:text-amber-400',
        'success' => 'text-emerald-600 dark:text-emerald-400',
        default => 'text-zinc-900 dark:text-zinc-50',
    };
@endphp

@if ($href)
    <a href="{{ $href }}" class="group block">
        <flux:card class="h-full transition hover:shadow-md hover:-translate-y-0.5">
            <div class="flex items-start justify-between gap-3">
                <flux:text size="xs" class="uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ $label }}</flux:text>
                @if ($icon)
                    <flux:icon :name="$icon" variant="mini" class="text-zinc-400 group-hover:text-zinc-600 dark:group-hover:text-zinc-300" />
                @endif
            </div>
            <div class="mt-2 text-2xl font-semibold tabular-nums {{ $toneClasses }}">{{ $value }}</div>
            @if ($hint)
                <flux:text size="xs" class="mt-1 text-zinc-500 dark:text-zinc-400">{{ $hint }}</flux:text>
            @endif
        </flux:card>
    </a>
@else
    <flux:card class="h-full">
        <div class="flex items-start justify-between gap-3">
            <flux:text size="xs" class="uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ $label }}</flux:text>
            @if ($icon)
                <flux:icon :name="$icon" variant="mini" class="text-zinc-400" />
            @endif
        </div>
        <div class="mt-2 text-2xl font-semibold tabular-nums {{ $toneClasses }}">{{ $value }}</div>
        @if ($hint)
            <flux:text size="xs" class="mt-1 text-zinc-500 dark:text-zinc-400">{{ $hint }}</flux:text>
        @endif
    </flux:card>
@endif
