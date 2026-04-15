<?php

use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';

    public array $selected = [];

    public function updatingSearch(): void
    {
        $this->resetPage();
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
                ->latest()
                ->paginate(20),
        ];
    }
};

?>

<div class="p-6 space-y-4">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Coupons</flux:heading>
        <a href="{{ route('billing.admin.coupons.create') }}" class="px-3 py-1.5 rounded bg-indigo-600 text-white text-sm">New coupon</a>
    </div>

    @if (session('status'))
        <div class="p-3 rounded bg-green-50 border border-green-200 text-green-800 text-sm">{{ session('status') }}</div>
    @endif

    <div class="flex gap-2">
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search by code or name"
            class="px-3 py-1.5 border rounded w-full max-w-md">
        @if (count($selected))
            <button wire:click="deactivateSelected" class="px-3 py-1.5 border rounded text-sm">Deactivate selected ({{ count($selected) }})</button>
        @endif
    </div>

    <table class="w-full text-sm border">
        <thead class="bg-zinc-50 dark:bg-zinc-800 text-left">
            <tr>
                <th class="p-2 w-8"></th>
                <th class="p-2">Code</th>
                <th class="p-2">Name</th>
                <th class="p-2">Type</th>
                <th class="p-2">Redemptions</th>
                <th class="p-2">Valid until</th>
                <th class="p-2">Active</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($coupons as $coupon)
                <tr class="border-t">
                    <td class="p-2"><input type="checkbox" wire:model.live="selected" value="{{ $coupon->id }}"></td>
                    <td class="p-2 font-mono">{{ $coupon->code }}</td>
                    <td class="p-2">{{ $coupon->name }}</td>
                    <td class="p-2">{{ $coupon->type?->value }}</td>
                    <td class="p-2">{{ $coupon->redemptions_count }}{{ $coupon->max_redemptions ? ' / '.$coupon->max_redemptions : '' }}</td>
                    <td class="p-2">{{ $coupon->valid_until?->format('Y-m-d') ?? '—' }}</td>
                    <td class="p-2">{{ $coupon->active ? 'yes' : 'no' }}</td>
                    <td class="p-2"><a href="{{ route('billing.admin.coupons.show', $coupon) }}" class="text-indigo-600">View</a></td>
                </tr>
            @empty
                <tr><td colspan="8" class="p-4 text-center text-zinc-500">No coupons yet.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div>{{ $coupons->links() }}</div>
</div>
