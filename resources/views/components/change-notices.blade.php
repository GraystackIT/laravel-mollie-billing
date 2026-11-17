{{--
    What the package is resolving — or has resolved — on its own, said where the customer
    looks: a scheduled change that is being retried or was given up, and a balance that is
    being collected after a renewal that was too low.

    Shared by the dashboard and the plan screen so both say the same thing.
--}}
@props(['billable' => null])

@php
    use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
    use GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange;
    use GraystackIT\MollieBilling\Services\Billing\UndercollectionCharge;
    use GraystackIT\MollieBilling\Support\BillingMoney;

    $retry = $billable ? ScheduleSubscriptionChange::retryState($billable) : null;
    $dropped = $billable ? ScheduleSubscriptionChange::droppedNotice($billable) : null;
    $balances = $billable ? UndercollectionCharge::openCases($billable) : [];
    $reasonText = static function (string $group, string $reason): string {
        $key = 'billing::notifications.'.$group.'.reasons.'.$reason;

        return (string) (\Illuminate\Support\Facades\Lang::has($key) ? __($key) : __('billing::notifications.'.$group.'.reasons.default'));
    };
@endphp

@if ($retry)
    <flux:callout icon="arrow-path" color="amber" inline dusk="scheduled-change-retry">
        {{ __('billing::portal.scheduled_change_retry', ['attempt' => $retry['next_attempt'], 'max' => $retry['max']]) }}
    </flux:callout>
@endif

@if ($dropped)
    <flux:callout icon="exclamation-triangle" color="amber" dusk="scheduled-change-dropped">
        <flux:callout.heading>
            @if ($dropped['plan_code'] !== '' && $dropped['plan_code'] !== (string) $billable->getBillingSubscriptionPlanCode())
                {{ __('billing::portal.scheduled_change_dropped', ['plan' => app(SubscriptionCatalogInterface::class)->planName($dropped['plan_code']) ?? $dropped['plan_code']]) }}
            @else
                {{ __('billing::portal.scheduled_change_dropped_generic') }}
            @endif
        </flux:callout.heading>
        <flux:callout.text>{{ $reasonText('scheduled_change_not_applied', $dropped['reason']) }}</flux:callout.text>
        @if ($dropped['payment_on_its_way'])
            <flux:callout.text dusk="scheduled-change-dropped-payment">{{ __('billing::portal.scheduled_change_dropped_payment') }}</flux:callout.text>
        @endif
    </flux:callout>
@endif

@foreach ($balances as $balance)
    <flux:callout
        :icon="$balance['status'] === 'failed' ? 'exclamation-triangle' : 'banknotes'"
        :variant="$balance['status'] === 'failed' ? 'danger' : null"
        :color="$balance['status'] === 'failed' ? null : 'blue'"
        dusk="balance-charge-{{ $balance['status'] }}"
    >
        <flux:callout.heading>
            {{ __('billing::portal.balance_charge.'.$balance['status'], ['amount' => BillingMoney::format($balance['amount_gross'], $balance['currency'])]) }}
        </flux:callout.heading>
        <flux:callout.text>{{ $reasonText('undercollection_charged', $balance['reason']) }}</flux:callout.text>
    </flux:callout>
@endforeach
