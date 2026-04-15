<?php

use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $statusFilter = '';
    public string $sortBy = 'created_at';
    public string $sortDirection = 'desc';

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingStatusFilter(): void { $this->resetPage(); }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
    }

    public function with(): array
    {
        $class = config('mollie-billing.billable_model');
        $query = $class ? $class::query() : null;

        if ($query && $this->search !== '') {
            $query->where(function ($q): void {
                $q->where('email', 'like', '%'.$this->search.'%')
                  ->orWhere('name', 'like', '%'.$this->search.'%');
            });
        }

        if ($query && $this->statusFilter !== '') {
            $query->where('subscription_status', $this->statusFilter);
        }

        if ($query) {
            $query->orderBy($this->sortBy, $this->sortDirection);
        }

        return [
            'billables' => $query ? $query->paginate(20) : null,
        ];
    }
};

?>

<div class="p-6 space-y-4">
    <flux:heading size="xl">Billables</flux:heading>

    <div class="flex gap-2 items-center">
        <flux:input
            type="search"
            wire:model.live.debounce.300ms="search"
            placeholder="Name or email"
            icon="magnifying-glass"
            class="flex-1 basis-0"
        />
        <flux:select wire:model.live="statusFilter" placeholder="All statuses" class="w-64 shrink-0">
            <flux:select.option value="">All</flux:select.option>
            <flux:select.option value="active">Active</flux:select.option>
            <flux:select.option value="trial">Trial</flux:select.option>
            <flux:select.option value="past_due">Past due</flux:select.option>
            <flux:select.option value="cancelled">Cancelled</flux:select.option>
            <flux:select.option value="expired">Expired</flux:select.option>
        </flux:select>
    </div>

    @if (! $billables)
        <flux:text class="text-zinc-500">No billable model configured.</flux:text>
    @else
        <flux:table :paginate="$billables">
            <flux:table.columns>
                <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection" wire:click="sort('name')">Name</flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'email'" :direction="$sortDirection" wire:click="sort('email')">Email</flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'subscription_plan_code'" :direction="$sortDirection" wire:click="sort('subscription_plan_code')">Plan</flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'subscription_status'" :direction="$sortDirection" wire:click="sort('subscription_status')">Status</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($billables as $b)
                    <flux:table.row :key="$b->getKey()">
                        <flux:table.cell variant="strong">{{ $b->name }}</flux:table.cell>
                        <flux:table.cell>{{ $b->email }}</flux:table.cell>
                        <flux:table.cell>{{ $b->subscription_plan_code ?? '—' }}</flux:table.cell>
                        <flux:table.cell>{{ is_object($b->subscription_status) ? $b->subscription_status->value : $b->subscription_status }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:button size="xs" variant="ghost" :href="route('billing.admin.billables.show', $b)">View</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" align="center">No billables yet.</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    @endif
</div>
