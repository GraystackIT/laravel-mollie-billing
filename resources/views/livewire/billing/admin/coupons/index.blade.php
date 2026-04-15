<?php

use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $sortBy = 'created_at';
    public string $sortDirection = 'desc';

    public array $selected = [];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
    }

    public function deactivateSelected(CouponService $service): void
    {
        foreach (Coupon::query()->whereIn('id', $this->selected)->get() as $coupon) {
            $service->deactivate($coupon);
        }
        $this->selected = [];
        session()->flash('status', 'Selected coupons deactivated.');
    }

    public function with(): array
    {
        return [
            'coupons' => Coupon::query()
                ->when($this->search !== '', fn ($q) => $q
                    ->where('code', 'like', '%'.strtoupper($this->search).'%')
                    ->orWhere('name', 'like', '%'.$this->search.'%'))
                ->orderBy($this->sortBy, $this->sortDirection)
                ->paginate(20),
        ];
    }
};

?>

<div class="p-6 space-y-4">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Coupons</flux:heading>
        <flux:button variant="primary" size="sm" :href="route('billing.admin.coupons.create')">New coupon</flux:button>
    </div>

    @if (session('status'))
        <flux:callout variant="success" icon="check-circle">{{ session('status') }}</flux:callout>
    @endif

    <div class="flex gap-2 items-center">
        <flux:input
            type="search"
            wire:model.live.debounce.300ms="search"
            placeholder="Search by code or name"
            icon="magnifying-glass"
            class="flex-1 basis-0"
        />
        @if (count($selected))
            <flux:button size="sm" wire:click="deactivateSelected" class="shrink-0">
                Deactivate selected ({{ count($selected) }})
            </flux:button>
        @endif
    </div>

    <flux:table :paginate="$coupons">
        <flux:table.columns>
            <flux:table.column></flux:table.column>
            <flux:table.column sortable :sorted="$sortBy === 'code'" :direction="$sortDirection" wire:click="sort('code')">Code</flux:table.column>
            <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection" wire:click="sort('name')">Name</flux:table.column>
            <flux:table.column sortable :sorted="$sortBy === 'type'" :direction="$sortDirection" wire:click="sort('type')">Type</flux:table.column>
            <flux:table.column sortable :sorted="$sortBy === 'redemptions_count'" :direction="$sortDirection" wire:click="sort('redemptions_count')">Redemptions</flux:table.column>
            <flux:table.column sortable :sorted="$sortBy === 'valid_until'" :direction="$sortDirection" wire:click="sort('valid_until')">Valid until</flux:table.column>
            <flux:table.column sortable :sorted="$sortBy === 'active'" :direction="$sortDirection" wire:click="sort('active')">Active</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($coupons as $coupon)
                <flux:table.row :key="$coupon->id">
                    <flux:table.cell>
                        <flux:checkbox wire:model.live="selected" value="{{ $coupon->id }}" />
                    </flux:table.cell>
                    <flux:table.cell variant="strong" class="font-mono">{{ $coupon->code }}</flux:table.cell>
                    <flux:table.cell>{{ $coupon->name }}</flux:table.cell>
                    <flux:table.cell>{{ $coupon->type?->value }}</flux:table.cell>
                    <flux:table.cell>{{ $coupon->redemptions_count }}{{ $coupon->max_redemptions ? ' / '.$coupon->max_redemptions : '' }}</flux:table.cell>
                    <flux:table.cell>{{ $coupon->valid_until?->format('Y-m-d') ?? '—' }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge :color="$coupon->active ? 'green' : 'zinc'" size="sm">
                            {{ $coupon->active ? 'yes' : 'no' }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:button size="xs" variant="ghost" :href="route('billing.admin.coupons.show', $coupon)">View</flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="8" align="center">No coupons yet.</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</div>
