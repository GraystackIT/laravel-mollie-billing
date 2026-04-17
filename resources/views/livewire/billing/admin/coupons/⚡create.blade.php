<?php

use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Support\BillingRoute;
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

    public ?string $error = null;

    public function save(CouponService $service)
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
            session()->flash('status', "Coupon {$attrs['code']} created.");
            return $this->redirectRoute(BillingRoute::admin('coupons.index'), navigate: true);
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }
};

?>

<div class="space-y-6">
    <x-mollie-billing::admin.page-header
        title="Create coupon"
        subtitle="Configure a coupon and its benefit. Fields below adapt to the selected type."
        :back="route(BillingRoute::admin('coupons.index'))"
        backLabel="Coupons"
    />

    <x-mollie-billing::admin.flash :error="$error" />

    <form wire:submit="save" class="space-y-6">
        <x-mollie-billing::admin.section title="Basics" description="Coupon identifier and the kind of benefit it grants.">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <flux:select wire:model.live="type" label="Coupon type" description="Determines which fields are required below.">
                    <flux:select.option value="first_payment">First payment discount</flux:select.option>
                    <flux:select.option value="recurring">Recurring discount</flux:select.option>
                    <flux:select.option value="credits">Credits</flux:select.option>
                    <flux:select.option value="trial_extension">Trial extension</flux:select.option>
                    <flux:select.option value="access_grant">Access grant</flux:select.option>
                </flux:select>
                <flux:input
                    wire:model="code"
                    label="Code"
                    description="Stored uppercased. Example: SUMMER25"
                    placeholder="SUMMER25"
                    required
                />
                <flux:input
                    wire:model="name"
                    label="Name"
                    description="Display label shown to operators. Defaults to the code."
                    class="md:col-span-2"
                />
            </div>

            <flux:textarea
                wire:model="description"
                label="Internal description"
                description="Optional. Only visible to admins."
                rows="2"
            />
        </x-mollie-billing::admin.section>

        <x-mollie-billing::admin.section title="Validity & limits" description="When the coupon can be used and how often.">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <flux:input type="datetime-local" wire:model="valid_from" label="Valid from" description="Leave empty to activate immediately." />
                <flux:input type="datetime-local" wire:model="valid_until" label="Valid until" description="Leave empty for no expiry." />
                <flux:input
                    type="number"
                    wire:model="max_redemptions"
                    label="Max total redemptions"
                    description="Across all billables. Empty = unlimited."
                    min="1"
                />
                <flux:input
                    type="number"
                    wire:model="max_redemptions_per_billable"
                    label="Max per billable"
                    description="How often a single billable can redeem this coupon."
                    min="1"
                />
            </div>

            <flux:separator />

            <div class="flex flex-wrap items-center gap-6">
                <flux:checkbox wire:model="active" label="Active" description="Inactive coupons cannot be redeemed." />
                <flux:checkbox wire:model="stackable" label="Stackable" description="Can combine with other coupons." />
            </div>
        </x-mollie-billing::admin.section>

        @if (in_array($type, ['first_payment', 'recurring']))
            <x-mollie-billing::admin.section
                title="Discount"
                :description="$type === 'first_payment' ? 'Applied to the first payment only.' : 'Applied to every recurring payment.'"
            >
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <flux:select wire:model.live="discount_type" label="Discount type">
                        <flux:select.option value="percentage">Percentage</flux:select.option>
                        <flux:select.option value="fixed">Fixed amount</flux:select.option>
                    </flux:select>
                    <flux:input
                        type="number"
                        wire:model="discount_value"
                        label="Value"
                        :description="$discount_type === 'percentage' ? 'Whole percent, 0–100.' : 'Amount in cents. Example: 500 = €5.00'"
                        :placeholder="$discount_type === 'percentage' ? '25' : '500'"
                        :suffix="$discount_type === 'percentage' ? '%' : 'cents'"
                        min="0"
                        :max="$discount_type === 'percentage' ? 100 : null"
                        required
                    />
                </div>

                <flux:input
                    type="number"
                    wire:model="minimum_order_amount_net"
                    label="Minimum order (net)"
                    description="Coupon only applies above this net amount. Empty = no minimum."
                    placeholder="1000"
                    suffix="cents"
                    min="0"
                />
            </x-mollie-billing::admin.section>
        @endif

        @if ($type === 'credits')
            <x-mollie-billing::admin.section
                title="Credits"
                description="Credits are issued via a dedicated credit grant flow. This coupon type currently has no additional configuration."
            >
                <flux:callout variant="secondary" icon="information-circle" inline>
                    Use the auto-apply token below to wire this coupon into a signup URL.
                </flux:callout>
            </x-mollie-billing::admin.section>
        @endif

        @if ($type === 'trial_extension')
            <x-mollie-billing::admin.section
                title="Trial extension"
                description="Extends the billable's trial by a fixed number of days from the moment the coupon is redeemed."
            >
                <flux:input
                    type="number"
                    wire:model="trial_extension_days"
                    label="Days"
                    description="Whole days to add. Example: 14"
                    placeholder="14"
                    suffix="days"
                    min="1"
                    required
                />
            </x-mollie-billing::admin.section>
        @endif

        @if ($type === 'access_grant')
            <x-mollie-billing::admin.section
                title="Access grant"
                description="Grants a plan (and optional addons) for a duration, free of charge."
            >
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <flux:select wire:model="grant_plan_code" label="Plan" description="Addon-only grants are also possible.">
                        <flux:select.option value="">— Addon-only (no plan) —</flux:select.option>
                        @foreach ($planOptions as $code => $name)
                            <flux:select.option value="{{ $code }}">{{ $name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="grant_interval" label="Interval" description="Required when a plan is set.">
                        <flux:select.option value="">—</flux:select.option>
                        <flux:select.option value="monthly">Monthly</flux:select.option>
                        <flux:select.option value="yearly">Yearly</flux:select.option>
                    </flux:select>
                    <flux:input
                        type="number"
                        wire:model="grant_duration_days"
                        label="Duration"
                        description="Length of the grant."
                        placeholder="30"
                        suffix="days"
                        min="1"
                    />
                </div>
            </x-mollie-billing::admin.section>
        @endif

        <x-mollie-billing::admin.section title="Advanced" description="Optional automation hooks.">
            <flux:input
                wire:model="auto_apply_token"
                label="Auto-apply token"
                description="If set, this coupon is auto-applied when the signup URL carries ?coupon=<token>. Use a short slug, e.g. welcome10."
                placeholder="welcome10"
            />
        </x-mollie-billing::admin.section>

        <div class="flex justify-end gap-2">
            <flux:button type="button" variant="ghost" :href="route(BillingRoute::admin('coupons.index'))">Cancel</flux:button>
            <flux:button type="submit" variant="primary" icon="check">Create coupon</flux:button>
        </div>
    </form>
</div>
