<?php

use GraystackIT\MollieBilling\Enums\RefundReasonCode;
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

<div class="space-y-6">
    <x-mollie-billing::admin.page-header
        title="Refunds"
        subtitle="Credit notes issued for partial or full refunds."
    />

    <div class="flex flex-wrap items-center gap-2">
        <flux:input
            type="search"
            wire:model.live.debounce.300ms="search"
            placeholder="Filter by billable id"
            icon="magnifying-glass"
            class="flex-1 basis-64"
        />
        <flux:select wire:model.live="reasonFilter" placeholder="All reasons" class="w-56 shrink-0">
            <flux:select.option value="">All reasons</flux:select.option>
            @foreach (RefundReasonCode::cases() as $reason)
                <flux:select.option value="{{ $reason->value }}">{{ \GraystackIT\MollieBilling\Support\EnumLabels::label($reason) }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @if ($notes->isEmpty())
        <flux:card>
            <x-mollie-billing::admin.empty
                icon="arrow-uturn-left"
                title="No refunds yet"
                description="Credit notes issued from the invoices tab will appear here."
            />
        </flux:card>
    @else
        <flux:card class="p-0!">
            <flux:table :paginate="$notes">
                <flux:table.columns>
                    <flux:table.column sortable :sorted="$sortBy === 'created_at'" :direction="$sortDirection" wire:click="sort('created_at')">Date</flux:table.column>
                    <flux:table.column>Billable</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'amount_gross'" :direction="$sortDirection" wire:click="sort('amount_gross')" align="end">Amount</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'refund_reason_code'" :direction="$sortDirection" wire:click="sort('refund_reason_code')">Reason</flux:table.column>
                    <flux:table.column>Original</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($notes as $n)
                        <flux:table.row :key="$n->id">
                            <flux:table.cell class="tabular-nums">{{ $n->created_at->format('Y-m-d') }}</flux:table.cell>
                            <flux:table.cell variant="strong" class="font-mono">{{ class_basename($n->billable_type) }}#{{ $n->billable_id }}</flux:table.cell>
                            <flux:table.cell align="end">
                                <x-mollie-billing::admin.money :cents="$n->amount_gross" />
                            </flux:table.cell>
                            <flux:table.cell>
                                <x-mollie-billing::admin.enum-badge :value="$n->refund_reason_code" />
                            </flux:table.cell>
                            <flux:table.cell class="font-mono text-sm">#{{ $n->parent_invoice_id ?? '—' }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @endif
</div>
