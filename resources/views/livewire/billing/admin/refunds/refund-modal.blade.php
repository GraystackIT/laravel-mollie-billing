@php

use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use Livewire\Component;

new class extends Component {
    public ?int $invoiceId = null;
    public ?int $amountCents = null;
    public string $reason = 'goodwill';
    public ?string $reasonText = null;
    public bool $notifyUser = true;
    public ?string $flash = null;

    public function submit(RefundInvoiceService $service): void
    {
        $invoice = BillingInvoice::find($this->invoiceId);
        if (! $invoice) return;
        try {
            $reasonCode = RefundReasonCode::from($this->reason);
            $service->refund($invoice, [
                'amount_net' => $this->amountCents,
                'wallet_credits' => [],
                'reason_code' => $reasonCode,
                'reason_text' => $this->reasonText,
                'notify_user' => $this->notifyUser,
            ]);
            $this->flash = 'Refund issued.';
        } catch (\Throwable $e) {
            $this->flash = 'Error: '.$e->getMessage();
        }
    }
};

@endphp

<div class="p-4 border rounded max-w-md space-y-3">
    <h2 class="font-semibold">Issue refund</h2>
    @if ($flash)<div class="p-2 rounded bg-green-50 border border-green-200 text-sm">{{ $flash }}</div>@endif
    <form wire:submit="submit" class="space-y-2 text-sm">
        <input type="number" wire:model="invoiceId" placeholder="Invoice id" class="border rounded px-2 py-1 w-full" required>
        <input type="number" wire:model="amountCents" placeholder="Net amount in cents (empty = full)" class="border rounded px-2 py-1 w-full">
        <select wire:model="reason" class="border rounded px-2 py-1 w-full">
            <option value="service_outage">Service outage</option>
            <option value="billing_error">Billing error</option>
            <option value="goodwill">Goodwill</option>
            <option value="chargeback">Chargeback</option>
            <option value="cancellation">Cancellation</option>
            <option value="other">Other</option>
        </select>
        <input wire:model="reasonText" placeholder="Reason text" class="border rounded px-2 py-1 w-full">
        <label class="flex items-center gap-2"><input type="checkbox" wire:model="notifyUser"> Notify user</label>
        <button class="px-3 py-1 border rounded bg-red-600 text-white">Refund</button>
    </form>
</div>
