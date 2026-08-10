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
            'usageTypeOptions' => $catalog->allUsageTypes(),
        ];
    }

    public string $type = 'single_payment';
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
    public array $grant_addon_codes = [];

    /** @var array<string, int|string|null> */
    public array $credits_payload = [];

    /** @var array<int, string> */
    public array $applicable_addons = [];
    /** @var array<int, string> */
    public array $applicable_products = [];

    public ?string $error = null;

    public function save(CouponService $service)
    {
        $this->error = null;
        try {
            $creditsPayload = [];
            foreach ($this->credits_payload as $usageType => $quantity) {
                $qty = (int) $quantity;
                if ($qty > 0) {
                    $creditsPayload[(string) $usageType] = $qty;
                }
            }

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
                'grant_addon_codes' => $this->grant_addon_codes !== [] ? array_values($this->grant_addon_codes) : null,
                'credits_payload' => $creditsPayload !== [] ? $creditsPayload : null,
                'applicable_addons' => $this->applicable_addons !== [] ? array_values($this->applicable_addons) : null,
                'applicable_products' => $this->applicable_products !== [] ? array_values($this->applicable_products) : null,
            ], fn ($v) => $v !== null && $v !== '');

            $service->create($attrs);
            session()->flash('status', __('billing::admin.coupons.create.created', ['code' => $attrs['code']]));
            return $this->redirectRoute(BillingRoute::admin('coupons.index'), navigate: true);
        } catch (\InvalidArgumentException $e) {
            // Domain-validation: surface the message directly so the admin can fix the input.
            $this->error = $e->getMessage();
        } catch (\Throwable $e) {
            report($e);
            $this->error = __('billing::admin.common.error_generic', ['code' => $e->getCode()]);
        }
    }
};

?>

<div class="space-y-6">
    <x-mollie-billing::admin.page-header
        :title="__('billing::admin.coupons.create.title')"
        :subtitle="__('billing::admin.coupons.create.subtitle')"
        :back="route(BillingRoute::admin('coupons.index'))"
        :backLabel="__('billing::admin.nav.coupons')"
    />

    <x-mollie-billing::admin.flash :error="$error" :errorHeading="__('billing::admin.coupons.create.error_heading')" />

    <form wire:submit="save" class="space-y-6">
        <x-mollie-billing::admin.section :title="__('billing::admin.coupons.create.basics_title')" :description="__('billing::admin.coupons.create.basics_description')">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3 md:items-start">
                <flux:select wire:model.live="type" :label="__('billing::admin.coupons.create.coupon_type')">
                    <flux:select.option value="single_payment">{{ __('billing::admin.coupons.type.single_payment') }}</flux:select.option>
                    <flux:select.option value="recurring">{{ __('billing::admin.coupons.type.recurring') }}</flux:select.option>
                    <flux:select.option value="credits">{{ __('billing::admin.coupons.type.credits') }}</flux:select.option>
                    <flux:select.option value="trial_extension">{{ __('billing::admin.coupons.type.trial_extension') }}</flux:select.option>
                    <flux:select.option value="access_grant">{{ __('billing::admin.coupons.type.access_grant') }}</flux:select.option>
                    <flux:select.option value="period_extension">{{ __('billing::admin.coupons.type.period_extension') }}</flux:select.option>
                </flux:select>
                <flux:input
                    wire:model="code"
                    :label="__('billing::admin.coupons.create.code')"
                    placeholder="SUMMER25"
                    required
                />
                <flux:input
                    wire:model="name"
                    :label="__('billing::admin.coupons.create.name')"
                    :placeholder="__('billing::admin.coupons.create.name_placeholder')"
                />
            </div>
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                {{ __('billing::admin.coupons.create.basics_hint') }}
            </flux:text>

            <flux:separator variant="subtle" />

            <flux:textarea
                wire:model="description"
                :label="__('billing::admin.coupons.create.description')"
                :description="__('billing::admin.coupons.create.description_hint')"
                rows="2"
            />
        </x-mollie-billing::admin.section>

        <x-mollie-billing::admin.section :title="__('billing::admin.coupons.create.validity_title')" :description="__('billing::admin.coupons.create.validity_description')">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-4 md:items-start">
                <flux:input type="datetime-local" wire:model="valid_from" :label="__('billing::admin.coupons.create.valid_from')" :placeholder="__('billing::admin.coupons.create.valid_from_placeholder')" />
                <flux:input type="datetime-local" wire:model="valid_until" :label="__('billing::admin.coupons.create.valid_until')" :placeholder="__('billing::admin.coupons.create.valid_until_placeholder')" />
                <flux:input
                    type="number"
                    wire:model="max_redemptions"
                    :label="__('billing::admin.coupons.create.max_redemptions')"
                    :placeholder="__('billing::admin.coupons.create.max_redemptions_placeholder')"
                    min="1"
                />
                <flux:input
                    type="number"
                    wire:model="max_redemptions_per_billable"
                    :label="__('billing::admin.coupons.create.max_per_billable')"
                    min="1"
                />
            </div>
            @php
                // The emphasis markup lives here, not in the translation: lang files are
                // publishable, so an app-side edit must not be able to inject HTML. The
                // translation is escaped first, then our own tokens are swapped for markup.
                $emphasize = fn (string $text) => '<span class="font-medium">'.e($text).'</span>';
                $validityHint = strtr(
                    e(__($type === 'recurring'
                        ? 'billing::admin.coupons.create.validity_hint_recurring'
                        : 'billing::admin.coupons.create.validity_hint_default')),
                    [
                        ':max_total' => $emphasize(__('billing::admin.coupons.create.max_redemptions')),
                        ':max_per_billable' => $emphasize(__('billing::admin.coupons.create.max_per_billable')),
                        ':valid_until' => $emphasize(__('billing::admin.coupons.create.valid_until')),
                        ':default_one' => '<span class="tabular-nums">1</span>',
                    ],
                );
            @endphp
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                {!! $validityHint !!}
            </flux:text>

            <flux:separator variant="subtle" />

            <div class="flex flex-wrap items-center gap-6">
                <flux:checkbox wire:model="active" :label="__('billing::admin.coupons.create.active')" :description="__('billing::admin.coupons.create.active_description')" />
                <flux:checkbox wire:model="stackable" :label="__('billing::admin.coupons.create.stackable')" :description="__('billing::admin.coupons.create.stackable_description')" />
            </div>
        </x-mollie-billing::admin.section>

        @if (in_array($type, ['single_payment', 'recurring']))
            @php
                $discountValueDescription = $discount_type === 'percentage'
                    ? ($type === 'recurring'
                        ? __('billing::admin.coupons.create.discount_hint_percentage_recurring')
                        : __('billing::admin.coupons.create.discount_hint_percentage_single'))
                    : __('billing::admin.coupons.create.discount_hint_fixed', [
                        'example' => \GraystackIT\MollieBilling\Support\BillingMoney::format(500),
                    ]);
            @endphp
            <x-mollie-billing::admin.section
                :title="__('billing::admin.coupons.create.discount_title')"
                :description="$type === 'single_payment'
                    ? __('billing::admin.coupons.create.discount_description_single')
                    : __('billing::admin.coupons.create.discount_description_recurring')"
            >
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3 md:items-start">
                    <flux:select wire:model.live="discount_type" :label="__('billing::admin.coupons.create.discount_type')">
                        <flux:select.option value="percentage">{{ __('billing::admin.coupons.create.discount_percentage') }}</flux:select.option>
                        <flux:select.option value="fixed">{{ __('billing::admin.coupons.create.discount_fixed') }}</flux:select.option>
                    </flux:select>
                    <flux:input
                        type="number"
                        wire:model="discount_value"
                        :label="__('billing::admin.coupons.create.discount_value')"
                        :placeholder="$discount_type === 'percentage' ? '25' : '500'"
                        :suffix="$discount_type === 'percentage' ? '%' : __('billing::admin.coupons.create.cents')"
                        min="1"
                        :max="$discount_type === 'percentage' ? 100 : null"
                        required
                    />
                    <flux:input
                        type="number"
                        wire:model="minimum_order_amount_net"
                        :label="__('billing::admin.coupons.create.minimum_order')"
                        :placeholder="__('billing::admin.coupons.create.minimum_order_placeholder')"
                        :suffix="__('billing::admin.coupons.create.cents')"
                        min="0"
                    />
                </div>
                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                    {{ $discountValueDescription }} <span class="text-zinc-400 dark:text-zinc-500">·</span> {{ __('billing::admin.coupons.create.minimum_order_hint') }}
                </flux:text>
            </x-mollie-billing::admin.section>
        @endif

        @if ($type === 'credits')
            <x-mollie-billing::admin.section
                :title="__('billing::admin.coupons.create.credits_title')"
                :description="__('billing::admin.coupons.create.credits_description')"
            >
                @if (empty($usageTypeOptions))
                    @php
                        // Same reasoning as the validity hint: escape the translation, then
                        // substitute our own token with the <code> markup.
                        $noUsageTypesHint = strtr(e(__('billing::admin.coupons.create.credits_no_usage_types')), [
                            ':config_keys' => '<code>included_usages</code> / <code>usage_overage_prices</code>',
                        ]);
                    @endphp
                    <flux:callout variant="warning" icon="exclamation-triangle" inline>
                        {!! $noUsageTypesHint !!}
                    </flux:callout>
                @else
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($usageTypeOptions as $usageType)
                            <flux:input
                                type="number"
                                wire:model="credits_payload.{{ $usageType }}"
                                :label="$usageType"
                                placeholder="0"
                                :suffix="$usageType"
                                min="0"
                            />
                        @endforeach
                    </div>
                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                        {{ __('billing::admin.coupons.create.credits_hint') }}
                    </flux:text>
                @endif
            </x-mollie-billing::admin.section>
        @endif

        @if ($type === 'trial_extension')
            <x-mollie-billing::admin.section
                :title="__('billing::admin.coupons.create.trial_title')"
                :description="__('billing::admin.coupons.create.trial_description')"
            >
                <flux:input
                    type="number"
                    wire:model="trial_extension_days"
                    :label="__('billing::admin.coupons.create.trial_days')"
                    :description="__('billing::admin.coupons.create.trial_days_description')"
                    placeholder="14"
                    :suffix="__('billing::admin.common.days')"
                    min="1"
                    required
                />
            </x-mollie-billing::admin.section>
        @endif

        @if ($type === 'access_grant')
            <x-mollie-billing::admin.section
                :title="__('billing::admin.coupons.create.grant_title')"
                :description="__('billing::admin.coupons.create.grant_description')"
            >
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <flux:select wire:model.live="grant_plan_code" :label="__('billing::admin.common.plan')" :description="__('billing::admin.coupons.create.grant_plan_description')">
                        <flux:select.option value="">{{ __('billing::admin.coupons.create.grant_plan_none') }}</flux:select.option>
                        @foreach ($planOptions as $code => $name)
                            <flux:select.option value="{{ $code }}">{{ $name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="grant_interval" :label="__('billing::admin.common.interval')" :description="__('billing::admin.coupons.create.grant_interval_description')">
                        <flux:select.option value="">—</flux:select.option>
                        <flux:select.option value="monthly">{{ __('billing::admin.common.monthly') }}</flux:select.option>
                        <flux:select.option value="yearly">{{ __('billing::admin.common.yearly') }}</flux:select.option>
                    </flux:select>
                    <flux:input
                        type="number"
                        wire:model="grant_duration_days"
                        :label="__('billing::admin.coupons.create.grant_duration')"
                        :description="__('billing::admin.coupons.create.grant_duration_description')"
                        placeholder="30"
                        :suffix="__('billing::admin.common.days')"
                        min="1"
                    />
                </div>

                @if (! empty($addonOptions))
                    <flux:separator variant="subtle" />

                    <flux:field>
                        <flux:label>{{ __('billing::admin.coupons.create.granted_addons') }}</flux:label>
                        <flux:description>
                            {{ empty($grant_plan_code)
                                ? __('billing::admin.coupons.create.granted_addons_required')
                                : __('billing::admin.coupons.create.granted_addons_optional') }}
                        </flux:description>
                        <flux:checkbox.group wire:model="grant_addon_codes">
                            <div class="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2 lg:grid-cols-3">
                                @foreach ($addonOptions as $code => $name)
                                    <flux:checkbox value="{{ $code }}" label="{{ $name }}" />
                                @endforeach
                            </div>
                        </flux:checkbox.group>
                    </flux:field>
                @endif
            </x-mollie-billing::admin.section>
        @endif

        @if ($type === 'period_extension')
            <x-mollie-billing::admin.section
                :title="__('billing::admin.coupons.create.period_title')"
                :description="__('billing::admin.coupons.create.period_description')"
            >
                <flux:input
                    type="number"
                    wire:model="grant_duration_days"
                    :label="__('billing::admin.coupons.create.period_extend_by')"
                    :description="__('billing::admin.coupons.create.period_extend_by_description')"
                    placeholder="14"
                    :suffix="__('billing::admin.common.days')"
                    min="1"
                    required
                />
            </x-mollie-billing::admin.section>
        @endif

        @if (! empty($addonOptions) && in_array($type, ['single_payment', 'recurring']))
            <x-mollie-billing::admin.collapsible
                :title="__('billing::admin.coupons.create.applicable_addons')"
                :description="__('billing::admin.coupons.create.applicable_addons_description')"
                :badge="count($applicable_addons) > 0 ? __('billing::admin.coupons.create.selected_count', ['count' => count($applicable_addons)]) : null"
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

        @if (! empty($productOptions) && in_array($type, ['single_payment', 'recurring']))
            <x-mollie-billing::admin.collapsible
                :title="__('billing::admin.coupons.create.applicable_products')"
                :description="__('billing::admin.coupons.create.applicable_products_description')"
                :badge="count($applicable_products) > 0 ? __('billing::admin.coupons.create.selected_count', ['count' => count($applicable_products)]) : null"
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
            :title="__('billing::admin.coupons.create.advanced')"
            :description="__('billing::admin.coupons.create.advanced_description')"
            :open="!empty($auto_apply_token)"
        >
            <flux:input
                wire:model="auto_apply_token"
                :label="__('billing::admin.coupons.create.auto_apply_token')"
                :description="__('billing::admin.coupons.create.auto_apply_token_description')"
                placeholder="welcome10"
            />
        </x-mollie-billing::admin.collapsible>

        <div class="flex justify-end gap-2">
            <flux:button type="button" variant="ghost" :href="route(BillingRoute::admin('coupons.index'))">{{ __('billing::admin.common.cancel') }}</flux:button>
            <flux:button type="submit" variant="primary" icon="check">{{ __('billing::admin.coupons.create.submit') }}</flux:button>
        </div>
    </form>
</div>
