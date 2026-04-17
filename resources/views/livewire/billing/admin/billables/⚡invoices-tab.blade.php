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
    public ?string $error = null;

    public function mount(mixed $billableId = null): void { $this->billableId = $billableId; }

    public function billable(): mixed
    {
        $class = config('mollie-billing.billable_model');
        return $class ? $class::find($this->billableId) : null;
    }

    public function with(): array
    {
        $billable = $this->billable();

        return [
            'billable' => $billable,
            'invoices' => $billable ? $billable->billingInvoices()->limit(20)->get() : collect(),
        ];
    }

    public function refund(RefundInvoiceService $service): void
    {
        $this->flash = $this->error = null;

        $b = $this->billable();
        if (! $b) {
            $this->error = 'Billable not found.';
            return;
        }

        if (! $this->refundInvoiceId) {
            $this->error = 'No invoice selected.';
            return;
        }

        $reason = RefundReasonCode::tryFrom($this->refundReason);
        if ($reason === null) {
            $this->error = 'Invalid refund reason.';
            return;
        }

        if ($this->refundAmount !== null && $this->refundAmount <= 0) {
            $this->error = 'Amount must be greater than zero.';
            return;
        }

        $invoice = $b->billingInvoices()->where('id', $this->refundInvoiceId)->first();
        if (! $invoice) {
            $this->error = 'Invoice not found.';
            return;
        }

        try {
            if ($this->refundAmount) {
                $service->refundPartially($invoice, (int) $this->refundAmount, $reason, $this->refundText);
            } else {
                $service->refundFully($invoice, $reason, $this->refundText);
            }
            $this->flash = 'Refund processed.';
            $this->reset(['refundInvoiceId', 'refundAmount', 'refundText']);
        } catch (\Throwable $e) {
            report($e);
            $this->error = 'Unable to process refund.';
        }
    }
};

?>

<div class="space-y-4">
    <x-mollie-billing::admin.flash :success="$flash" :error="$error" />

    @if ($billable)
        @if ($invoices->isEmpty())
            <flux:card>
                <x-mollie-billing::admin.empty
                    icon="document-text"
                    title="No invoices yet"
                    description="Invoices will appear here once the billable is charged."
                />
            </flux:card>
        @else
            <flux:card class="p-0!">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Date</flux:table.column>
                        <flux:table.column>Kind</flux:table.column>
                        <flux:table.column align="end">Net</flux:table.column>
                        <flux:table.column align="end">Gross</flux:table.column>
                        <flux:table.column>Status</flux:table.column>
                        <flux:table.column class="w-24"></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($invoices as $inv)
                            <flux:table.row :key="$inv->id">
                                <flux:table.cell class="tabular-nums">{{ $inv->created_at->format('Y-m-d') }}</flux:table.cell>
                                <flux:table.cell>{{ ucfirst(str_replace('_', ' ', (string) $inv->invoice_kind)) }}</flux:table.cell>
                                <flux:table.cell align="end">
                                    <x-mollie-billing::admin.money :cents="$inv->amount_net" />
                                </flux:table.cell>
                                <flux:table.cell align="end">
                                    <x-mollie-billing::admin.money :cents="$inv->amount_gross" />
                                </flux:table.cell>
                                <flux:table.cell>
                                    <x-mollie-billing::admin.enum-badge :value="$inv->status" />
                                </flux:table.cell>
                                <flux:table.cell>
                                    @if (in_array(optional($inv->status)->value ?? $inv->status, ['paid']))
                                        <flux:button size="xs" variant="ghost" icon="arrow-uturn-left" wire:click="$set('refundInvoiceId', {{ $inv->id }})">Refund</flux:button>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </flux:card>
        @endif

        @if ($refundInvoiceId)
            <x-mollie-billing::admin.section
                title="Refund invoice #{{ $refundInvoiceId }}"
                description="Leave the amount empty to refund in full. Partial refunds accept an amount in cents."
            >
                <form wire:submit="refund" class="space-y-4">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <flux:input
                            type="number"
                            wire:model="refundAmount"
                            label="Amount"
                            description="Net amount in cents. Example: 1000 = €10.00. Empty = refund full invoice."
                            placeholder="Full refund"
                            suffix="cents"
                            min="1"
                        />
                        <flux:select wire:model="refundReason" label="Reason">
                            @foreach (RefundReasonCode::cases() as $reason)
                                <flux:select.option value="{{ $reason->value }}">{{ \GraystackIT\MollieBilling\Support\EnumLabels::label($reason) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                    <flux:input
                        wire:model="refundText"
                        label="Reason text"
                        description="Required when reason is &quot;Other&quot;. Otherwise optional."
                    />
                    <div class="flex gap-2">
                        <flux:button type="submit" size="sm" variant="danger" icon="arrow-uturn-left">Issue refund</flux:button>
                        <flux:button type="button" size="sm" variant="ghost" wire:click="$set('refundInvoiceId', null)">Cancel</flux:button>
                    </div>
                </form>
            </x-mollie-billing::admin.section>
        @endif
    @endif
</div>
