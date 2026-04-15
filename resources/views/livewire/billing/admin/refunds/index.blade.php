@php

use GraystackIT\MollieBilling\Models\BillingInvoice;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;
    public string $reasonFilter = '';
    public function updatingReasonFilter(): void { $this->resetPage(); }

    public function with(): array
    {
        $q = BillingInvoice::query()->where('invoice_kind', 'credit_note')->latest();
        if ($this->reasonFilter !== '') $q->where('refund_reason_code', $this->reasonFilter);
        return ['notes' => $q->paginate(20)];
    }
};

@endphp

<div class="p-6 space-y-4">
    <flux:heading size="xl">Refunds</flux:heading>
    <select wire:model.live="reasonFilter" class="border rounded px-2 py-1.5">
        <option value="">All reasons</option>
        <option value="service_outage">Service outage</option>
        <option value="billing_error">Billing error</option>
        <option value="goodwill">Goodwill</option>
        <option value="chargeback">Chargeback</option>
        <option value="cancellation">Cancellation</option>
        <option value="other">Other</option>
    </select>
    <table class="w-full border text-sm">
        <thead class="bg-zinc-50 text-left"><tr><th class="p-2">Date</th><th class="p-2">Billable</th><th class="p-2">Amount</th><th class="p-2">Reason</th><th class="p-2">Original</th></tr></thead>
        <tbody>
            @foreach ($notes as $n)
                <tr class="border-t">
                    <td class="p-2">{{ $n->created_at->format('Y-m-d') }}</td>
                    <td class="p-2">{{ class_basename($n->billable_type) }}#{{ $n->billable_id }}</td>
                    <td class="p-2">{{ number_format($n->amount_gross / 100, 2) }}</td>
                    <td class="p-2">{{ $n->refund_reason_code?->value ?? '—' }}</td>
                    <td class="p-2">#{{ $n->parent_invoice_id ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <div>{{ $notes->links() }}</div>
</div>
