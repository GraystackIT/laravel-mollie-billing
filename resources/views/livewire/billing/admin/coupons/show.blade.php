<?php

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Support\BillingTime;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    /**
     * Locked: deactivate() and delete() act on whatever this points at. Livewire
     * re-resolves a model property from the checksummed snapshot rather than from
     * the incoming value, so this turns a silently ignored retarget into a refusal.
     */
    #[Locked]
    public ?Coupon $coupon = null;
    public ?string $flash = null;
    public ?string $error = null;

    public function mount(array $routeParameters = []): void
    {
        $id = $routeParameters['coupon'] ?? null;
        if ($id !== null) {
            $this->coupon = Coupon::find($id);
        }
    }

    public function deactivate(CouponService $service): void
    {
        if ($this->coupon) {
            $service->deactivate($this->coupon);
            $this->coupon->refresh();
            $this->flash = __('billing::admin.coupons.show.deactivated');
        }
    }

    public function delete(CouponService $service)
    {
        if (! $this->coupon) return;
        try {
            $service->delete($this->coupon);
            session()->flash('status', __('billing::admin.coupons.show.deleted'));
            return $this->redirectRoute(BillingRoute::admin('coupons.index'), navigate: true);
        } catch (\Throwable $e) {
            report($e);
            $this->error = __('billing::admin.coupons.show.delete_failed');
        }
    }
};

?>

<div class="space-y-6">
    @if (! $coupon)
        <x-mollie-billing::admin.page-header
            :title="__('billing::admin.coupons.show.not_found_title')"
            :back="route(BillingRoute::admin('coupons.index'))"
            :backLabel="__('billing::admin.nav.coupons')"
        />
        <flux:card>
            <x-mollie-billing::admin.empty
                icon="ticket"
                :title="__('billing::admin.coupons.show.not_found_title')"
                :description="__('billing::admin.coupons.show.not_found_description')"
            />
        </flux:card>
    @else
        @php
            $type = $coupon->type;
            $isExpired = $coupon->valid_until && $coupon->valid_until->isPast();
            $isUsedUp = $coupon->max_redemptions !== null && $coupon->redemptions_count >= $coupon->max_redemptions;
            $redemptionPct = $coupon->max_redemptions
                ? min(100, round(($coupon->redemptions_count / $coupon->max_redemptions) * 100))
                : null;
        @endphp

        <div class="space-y-2">
            <flux:button :href="route(BillingRoute::admin('coupons.index'))" size="xs" variant="ghost" icon="arrow-left" class="-ml-2">{{ __('billing::admin.nav.coupons') }}</flux:button>

            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0 space-y-2">
                    <div class="flex flex-wrap items-center gap-3">
                        <flux:heading size="xl" class="font-mono">{{ $coupon->code }}</flux:heading>
                        <x-mollie-billing::admin.enum-badge :value="$coupon->type" />
                        @if ($coupon->active && ! $isExpired && ! $isUsedUp)
                            <flux:badge color="green" size="sm">{{ __('billing::admin.common.active') }}</flux:badge>
                        @elseif ($isExpired)
                            <flux:badge color="zinc" size="sm">{{ __('billing::admin.coupons.show.expired') }}</flux:badge>
                        @elseif ($isUsedUp)
                            <flux:badge color="zinc" size="sm">{{ __('billing::admin.coupons.show.used_up') }}</flux:badge>
                        @else
                            <flux:badge color="zinc" size="sm">{{ __('billing::admin.common.inactive') }}</flux:badge>
                        @endif
                        @if ($coupon->stackable)
                            <flux:badge color="blue" size="sm" icon="squares-plus">{{ __('billing::admin.coupons.show.stackable') }}</flux:badge>
                        @endif
                    </div>
                    @if ($coupon->name && $coupon->name !== $coupon->code)
                        <flux:text class="text-zinc-600 dark:text-zinc-400">{{ $coupon->name }}</flux:text>
                    @endif
                    @if ($coupon->description)
                        <flux:text class="max-w-2xl text-zinc-500 dark:text-zinc-400">{{ $coupon->description }}</flux:text>
                    @endif
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    @if ($coupon->active)
                        <flux:button size="sm" icon="no-symbol" wire:click="deactivate" wire:confirm="{{ __('billing::admin.coupons.show.deactivate_confirm') }}">{{ __('billing::admin.coupons.show.deactivate') }}</flux:button>
                    @endif
                    <flux:button size="sm" variant="danger" icon="trash" wire:click="delete" wire:confirm="{{ __('billing::admin.coupons.show.delete_confirm') }}">{{ __('billing::admin.coupons.show.delete') }}</flux:button>
                </div>
            </div>
        </div>

        <x-mollie-billing::admin.flash :success="$flash" :error="$error" />

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <x-mollie-billing::admin.stat
                :label="__('billing::admin.coupons.show.redemptions')"
                :value="$coupon->redemptions_count.($coupon->max_redemptions ? ' / '.$coupon->max_redemptions : '')"
                icon="ticket"
                :hint="$redemptionPct !== null
                    ? __('billing::admin.coupons.show.redemptions_used', ['percent' => $redemptionPct])
                    : __('billing::admin.coupons.show.redemptions_no_cap')"
            />
            <x-mollie-billing::admin.stat
                :label="__('billing::admin.coupons.show.max_per_billable')"
                :value="$coupon->max_redemptions_per_billable ?? '∞'"
                icon="user"
            />
            <x-mollie-billing::admin.stat
                :label="__('billing::admin.coupons.show.valid_until')"
                :value="BillingTime::displayUtc($coupon->valid_until)?->format('Y-m-d') ?? __('billing::admin.coupons.show.no_expiry')"
                icon="calendar"
                :tone="$isExpired ? 'danger' : null"
                :hint="$coupon->valid_until ? $coupon->valid_until->diffForHumans() : null"
            />
        </div>

        <x-mollie-billing::admin.section :title="__('billing::admin.coupons.show.benefit_title')" :description="__('billing::admin.coupons.show.benefit_description')">
            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3">
                <x-mollie-billing::admin.detail :label="__('billing::admin.coupons.show.type')">
                    <x-mollie-billing::admin.enum-badge :value="$coupon->type" />
                </x-mollie-billing::admin.detail>

                @if (in_array($type, [CouponType::SinglePayment, CouponType::Recurring]))
                    <x-mollie-billing::admin.detail :label="__('billing::admin.coupons.show.discount_type')">
                        {{ $coupon->discount_type->label() }}
                    </x-mollie-billing::admin.detail>
                    <x-mollie-billing::admin.detail :label="__('billing::admin.coupons.show.discount_value')" mono>
                        @if ($coupon->discount_type === DiscountType::Percentage)
                            {{ $coupon->discount_value }}%
                        @else
                            <x-mollie-billing::admin.money :cents="$coupon->discount_value ?? 0" :currency="$coupon->currency" />
                        @endif
                    </x-mollie-billing::admin.detail>
                    @if ($coupon->minimum_order_amount_net)
                        <x-mollie-billing::admin.detail :label="__('billing::admin.coupons.show.minimum_order')" mono>
                            <x-mollie-billing::admin.money :cents="$coupon->minimum_order_amount_net" :currency="$coupon->currency" />
                        </x-mollie-billing::admin.detail>
                    @endif
                @endif

                @if ($type === CouponType::TrialExtension)
                    <x-mollie-billing::admin.detail :label="__('billing::admin.coupons.show.extends_trial_by')" mono>
                        {{ $coupon->trial_extension_days }} {{ __('billing::admin.common.days') }}
                    </x-mollie-billing::admin.detail>
                @endif

                @if ($type === CouponType::PeriodExtension)
                    <x-mollie-billing::admin.detail :label="__('billing::admin.coupons.show.period_extension')" mono>
                        {{ $coupon->grant_duration_days }} {{ __('billing::admin.common.days') }}
                    </x-mollie-billing::admin.detail>
                @endif

                @if ($type === CouponType::AccessGrant)
                    <x-mollie-billing::admin.detail :label="__('billing::admin.common.plan')" mono>
                        {{ $coupon->grant_plan_code ?? __('billing::admin.coupons.show.plan_addon_only') }}
                    </x-mollie-billing::admin.detail>
                    @if ($coupon->grant_interval)
                        <x-mollie-billing::admin.detail :label="__('billing::admin.common.interval')">
                            {{ __('billing::enums.subscription_interval.'.$coupon->grant_interval) }}
                        </x-mollie-billing::admin.detail>
                    @endif
                    @if ($coupon->grant_duration_days)
                        <x-mollie-billing::admin.detail :label="__('billing::admin.coupons.show.duration')" mono>
                            {{ $coupon->grant_duration_days }} {{ __('billing::admin.common.days') }}
                        </x-mollie-billing::admin.detail>
                    @endif
                    @if (! empty($coupon->grant_addon_codes))
                        <x-mollie-billing::admin.detail :label="__('billing::admin.coupons.show.addons')" mono>
                            {{ implode(', ', $coupon->grant_addon_codes) }}
                        </x-mollie-billing::admin.detail>
                    @endif
                @endif

                @if ($type === CouponType::Credits && ! empty($coupon->credits_payload))
                    <x-mollie-billing::admin.detail :label="__('billing::admin.coupons.show.credits')" mono>
                        @foreach ($coupon->credits_payload as $usage => $units)
                            <div>{{ $units }} × {{ $usage }}</div>
                        @endforeach
                    </x-mollie-billing::admin.detail>
                @endif

                @if (! empty($coupon->applicable_addons))
                    <x-mollie-billing::admin.detail :label="__('billing::admin.coupons.show.applicable_addons')" mono>
                        {{ implode(', ', (array) $coupon->applicable_addons) }}
                    </x-mollie-billing::admin.detail>
                @endif

                @if (! empty($coupon->applicable_products))
                    <x-mollie-billing::admin.detail :label="__('billing::admin.coupons.show.applicable_products')" mono>
                        {{ implode(', ', (array) $coupon->applicable_products) }}
                    </x-mollie-billing::admin.detail>
                @endif
            </dl>
        </x-mollie-billing::admin.section>

        <x-mollie-billing::admin.section :title="__('billing::admin.coupons.show.validity_title')">
            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3">
                <x-mollie-billing::admin.detail :label="__('billing::admin.coupons.show.valid_from_utc')" mono>
                    {{ BillingTime::displayUtc($coupon->valid_from)?->format('Y-m-d H:i') ?? __('billing::admin.coupons.show.immediately') }}
                </x-mollie-billing::admin.detail>
                <x-mollie-billing::admin.detail :label="__('billing::admin.coupons.show.valid_until_utc')" mono>
                    {{ BillingTime::displayUtc($coupon->valid_until)?->format('Y-m-d H:i') ?? __('billing::admin.coupons.show.no_expiry') }}
                </x-mollie-billing::admin.detail>
                <x-mollie-billing::admin.detail :label="__('billing::admin.coupons.show.redemptions')" mono>
                    {{ $coupon->redemptions_count }}{{ $coupon->max_redemptions ? ' / '.$coupon->max_redemptions : ' / ∞' }}
                </x-mollie-billing::admin.detail>
                <x-mollie-billing::admin.detail :label="__('billing::admin.coupons.show.max_per_billable')" mono>
                    {{ $coupon->max_redemptions_per_billable ?? '∞' }}
                </x-mollie-billing::admin.detail>
                @if ($coupon->type === \GraystackIT\MollieBilling\Enums\CouponType::Recurring)
                    <x-mollie-billing::admin.detail :label="__('billing::admin.coupons.show.discount_periods')" mono>
                        {{ $coupon->discount_periods ?? __('billing::admin.coupons.show.discount_periods_none') }}
                    </x-mollie-billing::admin.detail>
                @endif
                <x-mollie-billing::admin.detail :label="__('billing::admin.coupons.show.stackable')">
                    <flux:badge :color="$coupon->stackable ? 'blue' : 'zinc'" size="sm">{{ $coupon->stackable ? __('billing::admin.common.yes') : __('billing::admin.common.no') }}</flux:badge>
                </x-mollie-billing::admin.detail>
                <x-mollie-billing::admin.detail :label="__('billing::admin.coupons.show.auto_apply_token')" mono>
                    {{ $coupon->auto_apply_token ?? '—' }}
                </x-mollie-billing::admin.detail>
            </dl>
        </x-mollie-billing::admin.section>

        <x-mollie-billing::admin.section
            :title="__('billing::admin.coupons.show.history_title')"
            :description="trans_choice('billing::admin.coupons.show.history_description', $coupon->redemptions_count, ['count' => $coupon->redemptions_count])"
        >
            @php $redemptions = $coupon->redemptions()->latest('applied_at')->limit(30)->get(); @endphp
            @if ($redemptions->isEmpty())
                <x-mollie-billing::admin.empty
                    icon="receipt-percent"
                    :title="__('billing::admin.coupons.show.history_empty_title')"
                    :description="__('billing::admin.coupons.show.history_empty_description')"
                />
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('billing::admin.coupons.show.history_applied') }}</flux:table.column>
                        <flux:table.column>{{ __('billing::admin.coupons.show.history_billable') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('billing::admin.coupons.show.history_discount') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($redemptions as $r)
                            <flux:table.row :key="$r->id">
                                <flux:table.cell class="tabular-nums">{{ BillingTime::displayUtc($r->applied_at)?->format('Y-m-d H:i') }}</flux:table.cell>
                                <flux:table.cell variant="strong" class="font-mono">{{ class_basename($r->billable_type) }}#{{ $r->billable_id }}</flux:table.cell>
                                <flux:table.cell align="end">
                                    <x-mollie-billing::admin.money :cents="$r->discount_amount_net ?? 0" :currency="$coupon->currency" />
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </x-mollie-billing::admin.section>
    @endif
</div>
