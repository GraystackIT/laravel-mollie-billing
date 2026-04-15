@props([
    'title' => null,
    'description' => null,
])

<flux:card {{ $attributes->class(['space-y-4']) }}>
    @if ($title || $description || isset($header))
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                @if ($title)
                    <flux:heading size="md">{{ $title }}</flux:heading>
                @endif
                @if ($description)
                    <flux:text class="text-zinc-500 dark:text-zinc-400">{{ $description }}</flux:text>
                @endif
                {{ $header ?? '' }}
            </div>
            @if (isset($actions))
                <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
            @endif
        </div>
        <flux:separator />
    @endif

    {{ $slot }}
</flux:card>
