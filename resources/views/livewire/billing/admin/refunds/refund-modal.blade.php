<?php

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

?>

<flux:card class="max-w-md">
    <flux:heading size="md">Issue refund</flux:heading>

    @if ($flash)
        <flux:callout variant="success" icon="check-circle" inline class="mt-3">{{ $flash }}</flux:callout>
    @endif

    <form wire:submit="submit" class="space-y-3 mt-3">
        <flux:input type="number" wire:model="invoiceId" label="Invoice id" required />
        <flux:input type="number" wire:model="amountCents" label="Net amount (cents)" placeholder="Empty = full" />
        <flux:select wire:model="reason" label="Reason">
            <flux:select.option value="service_outage">Service outage</flux:select.option>
            <flux:select.option value="billing_error">Billing error</flux:select.option>
            <flux:select.option value="goodwill">Goodwill</flux:select.option>
            <flux:select.option value="chargeback">Chargeback</flux:select.option>
            <flux:select.option value="cancellation">Cancellation</flux:select.option>
            <flux:select.option value="other">Other</flux:select.option>
        </flux:select>
        <flux:input wire:model="reasonText" label="Reason text" />
        <flux:checkbox wire:model="notifyUser" label="Notify user" />
        <flux:button type="submit" size="sm" variant="danger">Refund</flux:button>
    </form>
</flux:card>
