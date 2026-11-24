{{--
    A moment in the admin panel: always UTC, always labelled as such.

    The panel works in UTC throughout (see docs/timezone.md); a bare time next to the
    operator's own clock reads as local time. Use this for every date or time the panel
    shows in markup, and BillingTime::formatUtc() where only text fits (a flash, a
    translation placeholder, an attribute). AdminUtcLabelGuardTest keeps it that way.
--}}
@props(['at' => null, 'format' => 'Y-m-d H:i', 'empty' => '—'])

@php
    $utc = $at === null || $at === ''
        ? null
        : \GraystackIT\MollieBilling\Support\BillingTime::toUtc($at);
@endphp

@if ($utc)
    <time datetime="{{ $utc->toIso8601String() }}" {{ $attributes->merge(['class' => 'tabular-nums whitespace-nowrap']) }}>{{ $utc->format($format) }} <span class="text-[0.75em] font-medium text-zinc-400 dark:text-zinc-500">UTC</span></time>
@else
    {{ $empty }}
@endif
