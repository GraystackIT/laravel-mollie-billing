<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('billing::portal.title', ['app' => config('mollie-billing.company_name', config('app.name'))]) }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    @if(\GraystackIT\MollieBilling\Support\FluxPro::isInstalled())
        @fluxAppearance
    @endif
</head>
<body class="h-full bg-zinc-50 dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100">
    <main class="min-h-full">
        {{ $slot ?? '' }}
    </main>
    @livewireScripts
    @if(\GraystackIT\MollieBilling\Support\FluxPro::isInstalled())
        @fluxScripts
    @endif
</body>
</html>
