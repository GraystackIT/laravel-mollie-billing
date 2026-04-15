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
        $class = config('mollie-billing.billable_model');
        $b = $class?->find($id);
        if ($b) { $service->cancel($b); $this->flash = 'Scheduled change cancelled.'; }
    }

    public function applyNow(mixed $id, ScheduleSubscriptionChange $service): void
    {
        $class = config('mollie-billing.billable_model');
        $b = $class?->find($id);
        if ($b) {
            try { $service->apply($b); $this->flash = 'Scheduled change applied.'; }
            catch (\Throwable $e) { $this->flash = 'Error: '.$e->getMessage(); }
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

<div class="p-6 space-y-4">
    <flux:heading size="xl">Scheduled changes</flux:heading>

    @if ($flash)
        <flux:callout variant="success" icon="check-circle">{{ $flash }}</flux:callout>
    @endif

    @if (! $billables)
        <flux:text class="text-zinc-500">No billable model configured.</flux:text>
    @else
        <flux:input
            type="search"
            wire:model.live.debounce.300ms="search"
            placeholder="Name or email"
            icon="magnifying-glass"
            class="w-full"
        />

        <flux:table :paginate="$billables">
            <flux:table.columns>
                <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection" wire:click="sort('name')">Billable</flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'scheduled_change_at'" :direction="$sortDirection" wire:click="sort('scheduled_change_at')">At</flux:table.column>
                <flux:table.column>Change</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($billables as $b)
                    <flux:table.row :key="$b->getKey()">
                        <flux:table.cell variant="strong">{{ $b->name }}</flux:table.cell>
                        <flux:table.cell>{{ $b->scheduled_change_at?->format('Y-m-d H:i') }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:text size="xs" class="font-mono">{{ json_encode($b->getBillingSubscriptionMeta()['scheduled_change'] ?? []) }}</flux:text>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex gap-2">
                                <flux:button size="xs" wire:click="applyNow({{ $b->getKey() }})">Apply now</flux:button>
                                <flux:button size="xs" variant="danger" wire:click="cancel({{ $b->getKey() }})">Cancel</flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4" align="center">No scheduled changes.</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    @endif
</div>
