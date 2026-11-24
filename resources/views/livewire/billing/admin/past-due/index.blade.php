<?php

use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\RetryUsageOverageChargeJob;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Support\BillingTime;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $sortBy = 'updated_at';
    public string $sortDirection = 'desc';
    public ?string $flash = null;
    public ?string $error = null;

    private const ALLOWED_SORTS = ['name', 'email', 'updated_at'];

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

    public function retry(mixed $id): void
    {
        $this->flash = $this->error = null;

        $class = config('mollie-billing.billable_model');
        $b = $class ? $class::find($id) : null;
        if (! $b) {
            $this->error = __('billing::admin.common.billable_not_found');
            return;
        }

        // Only what this list offers is actionable. The job evaluates the dunning
        // budget and can flip a billable to past_due itself, so a target the list
        // never showed must not reach it just because the id was in the payload.
        if (! $b->isBillingPastDue()) {
            $this->error = __('billing::admin.past_due.not_past_due', ['name' => $b->getBillingName()]);
            return;
        }

        // A debt whose own dunning cycle has given up is not something the retry job
        // can move: its budget is spent, so the job only gave up again and mailed the
        // customer a second time. For that case the operator's click starts a new
        // collection cycle.
        $overage = app(\GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly::class);

        if ($overage->deferredDebtLines($b) !== [] || \GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly::isPastDueForOverageDebt($b)) {
            try {
                $outcome = $overage->collectStandingDebtNow($b);
            } catch (\Throwable $e) {
                report($e);
                $this->error = __('billing::admin.past_due.debt_collection_failed', ['name' => $b->getBillingName()]);

                return;
            }

            if ($outcome !== 'nothing') {
                $this->flash = __(
                    $outcome === 'started'
                        ? 'billing::admin.past_due.debt_collection_started'
                        : 'billing::admin.past_due.debt_collection_in_flight',
                    ['name' => $b->getBillingName()],
                );

                return;
            }
        }

        RetryUsageOverageChargeJob::dispatch($class, $b->getKey());
        $this->flash = __('billing::admin.past_due.retry_dispatched', ['name' => $b->getBillingName()]);
    }

    public function with(): array
    {
        $class = config('mollie-billing.billable_model');
        $q = $class ? $class::query()->where('subscription_status', SubscriptionStatus::PastDue->value) : null;

        if ($q && $this->search !== '') {
            $q->billableSearch($this->search);
        }

        if ($q) {
            $sortBy = in_array($this->sortBy, self::ALLOWED_SORTS, true) ? $this->sortBy : 'updated_at';
            match ($sortBy) {
                'name' => $q->billableOrderByName($this->sortDirection),
                'email' => $q->billableOrderByEmail($this->sortDirection),
                default => $q->orderBy($sortBy, $this->sortDirection),
            };
        }

        return ['billables' => $q ? $q->paginate(20) : null];
    }
};

?>

<div class="space-y-6">
    <x-mollie-billing::admin.page-header
        :title="__('billing::admin.past_due.title')"
        :subtitle="__('billing::admin.past_due.subtitle')"
    />

    <x-mollie-billing::admin.flash :success="$flash" :error="$error" />

    @if (! $billables)
        <flux:card>
            <x-mollie-billing::admin.empty
                icon="exclamation-triangle"
                :title="__('billing::admin.common.no_billable_model')"
                :description="__('billing::admin.common.no_billable_model_description')"
            />
        </flux:card>
    @else
        <flux:input
            type="search"
            wire:model.live.debounce.300ms="search"
            :placeholder="__('billing::admin.common.search_name_email')"
            icon="magnifying-glass"
        />

        @if ($billables->isEmpty())
            <flux:card>
                <x-mollie-billing::admin.empty
                    icon="check-circle"
                    :title="__('billing::admin.past_due.empty_title')"
                    :description="__('billing::admin.past_due.empty_description')"
                />
            </flux:card>
        @else
            <flux:card class="p-0! sm:px-6! sm:py-2!">
                <flux:table :paginate="$billables">
                    <flux:table.columns>
                        <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection" wire:click="sort('name')">{{ __('billing::admin.past_due.column.billable') }}</flux:table.column>
                        <flux:table.column sortable :sorted="$sortBy === 'updated_at'" :direction="$sortDirection" wire:click="sort('updated_at')">{{ __('billing::admin.past_due.column.since') }}</flux:table.column>
                        <flux:table.column>{{ __('billing::admin.past_due.column.last_failure') }}</flux:table.column>
                        <flux:table.column class="w-32"></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($billables as $b)
                            <flux:table.row :key="$b->getKey()">
                                <flux:table.cell variant="strong">
                                    <a href="{{ route(BillingRoute::admin('billables.show'), $b) }}" class="hover:underline">{{ $b->getBillingName() }}</a>
                                    <flux:text size="xs" class="text-zinc-500">{{ $b->getBillingEmail() }}</flux:text>
                                </flux:table.cell>
                                <flux:table.cell class="tabular-nums">
                                    <x-mollie-billing::admin.time :at="$b->updated_at" />
                                    <flux:text size="xs" class="text-zinc-500">{{ $b->updated_at?->diffForHumans() }}</flux:text>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:text size="xs" class="text-zinc-500">{{ data_get($b->getBillingSubscriptionMeta(), 'payment_failure.reason', '—') }}</flux:text>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:button size="xs" icon="arrow-path" wire:click='retry({{ \Illuminate\Support\Js::from($b->getKey()) }})'>{{ __('billing::admin.common.retry') }}</flux:button>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </flux:card>
        @endif
    @endif
</div>
