{{--
    A changed order waits for an earlier first payment Mollie would not withdraw
    (`PendingPaymentForAnotherOrderException`): until when that payment stays payable, in the
    billable's timezone and the app's language, and the link that still finishes it.

    Shared by every screen that starts a first payment — the checkout, the upgrade from a free
    plan, the purchase of extras on a gift — so all of them say the same thing. The state
    comes from `Concerns\ShowsPendingOtherOrderNotice`, which only lets an https link (or
    one on the app's own origin) through.
--}}
@props(['until' => null, 'timezone' => null, 'url' => null])

@php
    $untilLabel = $until !== null
        ? \Carbon\CarbonImmutable::parse($until)
            ->setTimezone($timezone ?: (config('mollie-billing.billing_timezone') ?: 'UTC'))
            ->locale(app()->getLocale())
            ->translatedFormat(__('billing::checkout.pending_other_order_time_format'))
        : null;
@endphp

<flux:callout icon="clock" color="amber" dusk="pending-other-order" {{ $attributes }}>
    <flux:callout.heading>{{ __('billing::checkout.pending_other_order_heading') }}</flux:callout.heading>
    <flux:callout.text>
        <span dusk="pending-other-order-text">
            @if ($untilLabel !== null)
                {!! __('billing::checkout.pending_other_order_until', ['time' => '<strong dusk="pending-other-order-until">'.e($untilLabel).'</strong>']) !!}
            @else
                {{ __('billing::checkout.pending_other_order_open') }}
            @endif
            {{ $url
                ? __('billing::checkout.pending_other_order_finish_or_wait')
                : __('billing::checkout.pending_other_order_wait') }}
        </span>
    </flux:callout.text>
    @if ($url)
        <div class="mt-3">
            <flux:button size="sm" variant="primary" href="{{ $url }}" dusk="pending-other-order-resume">
                {{ __('billing::checkout.pending_other_order_resume') }}
            </flux:button>
        </div>
    @endif
</flux:callout>
