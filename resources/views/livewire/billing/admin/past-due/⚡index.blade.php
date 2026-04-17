<?php

use GraystackIT\MollieBilling\Jobs\RetryUsageOverageChargeJob;
use GraystackIT\MollieBilling\Support\BillingRoute;
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
        $b = $class ? $class::find($id) : null;
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

<div class="space-y-6">
    <x-mollie-billing::admin.page-header
        title="Past due"
        subtitle="Billables whose last recurring charge or overage payment failed."
    />

    <x-mollie-billing::admin.flash :success="$flash" />

    @if (! $billables)
        <flux:card>
            <x-mollie-billing::admin.empty
                icon="exclamation-triangle"
                title="No billable model configured"
                description="Set config('mollie-billing.billable_model') to populate this list."
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
                    icon="check-circle"
                    title="Nothing past due"
                    description="All billables are currently up to date."
                />
            </flux:card>
        @else
            <flux:card class="p-0!">
                <flux:table :paginate="$billables">
                    <flux:table.columns>
                        <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection" wire:click="sort('name')">Billable</flux:table.column>
                        <flux:table.column sortable :sorted="$sortBy === 'updated_at'" :direction="$sortDirection" wire:click="sort('updated_at')">Since</flux:table.column>
                        <flux:table.column>Last failure</flux:table.column>
                        <flux:table.column class="w-32"></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($billables as $b)
                            <flux:table.row :key="$b->getKey()">
                                <flux:table.cell variant="strong">
                                    <a href="{{ route(BillingRoute::admin('billables.show'), $b) }}" class="hover:underline">{{ $b->name }}</a>
                                    <flux:text size="xs" class="text-zinc-500">{{ $b->email }}</flux:text>
                                </flux:table.cell>
                                <flux:table.cell class="tabular-nums">
                                    {{ $b->updated_at?->format('Y-m-d') }}
                                    <flux:text size="xs" class="text-zinc-500">{{ $b->updated_at?->diffForHumans() }}</flux:text>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:text size="xs" class="text-zinc-500">{{ data_get($b->getBillingSubscriptionMeta(), 'payment_failure.reason', '—') }}</flux:text>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:button size="xs" icon="arrow-path" wire:click="retry({{ $b->getKey() }})">Retry</flux:button>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </flux:card>
        @endif
    @endif
</div>
