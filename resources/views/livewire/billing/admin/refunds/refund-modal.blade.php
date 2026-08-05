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

        $this->validate([
            'invoiceId' => ['required', 'integer', 'min:1'],
            'amountCents' => ['nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string'],
            'reasonText' => ['nullable', 'string', 'max:1000'],
            'notifyUser' => ['boolean'],
        ]);

        $reasonCode = RefundReasonCode::tryFrom($this->reason);
        if ($reasonCode === null) {
            $this->error = __('billing::admin.refunds.form.invalid_reason');
            return;
        }

        $invoice = BillingInvoice::find($this->invoiceId);
        if (! $invoice) {
            $this->error = __('billing::admin.refunds.form.invoice_not_found');
            return;
        }

        try {
            $service->refund($invoice, [
                'amount_net' => $this->amountCents,
                'wallet_credits' => [],
                'reason_code' => $reasonCode,
                'reason_text' => $this->reasonText,
                'notify_user' => $this->notifyUser,
            ]);
            $this->flash = __('billing::admin.refunds.form.issued');
        } catch (\Throwable $e) {
            report($e);
            $this->error = __('billing::admin.refunds.form.failed');
        }
    }
};

?>

<x-mollie-billing::admin.section :title="__('billing::admin.refunds.form.title')" :description="__('billing::admin.refunds.form.description')">
    <x-mollie-billing::admin.flash :success="$flash" :error="$error" />

    <form wire:submit="submit" class="space-y-4">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <flux:input
                type="number"
                wire:model="invoiceId"
                :label="__('billing::admin.refunds.form.invoice_id')"
                :description="__('billing::admin.refunds.form.invoice_id_description')"
                required
                min="1"
            />
            <flux:input
                type="number"
                wire:model="amountCents"
                :label="__('billing::admin.refunds.form.amount')"
                :description="__('billing::admin.refunds.form.amount_description')"
                :placeholder="__('billing::admin.refunds.form.amount_placeholder')"
                :suffix="__('billing::admin.coupons.create.cents')"
                min="1"
            />
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <flux:select wire:model="reason" :label="__('billing::admin.refunds.form.reason')">
                @foreach (RefundReasonCode::cases() as $r)
                    <flux:select.option value="{{ $r->value }}">{{ $r->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input
                wire:model="reasonText"
                :label="__('billing::admin.refunds.form.reason_text')"
                :description="__('billing::admin.refunds.form.reason_text_description')"
            />
        </div>

        <flux:checkbox wire:model="notifyUser" :label="__('billing::admin.refunds.form.notify_user')" :description="__('billing::admin.refunds.form.notify_user_description')" />

        <flux:button type="submit" size="sm" variant="danger" icon="arrow-uturn-left">{{ __('billing::admin.refunds.form.submit') }}</flux:button>
    </form>
</x-mollie-billing::admin.section>
