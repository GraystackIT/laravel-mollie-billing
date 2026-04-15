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
        <p class="text-zinc-500">Coupon not found.</p>
    @else
        <flux:heading size="xl">{{ $coupon->code }}</flux:heading>
        @if ($flash)<div class="p-2 rounded bg-green-50 border border-green-200">{{ $flash }}</div>@endif
        <dl class="grid grid-cols-2 gap-3 text-sm">
            <dt class="text-zinc-500">Type</dt><dd>{{ $coupon->type?->value }}</dd>
            <dt class="text-zinc-500">Active</dt><dd>{{ $coupon->active ? 'yes' : 'no' }}</dd>
            <dt class="text-zinc-500">Redemptions</dt><dd>{{ $coupon->redemptions_count }}{{ $coupon->max_redemptions ? ' / '.$coupon->max_redemptions : '' }}</dd>
            <dt class="text-zinc-500">Valid until</dt><dd>{{ $coupon->valid_until?->format('Y-m-d') ?? '—' }}</dd>
            <dt class="text-zinc-500">Auto-apply token</dt><dd>{{ $coupon->auto_apply_token ?? '—' }}</dd>
        </dl>
        <div class="flex gap-2">
            <button wire:click="deactivate" class="px-3 py-1.5 border rounded">Deactivate</button>
            <button wire:click="delete" wire:confirm="Delete this coupon?" class="px-3 py-1.5 border rounded text-red-600">Delete</button>
        </div>
        <h2 class="font-medium mt-4">Redemption history</h2>
        <table class="w-full text-sm border">
            <thead class="bg-zinc-50 text-left"><tr><th class="p-2">Applied</th><th class="p-2">Billable</th><th class="p-2">Discount net</th></tr></thead>
            <tbody>
                @foreach ($coupon->redemptions()->latest('applied_at')->limit(30)->get() as $r)
                    <tr class="border-t">
                        <td class="p-2">{{ $r->applied_at?->format('Y-m-d H:i') }}</td>
                        <td class="p-2">{{ class_basename($r->billable_type) }}#{{ $r->billable_id }}</td>
                        <td class="p-2">{{ number_format($r->discount_amount_net / 100, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
