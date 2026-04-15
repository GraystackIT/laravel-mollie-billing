@props([
    'label',
    'mono' => false,
])

<div class="flex flex-col gap-0.5">
    <dt class="text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
    <dd @class([
        'text-sm text-zinc-900 dark:text-zinc-100',
        'font-mono' => $mono,
    ])>
        {{ $slot }}
    </dd>
</div>
