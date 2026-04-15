@props([
    'title',
    'subtitle' => null,
    'back' => null,
    'backLabel' => 'Back',
])

<div class="flex flex-wrap items-start justify-between gap-4">
    <div class="min-w-0">
        @if ($back)
            <flux:button
                :href="$back"
                size="xs"
                variant="ghost"
                icon="arrow-left"
                class="-ml-2 mb-1"
            >{{ $backLabel }}</flux:button>
        @endif
        <flux:heading size="xl" class="truncate">{{ $title }}</flux:heading>
        @if ($subtitle)
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">{{ $subtitle }}</flux:text>
        @endif
    </div>
    @if (isset($actions))
        <div class="flex shrink-0 flex-wrap items-center gap-2">
            {{ $actions }}
        </div>
    @endif
</div>
