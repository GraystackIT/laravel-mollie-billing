<?php

use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Support\BillingTime;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $sortBy = 'created_at';
    public string $sortDirection = 'desc';

    public array $selected = [];

    private const ALLOWED_SORTS = ['code', 'name', 'type', 'redemptions_count', 'valid_until', 'active', 'created_at'];

    public function updatingSearch(): void
    {
        $this->resetPage();
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

    public function deactivateSelected(CouponService $service): void
    {
        foreach (Coupon::query()->whereIn('id', $this->selected)->get() as $coupon) {
            $service->deactivate($coupon);
        }
        $this->selected = [];
        session()->flash('status', __('billing::admin.coupons.deactivated_selected'));
    }

    public function with(): array
    {
        $sortBy = in_array($this->sortBy, self::ALLOWED_SORTS, true) ? $this->sortBy : 'created_at';

        return [
            'coupons' => Coupon::query()
                ->when($this->search !== '', fn ($q) => $q
                    ->where(fn ($w) => $w
                        ->where('code', 'like', '%'.strtoupper($this->search).'%')
                        ->orWhere('name', 'like', '%'.$this->search.'%')))
                ->orderBy($sortBy, $this->sortDirection)
                ->paginate(20),
        ];
    }
};

?>

<div class="space-y-6">
    <x-mollie-billing::admin.page-header :title="__('billing::admin.coupons.title')" :subtitle="__('billing::admin.coupons.subtitle')">
        <x-slot:actions>
            <flux:button variant="primary" size="sm" icon="plus" :href="route(BillingRoute::admin('coupons.create'))">{{ __('billing::admin.coupons.new') }}</flux:button>
        </x-slot:actions>
    </x-mollie-billing::admin.page-header>

    <x-mollie-billing::admin.flash />

    <div class="flex flex-wrap items-center gap-2">
        <flux:input
            type="search"
            wire:model.live.debounce.300ms="search"
            :placeholder="__('billing::admin.coupons.search')"
            icon="magnifying-glass"
            class="flex-1 basis-64"
        />
        @if (count($selected))
            <flux:button size="sm" variant="danger" icon="no-symbol" wire:click="deactivateSelected" class="shrink-0">
                {{ __('billing::admin.coupons.deactivate_selected', ['count' => count($selected)]) }}
            </flux:button>
        @endif
    </div>

    @if ($coupons->isEmpty())
        <flux:card>
            <x-mollie-billing::admin.empty
                icon="ticket"
                :title="__('billing::admin.coupons.empty_title')"
                :description="__('billing::admin.coupons.empty_description')"
            >
                <x-slot:cta>
                    <flux:button variant="primary" size="sm" icon="plus" :href="route(BillingRoute::admin('coupons.create'))">{{ __('billing::admin.coupons.new') }}</flux:button>
                </x-slot:cta>
            </x-mollie-billing::admin.empty>
        </flux:card>
    @else
        <flux:card class="p-0! sm:px-6! sm:py-2!">
            <flux:table :paginate="$coupons">
                <flux:table.columns>
                    <flux:table.column class="w-8"></flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'code'" :direction="$sortDirection" wire:click="sort('code')">{{ __('billing::admin.coupons.column.code') }}</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection" wire:click="sort('name')">{{ __('billing::admin.coupons.column.name') }}</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'type'" :direction="$sortDirection" wire:click="sort('type')">{{ __('billing::admin.coupons.column.type') }}</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'redemptions_count'" :direction="$sortDirection" wire:click="sort('redemptions_count')">{{ __('billing::admin.coupons.column.redemptions') }}</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'valid_until'" :direction="$sortDirection" wire:click="sort('valid_until')">{{ __('billing::admin.coupons.column.valid_until') }}</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'active'" :direction="$sortDirection" wire:click="sort('active')">{{ __('billing::admin.coupons.column.status') }}</flux:table.column>
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
                            <flux:table.cell class="tabular-nums">{{ BillingTime::displayUtc($coupon->valid_until)?->format('Y-m-d') ?? '—' }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge :color="$coupon->active ? 'green' : 'zinc'" size="sm">
                                    {{ $coupon->active ? __('billing::admin.common.active') : __('billing::admin.common.inactive') }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:button size="xs" variant="ghost" icon="arrow-right" :href="route(BillingRoute::admin('coupons.show'), $coupon)">{{ __('billing::admin.common.view') }}</flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @endif
</div>
