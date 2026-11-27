<?php

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Support\BillingTime;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $sortBy = 'created_at';
    public string $sortDirection = 'desc';

    /** Locked: which credit note the detail modal shows is decided by openDetail(), not by the browser. */
    #[Locked]
    public ?int $detailInvoiceId = null;
    public bool $showDetail = false;
    public ?string $error = null;

    private const ALLOWED_SORTS = ['created_at', 'amount_gross', 'serial_number'];

    public function updatingSearch(): void { $this->resetPage(); }

    public function sort(string $column): void
    {
        if (! in_array($column, self::ALLOWED_SORTS, true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
    }

    /**
     * Credit notes only. The modal presents its subject as a refund of a parent
     * invoice, so a regular invoice opened here would read as money returned that
     * never left. There is no separate credit-note kind — a credit note *is* an
     * InvoiceKind::Refund row.
     */
    public function openDetail(int $id): void
    {
        $this->error = null;

        $isCreditNote = BillingInvoice::query()
            ->whereKey($id)
            ->where('invoice_kind', InvoiceKind::Refund)
            ->exists();

        if (! $isCreditNote) {
            $this->error = __('billing::admin.refunds.not_a_credit_note');
            return;
        }

        $this->detailInvoiceId = $id;
        $this->showDetail = true;
    }

    public function with(): array
    {
        $q = BillingInvoice::query()
            ->where('invoice_kind', InvoiceKind::Refund)
            ->with('billable');

        // The invoice list's search: case-insensitive on every driver, exact on the id.
        \GraystackIT\MollieBilling\Support\AdminInvoiceFilter::applySearch($q, $this->search);

        $sortBy = in_array($this->sortBy, self::ALLOWED_SORTS, true) ? $this->sortBy : 'created_at';

        $detail = $this->showDetail && $this->detailInvoiceId
            ? BillingInvoice::with('billable')
                ->where('invoice_kind', InvoiceKind::Refund)
                ->whereKey($this->detailInvoiceId)
                ->first()
            : null;

        $parent = null;
        if ($detail && is_array($detail->line_items) && isset($detail->line_items[0])) {
            $parentId = $detail->line_items[0]['parent_invoice_id'] ?? null;
            if ($parentId) {
                $parent = BillingInvoice::with('billable')->find($parentId);
            }
        }

        return [
            'notes' => $q->orderBy($sortBy, $this->sortDirection)->paginate(20),
            'detail' => $detail,
            'parent' => $parent,
        ];
    }
};

?>

<div class="space-y-6">
    <x-mollie-billing::admin.page-header
        :title="__('billing::admin.refunds.title')"
        :subtitle="__('billing::admin.refunds.subtitle')"
    />

    <x-mollie-billing::admin.flash :error="$error" />

    <flux:input
        type="search"
        wire:model.live.debounce.300ms="search"
        :placeholder="__('billing::admin.refunds.search')"
        icon="magnifying-glass"
    />

    @if ($notes->isEmpty())
        <flux:card>
            <x-mollie-billing::admin.empty
                icon="arrow-uturn-left"
                :title="__('billing::admin.refunds.empty_title')"
                :description="__('billing::admin.refunds.empty_description')"
            />
        </flux:card>
    @else
        <flux:card class="p-0! sm:px-6! sm:py-2!">
            <flux:table :paginate="$notes">
                <flux:table.columns>
                    <flux:table.column sortable :sorted="$sortBy === 'created_at'" :direction="$sortDirection" wire:click="sort('created_at')">{{ __('billing::admin.refunds.column.date') }}</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'serial_number'" :direction="$sortDirection" wire:click="sort('serial_number')">{{ __('billing::admin.refunds.column.serial') }}</flux:table.column>
                    <flux:table.column>{{ __('billing::admin.refunds.column.billable') }}</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'amount_gross'" :direction="$sortDirection" wire:click="sort('amount_gross')" align="end">{{ __('billing::admin.refunds.column.amount') }}</flux:table.column>
                    <flux:table.column class="w-16"></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($notes as $n)
                        @php
                            $billable = $n->billable;
                            $billableEmail = $billable?->getBillingEmail();
                            $billableLabel = $billable ? ($billable->getBillingName() ?: $billableEmail ?: null) : null;
                        @endphp
                        <flux:table.row :key="$n->id">
                            <flux:table.cell><x-mollie-billing::admin.time :at="$n->created_at" /></flux:table.cell>
                            <flux:table.cell class="font-mono text-sm">
                                {{ $n->serial_number ?? '—' }}
                            </flux:table.cell>
                            <flux:table.cell variant="strong">
                                @if ($billableLabel)
                                    @if ($billable)
                                        <a href="{{ route(BillingRoute::admin('billables.show'), $billable) }}" class="hover:underline">{{ $billableLabel }}</a>
                                    @else
                                        {{ $billableLabel }}
                                    @endif
                                    <x-mollie-billing::admin.billable-address
                                        :billable="$billable"
                                        :fallback="$billableEmail && $billableEmail !== $billableLabel ? $billableEmail : null"
                                        class="block text-xs font-normal text-zinc-500 dark:text-zinc-400"
                                    />
                                @else
                                    <span class="font-mono text-sm text-zinc-500">{{ class_basename($n->billable_type) }}#{{ $n->billable_id }}</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                <x-mollie-billing::admin.money :cents="$n->amount_gross" :currency="$n->currency" />
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:button
                                    size="xs"
                                    variant="ghost"
                                    icon="arrow-top-right-on-square"
                                    wire:click="openDetail({{ $n->id }})"
                                >{{ __('billing::admin.common.open') }}</flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @endif

    <flux:modal wire:model.self="showDetail" name="refund-detail" class="max-w-2xl">
    @if ($detail)
            @php
                $detailBillable = $detail->billable;
                $detailBillableEmail = $detailBillable?->getBillingEmail();
                $detailBillableLabel = $detailBillable ? ($detailBillable->getBillingName() ?: $detailBillableEmail) : '';
                $grossAbs = abs((int) $detail->amount_gross);
                $netAbs = abs((int) $detail->amount_net);
                $vatAbs = abs((int) $detail->amount_vat);
                // Not gated on hasPdf(): the download route renders a document that is not
                // on the disk yet — a credit note's deferred render — on the click.
                $refundDownloadUrl = route(BillingRoute::admin('invoice.download'), ['invoice' => $detail]);

                $parentDownloadUrl = $parent
                    ? route(BillingRoute::admin('invoice.download'), ['invoice' => $parent])
                    : null;
                $parentGross = $parent ? (int) $parent->amount_gross : null;

                $billableInitials = '';
                if ($detailBillable) {
                    $name = $detailBillableLabel ?: '';
                    $parts = preg_split('/\s+/', trim($name));
                    foreach (array_slice($parts, 0, 2) as $p) {
                        $billableInitials .= mb_strtoupper(mb_substr($p, 0, 1));
                    }
                }

                $hasInformativeLineItems = ! empty($detail->line_items)
                    && (count($detail->line_items) > 1
                        || (isset($detail->line_items[0]['amount_gross']) && abs((int) $detail->line_items[0]['amount_gross']) !== $grossAbs));
            @endphp

            <div class="space-y-6">
                {{-- Customer strip: who got the money back. Whole strip is clickable;
                     the modal's own X-button handles close, so no chevron here. --}}
                @if ($detailBillable || $detail->billable_id)
                    <a
                        @if ($detailBillable) href="{{ route(BillingRoute::admin('billables.show'), $detailBillable) }}" @endif
                        class="group -mx-1 flex items-start gap-3 rounded-lg px-1 py-1 pe-12 transition hover:bg-zinc-50 dark:hover:bg-white/5 @if (! $detailBillable) pointer-events-none @endif"
                    >
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-zinc-100 to-zinc-200 text-sm font-semibold tracking-wide text-zinc-700 ring-1 ring-zinc-200 dark:from-zinc-700 dark:to-zinc-800 dark:text-zinc-200 dark:ring-white/10">
                            {{ $billableInitials ?: '?' }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium text-zinc-900 dark:text-white">
                                {{ $detailBillableLabel ?: class_basename($detail->billable_type).'#'.$detail->billable_id }}
                            </div>
                            <x-mollie-billing::admin.billable-address
                                :billable="$detailBillable"
                                :fallback="$detailBillableEmail"
                                class="mt-0.5 block truncate text-xs text-zinc-500 dark:text-zinc-400"
                            />
                        </div>
                    </a>
                @endif

                {{-- Refund-card: the headline document --}}
                <div class="relative overflow-hidden rounded-2xl border border-amber-200/70 bg-linear-to-br from-amber-50/80 via-amber-50/40 to-white p-5 dark:border-amber-500/20 dark:from-amber-950/40 dark:via-amber-950/20 dark:to-zinc-900">
                    {{-- Watermark glyph --}}
                    {{-- <flux:icon.arrow-uturn-left variant="solid" class="pointer-events-none absolute -right-3 -top-3 size-24 text-amber-200/40 dark:text-amber-500/10" /> --}}

                    <div class="relative flex items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <div class="mb-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-[10px] font-semibold uppercase tracking-[0.2em] text-amber-700/80 dark:text-amber-400/80">
                                <span>{{ __('billing::admin.refunds.detail.refund') }}</span>
                                @if ($detail->serial_number)
                                    <span class="text-amber-300 dark:text-amber-600/60">·</span>
                                    <span class="font-mono normal-case tracking-normal">{{ $detail->serial_number }}</span>
                                @endif
                            </div>
                            <div class="flex items-baseline gap-2">
                                <span class="text-4xl font-semibold tabular-nums tracking-tight text-amber-900 dark:text-amber-200">
                                    −<x-mollie-billing::admin.money :cents="$grossAbs" :currency="$detail->currency" />
                                </span>
                            </div>
                            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-amber-800/70 dark:text-amber-300/70">
                                <x-mollie-billing::admin.time :at="$detail->created_at" format="Y-m-d · H:i" />
                                @if ($vatAbs > 0)
                                    <span class="text-amber-300 dark:text-amber-600/60">·</span>
                                    {{-- Plain strings via the shared formatter — the
                                         <x-…money> component can't be nested inside a
                                         translation placeholder. --}}
                                    <span class="tabular-nums">{{ __('billing::admin.refunds.detail.net_vat', [
                                        'net' => BillingMoney::format($netAbs, $detail->currency),
                                        'vat' => BillingMoney::format($vatAbs, $detail->currency),
                                    ]) }}</span>
                                @endif
                            </div>
                        </div>

                        @if ($detail->refund_reason_code)
                            <div class="shrink-0">
                                <x-mollie-billing::admin.enum-badge :value="$detail->refund_reason_code" />
                            </div>
                        @endif
                    </div>

                    {{-- What the operator wrote when issuing it. The badge alone says "Other"
                         for exactly the refunds that needed explaining. --}}
                    @if (filled($detail->refund_reason_text))
                        <p dusk="refund-reason-text" class="relative mt-3 whitespace-pre-line text-sm text-amber-900/90 dark:text-amber-200/90">{{ $detail->refund_reason_text }}</p>
                    @endif

                    @if ($refundDownloadUrl)
                        <div class="relative mt-4 flex justify-end border-t border-amber-200/60 pt-3 dark:border-amber-500/15">
                            <flux:button
                                size="xs"
                                variant="ghost"
                                icon="arrow-down-tray"
                                :href="$refundDownloadUrl"
                                target="_blank"
                                class="text-amber-800! hover:bg-amber-100/60! dark:text-amber-300! dark:hover:bg-amber-500/10!"
                            >{{ __('billing::admin.refunds.detail.refund_pdf') }}</flux:button>
                        </div>
                    @endif
                </div>

                {{-- Connector + original-invoice card --}}
                @if ($parent || $detail->mollie_payment_id)
                    <div class="relative">
                        {{-- Connector line above --}}
                        <div class="absolute left-1/2 -top-3 flex -translate-x-1/2 -translate-y-full flex-col items-center gap-1 -mt-3">
                            <div class="h-3 w-px bg-zinc-300 dark:bg-white/15"></div>
                            <flux:icon.link variant="micro" class="size-3.5 text-zinc-400 dark:text-zinc-500" />
                            <div class="h-3 w-px bg-zinc-300 dark:bg-white/15"></div>
                        </div>

                        <div class="rounded-2xl border border-zinc-200/80 bg-zinc-50/60 p-5 dark:border-white/10 dark:bg-white/[0.03]">
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0 flex-1">
                                    <div class="mb-2 text-[10px] font-semibold uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">
                                        {{ __('billing::admin.refunds.detail.original_invoice') }}
                                    </div>
                                    @if ($parent)
                                        <div class="flex items-baseline gap-3">
                                            <span class="font-mono text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">#{{ $parent->id }}</span>
                                            <span class="text-base tabular-nums text-zinc-600 dark:text-zinc-400">
                                                <x-mollie-billing::admin.money :cents="$parentGross" :currency="$parent->currency" />
                                            </span>
                                        </div>
                                        <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                                            @if ($parent->serial_number)
                                                <span class="font-mono">{{ $parent->serial_number }}</span>
                                            @endif
                                            @if ($parent->serial_number && $parent->invoice_kind)
                                                <span class="text-zinc-300 dark:text-zinc-600">·</span>
                                            @endif
                                            @if ($parent->invoice_kind)
                                                <x-mollie-billing::admin.enum-badge :value="$parent->invoice_kind" size="xs" />
                                            @endif
                                            @if ($parent->created_at)
                                                <span class="text-zinc-300 dark:text-zinc-600">·</span>
                                                <x-mollie-billing::admin.time :at="$parent->created_at" format="Y-m-d" />
                                            @endif
                                        </div>
                                    @else
                                        <div class="text-sm text-zinc-500 dark:text-zinc-400">
                                            {{ __('billing::admin.refunds.detail.original_unavailable') }}
                                        </div>
                                    @endif

                                    @if ($detail->mollie_payment_id)
                                        <div class="mt-2 inline-flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                                            <flux:icon.credit-card variant="micro" class="size-3.5" />
                                            <span class="font-mono">{{ $detail->mollie_payment_id }}</span>
                                        </div>
                                    @endif
                                </div>
                            </div>

                            @if ($parentDownloadUrl)
                                <div class="mt-4 flex justify-end border-t border-zinc-200/70 pt-3 dark:border-white/10">
                                    <flux:button size="xs" variant="ghost" icon="arrow-down-tray" :href="$parentDownloadUrl" target="_blank">{{ __('billing::admin.refunds.detail.original_pdf') }}</flux:button>
                                </div>
                            @endif
                        </div>
                    </div>
                @endif

                {{-- Line items: only when they add information beyond the hero summary --}}
                @if ($hasInformativeLineItems)
                    <div>
                        <div class="mb-2.5 flex items-end justify-between">
                            <div class="text-[10px] font-semibold uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">{{ __('billing::admin.refunds.detail.line_items') }}</div>
                            <div class="text-[10px] font-semibold uppercase tracking-[0.2em] text-zinc-400 dark:text-zinc-500">{{ count($detail->line_items) }}</div>
                        </div>
                        <ul class="divide-y divide-zinc-200/70 rounded-xl border border-zinc-200/70 dark:divide-white/10 dark:border-white/10">
                            @foreach ($detail->line_items as $li)
                                @php
                                    $liGrossAbs = abs((int) ($li['amount_gross'] ?? 0));
                                    $qty = (int) ($li['quantity'] ?? 1);
                                @endphp
                                <li class="flex items-center justify-between gap-6 px-4 py-3">
                                    <div class="min-w-0 flex-1">
                                        <div class="truncate text-sm font-medium text-zinc-900 dark:text-white">
                                            {{ $li['label'] ?? $li['description'] ?? $li['kind'] ?? '—' }}
                                        </div>
                                        @if (isset($li['code']) || $qty > 1)
                                            <div class="mt-0.5 flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                                                @if (isset($li['code']))
                                                    <span class="font-mono">{{ $li['code'] }}</span>
                                                @endif
                                                @if (isset($li['code']) && $qty > 1)
                                                    <span class="text-zinc-300 dark:text-zinc-600">·</span>
                                                @endif
                                                @if ($qty > 1)
                                                    <span class="tabular-nums">{{ __('billing::admin.refunds.detail.qty', ['count' => $qty]) }}</span>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                    <div class="shrink-0 text-right text-sm font-medium tabular-nums text-amber-700 dark:text-amber-400">
                                        −<x-mollie-billing::admin.money :cents="$liGrossAbs" :currency="$detail->currency" />
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- Footer action: just the primary next step. PDF links live next to their card. --}}
                @if ($detailBillable)
                    <div class="flex justify-end">
                        <flux:button size="sm" variant="primary" icon-trailing="arrow-right" :href="route(BillingRoute::admin('billables.show'), $detailBillable)">{{ __('billing::admin.refunds.detail.open_billable') }}</flux:button>
                    </div>
                @endif
            </div>
    @endif
    </flux:modal>
</div>
