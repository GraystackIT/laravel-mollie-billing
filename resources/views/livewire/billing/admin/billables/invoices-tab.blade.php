<?php

use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Exceptions\InvalidRefundTargetException;
use GraystackIT\MollieBilling\Exceptions\RefundExceedsInvoiceAmountException;
use GraystackIT\MollieBilling\Exceptions\RefundExceedsLineAmountException;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingTime;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    /** Locked: every action below is scoped to this billable's invoices. */
    #[Locked]
    public mixed $billableId = null;
    /** Locked: which invoice the modal refunds is decided by openRefund(), not by the browser. */
    #[Locked]
    public ?int $refundInvoiceId = null;
    public bool $showRefundModal = false;
    /** Decimal string entered by the admin (e.g. "10.50" or "10,50"). Parsed to cents on submit. */
    public ?string $refundAmount = null;
    public string $refundReason = 'goodwill';
    public ?string $refundText = null;
    /**
     * Which line the refund is booked against: the original line's index, or empty for
     * the whole invoice (spread over its lines). Checked against the lines the invoice
     * offers on submit — a value the modal never showed is refused.
     */
    public ?string $refundLine = null;
    public ?string $flash = null;
    public ?string $error = null;

    /**
     * Locale-grouping characters ICU may emit inside a formatted amount, so an
     * operator can paste a displayed value straight back into the field:
     * regular space, NBSP, narrow NBSP (fr), thin space, right single quote (de_CH).
     */
    private const GROUPING_CHARS = [' ', "\u{00A0}", "\u{202F}", "\u{2009}", "\u{2019}"];

    public function mount(mixed $billableId = null): void { $this->billableId = $billableId; }

    public function openRefund(int $invoiceId): void
    {
        $this->reset(['refundAmount', 'refundText', 'refundLine', 'error']);
        $this->refundReason = 'goodwill';
        $this->refundInvoiceId = $invoiceId;
        $this->showRefundModal = true;
    }

    /**
     * The modal's close button and ESC only flip `showRefundModal`, so the reset
     * has to hang off that property — a `closeRefund()` action would never run.
     */
    public function updatedShowRefundModal(bool $value): void
    {
        if (! $value) {
            $this->reset(['refundInvoiceId', 'refundAmount', 'refundText', 'refundLine', 'error']);
        }
    }

    /**
     * Choosing a line fills in what is left of it; the operator can lower it. Going back
     * to the whole invoice leaves the amount as it is.
     */
    public function updatedRefundLine(?string $value): void
    {
        $this->error = null;

        if ($value === null || $value === '') {
            return;
        }

        $invoice = $this->refundTarget();
        $line = $invoice ? $this->offeredLine($invoice, $value) : null;

        if ($line !== null) {
            $this->refundAmount = BillingMoney::numberFromMinor($line['remaining_net'], $invoice->currency);
        }
    }

    /**
     * The line the modal offers under `$value`, or null.
     *
     * @return array{index: int, label: ?string, net: int, rate: float, remaining_net: int, remaining_vat: int}|null
     */
    private function offeredLine(mixed $invoice, string $value, bool $withExhausted = false): ?array
    {
        if (preg_match('/^\d+$/', $value) !== 1) {
            return null;
        }

        foreach (app(InvoiceService::class)->refundableLines($invoice, $withExhausted) as $line) {
            if ($line['index'] === (int) $value) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Parse the decimal string entered in the modal to integer minor units. Accepts
     * both `.` and `,` as decimal separator plus the locale grouping characters the
     * formatter emits; anything else (including scientific notation, which would
     * silently inflate the amount) returns false so the caller can flash an error.
     *
     * Scaled by the invoice's own currency, not by a hardcoded 100. This is real
     * money leaving the account: on a JPY install (`fractionDigits() === 0`) an
     * operator typing `500` to refund ¥500 posted a ¥50 000 refund — a 100× over-
     * refund — while every honest entry below ¥50 000 was rejected as "exceeds
     * remaining". KWD (3 digits) was wrong by 10× in the other direction.
     *
     * Composed from the integer and fraction parts rather than by multiplying a
     * float: `(float) '0.07' * 100` is 7.000000000000001, and round() only papers
     * over that for as long as the factor stays small.
     */
    private function parseAmountToMinor(string $input, ?string $currency = null): int|false
    {
        $digits = BillingMoney::fractionDigits($currency);

        // Strip thousands separators heuristically. We accept "1.234,56" and "1,234.56"
        // by removing every separator that is not the last decimal mark.
        $normalized = str_replace(self::GROUPING_CHARS, '', trim($input));
        $lastComma = strrpos($normalized, ',');
        $lastDot = strrpos($normalized, '.');
        $decimalPos = max($lastComma === false ? -1 : $lastComma, $lastDot === false ? -1 : $lastDot);

        if ($decimalPos >= 0) {
            $intPart = preg_replace('/[.,]/', '', substr($normalized, 0, $decimalPos));
            $fracPart = preg_replace('/[.,]/', '', substr($normalized, $decimalPos + 1));
            if (strlen($fracPart) > $digits) {
                return false;
            }
            $normalized = $intPart.'.'.$fracPart;
        } else {
            $normalized = preg_replace('/[.,]/', '', $normalized);
            $fracPart = '';
        }

        // Digits only, optional decimals up to what the currency has.
        // `is_numeric` would accept "1e3".
        $pattern = $digits === 0
            ? '/^\d+$/'
            : '/^\d+(?:\.\d{1,'.$digits.'})?$/';

        if (preg_match($pattern, $normalized) !== 1) {
            return false;
        }

        $intPart = $decimalPos >= 0 ? (string) $intPart : $normalized;

        return (int) $intPart * BillingMoney::minorUnitFactor($currency)
            + (int) str_pad($fracPart, $digits, '0');
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
            // Paginated, not capped. `limit(20)` silently hid everything older than the
            // twentieth invoice with no control to reach it — so a customer disputing a
            // charge from two years ago could not be refunded from the panel at all, and
            // the tab looked complete while it was not.
            'invoices' => $billable ? $billable->billingInvoices()->paginate(20) : null,
            'refundInvoice' => $refundInvoice,
        ];
    }

    /**
     * Resolve the invoice this modal addresses, scoped to the locked billable.
     * Sets `$error` and returns null when the target cannot be refunded.
     */
    private function refundTarget(): mixed
    {
        $b = $this->billable();
        if (! $b) {
            $this->error = __('billing::admin.common.billable_not_found');
            return null;
        }

        if (! $this->refundInvoiceId) {
            $this->error = __('billing::admin.billables.invoices.flash.no_invoice_selected');
            return null;
        }

        $invoice = $b->billingInvoices()->where('id', $this->refundInvoiceId)->first();
        if (! $invoice) {
            $this->error = __('billing::admin.billables.invoices.flash.invoice_not_found');
            return null;
        }

        if ($invoice->isFullyRefunded()) {
            $this->error = __('billing::admin.billables.invoices.flash.already_refunded');
            return null;
        }

        return $invoice;
    }

    private function refundReasonCode(): ?RefundReasonCode
    {
        $reason = RefundReasonCode::tryFrom($this->refundReason);
        if ($reason === null) {
            $this->error = __('billing::admin.billables.invoices.flash.invalid_reason');

            return null;
        }

        // Said here, in the operator's language. The service refuses the same thing, but
        // with an English developer message that was shown as it came.
        if ($reason === RefundReasonCode::Other && trim((string) $this->refundText) === '') {
            $this->error = __('billing::admin.billables.invoices.flash.reason_text_required');

            return null;
        }

        return $reason;
    }

    /**
     * Partial refund. The amount is mandatory: an empty field must never fall
     * through to a full refund — that is what refundFull() is for.
     */
    public function refund(RefundInvoiceService $service): void
    {
        $this->flash = $this->error = null;

        $reason = $this->refundReasonCode();
        if ($reason === null) {
            return;
        }

        $invoice = $this->refundTarget();
        if (! $invoice) {
            return;
        }

        $raw = trim((string) ($this->refundAmount ?? ''));
        if ($raw === '') {
            $this->error = __('billing::admin.billables.invoices.flash.amount_required');
            return;
        }

        $amountCents = $this->parseAmountToMinor($raw, $invoice->currency);
        if ($amountCents === false) {
            $this->error = __('billing::admin.billables.invoices.flash.invalid_amount', [
                // The example the operator is meant to retype has to be typable: on a
                // zero-decimal currency "10,50" is exactly what this parser rejects.
                'example' => BillingMoney::number(10.5, $invoice->currency),
            ]);
            return;
        }
        if ($amountCents <= 0) {
            $this->error = __('billing::admin.billables.invoices.flash.amount_positive');
            return;
        }

        $line = null;
        if ($this->refundLine !== null && $this->refundLine !== '') {
            // Read again now, not from what the modal showed: a colleague may have refunded
            // the same line since it was opened.
            $line = $this->offeredLine($invoice, $this->refundLine, withExhausted: true);

            if ($line === null) {
                $this->error = __('billing::admin.billables.invoices.flash.invalid_line');
                return;
            }

            if ($line['remaining_net'] <= 0) {
                $this->error = __('billing::admin.billables.invoices.flash.line_already_refunded');
                return;
            }

            if ($amountCents > $line['remaining_net']) {
                $this->error = __('billing::admin.billables.invoices.flash.exceeds_line', [
                    'amount' => BillingMoney::format($line['remaining_net'], $invoice->currency),
                ]);
                return;
            }
        }

        $remaining = $invoice->remainingRefundableNet();
        if ($amountCents > $remaining) {
            $this->error = __('billing::admin.billables.invoices.flash.exceeds_remaining', [
                'amount' => BillingMoney::format($remaining, $invoice->currency),
            ]);
            return;
        }

        $this->runRefund($invoice, fn () => $line !== null
            ? $service->refundLine($invoice, $line['index'], $amountCents, $reason, $this->refundText)
            : $service->refundPartially($invoice, $amountCents, $reason, $this->refundText));
    }

    /** Refund the whole remaining net — a deliberate, separately confirmed action. */
    public function refundFull(RefundInvoiceService $service): void
    {
        $this->flash = $this->error = null;

        $reason = $this->refundReasonCode();
        if ($reason === null) {
            return;
        }

        $invoice = $this->refundTarget();
        if (! $invoice) {
            return;
        }

        $this->runRefund($invoice, fn () => $service->refundFully($invoice, $reason, $this->refundText));
    }

    private function runRefund(mixed $invoice, callable $refund): void
    {
        try {
            $refund();
            $this->flash = __('billing::admin.billables.invoices.flash.refund_processed');
            $this->showRefundModal = false;
            $this->reset(['refundInvoiceId', 'refundAmount', 'refundText', 'refundLine']);
        } catch (RefundExceedsLineAmountException $e) {
            // Somebody refunded the line between the check above and the refund's lock.
            // Said about the LINE: the invoice-level branch below would name the invoice's
            // remainder, which is larger than what this line can still give back.
            $this->error = $e->remainingNet > 0
                ? __('billing::admin.billables.invoices.flash.exceeds_line', [
                    'amount' => BillingMoney::format($e->remainingNet, $invoice->currency),
                ])
                : __('billing::admin.billables.invoices.flash.line_already_refunded');
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
        @if (! $invoices || $invoices->isEmpty())
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
                        {{-- One credit-note lookup for the whole page: effectiveRefundedNet()
                             is called per row and each call used to load every credit note of
                             this billable again. --}}
                        @php
                            \GraystackIT\MollieBilling\Models\BillingInvoice::shareRefundChildren($invoices);
                        @endphp
                        @foreach ($invoices as $inv)
                            @php
                                $isPaid = (optional($inv->status)->value ?? $inv->status) === 'paid';
                                $isRefundKind = (optional($inv->invoice_kind)->value ?? $inv->invoice_kind) === 'refund';
                                // What the refund service actually accepts, not just "paid
                                // and not itself a refund". A saldo-zero plan-switch
                                // invoice and a locally booked one (a fully coupon-covered
                                // order, a zero-amount audit document) are both Paid, and
                                // both are rejected — for having nothing refundable, or no
                                // Mollie payment to refund against. The button was offered
                                // for them anyway and could only ever produce an error.
                                $refundable = $isPaid
                                    && ! $isRefundKind
                                    && $inv->refundableBaseNet() > 0
                                    && $inv->refundablePaymentId() !== null;
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
                                        <x-mollie-billing::admin.money :cents="$inv->amount_net" :currency="$inv->currency" />
                                        @if ($partiallyRefunded)
                                            <div class="text-xs text-amber-600 dark:text-amber-400">
                                                −<x-mollie-billing::admin.money :cents="$effectiveRefundedNet" :currency="$inv->currency" /> {{ __('billing::admin.billables.invoices.refunded_suffix') }}
                                            </div>
                                        @elseif ($fullyRefunded)
                                            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('billing::admin.billables.invoices.fully_refunded') }}</div>
                                        @endif
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell align="end">
                                         <div><x-mollie-billing::admin.money :cents="$inv->amount_gross" :currency="$inv->currency" /></div>
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

                @if ($invoices && $invoices->hasPages())
                    <div class="pt-2">{{ $invoices->links() }}</div>
                @endif
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

                @php
                    $refundableLines = app(InvoiceService::class)->refundableLines($refundInvoice);
                    $chosenLine = null;
                    foreach ($refundableLines as $offered) {
                        if ((string) $offered['index'] === (string) $refundLine) {
                            $chosenLine = $offered;
                        }
                    }
                @endphp

                <form wire:submit="refund" class="space-y-4">
                    @if ($refundableLines !== [])
                        {{-- A refund by line: the credit note reverses that line, at its
                             rate, and the amount is capped at what is left of it. --}}
                        <flux:select wire:model.live="refundLine" :label="__('billing::admin.billables.invoices.modal.line_label')">
                            <flux:select.option value="">{{ __('billing::admin.billables.invoices.modal.line_whole') }}</flux:select.option>
                            @foreach ($refundableLines as $offered)
                                <flux:select.option value="{{ $offered['index'] }}">{{ __('billing::admin.billables.invoices.modal.line_option', [
                                    'label' => $offered['label'] ?? __('billing::admin.billables.invoices.modal.line_unnamed', ['number' => $offered['index'] + 1]),
                                    'amount' => $moneyFormat($offered['remaining_net']),
                                ]) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3 md:items-start">
                        {{-- Free text, not type="number": the hint shows a locale-formatted
                             example ("10,50" under de) and a number input's sanitizer would
                             hand us an empty string for it. --}}
                        <flux:input
                            type="text"
                            inputmode="decimal"
                            autocomplete="off"
                            wire:model="refundAmount"
                            :label="__('billing::admin.billables.invoices.modal.amount_label')"
                            :placeholder="BillingMoney::number(10.5)"
                            :suffix="$currencyCode"
                            required
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
                        @if ($chosenLine !== null)
                            {{ __('billing::admin.billables.invoices.modal.line_hint', ['amount' => $moneyFormat($chosenLine['remaining_net'])]) }}
                        @endif
                    </flux:text>
                    <div class="flex flex-wrap justify-end gap-2 pt-2">
                        <flux:modal.close>
                            <flux:button type="button" size="sm" variant="ghost">{{ __('billing::admin.common.cancel') }}</flux:button>
                        </flux:modal.close>
                        {{-- Refunding everything is its own confirmed action, so an empty
                             amount field can never be mistaken for that intent. --}}
                        <flux:button
                            type="button"
                            size="sm"
                            variant="ghost"
                            icon="banknotes"
                            wire:click="refundFull"
                            wire:confirm="{{ __('billing::admin.billables.invoices.modal.refund_full_confirm', ['amount' => $moneyFormat($remainingNet)]) }}"
                            wire:loading.attr="disabled"
                        >{{ __('billing::admin.billables.invoices.modal.refund_full', ['amount' => $moneyFormat($remainingNet)]) }}</flux:button>
                        <flux:button type="submit" size="sm" variant="danger" icon="arrow-uturn-left" wire:loading.attr="disabled">{{ __('billing::admin.billables.invoices.modal.submit') }}</flux:button>
                    </div>
                </form>
            </div>
        @endif
    </flux:modal>
</div>
