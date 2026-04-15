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
    public ?string $error = null;

    public function submit(RefundInvoiceService $service): void
    {
        $this->flash = $this->error = null;
        $invoice = BillingInvoice::find($this->invoiceId);
        if (! $invoice) {
            $this->error = 'Invoice not found.';
            return;
        }
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
            $this->error = $e->getMessage();
        }
    }
};

?>

<x-mollie-billing::admin.section title="Issue refund" description="Issue a refund by invoice id. Leave amount empty for a full refund.">
    <x-mollie-billing::admin.flash :success="$flash" :error="$error" />

    <form wire:submit="submit" class="space-y-4">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <flux:input
                type="number"
                wire:model="invoiceId"
                label="Invoice id"
                description="Numeric id of the invoice to refund."
                required
                min="1"
            />
            <flux:input
                type="number"
                wire:model="amountCents"
                label="Net amount"
                description="Amount in cents. Example: 1000 = €10.00. Empty = refund full invoice."
                placeholder="Full refund"
                suffix="cents"
                min="1"
            />
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <flux:select wire:model="reason" label="Reason">
                @foreach (RefundReasonCode::cases() as $r)
                    <flux:select.option value="{{ $r->value }}">{{ \GraystackIT\MollieBilling\Support\EnumLabels::label($r) }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input
                wire:model="reasonText"
                label="Reason text"
                description="Required when reason is &quot;Other&quot;."
            />
        </div>

        <flux:checkbox wire:model="notifyUser" label="Notify user" description="Send an email to the billable about this refund." />

        <flux:button type="submit" size="sm" variant="danger" icon="arrow-uturn-left">Issue refund</flux:button>
    </form>
</x-mollie-billing::admin.section>
