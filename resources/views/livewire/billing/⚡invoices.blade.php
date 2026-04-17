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
                ? $this->billable->billingInvoices()->latest()->paginate(20)
                : null,
        ];
    }
};

?>

<div class="space-y-6">
    <flux:heading size="xl">{{ __('billing::portal.invoices') }}</flux:heading>

    @if (! $invoices || $invoices->isEmpty())
        <flux:callout variant="secondary" icon="document-text">
            {{ __('billing::portal.no_invoices') }}
        </flux:callout>
    @else
        <flux:card>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('billing::portal.invoice.date') }}</flux:table.column>
                    <flux:table.column>{{ __('billing::portal.invoice.kind') }}</flux:table.column>
                    <flux:table.column align="right">{{ __('billing::portal.invoice.net') }}</flux:table.column>
                    <flux:table.column align="right">{{ __('billing::portal.invoice.vat') }}</flux:table.column>
                    <flux:table.column align="right">{{ __('billing::portal.invoice.gross') }}</flux:table.column>
                    <flux:table.column>{{ __('billing::portal.invoice.status') }}</flux:table.column>
                    <flux:table.column align="right">{{ __('billing::portal.invoice.pdf') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($invoices as $invoice)
                        <flux:table.row>
                            <flux:table.cell>{{ $invoice->created_at->format('Y-m-d') }}</flux:table.cell>
                            <flux:table.cell>{{ $invoice->invoice_kind }}</flux:table.cell>
                            <flux:table.cell align="right">{{ number_format($invoice->amount_net / 100, 2) }}</flux:table.cell>
                            <flux:table.cell align="right">{{ number_format($invoice->amount_vat / 100, 2) }}</flux:table.cell>
                            <flux:table.cell align="right" class="font-medium">{{ number_format($invoice->amount_gross / 100, 2) }}</flux:table.cell>
                            <flux:table.cell><flux:badge size="sm" color="zinc">{{ $invoice->status->value }}</flux:badge></flux:table.cell>
                            <flux:table.cell align="right">
                                @if ($invoice->mollie_pdf_url)
                                    <flux:button size="xs" variant="ghost" icon="arrow-down-tray" href="{{ $invoice->mollie_pdf_url }}" target="_blank">
                                        {{ __('billing::portal.download') }}
                                    </flux:button>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
            <div class="pt-4">{{ $invoices->links() }}</div>
        </flux:card>
    @endif
</div>
