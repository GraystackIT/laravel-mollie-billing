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

    /**
     * What the operator wants to give: a discount, free time or credits. It only steers
     * `type` — the seven coupon types stay what everything below and CouponService read.
     * Setting `type` directly derives it (see updatedType()).
     */
    public string $intent = 'discount';

    public string $type = 'single_payment';

    /**
     * The access grant without a plan. A special case, reachable from "Advanced" only:
     * under "free time" a grant is the product, and its plan is not optional.
     */
    public bool $addonOnlyGrant = false;

    /** The kinds each intent offers, in the order they are shown. */
    private const KINDS = [
        'discount' => ['single_payment', 'recurring'],
        'free_time' => ['trial_extension', 'period_extension', 'plan_waiver', 'access_grant'],
        'credits' => ['credits'],
    ];

    public function mount(): void
    {
        $this->intent = self::intentOf($this->type);
    }

    private static function intentOf(string $type): string
    {
        foreach (self::KINDS as $intent => $kinds) {
            if (in_array($type, $kinds, true)) {
                return $intent;
            }
        }

        return 'discount';
    }

    public function updatedIntent(string $value): void
    {
        if (! isset(self::KINDS[$value])) {
            $this->intent = self::intentOf($this->type);

            return;
        }

        // A kind that already belongs to the intent stays; otherwise its first one, so
        // the form below is never without a type.
        if (! in_array($this->type, self::KINDS[$value], true)) {
            $this->type = self::KINDS[$value][0];
            $this->addonOnlyGrant = false;
        }
    }

    public function updatedType(string $value): void
    {
        $this->intent = self::intentOf($value);

        if ($value !== 'access_grant') {
            $this->addonOnlyGrant = false;
        }
    }

    public function updatedAddonOnlyGrant(bool $value): void
    {
        if ($value) {
            $this->type = 'access_grant';
            $this->intent = 'free_time';
        }
    }
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
    /** Recurring only: how many charges are discounted. Empty: until `valid_until`. */
    public ?int $discount_periods = null;
    public ?int $trial_extension_days = null;
    public ?string $grant_plan_code = null;
    public ?string $grant_interval = null;
    public ?int $grant_duration_days = null;
    public string $grant_on_expiry = 'end';
    public ?int $waiver_periods = null;

    /** @var array<int, string> */
    public array $grant_addon_codes = [];

    /** @var array<string, int|string|null> */
    public array $credits_payload = [];

    /** @var array<int, string> */
    public array $applicable_plans = [];
    /** @var array<int, string> */
    public array $applicable_intervals = [];
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

            // The plan / interval / addon / product restrictions are offered for the two
            // discount kinds only. Ticked under one of them and the kind then switched, they
            // stayed in component state, out of sight — and were saved onto a credits or
            // free-time code that `CouponService::validate()` then refused on every order
            // naming another plan, or none.
            $restrictable = in_array($this->type, ['single_payment', 'recurring'], true);

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
                // Only meaningful for a full grant; harmless otherwise.
                'grant_on_expiry' => $this->type === 'access_grant' && ! empty($this->grant_plan_code) ? $this->grant_on_expiry : null,
                'waiver_periods' => $this->type === 'plan_waiver' ? $this->waiver_periods : null,
                'credits_payload' => $creditsPayload !== [] ? $creditsPayload : null,
                'applicable_plans' => $restrictable && $this->applicable_plans !== [] ? array_values($this->applicable_plans) : null,
                'applicable_intervals' => $restrictable && $this->applicable_intervals !== [] ? array_values($this->applicable_intervals) : null,
                'applicable_addons' => $restrictable && $this->applicable_addons !== [] ? array_values($this->applicable_addons) : null,
                'applicable_products' => $restrictable && $this->applicable_products !== [] ? array_values($this->applicable_products) : null,
            ], fn ($v) => $v !== null && $v !== '');

            // Always passed for a recurring coupon, also when empty: without the key
            // CouponService reads the per-customer limit as the period count it used to
            // be (how coupons created in code are kept working) — and this form always
            // submits that limit.
            if ($this->type === 'recurring') {
                $attrs['discount_periods'] = $this->discount_periods ?: null;
            }

            // An addon-only grant has no plan, interval or duration. Whatever an earlier
            // choice left in those fields must not turn it into a full grant.
            if ($this->type === 'access_grant' && $this->addonOnlyGrant) {
                unset($attrs['grant_plan_code'], $attrs['grant_interval'], $attrs['grant_duration_days'], $attrs['grant_on_expiry']);
            }

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
            {{-- First what the operator wants to give, then which kind of it. The intent
                 only steers `type`. --}}
            <x-mollie-billing::admin.labelled :label="__('billing::admin.coupons.create.intent_label')" :info="__('billing::admin.coupons.create.info.intent')">
                <flux:radio.group wire:model.live="intent" variant="cards" class="max-md:flex-col">
                    @foreach (['discount' => 'receipt-percent', 'free_time' => 'gift', 'credits' => 'circle-stack'] as $intentKey => $intentIcon)
                        <flux:radio value="{{ $intentKey }}" class="items-start">
                            <flux:icon :name="$intentIcon" class="mt-0.5 shrink-0" />
                            <div class="flex-1">
                                <flux:heading class="leading-5">{{ __('billing::admin.coupons.intent.'.$intentKey) }}</flux:heading>
                                <flux:text size="sm" class="mt-1">{{ __('billing::admin.coupons.intent_info.'.$intentKey) }}</flux:text>
                            </div>
                            <flux:radio.indicator />
                        </flux:radio>
                    @endforeach
                </flux:radio.group>
            </x-mollie-billing::admin.labelled>

            @php
                $kinds = [
                    'discount' => ['single_payment', 'recurring'],
                    'free_time' => ['trial_extension', 'period_extension', 'plan_waiver', 'access_grant'],
                    'credits' => [],
                ][$intent] ?? [];
            @endphp

            <div class="grid grid-cols-1 gap-4 md:grid-cols-3 md:items-start">
                @if ($kinds !== [])
                    <x-mollie-billing::admin.labelled :label="__('billing::admin.coupons.create.kind_label')" :info="__('billing::admin.coupons.create.info.kind')">
                        <flux:select wire:model.live="type">
                            @foreach ($kinds as $kind)
                                <flux:select.option value="{{ $kind }}">{{ __('billing::admin.coupons.kind.'.$kind) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </x-mollie-billing::admin.labelled>
                @endif
                <x-mollie-billing::admin.labelled :label="__('billing::admin.coupons.create.code')" :info="__('billing::admin.coupons.create.info.code')" required>
                    <flux:input wire:model="code" placeholder="SUMMER25" required />
                </x-mollie-billing::admin.labelled>
                <x-mollie-billing::admin.labelled :label="__('billing::admin.coupons.create.name')" :info="__('billing::admin.coupons.create.info.name')">
                    <flux:input wire:model="name" :placeholder="__('billing::admin.coupons.create.name_placeholder')" />
                </x-mollie-billing::admin.labelled>
            </div>

            {{-- What the chosen type does, in full and always visible: the type decides
                 everything else on this form. --}}
            <flux:callout icon="information-circle" color="zinc" inline>
                <flux:callout.heading>{{ __('billing::admin.coupons.type.'.$type) }}</flux:callout.heading>
                <flux:callout.text>{{ __('billing::admin.coupons.type_info.'.$type) }}</flux:callout.text>
            </flux:callout>

            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                {{ __('billing::admin.coupons.create.basics_hint') }}
            </flux:text>

            <flux:separator variant="subtle" />

            <x-mollie-billing::admin.labelled
                :label="__('billing::admin.coupons.create.description')"
                :info="__('billing::admin.coupons.create.info.description')"
                :description="__('billing::admin.coupons.create.description_hint')"
            >
                <flux:textarea wire:model="description" rows="2" />
            </x-mollie-billing::admin.labelled>
        </x-mollie-billing::admin.section>

        <x-mollie-billing::admin.section :title="__('billing::admin.coupons.create.validity_title')" :description="__('billing::admin.coupons.create.validity_description')">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-4 md:items-start">
                <x-mollie-billing::admin.labelled :label="__('billing::admin.coupons.create.valid_from').' (UTC)'" :info="__('billing::admin.coupons.create.info.valid_from')">
                    <flux:input type="datetime-local" wire:model="valid_from" :placeholder="__('billing::admin.coupons.create.valid_from_placeholder')" />
                </x-mollie-billing::admin.labelled>
                <x-mollie-billing::admin.labelled
                    :label="__('billing::admin.coupons.create.valid_until').' (UTC)'"
                    :info="$type === 'recurring' ? __('billing::admin.coupons.create.info.valid_until_recurring') : __('billing::admin.coupons.create.info.valid_until')"
                >
                    <flux:input type="datetime-local" wire:model="valid_until" :placeholder="__('billing::admin.coupons.create.valid_until_placeholder')" />
                </x-mollie-billing::admin.labelled>
                <x-mollie-billing::admin.labelled :label="__('billing::admin.coupons.create.max_redemptions')" :info="__('billing::admin.coupons.create.info.max_redemptions')">
                    <flux:input type="number" wire:model="max_redemptions" :placeholder="__('billing::admin.coupons.create.max_redemptions_placeholder')" min="1" />
                </x-mollie-billing::admin.labelled>
                <x-mollie-billing::admin.labelled
                    :label="__('billing::admin.coupons.create.max_per_billable')"
                    :info="__('billing::admin.coupons.create.info.max_per_billable').($type === 'recurring' ? ' '.__('billing::admin.coupons.create.info.max_per_billable_recurring') : '')"
                >
                    <flux:input type="number" wire:model="max_redemptions_per_billable" min="1" />
                </x-mollie-billing::admin.labelled>
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

            <div class="flex flex-wrap items-start gap-6">
                <div class="flex items-start gap-1">
                    <flux:checkbox wire:model="active" :label="__('billing::admin.coupons.create.active')" :description="__('billing::admin.coupons.create.active_description')" />
                    <x-mollie-billing::admin.info :text="__('billing::admin.coupons.create.info.active')" />
                </div>
                <div class="flex items-start gap-1">
                    <flux:checkbox wire:model="stackable" :label="__('billing::admin.coupons.create.stackable')" :description="__('billing::admin.coupons.create.stackable_description')" />
                    <x-mollie-billing::admin.info :text="__('billing::admin.coupons.create.info.stackable')" />
                </div>
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
                <div class="grid grid-cols-1 gap-4 {{ $type === 'recurring' ? 'md:grid-cols-2 lg:grid-cols-4' : 'md:grid-cols-3' }} md:items-start">
                    <x-mollie-billing::admin.labelled :label="__('billing::admin.coupons.create.discount_type')" :info="__('billing::admin.coupons.create.info.discount_type')">
                        <flux:select wire:model.live="discount_type">
                            <flux:select.option value="percentage">{{ __('billing::admin.coupons.create.discount_percentage') }}</flux:select.option>
                            <flux:select.option value="fixed">{{ __('billing::admin.coupons.create.discount_fixed') }}</flux:select.option>
                        </flux:select>
                    </x-mollie-billing::admin.labelled>
                    <x-mollie-billing::admin.labelled
                        :label="__('billing::admin.coupons.create.discount_value')"
                        :info="$type === 'recurring' ? __('billing::admin.coupons.create.info.discount_value_recurring') : __('billing::admin.coupons.create.info.discount_value_single')"
                        required
                    >
                        <flux:input
                            type="number"
                            wire:model="discount_value"
                            :placeholder="$discount_type === 'percentage' ? '25' : '500'"
                            :suffix="$discount_type === 'percentage' ? '%' : __('billing::admin.coupons.create.cents')"
                            min="1"
                            :max="$discount_type === 'percentage' ? 100 : null"
                            required
                        />
                    </x-mollie-billing::admin.labelled>
                    @if ($type === 'recurring')
                        <x-mollie-billing::admin.labelled
                            :label="__('billing::admin.coupons.create.discount_periods')"
                            :info="__('billing::admin.coupons.create.info.discount_periods')"
                            :description="__('billing::admin.coupons.create.discount_periods_description')"
                        >
                            <flux:input
                                type="number"
                                wire:model="discount_periods"
                                :placeholder="__('billing::admin.coupons.create.discount_periods_placeholder')"
                                :suffix="__('billing::admin.billables.subscription.free_time.periods')"
                                min="1"
                            />
                        </x-mollie-billing::admin.labelled>
                    @endif
                    <x-mollie-billing::admin.labelled :label="__('billing::admin.coupons.create.minimum_order')" :info="__('billing::admin.coupons.create.info.minimum_order')">
                        <flux:input
                            type="number"
                            wire:model="minimum_order_amount_net"
                            :placeholder="__('billing::admin.coupons.create.minimum_order_placeholder')"
                            :suffix="__('billing::admin.coupons.create.cents')"
                            min="0"
                        />
                    </x-mollie-billing::admin.labelled>
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
                            <x-mollie-billing::admin.labelled :label="$usageType" :info="__('billing::admin.coupons.create.info.credits')">
                                <flux:input
                                    type="number"
                                    wire:model="credits_payload.{{ $usageType }}"
                                    placeholder="0"
                                    :suffix="$usageType"
                                    min="0"
                                />
                            </x-mollie-billing::admin.labelled>
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
                <x-mollie-billing::admin.labelled
                    :label="__('billing::admin.coupons.create.trial_days')"
                    :info="__('billing::admin.coupons.create.info.trial_days')"
                    :description="__('billing::admin.coupons.create.trial_days_description')"
                    required
                >
                    <flux:input type="number" wire:model="trial_extension_days" placeholder="14" :suffix="__('billing::admin.common.days')" min="1" required />
                </x-mollie-billing::admin.labelled>
            </x-mollie-billing::admin.section>
        @endif

        @if ($type === 'access_grant')
            <x-mollie-billing::admin.section
                :title="__('billing::admin.coupons.create.grant_title')"
                :description="__('billing::admin.coupons.create.grant_description')"
            >
                @if (! $addonOnlyGrant)
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <x-mollie-billing::admin.labelled
                        :label="__('billing::admin.common.plan')"
                        :info="__('billing::admin.coupons.create.info.grant_plan')"
                        :description="__('billing::admin.coupons.create.grant_plan_description')"
                    >
                        <flux:select wire:model.live="grant_plan_code">
                            <flux:select.option value="">—</flux:select.option>
                            @foreach ($planOptions as $code => $name)
                                <flux:select.option value="{{ $code }}">{{ $name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </x-mollie-billing::admin.labelled>
                    <x-mollie-billing::admin.labelled
                        :label="__('billing::admin.common.interval')"
                        :info="__('billing::admin.coupons.create.info.grant_interval')"
                        :description="__('billing::admin.coupons.create.grant_interval_description')"
                    >
                        <flux:select wire:model="grant_interval">
                            <flux:select.option value="">—</flux:select.option>
                            <flux:select.option value="monthly">{{ __('billing::admin.common.monthly') }}</flux:select.option>
                            <flux:select.option value="yearly">{{ __('billing::admin.common.yearly') }}</flux:select.option>
                        </flux:select>
                    </x-mollie-billing::admin.labelled>
                    <x-mollie-billing::admin.labelled
                        :label="__('billing::admin.coupons.create.grant_duration')"
                        :info="__('billing::admin.coupons.create.info.grant_duration')"
                        :description="__('billing::admin.coupons.create.grant_duration_description')"
                    >
                        <flux:input type="number" wire:model="grant_duration_days" placeholder="30" :suffix="__('billing::admin.common.days')" min="1" />
                    </x-mollie-billing::admin.labelled>
                </div>

                @endif

                @if (! $addonOnlyGrant && ! empty($grant_plan_code))
                    <x-mollie-billing::admin.labelled
                        :label="__('billing::admin.grants.on_expiry_label')"
                        :info="__('billing::admin.grants.on_expiry_info')"
                        class="md:max-w-sm"
                    >
                        <flux:select wire:model="grant_on_expiry">
                            <flux:select.option value="end">{{ __('billing::admin.grants.on_expiry.end') }}</flux:select.option>
                            <flux:select.option value="continue_paid">{{ __('billing::admin.grants.on_expiry.continue_paid') }}</flux:select.option>
                        </flux:select>
                    </x-mollie-billing::admin.labelled>
                @endif

                @if (! empty($addonOptions))
                    <flux:separator variant="subtle" />

                    <x-mollie-billing::admin.labelled
                        :label="__('billing::admin.coupons.create.granted_addons')"
                        :info="__('billing::admin.coupons.create.info.granted_addons')"
                        :description="($addonOnlyGrant || empty($grant_plan_code))
                            ? __('billing::admin.coupons.create.granted_addons_required')
                            : __('billing::admin.coupons.create.granted_addons_optional')"
                    >
                        <flux:checkbox.group wire:model="grant_addon_codes">
                            <div class="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2 lg:grid-cols-3">
                                @foreach ($addonOptions as $code => $name)
                                    <flux:checkbox value="{{ $code }}" label="{{ $name }}" />
                                @endforeach
                            </div>
                        </flux:checkbox.group>
                    </x-mollie-billing::admin.labelled>
                @endif
            </x-mollie-billing::admin.section>
        @endif

        @if ($type === 'period_extension')
            <x-mollie-billing::admin.section
                :title="__('billing::admin.coupons.create.period_title')"
                :description="__('billing::admin.coupons.create.period_description')"
            >
                <x-mollie-billing::admin.labelled
                    :label="__('billing::admin.coupons.create.period_extend_by')"
                    :info="__('billing::admin.coupons.create.info.period_extend_by')"
                    :description="__('billing::admin.coupons.create.period_extend_by_description')"
                    required
                >
                    <flux:input type="number" wire:model="grant_duration_days" placeholder="14" :suffix="__('billing::admin.common.days')" min="1" required />
                </x-mollie-billing::admin.labelled>
            </x-mollie-billing::admin.section>
        @endif

        @if ($type === 'plan_waiver')
            <x-mollie-billing::admin.section
                :title="__('billing::admin.coupons.create.waiver_title')"
                :description="__('billing::admin.coupons.create.waiver_description')"
            >
                <x-mollie-billing::admin.labelled
                    :label="__('billing::admin.coupons.create.waiver_periods')"
                    :info="__('billing::admin.coupons.create.info.waiver_periods')"
                    :description="__('billing::admin.coupons.create.waiver_periods_description')"
                    required
                >
                    <flux:input type="number" wire:model="waiver_periods" placeholder="3" :suffix="__('billing::admin.billables.subscription.free_time.periods')" min="1" required />
                </x-mollie-billing::admin.labelled>
            </x-mollie-billing::admin.section>
        @endif

        {{-- Plan and interval restrict where a discount applies. A coupon restricted to a
             plan is refused on every order that names another plan — or none, such as a
             product purchase. --}}
        @if (in_array($type, ['single_payment', 'recurring']))
            <x-mollie-billing::admin.collapsible
                :title="__('billing::admin.coupons.create.applicable_plans')"
                :description="__('billing::admin.coupons.create.applicable_plans_description')"
                :badge="(count($applicable_plans) + count($applicable_intervals)) > 0 ? __('billing::admin.coupons.create.selected_count', ['count' => count($applicable_plans) + count($applicable_intervals)]) : null"
                :open="(count($applicable_plans) + count($applicable_intervals)) > 0"
            >
                <div class="flex items-center gap-1">
                    <flux:text size="sm">{{ __('billing::admin.coupons.create.info.what_this_does') }}</flux:text>
                    <x-mollie-billing::admin.info :text="__('billing::admin.coupons.create.info.applicable_plans')" />
                </div>
                <flux:checkbox.group wire:model="applicable_plans">
                    <div class="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($planOptions as $code => $name)
                            <flux:checkbox value="{{ $code }}" label="{{ $name }}" />
                        @endforeach
                    </div>
                </flux:checkbox.group>

                <flux:separator variant="subtle" />

                <div class="flex items-center gap-1">
                    <flux:text size="sm">{{ __('billing::admin.coupons.create.applicable_intervals') }}</flux:text>
                    <x-mollie-billing::admin.info :text="__('billing::admin.coupons.create.info.applicable_intervals')" />
                </div>
                <flux:checkbox.group wire:model="applicable_intervals">
                    <div class="flex flex-wrap gap-x-6 gap-y-2">
                        <flux:checkbox value="monthly" :label="__('billing::admin.common.monthly')" />
                        <flux:checkbox value="yearly" :label="__('billing::admin.common.yearly')" />
                    </div>
                </flux:checkbox.group>
            </x-mollie-billing::admin.collapsible>
        @endif

        @if (! empty($addonOptions) && in_array($type, ['single_payment', 'recurring']))
            <x-mollie-billing::admin.collapsible
                :title="__('billing::admin.coupons.create.applicable_addons')"
                :description="__('billing::admin.coupons.create.applicable_addons_description')"
                :badge="count($applicable_addons) > 0 ? __('billing::admin.coupons.create.selected_count', ['count' => count($applicable_addons)]) : null"
                :open="count($applicable_addons) > 0"
            >
                <div class="flex items-center gap-1">
                    <flux:text size="sm">{{ __('billing::admin.coupons.create.info.what_this_does') }}</flux:text>
                    <x-mollie-billing::admin.info :text="__('billing::admin.coupons.create.info.applicable_addons')" />
                </div>
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
                <div class="flex items-center gap-1">
                    <flux:text size="sm">{{ __('billing::admin.coupons.create.info.what_this_does') }}</flux:text>
                    <x-mollie-billing::admin.info :text="__('billing::admin.coupons.create.info.applicable_products')" />
                </div>
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
            :open="!empty($auto_apply_token) || $addonOnlyGrant"
        >
            <div class="flex items-start gap-1">
                <flux:field variant="inline">
                    <flux:checkbox wire:model.live="addonOnlyGrant" />
                    <flux:label>{{ __('billing::admin.coupons.create.addon_only_grant') }}</flux:label>
                    <flux:description>{{ __('billing::admin.coupons.create.addon_only_grant_description') }}</flux:description>
                </flux:field>
                <x-mollie-billing::admin.info :text="__('billing::admin.coupons.create.info.addon_only_grant')" />
            </div>

            <x-mollie-billing::admin.labelled
                :label="__('billing::admin.coupons.create.auto_apply_token')"
                :info="__('billing::admin.coupons.create.info.auto_apply_token')"
                :description="__('billing::admin.coupons.create.auto_apply_token_description')"
            >
                <flux:input wire:model="auto_apply_token" placeholder="welcome10" />
            </x-mollie-billing::admin.labelled>
        </x-mollie-billing::admin.collapsible>

        <div class="flex justify-end gap-2">
            <flux:button type="button" variant="ghost" :href="route(BillingRoute::admin('coupons.index'))">{{ __('billing::admin.common.cancel') }}</flux:button>
            <flux:button type="submit" variant="primary" icon="check">{{ __('billing::admin.coupons.create.submit') }}</flux:button>
        </div>
    </form>
</div>
