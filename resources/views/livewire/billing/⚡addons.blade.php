<?php

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use Livewire\Component;

new class extends Component {
    public bool $polling = false;
    public ?string $pendingAddon = null;
    public ?string $pendingAction = null;

    /** @var array<string, array<int, string>> applied coupon codes per addon */
    public array $appliedCouponCodes = [];
    /** @var array<string, array<int, array{code:string, name:string, stackable:bool}>> */
    public array $appliedCouponInfo = [];
    /** @var array<string, string> active input per addon */
    public array $couponInputs = [];
    /** @var array<string, ?string> validation error per addon */
    public array $couponErrors = [];
    /** @var array<string, int> coupon total discount net (cents) per addon */
    public array $couponDiscounts = [];

    private function resolveBillable(): ?Billable
    {
        return MollieBilling::resolveBillable(request());
    }

    public function applyCoupon(string $addonCode, CouponService $couponService, PreviewService $previewService): void
    {
        $this->couponErrors[$addonCode] = null;

        $code = strtoupper(trim((string) ($this->couponInputs[$addonCode] ?? '')));
        if ($code === '') {
            return;
        }

        $current = $this->appliedCouponCodes[$addonCode] ?? [];

        if (in_array($code, $current, true)) {
            $this->couponErrors[$addonCode] = __('billing::checkout.coupon_already_applied');
            return;
        }

        if (! $this->canAddMoreCouponsFor($addonCode)) {
            $this->couponErrors[$addonCode] = __('billing::checkout.coupon_not_stackable_with_current');
            return;
        }

        $billable = $this->resolveBillable();
        if (! $billable) {
            return;
        }

        $catalog = app(SubscriptionCatalogInterface::class);
        $next = array_values(array_unique(array_merge($billable->getActiveBillingAddonCodes(), [$addonCode])));
        $planCode = $billable->getBillingSubscriptionPlanCode() ?? '';
        $interval = $billable->getBillingSubscriptionInterval() ?? 'monthly';
        $totalSeats = $planCode ? $catalog->includedSeats($planCode) + max(0, $billable->getExtraBillingSeats()) : 0;
        $newNet = $planCode
            ? \GraystackIT\MollieBilling\Support\SubscriptionAmount::net($catalog, $billable, $planCode, $interval, $totalSeats, $next)
            : 0;

        try {
            $coupon = $couponService->validate($code, $billable, [
                'planCode' => $planCode ?: null,
                'interval' => $interval,
                'addonCodes' => $next,
                'orderAmountNet' => $newNet,
                'existingCouponIds' => $this->resolveAppliedCouponIds($current),
                'allowed_types' => [
                    \GraystackIT\MollieBilling\Enums\CouponType::FirstPayment,
                    \GraystackIT\MollieBilling\Enums\CouponType::Recurring,
                ],
            ]);
        } catch (InvalidCouponException $e) {
            $this->couponErrors[$addonCode] = $this->translateCouponReason($e->reason());
            return;
        } catch (\Throwable $e) {
            report($e);
            $this->couponErrors[$addonCode] = __('billing::checkout.coupon_failed');
            return;
        }

        $this->appliedCouponCodes[$addonCode][] = (string) $coupon->code;
        $this->appliedCouponInfo[$addonCode][] = [
            'code' => (string) $coupon->code,
            'name' => (string) ($coupon->name ?: $coupon->code),
            'stackable' => (bool) $coupon->stackable,
        ];
        $this->couponInputs[$addonCode] = '';

        $this->refreshAddonPreview($previewService, $addonCode);
    }

    public function removeCoupon(string $addonCode, string $code, PreviewService $service): void
    {
        $code = strtoupper(trim($code));
        $this->appliedCouponCodes[$addonCode] = array_values(array_filter(
            $this->appliedCouponCodes[$addonCode] ?? [],
            fn (string $c) => $c !== $code,
        ));
        $this->appliedCouponInfo[$addonCode] = array_values(array_filter(
            $this->appliedCouponInfo[$addonCode] ?? [],
            fn (array $info) => ($info['code'] ?? null) !== $code,
        ));
        $this->couponErrors[$addonCode] = null;
        $this->refreshAddonPreview($service, $addonCode);
    }

    public function canAddMoreCouponsFor(string $addonCode): bool
    {
        $infos = $this->appliedCouponInfo[$addonCode] ?? [];
        if ($infos === []) {
            return true;
        }

        foreach ($infos as $info) {
            if (! ($info['stackable'] ?? true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string>  $codes
     * @return array<int, int>
     */
    private function resolveAppliedCouponIds(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        return Coupon::query()
            ->whereIn('code', array_map('strtoupper', $codes))
            ->pluck('id')
            ->all();
    }

    private function refreshAddonPreview(PreviewService $service, string $addonCode): void
    {
        $this->couponDiscounts[$addonCode] = 0;

        $codes = $this->appliedCouponCodes[$addonCode] ?? [];
        if ($codes === []) {
            return;
        }

        $billable = $this->resolveBillable();
        if (! $billable) {
            return;
        }

        $next = array_values(array_unique(array_merge($billable->getActiveBillingAddonCodes(), [$addonCode])));

        $preview = $service->previewUpdate($billable, new \GraystackIT\MollieBilling\Services\Billing\SubscriptionUpdateRequest(
            addons: $next,
            couponCodes: $codes,
        ));

        $this->couponDiscounts[$addonCode] = (int) ($preview['couponDiscountNet'] ?? 0);
    }

    private function translateCouponReason(string $reason): string
    {
        return match ($reason) {
            'not_found' => __('billing::checkout.coupon_not_found'),
            'inactive' => __('billing::checkout.coupon_inactive'),
            'not_yet_valid' => __('billing::checkout.coupon_not_yet_valid'),
            'expired' => __('billing::checkout.coupon_expired'),
            'globally_exhausted' => __('billing::checkout.coupon_exhausted'),
            'plan_not_applicable' => __('billing::checkout.coupon_plan_mismatch'),
            'interval_not_applicable' => __('billing::checkout.coupon_interval_mismatch'),
            'addon_not_applicable' => __('billing::checkout.coupon_addon_mismatch'),
            'product_not_applicable' => __('billing::checkout.coupon_product_mismatch'),
            'min_order_not_met' => __('billing::checkout.coupon_min_order'),
            'requires_billable' => __('billing::checkout.coupon_requires_billable'),
            'recurring_conflict' => __('billing::checkout.coupon_recurring_conflict'),
            'requires_active_subscription' => __('billing::checkout.coupon_requires_active_subscription'),
            'too_close_to_charge' => __('billing::checkout.coupon_too_close_to_charge'),
            'per_billable_limit_reached' => __('billing::checkout.coupon_per_billable_limit_reached'),
            'full_coverage_use_access_grant' => __('billing::checkout.coupon_full_coverage_use_access_grant'),
            'recurring_already_active' => __('billing::checkout.coupon_recurring_already_active'),
            'type_not_allowed_in_context' => __('billing::checkout.coupon_type_not_allowed_in_context'),
            default => __('billing::checkout.coupon_failed'),
        };
    }

    public function enableAddon(string $addonCode): void
    {
        $billable = $this->resolveBillable();
        if (! $billable) return;

        $codes = $this->appliedCouponCodes[$addonCode] ?? [];

        try {
            $billable->enableBillingAddon(
                $addonCode,
                null,
                $codes !== [] ? $codes : null,
            );
            $this->pendingAddon = $addonCode;
            $this->pendingAction = 'enabled';
            $this->polling = true;
            unset(
                $this->appliedCouponCodes[$addonCode],
                $this->appliedCouponInfo[$addonCode],
                $this->couponInputs[$addonCode],
                $this->couponErrors[$addonCode],
                $this->couponDiscounts[$addonCode],
            );
        } catch (\Throwable $e) {
            report($e);
            \Flux::toast(__('billing::portal.flash.error'), variant: 'danger');
        }
    }

    public function disableAddon(string $addonCode): void
    {
        $billable = $this->resolveBillable();
        if (! $billable) return;

        try {
            $billable->disableBillingAddon($addonCode);
            $this->pendingAddon = $addonCode;
            $this->pendingAction = 'disabled';
            $this->polling = true;
        } catch (\Throwable $e) {
            report($e);
            \Flux::toast(__('billing::portal.flash.error'), variant: 'danger');
        }
    }

    public function pollForChange(): void
    {
        $billable = $this->resolveBillable();
        if (! $billable) return;

        $meta = $billable->getBillingSubscriptionMeta();

        if (empty($meta['pending_plan_change'])) {
            $this->polling = false;
            $name = $this->pendingAddon
                ? (app(SubscriptionCatalogInterface::class)->addonName($this->pendingAddon) ?? $this->pendingAddon)
                : null;
            $message = $name
                ? __("billing::portal.addons_flash.{$this->pendingAction}", ['addon' => $name])
                : __('billing::portal.flash.plan_changed');
            \Flux::toast($message, variant: 'success');
            $this->pendingAddon = null;
            $this->pendingAction = null;
        }
    }

    public function with(): array
    {
        $billable = $this->resolveBillable();
        $catalog = app(SubscriptionCatalogInterface::class);
        $interval = $billable?->getBillingSubscriptionInterval() ?? 'monthly';
        $planCode = $billable?->getBillingSubscriptionPlanCode();
        $activeAddons = $billable?->getActiveBillingAddonCodes() ?? [];
        $currency = config('mollie-billing.currency', 'EUR');
        $currencySymbol = $currency === 'EUR' ? '€' : $currency;

        $reverseCharge = $billable !== null
            && method_exists($billable, 'usesReverseCharge')
            && $billable->usesReverseCharge();
        $vatService = app(VatCalculationService::class);
        $country = (string) ($billable?->getBillingCountry() ?? 'AT');

        $addons = [];
        foreach ($catalog->allAddons() as $code) {
            $allowed = $planCode ? $catalog->planAllowsAddon($planCode, $code) : false;
            $netPrice = $catalog->addonPriceNet($code, $interval);

            // Display gross to B2C, net to B2B with valid reverse-charge.
            $displayPrice = $netPrice;
            if ($netPrice > 0 && ! $reverseCharge && $billable !== null) {
                try {
                    $displayPrice = (int) $vatService->calculate($country, $netPrice, $billable)['gross'];
                } catch (\Throwable) {
                    // fall back to net
                }
            }

            $addons[] = [
                'code' => $code,
                'name' => $catalog->addonName($code) ?? $code,
                'price' => $displayPrice,
                'features' => $catalog->addonFeatures($code),
                'isActive' => in_array($code, $activeAddons, true),
                'isAllowed' => $allowed,
            ];
        }

        return [
            'billable' => $billable,
            'addons' => $addons,
            'interval' => $interval,
            'currencySymbol' => $currencySymbol,
            'catalog' => $catalog,
            'hasPendingPlanChange' => $billable?->hasPendingBillingPlanChange() ?? false,
            'reverseCharge' => $reverseCharge,
        ];
    }
};

?>

<div class="space-y-6" @if ($polling) wire:poll.3s="pollForChange" @endif>
    {{-- Page header --}}
    <div>
        <flux:heading size="xl">{{ __('billing::portal.addons') }}</flux:heading>
        <flux:subheading>
            {{ __('billing::portal.addons_subtitle') }}
        </flux:subheading>
    </div>

    @if ($polling)
        <flux:callout icon="arrow-path" color="blue" inline>{{ __('billing::portal.flash.plan_change_pending_payment') }}</flux:callout>
    @endif

    @if ($hasPendingPlanChange)
        <flux:callout icon="arrow-path" color="blue" inline>
            {{ __('billing::portal.pending_change_blocks_modifications') }}
        </flux:callout>
    @endif

    @if ($billable && $billable->isLocalBillingSubscription())
        <flux:callout icon="information-circle" color="blue">
            <span>{{ __('billing::portal.free_plan_no_paid_extras') }}</span>
            <flux:button :href="route(\GraystackIT\MollieBilling\Support\BillingRoute::name('plan'), \GraystackIT\MollieBilling\MollieBilling::resolveUrlParameters($billable))" variant="primary" size="sm" class="mt-3">
                {{ __('billing::portal.plan_change') }}
            </flux:button>
        </flux:callout>
    @endif

    @if (! $billable)
        <flux:callout variant="warning" icon="exclamation-triangle">
            {{ __('billing::portal.no_billable') }}
        </flux:callout>
    @elseif (empty($addons))
        <flux:callout icon="information-circle" color="zinc" inline>
            {{ __('billing::portal.addons_none_available') }}
        </flux:callout>
    @else
        <div class="space-y-4">
            @foreach ($addons as $addon)
                <flux:card class="relative p-0! hover:shadow-md transition">
                    <div class="flex flex-col gap-4 px-6 py-6 sm:flex-row sm:items-start sm:justify-between">
                        {{-- Left: info --}}
                        <div class="flex items-start gap-4">
                            <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                                <flux:icon.puzzle-piece class="size-5 text-zinc-400" />
                            </div>
                            <div class="space-y-1">
                                <div class="flex items-center gap-3">
                                    <flux:heading size="lg">{{ $addon['name'] }}</flux:heading>
                                </div>
                                @if (count($addon['features']) > 0)
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach ($addon['features'] as $feature)
                                            <span class="inline-flex items-center gap-1 text-sm text-zinc-500 dark:text-zinc-400">
                                                <flux:icon.check class="size-3.5 text-emerald-500" />
                                                {{ $catalog->featureName($feature) }}
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>

                        {{-- Right: price + action --}}
                        <div class="flex items-center gap-6 sm:shrink-0">
                            @if ($addon['isActive'])
                                <flux:badge size="sm" color="lime">{{ __('billing::portal.active') }}</flux:badge>
                            @endif
                            <div class="text-right">
                                <div class="flex items-baseline gap-1">
                                    <span class="text-2xl font-bold tabular-nums tracking-tight">{{ $currencySymbol }}{{ number_format($addon['price'] / 100, 2) }}</span>
                                    <span class="text-sm text-zinc-400">{{ $interval === 'monthly' ? __('billing::portal.per_month') : __('billing::portal.per_year') }}</span>
                                </div>
                                <flux:text class="text-xs text-zinc-400">{{ $reverseCharge ? __('billing::portal.prices_excl_vat') : __('billing::portal.prices_incl_vat') }}</flux:text>
                            </div>

                            @php
                                $localBlocksThisAddon = $billable && $billable->isLocalBillingSubscription() && $addon['price'] > 0;
                            @endphp
                            <div class="w-32 flex justify-end">
                                @if ($hasPendingPlanChange)
                                    <flux:button class="w-full" size="sm" variant="ghost" disabled>
                                        {{ $addon['isActive'] ? __('billing::portal.addons_disable') : __('billing::portal.addons_enable') }}
                                    </flux:button>
                                @elseif ($addon['isActive'])
                                    <flux:modal.trigger name="disable-addon-{{ $addon['code'] }}">
                                        <flux:button class="w-full" size="sm" variant="ghost" icon="x-circle">
                                            {{ __('billing::portal.addons_disable') }}
                                        </flux:button>
                                    </flux:modal.trigger>
                                @elseif ($localBlocksThisAddon)
                                    <flux:button class="w-full" size="sm" variant="ghost" disabled>
                                        {{ __('billing::portal.addons_enable') }}
                                    </flux:button>
                                @elseif ($addon['isAllowed'])
                                    <flux:modal.trigger name="enable-addon-{{ $addon['code'] }}">
                                        <flux:button class="w-full" size="sm" variant="primary">
                                            {{ __('billing::portal.addons_enable') }}
                                        </flux:button>
                                    </flux:modal.trigger>
                                @else
                                    <flux:tooltip :content="__('billing::portal.addons_not_available')">
                                        <flux:badge size="sm" color="zinc" icon="lock-closed">
                                            {{ __('billing::portal.addons_not_available_short') }}
                                        </flux:badge>
                                    </flux:tooltip>
                                @endif
                            </div>
                        </div>
                    </div>
                </flux:card>

                {{-- Enable confirm modal --}}
                @if (! $addon['isActive'] && $addon['isAllowed'])
                    <flux:modal name="enable-addon-{{ $addon['code'] }}" class="max-w-md">
                        <div class="space-y-6">
                            <div class="space-y-2">
                                <flux:heading size="lg">{{ __('billing::portal.addons_enable_confirm.title') }}</flux:heading>
                                <flux:text>{{ __('billing::portal.addons_enable_confirm.body', ['addon' => $addon['name'], 'price' => $currencySymbol . number_format($addon['price'] / 100, 2), 'interval' => $interval === 'monthly' ? __('billing::portal.per_month') : __('billing::portal.per_year')]) }}</flux:text>
                            </div>

                            <div class="space-y-2">
                                @foreach (($appliedCouponInfo[$addon['code']] ?? []) as $info)
                                    <div class="flex items-center justify-between gap-2 rounded-md border border-emerald-300/60 bg-emerald-50/60 px-2.5 py-1.5 dark:border-emerald-800/50 dark:bg-emerald-900/20">
                                        <div class="flex items-center gap-2 text-sm">
                                            <flux:icon.ticket class="size-3.5 text-emerald-600 dark:text-emerald-400" />
                                            <span class="font-medium tabular-nums text-emerald-700 dark:text-emerald-300">{{ $info['code'] }}</span>
                                            @if (! ($info['stackable'] ?? true))
                                                <span class="text-xs text-zinc-400">{{ __('billing::portal.coupon_not_stackable') }}</span>
                                            @endif
                                        </div>
                                        <flux:button
                                            size="xs"
                                            variant="ghost"
                                            icon="x-mark"
                                            wire:click="removeCoupon('{{ $addon['code'] }}', '{{ $info['code'] }}')"
                                            :aria-label="__('billing::checkout.remove_coupon')"
                                        />
                                    </div>
                                @endforeach

                                @if ($this->canAddMoreCouponsFor($addon['code']))
                                    <flux:input.group>
                                        <flux:input
                                            wire:model="couponInputs.{{ $addon['code'] }}"
                                            wire:keydown.enter.prevent="applyCoupon('{{ $addon['code'] }}')"
                                            :placeholder="__('billing::portal.coupon_code_placeholder')"
                                        />
                                        <flux:button type="button" wire:click="applyCoupon('{{ $addon['code'] }}')" icon="check">
                                            {{ __('billing::portal.coupon_redeem_button') }}
                                        </flux:button>
                                    </flux:input.group>
                                @endif

                                @if (($couponDiscounts[$addon['code']] ?? 0) > 0)
                                    <flux:text class="text-xs text-emerald-600 dark:text-emerald-400">
                                        −{{ $currencySymbol }}{{ number_format(($couponDiscounts[$addon['code']] ?? 0) / 100, 2) }} {{ __('billing::portal.coupon_discount_applied') }}
                                    </flux:text>
                                @endif

                                @if (! empty($couponErrors[$addon['code']] ?? null))
                                    <flux:text class="text-xs text-rose-600 dark:text-rose-400">{{ $couponErrors[$addon['code']] }}</flux:text>
                                @endif
                            </div>

                            <div class="flex justify-end gap-2">
                                <flux:modal.close>
                                    <flux:button variant="ghost">{{ __('billing::portal.addons_enable_confirm.cancel') }}</flux:button>
                                </flux:modal.close>
                                <flux:button variant="primary" wire:click="enableAddon('{{ $addon['code'] }}')" x-on:click="$flux.modal('enable-addon-{{ $addon['code'] }}').close()">
                                    {{ __('billing::portal.addons_enable_confirm.confirm') }}
                                </flux:button>
                            </div>
                        </div>
                    </flux:modal>
                @endif

                {{-- Disable confirm modal --}}
                @if ($addon['isActive'])
                    <flux:modal name="disable-addon-{{ $addon['code'] }}" class="max-w-md">
                        <div class="space-y-6">
                            <div class="space-y-2">
                                <flux:heading size="lg">{{ __('billing::portal.addons_disable_confirm.title') }}</flux:heading>
                                <flux:text>{{ __('billing::portal.addons_disable_confirm.body', ['addon' => $addon['name']]) }}</flux:text>
                            </div>
                            <div class="flex justify-end gap-2">
                                <flux:modal.close>
                                    <flux:button variant="ghost">{{ __('billing::portal.addons_disable_confirm.keep') }}</flux:button>
                                </flux:modal.close>
                                <flux:button variant="danger" wire:click="disableAddon('{{ $addon['code'] }}')" x-on:click="$flux.modal('disable-addon-{{ $addon['code'] }}').close()">
                                    {{ __('billing::portal.addons_disable_confirm.confirm') }}
                                </flux:button>
                            </div>
                        </div>
                    </flux:modal>
                @endif
            @endforeach
        </div>
    @endif
</div>
