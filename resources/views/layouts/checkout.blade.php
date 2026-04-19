@php
    $logoUrl = config('mollie-billing.logo_url');
    $companyName = config('mollie-billing.company_name', config('app.name'));
    $primaryColor = config('mollie-billing.primary_color', 'teal');    $resolvedBackUrl = \GraystackIT\MollieBilling\Support\Sanitize::backUrl($backUrl)
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
            .billing-grid-bg {
                background-image:
                    linear-gradient(to right, rgba(228,228,231,0.4) 1px, transparent 1px),
                    linear-gradient(to bottom, rgba(228,228,231,0.4) 1px, transparent 1px);
                background-size: 64px 64px;
                mask-image: radial-gradient(ellipse at top, black 20%, transparent 70%);
                -webkit-mask-image: radial-gradient(ellipse at top, black 20%, transparent 70%);
            }
            .dark .billing-grid-bg {
                background-image:
                    linear-gradient(to right, rgba(255,255,255,0.05) 1px, transparent 1px),
                    linear-gradient(to bottom, rgba(255,255,255,0.05) 1px, transparent 1px);
            }
        </style>
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-neutral-950">
        <flux:accent color="{{ $primaryColor }}">
        <div class="relative min-h-svh overflow-hidden">
            {{-- Ambient background: radial glow + subtle grid texture --}}
            <div aria-hidden="true" class="pointer-events-none absolute inset-0">
                <div class="absolute -top-40 left-1/2 h-[32rem] w-[64rem] -translate-x-1/2 rounded-full bg-linear-to-b from-zinc-200/60 via-zinc-100/20 to-transparent blur-3xl dark:from-white/6 dark:via-white/2 dark:to-transparent"></div>
                <div class="billing-grid-bg absolute inset-0"></div>
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
        </flux:accent>
        @livewireScripts
        @fluxScripts
    </body>
</html>
