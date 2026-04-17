@php
    $logoUrl = config('mollie-billing.logo_url');
    $companyName = config('mollie-billing.company_name', config('app.name'));
    $primaryColor = \GraystackIT\MollieBilling\Support\Sanitize::cssColor(
        (string) config('mollie-billing.primary_color', '#6366f1'),
    );
    $resolvedBackUrl = \GraystackIT\MollieBilling\Support\Sanitize::backUrl($backUrl)
        ?? config('mollie-billing.checkout_back_url', '/');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('billing::checkout.title', ['app' => $companyName]) }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
        @fluxAppearance
        <style>
            :root {
                --color-accent: {{ $primaryColor }};
            }
        </style>
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-neutral-950">
        <div class="relative min-h-svh overflow-hidden">
            {{-- Ambient background: radial glow + subtle grid texture --}}
            <div aria-hidden="true" class="pointer-events-none absolute inset-0">
                <div class="absolute -top-40 left-1/2 h-[32rem] w-[64rem] -translate-x-1/2 rounded-full bg-linear-to-b from-zinc-200/60 via-zinc-100/20 to-transparent blur-3xl dark:from-white/6 dark:via-white/2 dark:to-transparent"></div>
                <div class="absolute inset-0 bg-[linear-gradient(to_right,theme(colors.zinc.200/40)_1px,transparent_1px),linear-gradient(to_bottom,theme(colors.zinc.200/40)_1px,transparent_1px)] bg-[size:64px_64px] [mask-image:radial-gradient(ellipse_at_top,black_20%,transparent_70%)] dark:bg-[linear-gradient(to_right,theme(colors.white/5)_1px,transparent_1px),linear-gradient(to_bottom,theme(colors.white/5)_1px,transparent_1px)]"></div>
            </div>

            {{-- Header: logo + back link --}}
            <header class="relative z-10 flex items-center justify-between px-6 py-6 lg:px-12">
                <span class="flex items-center gap-2">
                    @if ($logoUrl)
                        <img src="{{ $logoUrl }}" alt="{{ $companyName }}" class="h-8 w-auto">
                    @else
                        <flux:icon.credit-card class="size-7 text-accent" />
                        <span class="text-sm font-medium tracking-tight text-zinc-900 dark:text-white">
                            {{ $companyName }}
                        </span>
                    @endif
                </span>
                <flux:link :href="$resolvedBackUrl" class="text-sm" icon="arrow-left">
                    {{ __('billing::checkout.back') }}
                </flux:link>
            </header>

            {{-- Content --}}
            <main class="relative z-10 mx-auto flex w-full max-w-3xl flex-col px-6 pb-16 pt-4 lg:px-0 lg:pt-8">
                @livewire($livewireComponent)
            </main>
        </div>
        @livewireScripts
        @fluxScripts
    </body>
</html>
