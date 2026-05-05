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
            'addonOptions' => collect($catalog->allAddons())
                ->mapWithKeys(fn (string $code) => [$code => $catalog->addonName($code) ?: $code])
                ->all(),
            'productOptions' => collect($catalog->allProducts())
                ->mapWithKeys(fn (string $code) => [$code => $catalog->productName($code) ?: $code])
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

    /** @var array<int, string> */
    public array $applicable_addons = [];
    /** @var array<int, string> */
    public array $applicable_products = [];

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
                'applicable_addons' => $this->applicable_addons !== [] ? array_values($this->applicable_addons) : null,
                'applicable_products' => $this->applicable_products !== [] ? array_values($this->applicable_products) : null,
            ], fn ($v) => $v !== null && $v !== '');

            $service->create($attrs);
            session()->flash('status', "Coupon {$attrs['code']} created.");
            return $this->redirectRoute(BillingRoute::admin('coupons.index'), navigate: true);
        } catch (\InvalidArgumentException $e) {
            // Domain-validation: surface the message directly so the admin can fix the input.
            $this->error = $e->getMessage();
        } catch (\Throwable $e) {
            report($e);
            $this->error = 'Error (Code: '.$e->getCode().')';
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
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3 md:items-start">
                <flux:select wire:model.live="type" label="Coupon type">
                    <flux:select.option value="first_payment">First payment discount</flux:select.option>
                    <flux:select.option value="recurring">Recurring discount</flux:select.option>
                    <flux:select.option value="credits">Credits</flux:select.option>
                    <flux:select.option value="trial_extension">Trial extension</flux:select.option>
                    <flux:select.option value="access_grant">Access grant</flux:select.option>
                    <flux:select.option value="period_extension">Period extension</flux:select.option>
                </flux:select>
                <flux:input
                    wire:model="code"
                    label="Code"
                    placeholder="SUMMER25"
                    required
                />
                <flux:input
                    wire:model="name"
                    label="Name"
                    placeholder="Defaults to the code"
                />
            </div>
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                The code is stored uppercased and shown on invoices. The coupon type determines which fields are required below — name is optional and falls back to the code.
            </flux:text>

            <flux:separator variant="subtle" />

            <flux:textarea
                wire:model="description"
                label="Internal description"
                description="Optional. Only visible to admins."
                rows="2"
            />
        </x-mollie-billing::admin.section>

        <x-mollie-billing::admin.section title="Validity & limits" description="When the coupon can be used and how often.">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-4 md:items-start">
                <flux:input type="datetime-local" wire:model="valid_from" label="Valid from" placeholder="Activate immediately" />
                <flux:input type="datetime-local" wire:model="valid_until" label="Valid until" placeholder="No expiry" />
                <flux:input
                    type="number"
                    wire:model="max_redemptions"
                    label="Max total redemptions"
                    placeholder="Unlimited"
                    min="1"
                />
                <flux:input
                    type="number"
                    wire:model="max_redemptions_per_billable"
                    label="Max per billable"
                    min="1"
                />
            </div>
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                @if ($type === 'recurring')
                    For recurring coupons, <span class="font-medium">max per billable</span> defines how many billing periods the discount applies — default <span class="tabular-nums">1</span> means the first recurring charge only (equivalent to a first-payment coupon). Use a higher number or set <span class="font-medium">valid until</span> for multi-period campaigns.
                @else
                    Leave dates empty to activate immediately and never expire. <span class="font-medium">Max total</span> caps redemptions across all billables; <span class="font-medium">max per billable</span> caps redemptions for a single billable.
                @endif
            </flux:text>

            <flux:separator variant="subtle" />

            <div class="flex flex-wrap items-center gap-6">
                <flux:checkbox wire:model="active" label="Active" description="Inactive coupons cannot be redeemed." />
                <flux:checkbox wire:model="stackable" label="Stackable" description="Can combine with other coupons." />
            </div>
        </x-mollie-billing::admin.section>

        @if (in_array($type, ['first_payment', 'recurring']))
            @php
                $discountValueDescription = $discount_type === 'percentage'
                    ? ($type === 'recurring'
                        ? 'Whole percent, 1–100. With 100 %, the subscription is free for the discount lifetime, then resumes at full price.'
                        : 'Whole percent, 1–99. For first-payment, 100 % is not supported — use an access_grant coupon instead.')
                    : 'Amount in cents. Example: 500 = €5.00';
            @endphp
            <x-mollie-billing::admin.section
                title="Discount"
                :description="$type === 'first_payment' ? 'Applied to the first payment only.' : 'Applied to every recurring payment.'"
            >
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3 md:items-start">
                    <flux:select wire:model.live="discount_type" label="Discount type">
                        <flux:select.option value="percentage">Percentage</flux:select.option>
                        <flux:select.option value="fixed">Fixed amount</flux:select.option>
                    </flux:select>
                    <flux:input
                        type="number"
                        wire:model="discount_value"
                        label="Value"
                        :placeholder="$discount_type === 'percentage' ? '25' : '500'"
                        :suffix="$discount_type === 'percentage' ? '%' : 'cents'"
                        min="1"
                        :max="$discount_type === 'percentage' ? ($type === 'recurring' ? 100 : 99) : null"
                        required
                    />
                    <flux:input
                        type="number"
                        wire:model="minimum_order_amount_net"
                        label="Minimum order (net)"
                        placeholder="No minimum"
                        suffix="cents"
                        min="0"
                    />
                </div>
                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                    {{ $discountValueDescription }} <span class="text-zinc-400 dark:text-zinc-500">·</span> Minimum order is the net amount (in cents) the order must reach for the coupon to apply.
                </flux:text>
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

        @if ($type === 'period_extension')
            <x-mollie-billing::admin.section
                title="Period extension"
                description="Pushes the next billing date forward by the given number of days. Works for both Local and Mollie subscriptions; the plan and all active addons keep running unchanged. We recommend keeping `stackable` disabled for this type."
            >
                <flux:input
                    type="number"
                    wire:model="grant_duration_days"
                    label="Extend by"
                    description="Number of days to extend the current billing period."
                    placeholder="14"
                    suffix="days"
                    min="1"
                    required
                />
            </x-mollie-billing::admin.section>
        @endif

        @if (! empty($addonOptions))
            <x-mollie-billing::admin.collapsible
                title="Applicable addons"
                description="Restrict the coupon to specific addons. Leave empty to allow any."
                :badge="count($applicable_addons) > 0 ? count($applicable_addons).' selected' : null"
                :open="count($applicable_addons) > 0"
            >
                <flux:checkbox.group wire:model="applicable_addons">
                    <div class="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($addonOptions as $code => $name)
                            <flux:checkbox value="{{ $code }}" label="{{ $name }}" />
                        @endforeach
                    </div>
                </flux:checkbox.group>
            </x-mollie-billing::admin.collapsible>
        @endif

        @if (! empty($productOptions))
            <x-mollie-billing::admin.collapsible
                title="Applicable products"
                description="Restrict the coupon to specific one-time-order products. Leave empty to allow any."
                :badge="count($applicable_products) > 0 ? count($applicable_products).' selected' : null"
                :open="count($applicable_products) > 0"
            >
                <flux:checkbox.group wire:model="applicable_products">
                    <div class="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($productOptions as $code => $name)
                            <flux:checkbox value="{{ $code }}" label="{{ $name }}" />
                        @endforeach
                    </div>
                </flux:checkbox.group>
            </x-mollie-billing::admin.collapsible>
        @endif

        <x-mollie-billing::admin.collapsible
            title="Advanced"
            description="Optional automation hooks."
            :open="!empty($auto_apply_token)"
        >
            <flux:input
                wire:model="auto_apply_token"
                label="Auto-apply token"
                description="If set, this coupon is auto-applied when the signup URL carries ?coupon=<token>. Use a short slug, e.g. welcome10."
                placeholder="welcome10"
            />
        </x-mollie-billing::admin.collapsible>

        <div class="flex justify-end gap-2">
            <flux:button type="button" variant="ghost" :href="route(BillingRoute::admin('coupons.index'))">Cancel</flux:button>
            <flux:button type="submit" variant="primary" icon="check">Create coupon</flux:button>
        </div>
    </form>
</div>
