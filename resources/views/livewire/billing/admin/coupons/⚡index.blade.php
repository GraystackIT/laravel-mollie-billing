<?php

use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Support\BillingRoute;
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

<div class="space-y-6">
    <x-mollie-billing::admin.page-header title="Coupons" subtitle="Discounts, credits, trial extensions and access grants.">
        <x-slot:actions>
            <flux:button variant="primary" size="sm" icon="plus" :href="route(BillingRoute::admin('coupons.create'))">New coupon</flux:button>
        </x-slot:actions>
    </x-mollie-billing::admin.page-header>

    <x-mollie-billing::admin.flash />

    <div class="flex flex-wrap items-center gap-2">
        <flux:input
            type="search"
            wire:model.live.debounce.300ms="search"
            placeholder="Search by code or name"
            icon="magnifying-glass"
            class="flex-1 basis-64"
        />
        @if (count($selected))
            <flux:button size="sm" variant="danger" icon="no-symbol" wire:click="deactivateSelected" class="shrink-0">
                Deactivate selected ({{ count($selected) }})
            </flux:button>
        @endif
    </div>

    @if ($coupons->isEmpty())
        <flux:card>
            <x-mollie-billing::admin.empty
                icon="ticket"
                title="No coupons yet"
                description="Create your first coupon to offer discounts, credits, trial extensions or free access."
            >
                <x-slot:cta>
                    <flux:button variant="primary" size="sm" icon="plus" :href="route(BillingRoute::admin('coupons.create'))">New coupon</flux:button>
                </x-slot:cta>
            </x-mollie-billing::admin.empty>
        </flux:card>
    @else
        <flux:card class="p-0!">
            <flux:table :paginate="$coupons">
                <flux:table.columns>
                    <flux:table.column class="w-8"></flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'code'" :direction="$sortDirection" wire:click="sort('code')">Code</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection" wire:click="sort('name')">Name</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'type'" :direction="$sortDirection" wire:click="sort('type')">Type</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'redemptions_count'" :direction="$sortDirection" wire:click="sort('redemptions_count')">Redemptions</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'valid_until'" :direction="$sortDirection" wire:click="sort('valid_until')">Valid until</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'active'" :direction="$sortDirection" wire:click="sort('active')">Status</flux:table.column>
                    <flux:table.column class="w-16"></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($coupons as $coupon)
                        <flux:table.row :key="$coupon->id">
                            <flux:table.cell>
                                <flux:checkbox wire:model.live="selected" value="{{ $coupon->id }}" />
                            </flux:table.cell>
                            <flux:table.cell variant="strong" class="font-mono">{{ $coupon->code }}</flux:table.cell>
                            <flux:table.cell>{{ $coupon->name }}</flux:table.cell>
                            <flux:table.cell>
                                <x-mollie-billing::admin.enum-badge :value="$coupon->type" />
                            </flux:table.cell>
                            <flux:table.cell class="tabular-nums">
                                {{ $coupon->redemptions_count }}{{ $coupon->max_redemptions ? ' / '.$coupon->max_redemptions : '' }}
                            </flux:table.cell>
                            <flux:table.cell class="tabular-nums">{{ $coupon->valid_until?->format('Y-m-d') ?? '—' }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge :color="$coupon->active ? 'green' : 'zinc'" size="sm">
                                    {{ $coupon->active ? 'Active' : 'Inactive' }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:button size="xs" variant="ghost" icon="arrow-right" :href="route(BillingRoute::admin('coupons.show'), $coupon)">View</flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @endif
</div>
