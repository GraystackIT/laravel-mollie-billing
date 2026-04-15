<?php

use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use Livewire\Component;

new class extends Component {
    public ?Coupon $coupon = null;
    public ?string $flash = null;

    public function mount(mixed $coupon = null): void
    {
        if ($coupon !== null) {
            $this->coupon = Coupon::find($coupon);
        }
    }

    public function deactivate(CouponService $service): void
    {
        if ($this->coupon) { $service->deactivate($this->coupon); $this->coupon->refresh(); $this->flash = 'Deactivated.'; }
    }

    public function delete(CouponService $service): void
    {
        if ($this->coupon) {
            try { $service->delete($this->coupon); $this->redirectRoute('billing.admin.coupons.index'); }
            catch (\Throwable $e) { $this->flash = 'Error: '.$e->getMessage(); }
        }
    }
};

?>

<div class="p-6 space-y-4 max-w-3xl">
    @if (! $coupon)
        <flux:text class="text-zinc-500">Coupon not found.</flux:text>
    @else
        <flux:heading size="xl">{{ $coupon->code }}</flux:heading>

        @if ($flash)
            <flux:callout variant="success" icon="check-circle">{{ $flash }}</flux:callout>
        @endif

        <dl class="grid grid-cols-2 gap-3 text-sm">
            <dt class="text-zinc-500 dark:text-zinc-400">Type</dt><dd>{{ $coupon->type?->value }}</dd>
            <dt class="text-zinc-500 dark:text-zinc-400">Active</dt><dd>
                <flux:badge :color="$coupon->active ? 'green' : 'zinc'" size="sm">{{ $coupon->active ? 'yes' : 'no' }}</flux:badge>
            </dd>
            <dt class="text-zinc-500 dark:text-zinc-400">Redemptions</dt><dd>{{ $coupon->redemptions_count }}{{ $coupon->max_redemptions ? ' / '.$coupon->max_redemptions : '' }}</dd>
            <dt class="text-zinc-500 dark:text-zinc-400">Valid from</dt><dd>{{ $coupon->valid_from?->format('Y-m-d H:i') ?? '—' }}</dd>
            <dt class="text-zinc-500 dark:text-zinc-400">Valid until</dt><dd>{{ $coupon->valid_until?->format('Y-m-d H:i') ?? '—' }}</dd>
            <dt class="text-zinc-500 dark:text-zinc-400">Auto-apply token</dt><dd>{{ $coupon->auto_apply_token ?? '—' }}</dd>
        </dl>

        <div class="flex gap-2">
            <flux:button size="sm" wire:click="deactivate">Deactivate</flux:button>
            <flux:button size="sm" variant="danger" wire:click="delete" wire:confirm="Delete this coupon?">Delete</flux:button>
        </div>

        <flux:heading size="md" class="mt-4">Redemption history</flux:heading>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Applied</flux:table.column>
                <flux:table.column>Billable</flux:table.column>
                <flux:table.column align="end">Discount net</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($coupon->redemptions()->latest('applied_at')->limit(30)->get() as $r)
                    <flux:table.row :key="$r->id">
                        <flux:table.cell>{{ $r->applied_at?->format('Y-m-d H:i') }}</flux:table.cell>
                        <flux:table.cell variant="strong">{{ class_basename($r->billable_type) }}#{{ $r->billable_id }}</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($r->discount_amount_net / 100, 2) }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="3" align="center">No redemptions yet.</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    @endif
</div>
