@php
    $logoUrl = config('mollie-billing.logo_url');
    $portalTitle = config('app.name').' Billing Portal';
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

        <a href="{{ route('billing.admin.dashboard') }}" class="flex items-center gap-2">
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
            <flux:navlist.item icon="home" href="{{ route('billing.admin.dashboard') }}" :current="$currentRoute === 'billing.admin.dashboard'">Dashboard</flux:navlist.item>
            <flux:navlist.item icon="users" href="{{ route('billing.admin.billables.index') }}" :current="str_starts_with($currentRoute ?? '', 'billing.admin.billables')">Billables</flux:navlist.item>
            <flux:navlist.item icon="ticket" href="{{ route('billing.admin.coupons.index') }}" :current="str_starts_with($currentRoute ?? '', 'billing.admin.coupons')">Coupons</flux:navlist.item>
            <flux:navlist.item icon="calendar" href="{{ route('billing.admin.scheduled_changes.index') }}" :current="$currentRoute === 'billing.admin.scheduled_changes.index'">Scheduled changes</flux:navlist.item>
            <flux:navlist.item icon="exclamation-triangle" href="{{ route('billing.admin.past_due.index') }}" :current="$currentRoute === 'billing.admin.past_due.index'">Past due</flux:navlist.item>
            <flux:navlist.item icon="globe-europe-africa" href="{{ route('billing.admin.mismatches.index') }}" :current="$currentRoute === 'billing.admin.mismatches.index'">Country mismatches</flux:navlist.item>
            <flux:navlist.item icon="arrow-uturn-left" href="{{ route('billing.admin.refunds.index') }}" :current="$currentRoute === 'billing.admin.refunds.index'">Refunds</flux:navlist.item>
            <flux:navlist.item icon="document-arrow-down" href="{{ route('billing.admin.oss.index') }}" :current="$currentRoute === 'billing.admin.oss.index'">OSS export</flux:navlist.item>
            <flux:navlist.item icon="bolt" href="{{ route('billing.admin.bulk.index') }}" :current="$currentRoute === 'billing.admin.bulk.index'">Bulk actions</flux:navlist.item>
        </flux:navlist>

        <flux:spacer />
    </flux:sidebar>

    <flux:main container>
        <div class="mx-auto w-full max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            @livewire($livewireComponent, ['routeParameters' => request()->route()?->parameters() ?? []])
        </div>
    </flux:main>

    @livewireScripts
    @fluxScripts
</body>
</html>
