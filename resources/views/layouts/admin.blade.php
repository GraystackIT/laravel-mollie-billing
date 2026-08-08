@php
    use GraystackIT\MollieBilling\Support\BillingRoute;

    $logoUrl = config('mollie-billing.logo_url');
    $faviconUrl = config('mollie-billing.favicon_url', '/favicon.ico');
    $primaryColor = config('mollie-billing.primary_color', 'teal');
    $portalTitle = __('billing::admin.title', ['app' => config('mollie-billing.company_name') ?: config('app.name')]);
    $currentRoute = request()->route()?->getName();

    // Where "back" leaves the panel to. Its own key, because the admin panel and the customer
    // portal do not lead to the same place: the portal returns a customer to their app
    // dashboard, the panel returns staff to wherever they administer from. Falls back to
    // `dashboard_url` so a single-app install only has to configure one of them.
    $backUrl = BillingRoute::configuredUrl(
        config('mollie-billing.admin_back_url') ?: config('mollie-billing.dashboard_url'),
    );
@endphp
<!DOCTYPE html>
{{-- `lang` reflects the active locale (pinned by config `mollie-billing.admin_locale`
     when set, otherwise the app locale). A correct value also suppresses Chrome's
     auto-translate prompt, which would otherwise machine-translate the panel. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $portalTitle }}</title>
    <link rel="icon" href="{{ str_starts_with($faviconUrl, 'http') || str_starts_with($faviconUrl, '/') ? $faviconUrl : asset($faviconUrl) }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    @fluxAppearance
</head>
<body class="h-full min-h-screen bg-zinc-50 dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100">
    <flux:accent color="{{ $primaryColor }}" class="min-h-screen">
    <flux:header sticky class="border-b border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

        <a href="{{ route(BillingRoute::admin('dashboard')) }}" class="flex items-center gap-2">
            @if ($logoUrl)
                <img src="{{ $logoUrl }}" alt="{{ $portalTitle }}" class="h-8 w-auto">
            @else
                <flux:icon.credit-card class="text-accent" />
                <flux:heading size="lg" class="mb-0! whitespace-nowrap">{{ $portalTitle }}</flux:heading>
            @endif
        </a>

        <flux:spacer />

        {{-- App-provided header components (e.g. a language switcher). Keyed by
             index so Livewire keeps them apart across re-renders — the directive
             only picks up a literal `key(...)` call, a bare third argument is
             dropped. A switcher only has an effect while
             `mollie-billing.admin_locale` is null — a pinned admin locale wins
             over whatever the switcher sets. --}}
        @foreach (config('mollie-billing.header_components', []) as $i => $headerComponent)
            @livewire($headerComponent, [], key('billing-header-'.$i))
        @endforeach

        <flux:dropdown position="bottom" align="end">
            <flux:button variant="ghost" size="sm" icon="sun" icon:variant="mini" x-bind:icon="$flux.appearance === 'dark' ? 'moon' : ($flux.appearance === 'light' ? 'sun' : 'computer-desktop')" :aria-label="__('billing::admin.theme.toggle')" />
            <flux:menu>
                <flux:menu.radio.group x-model="$flux.appearance">
                    <flux:menu.radio value="light" icon="sun">{{ __('billing::admin.theme.light') }}</flux:menu.radio>
                    <flux:menu.radio value="dark" icon="moon">{{ __('billing::admin.theme.dark') }}</flux:menu.radio>
                    <flux:menu.radio value="system" icon="computer-desktop">{{ __('billing::admin.theme.system') }}</flux:menu.radio>
                </flux:menu.radio.group>
            </flux:menu>
        </flux:dropdown>
    </flux:header>

    <flux:sidebar sticky stashable class="border-e border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.toggle class="lg:hidden" icon="x-mark" inset="left" />

        <flux:navlist variant="outline">
            <flux:navlist.item icon="home" href="{{ route(BillingRoute::admin('dashboard')) }}" :current="$currentRoute === BillingRoute::admin('dashboard')">{{ __('billing::admin.nav.dashboard') }}</flux:navlist.item>
            <flux:navlist.item icon="users" href="{{ route(BillingRoute::admin('billables.index')) }}" :current="str_starts_with($currentRoute ?? '', BillingRoute::admin('billables'))">{{ __('billing::admin.nav.billables') }}</flux:navlist.item>
            <flux:navlist.item icon="ticket" href="{{ route(BillingRoute::admin('coupons.index')) }}" :current="str_starts_with($currentRoute ?? '', BillingRoute::admin('coupons'))">{{ __('billing::admin.nav.coupons') }}</flux:navlist.item>
            <flux:navlist.item icon="calendar" href="{{ route(BillingRoute::admin('scheduled_changes.index')) }}" :current="$currentRoute === BillingRoute::admin('scheduled_changes.index')">{{ __('billing::admin.nav.scheduled_changes') }}</flux:navlist.item>
            <flux:navlist.item icon="exclamation-triangle" href="{{ route(BillingRoute::admin('past_due.index')) }}" :current="$currentRoute === BillingRoute::admin('past_due.index')">{{ __('billing::admin.nav.past_due') }}</flux:navlist.item>
            <flux:navlist.item icon="globe-europe-africa" href="{{ route(BillingRoute::admin('mismatches.index')) }}" :current="$currentRoute === BillingRoute::admin('mismatches.index')">{{ __('billing::admin.nav.mismatches') }}</flux:navlist.item>
            <flux:navlist.item icon="arrow-uturn-left" href="{{ route(BillingRoute::admin('refunds.index')) }}" :current="$currentRoute === BillingRoute::admin('refunds.index')">{{ __('billing::admin.nav.refunds') }}</flux:navlist.item>
            <flux:navlist.item icon="document-arrow-down" href="{{ route(BillingRoute::admin('oss.index')) }}" :current="$currentRoute === BillingRoute::admin('oss.index')">{{ __('billing::admin.nav.oss') }}</flux:navlist.item>
            <flux:navlist.item icon="bolt" href="{{ route(BillingRoute::admin('bulk.index')) }}" :current="$currentRoute === BillingRoute::admin('bulk.index')">{{ __('billing::admin.nav.bulk') }}</flux:navlist.item>
        </flux:navlist>

        <flux:spacer />

        @if ($backUrl)
            <flux:navlist variant="outline">
                <flux:navlist.item icon="arrow-left" href="{{ $backUrl }}">
                    {{ __('billing::admin.nav.back_to_app') }}
                </flux:navlist.item>
            </flux:navlist>
        @endif

        @if (Route::has('logout'))
            <flux:navlist variant="outline">
                <flux:navlist.item icon="arrow-right-start-on-rectangle"
                    x-on:click.prevent="$refs.logoutForm.submit()"
                    class="cursor-pointer"
                >
                    {{ __('billing::portal.nav.logout') }}
                </flux:navlist.item>
            </flux:navlist>
            <form x-ref="logoutForm" method="POST" action="{{ route('logout') }}" class="hidden">
                @csrf
            </form>
        @endif
    </flux:sidebar>

    <flux:main container>
        <div class="mx-auto w-full max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            @livewire($livewireComponent, ['routeParameters' => request()->route()?->parameters() ?? []])
        </div>
    </flux:main>

    </flux:accent>
    @livewireScripts
    @fluxScripts
</body>
</html>
