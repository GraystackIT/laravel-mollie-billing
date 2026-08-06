<?php

use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Exceptions\InvalidRefundTargetException;
use GraystackIT\MollieBilling\Exceptions\RefundExceedsInvoiceAmountException;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use GraystackIT\MollieBilling\Support\BillingMoney;
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
            $this->error = __('billing::admin.common.billable_not_found');
            return;
        }

        if (! $this->refundInvoiceId) {
            $this->error = __('billing::admin.billables.invoices.flash.no_invoice_selected');
            return;
        }

        $reason = RefundReasonCode::tryFrom($this->refundReason);
        if ($reason === null) {
            $this->error = __('billing::admin.billables.invoices.flash.invalid_reason');
            return;
        }

        $amountCents = $this->parseEuroToCents($this->refundAmount);
        if ($amountCents === false) {
            $this->error = __('billing::admin.billables.invoices.flash.invalid_amount');
            return;
        }
        if ($amountCents !== null && $amountCents <= 0) {
            $this->error = __('billing::admin.billables.invoices.flash.amount_positive');
            return;
        }

        $invoice = $b->billingInvoices()->where('id', $this->refundInvoiceId)->first();
        if (! $invoice) {
            $this->error = __('billing::admin.billables.invoices.flash.invoice_not_found');
            return;
        }

        if ($invoice->isFullyRefunded()) {
            $this->error = __('billing::admin.billables.invoices.flash.already_refunded');
            return;
        }

        $remaining = $invoice->remainingRefundableNet();
        if ($amountCents !== null && $amountCents > $remaining) {
            $this->error = __('billing::admin.billables.invoices.flash.exceeds_remaining', [
                'amount' => BillingMoney::format($remaining, $invoice->currency),
            ]);
            return;
        }

        try {
            if ($amountCents !== null) {
                $service->refundPartially($invoice, $amountCents, $reason, $this->refundText);
            } else {
                $service->refundFully($invoice, $reason, $this->refundText);
            }
            $this->flash = __('billing::admin.billables.invoices.flash.refund_processed');
            $this->showRefundModal = false;
            $this->reset(['refundInvoiceId', 'refundAmount', 'refundText']);
        } catch (RefundExceedsInvoiceAmountException $e) {
            $remaining = $invoice->fresh()->remainingRefundableNet();
            $this->error = $remaining > 0
                ? __('billing::admin.billables.invoices.flash.exceeds_remaining', ['amount' => BillingMoney::format($remaining, $invoice->currency)])
                : __('billing::admin.billables.invoices.flash.already_refunded');
        } catch (InvalidRefundTargetException $e) {
            $this->error = $e->getMessage();
        } catch (\InvalidArgumentException $e) {
            $this->error = $e->getMessage();
        } catch (\Throwable $e) {
            report($e);
            $this->error = __('billing::admin.billables.invoices.flash.refund_failed');
        }
    }

    public function regeneratePdf(int $invoiceId, InvoiceService $service): void
    {
        $this->flash = $this->error = null;

        $billable = $this->billable();
        if (! $billable) {
            $this->error = __('billing::admin.common.billable_not_found');
            return;
        }

        $invoice = $billable->billingInvoices()->where('id', $invoiceId)->first();
        if (! $invoice) {
            $this->error = __('billing::admin.billables.invoices.flash.invoice_not_found');
            return;
        }

        try {
            $success = $service->regeneratePdf($invoice);
            $this->flash = $success
                ? __('billing::admin.billables.invoices.flash.pdf_regenerated')
                : __('billing::admin.billables.invoices.flash.pdf_failed');
            if (! $success) {
                $this->error = $this->flash;
                $this->flash = null;
            }
        } catch (\Throwable $e) {
            report($e);
            $this->error = __('billing::admin.billables.invoices.flash.pdf_error', ['message' => $e->getMessage()]);
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
                    :title="__('billing::admin.billables.invoices.empty_title')"
                    :description="__('billing::admin.billables.invoices.empty_description')"
                />
            </flux:card>
        @else
            <flux:card class="p-0! sm:px-6! sm:py-2!">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('billing::admin.billables.invoices.column.date') }}</flux:table.column>
                        <flux:table.column>{{ __('billing::admin.billables.invoices.column.serial') }}</flux:table.column>
                        <flux:table.column>{{ __('billing::admin.billables.invoices.column.kind') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('billing::admin.billables.invoices.column.net') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('billing::admin.billables.invoices.column.gross') }}</flux:table.column>
                        <flux:table.column>{{ __('billing::admin.billables.invoices.column.status') }}</flux:table.column>
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
                                                −<x-mollie-billing::admin.money :cents="$effectiveRefundedNet" /> {{ __('billing::admin.billables.invoices.refunded_suffix') }}
                                            </div>
                                        @elseif ($fullyRefunded)
                                            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('billing::admin.billables.invoices.fully_refunded') }}</div>
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
                                        <flux:tooltip :content="__('billing::admin.billables.invoices.refund')">
                                            <flux:button size="xs" variant="ghost" :aria-label="__('billing::admin.billables.invoices.refund')" icon="arrow-uturn-left" wire:click="openRefund({{ $inv->id }})"/>
                                        </flux:tooltip>
                                        @endif
                                        <flux:tooltip :content="$inv->hasPdf() ? __('billing::admin.billables.invoices.regenerate_pdf') : __('billing::admin.billables.invoices.generate_pdf')">
                                            <flux:button
                                                size="xs"
                                                variant="ghost"
                                                icon="arrow-path"
                                                :aria-label="__('billing::admin.billables.invoices.regenerate_pdf_aria')"
                                                wire:click="regeneratePdf({{ $inv->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="regeneratePdf({{ $inv->id }})"
                                            />
                                        </flux:tooltip>
                                        @if ($pdfUrl)
                                            <flux:tooltip :content="__('billing::admin.billables.invoices.download_pdf')">
                                                <flux:button size="xs" variant="ghost" icon="arrow-down-tray" :href="$pdfUrl" target="_blank" :aria-label="__('billing::admin.billables.invoices.download_pdf_aria')" />
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
                $modalTitle = __('billing::admin.billables.invoices.modal.title', [
                    'invoice' => $refundInvoice->serial_number ?: '#'.$refundInvoice->id,
                ]);
            @endphp

            <div class="space-y-5">
                @php
                    $currencyCode = strtoupper((string) ($refundInvoice->currency ?: config('mollie-billing.currency', 'EUR')));

                    // Money strings that go through :placeholders below — the
                    // <x-…money> component can't be nested inside a translation.
                    $moneyFormat = fn (int $cents) => BillingMoney::format($cents, $currencyCode);
                @endphp

                <div>
                    <flux:heading size="lg">{{ $modalTitle }}</flux:heading>
                    <flux:text class="text-zinc-500 dark:text-zinc-400">
                        {{ __('billing::admin.billables.invoices.modal.description') }}
                    </flux:text>
                </div>

                @if ($isPartial)
                    <div class="rounded-lg border border-amber-200/70 bg-amber-50/60 px-3 py-2 text-sm text-amber-800 dark:border-amber-500/20 dark:bg-amber-950/30 dark:text-amber-300">
                        <span class="font-medium">{{ __('billing::admin.billables.invoices.modal.partially_refunded') }}</span>
                        <span class="tabular-nums">{{ __('billing::admin.billables.invoices.modal.partial_summary', [
                            'refunded' => $moneyFormat($alreadyRefundedNet),
                            'total' => $moneyFormat((int) $refundInvoice->amount_net),
                            'remaining' => $moneyFormat($remainingNet),
                        ]) }}</span>
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
                            :label="__('billing::admin.billables.invoices.modal.amount_label')"
                            :placeholder="__('billing::admin.billables.invoices.modal.amount_placeholder')"
                            :suffix="$currencyCode"
                        />
                        <flux:select wire:model="refundReason" :label="__('billing::admin.billables.invoices.modal.reason_label')">
                            @foreach (RefundReasonCode::cases() as $reason)
                                <flux:select.option value="{{ $reason->value }}">{{ $reason->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:input
                            wire:model="refundText"
                            :label="__('billing::admin.billables.invoices.modal.reason_text_label')"
                            :placeholder="__('billing::admin.billables.invoices.modal.reason_text_placeholder')"
                        />
                    </div>
                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                        {{ __('billing::admin.billables.invoices.modal.hint', [
                            'example' => BillingMoney::number(10.5),
                            'remaining' => $moneyFormat($remainingNet),
                        ]) }}
                    </flux:text>
                    <div class="flex justify-end gap-2 pt-2">
                        <flux:modal.close>
                            <flux:button type="button" size="sm" variant="ghost">{{ __('billing::admin.common.cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button type="submit" size="sm" variant="danger" icon="arrow-uturn-left">{{ __('billing::admin.billables.invoices.modal.submit') }}</flux:button>
                    </div>
                </form>
            </div>
        @endif
    </flux:modal>
</div>
