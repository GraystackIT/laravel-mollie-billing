<?php

use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use Livewire\Component;

new class extends Component {
    public mixed $billableId = null;
    public ?int $refundInvoiceId = null;
    public ?int $refundAmount = null;
    public string $refundReason = 'goodwill';
    public ?string $refundText = null;
    public ?string $flash = null;

    public function mount(mixed $billableId = null): void { $this->billableId = $billableId; }

    public function billable(): mixed
    {
        $class = config('mollie-billing.billable_model');
        return $class ? $class::find($this->billableId) : null;
    }

    public function refund(RefundInvoiceService $service): void
    {
        $b = $this->billable();
        if (! $b || ! $this->refundInvoiceId) return;
        $invoice = $b->billingInvoices()->where('id', $this->refundInvoiceId)->first();
        if (! $invoice) return;
        try {
            $reason = RefundReasonCode::from($this->refundReason);
            if ($this->refundAmount) {
                $service->refundPartially($invoice, (int) $this->refundAmount, $reason, $this->refundText);
            } else {
                $service->refundFully($invoice, $reason, $this->refundText);
            }
            $this->flash = 'Refund processed.';
            $this->reset(['refundInvoiceId', 'refundAmount', 'refundText']);
        } catch (\Throwable $e) {
            $this->flash = 'Error: '.$e->getMessage();
        }
    }
};

?>

<div class="space-y-3">
    @if ($flash)
        <flux:callout variant="success" icon="check-circle" inline>{{ $flash }}</flux:callout>
    @endif
    @php $b = $this->billable(); @endphp
    @if ($b)
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Date</flux:table.column>
                <flux:table.column>Kind</flux:table.column>
                <flux:table.column align="end">Net</flux:table.column>
                <flux:table.column align="end">Gross</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($b->billingInvoices()->limit(20)->get() as $inv)
                    <flux:table.row :key="$inv->id">
                        <flux:table.cell>{{ $inv->created_at->format('Y-m-d') }}</flux:table.cell>
                        <flux:table.cell>{{ $inv->invoice_kind }}</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($inv->amount_net / 100, 2) }}</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($inv->amount_gross / 100, 2) }}</flux:table.cell>
                        <flux:table.cell>{{ $inv->status->value }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:button size="xs" variant="ghost" wire:click="$set('refundInvoiceId', {{ $inv->id }})">Refund</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6" align="center">No invoices.</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
        @if ($refundInvoiceId)
            <flux:card>
                <form wire:submit="refund" class="space-y-3">
                    <flux:heading size="md">Refund invoice #{{ $refundInvoiceId }}</flux:heading>
                    <flux:input type="number" wire:model="refundAmount" label="Amount (cents)" placeholder="Empty = full" />
                    <flux:select wire:model="refundReason" label="Reason">
                        <flux:select.option value="service_outage">Service outage</flux:select.option>
                        <flux:select.option value="billing_error">Billing error</flux:select.option>
                        <flux:select.option value="goodwill">Goodwill</flux:select.option>
                        <flux:select.option value="chargeback">Chargeback</flux:select.option>
                        <flux:select.option value="cancellation">Cancellation</flux:select.option>
                        <flux:select.option value="other">Other</flux:select.option>
                    </flux:select>
                    <flux:input wire:model="refundText" label="Reason text" description="Required for Other" />
                    <div class="flex gap-2">
                        <flux:button type="submit" size="sm" variant="danger">Issue refund</flux:button>
                        <flux:button type="button" size="sm" wire:click="$set('refundInvoiceId', null)">Cancel</flux:button>
                    </div>
                </form>
            </flux:card>
        @endif
    @endif
</div>
