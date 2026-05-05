@props([
    'billable' => null,
    'separator' => ' · ',
    'fallback' => null,
])

@php
    $parts = [];
    if ($billable) {
        $street = $billable->getBillingStreet();
        $postal = $billable->getBillingPostalCode();
        $city = $billable->getBillingCity();
        $country = $billable->getBillingCountry();

        if ($street) {
            $parts[] = $street;
        }
        $cityLine = trim(($postal ?? '').' '.($city ?? ''));
        if ($cityLine !== '') {
            $parts[] = $cityLine;
        }
        if ($country) {
            $parts[] = $country;
        }
    }
@endphp

@if ($parts !== [])
    <span {{ $attributes }}>{{ implode($separator, $parts) }}</span>
@elseif ($fallback)
    <span {{ $attributes }}>{{ $fallback }}</span>
@endif
