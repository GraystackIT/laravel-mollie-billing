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

<div class="space-y-3 text-sm">
    @if ($flash)<div class="p-2 rounded bg-green-50 border border-green-200">{{ $flash }}</div>@endif
    @php $b = $this->billable(); @endphp
    @if ($b)
        <table class="w-full border text-xs">
            <thead class="bg-zinc-50 text-left"><tr><th class="p-1">Date</th><th class="p-1">Kind</th><th class="p-1">Net</th><th class="p-1">Gross</th><th class="p-1">Status</th><th></th></tr></thead>
            <tbody>
                @foreach ($b->billingInvoices()->limit(20)->get() as $inv)
                    <tr class="border-t">
                        <td class="p-1">{{ $inv->created_at->format('Y-m-d') }}</td>
                        <td class="p-1">{{ $inv->invoice_kind }}</td>
                        <td class="p-1">{{ number_format($inv->amount_net / 100, 2) }}</td>
                        <td class="p-1">{{ number_format($inv->amount_gross / 100, 2) }}</td>
                        <td class="p-1">{{ $inv->status->value }}</td>
                        <td class="p-1"><button wire:click="$set('refundInvoiceId', {{ $inv->id }})" class="text-indigo-600">Refund</button></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if ($refundInvoiceId)
            <form wire:submit="refund" class="p-3 border rounded space-y-2 bg-zinc-50">
                <h3 class="font-medium">Refund invoice #{{ $refundInvoiceId }}</h3>
                <input type="number" wire:model="refundAmount" placeholder="Amount in cents (empty = full)" class="border rounded px-2 py-1 w-full">
                <select wire:model="refundReason" class="border rounded px-2 py-1 w-full">
                    <option value="service_outage">Service outage</option>
                    <option value="billing_error">Billing error</option>
                    <option value="goodwill">Goodwill</option>
                    <option value="chargeback">Chargeback</option>
                    <option value="cancellation">Cancellation</option>
                    <option value="other">Other</option>
                </select>
                <input wire:model="refundText" placeholder="Reason text (required for Other)" class="border rounded px-2 py-1 w-full">
                <div class="flex gap-2">
                    <button class="px-3 py-1 border rounded bg-red-600 text-white">Issue refund</button>
                    <button type="button" wire:click="$set('refundInvoiceId', null)" class="px-3 py-1 border rounded">Cancel</button>
                </div>
            </form>
        @endif
    @endif
</div>
