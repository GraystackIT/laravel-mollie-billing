{{--
    "Next charge: date and amount", with what it is made of.

    Shared by the customer dashboard and the admin's subscription tab, fed by
    Services\Billing\NextChargeSummary — the same lines, discount and date the charge
    itself is built from. Amounts are shown gross with the net breakdown underneath,
    because gross is what leaves the account.
--}}
@props(['summary', 'billable' => null])

@php
    use GraystackIT\MollieBilling\Services\Billing\NextChargeSummary;
    use GraystackIT\MollieBilling\Support\BillingMoney;
    use GraystackIT\MollieBilling\Support\BillingTime;

    $state = $summary['state'] ?? NextChargeSummary::STATE_NONE;
    $fmtDate = fn ($date) => $date === null
        ? null
        : ($billable !== null ? BillingTime::display($date, $billable) : BillingTime::displayUtc($date))?->translatedFormat('d. M Y');
    $date = $fmtDate($summary['date'] ?? null);
    $endsAt = $fmtDate($summary['ends_at'] ?? null);
    $freeUntil = $fmtDate($summary['plan_free_until'] ?? null);
@endphp

<div {{ $attributes->class('space-y-3') }} data-next-charge="{{ $state }}">
    <div>
        <flux:subheading size="sm" class="text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.next_charge.title') }}</flux:subheading>

        @if ($state === NextChargeSummary::STATE_SCHEDULED)
            <flux:text class="mt-1 font-semibold">
                <span data-next-charge-date>{{ $date ?? '—' }}</span>
                <span class="text-zinc-400">·</span>
                <span class="tabular-nums" data-next-charge-gross>{{ BillingMoney::format($summary['gross']) }}</span>
            </flux:text>
        @elseif ($state === NextChargeSummary::STATE_FREE)
            <flux:text class="mt-1 font-semibold text-zinc-500 dark:text-zinc-400">{{ __('billing::portal.next_charge.free') }}</flux:text>
        @elseif ($state === NextChargeSummary::STATE_ENDING)
            <flux:text class="mt-1 font-semibold text-zinc-500 dark:text-zinc-400">
                {{ $endsAt ? __('billing::portal.next_charge.ending_on', ['date' => $endsAt]) : __('billing::portal.next_charge.ending') }}
            </flux:text>
        @elseif ($state === NextChargeSummary::STATE_PAST_DUE)
            <flux:text class="mt-1 font-semibold text-red-600 dark:text-red-400">{{ __('billing::portal.next_charge.past_due') }}</flux:text>
        @else
            <flux:text class="mt-1 font-semibold text-zinc-500 dark:text-zinc-400">—</flux:text>
        @endif
    </div>

    @if ($state === NextChargeSummary::STATE_SCHEDULED)
        <dl class="space-y-1 text-sm">
            @foreach ($summary['lines'] as $line)
                <div class="flex justify-between gap-4">
                    <dt class="text-zinc-500 dark:text-zinc-400">
                        {{ $line['label'] }}@if ($line['quantity'] > 1) <span class="text-zinc-400">× {{ $line['quantity'] }}</span>@endif
                    </dt>
                    <dd class="tabular-nums">{{ BillingMoney::format($line['net']) }}</dd>
                </div>
            @endforeach

            @if ($summary['discount_net'] > 0)
                <div class="flex justify-between gap-4 text-emerald-600 dark:text-emerald-400">
                    <dt>
                        {{ $freeUntil
                            ? __('billing::portal.next_charge.plan_free_until', ['date' => $freeUntil])
                            : __('billing::portal.next_charge.discount', ['code' => $summary['discount_code'] ?? '']) }}
                    </dt>
                    <dd class="tabular-nums">−{{ BillingMoney::format($summary['discount_net']) }}</dd>
                </div>
            @endif

            <div class="flex justify-between gap-4 border-t border-zinc-200/75 pt-1 dark:border-zinc-700/50">
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('billing::portal.next_charge.net') }}</dt>
                <dd class="tabular-nums">{{ BillingMoney::format($summary['net']) }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('billing::portal.next_charge.vat', ['rate' => rtrim(rtrim(number_format((float) $summary['vat_rate'], 1, '.', ''), '0'), '.')]) }}</dt>
                <dd class="tabular-nums">{{ BillingMoney::format($summary['vat']) }}</dd>
            </div>
            <div class="flex justify-between gap-4 font-semibold">
                <dt>{{ __('billing::portal.next_charge.total') }}</dt>
                <dd class="tabular-nums">{{ BillingMoney::format($summary['gross']) }}</dd>
            </div>
        </dl>

        @if ($summary['deferred'])
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('billing::portal.next_charge.deferred_hint') }}</flux:text>
        @endif
        @if ($summary['has_scheduled_change'])
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('billing::portal.next_charge.scheduled_change_hint') }}</flux:text>
        @endif
        <flux:text size="sm" class="text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.next_charge.usage_hint') }}</flux:text>
    @endif
</div>
