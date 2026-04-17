<?php

use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Support\BillingRoute;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $statusFilter = '';
    public string $sortBy = 'created_at';
    public string $sortDirection = 'desc';

    private const ALLOWED_SORTS = ['name', 'email', 'subscription_plan_code', 'subscription_status', 'created_at'];

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingStatusFilter(): void { $this->resetPage(); }

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
            $sortBy = in_array($this->sortBy, self::ALLOWED_SORTS, true) ? $this->sortBy : 'created_at';
            $query->orderBy($sortBy, $this->sortDirection);
        }

        return [
            'billables' => $query ? $query->paginate(20) : null,
        ];
    }
};

?>

<div class="space-y-6">
    <x-mollie-billing::admin.page-header
        title="Billables"
        subtitle="Customers, tenants or organisations subject to billing."
    />

    @if (! $billables)
        <flux:card>
            <x-mollie-billing::admin.empty
                icon="exclamation-triangle"
                title="No billable model configured"
                description="Set config('mollie-billing.billable_model') to your tenant model to populate this list."
            />
        </flux:card>
    @else
        <div class="flex flex-wrap items-center gap-2">
            <flux:input
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="Search by name or email"
                icon="magnifying-glass"
                class="flex-1 basis-64"
            />
            <flux:select wire:model.live="statusFilter" placeholder="All statuses" class="w-56 shrink-0">
                <flux:select.option value="">All statuses</flux:select.option>
                @foreach (SubscriptionStatus::cases() as $status)
                    <flux:select.option value="{{ $status->value }}">{{ \GraystackIT\MollieBilling\Support\EnumLabels::label($status) }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        @if ($billables->isEmpty())
            <flux:card>
                <x-mollie-billing::admin.empty
                    icon="users"
                    title="No billables match"
                    description="Try adjusting the search term or status filter."
                />
            </flux:card>
        @else
            <flux:card class="p-0!">
                <flux:table :paginate="$billables">
                    <flux:table.columns>
                        <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection" wire:click="sort('name')">Name</flux:table.column>
                        <flux:table.column sortable :sorted="$sortBy === 'email'" :direction="$sortDirection" wire:click="sort('email')">Email</flux:table.column>
                        <flux:table.column sortable :sorted="$sortBy === 'subscription_plan_code'" :direction="$sortDirection" wire:click="sort('subscription_plan_code')">Plan</flux:table.column>
                        <flux:table.column sortable :sorted="$sortBy === 'subscription_status'" :direction="$sortDirection" wire:click="sort('subscription_status')">Status</flux:table.column>
                        <flux:table.column class="w-16"></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($billables as $b)
                            <flux:table.row :key="$b->getKey()">
                                <flux:table.cell variant="strong">{{ $b->name }}</flux:table.cell>
                                <flux:table.cell>{{ $b->email }}</flux:table.cell>
                                <flux:table.cell class="font-mono text-sm">{{ $b->subscription_plan_code ?? '—' }}</flux:table.cell>
                                <flux:table.cell>
                                    <x-mollie-billing::admin.enum-badge :value="$b->subscription_status" />
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:button size="xs" variant="ghost" icon="arrow-right" :href="route(BillingRoute::admin('billables.show'), $b)">View</flux:button>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </flux:card>
        @endif
    @endif
</div>
