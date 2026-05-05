@props([
    'title' => null,
    'description' => null,
    'open' => false,
    'badge' => null,
])

<flux:card class="px-6! py-2! overflow-hidden">
    <flux:accordion>
        <flux:accordion.item :expanded="$open" {{ $attributes }}>
            <flux:accordion.heading>
                <span class="flex items-center gap-2">
                    @if ($title)
                        <span class="font-medium text-zinc-900 dark:text-white">{{ $title }}</span>
                    @endif
                    @if ($badge)
                        <flux:badge size="sm" color="zinc">{{ $badge }}</flux:badge>
                    @endif
                </span>
                @if ($description)
                    <flux:text class="mt-0.5 text-zinc-500 dark:text-zinc-400">{{ $description }}</flux:text>
                @endif
            </flux:accordion.heading>
            <flux:accordion.content>
                <div class="space-y-4 pb-4 pt-2">
                    {{ $slot }}
                </div>
            </flux:accordion.content>
        </flux:accordion.item>
    </flux:accordion>
</flux:card>
