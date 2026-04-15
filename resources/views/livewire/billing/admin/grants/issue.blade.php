<?php

use GraystackIT\MollieBilling\Services\Billing\CouponService;
use Illuminate\Support\Str;
use Livewire\Component;

new class extends Component {
    public mixed $billableId = null;
    public string $mode = 'full';
    public string $planCode = '';
    public string $interval = 'monthly';
    public string $addonCodes = '';
    public int $durationDays = 30;
    public ?string $flash = null;

    public function mount(mixed $billable = null): void
    {
        $this->billableId = $billable;
    }

    public function issue(CouponService $service): void
    {
        $class = config('mollie-billing.billable_model');
        $b = $class?->find($this->billableId);
        if (! $b) { $this->flash = 'Billable not found.'; return; }

        $addons = array_filter(array_map('trim', explode(',', $this->addonCodes)));
        $code = 'GRANT-'.Str::upper(Str::random(8));
        try {
            $coupon = $this->mode === 'full'
                ? $service->accessGrantCoupon($code, $this->planCode, $this->interval, $addons, $this->durationDays)
                : $service->addonGrantCoupon($code, $addons);
            $service->redeem($coupon, $b, []);
            $this->flash = "Grant {$code} issued and redeemed.";
        } catch (\Throwable $e) {
            $this->flash = 'Error: '.$e->getMessage();
        }
    }
};

?>

<div class="p-6 space-y-4 max-w-xl">
    <flux:heading size="xl">Issue access grant</flux:heading>
    @if ($flash)<div class="p-3 rounded bg-green-50 border border-green-200 text-sm">{{ $flash }}</div>@endif
    <form wire:submit="issue" class="space-y-3 text-sm">
        <div>
            <label class="block">Mode</label>
            <select wire:model.live="mode" class="border rounded px-2 py-1 w-full">
                <option value="full">Full (Plan + Addons + Duration)</option>
                <option value="addon">Addon-only</option>
            </select>
        </div>
        @if ($mode === 'full')
            <div><label>Plan code</label><input wire:model="planCode" class="border rounded px-2 py-1 w-full" required></div>
            <div><label>Interval</label>
                <select wire:model="interval" class="border rounded px-2 py-1 w-full">
                    <option>monthly</option><option>yearly</option>
                </select>
            </div>
            <div><label>Duration (days)</label><input type="number" wire:model="durationDays" class="border rounded px-2 py-1 w-full"></div>
        @endif
        <div><label>Addon codes (comma-separated)</label><input wire:model="addonCodes" class="border rounded px-2 py-1 w-full"></div>
        <flux:button variant="primary">Issue</flux:button>
    </form>
</div>
