<?php

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Services\Vat\CountryMatchService;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $sortBy = 'created_at';
    public string $sortDirection = 'desc';
    public ?string $flash = null;

    public function updatingSearch(): void { $this->resetPage(); }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
    }

    public function resolve(int $id, CountryMatchService $service): void
    {
        $m = BillingCountryMismatch::find($id);
        if (! $m) return;
        $b = $m->billable()->first();
        if ($b) { $service->resolve($b, $m, auth()->user()); $this->flash = 'Mismatch resolved.'; }
    }

    public function with(): array
    {
        $q = BillingCountryMismatch::query()
            ->where('status', CountryMismatchStatus::Pending);

        if ($this->search !== '') {
            $q->where(function ($w): void {
                $w->where('tax_country_user', 'like', '%'.strtoupper($this->search).'%')
                  ->orWhere('tax_country_ip', 'like', '%'.strtoupper($this->search).'%')
                  ->orWhere('tax_country_payment', 'like', '%'.strtoupper($this->search).'%');
            });
        }

        return ['rows' => $q->orderBy($this->sortBy, $this->sortDirection)->paginate(20)];
    }
};

?>

<div class="p-6 space-y-4">
    <flux:heading size="xl">Country mismatches</flux:heading>

    @if ($flash)
        <flux:callout variant="success" icon="check-circle">{{ $flash }}</flux:callout>
    @endif

    <flux:input
        type="search"
        wire:model.live.debounce.300ms="search"
        placeholder="Filter by country code"
        icon="magnifying-glass"
        class="w-full"
    />

    <flux:table :paginate="$rows">
        <flux:table.columns>
            <flux:table.column>Billable</flux:table.column>
            <flux:table.column sortable :sorted="$sortBy === 'tax_country_user'" :direction="$sortDirection" wire:click="sort('tax_country_user')">User</flux:table.column>
            <flux:table.column sortable :sorted="$sortBy === 'tax_country_ip'" :direction="$sortDirection" wire:click="sort('tax_country_ip')">IP</flux:table.column>
            <flux:table.column sortable :sorted="$sortBy === 'tax_country_payment'" :direction="$sortDirection" wire:click="sort('tax_country_payment')">Payment</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($rows as $r)
                <flux:table.row :key="$r->id">
                    <flux:table.cell variant="strong">{{ class_basename($r->billable_type) }}#{{ $r->billable_id }}</flux:table.cell>
                    <flux:table.cell>{{ $r->tax_country_user }}</flux:table.cell>
                    <flux:table.cell>{{ $r->tax_country_ip ?? '—' }}</flux:table.cell>
                    <flux:table.cell>{{ $r->tax_country_payment ?? '—' }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:button size="xs" wire:click="resolve({{ $r->id }})">Resolve</flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" align="center">No pending mismatches.</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</div>
