<?php

use Bavix\Wallet\Models\Transaction;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingTime;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $usageType = '';
    public ?string $dateFrom = null;
    public ?string $dateTo = null;

    private function resolveBillable(): ?Billable
    {
        return MollieBilling::resolveBillable(request());
    }

    public function mount(): void
    {
        $billable = $this->resolveBillable();
        if (! $billable) {
            return;
        }

        $periodStart = $billable->getBillingPeriodStartsAt();
        $this->dateFrom = $periodStart?->format('Y-m-d') ?? now()->startOfMonth()->format('Y-m-d');
        $this->dateTo = now()->format('Y-m-d');
    }

    public function updatedUsageType(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $billable = $this->resolveBillable();

        if (! $billable) {
            return ['transactions' => null, 'usageTypes' => [], 'stats' => null, 'billable' => null, 'meters' => []];
        }

        $catalog = app(SubscriptionCatalogInterface::class);
        $planCode = $billable->getBillingSubscriptionPlanCode();
        $interval = $billable->getBillingSubscriptionInterval();

        // Collect available usage types from plan config + meter data.
        $availableTypes = [];
        $usageMeters = [];
        if ($planCode) {
            foreach ($catalog->includedUsages($planCode, $interval) as $type => $included) {
                $slug = (string) $type;
                $label = $catalog->usageTypeName($slug);
                $availableTypes[$slug] = $label;

                $wallet = $billable->getWallet($slug);
                $usageMeters[$slug] = [
                    'type' => $slug,
                    'label' => $label,
                    'included' => (int) $included,
                    'balance' => (int) ($wallet?->balanceInt ?? 0),
                    'purchased_balance' => $wallet !== null
                        ? WalletUsageService::getPurchasedBalance($wallet)
                        : 0,
                ];
            }
        }

        // Also include types from existing wallets (covers add-on or legacy types).
        foreach ($billable->wallets as $wallet) {
            $slug = $wallet->slug;
            if ($slug === 'default' || isset($availableTypes[$slug])) {
                continue;
            }
            $label = $catalog->usageTypeName($slug);
            $availableTypes[$slug] = $label;
            $usageMeters[$slug] = [
                'type' => $slug,
                'label' => $label,
                'included' => 0,
                'balance' => (int) ($wallet->balanceInt ?? 0),
                'purchased_balance' => WalletUsageService::getPurchasedBalance($wallet),
            ];
        }

        // Filter meters to the active usage type, if any.
        $visibleMeters = $this->usageType !== ''
            ? array_values(array_filter($usageMeters, fn ($m) => $m['type'] === $this->usageType))
            : array_values($usageMeters);

        // Build wallet IDs query scope.
        $walletIds = $billable->wallets
            ->when($this->usageType !== '', fn ($c) => $c->where('slug', $this->usageType))
            ->where('slug', '!=', 'default')
            ->pluck('id');

        $baseQuery = Transaction::query()
            ->whereIn('wallet_id', $walletIds)
            ->when($this->dateFrom, fn ($q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->whereDate('created_at', '<=', $this->dateTo));

        $query = (clone $baseQuery)->latest();

        // Stats for the filtered result set — use the unordered base query so
        // grouped aggregations work on Postgres (no leftover ORDER BY columns).
        $statsQuery = $baseQuery;
        $totalDebits = (clone $statsQuery)->where('type', Transaction::TYPE_WITHDRAW)->sum('amount');
        $totalCredits = (clone $statsQuery)->where('type', Transaction::TYPE_DEPOSIT)->sum('amount');
        $totalTransactions = (clone $statsQuery)->count();

        $debitsAbs = abs((int) $totalDebits);
        $creditsInt = (int) $totalCredits;
        $netUsage = max(0, $debitsAbs - $creditsInt);

        // Daily debit series for sparkline + peak/avg.
        // reorder() clears any inherited ORDER BY so Postgres' GROUP BY check passes.
        $dailyRows = (clone $statsQuery)
            ->where('type', Transaction::TYPE_WITHDRAW)
            ->reorder()
            ->selectRaw('DATE(created_at) as day, SUM(amount) as amt')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->mapWithKeys(fn ($r) => [(string) $r->day => abs((int) $r->amt)])
            ->all();

        $rangeStart = $this->dateFrom ? \Carbon\Carbon::parse($this->dateFrom)->startOfDay() : null;
        $rangeEnd = $this->dateTo ? \Carbon\Carbon::parse($this->dateTo)->endOfDay() : null;
        $series = [];
        if ($rangeStart && $rangeEnd && $rangeEnd->gte($rangeStart)) {
            $cursor = $rangeStart->copy();
            while ($cursor->lte($rangeEnd)) {
                $key = $cursor->format('Y-m-d');
                $series[] = ['day' => $key, 'value' => $dailyRows[$key] ?? 0];
                $cursor->addDay();
            }
        }

        $daySpan = max(1, count($series));
        $dailyAvg = $daySpan > 0 ? (int) round($debitsAbs / $daySpan) : 0;

        $peakDay = null;
        $peakValue = 0;
        foreach ($series as $point) {
            if ($point['value'] > $peakValue) {
                $peakValue = $point['value'];
                $peakDay = $point['day'];
            }
        }

        // Top usage type by consumption (debits) over filtered range.
        // Iterate per wallet to keep the query simple and avoid join ambiguity
        // with the cloned statsQuery (wallets table also has created_at/type).
        $perTypeAmounts = [];
        foreach ($billable->wallets as $wallet) {
            if ($wallet->slug === 'default') continue;
            if ($this->usageType !== '' && $wallet->slug !== $this->usageType) continue;

            $abs = abs((int) (clone $statsQuery)
                ->where('type', Transaction::TYPE_WITHDRAW)
                ->where('wallet_id', $wallet->id)
                ->sum('amount'));
            if ($abs > 0) {
                $perTypeAmounts[$wallet->slug] = $abs;
            }
        }
        arsort($perTypeAmounts);

        $topType = null;
        if (! empty($perTypeAmounts)) {
            $topSlug = (string) array_key_first($perTypeAmounts);
            $topAmount = $perTypeAmounts[$topSlug];
            $topType = [
                'slug' => $topSlug,
                'label' => $availableTypes[$topSlug] ?? ucfirst($topSlug),
                'amount' => $topAmount,
                'share' => $debitsAbs > 0 ? (int) round(($topAmount / $debitsAbs) * 100) : 0,
            ];
        }

        $stats = [
            'total' => $totalTransactions,
            'debits' => $debitsAbs,
            'credits' => $creditsInt,
            'net' => $netUsage,
            'dailyAvg' => $dailyAvg,
            'peakDay' => $peakDay,
            'peakValue' => $peakValue,
            'topType' => $topType,
            'series' => $series,
        ];

        $transactions = $query->paginate(25);

        return [
            'transactions' => $transactions,
            'usageTypes' => $availableTypes,
            'stats' => $stats,
            'billable' => $billable,
            'meters' => $visibleMeters,
        ];
    }
};

?>

<div class="space-y-6">
    <div>
        <flux:heading size="xl">{{ __('billing::portal.usage_history') }}</flux:heading>
        <flux:subheading>{{ __('billing::portal.usage_history_subtitle') }}</flux:subheading>
    </div>

    {{-- Filters --}}
    <flux:card class="p-4!">
        <div class="flex flex-wrap items-end gap-4">
            <div class="min-w-[180px] flex-1">
                <flux:select wire:model.live="usageType" label="{{ __('billing::portal.usage_history_filter_type') }}">
                    <flux:select.option value="">{{ __('billing::portal.usage_history_all_types') }}</flux:select.option>
                    @foreach ($usageTypes as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div class="min-w-[160px]">
                <flux:input wire:model.live.debounce.300ms="dateFrom" type="date" label="{{ __('billing::portal.usage_history_from') }}" />
            </div>
            <div class="min-w-[160px]">
                <flux:input wire:model.live.debounce.300ms="dateTo" type="date" label="{{ __('billing::portal.usage_history_to') }}" />
            </div>
        </div>
    </flux:card>

    {{-- Current usage meters --}}
    @if (! empty($meters))
        @php
            $metersColsClass = match (min(count($meters), 4)) {
                1 => 'lg:grid-cols-1',
                2 => 'lg:grid-cols-2',
                3 => 'lg:grid-cols-3',
                default => 'lg:grid-cols-4',
            };
        @endphp
        <div class="grid gap-4 grid-cols-1 sm:grid-cols-2 {{ $metersColsClass }}">
            @foreach ($meters as $meter)
                <livewire:mollie-billing::components.usage-meter
                    :type="$meter['type']"
                    :label="$meter['label']"
                    :included="$meter['included']"
                    :balance="$meter['balance']"
                    :purchased-balance="$meter['purchased_balance']"
                    :key="'history-meter-'.$meter['type']"
                />
            @endforeach
        </div>
    @endif

    {{-- Stats --}}
    @if ($stats)
        @php
            $series = $stats['series'] ?? [];
            $maxVal = 0;
            foreach ($series as $p) { if ($p['value'] > $maxVal) $maxVal = $p['value']; }
            $hasTrend = count($series) >= 2 && $maxVal > 0;
            $w = 560; $h = 64; $pad = 2;
            $points = [];
            $areaPoints = [];
            if ($hasTrend) {
                $count = count($series);
                $stepX = ($w - $pad * 2) / max(1, $count - 1);
                foreach ($series as $i => $p) {
                    $x = $pad + $i * $stepX;
                    $y = $h - $pad - (($p['value'] / $maxVal) * ($h - $pad * 2));
                    $points[] = round($x, 2) . ',' . round($y, 2);
                }
                $areaPoints = array_merge(
                    [round($pad, 2) . ',' . ($h - $pad)],
                    $points,
                    [round($pad + ($count - 1) * $stepX, 2) . ',' . ($h - $pad)],
                );
            }
            $peakLabel = $stats['peakDay']
                ? \Carbon\Carbon::parse($stats['peakDay'])->translatedFormat('d. M')
                : null;
        @endphp

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-4">
            {{-- Net usage (hero stat) --}}
            <flux:card class="relative overflow-hidden p-5!">
                <div class="pointer-events-none absolute -right-6 -top-6 size-24 rounded-full bg-red-500/10 blur-2xl dark:bg-red-400/10"></div>
                <div class="flex items-start justify-between gap-2">
                    <flux:subheading>{{ __('billing::portal.usage_history_net') }}</flux:subheading>
                    <flux:icon.arrow-trending-down class="size-4 text-red-500/70 dark:text-red-400/70" />
                </div>
                <div class="mt-3 flex items-baseline gap-2">
                    <span class="text-3xl font-bold tabular-nums tracking-tight text-red-600 dark:text-red-400">{{ number_format($stats['net']) }}</span>
                </div>
                <flux:text class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    {{ __('billing::portal.usage_history_net_hint') }}
                </flux:text>
                <div class="mt-3 flex items-center gap-3 text-xs tabular-nums">
                    <span class="inline-flex items-center gap-1 text-red-600/80 dark:text-red-400/80">
                        <span class="size-1.5 rounded-full bg-red-500"></span>
                        −{{ number_format($stats['debits']) }}
                    </span>
                    <span class="inline-flex items-center gap-1 text-emerald-600/80 dark:text-emerald-400/80">
                        <span class="size-1.5 rounded-full bg-emerald-500"></span>
                        +{{ number_format($stats['credits']) }}
                    </span>
                </div>
            </flux:card>

            {{-- Daily average --}}
            <flux:card class="p-5!">
                <div class="flex items-start justify-between gap-2">
                    <flux:subheading>{{ __('billing::portal.usage_history_daily_avg') }}</flux:subheading>
                    <flux:icon.calculator class="size-4 text-zinc-400" />
                </div>
                <div class="mt-3">
                    <span class="text-3xl font-bold tabular-nums tracking-tight">{{ number_format($stats['dailyAvg']) }}</span>
                </div>
                <flux:text class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    {{ __('billing::portal.usage_history_daily_avg_hint') }}
                </flux:text>
            </flux:card>

            {{-- Peak day --}}
            <flux:card class="p-5!">
                <div class="flex items-start justify-between gap-2">
                    <flux:subheading>{{ __('billing::portal.usage_history_peak') }}</flux:subheading>
                    <flux:icon.bolt class="size-4 text-amber-500/80" />
                </div>
                <div class="mt-3">
                    @if ($stats['peakValue'] > 0)
                        <span class="text-3xl font-bold tabular-nums tracking-tight">{{ number_format($stats['peakValue']) }}</span>
                    @else
                        <span class="text-3xl font-bold tabular-nums tracking-tight text-zinc-300 dark:text-zinc-600">—</span>
                    @endif
                </div>
                <flux:text class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    {{ $peakLabel ?? __('billing::portal.usage_history_peak_none') }}
                </flux:text>
            </flux:card>

            {{-- Top usage type --}}
            <flux:card class="p-5!">
                <div class="flex items-start justify-between gap-2">
                    <flux:subheading>{{ __('billing::portal.usage_history_top_type') }}</flux:subheading>
                    <flux:icon.chart-pie class="size-4 text-zinc-400" />
                </div>
                @if ($stats['topType'])
                    <div class="mt-3 flex items-baseline gap-2">
                        <span class="truncate text-2xl font-bold tracking-tight">{{ $stats['topType']['label'] }}</span>
                    </div>
                    <div class="mt-2 flex items-center gap-2">
                        <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/5">
                            <div class="h-full rounded-full bg-linear-to-r from-accent/70 to-accent" style="width: {{ max(4, $stats['topType']['share']) }}%"></div>
                        </div>
                        <span class="text-xs font-semibold tabular-nums text-zinc-500 dark:text-zinc-400">{{ $stats['topType']['share'] }}%</span>
                    </div>
                @else
                    <div class="mt-3">
                        <span class="text-3xl font-bold tabular-nums tracking-tight text-zinc-300 dark:text-zinc-600">—</span>
                    </div>
                    <flux:text class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        {{ __('billing::portal.usage_history_top_type_none') }}
                    </flux:text>
                @endif
            </flux:card>
        </div>

        {{-- Trend / sparkline --}}
        <flux:card class="p-5!">
            <div class="flex items-center justify-between gap-2">
                <div>
                    <flux:subheading>{{ __('billing::portal.usage_history_trend_title') }}</flux:subheading>
                    <flux:text class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                        {{ $stats['total'] }} {{ __('billing::portal.usage_history_total') }}
                    </flux:text>
                </div>
                @if ($peakLabel)
                    <flux:badge size="sm" color="amber" icon="bolt">{{ $peakLabel }} · {{ number_format($stats['peakValue']) }}</flux:badge>
                @endif
            </div>

            @if ($hasTrend)
                <div class="mt-4">
                    <svg viewBox="0 0 {{ $w }} {{ $h }}" preserveAspectRatio="none" class="h-16 w-full overflow-visible">
                        <defs>
                            <linearGradient id="sparkFill" x1="0" x2="0" y1="0" y2="1">
                                <stop offset="0%" stop-color="rgb(244 63 94)" stop-opacity="0.28" />
                                <stop offset="100%" stop-color="rgb(244 63 94)" stop-opacity="0" />
                            </linearGradient>
                        </defs>
                        <polygon points="{{ implode(' ', $areaPoints) }}" fill="url(#sparkFill)" />
                        <polyline
                            points="{{ implode(' ', $points) }}"
                            fill="none"
                            stroke="rgb(244 63 94)"
                            stroke-width="1.5"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            vector-effect="non-scaling-stroke"
                        />
                    </svg>
                </div>
            @else
                <flux:text class="mt-4 text-xs text-zinc-400 dark:text-zinc-500">
                    {{ __('billing::portal.usage_history_trend_empty') }}
                </flux:text>
            @endif
        </flux:card>
    @endif

    {{-- Transaction table --}}
    @if (! $transactions || $transactions->isEmpty())
        <flux:callout variant="secondary" icon="chart-bar">
            {{ __('billing::portal.usage_history_empty') }}
        </flux:callout>
    @else
        <flux:card class="py-0! overflow-hidden">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('billing::portal.usage_history_col_date') }}</flux:table.column>
                    <flux:table.column>{{ __('billing::portal.usage_history_col_type') }}</flux:table.column>
                    <flux:table.column>{{ __('billing::portal.usage_history_col_direction') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('billing::portal.usage_history_col_quantity') }}</flux:table.column>
                    <flux:table.column>{{ __('billing::portal.usage_history_col_reason') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($transactions as $tx)
                        @php
                            $meta = $tx->meta ?? [];
                            $isDebit = $tx->type === 'withdraw';
                            $reasonKey = $meta['reason'] ?? null;
                            $langKey = $reasonKey ? "billing::portal.usage_reasons.{$reasonKey}" : null;
                            $translatedReason = $langKey && __($langKey) !== $langKey ? __($langKey) : ($reasonKey ?? '—');
                        @endphp
                        <flux:table.row>
                            <flux:table.cell class="tabular-nums">{{ BillingTime::display($tx->created_at, $billable)->translatedFormat('d. M Y H:i') }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" color="zinc">{{ ucfirst($meta['type'] ?? $tx->wallet?->slug ?? '—') }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                @if ($isDebit)
                                    <flux:badge size="sm" color="red">{{ __('billing::portal.usage_history_debit') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="emerald">{{ __('billing::portal.usage_history_credit') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="text-right tabular-nums font-medium">
                                <span class="{{ $isDebit ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                                    {{ $isDebit ? '−' : '+' }}{{ number_format(abs((int) $tx->amount)) }}
                                </span>
                            </flux:table.cell>
                            <flux:table.cell class="text-zinc-500 dark:text-zinc-400">{{ $translatedReason }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>

        @if ($transactions->hasPages())
            <div>{{ $transactions->links() }}</div>
        @endif
    @endif
</div>
