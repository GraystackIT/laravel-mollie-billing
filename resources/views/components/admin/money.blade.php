@props([
    'cents' => 0,
    'currency' => 'EUR',
    'signed' => false,
])

@php
    $amount = (int) ($cents ?? 0);
    $sign = $signed && $amount > 0 ? '+' : '';
    $symbol = match (strtoupper($currency)) {
        'EUR' => '€',
        'USD' => '$',
        'GBP' => '£',
        default => strtoupper($currency).' ',
    };
@endphp

<span {{ $attributes->class(['tabular-nums']) }}>{{ $sign }}{{ $symbol }}{{ number_format($amount / 100, 2) }}</span>
