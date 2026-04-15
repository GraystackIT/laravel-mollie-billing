<?php

use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use Livewire\Component;

new class extends Component {
    public function with(SubscriptionCatalogInterface $catalog): array
    {
        return [
            'planOptions' => collect($catalog->allPlans())
                ->mapWithKeys(fn (string $code) => [$code => $catalog->planName($code) ?: $code])
                ->all(),
        ];
    }

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

<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Create coupon</flux:heading>
        <flux:button size="sm" variant="ghost" :href="route('billing.admin.coupons.index')" icon="arrow-left">Back</flux:button>
    </div>

    @if ($success)
        <flux:callout variant="success" icon="check-circle">{{ $success }}</flux:callout>
    @endif
    @if ($error)
        <flux:callout variant="danger" icon="exclamation-triangle">{{ $error }}</flux:callout>
    @endif

    <form wire:submit="save" class="space-y-6">
        <flux:card class="space-y-4">
            <div>
                <flux:heading size="md">Basics</flux:heading>
                <flux:text class="text-zinc-500">Coupon identifier and type of benefit.</flux:text>
            </div>

            <flux:separator />

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <flux:select wire:model.live="type" label="Coupon type" description="Determines which fields are required below.">
                    <flux:select.option value="first_payment">First payment discount</flux:select.option>
                    <flux:select.option value="recurring">Recurring discount</flux:select.option>
                    <flux:select.option value="credits">Credits</flux:select.option>
                    <flux:select.option value="trial_extension">Trial extension</flux:select.option>
                    <flux:select.option value="access_grant">Access grant</flux:select.option>
                </flux:select>
                <flux:input wire:model="code" label="Code" description="Stored uppercased." required />
                <flux:input wire:model="name" label="Name" description="Defaults to code." class="md:col-span-2" />
            </div>

            <flux:textarea wire:model="description" label="Description" rows="2" />
        </flux:card>

        <flux:card class="space-y-4">
            <div>
                <flux:heading size="md">Validity & limits</flux:heading>
                <flux:text class="text-zinc-500">When the coupon can be used and how often.</flux:text>
            </div>

            <flux:separator />

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <flux:input type="datetime-local" wire:model="valid_from" label="Valid from" />
                <flux:input type="datetime-local" wire:model="valid_until" label="Valid until" />
                <flux:input type="number" wire:model="max_redemptions" label="Max total redemptions" description="Empty = unlimited." />
                <flux:input type="number" wire:model="max_redemptions_per_billable" label="Max per billable" />
            </div>

            <flux:separator />

            <div class="flex flex-wrap gap-6 items-center">
                <flux:checkbox wire:model="active" label="Active" />
                <flux:checkbox wire:model="stackable" label="Stackable with other coupons" />
            </div>
        </flux:card>

        @if (in_array($type, ['first_payment', 'recurring']))
            <flux:card class="space-y-4">
                <div>
                    <flux:heading size="md">Discount</flux:heading>
                    <flux:text class="text-zinc-500">
                        @if ($type === 'first_payment')
                            Applied to the first payment only.
                        @else
                            Applied to every recurring payment.
                        @endif
                    </flux:text>
                </div>

                <flux:separator />

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <flux:select wire:model="discount_type" label="Discount type">
                        <flux:select.option value="percentage">Percentage</flux:select.option>
                        <flux:select.option value="fixed">Fixed amount</flux:select.option>
                    </flux:select>
                    <flux:input
                        type="number"
                        wire:model="discount_value"
                        label="Value"
                        :description="$discount_type === 'percentage' ? 'Percent (0–100).' : 'Amount in cents.'"
                        required
                    />
                </div>

                <flux:input type="number" wire:model="minimum_order_amount_net" label="Minimum order (net, cents)" description="Empty = no minimum." />
            </flux:card>
        @endif

        @if ($type === 'trial_extension')
            <flux:card class="space-y-4">
                <div>
                    <flux:heading size="md">Trial extension</flux:heading>
                    <flux:text class="text-zinc-500">Extends the billable's trial by a fixed number of days.</flux:text>
                </div>

                <flux:separator />

                <flux:input type="number" wire:model="trial_extension_days" label="Days" required />
            </flux:card>
        @endif

        @if ($type === 'access_grant')
            <flux:card class="space-y-4">
                <div>
                    <flux:heading size="md">Access grant</flux:heading>
                    <flux:text class="text-zinc-500">Grants a plan and/or addons for a duration without charge.</flux:text>
                </div>

                <flux:separator />

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <flux:select wire:model="grant_plan_code" label="Plan" placeholder="Addon-only (no plan)">
                        <flux:select.option value="">— Addon-only —</flux:select.option>
                        @foreach ($planOptions as $code => $name)
                            <flux:select.option value="{{ $code }}">{{ $name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="grant_interval" label="Interval" placeholder="—">
                        <flux:select.option value="monthly">Monthly</flux:select.option>
                        <flux:select.option value="yearly">Yearly</flux:select.option>
                    </flux:select>
                    <flux:input type="number" wire:model="grant_duration_days" label="Duration (days)" />
                </div>
            </flux:card>
        @endif

        <flux:card class="space-y-4">
            <div>
                <flux:heading size="md">Advanced</flux:heading>
                <flux:text class="text-zinc-500">Optional automation.</flux:text>
            </div>

            <flux:separator />

            <flux:input
                wire:model="auto_apply_token"
                label="Auto-apply token"
                description="If set, this coupon is auto-applied when the signup URL carries ?coupon=<token>."
            />
        </flux:card>

        <div class="flex gap-2 justify-end">
            <flux:button type="button" variant="ghost" :href="route('billing.admin.coupons.index')">Cancel</flux:button>
            <flux:button type="submit" variant="primary">Create coupon</flux:button>
        </div>
    </form>
</div>
