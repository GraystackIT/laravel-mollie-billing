<?php

use GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $sortBy = 'scheduled_change_at';
    public string $sortDirection = 'asc';
    public ?string $flash = null;
    public ?string $error = null;

    public function updatingSearch(): void { $this->resetPage(); }

    public function sort(string $column): void
    {
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
            catch (\Throwable $e) { $this->error = $e->getMessage(); }
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
            $query->orderBy($this->sortBy, $this->sortDirection);
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
            <flux:card class="p-0!">
                <flux:table :paginate="$billables">
                    <flux:table.columns>
                        <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection" wire:click="sort('name')">Billable</flux:table.column>
                        <flux:table.column sortable :sorted="$sortBy === 'scheduled_change_at'" :direction="$sortDirection" wire:click="sort('scheduled_change_at')">At</flux:table.column>
                        <flux:table.column>Change</flux:table.column>
                        <flux:table.column class="w-48"></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($billables as $b)
                            @php $change = $b->getBillingSubscriptionMeta()['scheduled_change'] ?? []; @endphp
                            <flux:table.row :key="$b->getKey()">
                                <flux:table.cell variant="strong">
                                    <a href="{{ route('billing.admin.billables.show', $b) }}" class="hover:underline">{{ $b->name }}</a>
                                    <flux:text size="xs" class="text-zinc-500">{{ $b->email }}</flux:text>
                                </flux:table.cell>
                                <flux:table.cell class="tabular-nums">
                                    {{ $b->scheduled_change_at?->format('Y-m-d H:i') }}
                                    <flux:text size="xs" class="text-zinc-500">{{ $b->scheduled_change_at?->diffForHumans() }}</flux:text>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($change as $key => $value)
                                            <flux:badge size="sm" color="zinc" class="font-mono">
                                                {{ $key }}: {{ is_scalar($value) ? $value : json_encode($value) }}
                                            </flux:badge>
                                        @endforeach
                                        @if (empty($change))
                                            <span class="text-zinc-400">—</span>
                                        @endif
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <div class="flex gap-2">
                                        <flux:button size="xs" icon="play" wire:click="applyNow({{ $b->getKey() }})" wire:confirm="Apply this change now?">Apply</flux:button>
                                        <flux:button size="xs" variant="danger" icon="x-mark" wire:click="cancel({{ $b->getKey() }})" wire:confirm="Cancel this scheduled change?">Cancel</flux:button>
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
