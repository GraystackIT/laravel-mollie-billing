<?php

use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Support\BillingTime;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $sortBy = 'scheduled_change_at';
    public string $sortDirection = 'asc';
    public ?string $flash = null;
    public ?string $error = null;

    private const ALLOWED_SORTS = ['name', 'email', 'scheduled_change_at'];

    public function updatingSearch(): void { $this->resetPage(); }

    public function sort(string $column): void
    {
        if (! in_array($column, self::ALLOWED_SORTS, true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
    }

    public function cancel(mixed $id, ScheduleSubscriptionChange $service): void
    {
        $this->flash = $this->error = null;
        $class = config('mollie-billing.billable_model');
        $b = $class ? $class::find($id) : null;
        if (! $b) { $this->error = __('billing::admin.common.billable_not_found'); return; }

        // Both service methods return silently when there is nothing scheduled —
        // check first so the operator is not told about a change that never existed.
        if (! $this->hasScheduledChange($b)) {
            $this->error = __('billing::admin.scheduled_changes.nothing_scheduled');
            return;
        }

        $service->cancel($b);
        $this->flash = __('billing::admin.scheduled_changes.cancelled');
    }

    public function applyNow(mixed $id, ScheduleSubscriptionChange $service): void
    {
        $this->flash = $this->error = null;
        $class = config('mollie-billing.billable_model');
        $b = $class ? $class::find($id) : null;
        if (! $b) { $this->error = __('billing::admin.common.billable_not_found'); return; }

        if (! $this->hasScheduledChange($b)) {
            $this->error = __('billing::admin.scheduled_changes.nothing_scheduled');
            return;
        }

        try {
            // `onRequest`: an operator is looking at the result. A change that cannot be
            // applied must say so here and stay booked — the unattended path drops it and
            // tells the customer instead, after which this screen flashed "Applied" for a
            // change that had just been thrown away.
            $service->apply($b, true);
        } catch (\Throwable $e) {
            report($e);
            $this->error = __('billing::admin.scheduled_changes.apply_failed');
            return;
        }

        // apply() clears the marker on success only; a still-present marker means
        // the change did not go through even though nothing was thrown.
        if ($this->hasScheduledChange($b)) {
            $this->error = __('billing::admin.scheduled_changes.apply_incomplete');
            return;
        }

        // A change that costs money is only started here: its charge is at Mollie and the
        // plan moves when that payment settles. Saying "applied" for it was premature.
        $pendingCharge = ($b->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null) !== null;

        $this->flash = __($pendingCharge
            ? 'billing::admin.scheduled_changes.applied_pending_payment'
            : 'billing::admin.scheduled_changes.applied');
    }

    private function hasScheduledChange(mixed $billable): bool
    {
        if ($billable instanceof \Illuminate\Database\Eloquent\Model) {
            $billable->refresh();
        }

        return ($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null) !== null;
    }

    public function with(): array
    {
        $class = config('mollie-billing.billable_model');
        $query = $class ? $class::query()->whereNotNull('scheduled_change_at') : null;

        if ($query && $this->search !== '') {
            $query->billableSearch($this->search);
        }

        if ($query) {
            $sortBy = in_array($this->sortBy, self::ALLOWED_SORTS, true) ? $this->sortBy : 'scheduled_change_at';
            match ($sortBy) {
                'name' => $query->billableOrderByName($this->sortDirection),
                'email' => $query->billableOrderByEmail($this->sortDirection),
                default => $query->orderBy($sortBy, $this->sortDirection),
            };
        }

        return ['billables' => $query ? $query->paginate(20) : null];
    }
};

?>

<div class="space-y-6">
    <x-mollie-billing::admin.page-header
        :title="__('billing::admin.scheduled_changes.title')"
        :subtitle="__('billing::admin.scheduled_changes.subtitle')"
    />

    <x-mollie-billing::admin.flash :success="$flash" :error="$error" />

    @if (! $billables)
        <flux:card>
            <x-mollie-billing::admin.empty
                icon="exclamation-triangle"
                :title="__('billing::admin.common.no_billable_model')"
            />
        </flux:card>
    @else
        <flux:input
            type="search"
            wire:model.live.debounce.300ms="search"
            :placeholder="__('billing::admin.common.search_name_email')"
            icon="magnifying-glass"
        />

        @if ($billables->isEmpty())
            <flux:card>
                <x-mollie-billing::admin.empty
                    icon="calendar"
                    :title="__('billing::admin.scheduled_changes.empty_title')"
                    :description="__('billing::admin.scheduled_changes.empty_description')"
                />
            </flux:card>
        @else
            <flux:card class="p-0! sm:px-6! sm:py-2!">
                <flux:table :paginate="$billables">
                    <flux:table.columns>
                        <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection" wire:click="sort('name')">{{ __('billing::admin.scheduled_changes.column.billable') }}</flux:table.column>
                        <flux:table.column sortable :sorted="$sortBy === 'scheduled_change_at'" :direction="$sortDirection" wire:click="sort('scheduled_change_at')">{{ __('billing::admin.scheduled_changes.column.at') }}</flux:table.column>
                        <flux:table.column>{{ __('billing::admin.scheduled_changes.column.change') }}</flux:table.column>
                        <flux:table.column class="w-48"></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @php $catalog = app(SubscriptionCatalogInterface::class); @endphp
                        @foreach ($billables as $b)
                            @php
                                $change = $b->getBillingSubscriptionMeta()['scheduled_change'] ?? [];
                                $newPlanCode = $change['plan_code'] ?? null;
                                $newPlanName = $newPlanCode ? ($catalog->planName($newPlanCode) ?? $newPlanCode) : null;
                                $newInterval = $change['interval'] ?? null;
                                $newIntervalLabel = $newInterval
                                    ? __('billing::enums.subscription_interval.'.$newInterval)
                                    : null;
                                $newSeats = $change['seats'] ?? null;
                                $newAddons = (array) ($change['addons'] ?? []);
                                $newCoupons = array_filter(array_merge(
                                    array_filter([$change['coupon_code'] ?? null]),
                                    (array) ($change['coupon_codes'] ?? []),
                                ));
                                $currentPlanCode = $b->subscription_plan_code;
                                $currentPlanName = $currentPlanCode ? ($catalog->planName($currentPlanCode) ?? $currentPlanCode) : null;
                                $currentInterval = $b->subscription_interval?->value ?? null;
                                $planChanged = $newPlanCode !== null && $newPlanCode !== $currentPlanCode;
                                $intervalChanged = $newInterval !== null && $newInterval !== $currentInterval;
                            @endphp
                            <flux:table.row :key="$b->getKey()">
                                <flux:table.cell variant="strong">
                                    <a href="{{ route(BillingRoute::admin('billables.show'), $b) }}" class="hover:underline">{{ $b->getBillingName() }}</a>
                                    <flux:text size="xs" class="text-zinc-500">{{ $b->getBillingEmail() }}</flux:text>
                                </flux:table.cell>
                                <flux:table.cell class="tabular-nums">
                                    {{ BillingTime::displayUtc($b->scheduled_change_at)?->format('Y-m-d H:i') }} UTC
                                    <flux:text size="xs" class="text-zinc-500">{{ $b->scheduled_change_at?->diffForHumans() }}</flux:text>
                                </flux:table.cell>
                                <flux:table.cell>
                                    @if (empty($change))
                                        <span class="text-zinc-400">—</span>
                                    @else
                                        <div class="space-y-1">
                                            {{-- Headline: plan transition. Show "from → to" when the plan changes,
                                                 otherwise just the plan being kept. --}}
                                            <div class="flex flex-wrap items-center gap-1.5 text-sm">
                                                @if ($planChanged && $currentPlanName)
                                                    <span class="text-zinc-400 line-through dark:text-zinc-500">{{ $currentPlanName }}</span>
                                                    <flux:icon.arrow-right variant="micro" class="size-3 text-zinc-400" />
                                                @endif
                                                @if ($newPlanName)
                                                    <span class="font-medium text-zinc-900 dark:text-white">{{ $newPlanName }}</span>
                                                @endif
                                            </div>

                                            {{-- Interval transition (if any), mirroring the plan-transition style. --}}
                                            @if ($newIntervalLabel)
                                                <div class="flex flex-wrap items-center gap-1.5 text-sm text-zinc-600 dark:text-zinc-300">
                                                    @if ($intervalChanged && $currentInterval)
                                                        <span class="text-zinc-400 line-through dark:text-zinc-500">
                                                            {{ __('billing::enums.subscription_interval.'.$currentInterval) }}
                                                        </span>
                                                        <flux:icon.arrow-right variant="micro" class="size-3 text-zinc-400" />
                                                    @endif
                                                    <span>{{ $newIntervalLabel }}</span>
                                                </div>
                                            @endif

                                            {{-- Detail meta: seats / addons / coupons. Only render rows that carry info. --}}
                                            @if ($newSeats !== null || ! empty($newAddons) || ! empty($newCoupons))
                                                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                                                    @if ($newSeats !== null)
                                                        <span class="inline-flex items-center gap-1">
                                                            <flux:icon.users variant="micro" class="size-3" />
                                                            <span class="tabular-nums">{{ trans_choice('billing::admin.scheduled_changes.seats', (int) $newSeats, ['count' => $newSeats]) }}</span>
                                                        </span>
                                                    @endif
                                                    @if (! empty($newAddons))
                                                        <span class="inline-flex items-center gap-1">
                                                            <flux:icon.puzzle-piece variant="micro" class="size-3" />
                                                            <span>{{ implode(', ', array_map(fn ($code) => $catalog->addonName($code) ?? $code, $newAddons)) }}</span>
                                                        </span>
                                                    @endif
                                                    @if (! empty($newCoupons))
                                                        <span class="inline-flex items-center gap-1">
                                                            <flux:icon.ticket variant="micro" class="size-3" />
                                                            <span class="font-mono">{{ implode(', ', $newCoupons) }}</span>
                                                        </span>
                                                    @endif
                                                </div>
                                            @endif
                                        </div>
                                    @endif
                                    @php $retry = \GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange::retryState($b); @endphp
                                    @if ($retry)
                                        <div class="mt-2 space-y-1" dusk="scheduled-change-attempts-{{ $b->getKey() }}">
                                            <div class="flex items-center gap-1">
                                                <flux:badge size="sm" color="amber" icon="arrow-path">{{ __('billing::admin.scheduled_changes.attempts', ['attempts' => $retry['attempts'], 'max' => $retry['max']]) }}</flux:badge>
                                                <x-mollie-billing::admin.info :text="__('billing::admin.scheduled_changes.attempts_info')" />
                                            </div>
                                            @if ($retry['last_error'])
                                                <flux:text size="xs" class="text-zinc-500"><span class="font-medium">{{ __('billing::admin.scheduled_changes.last_error') }}:</span> {{ $retry['last_error'] }}</flux:text>
                                            @endif
                                            @if ($retry['payment_id'])
                                                <flux:text size="xs" class="text-zinc-500" dusk="scheduled-change-payment-{{ $b->getKey() }}">{{ __('billing::admin.scheduled_changes.payment_in_flight', ['payment' => $retry['payment_id']]) }}</flux:text>
                                            @endif
                                        </div>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell align="end">
                                    <div class="flex justify-end gap-1">
                                        <flux:tooltip :content="__('billing::admin.scheduled_changes.cancel_tooltip')">
                                            <flux:button size="xs" variant="ghost" icon="x-mark" :aria-label="__('billing::admin.scheduled_changes.cancel_aria')" wire:click='cancel({{ \Illuminate\Support\Js::from($b->getKey()) }})' wire:confirm="{{ __('billing::admin.scheduled_changes.cancel_confirm') }}" />
                                        </flux:tooltip>
                                        <flux:button size="xs" variant="primary" icon="play" wire:click='applyNow({{ \Illuminate\Support\Js::from($b->getKey()) }})' wire:confirm="{{ __('billing::admin.scheduled_changes.apply_confirm') }}">{{ __('billing::admin.scheduled_changes.apply_now') }}</flux:button>
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </flux:card>
        @endif
    @endif
</div>
