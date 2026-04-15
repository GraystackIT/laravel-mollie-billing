<?php

use GraystackIT\MollieBilling\Jobs\RetryUsageOverageChargeJob;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $sortBy = 'updated_at';
    public string $sortDirection = 'desc';
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

    public function retry(mixed $id): void
    {
        $class = config('mollie-billing.billable_model');
        $b = $class?->find($id);
        if ($b) {
            RetryUsageOverageChargeJob::dispatch($class, $b->getKey());
            $this->flash = "Retry dispatched for {$b->name}.";
        }
    }

    public function with(): array
    {
        $class = config('mollie-billing.billable_model');
        $q = $class ? $class::query()->where('subscription_status', 'past_due') : null;

        if ($q && $this->search !== '') {
            $q->where(function ($w): void {
                $w->where('name', 'like', '%'.$this->search.'%')
                  ->orWhere('email', 'like', '%'.$this->search.'%');
            });
        }

        if ($q) {
            $q->orderBy($this->sortBy, $this->sortDirection);
        }

        return ['billables' => $q ? $q->paginate(20) : null];
    }
};

?>

<div class="p-6 space-y-4">
    <flux:heading size="xl">Past due</flux:heading>

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
                <flux:table.column sortable :sorted="$sortBy === 'updated_at'" :direction="$sortDirection" wire:click="sort('updated_at')">Since</flux:table.column>
                <flux:table.column>Last failure</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($billables as $b)
                    <flux:table.row :key="$b->getKey()">
                        <flux:table.cell variant="strong">
                            {{ $b->name }}
                            <flux:text size="xs" class="text-zinc-500">{{ $b->email }}</flux:text>
                        </flux:table.cell>
                        <flux:table.cell>{{ $b->updated_at?->format('Y-m-d') }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:text size="xs">{{ data_get($b->getBillingSubscriptionMeta(), 'payment_failure.reason', '—') }}</flux:text>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:button size="xs" wire:click="retry({{ $b->getKey() }})">Retry overage</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4" align="center">No past-due billables.</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    @endif
</div>
