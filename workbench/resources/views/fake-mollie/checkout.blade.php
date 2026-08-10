<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fake Mollie — Checkout</title>
    {{-- Styled to read unmistakably as "you have LEFT the app and are at the
         PSP now" — that page transition is part of what the suite documents. --}}
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; background: #0f172a; color: #f8fafc; margin: 0; min-height: 100vh; display: grid; place-items: center; }
        .panel { width: min(420px, 92vw); background: #1e293b; border-radius: 16px; padding: 2rem; box-shadow: 0 25px 60px rgb(0 0 0 / .45); }
        .brand { display: flex; align-items: center; gap: .6rem; margin-bottom: 1.25rem; }
        .dot { width: 34px; height: 34px; border-radius: 999px; background: #22d3ee; display: grid; place-items: center; color: #0f172a; font-weight: 800; }
        .brand strong { letter-spacing: .02em; }
        .amount { font-size: 2.2rem; font-weight: 700; margin: .25rem 0; }
        .desc { color: #94a3b8; font-size: .9rem; margin-bottom: 1.25rem; }
        .meta { font-size: .75rem; color: #64748b; margin-bottom: 1.5rem; }
        label { display: block; font-size: .75rem; color: #94a3b8; margin: 0 0 .35rem; }
        select { width: 100%; background: #0f172a; color: #f8fafc; border: 1px solid #334155; border-radius: 8px; padding: .55rem .7rem; margin-bottom: 1.25rem; font-size: .9rem; }
        button { width: 100%; border: 0; border-radius: 10px; padding: .8rem 1rem; font-size: .95rem; font-weight: 600; cursor: pointer; margin-bottom: .6rem; }
        .pay { background: #22c55e; color: #052e16; }
        .fail { background: #ef4444; color: #450a0a; }
        .ghost { background: transparent; color: #94a3b8; border: 1px solid #334155; }
    </style>
</head>
<body>
    <div class="panel">
        <div class="brand"><span class="dot">M</span><strong>mollie</strong> <span style="color:#64748b;font-size:.75rem">(workbench fake)</span></div>

        <div class="amount">{{ $payment['amount_currency'] }} {{ $payment['amount_value'] }}</div>
        <div class="desc">{{ $payment['description'] ?? 'Payment' }}</div>
        <div class="meta">{{ $payment['id'] }} · {{ $payment['sequence_type'] }} · status: {{ $payment['status'] }}</div>

        <form method="POST" action="{{ route('fake-mollie.settle', $payment['id']) }}">
            @csrf
            <label for="country">Card country (drives the three-way VAT check)</label>
            <select id="country" name="country" dusk="fake-mollie-country">
                @foreach (['DE', 'AT', 'NL', 'FR', 'CH'] as $country)
                    <option value="{{ $country }}">{{ $country }}</option>
                @endforeach
            </select>

            <button class="pay" name="outcome" value="paid" dusk="fake-mollie-pay">Pay {{ $payment['amount_currency'] }} {{ $payment['amount_value'] }}</button>
            <button class="fail" name="outcome" value="failed" dusk="fake-mollie-fail">Fail payment</button>
            <button class="ghost" name="outcome" value="canceled" dusk="fake-mollie-cancel">Cancel</button>
            <button class="ghost" name="outcome" value="expired" dusk="fake-mollie-expire">Let it expire</button>
        </form>
    </div>
</body>
</html>
