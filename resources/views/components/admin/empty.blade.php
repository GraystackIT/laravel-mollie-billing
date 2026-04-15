@props([
    'icon' => 'inbox',
    'title' => 'Nothing here yet',
    'description' => null,
])

<div {{ $attributes->class(['flex flex-col items-center justify-center gap-2 py-12 text-center']) }}>
    <div class="rounded-full bg-zinc-100 p-3 text-zinc-400 dark:bg-zinc-800 dark:text-zinc-500">
        <flux:icon :name="$icon" class="h-6 w-6" />
    </div>
    <flux:heading size="md" class="mt-1">{{ $title }}</flux:heading>
    @if ($description)
        <flux:text class="max-w-sm text-zinc-500 dark:text-zinc-400">{{ $description }}</flux:text>
    @endif
    @if (isset($cta))
        <div class="mt-3">{{ $cta }}</div>
    @endif
</div>
