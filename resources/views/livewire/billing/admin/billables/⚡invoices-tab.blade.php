<?php

use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Exceptions\InvalidRefundTargetException;
use GraystackIT\MollieBilling\Exceptions\RefundExceedsInvoiceAmountException;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use GraystackIT\MollieBilling\Support\BillingTime;
use Livewire\Component;

new class extends Component {
    public mixed $billableId = null;
    public ?int $refundInvoiceId = null;
    public bool $showRefundModal = false;
    /** Euro decimal string entered by the admin (e.g. "10.50" or "10,50"). Parsed to cents on submit. */
    public ?string $refundAmount = null;
    public string $refundReason = 'goodwill';
    public ?string $refundText = null;
    public ?string $flash = null;
    public ?string $error = null;

    public function mount(mixed $billableId = null): void { $this->billableId = $billableId; }

    public function openRefund(int $invoiceId): void
    {
        $this->reset(['refundAmount', 'refundText', 'error']);
        $this->refundReason = 'goodwill';
        $this->refundInvoiceId = $invoiceId;
        $this->showRefundModal = true;
    }

    public function closeRefund(): void
    {
        $this->showRefundModal = false;
        $this->reset(['refundInvoiceId', 'refundAmount', 'refundText']);
    }

    /**
     * Parse the euro string entered in the modal to integer cents. Accepts both `.`
     * and `,` as decimal separator, optional thousands separator stripped. Empty
     * string / null means "full refund" — return null. Anything that does not parse
     * to a non-negative number returns false so the caller can flash an error.
     */
    private function parseEuroToCents(?string $input): null|int|false
    {
        if ($input === null) {
            return null;
        }
        $trimmed = trim($input);
        if ($trimmed === '') {
            return null;
        }

        // Strip thousands separators heuristically. We accept "1.234,56" and "1,234.56"
        // by removing every separator that is not the last decimal mark.
        $normalized = str_replace([' ', "\u{00A0}"], '', $trimmed);
        $lastComma = strrpos($normalized, ',');
        $lastDot = strrpos($normalized, '.');
        $decimalPos = max($lastComma === false ? -1 : $lastComma, $lastDot === false ? -1 : $lastDot);

        if ($decimalPos >= 0) {
            $intPart = preg_replace('/[.,]/', '', substr($normalized, 0, $decimalPos));
            $fracPart = preg_replace('/[.,]/', '', substr($normalized, $decimalPos + 1));
            if (strlen($fracPart) > 2) {
                return false;
            }
            $normalized = $intPart.'.'.$fracPart;
        } else {
            $normalized = preg_replace('/[.,]/', '', $normalized);
        }

        if (! is_numeric($normalized)) {
            return false;
        }
        $value = (float) $normalized;
        if ($value < 0) {
            return false;
        }

        return (int) round($value * 100);
    }

    public function billable(): mixed
    {
        $class = config('mollie-billing.billable_model');
        return $class ? $class::find($this->billableId) : null;
    }

    public function with(): array
    {
        $billable = $this->billable();

        $refundInvoice = null;
        if ($billable && $this->refundInvoiceId) {
            $refundInvoice = $billable->billingInvoices()->where('id', $this->refundInvoiceId)->first();
        }

        return [
            'billable' => $billable,
            'invoices' => $billable ? $billable->billingInvoices()->limit(20)->get() : collect(),
            'refundInvoice' => $refundInvoice,
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

        $amountCents = $this->parseEuroToCents($this->refundAmount);
        if ($amountCents === false) {
            $this->error = 'Invalid amount. Use a number with up to 2 decimals, e.g. 10.50.';
            return;
        }
        if ($amountCents !== null && $amountCents <= 0) {
            $this->error = 'Amount must be greater than zero.';
            return;
        }

        $invoice = $b->billingInvoices()->where('id', $this->refundInvoiceId)->first();
        if (! $invoice) {
            $this->error = 'Invoice not found.';
            return;
        }

        if ($invoice->isFullyRefunded()) {
            $this->error = 'Invoice is already fully refunded.';
            return;
        }

        $remaining = $invoice->remainingRefundableNet();
        if ($amountCents !== null && $amountCents > $remaining) {
            $this->error = 'Amount exceeds remaining refundable net of '.number_format($remaining / 100, 2).'.';
            return;
        }

        try {
            if ($amountCents !== null) {
                $service->refundPartially($invoice, $amountCents, $reason, $this->refundText);
            } else {
                $service->refundFully($invoice, $reason, $this->refundText);
            }
            $this->flash = 'Refund processed.';
            $this->showRefundModal = false;
            $this->reset(['refundInvoiceId', 'refundAmount', 'refundText']);
        } catch (RefundExceedsInvoiceAmountException $e) {
            $remaining = $invoice->fresh()->remainingRefundableNet();
            $this->error = $remaining > 0
                ? 'Amount exceeds remaining refundable net of '.number_format($remaining / 100, 2).'.'
                : 'This invoice has already been fully refunded.';
        } catch (InvalidRefundTargetException $e) {
            $this->error = $e->getMessage();
        } catch (\InvalidArgumentException $e) {
            $this->error = $e->getMessage();
        } catch (\Throwable $e) {
            report($e);
            $this->error = 'Unable to process refund. The payment provider rejected the request — the invoice may already be fully refunded.';
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
            <flux:card class="p-0! sm:px-6! sm:py-2!">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Date</flux:table.column>
                        <flux:table.column>Serial</flux:table.column>
                        <flux:table.column>Kind</flux:table.column>
                        <flux:table.column align="end">Net</flux:table.column>
                        <flux:table.column align="end">Gross</flux:table.column>
                        <flux:table.column>Status</flux:table.column>
                        <flux:table.column class="w-40"></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($invoices as $inv)
                            @php
                                $isPaid = (optional($inv->status)->value ?? $inv->status) === 'paid';
                                $isRefundKind = (optional($inv->invoice_kind)->value ?? $inv->invoice_kind) === 'refund';
                                $refundable = $isPaid && ! $isRefundKind;
                                $effectiveRefundedNet = $refundable ? $inv->effectiveRefundedNet() : 0;
                                $fullyRefunded = $refundable && $inv->isFullyRefunded();
                                $partiallyRefunded = $refundable && $effectiveRefundedNet > 0 && ! $fullyRefunded;
                            @endphp
                            <flux:table.row :key="$inv->id">
                                <flux:table.cell class="tabular-nums">{{ BillingTime::displayUtc($inv->created_at)->format('Y-m-d H:i') }}</flux:table.cell>
                                <flux:table.cell class="font-mono text-sm">{{ $inv->serial_number ?? '—' }}</flux:table.cell>
                                <flux:table.cell><x-mollie-billing::admin.enum-badge :value="$inv->invoice_kind" /></flux:table.cell>
                                <flux:table.cell align="end">
                                    <div class="space-y-0.5">
                                        <x-mollie-billing::admin.money :cents="$inv->amount_net" />
                                        @if ($partiallyRefunded)
                                            <div class="text-xs text-amber-600 dark:text-amber-400">
                                                −<x-mollie-billing::admin.money :cents="$effectiveRefundedNet" /> refunded
                                            </div>
                                        @elseif ($fullyRefunded)
                                            <div class="text-xs text-zinc-500 dark:text-zinc-400">Fully refunded</div>
                                        @endif
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell align="end">
                                         <div><x-mollie-billing::admin.money :cents="$inv->amount_gross" /></div>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <x-mollie-billing::admin.enum-badge :value="$inv->status" />
                                </flux:table.cell>
                                <flux:table.cell align="end">
                                    @php
                                        $pdfUrl = $inv->hasPdf()
                                            ? route(\GraystackIT\MollieBilling\Support\BillingRoute::admin('invoice.download'), ['invoice' => $inv])
                                            : null;
                                    @endphp
                                    <div class="flex items-center justify-end gap-1">
                                        @if ($refundable && ! $fullyRefunded)
                                        <flux:tooltip content="Refund">
                                            <flux:button size="xs" variant="ghost" aria-label="Refund" icon="arrow-uturn-left" wire:click="openRefund({{ $inv->id }})"/>
                                        </flux:tooltip>
                                        @endif
                                        @if ($pdfUrl)
                                            <flux:tooltip content="Download PDF">
                                                <flux:button size="xs" variant="ghost" icon="arrow-down-tray" :href="$pdfUrl" target="_blank" aria-label="Download invoice PDF" />
                                            </flux:tooltip>
                                        @endif
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </flux:card>
        @endif

    @endif

    <flux:modal wire:model.self="showRefundModal" name="refund-form" class="max-w-2xl">
        @if ($refundInvoice)
            @php
                $remainingNet = $refundInvoice->remainingRefundableNet();
                $alreadyRefundedNet = $refundInvoice->effectiveRefundedNet();
                $isPartial = $alreadyRefundedNet > 0;
                $modalTitle = 'Refund invoice'.($refundInvoice->serial_number ? ' '.$refundInvoice->serial_number : ' #'.$refundInvoice->id);
            @endphp

            <div class="space-y-5">
                @php
                    $currencyCode = strtoupper((string) ($refundInvoice->currency ?: config('mollie-billing.currency', 'EUR')));
                @endphp

                <div>
                    <flux:heading size="lg">{{ $modalTitle }}</flux:heading>
                    <flux:text class="text-zinc-500 dark:text-zinc-400">
                        Leave the amount empty to refund the full remaining net. Partial refunds accept a net amount with up to two decimals.
                    </flux:text>
                </div>

                @if ($isPartial)
                    <div class="rounded-lg border border-amber-200/70 bg-amber-50/60 px-3 py-2 text-sm text-amber-800 dark:border-amber-500/20 dark:bg-amber-950/30 dark:text-amber-300">
                        <span class="font-medium">Partially refunded.</span>
                        <span class="tabular-nums"><x-mollie-billing::admin.money :cents="$alreadyRefundedNet" /></span> of
                        <span class="tabular-nums"><x-mollie-billing::admin.money :cents="(int) $refundInvoice->amount_net" /></span> already refunded ·
                        max remaining: <span class="font-medium tabular-nums"><x-mollie-billing::admin.money :cents="$remainingNet" /></span>
                    </div>
                @endif

                <x-mollie-billing::admin.flash :error="$error" />

                <form wire:submit="refund" class="space-y-4">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3 md:items-start">
                        <flux:input
                            type="number"
                            step="0.01"
                            min="0.01"
                            :max="number_format($remainingNet / 100, 2, '.', '')"
                            wire:model="refundAmount"
                            label="Amount (net)"
                            placeholder="Full refund"
                            :suffix="$currencyCode"
                        />
                        <flux:select wire:model="refundReason" label="Reason">
                            @foreach (RefundReasonCode::cases() as $reason)
                                <flux:select.option value="{{ $reason->value }}">{{ \GraystackIT\MollieBilling\Support\AdminLocale::enumLabel($reason) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:input
                            wire:model="refundText"
                            label="Reason text"
                            placeholder="Required when reason is &quot;Other&quot;"
                        />
                    </div>
                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                        Net amount with up to two decimals (e.g. <span class="tabular-nums">10.50</span>). Leave empty to refund the full remaining net (<span class="tabular-nums"><x-mollie-billing::admin.money :cents="$remainingNet" /></span>).
                    </flux:text>
                    <div class="flex justify-end gap-2 pt-2">
                        <flux:modal.close>
                            <flux:button type="button" size="sm" variant="ghost">Cancel</flux:button>
                        </flux:modal.close>
                        <flux:button type="submit" size="sm" variant="danger" icon="arrow-uturn-left">Issue refund</flux:button>
                    </div>
                </form>
            </div>
        @endif
    </flux:modal>
</div>
