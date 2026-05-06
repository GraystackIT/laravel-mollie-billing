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
        if ($b) { $service->cancel($b); $this->flash = 'Scheduled change cancelled.'; }
    }

    public function applyNow(mixed $id, ScheduleSubscriptionChange $service): void
    {
        $this->flash = $this->error = null;
        $class = config('mollie-billing.billable_model');
        $b = $class ? $class::find($id) : null;
        if ($b) {
            try { $service->apply($b); $this->flash = 'Scheduled change applied.'; }
            catch (\Throwable $e) { report($e); $this->error = 'An error occurred while applying the scheduled change.'; }
        }
    }

    public function with(): array
    {
        $class = config('mollie-billing.billable_model');
        $query = $class ? $class::query()->whereNotNull('scheduled_change_at') : null;

        if ($query && $this->search !== '') {
            $query->where(function ($w): void {
                $w->where('name', 'like', '%'.$this->search.'%')
                  ->orWhere('email', 'like', '%'.$this->search.'%');
            });
        }

        if ($query) {
            $sortBy = in_array($this->sortBy, self::ALLOWED_SORTS, true) ? $this->sortBy : 'scheduled_change_at';
            $query->orderBy($sortBy, $this->sortDirection);
        }

        return ['billables' => $query ? $query->paginate(20) : null];
    }
};

?>

<div class="space-y-6">
    <x-mollie-billing::admin.page-header
        title="Scheduled changes"
        subtitle="Plan and interval changes queued to apply at the end of the current billing period."
    />

    <x-mollie-billing::admin.flash :success="$flash" :error="$error" />

    @if (! $billables)
        <flux:card>
            <x-mollie-billing::admin.empty
                icon="exclamation-triangle"
                title="No billable model configured"
            />
        </flux:card>
    @else
        <flux:input
            type="search"
            wire:model.live.debounce.300ms="search"
            placeholder="Search by name or email"
            icon="magnifying-glass"
        />

        @if ($billables->isEmpty())
            <flux:card>
                <x-mollie-billing::admin.empty
                    icon="calendar"
                    title="No scheduled changes"
                    description="When a billable schedules a plan change, it will appear here."
                />
            </flux:card>
        @else
            <flux:card class="p-0! sm:px-6! sm:py-2!">
                <flux:table :paginate="$billables">
                    <flux:table.columns>
                        <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection" wire:click="sort('name')">Billable</flux:table.column>
                        <flux:table.column sortable :sorted="$sortBy === 'scheduled_change_at'" :direction="$sortDirection" wire:click="sort('scheduled_change_at')">At</flux:table.column>
                        <flux:table.column>Change</flux:table.column>
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
                                    <a href="{{ route(BillingRoute::admin('billables.show'), $b) }}" class="hover:underline">{{ $b->name }}</a>
                                    <flux:text size="xs" class="text-zinc-500">{{ $b->email }}</flux:text>
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
                                                            <span class="tabular-nums">{{ $newSeats }} {{ (int) $newSeats === 1 ? 'seat' : 'seats' }}</span>
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
                                </flux:table.cell>
                                <flux:table.cell align="end">
                                    <div class="flex justify-end gap-1">
                                        <flux:tooltip content="Cancel scheduled change">
                                            <flux:button size="xs" variant="ghost" icon="x-mark" aria-label="Cancel" wire:click="cancel('{{ $b->getKey() }}')" wire:confirm="Cancel this scheduled change?" />
                                        </flux:tooltip>
                                        <flux:button size="xs" variant="primary" icon="play" wire:click="applyNow('{{ $b->getKey() }}')" wire:confirm="Apply this change now?">Apply now</flux:button>
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
