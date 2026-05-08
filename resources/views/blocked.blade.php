@php
    $companyName = config('mollie-billing.company_name', config('app.name'));
    $logoUrl = config('mollie-billing.logo_url');
    $faviconUrl = config('mollie-billing.favicon_url', '/favicon.ico');
    $primaryColor = config('mollie-billing.primary_color', 'teal');
    $portalTitle = __('billing::portal.title', ['app' => $companyName]);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('billing::portal.blocked.title', ['app' => $companyName]) }}</title>
    <link rel="icon" href="{{ str_starts_with($faviconUrl, 'http') || str_starts_with($faviconUrl, '/') ? $faviconUrl : asset($faviconUrl) }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="h-full min-h-screen bg-zinc-50 dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100">
    <flux:accent color="{{ $primaryColor }}" class="min-h-screen">
        <main class="flex min-h-screen flex-col items-center justify-center px-6 py-16">
            <div class="w-full max-w-xl">
                <div class="flex flex-col items-center text-center">
                    @if ($logoUrl)
                        <img src="{{ $logoUrl }}" alt="{{ $portalTitle }}" class="mb-6 h-10 w-auto">
                    @else
                        <flux:icon.credit-card class="mb-6 size-10 text-accent" />
                    @endif

                    {{-- <flux:icon.no-symbol class="mb-4 size-14 text-rose-500" /> --}}

                    <flux:heading size="xl" level="1">
                        {{ __('billing::portal.blocked.heading') }}
                    </flux:heading>

                    <flux:subheading class="mt-3">
                        @if ($countryName)
                            {{ __('billing::portal.blocked.body_with_country', ['country' => $countryName]) }}
                        @else
                            {{ __('billing::portal.blocked.body_unknown_country') }}
                        @endif
                    </flux:subheading>

                    @if ($countryCode)
                        <flux:text class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                            {{ __('billing::portal.blocked.detected_country', ['code' => $countryCode]) }}
                        </flux:text>
                    @endif

                    <div class="mt-8">
                        <flux:button as="a" href="{{ $backUrl }}" variant="primary" icon="arrow-left">
                            {{ __('billing::portal.blocked.back') }}
                        </flux:button>
                    </div>
                </div>
            </div>
        </main>
    </flux:accent>
    @fluxScripts
</body>
</html>
