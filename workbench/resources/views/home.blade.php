<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Billing Workbench</title>
    {{-- Deliberately no Vite here: this page must render even when the asset
         build is missing, so a broken pipeline is diagnosable from the browser. --}}
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; background: #fafafa; color: #18181b; margin: 0; padding: 3rem 1.5rem; }
        .card { max-width: 640px; margin: 0 auto; background: #fff; border: 1px solid #e4e4e7; border-radius: 12px; padding: 2rem; }
        h1 { font-size: 1.25rem; margin: 0 0 .25rem; }
        p.sub { color: #71717a; margin: 0 0 1.5rem; font-size: .875rem; }
        h2 { font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; color: #a1a1aa; margin: 1.5rem 0 .5rem; }
        ul { list-style: none; margin: 0; padding: 0; }
        li { padding: .4rem 0; border-bottom: 1px solid #f4f4f5; display: flex; justify-content: space-between; align-items: center; font-size: .9rem; }
        a { color: #4f46e5; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .badge { font-size: .7rem; background: #eef2ff; color: #4338ca; border-radius: 999px; padding: .15rem .6rem; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Billing Workbench</h1>
        <p class="sub">Browser-test host for graystackit/laravel-mollie-billing. Fake Mollie, fake VIES, real everything else.</p>

        @if ($actingAs)
            <p>Acting as billable <span class="badge">#{{ $actingAs }}</span> — <a href="{{ route('billing.index') }}">open portal</a> · <a href="{{ route('wb.logout') }}">reset session</a></p>
        @endif
        @if ($admin)
            <p>Admin session: <span class="badge">{{ $admin->email }}</span> — <a href="{{ route('billing.admin.dashboard') }}">open admin</a></p>
        @endif

        <h2>Billables</h2>
        <ul>
            @forelse ($billables as $billable)
                <li>
                    <span>#{{ $billable->id }} — {{ $billable->name }} <em style="color:#a1a1aa">({{ $billable->subscription_status?->value ?? 'new' }})</em></span>
                    <a href="{{ route('wb.act-as', $billable) }}">act as</a>
                </li>
            @empty
                <li>No billables yet — the test seeds them.</li>
            @endforelse
        </ul>

        <h2>Entry points</h2>
        <ul>
            <li><a href="{{ route('billing.checkout') }}">Checkout</a></li>
            <li><a href="{{ route('billing.index') }}">Customer portal</a></li>
            <li><a href="{{ route('wb.admin-login') }}">Admin panel (auto-login)</a></li>
        </ul>
    </div>
</body>
</html>
