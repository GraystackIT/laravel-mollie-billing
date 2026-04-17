@php
    use GraystackIT\MollieBilling\Support\BillingRoute;

    $logoUrl = config('mollie-billing.logo_url');
    $portalTitle = __('billing::portal.title', ['app' => config('mollie-billing.company_name', config('app.name'))]);
    $currentRoute = request()->route()?->getName();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $portalTitle }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    @fluxAppearance
</head>
<body class="h-full min-h-screen bg-zinc-50 dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100">
    <flux:header sticky class="border-b border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

        <a href="{{ route(BillingRoute::name('index')) }}" class="flex items-center gap-2">
            @if ($logoUrl)
                <img src="{{ $logoUrl }}" alt="{{ $portalTitle }}" class="h-8 w-auto">
            @else
                <flux:icon.credit-card class="text-accent" />
                <flux:heading size="lg" class="mb-0! whitespace-nowrap">{{ $portalTitle }}</flux:heading>
            @endif
        </a>

        <flux:spacer />

        <flux:dropdown position="bottom" align="end">
            <flux:button variant="ghost" size="sm" icon="sun" icon:variant="mini" x-bind:icon="$flux.appearance === 'dark' ? 'moon' : ($flux.appearance === 'light' ? 'sun' : 'computer-desktop')" aria-label="Toggle theme" />
            <flux:menu>
                <flux:menu.radio.group x-model="$flux.appearance">
                    <flux:menu.radio value="light" icon="sun">Light</flux:menu.radio>
                    <flux:menu.radio value="dark" icon="moon">Dark</flux:menu.radio>
                    <flux:menu.radio value="system" icon="computer-desktop">System</flux:menu.radio>
                </flux:menu.radio.group>
            </flux:menu>
        </flux:dropdown>
    </flux:header>

    <flux:sidebar sticky stashable class="border-e border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.toggle class="lg:hidden" icon="x-mark" inset="left" />

        <flux:navlist variant="outline">
            <flux:navlist.item icon="home" href="{{ route(BillingRoute::name('index')) }}" :current="$currentRoute === BillingRoute::name('index')">
                {{ __('billing::portal.nav.dashboard') }}
            </flux:navlist.item>
            <flux:navlist.item icon="arrow-path" href="{{ route(BillingRoute::name('plan')) }}" :current="$currentRoute === BillingRoute::name('plan')">
                {{ __('billing::portal.nav.plan') }}
            </flux:navlist.item>
            <flux:navlist.item icon="document-text" href="{{ route(BillingRoute::name('invoices')) }}" :current="$currentRoute === BillingRoute::name('invoices')">
                {{ __('billing::portal.nav.invoices') }}
            </flux:navlist.item>
        </flux:navlist>

        <flux:spacer />
    </flux:sidebar>

    <flux:main container>
        @livewire($livewireComponent)
    </flux:main>

    @livewireScripts
    @fluxScripts
</body>
</html>
