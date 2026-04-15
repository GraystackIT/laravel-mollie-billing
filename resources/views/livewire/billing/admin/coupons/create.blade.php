<?php

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use Livewire\Component;

new class extends Component {
    public string $type = 'first_payment';
    public string $code = '';
    public string $name = '';
    public ?string $description = null;
    public ?string $valid_from = null;
    public ?string $valid_until = null;
    public bool $active = true;
    public bool $stackable = false;
    public ?int $max_redemptions = null;
    public int $max_redemptions_per_billable = 1;
    public ?string $auto_apply_token = null;
    public ?int $minimum_order_amount_net = null;

    public ?string $discount_type = 'percentage';
    public ?int $discount_value = null;
    public ?int $trial_extension_days = null;
    public ?string $grant_plan_code = null;
    public ?string $grant_interval = null;
    public ?int $grant_duration_days = null;

    public ?string $success = null;
    public ?string $error = null;

    public function save(CouponService $service): void
    {
        $this->error = null;
        try {
            $attrs = array_filter([
                'code' => strtoupper(trim($this->code)),
                'name' => $this->name ?: $this->code,
                'description' => $this->description,
                'type' => $this->type,
                'valid_from' => $this->valid_from,
                'valid_until' => $this->valid_until,
                'active' => $this->active,
                'stackable' => $this->stackable,
                'max_redemptions' => $this->max_redemptions,
                'max_redemptions_per_billable' => $this->max_redemptions_per_billable,
                'auto_apply_token' => $this->auto_apply_token,
                'minimum_order_amount_net' => $this->minimum_order_amount_net,
                'discount_type' => $this->discount_type,
                'discount_value' => $this->discount_value,
                'trial_extension_days' => $this->trial_extension_days,
                'grant_plan_code' => $this->grant_plan_code,
                'grant_interval' => $this->grant_interval,
                'grant_duration_days' => $this->grant_duration_days,
            ], fn ($v) => $v !== null && $v !== '');

            $service->create($attrs);
            $this->success = "Coupon {$attrs['code']} created.";
            $this->reset(['code', 'discount_value', 'trial_extension_days', 'grant_plan_code', 'grant_interval', 'grant_duration_days']);
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }
};

?>

<div class="p-6 space-y-4 max-w-2xl">
    <flux:heading size="xl">Create coupon</flux:heading>

    @if ($success)<div class="p-3 rounded bg-green-50 border border-green-200 text-green-800 text-sm">{{ $success }}</div>@endif
    @if ($error)<div class="p-3 rounded bg-red-50 border border-red-200 text-red-800 text-sm">{{ $error }}</div>@endif

    <form wire:submit="save" class="space-y-4">
        <div>
            <label class="block text-sm font-medium">Type</label>
            <select wire:model.live="type" class="border rounded px-2 py-1.5 w-full">
                <option value="first_payment">first_payment</option>
                <option value="recurring">recurring</option>
                <option value="credits">credits</option>
                <option value="trial_extension">trial_extension</option>
                <option value="access_grant">access_grant</option>
            </select>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div><label class="block text-sm">Code</label><input wire:model="code" class="border rounded px-2 py-1.5 w-full" required></div>
            <div><label class="block text-sm">Name</label><input wire:model="name" class="border rounded px-2 py-1.5 w-full"></div>
            <div><label class="block text-sm">Valid until</label><input type="datetime-local" wire:model="valid_until" class="border rounded px-2 py-1.5 w-full"></div>
            <div><label class="block text-sm">Max per billable</label><input type="number" wire:model="max_redemptions_per_billable" class="border rounded px-2 py-1.5 w-full"></div>
        </div>

        <div class="flex gap-4 items-center text-sm">
            <label><input type="checkbox" wire:model="active"> Active</label>
            <label><input type="checkbox" wire:model="stackable"> Stackable</label>
        </div>

        @if (in_array($type, ['first_payment', 'recurring']))
            <fieldset class="border rounded p-3 space-y-2">
                <legend class="px-1 text-sm font-medium">Discount</legend>
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="block text-sm">Discount type</label>
                        <select wire:model="discount_type" class="border rounded px-2 py-1.5 w-full">
                            <option value="percentage">percentage</option>
                            <option value="fixed">fixed</option>
                        </select>
                    </div>
                    <div><label class="block text-sm">Value</label><input type="number" wire:model="discount_value" class="border rounded px-2 py-1.5 w-full" required></div>
                </div>
            </fieldset>
        @endif

        @if ($type === 'trial_extension')
            <div><label class="block text-sm">Trial extension days</label><input type="number" wire:model="trial_extension_days" class="border rounded px-2 py-1.5 w-full" required></div>
        @endif

        @if ($type === 'access_grant')
            <fieldset class="border rounded p-3 space-y-2">
                <legend class="px-1 text-sm font-medium">Access grant</legend>
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="block text-sm">Plan code (empty = addon-only)</label><input wire:model="grant_plan_code" class="border rounded px-2 py-1.5 w-full"></div>
                    <div><label class="block text-sm">Interval</label><input wire:model="grant_interval" class="border rounded px-2 py-1.5 w-full" placeholder="monthly|yearly"></div>
                    <div><label class="block text-sm">Duration days</label><input type="number" wire:model="grant_duration_days" class="border rounded px-2 py-1.5 w-full"></div>
                </div>
            </fieldset>
        @endif

        <flux:button variant="primary">Create</flux:button>
    </form>
</div>
