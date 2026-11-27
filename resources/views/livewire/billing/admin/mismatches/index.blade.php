<?php

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Services\Vat\CountryMatchService;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\BillingRoute;
use Livewire\Attributes\Locked;
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

    /** Locked: which mismatch confirmResolve() writes to is decided by openResolve(), not by the browser. */
    #[Locked]
    public ?int $resolvingId = null;
    public ?string $resolvingChosen = null;
    public ?string $resolvingJustification = null;
    public ?string $resolvingError = null;

    private const ALLOWED_SORTS = ['id', 'tax_country_user', 'tax_country_payment', 'tax_country_ip', 'created_at', 'resolved_at'];

    /**
     * Cap for the operator's justification. It is persisted inside the billable's
     * JSON subscription meta, so an unbounded textarea would grow a column that is
     * read on every billing operation.
     */
    private const JUSTIFICATION_MAX = 500;

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
        $this->resolvingJustification = null;
        $this->resolvingError = null;

        $this->dispatch('open-resolve-modal');
    }

    public function cancelResolve(): void
    {
        $this->resolvingId = null;
        $this->resolvingChosen = null;
        $this->resolvingJustification = null;
        $this->resolvingError = null;
    }

    public function confirmResolve(CountryMatchService $service): void
    {
        if ($this->resolvingId === null) {
            return;
        }

        $chosen = strtoupper(trim((string) $this->resolvingChosen));
        if (strlen($chosen) !== 2) {
            $this->resolvingError = __('billing::admin.mismatches.modal.invalid_country');
            return;
        }

        // Refused here as well as in the service, so the operator reads a translated
        // sentence instead of the service's untranslated exception. Resolving refunds
        // every linked invoice before it tries to re-issue, and a country the VAT
        // calculator cannot price makes the re-issue impossible — the customer would be
        // left fully refunded and never re-charged.
        if (! app(VatCalculationService::class)->isInvoiceable($chosen)) {
            $this->resolvingError = __('billing::admin.mismatches.modal.country_not_invoiceable', ['country' => $chosen]);
            return;
        }

        // Resolving refunds and reissues every linked invoice at another country's
        // VAT rate. That is a hand-made tax decision, so it is only accepted with a
        // reason on record — the service also refuses an off-signal country without
        // one, but that guard throws an untranslated message.
        $justification = trim((string) $this->resolvingJustification);
        if ($justification === '') {
            $this->resolvingError = __('billing::admin.mismatches.modal.justification_required');
            return;
        }
        if (mb_strlen($justification) > self::JUSTIFICATION_MAX) {
            $this->resolvingError = __('billing::admin.mismatches.modal.justification_too_long', ['max' => self::JUSTIFICATION_MAX]);
            return;
        }

        $m = BillingCountryMismatch::query()->whereKey($this->resolvingId)->first();
        if ($m === null) {
            $this->cancelResolve();
            return;
        }

        $billable = $m->billable;
        if ($billable === null) {
            $this->resolvingError = __('billing::admin.mismatches.modal.unresolvable');
            return;
        }

        try {
            $service->resolve($billable, $m, $chosen, auth()->user(), $justification);
        } catch (\Throwable $e) {
            // A refusal the service can say is shown in the operator's language and not
            // reported; anything else is reported and shown with its (English) detail.
            if (! \GraystackIT\MollieBilling\Support\ExceptionMessage::isTranslatable($e)) {
                report($e);
            }
            $this->resolvingError = \GraystackIT\MollieBilling\Support\ExceptionMessage::forOperator($e);
            return;
        }

        $this->flash = __('billing::admin.mismatches.modal.resolved');
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
            'justificationMax' => self::JUSTIFICATION_MAX,
        ];
    }
};

?>

<div class="space-y-6">
    <x-mollie-billing::admin.page-header
        :title="__('billing::admin.mismatches.title')"
        :subtitle="__('billing::admin.mismatches.subtitle')"
    />

    @if ($flash)
        <flux:callout variant="{{ $flashError ? 'danger' : 'success' }}" icon="{{ $flashError ? 'exclamation-triangle' : 'check-circle' }}" inline>{{ $flashError ?? $flash }}</flux:callout>
    @endif

    <flux:tab.group>
        <flux:tabs wire:model.live="tab">
            <flux:tab name="pending" icon="exclamation-triangle">{{ __('billing::admin.mismatches.tab_pending') }}</flux:tab>
            <flux:tab name="resolved" icon="check-circle">{{ __('billing::admin.mismatches.tab_resolved') }}</flux:tab>
        </flux:tabs>

        <flux:tab.panel name="pending" class="pt-4 space-y-4">
            <flux:input
                type="search"
                wire:model.live.debounce.300ms="search"
                :placeholder="__('billing::admin.mismatches.search_pending')"
                icon="magnifying-glass"
            />

            @if ($rows->isEmpty())
                <flux:card>
                    <x-mollie-billing::admin.empty
                        icon="check-circle"
                        :title="__('billing::admin.mismatches.pending_empty_title')"
                        :description="__('billing::admin.mismatches.pending_empty_description')"
                    />
                </flux:card>
            @else
                <flux:card class="p-0! sm:px-6! sm:py-2!">
                    <flux:table :paginate="$rows">
                        <flux:table.columns>
                            <flux:table.column>{{ __('billing::admin.mismatches.column.billable') }}</flux:table.column>
                            <flux:table.column sortable :sorted="$sortBy === 'tax_country_user'" :direction="$sortDirection" wire:click="sort('tax_country_user')">{{ __('billing::admin.mismatches.column.user') }}</flux:table.column>
                            <flux:table.column sortable :sorted="$sortBy === 'tax_country_payment'" :direction="$sortDirection" wire:click="sort('tax_country_payment')">{{ __('billing::admin.mismatches.column.payment') }}</flux:table.column>
                            <flux:table.column sortable :sorted="$sortBy === 'tax_country_ip'" :direction="$sortDirection" wire:click="sort('tax_country_ip')">{{ __('billing::admin.mismatches.column.ip') }}</flux:table.column>
                            <flux:table.column>{{ __('billing::admin.mismatches.column.notified') }}</flux:table.column>
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
                                    <flux:table.cell class="text-sm text-zinc-500"><x-mollie-billing::admin.time :at="$r->notified_at" /></flux:table.cell>
                                    <flux:table.cell>
                                        <flux:button size="xs" icon="check" wire:click="openResolve({{ $r->id }})">{{ __('billing::admin.mismatches.resolve') }}</flux:button>
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
                :placeholder="__('billing::admin.mismatches.search_resolved')"
                icon="magnifying-glass"
            />

            @if ($rows->isEmpty())
                <flux:card>
                    <x-mollie-billing::admin.empty
                        icon="check-circle"
                        :title="__('billing::admin.mismatches.resolved_empty_title')"
                        :description="__('billing::admin.mismatches.resolved_empty_description')"
                    />
                </flux:card>
            @else
                <flux:card class="p-0! sm:px-6! sm:py-2!">
                    <flux:table :paginate="$rows">
                        <flux:table.columns>
                            <flux:table.column>{{ __('billing::admin.mismatches.column.billable') }}</flux:table.column>
                            <flux:table.column>{{ __('billing::admin.mismatches.column.triple') }}</flux:table.column>
                            <flux:table.column>{{ __('billing::admin.mismatches.column.chosen') }}</flux:table.column>
                            <flux:table.column sortable :sorted="$sortBy === 'resolved_at'" :direction="$sortDirection" wire:click="sort('resolved_at')">{{ __('billing::admin.mismatches.column.resolved') }}</flux:table.column>
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
                                        <x-mollie-billing::admin.time :at="$r->resolved_at" />
                                        @if ($r->resolved_by_user_id)
                                            <div class="text-xs text-zinc-400">{{ __('billing::admin.mismatches.by_user', ['id' => $r->resolved_by_user_id]) }}</div>
                                        @else
                                            <div class="text-xs text-zinc-400">{{ __('billing::admin.mismatches.by_system') }}</div>
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
            <flux:heading size="lg">{{ __('billing::admin.mismatches.modal.title') }}</flux:heading>

            @if ($resolvingMismatch)
                @php
                    $userCountryRaw = strtoupper((string) ($resolvingMismatch->tax_country_user ?? ''));
                    $paymentCountryRaw = strtoupper((string) ($resolvingMismatch->tax_country_payment ?? ''));
                    $ipCountryRaw = strtoupper((string) ($resolvingMismatch->tax_country_ip ?? ''));
                    $userCountryValid = strlen($userCountryRaw) === 2;
                    $paymentCountryValid = strlen($paymentCountryRaw) === 2;
                    $ipCountryValid = strlen($ipCountryRaw) === 2;
                    $hasAnyChoice = $userCountryValid || $paymentCountryValid || $ipCountryValid;
                    // The invoices the resolve will refund and reissue — the sentence below says
                    // exactly that, so an invoice refunded in full before (nothing left) or an
                    // earlier correction's reissue is not counted (CountryMatchService::refundsOnResolve()).
                    $invoiceCount = $resolvingMismatch->billable !== null
                        ? count(app(CountryMatchService::class)->refundsOnResolve($resolvingMismatch->billable, $resolvingMismatch))
                        : $resolvingMismatch->invoices->count();
                @endphp

                <flux:text>
                    {{ __('billing::admin.mismatches.modal.intro') }}
                </flux:text>

                <flux:callout variant="secondary" icon="information-circle" inline>
                    {{ trans_choice('billing::admin.mismatches.modal.invoice_count', $invoiceCount, ['count' => $invoiceCount]) }}
                </flux:callout>

                @if ($hasAnyChoice)
                    <flux:radio.group wire:model.live="resolvingChosen" variant="cards" :indicator="false">
                        @if ($userCountryValid)
                            <flux:radio value="{{ $userCountryRaw }}">
                                <div>
                                    <div class="font-medium">{{ __('billing::admin.mismatches.modal.user_option', ['country' => $userCountryRaw]) }}</div>
                                    <div class="text-xs text-zinc-500">
                                        {{ __('billing::admin.mismatches.modal.user_hint', ['country' => $userCountryRaw]) }}
                                    </div>
                                </div>
                            </flux:radio>
                        @endif
                        @if ($paymentCountryValid)
                            <flux:radio value="{{ $paymentCountryRaw }}">
                                <div>
                                    <div class="font-medium">{{ __('billing::admin.mismatches.modal.payment_option', ['country' => $paymentCountryRaw]) }}</div>
                                    <div class="text-xs text-zinc-500">
                                        {{ __('billing::admin.mismatches.modal.payment_hint', ['country' => $paymentCountryRaw]) }}
                                    </div>
                                </div>
                            </flux:radio>
                        @endif
                        @if ($ipCountryValid)
                            <flux:radio value="{{ $ipCountryRaw }}">
                                <div>
                                    <div class="font-medium">{{ __('billing::admin.mismatches.modal.ip_option', ['country' => $ipCountryRaw]) }}</div>
                                    <div class="text-xs text-zinc-500">
                                        {{ __('billing::admin.mismatches.modal.ip_hint', ['country' => $ipCountryRaw]) }}
                                    </div>
                                </div>
                            </flux:radio>
                        @endif
                    </flux:radio.group>
                @endif

                <flux:field>
                    <flux:label>{{ __('billing::admin.mismatches.modal.manual_label') }}</flux:label>
                    <flux:input
                        wire:model.live="resolvingChosen"
                        :placeholder="__('billing::admin.mismatches.modal.manual_placeholder')"
                        maxlength="2"
                        class="font-mono uppercase"
                    />
                    <flux:description>
                        {{ __('billing::admin.mismatches.modal.manual_description') }}
                    </flux:description>
                </flux:field>

                <flux:field>
                    <flux:label :badge="__('billing::admin.common.required')">{{ __('billing::admin.mismatches.modal.justification_label') }}</flux:label>
                    <flux:textarea
                        wire:model="resolvingJustification"
                        :placeholder="__('billing::admin.mismatches.modal.justification_placeholder')"
                        rows="3"
                        :maxlength="$justificationMax"
                    />
                    <flux:description>
                        {{ __('billing::admin.mismatches.modal.justification_description') }}
                    </flux:description>
                </flux:field>

                @if ($resolvingError)
                    <flux:callout variant="danger" icon="exclamation-circle" inline>
                        {{ $resolvingError }}
                    </flux:callout>
                @endif

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('billing::admin.common.cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" icon="check" wire:click="confirmResolve">{{ __('billing::admin.mismatches.modal.confirm') }}</flux:button>
                </div>
            @endif
        </div>
    </flux:modal>
</div>
