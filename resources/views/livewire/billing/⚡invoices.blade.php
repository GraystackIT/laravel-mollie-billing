<?php

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    private function resolveBillable(): ?Billable
    {
        return MollieBilling::resolveBillable(request());
    }

    public function with(): array
    {
        $billable = $this->resolveBillable();
        $currency = config('mollie-billing.currency_symbol', '€');

        if (! $billable) {
            return ['invoices' => null, 'currency' => $currency, 'stats' => null];
        }

        $invoices = $billable->billingInvoices()->latest()->paginate(20);

        $allInvoices = $billable->billingInvoices()->get();
        $stats = [
            'total' => $allInvoices->count(),
            'paid' => $allInvoices->where('status', InvoiceStatus::Paid)->count(),
            'open' => $allInvoices->where('status', InvoiceStatus::Open)->count(),
            'totalAmount' => $currency . number_format($allInvoices->where('status', InvoiceStatus::Paid)->sum('amount_gross') / 100, 2),
        ];

        return [
            'invoices' => $invoices,
            'currency' => $currency,
            'stats' => $stats,
        ];
    }
};

?>

<div class="space-y-6">
    <div>
        <flux:heading size="xl">{{ __('billing::portal.invoices') }}</flux:heading>
        <flux:subheading>{{ __('billing::portal.invoices_subtitle') }}</flux:subheading>
    </div>

    @if (! $invoices || $invoices->isEmpty())
        <flux:callout variant="secondary" icon="document-text">
            {{ __('billing::portal.no_invoices') }}
        </flux:callout>
    @else
        {{-- Summary stats --}}
        @if ($stats)
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <flux:card class="p-5!">
                    <flux:subheading>{{ __('billing::portal.invoice.total_invoices') }}</flux:subheading>
                    <div class="mt-3">
                        <span class="text-3xl font-bold tabular-nums tracking-tight">{{ $stats['total'] }}</span>
                    </div>
                </flux:card>
                <flux:card class="p-5!">
                    <flux:subheading>{{ __('billing::portal.invoice.paid_count') }}</flux:subheading>
                    <div class="mt-3">
                        <span class="text-3xl font-bold tabular-nums tracking-tight text-emerald-600 dark:text-emerald-400">{{ $stats['paid'] }}</span>
                    </div>
                </flux:card>
                <flux:card class="p-5!">
                    <flux:subheading>{{ __('billing::portal.invoice.open_count') }}</flux:subheading>
                    <div class="mt-3">
                        <span class="text-3xl font-bold tabular-nums tracking-tight {{ $stats['open'] > 0 ? 'text-amber-600 dark:text-amber-400' : '' }}">{{ $stats['open'] }}</span>
                    </div>
                </flux:card>
                <flux:card class="p-5!">
                    <flux:subheading>{{ __('billing::portal.invoice.total_paid') }}</flux:subheading>
                    <div class="mt-3">
                        <span class="text-3xl font-bold tabular-nums tracking-tight">{{ $stats['totalAmount'] }}</span>
                    </div>
                </flux:card>
            </div>
        @endif

        {{-- Invoice table --}}
        <flux:card class="p-0! overflow-hidden">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('billing::portal.invoice.date') }}</flux:table.column>
                    <flux:table.column>{{ __('billing::portal.invoice.kind') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('billing::portal.invoice.net') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('billing::portal.invoice.vat') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('billing::portal.invoice.gross') }}</flux:table.column>
                    <flux:table.column>{{ __('billing::portal.invoice.status') }}</flux:table.column>
                    <flux:table.column class="text-right"></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($invoices as $invoice)
                        <flux:table.row>
                            <flux:table.cell class="tabular-nums">{{ $invoice->created_at->translatedFormat('d. M Y') }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" color="zinc">{{ $invoice->invoice_kind }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="text-right tabular-nums">{{ $currency }}{{ number_format($invoice->amount_net / 100, 2) }}</flux:table.cell>
                            <flux:table.cell class="text-right tabular-nums text-zinc-400">{{ $currency }}{{ number_format($invoice->amount_vat / 100, 2) }}</flux:table.cell>
                            <flux:table.cell class="text-right tabular-nums font-medium">{{ $currency }}{{ number_format($invoice->amount_gross / 100, 2) }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="match($invoice->status) {
                                    \GraystackIT\MollieBilling\Enums\InvoiceStatus::Paid => 'lime',
                                    \GraystackIT\MollieBilling\Enums\InvoiceStatus::Refunded => 'amber',
                                    \GraystackIT\MollieBilling\Enums\InvoiceStatus::Failed => 'red',
                                    default => 'zinc',
                                }">{{ $invoice->status->value }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="text-right">
                                @if ($invoice->hasPdf())
                                    <flux:button size="xs" variant="ghost" icon="arrow-down-tray" href="{{ $invoice->getDownloadUrl() }}" target="_blank">
                                        {{ __('billing::portal.invoice.pdf') }}
                                    </flux:button>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>

        @if ($invoices->hasPages())
            <div>{{ $invoices->links() }}</div>
        @endif
    @endif
</div>
