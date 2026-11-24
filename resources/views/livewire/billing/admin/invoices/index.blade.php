<?php

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceArchiveService;
use GraystackIT\MollieBilling\Support\AdminInvoiceFilter;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Support\BillingTime;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every invoice and credit note the package has issued, across all billables.
 *
 * The filter lives in the URL, so a filtered list can be bookmarked or handed to a
 * colleague, and the ZIP export is a plain link carrying the same filter — what the
 * archive holds is exactly what the list shows ({@see AdminInvoiceFilter}).
 */
new class extends Component {
    use WithPagination;

    /** First day, Y-m-d, UTC, inclusive. */
    #[Url(except: '')]
    public string $from = '';

    /** Last day, Y-m-d, UTC, inclusive. */
    #[Url(except: '')]
    public string $to = '';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $kind = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: 'created_at')]
    public string $sortBy = 'created_at';

    #[Url(except: 'desc')]
    public string $sortDirection = 'desc';

    /** Locked: which invoice the detail modal shows is decided by openDetail(), not by the browser. */
    #[Locked]
    public ?int $detailInvoiceId = null;

    public bool $showDetail = false;

    /**
     * Sums of the current filter, per currency. Kept between renders: sorting, paging and
     * opening a detail do not change them, and on a large table they are the expensive
     * part of a render. Recomputed when the filter changes, or after SUMMARY_TTL seconds so
     * a list left open catches up with new invoices. The count shown and the export button
     * follow the paginator's total, which is never older than the render.
     *
     * @var list<array{currency: string, count: int, net: int, gross: int}>
     */
    #[Locked]
    public array $summary = [];

    #[Locked]
    public ?string $summaryKey = null;

    #[Locked]
    public int $summaryAt = 0;

    private const SUMMARY_TTL = 60;

    /** Whether summary() recomputed in this render — only then is its count current. */
    private bool $summaryFresh = false;

    private const ALLOWED_SORTS = ['created_at', 'serial_number', 'amount_net', 'amount_gross'];

    private const FILTERS = ['from', 'to', 'search', 'kind', 'status'];

    public function updated(string $property): void
    {
        if (in_array($property, self::FILTERS, true)) {
            $this->resetPage();
        }
    }

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

    /** A quick filter: this or last month, this or last year. Clicking the active one again clears the range. */
    public function applyPreset(string $preset): void
    {
        $range = AdminInvoiceFilter::presetRange($preset);
        if ($range === null) {
            return;
        }

        if ($this->filter()->preset() === $preset) {
            [$this->from, $this->to] = ['', ''];
        } else {
            [$this->from, $this->to] = $range;
        }

        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(self::FILTERS);
        $this->resetPage();
    }

    public function openDetail(int $id): void
    {
        if (! BillingInvoice::query()->whereKey($id)->exists()) {
            return;
        }

        $this->detailInvoiceId = $id;
        $this->showDetail = true;
    }

    private function filter(): AdminInvoiceFilter
    {
        return new AdminInvoiceFilter($this->from, $this->to, $this->search, $this->kind, $this->status);
    }

    /** @return list<array{currency: string, count: int, net: int, gross: int}> */
    private function summary(AdminInvoiceFilter $filter): array
    {
        $key = md5((string) json_encode($filter->toArray()));
        $now = BillingTime::nowUtc()->getTimestamp();

        if ($this->summaryKey !== $key || $now - $this->summaryAt > self::SUMMARY_TTL) {
            // One row per currency: amounts in different currencies cannot be added up.
            // Credit notes are stored negative, so the sums are what was really billed.
            $this->summary = $filter->query()
                ->selectRaw('currency, count(*) as invoice_count, sum(amount_net) as net, sum(amount_gross) as gross')
                ->groupBy('currency')
                ->orderBy('currency')
                ->get()
                ->map(fn ($row): array => [
                    'currency' => (string) $row->currency,
                    'count' => (int) $row->invoice_count,
                    'net' => (int) $row->net,
                    'gross' => (int) $row->gross,
                ])
                ->all();
            $this->summaryKey = $key;
            $this->summaryAt = $now;
            $this->summaryFresh = true;
        }

        return $this->summary;
    }

    public function with(): array
    {
        $filter = $this->filter();
        $sortBy = in_array($this->sortBy, self::ALLOWED_SORTS, true) ? $this->sortBy : 'created_at';
        $direction = $this->sortDirection === 'asc' ? 'asc' : 'desc';

        $totals = $this->summary($filter);

        // Ordered by (created_at, id) by default, which the index of that name serves.
        $rows = $filter->query()
            ->with('billable')
            ->orderBy($sortBy, $direction)
            ->orderBy('id', $direction);

        // The summary's count is handed to the paginator only in the render that computed
        // it, so a render never counts twice. A kept one may be up to SUMMARY_TTL old, and
        // as a total it would cut rows off — a cached 0 even skips the row query and shows
        // an empty list while invoices exist — so then the paginator counts for itself.
        $invoices = $this->summaryFresh
            ? $rows->paginate(25, ['*'], 'page', null, array_sum(array_column($totals, 'count')))
            : $rows->paginate(25);

        $count = $invoices->total();

        return [
            'invoices' => $invoices,
            'totals' => $totals,
            'count' => $count,
            'activePreset' => $filter->preset(),
            'exportUrl' => route(BillingRoute::admin('invoices.export'), $filter->toArray()),
            'exportAvailable' => InvoiceArchiveService::isAvailable(),
            'exportMax' => InvoiceArchiveService::maxInvoices(),
            'hasFilter' => $filter->toArray() !== [],
            'detail' => $this->showDetail && $this->detailInvoiceId
                ? BillingInvoice::with('billable')->find($this->detailInvoiceId)
                : null,
        ];
    }
};

?>

<div class="space-y-6">
    @php
        $billableOf = function (BillingInvoice $invoice): array {
            $billable = $invoice->billable;
            $email = $billable?->getBillingEmail();
    
            return [
                'model' => $billable,
                'label' => $billable ? ($billable->getBillingName() ?: $email ?: null) : null,
                'email' => $email,
                'url' => $billable ? route(BillingRoute::admin('billables.show'), $billable) : null,
            ];
        };
    @endphp

    <x-mollie-billing::admin.page-header
        :title="__('billing::admin.invoices.title')"
        :subtitle="__('billing::admin.invoices.subtitle')"
    >
        <x-slot:actions>
            @if (! $exportAvailable)
                <flux:tooltip :content="__('billing::admin.invoices.export.unavailable')">
                    <div><flux:button icon="archive-box-arrow-down" disabled>{{ __('billing::admin.invoices.export.button') }}</flux:button></div>
                </flux:tooltip>
            @elseif ($count === 0)
                <flux:button icon="archive-box-arrow-down" disabled>{{ __('billing::admin.invoices.export.button') }}</flux:button>
            @elseif ($count > $exportMax)
                <flux:tooltip :content="__('billing::admin.invoices.export.too_many', ['max' => $exportMax])">
                    <div><flux:button icon="archive-box-arrow-down" disabled dusk="invoice-export">{{ __('billing::admin.invoices.export.button') }}</flux:button></div>
                </flux:tooltip>
            @else
                <flux:button
                    icon="archive-box-arrow-down"
                    variant="primary"
                    :href="$exportUrl"
                    dusk="invoice-export"
                >{{ trans_choice('billing::admin.invoices.export.button_count', $count, ['count' => $count]) }}</flux:button>
            @endif
        </x-slot:actions>
    </x-mollie-billing::admin.page-header>

    <flux:card class="space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('billing::admin.invoices.filter.quick') }}</span>
            @foreach (AdminInvoiceFilter::PRESETS as $preset)
                <flux:button
                    size="sm"
                    :variant="$activePreset === $preset ? 'primary' : 'outline'"
                    wire:click="applyPreset('{{ $preset }}')"
                    dusk="invoice-preset-{{ $preset }}"
                >{{ __('billing::admin.invoices.filter.preset.'.$preset) }}</flux:button>
            @endforeach
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <flux:input type="date" wire:model.live="from" :label="__('billing::admin.invoices.filter.from')" dusk="invoice-from" />
            <flux:input type="date" wire:model.live="to" :label="__('billing::admin.invoices.filter.to')" dusk="invoice-to" />
            <flux:select wire:model.live="kind" :label="__('billing::admin.invoices.filter.kind')">
                <flux:select.option value="">{{ __('billing::admin.invoices.filter.all') }}</flux:select.option>
                @foreach (InvoiceKind::cases() as $case)
                    <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="status" :label="__('billing::admin.invoices.filter.status')">
                <flux:select.option value="">{{ __('billing::admin.invoices.filter.all') }}</flux:select.option>
                @foreach (InvoiceStatus::cases() as $case)
                    <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input
                type="search"
                wire:model.live.debounce.300ms="search"
                :label="__('billing::admin.invoices.filter.search')"
                :placeholder="__('billing::admin.invoices.filter.search_placeholder')"
                icon="magnifying-glass"
            />
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 pt-3 text-sm dark:border-white/10">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-zinc-600 dark:text-zinc-300" dusk="invoice-totals">
                <span class="font-medium">{{ trans_choice('billing::admin.invoices.summary.count', $count, ['count' => $count]) }}</span>
                @foreach ($totals as $total)
                    <span class="tabular-nums">{{ __('billing::admin.invoices.summary.amounts', [
                        'currency' => $total['currency'],
                        'net' => BillingMoney::format($total['net'], $total['currency']),
                        'gross' => BillingMoney::format($total['gross'], $total['currency']),
                    ]) }}</span>
                @endforeach
            </div>
            @if ($hasFilter)
                <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="clearFilters">{{ __('billing::admin.invoices.filter.clear') }}</flux:button>
            @endif
        </div>
    </flux:card>

    @if ($invoices->isEmpty())
        <flux:card>
            <x-mollie-billing::admin.empty
                icon="document-text"
                :title="__('billing::admin.invoices.empty_title')"
                :description="$hasFilter ? __('billing::admin.invoices.empty_filtered') : __('billing::admin.invoices.empty_description')"
            />
        </flux:card>
    @else
        <flux:card class="p-0! sm:px-6! sm:py-2!">
            <flux:table :paginate="$invoices">
                <flux:table.columns>
                    <flux:table.column sortable :sorted="$sortBy === 'created_at'" :direction="$sortDirection" wire:click="sort('created_at')">{{ __('billing::admin.invoices.column.date') }}</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'serial_number'" :direction="$sortDirection" wire:click="sort('serial_number')">{{ __('billing::admin.invoices.column.serial') }}</flux:table.column>
                    <flux:table.column>{{ __('billing::admin.invoices.column.billable') }}</flux:table.column>
                    <flux:table.column>{{ __('billing::admin.invoices.column.kind') }}</flux:table.column>
                    <flux:table.column>{{ __('billing::admin.invoices.column.status') }}</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'amount_net'" :direction="$sortDirection" wire:click="sort('amount_net')" align="end">{{ __('billing::admin.invoices.column.net') }}</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'amount_gross'" :direction="$sortDirection" wire:click="sort('amount_gross')" align="end">{{ __('billing::admin.invoices.column.gross') }}</flux:table.column>
                    <flux:table.column class="w-12"></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($invoices as $inv)
                        @php $b = $billableOf($inv); @endphp
                        <flux:table.row :key="$inv->id">
                            <flux:table.cell><x-mollie-billing::admin.time :at="$inv->created_at" /></flux:table.cell>
                            <flux:table.cell class="font-mono text-sm">
                                <button type="button" class="hover:underline" wire:click="openDetail({{ $inv->id }})">{{ $inv->serial_number ?? '#'.$inv->id }}</button>
                            </flux:table.cell>
                            <flux:table.cell variant="strong">
                                @if ($b['label'])
                                    <a href="{{ $b['url'] }}" class="hover:underline">{{ $b['label'] }}</a>
                                    <x-mollie-billing::admin.billable-address
                                        :billable="$b['model']"
                                        :fallback="$b['email'] && $b['email'] !== $b['label'] ? $b['email'] : null"
                                        class="block text-xs font-normal text-zinc-500 dark:text-zinc-400"
                                    />
                                @else
                                    <span class="font-mono text-sm text-zinc-500">{{ class_basename($inv->billable_type) }}#{{ $inv->billable_id }}</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell><x-mollie-billing::admin.enum-badge :value="$inv->invoice_kind" /></flux:table.cell>
                            <flux:table.cell><x-mollie-billing::admin.enum-badge :value="$inv->status" /></flux:table.cell>
                            <flux:table.cell align="end">
                                <x-mollie-billing::admin.money :cents="$inv->amount_net" :currency="$inv->currency" />
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                <x-mollie-billing::admin.money :cents="$inv->amount_gross" :currency="$inv->currency" />
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('billing::admin.invoices.actions.menu')" dusk="invoice-actions-{{ $inv->id }}" />
                                    <flux:menu>
                                        <flux:menu.item icon="eye" wire:click="openDetail({{ $inv->id }})">{{ __('billing::admin.invoices.actions.details') }}</flux:menu.item>
                                        <flux:menu.item icon="document-text" :href="route(BillingRoute::admin('invoice.view'), ['invoice' => $inv])" target="_blank">{{ __('billing::admin.invoices.actions.view_pdf') }}</flux:menu.item>
                                        <flux:menu.item icon="arrow-down-tray" :href="route(BillingRoute::admin('invoice.download'), ['invoice' => $inv])">{{ __('billing::admin.invoices.actions.download_pdf') }}</flux:menu.item>
                                        @if ($b['url'])
                                            <flux:menu.separator />
                                            <flux:menu.item icon="user" :href="$b['url']" dusk="invoice-billable-{{ $inv->id }}">{{ __('billing::admin.invoices.actions.open_billable') }}</flux:menu.item>
                                        @endif
                                    </flux:menu>
                                </flux:dropdown>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @endif

    <flux:modal wire:model.self="showDetail" name="invoice-detail" class="max-w-2xl">
        @if ($detail)
            @php
                $b = $billableOf($detail);
                $rows = [
                    __('billing::admin.invoices.detail.date') => BillingTime::formatUtc($detail->created_at),
                    // A billing day, not a moment: shown as the day it was computed on,
                    // as everywhere else (BillingTime::billingDay()).
                    __('billing::admin.invoices.detail.period') => $detail->period_start
                        ? BillingTime::billingDay($detail->period_start)->format('Y-m-d').' – '.(BillingTime::formatUtc($detail->period_end, 'Y-m-d') ?? '…')
                        : null,
                    __('billing::admin.invoices.detail.country') => $detail->country,
                    __('billing::admin.invoices.detail.payment') => $detail->mollie_payment_id,
                ];
            @endphp

            <div class="space-y-6">
                <div class="pe-10">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading size="lg" class="font-mono">{{ $detail->serial_number ?? '#'.$detail->id }}</flux:heading>
                        <x-mollie-billing::admin.enum-badge :value="$detail->invoice_kind" />
                        <x-mollie-billing::admin.enum-badge :value="$detail->status" />
                    </div>
                    @if ($b['label'])
                        <flux:text class="mt-1">
                            <a href="{{ $b['url'] }}" class="font-medium hover:underline">{{ $b['label'] }}</a>
                            @if ($b['email'] && $b['email'] !== $b['label'])
                                <span class="text-zinc-500 dark:text-zinc-400">· {{ $b['email'] }}</span>
                            @endif
                        </flux:text>
                    @endif
                </div>

                <div class="grid grid-cols-3 gap-3 rounded-xl border border-zinc-200 p-4 dark:border-white/10">
                    <div>
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('billing::admin.invoices.column.net') }}</div>
                        <div class="tabular-nums text-zinc-900 dark:text-white"><x-mollie-billing::admin.money :cents="$detail->amount_net" :currency="$detail->currency" /></div>
                    </div>
                    <div>
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('billing::admin.invoices.detail.vat') }}</div>
                        <div class="tabular-nums text-zinc-900 dark:text-white"><x-mollie-billing::admin.money :cents="$detail->amount_vat" :currency="$detail->currency" /></div>
                    </div>
                    <div>
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('billing::admin.invoices.column.gross') }}</div>
                        <div class="tabular-nums text-zinc-900 dark:text-white"><span class="font-semibold"><x-mollie-billing::admin.money :cents="$detail->amount_gross" :currency="$detail->currency" /></span></div>
                    </div>
                </div>

                <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                    @foreach ($rows as $label => $value)
                        @if (filled($value))
                            <div>
                                <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                                <dd class="font-mono text-zinc-900 dark:text-white">{{ $value }}</dd>
                            </div>
                        @endif
                    @endforeach
                </dl>

                @if (! empty($detail->line_items))
                    <div>
                        <div class="mb-2 text-[10px] font-semibold uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">{{ __('billing::admin.invoices.detail.line_items') }}</div>
                        <ul class="divide-y divide-zinc-200/70 rounded-xl border border-zinc-200/70 dark:divide-white/10 dark:border-white/10">
                            @foreach ($detail->line_items as $li)
                                <li class="flex items-center justify-between gap-6 px-4 py-2.5">
                                    <div class="min-w-0 flex-1 truncate text-sm text-zinc-900 dark:text-white">
                                        @if ((int) ($li['quantity'] ?? 1) > 1)
                                            <span class="tabular-nums text-zinc-500">{{ (int) $li['quantity'] }} ×</span>
                                        @endif
                                        {{ $li['label'] ?? $li['description'] ?? $li['kind'] ?? '—' }}
                                    </div>
                                    <div class="shrink-0 text-sm tabular-nums">
                                        <x-mollie-billing::admin.money :cents="(int) ($li['amount_net'] ?? 0)" :currency="$detail->currency" />
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="flex flex-wrap justify-end gap-2">
                    <flux:button size="sm" variant="ghost" icon="document-text" :href="route(BillingRoute::admin('invoice.view'), ['invoice' => $detail])" target="_blank">{{ __('billing::admin.invoices.actions.view_pdf') }}</flux:button>
                    <flux:button size="sm" variant="ghost" icon="arrow-down-tray" :href="route(BillingRoute::admin('invoice.download'), ['invoice' => $detail])">{{ __('billing::admin.invoices.actions.download_pdf') }}</flux:button>
                    @if ($b['url'])
                        <flux:button size="sm" variant="primary" icon-trailing="arrow-right" :href="$b['url']">{{ __('billing::admin.invoices.actions.open_billable') }}</flux:button>
                    @endif
                </div>
            </div>
        @endif
    </flux:modal>
</div>
