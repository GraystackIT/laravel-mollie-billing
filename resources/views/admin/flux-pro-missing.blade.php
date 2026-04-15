<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Billing Admin — Flux Pro required</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen flex items-center justify-center bg-zinc-50 text-zinc-900 p-6">
    <div class="max-w-lg w-full bg-white rounded-2xl shadow p-8 border border-zinc-200">
        <h1 class="text-2xl font-semibold mb-3">Billing admin panel requires Flux&nbsp;Pro</h1>
        <p class="text-zinc-600 mb-4">
            The <code class="bg-zinc-100 px-1 rounded">/billing/admin</code> panel is built on top of Flux Pro components.
            Install it to activate the panel:
        </p>
        <pre class="bg-zinc-900 text-zinc-100 rounded-lg p-3 text-sm overflow-x-auto mb-4"><code>composer require livewire/flux-pro</code></pre>
        <p class="text-sm text-zinc-500 mb-4">
            Customer-facing billing pages under <code class="bg-zinc-100 px-1 rounded">/billing</code> are unaffected and continue to work.
        </p>
        <a href="https://fluxui.dev" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 text-indigo-600 hover:text-indigo-800 text-sm font-medium">
            Flux Pro documentation →
        </a>
    </div>
</body>
</html>
