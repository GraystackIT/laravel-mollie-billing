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
    public ?int $seatCount = 0;
    public ?string $flash = null;
    public bool $flashSuccess = true;

    /** @var array<int, string> Codes that the user has already applied (uppercased). */
    public array $appliedCouponCodes = [];
    /** @var array<int, array{code:string, name:string, stackable:bool}> */
    public array $appliedCouponInfo = [];
    public string $couponInput = '';
    public ?string $couponError = null;
    public ?int $couponDiscountNet = null;
    public array $couponWarnings = [];

    public function mount(): void
    {
        $billable = $this->resolveBillable();
        if ($billable) {
            $this->seatCount = $billable->getBillingSeatCount();
        }
    }

    private function resolveBillable(): ?Billable
    {
        return MollieBilling::resolveBillable(request());
    }

    private function ensureMinSeats(): void
    {
        $min = $this->minSeats();
        if ($this->seatCount === null || $this->seatCount < $min) {
            $this->seatCount = $min;
        }
    }

    public function increment(PreviewService $service): void
    {
        $this->ensureMinSeats();
        $this->seatCount++;
        $this->refreshCouponPreview($service);
    }

    public function decrement(PreviewService $service): void
    {
        $this->ensureMinSeats();
        $min = $this->minSeats();
        if ($this->seatCount > $min) {
            $this->seatCount--;
        }
        $this->refreshCouponPreview($service);
    }

    public function updatedSeatCount(PreviewService $service): void
    {
        $this->refreshCouponPreview($service);
    }

    public function applyCoupon(CouponService $couponService, PreviewService $previewService): void
    {
        $this->couponError = null;

        $code = strtoupper(trim($this->couponInput));
        if ($code === '') {
            return;
        }

        if (in_array($code, $this->appliedCouponCodes, true)) {
            $this->couponError = __('billing::checkout.coupon_already_applied');
            return;
        }

        if (! $this->canAddMoreCoupons()) {
            $this->couponError = __('billing::checkout.coupon_not_stackable_with_current');
            return;
        }

        $billable = $this->resolveBillable();
        if (! $billable) {
            return;
        }

        $catalog = app(SubscriptionCatalogInterface::class);
        $planCode = $billable->getBillingSubscriptionPlanCode() ?? '';
        $interval = $billable->getBillingSubscriptionInterval() ?? 'monthly';
        $newSeats = max($this->seatCount ?? 0, $catalog->includedSeats($planCode));
        $newAddons = $billable->getActiveBillingAddonCodes();
        $newNet = \GraystackIT\MollieBilling\Support\SubscriptionAmount::net(
            $catalog,
            $billable,
            $planCode,
            $interval,
            $newSeats,
            $newAddons,
        );

        try {
            $coupon = $couponService->validate($code, $billable, [
                'planCode' => $planCode ?: null,
                'interval' => $interval,
                'addonCodes' => $newAddons,
                'orderAmountNet' => $newNet,
                'existingCouponIds' => $this->resolveAppliedCouponIds(),
                'allowed_types' => [
                    \GraystackIT\MollieBilling\Enums\CouponType::Recurring,
                ],
            ]);
        } catch (InvalidCouponException $e) {
            $this->couponError = $this->translateCouponReason($e->reason());
            return;
        } catch (\Throwable $e) {
            report($e);
            $this->couponError = __('billing::checkout.coupon_failed');
            return;
        }

        $this->appliedCouponCodes[] = (string) $coupon->code;
        $this->appliedCouponInfo[] = [
            'code' => (string) $coupon->code,
            'name' => (string) ($coupon->name ?: $coupon->code),
            'stackable' => (bool) $coupon->stackable,
        ];
        $this->couponInput = '';

        $this->refreshCouponPreview($previewService);
    }

    public function removeCoupon(string $code, PreviewService $service): void
    {
        $code = strtoupper(trim($code));
        $this->appliedCouponCodes = array_values(array_filter(
            $this->appliedCouponCodes,
            fn (string $c) => $c !== $code,
        ));
        $this->appliedCouponInfo = array_values(array_filter(
            $this->appliedCouponInfo,
            fn (array $info) => ($info['code'] ?? null) !== $code,
        ));
        $this->couponError = null;
        $this->refreshCouponPreview($service);
    }

    public function canAddMoreCoupons(): bool
    {
        if ($this->appliedCouponCodes === []) {
            return true;
        }

        foreach ($this->appliedCouponInfo as $info) {
            if (! ($info['stackable'] ?? true)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, int> */
    private function resolveAppliedCouponIds(): array
    {
        if ($this->appliedCouponCodes === []) {
            return [];
        }

        $upper = array_map('strtoupper', $this->appliedCouponCodes);
        $placeholders = implode(',', array_fill(0, count($upper), '?'));

        return Coupon::query()
            ->whereRaw('UPPER(code) IN ('.$placeholders.')', $upper)
            ->pluck('id')
            ->all();
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

    private function refreshCouponPreview(PreviewService $service): void
    {
        $this->couponDiscountNet = null;
        $this->couponWarnings = [];

        if ($this->appliedCouponCodes === []) {
            return;
        }

        $billable = $this->resolveBillable();
        if (! $billable) {
            return;
        }

        $preview = $service->previewUpdate($billable, new \GraystackIT\MollieBilling\Services\Billing\SubscriptionUpdateRequest(
            seats: $this->seatCount ?? 0,
            couponCodes: $this->appliedCouponCodes,
        ));

        $this->couponDiscountNet = (int) ($preview['couponDiscountNet'] ?? 0);
        $this->couponWarnings = (array) ($preview['warnings'] ?? []);
    }

    private function minSeats(): int
    {
        $billable = $this->resolveBillable();
        $planCode = $billable?->getBillingSubscriptionPlanCode();

        return $planCode ? app(SubscriptionCatalogInterface::class)->includedSeats($planCode) : 0;
    }

    public function syncSeats(): void
    {
        $billable = $this->resolveBillable();
        if (! $billable) return;

        $this->ensureMinSeats();

        try {
            $billable->syncBillingSeats(
                $this->seatCount,
                null,
                $this->appliedCouponCodes !== [] ? $this->appliedCouponCodes : null,
            );
            $this->flash = __('billing::portal.seats_flash.synced');
            $this->flashSuccess = true;
            $this->appliedCouponCodes = [];
            $this->appliedCouponInfo = [];
            $this->couponInput = '';
            $this->couponError = null;
            $this->couponDiscountNet = null;
            $this->couponWarnings = [];
        } catch (\Throwable $e) {
            report($e);
            $this->flash = __('billing::portal.flash.error');
            $this->flashSuccess = false;
        }
    }

    public function with(): array
    {
        $billable = $this->resolveBillable();
        $catalog = app(SubscriptionCatalogInterface::class);
        $planCode = $billable?->getBillingSubscriptionPlanCode();
        $interval = $billable?->getBillingSubscriptionInterval() ?? 'monthly';
        $currency = config('mollie-billing.currency', 'EUR');
        $currencySymbol = $currency === 'EUR' ? '€' : $currency;

        $includedSeats = $planCode ? $catalog->includedSeats($planCode) : 0;
        $seatPriceNet = $planCode ? $catalog->seatPriceNet($planCode, $interval) : null;
        $usedSeats = $billable?->getUsedBillingSeats() ?? 0;
        $extraSeats = max(0, ($this->seatCount ?? $includedSeats) - $includedSeats);

        // Display gross to B2C, net to B2B with valid reverse-charge.
        $reverseCharge = $billable !== null
            && method_exists($billable, 'usesReverseCharge')
            && $billable->usesReverseCharge();

        $seatPrice = $seatPriceNet;
        if ($seatPriceNet !== null && $seatPriceNet > 0 && ! $reverseCharge && $billable !== null) {
            try {
                $seatPrice = (int) app(VatCalculationService::class)
                    ->calculate((string) ($billable->getBillingCountry() ?? 'AT'), $seatPriceNet, $billable)['gross'];
            } catch (\Throwable) {
                // fall back to net
            }
        }

        return [
            'billable' => $billable,
            'includedSeats' => $includedSeats,
            'seatPrice' => $seatPrice,
            'usedSeats' => $usedSeats,
            'extraSeats' => $extraSeats,
            'extraCost' => $seatPrice !== null ? $extraSeats * $seatPrice : null,
            'interval' => $interval,
            'currencySymbol' => $currencySymbol,
            'hasSeats' => $seatPrice !== null || $includedSeats > 0,
            'reverseCharge' => $reverseCharge,
        ];
    }
};

?>

@php
    $currentSeats = $this->seatCount ?? $includedSeats;
    $utilizationPercent = $currentSeats > 0 ? min(100, (int) round(($usedSeats ?? 0) / $currentSeats * 100)) : 0;
    $isHighUtilization = $utilizationPercent >= 80 && $utilizationPercent < 100;
    $isFullUtilization = $utilizationPercent >= 100;
    $savedSeatCount = $billable?->getBillingSeatCount() ?? 0;
    $hasChanges = $this->seatCount !== null && $this->seatCount !== $savedSeatCount;
    $hasPendingPlanChange = $billable?->hasPendingBillingPlanChange() ?? false;
@endphp

<div class="space-y-6">
    {{-- Page header --}}
    <div>
        <flux:heading size="xl">{{ __('billing::portal.seats') }}</flux:heading>
        <flux:subheading>
            {{ __('billing::portal.seats_subtitle') }}
        </flux:subheading>
    </div>

    @if ($flash)
        <flux:callout icon="{{ $flashSuccess ? 'check-circle' : 'exclamation-triangle' }}" color="{{ $flashSuccess ? 'lime' : 'red' }}" x-init="$el.scrollIntoView({ behavior: 'smooth', block: 'center' })" inline>{{ $flash }}</flux:callout>
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
    @elseif (! $hasSeats)
        <flux:callout icon="information-circle" color="zinc" inline>
            {{ __('billing::portal.seats_not_available') }}
        </flux:callout>
    @else
        {{-- Stat cards --}}
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {{-- Total seats --}}
            <flux:card class="p-5!">
                <div class="flex items-start justify-between gap-2">
                    <flux:subheading>{{ __('billing::portal.seats_current') }}</flux:subheading>
                    <div class="flex size-8 items-center justify-center rounded-lg bg-accent/10">
                        <flux:icon.users class="size-4 text-accent" />
                    </div>
                </div>
                <div class="mt-3">
                    <span class="text-3xl font-bold tabular-nums tracking-tight">{{ $currentSeats }}</span>
                </div>
            </flux:card>

            {{-- Included --}}
            <flux:card class="p-5!">
                <div class="flex items-start justify-between gap-2">
                    <flux:subheading>{{ __('billing::portal.seats_included') }}</flux:subheading>
                    <div class="flex size-8 items-center justify-center rounded-lg bg-emerald-500/10">
                        <flux:icon.check-badge class="size-4 text-emerald-500" />
                    </div>
                </div>
                <div class="mt-3">
                    <span class="text-3xl font-bold tabular-nums tracking-tight">{{ $includedSeats }}</span>
                </div>
                
            </flux:card>

            {{-- In use --}}
            <flux:card class="p-5!">
                <div class="flex items-start justify-between gap-2">
                    <flux:subheading>{{ __('billing::portal.seats_in_use') }}</flux:subheading>
                    <div class="flex size-8 items-center justify-center rounded-lg {{ $isFullUtilization ? 'bg-red-500/10' : ($isHighUtilization ? 'bg-amber-400/10' : 'bg-zinc-100 dark:bg-zinc-800') }}">
                        <flux:icon.user-circle class="size-4 {{ $isFullUtilization ? 'text-red-500' : ($isHighUtilization ? 'text-amber-500' : 'text-zinc-400') }}" />
                    </div>
                </div>
                <div class="mt-3 flex items-baseline gap-1.5">
                    <span class="text-3xl font-bold tabular-nums tracking-tight">{{ $usedSeats }}</span>
                    <span class="text-sm text-zinc-400">/ {{ $currentSeats }}</span>
                </div>
                <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                    <div
                        class="h-full rounded-full transition-all duration-500 {{ $isFullUtilization ? 'bg-red-500' : ($isHighUtilization ? 'bg-amber-400' : 'bg-emerald-500') }}"
                        style="width: {{ $utilizationPercent }}%"
                    ></div>
                </div>
            </flux:card>

            {{-- Price per extra seat --}}
            @if ($seatPrice !== null)
                <flux:card class="p-5!">
                    <div class="flex items-start justify-between gap-2">
                        <flux:subheading>{{ __('billing::portal.seats_price_per_seat') }}</flux:subheading>
                        <div class="flex size-8 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
                            <flux:icon.currency-euro class="size-4 text-zinc-400" />
                        </div>
                    </div>
                    <div class="mt-3">
                        <span class="text-3xl font-bold tabular-nums tracking-tight">{{ $currencySymbol }}{{ number_format($seatPrice / 100, 2) }}</span>
                    </div>
                    <flux:text class="mt-1 text-xs text-zinc-400">{{ $interval === 'monthly' ? __('billing::portal.per_month') : __('billing::portal.per_year') }} · {{ $reverseCharge ? __('billing::portal.prices_excl_vat') : __('billing::portal.prices_incl_vat') }}</flux:text>
                </flux:card>
            @endif
        </div>

        {{-- Adjust seats --}}
        <flux:card class="relative overflow-hidden p-0!">
            <div class="absolute inset-x-0 top-0 h-1 {{ $hasChanges ? 'bg-accent' : 'bg-transparent' }}"></div>

            <div class="px-6 pb-4 pt-8">
                <div class="flex items-center gap-3">
                    <div class="flex size-10 items-center justify-center rounded-full bg-accent/10">
                        <flux:icon.adjustments-horizontal class="size-5 text-accent" />
                    </div>
                    <div>
                        <flux:heading size="lg">{{ __('billing::portal.seats_adjust') }}</flux:heading>
                        <flux:text class="text-sm text-zinc-500">{{ __('billing::portal.seats_adjust_subtitle') }}</flux:text>
                    </div>
                </div>
            </div>

            @php
                $localBlocksPaidSeats = $billable && $billable->isLocalBillingSubscription() && ($seatPrice ?? null) !== null && $seatPrice > 0;
            @endphp

            <div class="border-t border-zinc-200/75 bg-zinc-50/50 px-6 py-6 dark:border-zinc-700/50 dark:bg-white/[0.02]">
                <div class="flex flex-col gap-6 sm:flex-row sm:items-start sm:justify-between">
                    {{-- Stepper --}}
                    <div class="space-y-3">
                        <flux:subheading size="sm" class="text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.seats_current') }}</flux:subheading>
                        <div class="flex items-center gap-1">
                            <flux:button size="sm" variant="ghost" icon="minus"
                                wire:click="decrement"
                                :disabled="$currentSeats <= $includedSeats || $hasPendingPlanChange || $localBlocksPaidSeats"
                                class="rounded-r-none"
                            />
                            <flux:input type="number" wire:model.live="seatCount" :min="$includedSeats" :disabled="$hasPendingPlanChange || $localBlocksPaidSeats" class="w-20 text-center tabular-nums rounded-none! border-x-0!" x-on:blur="if (!$el.value || parseInt($el.value) < {{ $includedSeats }}) { $wire.set('seatCount', {{ $includedSeats }}) }" />
                            <flux:button size="sm" variant="ghost" icon="plus"
                                wire:click="increment"
                                :disabled="$hasPendingPlanChange || $localBlocksPaidSeats"
                                class="rounded-l-none"
                            />
                        </div>
                    </div>

                    {{-- Cost breakdown --}}
                    @if ($seatPrice !== null)
                        <div class="flex-1 max-w-sm">
                            <flux:subheading size="sm" class="mb-3 text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.seats_cost_summary') }}</flux:subheading>
                            <div class="space-y-2">
                                <div class="flex items-center justify-between text-sm">
                                    <span class="text-zinc-600 dark:text-zinc-300">{{ trans_choice('billing::portal.seats_included_label', $includedSeats, ['count' => $includedSeats]) }}</span>
                                    <span class="tabular-nums text-zinc-400">{{ __('billing::portal.free') }}</span>
                                </div>
                                @if ($extraSeats > 0)
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="text-zinc-600 dark:text-zinc-300">
                                            {{ $extraSeats }} {{ __('billing::portal.seats_extra') }}
                                            <span class="text-zinc-400">&times; {{ $currencySymbol }}{{ number_format($seatPrice / 100, 2) }}</span>
                                        </span>
                                        <span class="tabular-nums font-medium text-zinc-700 dark:text-zinc-200">{{ $currencySymbol }}{{ number_format($extraCost / 100, 2) }}</span>
                                    </div>
                                @endif
                                @if (($couponDiscountNet ?? 0) > 0)
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="text-zinc-600 dark:text-zinc-300">{{ __('billing::portal.discount') }}</span>
                                        <span class="tabular-nums font-medium text-emerald-600 dark:text-emerald-400">−{{ $currencySymbol }}{{ number_format($couponDiscountNet / 100, 2) }}</span>
                                    </div>
                                @endif
                                <flux:separator class="my-1!" />
                                <div class="flex items-center justify-between">
                                    <span class="font-medium text-zinc-700 dark:text-zinc-200">{{ __('billing::portal.seats_total_cost') }}</span>
                                    <span class="text-lg font-bold tabular-nums text-zinc-900 dark:text-white">{{ $currencySymbol }}{{ number_format(max(0, ($extraCost ?? 0) - ($couponDiscountNet ?? 0)) / 100, 2) }}</span>
                                </div>
                                <flux:text class="text-xs text-zinc-400">{{ $interval === 'monthly' ? __('billing::portal.per_month') : __('billing::portal.per_year') }} · {{ $reverseCharge ? __('billing::portal.prices_excl_vat') : __('billing::portal.prices_incl_vat') }}</flux:text>

                                <flux:separator class="my-3!" />

                                <div class="space-y-2">
                                    @foreach ($appliedCouponInfo as $info)
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
                                                wire:click="removeCoupon({{ \Illuminate\Support\Js::from($info['code']) }})"
                                                :aria-label="__('billing::checkout.remove_coupon')"
                                            />
                                        </div>
                                    @endforeach

                                    @if ($this->canAddMoreCoupons())
                                        <flux:input.group>
                                            <flux:input
                                                size="sm"
                                                wire:model="couponInput"
                                                wire:keydown.enter.prevent="applyCoupon"
                                                :placeholder="__('billing::portal.coupon_code_placeholder')"
                                                :disabled="$hasPendingPlanChange || $localBlocksPaidSeats"
                                            />
                                            <flux:button
                                                size="sm"
                                                type="button"
                                                wire:click="applyCoupon"
                                                icon="check"
                                                :disabled="$hasPendingPlanChange || $localBlocksPaidSeats"
                                            >
                                                {{ __('billing::portal.coupon_redeem_button') }}
                                            </flux:button>
                                        </flux:input.group>
                                    @endif

                                    @if ($couponError)
                                        <flux:text class="text-xs text-rose-600 dark:text-rose-400">{{ $couponError }}</flux:text>
                                    @endif

                                    @foreach ($couponWarnings as $warning)
                                        <flux:text class="text-xs text-rose-600 dark:text-rose-400">{{ $warning }}</flux:text>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endif
                </div>

                @if ($usedSeats > $currentSeats)
                    <flux:callout icon="exclamation-triangle" color="amber" class="mt-5" inline>
                        {{ __('billing::portal.seats_warning_below_used', ['used' => $usedSeats]) }}
                    </flux:callout>
                @endif

                {{-- Action --}}
                <div class="mt-5 flex justify-end">
                    <flux:button variant="primary" size="sm" wire:click="syncSeats" :disabled="! $hasChanges || $hasPendingPlanChange || $localBlocksPaidSeats">
                        {{ __('billing::portal.seats_save') }}
                    </flux:button>
                </div>
            </div>
        </flux:card>
    @endif
</div>
