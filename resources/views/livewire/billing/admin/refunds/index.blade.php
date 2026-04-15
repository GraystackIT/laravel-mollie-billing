<?php

use GraystackIT\MollieBilling\Models\BillingInvoice;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $reasonFilter = '';
    public string $search = '';
    public string $sortBy = 'created_at';
    public string $sortDirection = 'desc';

    public function updatingReasonFilter(): void { $this->resetPage(); }
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

    public function with(): array
    {
        $q = BillingInvoice::query()->where('invoice_kind', 'credit_note');

        if ($this->reasonFilter !== '') {
            $q->where('refund_reason_code', $this->reasonFilter);
        }

        if ($this->search !== '') {
            $q->where('billable_id', 'like', '%'.$this->search.'%');
        }

        return ['notes' => $q->orderBy($this->sortBy, $this->sortDirection)->paginate(20)];
    }
};

?>

<div class="p-6 space-y-4">
    <flux:heading size="xl">Refunds</flux:heading>

    <div class="flex gap-2 items-center">
        <flux:input
            type="search"
            wire:model.live.debounce.300ms="search"
            placeholder="Billable id"
            icon="magnifying-glass"
            class="flex-1 basis-0"
        />
        <flux:select wire:model.live="reasonFilter" placeholder="All reasons" class="w-64 shrink-0">
            <flux:select.option value="">All reasons</flux:select.option>
            <flux:select.option value="service_outage">Service outage</flux:select.option>
            <flux:select.option value="billing_error">Billing error</flux:select.option>
            <flux:select.option value="goodwill">Goodwill</flux:select.option>
            <flux:select.option value="chargeback">Chargeback</flux:select.option>
            <flux:select.option value="cancellation">Cancellation</flux:select.option>
            <flux:select.option value="other">Other</flux:select.option>
        </flux:select>
    </div>

    <flux:table :paginate="$notes">
        <flux:table.columns>
            <flux:table.column sortable :sorted="$sortBy === 'created_at'" :direction="$sortDirection" wire:click="sort('created_at')">Date</flux:table.column>
            <flux:table.column>Billable</flux:table.column>
            <flux:table.column sortable :sorted="$sortBy === 'amount_gross'" :direction="$sortDirection" wire:click="sort('amount_gross')" align="end">Amount</flux:table.column>
            <flux:table.column sortable :sorted="$sortBy === 'refund_reason_code'" :direction="$sortDirection" wire:click="sort('refund_reason_code')">Reason</flux:table.column>
            <flux:table.column>Original</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($notes as $n)
                <flux:table.row :key="$n->id">
                    <flux:table.cell>{{ $n->created_at->format('Y-m-d') }}</flux:table.cell>
                    <flux:table.cell variant="strong">{{ class_basename($n->billable_type) }}#{{ $n->billable_id }}</flux:table.cell>
                    <flux:table.cell align="end">{{ number_format($n->amount_gross / 100, 2) }}</flux:table.cell>
                    <flux:table.cell>{{ $n->refund_reason_code?->value ?? '—' }}</flux:table.cell>
                    <flux:table.cell>#{{ $n->parent_invoice_id ?? '—' }}</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" align="center">No refunds yet.</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</div>
