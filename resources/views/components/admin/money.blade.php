@props([
    'cents' => 0,
    'currency' => null,
    'signed' => false,
])

<span {{ $attributes->class(['tabular-nums']) }}>{{ \GraystackIT\MollieBilling\Support\BillingMoney::format((int) ($cents ?? 0), $currency, $signed) }}</span>
