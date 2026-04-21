<?php

use Bavix\Wallet\Models\Transaction;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Facades\MollieBilling;
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
            return ['transactions' => null, 'usageTypes' => [], 'stats' => null];
        }

        $catalog = app(SubscriptionCatalogInterface::class);
        $planCode = $billable->getBillingSubscriptionPlanCode();
        $interval = $billable->getBillingSubscriptionInterval();

        // Collect available usage types from plan config.
        $availableTypes = [];
        if ($planCode) {
            foreach ($catalog->includedUsages($planCode, $interval) as $type => $included) {
                $availableTypes[(string) $type] = ucfirst((string) $type);
            }
        }

        // Also include types from existing wallets (covers add-on or legacy types).
        foreach ($billable->wallets as $wallet) {
            $slug = $wallet->slug;
            if ($slug !== 'default' && ! isset($availableTypes[$slug])) {
                $availableTypes[$slug] = ucfirst($slug);
            }
        }

        // Build wallet IDs query scope.
        $walletIds = $billable->wallets
            ->when($this->usageType !== '', fn ($c) => $c->where('slug', $this->usageType))
            ->where('slug', '!=', 'default')
            ->pluck('id');

        $query = Transaction::query()
            ->whereIn('wallet_id', $walletIds)
            ->when($this->dateFrom, fn ($q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->whereDate('created_at', '<=', $this->dateTo))
            ->latest();

        // Stats for the filtered result set.
        $statsQuery = (clone $query);
        $totalDebits = (clone $statsQuery)->where('type', Transaction::TYPE_WITHDRAW)->sum('amount');
        $totalCredits = (clone $statsQuery)->where('type', Transaction::TYPE_DEPOSIT)->sum('amount');
        $totalTransactions = (clone $statsQuery)->count();

        $stats = [
            'total' => $totalTransactions,
            'debits' => abs((int) $totalDebits),
            'credits' => (int) $totalCredits,
        ];

        $transactions = $query->paginate(25);

        return [
            'transactions' => $transactions,
            'usageTypes' => $availableTypes,
            'stats' => $stats,
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

    {{-- Stats --}}
    @if ($stats)
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <flux:card class="p-5!">
                <flux:subheading>{{ __('billing::portal.usage_history_total') }}</flux:subheading>
                <div class="mt-3">
                    <span class="text-3xl font-bold tabular-nums tracking-tight">{{ number_format($stats['total']) }}</span>
                </div>
            </flux:card>
            <flux:card class="p-5!">
                <flux:subheading>{{ __('billing::portal.usage_history_debits') }}</flux:subheading>
                <div class="mt-3">
                    <span class="text-3xl font-bold tabular-nums tracking-tight text-red-600 dark:text-red-400">{{ number_format($stats['debits']) }}</span>
                </div>
            </flux:card>
            <flux:card class="p-5!">
                <flux:subheading>{{ __('billing::portal.usage_history_credits') }}</flux:subheading>
                <div class="mt-3">
                    <span class="text-3xl font-bold tabular-nums tracking-tight text-emerald-600 dark:text-emerald-400">{{ number_format($stats['credits']) }}</span>
                </div>
            </flux:card>
        </div>
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
                            <flux:table.cell class="tabular-nums">{{ $tx->created_at->translatedFormat('d. M Y H:i') }}</flux:table.cell>
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
