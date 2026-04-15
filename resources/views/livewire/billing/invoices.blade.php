<?php

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public ?Billable $billable = null;

    public function mount(): void
    {
        $this->billable = MollieBilling::resolveBillable(request());
    }

    public function with(): array
    {
        return [
            'invoices' => $this->billable
                ? $this->billable->billingInvoices()->paginate(20)
                : null,
        ];
    }
};

?>

<div class="p-6 space-y-4">
    <h1 class="text-xl font-semibold">Invoices</h1>

    @if (! $invoices || $invoices->isEmpty())
        <p class="text-zinc-500">No invoices yet.</p>
    @else
        <table class="w-full text-sm border">
            <thead class="bg-zinc-50 text-left">
                <tr>
                    <th class="p-2">Date</th>
                    <th class="p-2">Kind</th>
                    <th class="p-2">Net</th>
                    <th class="p-2">VAT</th>
                    <th class="p-2">Gross</th>
                    <th class="p-2">Status</th>
                    <th class="p-2">PDF</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoices as $invoice)
                    <tr class="border-t">
                        <td class="p-2">{{ $invoice->created_at->format('Y-m-d') }}</td>
                        <td class="p-2">{{ $invoice->invoice_kind }}</td>
                        <td class="p-2">{{ number_format($invoice->amount_net / 100, 2) }}</td>
                        <td class="p-2">{{ number_format($invoice->amount_vat / 100, 2) }}</td>
                        <td class="p-2 font-medium">{{ number_format($invoice->amount_gross / 100, 2) }}</td>
                        <td class="p-2">{{ $invoice->status->value }}</td>
                        <td class="p-2">
                            @if ($invoice->mollie_pdf_url)
                                <a href="{{ $invoice->mollie_pdf_url }}" class="text-indigo-600" target="_blank">download</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        {{ $invoices->links() }}
    @endif
</div>
