<?php

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Services\Vat\CountryMatchService;
use GraystackIT\MollieBilling\Support\BillingRoute;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $tab = 'pending';
    public string $search = '';
    public string $sortBy = 'created_at';
    public string $sortDirection = 'desc';
    public ?string $flash = null;
    public ?string $flashError = null;

    public ?int $resolvingId = null;
    public ?string $resolvingChosen = null;
    public ?string $resolvingError = null;

    private const ALLOWED_SORTS = ['id', 'tax_country_user', 'tax_country_payment', 'tax_country_ip', 'created_at', 'resolved_at'];

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingTab(): void { $this->resetPage(); }

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

    public function openResolve(int $id): void
    {
        $m = BillingCountryMismatch::query()->whereKey($id)->first();
        if ($m === null || $m->status !== CountryMismatchStatus::Pending) {
            return;
        }

        $this->resolvingId = $id;
        $this->resolvingChosen = null;
        $this->resolvingError = null;

        $this->dispatch('open-resolve-modal');
    }

    public function cancelResolve(): void
    {
        $this->resolvingId = null;
        $this->resolvingChosen = null;
        $this->resolvingError = null;
    }

    public function confirmResolve(CountryMatchService $service): void
    {
        if ($this->resolvingId === null) {
            return;
        }

        $chosen = strtoupper(trim((string) $this->resolvingChosen));
        if (strlen($chosen) !== 2) {
            $this->resolvingError = 'Please choose or enter a valid 2-letter country code before confirming.';
            return;
        }

        $m = BillingCountryMismatch::query()->whereKey($this->resolvingId)->first();
        if ($m === null) {
            $this->cancelResolve();
            return;
        }

        $billable = $m->billable;
        if ($billable === null) {
            $this->resolvingError = 'Could not resolve the mismatch. Check the error and try again.';
            return;
        }

        try {
            $service->resolve($billable, $m, $chosen, auth()->user());
        } catch (\Throwable $e) {
            report($e);
            $this->resolvingError = $e->getMessage();
            return;
        }

        $this->flash = 'Mismatch resolved. Refunds and reissues have been queued at Mollie — invoices will appear once Mollie confirms the payments.';
        $this->flashError = null;
        $this->cancelResolve();
        $this->dispatch('close-resolve-modal');
    }

    public function with(): array
    {
        $base = BillingCountryMismatch::query()->with('billable');

        if ($this->tab === 'pending') {
            $base->where('status', CountryMismatchStatus::Pending);
        } else {
            $base->where('status', CountryMismatchStatus::Resolved);
        }

        if ($this->search !== '') {
            $needle = strtoupper($this->search);
            $base->where(function ($w) use ($needle): void {
                $w->where('tax_country_user', 'like', '%'.$needle.'%')
                    ->orWhere('tax_country_payment', 'like', '%'.$needle.'%')
                    ->orWhere('tax_country_ip', 'like', '%'.$needle.'%')
                    ->orWhere('chosen_country', 'like', '%'.$needle.'%');
            });
        }

        $sortBy = in_array($this->sortBy, self::ALLOWED_SORTS, true) ? $this->sortBy : 'created_at';

        $resolvingMismatch = $this->resolvingId !== null
            ? BillingCountryMismatch::query()->with('billable', 'invoices')->whereKey($this->resolvingId)->first()
            : null;

        return [
            'rows' => $base->orderBy($sortBy, $this->sortDirection)->paginate(20),
            'resolvingMismatch' => $resolvingMismatch,
        ];
    }
};

?>

<div class="space-y-6">
    <x-mollie-billing::admin.page-header
        title="Country mismatches"
        subtitle="Cases where the user's declared tax country matches neither their payment method country nor their IP country. Resolved by the user via the dashboard self-service modal — manual override available here for edge cases."
    />

    @if ($flash)
        <flux:callout variant="{{ $flashError ? 'danger' : 'success' }}" icon="{{ $flashError ? 'exclamation-triangle' : 'check-circle' }}" inline>{{ $flashError ?? $flash }}</flux:callout>
    @endif

    <flux:tab.group>
        <flux:tabs wire:model.live="tab">
            <flux:tab name="pending" icon="exclamation-triangle">Pending</flux:tab>
            <flux:tab name="resolved" icon="check-circle">Resolved</flux:tab>
        </flux:tabs>

        <flux:tab.panel name="pending" class="pt-4 space-y-4">
            <flux:input
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="Filter by country code (e.g. DE, FR)"
                icon="magnifying-glass"
            />

            @if ($rows->isEmpty())
                <flux:card>
                    <x-mollie-billing::admin.empty
                        icon="check-circle"
                        title="No pending mismatches"
                        description="All flagged country mismatches have been resolved."
                    />
                </flux:card>
            @else
                <flux:card class="p-0! sm:px-6! sm:py-2!">
                    <flux:table :paginate="$rows">
                        <flux:table.columns>
                            <flux:table.column>Billable</flux:table.column>
                            <flux:table.column sortable :sorted="$sortBy === 'tax_country_user'" :direction="$sortDirection" wire:click="sort('tax_country_user')">User</flux:table.column>
                            <flux:table.column sortable :sorted="$sortBy === 'tax_country_payment'" :direction="$sortDirection" wire:click="sort('tax_country_payment')">Payment</flux:table.column>
                            <flux:table.column sortable :sorted="$sortBy === 'tax_country_ip'" :direction="$sortDirection" wire:click="sort('tax_country_ip')">IP</flux:table.column>
                            <flux:table.column>Notified</flux:table.column>
                            <flux:table.column class="w-32"></flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($rows as $r)
                                @php
                                    $b = $r->billable;
                                    $name = $b?->getBillingName();
                                    $email = $b?->getBillingEmail();
                                @endphp
                                <flux:table.row :key="$r->id">
                                    <flux:table.cell>
                                        @if ($b)
                                            <a href="{{ route(BillingRoute::admin('billables.show'), $b) }}" class="hover:underline">
                                                <span class="font-medium text-zinc-900 dark:text-white">{{ $name ?: class_basename($r->billable_type).'#'.$r->billable_id }}</span>
                                            </a>
                                            @if ($email)
                                                <div class="text-xs text-zinc-500">{{ $email }}</div>
                                            @endif
                                        @else
                                            <span class="font-mono text-zinc-500">{{ class_basename($r->billable_type) }}#{{ $r->billable_id }}</span>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell class="font-mono">{{ $r->tax_country_user ?? '—' }}</flux:table.cell>
                                    <flux:table.cell class="font-mono">{{ $r->tax_country_payment ?? '—' }}</flux:table.cell>
                                    <flux:table.cell class="font-mono">{{ $r->tax_country_ip ?? '—' }}</flux:table.cell>
                                    <flux:table.cell class="text-sm text-zinc-500">{{ $r->notified_at?->format('Y-m-d H:i') ?? '—' }}</flux:table.cell>
                                    <flux:table.cell>
                                        <flux:button size="xs" icon="check" wire:click="openResolve({{ $r->id }})">Resolve</flux:button>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </flux:card>
            @endif
        </flux:tab.panel>

        <flux:tab.panel name="resolved" class="pt-4 space-y-4">
            <flux:input
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="Filter by country code"
                icon="magnifying-glass"
            />

            @if ($rows->isEmpty())
                <flux:card>
                    <x-mollie-billing::admin.empty
                        icon="check-circle"
                        title="No resolved mismatches yet"
                        description="Resolved mismatches will appear here as an audit log."
                    />
                </flux:card>
            @else
                <flux:card class="p-0! sm:px-6! sm:py-2!">
                    <flux:table :paginate="$rows">
                        <flux:table.columns>
                            <flux:table.column>Billable</flux:table.column>
                            <flux:table.column>User / Payment / IP</flux:table.column>
                            <flux:table.column>Chosen</flux:table.column>
                            <flux:table.column sortable :sorted="$sortBy === 'resolved_at'" :direction="$sortDirection" wire:click="sort('resolved_at')">Resolved</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($rows as $r)
                                @php
                                    $b = $r->billable;
                                    $name = $b?->getBillingName();
                                @endphp
                                <flux:table.row :key="$r->id">
                                    <flux:table.cell>
                                        @if ($b)
                                            <a href="{{ route(BillingRoute::admin('billables.show'), $b) }}" class="hover:underline">
                                                <span class="font-medium text-zinc-900 dark:text-white">{{ $name ?: class_basename($r->billable_type).'#'.$r->billable_id }}</span>
                                            </a>
                                        @else
                                            <span class="font-mono text-zinc-500">{{ class_basename($r->billable_type) }}#{{ $r->billable_id }}</span>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell class="font-mono text-sm">
                                        {{ $r->tax_country_user ?? '—' }} / {{ $r->tax_country_payment ?? '—' }} / {{ $r->tax_country_ip ?? '—' }}
                                    </flux:table.cell>
                                    <flux:table.cell class="font-mono">{{ $r->chosen_country ?? '—' }}</flux:table.cell>
                                    <flux:table.cell class="text-sm text-zinc-500">
                                        {{ $r->resolved_at?->format('Y-m-d H:i') ?? '—' }}
                                        @if ($r->resolved_by_user_id)
                                            <div class="text-xs text-zinc-400">by user #{{ $r->resolved_by_user_id }}</div>
                                        @else
                                            <div class="text-xs text-zinc-400">by system</div>
                                        @endif
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </flux:card>
            @endif
        </flux:tab.panel>
    </flux:tab.group>

    <flux:modal name="resolve-mismatch" class="md:w-[520px]" @open-resolve-modal.window="$flux.modal('resolve-mismatch').show()" @close-resolve-modal.window="$flux.modal('resolve-mismatch').close()" @close="$wire.cancelResolve()">
        <div class="space-y-5">
            <flux:heading size="lg">Resolve country mismatch</flux:heading>

            @if ($resolvingMismatch)
                @php
                    $userCountryRaw = strtoupper((string) ($resolvingMismatch->tax_country_user ?? ''));
                    $paymentCountryRaw = strtoupper((string) ($resolvingMismatch->tax_country_payment ?? ''));
                    $ipCountryRaw = strtoupper((string) ($resolvingMismatch->tax_country_ip ?? ''));
                    $userCountryValid = strlen($userCountryRaw) === 2;
                    $paymentCountryValid = strlen($paymentCountryRaw) === 2;
                    $ipCountryValid = strlen($ipCountryRaw) === 2;
                    $hasAnyChoice = $userCountryValid || $paymentCountryValid || $ipCountryValid;
                    $invoiceCount = $resolvingMismatch->invoices->count();
                @endphp

                <flux:text>
                    Pick the correct billing country for this billable. All linked invoices will be refunded via Mollie and reissued at the chosen country's VAT rate. The customer must reactivate their subscription themselves after the resolve.
                </flux:text>

                <flux:callout variant="secondary" icon="information-circle" inline>
                    @if ($invoiceCount === 0)
                        No invoices linked to this mismatch.
                    @elseif ($invoiceCount === 1)
                        1 invoice will be refunded and reissued.
                    @else
                        {{ $invoiceCount }} invoices will be refunded and reissued.
                    @endif
                </flux:callout>

                @if ($hasAnyChoice)
                    <flux:radio.group wire:model.live="resolvingChosen" variant="cards" :indicator="false">
                        @if ($userCountryValid)
                            <flux:radio value="{{ $userCountryRaw }}">
                                <div>
                                    <div class="font-medium">User-declared: {{ $userCountryRaw }}</div>
                                    <div class="text-xs text-zinc-500">
                                        Use this when the user is correct (e.g. corrected a typo). Existing invoices are refunded and reissued at the same VAT rate ({{ $userCountryRaw }}).
                                    </div>
                                </div>
                            </flux:radio>
                        @endif
                        @if ($paymentCountryValid)
                            <flux:radio value="{{ $paymentCountryRaw }}">
                                <div>
                                    <div class="font-medium">Payment country: {{ $paymentCountryRaw }}</div>
                                    <div class="text-xs text-zinc-500">
                                        Use this when the payment-method country is correct. Existing invoices are refunded via Mollie and reissued at the {{ $paymentCountryRaw }} VAT rate.
                                    </div>
                                </div>
                            </flux:radio>
                        @endif
                        @if ($ipCountryValid)
                            <flux:radio value="{{ $ipCountryRaw }}">
                                <div>
                                    <div class="font-medium">IP country: {{ $ipCountryRaw }}</div>
                                    <div class="text-xs text-zinc-500">
                                        Use this when the IP country is correct (typical for residential customers). Existing invoices are refunded via Mollie and reissued at the {{ $ipCountryRaw }} VAT rate.
                                    </div>
                                </div>
                            </flux:radio>
                        @endif
                    </flux:radio.group>
                @endif

                <flux:field>
                    <flux:label>Or enter a country manually</flux:label>
                    <flux:input
                        wire:model.live="resolvingChosen"
                        placeholder="e.g. AT"
                        maxlength="2"
                        class="font-mono uppercase"
                    />
                    <flux:description>
                        Overrides the selection above. Use this when none of the suggestions is correct.
                    </flux:description>
                </flux:field>

                @if ($resolvingError)
                    <flux:callout variant="danger" icon="exclamation-circle" inline>
                        {{ $resolvingError }}
                    </flux:callout>
                @endif

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" icon="check" wire:click="confirmResolve">Confirm resolve</flux:button>
                </div>
            @endif
        </div>
    </flux:modal>
</div>
