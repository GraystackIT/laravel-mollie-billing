<?php

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\BillingTime;
use Livewire\Component;

new class extends Component {
    public bool $processing = false;

    /** @var array<string, array<int, string>> applied coupon codes per product */
    public array $appliedCouponCodes = [];
    /** @var array<string, array<int, array{code:string, name:string, stackable:bool}>> */
    public array $appliedCouponInfo = [];
    /** @var array<string, string> active input per product */
    public array $couponInputs = [];
    /** @var array<string, ?string> validation error per product */
    public array $couponErrors = [];
    /** @var array<string, int> total coupon discount net (cents) per product */
    public array $couponDiscounts = [];

    private function resolveBillable(): ?Billable
    {
        return MollieBilling::resolveBillable(request());
    }

    public function applyCoupon(string $productCode, CouponService $service): void
    {
        $this->couponErrors[$productCode] = null;

        $code = strtoupper(trim((string) ($this->couponInputs[$productCode] ?? '')));
        if ($code === '') {
            return;
        }

        $current = $this->appliedCouponCodes[$productCode] ?? [];

        if (in_array($code, $current, true)) {
            $this->couponErrors[$productCode] = __('billing::checkout.coupon_already_applied');
            return;
        }

        if (! $this->canAddMoreCouponsFor($productCode)) {
            $this->couponErrors[$productCode] = __('billing::checkout.coupon_not_stackable_with_current');
            return;
        }

        $billable = $this->resolveBillable();
        if (! $billable) {
            return;
        }

        $catalog = app(SubscriptionCatalogInterface::class);
        $priceNet = $catalog->productPriceNet($productCode);
        if ($priceNet <= 0) {
            return;
        }

        $remainingNet = $priceNet - (int) ($this->couponDiscounts[$productCode] ?? 0);
        $remainingNet = max(0, $remainingNet);

        try {
            $coupon = $service->validate($code, $billable, [
                'productCodes' => [$productCode],
                'orderAmountNet' => $remainingNet,
                'existingCouponIds' => $this->resolveAppliedCouponIds($current),
                'allowed_types' => [
                    \GraystackIT\MollieBilling\Enums\CouponType::SinglePayment,
                    \GraystackIT\MollieBilling\Enums\CouponType::Recurring,
                ],
            ]);
        } catch (InvalidCouponException $e) {
            $this->couponErrors[$productCode] = $this->translateCouponReason($e->reason());
            return;
        } catch (\Throwable $e) {
            report($e);
            $this->couponErrors[$productCode] = __('billing::portal.coupon_redeem_failed');
            return;
        }

        $thisDiscount = $service->computeRecurringDiscount($coupon, $remainingNet);

        $this->appliedCouponCodes[$productCode][] = (string) $coupon->code;
        $this->appliedCouponInfo[$productCode][] = [
            'code' => (string) $coupon->code,
            'name' => (string) ($coupon->name ?: $coupon->code),
            'stackable' => (bool) $coupon->stackable,
        ];
        $this->couponDiscounts[$productCode] = ($this->couponDiscounts[$productCode] ?? 0) + $thisDiscount;
        $this->couponInputs[$productCode] = '';
    }

    public function removeCoupon(string $productCode, string $code, CouponService $service): void
    {
        $code = strtoupper(trim($code));
        $this->appliedCouponCodes[$productCode] = array_values(array_filter(
            $this->appliedCouponCodes[$productCode] ?? [],
            fn (string $c) => $c !== $code,
        ));
        $this->appliedCouponInfo[$productCode] = array_values(array_filter(
            $this->appliedCouponInfo[$productCode] ?? [],
            fn (array $info) => ($info['code'] ?? null) !== $code,
        ));
        $this->couponErrors[$productCode] = null;
        $this->recomputeDiscounts($service, $productCode);
    }

    public function canAddMoreCouponsFor(string $productCode): bool
    {
        $infos = $this->appliedCouponInfo[$productCode] ?? [];
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

        $upper = array_map('strtoupper', $codes);
        $placeholders = implode(',', array_fill(0, count($upper), '?'));

        return Coupon::query()
            ->whereRaw('UPPER(code) IN ('.$placeholders.')', $upper)
            ->pluck('id')
            ->all();
    }

    private function recomputeDiscounts(CouponService $service, string $productCode): void
    {
        $this->couponDiscounts[$productCode] = 0;

        $codes = $this->appliedCouponCodes[$productCode] ?? [];
        if ($codes === []) {
            return;
        }

        $catalog = app(SubscriptionCatalogInterface::class);
        $priceNet = $catalog->productPriceNet($productCode);
        $remaining = $priceNet;
        $sum = 0;

        foreach ($codes as $code) {
            $coupon = Coupon::query()->whereRaw('UPPER(code) = ?', [strtoupper($code)])->first();
            if ($coupon === null) {
                continue;
            }
            $discount = $service->computeRecurringDiscount($coupon, $remaining);
            $sum += $discount;
            $remaining = max(0, $remaining - $discount);
        }

        $this->couponDiscounts[$productCode] = $sum;
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
            default => __('billing::portal.coupon_redeem_failed'),
        };
    }

    public function purchase(string $productCode): void
    {
        $billable = $this->resolveBillable();
        if (! $billable) return;

        $this->processing = true;

        try {
            $codes = $this->appliedCouponCodes[$productCode] ?? [];
            $result = $billable->purchaseOneTimeOrder(
                $productCode,
                [],
                null,
                $codes !== [] ? $codes : null,
            );

            if (! empty($result['checkout_url'])) {
                $this->processing = false;
                $this->redirect($result['checkout_url']);
                return;
            }

            \Flux::toast(__('billing::portal.products.error'), variant: 'danger');
        } catch (\Throwable $e) {
            report($e);
            \Flux::toast(__('billing::portal.products.error'), variant: 'danger');
        }

        $this->processing = false;
    }

    public function with(): array
    {
        $billable = $this->resolveBillable();
        $catalog = app(SubscriptionCatalogInterface::class);
        $currency = config('mollie-billing.currency', 'EUR');
        $currencySymbol = $currency === 'EUR' ? '€' : $currency;

        $availableCodes = $billable?->availableBillingProducts() ?? [];
        $boughtCodes = $billable?->boughtBillingProducts() ?? [];

        $reverseCharge = $billable !== null
            && method_exists($billable, 'usesReverseCharge')
            && $billable->usesReverseCharge();
        $vatService = app(VatCalculationService::class);
        $country = (string) ($billable?->getBillingCountry() ?? 'AT');

        $buildProduct = function (string $code) use ($catalog, $reverseCharge, $vatService, $country, $billable): array {
            $netPrice = $catalog->productPriceNet($code);

            // Display gross to B2C, net to B2B with valid reverse-charge.
            $displayPrice = $netPrice;
            if ($netPrice > 0 && ! $reverseCharge && $billable !== null) {
                try {
                    $displayPrice = (int) $vatService->calculate($country, $netPrice, $billable)['gross'];
                } catch (\Throwable) {
                    // fall back to net
                }
            }

            return [
                'code' => $code,
                'name' => $catalog->productName($code) ?? $code,
                'description' => $catalog->productDescription($code),
                'image_url' => $catalog->productImageUrl($code),
                'price_net' => $displayPrice,
                'usage_type' => $catalog->productUsageType($code),
                'quantity' => $catalog->productQuantity($code),
                'onetimeonly' => $catalog->productOneTimeOnly($code),
                'group' => $catalog->productGroup($code),
            ];
        };

        $available = array_map($buildProduct, $availableCodes);

        // Group available products by group key, sorted by group sort
        $groupProducts = function (array $products) use ($catalog): array {
            $groups = [];
            foreach ($products as $product) {
                $groupKey = $product['group'] ?? '__ungrouped__';
                if (! isset($groups[$groupKey])) {
                    $groups[$groupKey] = [
                        'key' => $groupKey,
                        'name' => $groupKey === '__ungrouped__' ? null : $catalog->productGroupName($groupKey),
                        'sort' => $groupKey === '__ungrouped__' ? PHP_INT_MAX : $catalog->productGroupSort($groupKey),
                        'products' => [],
                    ];
                }
                $groups[$groupKey]['products'][] = $product;
            }
            usort($groups, fn ($a, $b) => $a['sort'] <=> $b['sort']);
            return $groups;
        };

        $availableGroups = $groupProducts($available);

        // Build purchase stats per product code (count + last purchase date)
        $purchaseStats = [];
        if ($billable) {
            $invoices = $billable->billingInvoices()
                ->where('invoice_kind', InvoiceKind::OneTimeOrder)
                ->where('status', InvoiceStatus::Paid)
                ->get(['line_items', 'created_at']);

            foreach ($invoices as $invoice) {
                foreach ((array) $invoice->line_items as $item) {
                    $code = $item['code'] ?? null;
                    if ($code === null) continue;

                    if (! isset($purchaseStats[$code])) {
                        $purchaseStats[$code] = ['count' => 0, 'last_purchased_at' => null];
                    }
                    $purchaseStats[$code]['count']++;
                    if ($purchaseStats[$code]['last_purchased_at'] === null || $invoice->created_at->gt($purchaseStats[$code]['last_purchased_at'])) {
                        $purchaseStats[$code]['last_purchased_at'] = $invoice->created_at;
                    }
                }
            }
        }

        $bought = [];
        foreach ($boughtCodes as $code) {
            if ($catalog->product($code) !== []) {
                $product = $buildProduct($code);
                $product['purchase_count'] = $purchaseStats[$code]['count'] ?? 0;
                $product['last_purchased_at'] = $purchaseStats[$code]['last_purchased_at'] ?? null;
                $bought[] = $product;
            }
        }

        $boughtGroups = $groupProducts($bought);

        return [
            'billable' => $billable,
            'availableGroups' => $availableGroups,
            'boughtGroups' => $boughtGroups,
            'currencySymbol' => $currencySymbol,
            'reverseCharge' => $reverseCharge,
        ];
    }
};

?>

<div class="space-y-8">
    {{-- Page header --}}
    <div>
        <flux:heading size="xl">{{ __('billing::portal.products.title') }}</flux:heading>
        <flux:subheading>{{ __('billing::portal.products.subtitle') }}</flux:subheading>
    </div>

    @if (! $billable)
        <flux:callout variant="warning" icon="exclamation-triangle">
            {{ __('billing::portal.no_billable') }}
        </flux:callout>
    @elseif (empty($availableGroups) && empty($boughtGroups))
        <flux:callout icon="information-circle" color="zinc" inline>
            {{ __('billing::portal.products.none_available') }}
        </flux:callout>
    @else
        {{-- Available products --}}
        @if (! empty($availableGroups))
            <div class="space-y-6">
                <flux:heading size="lg">{{ __('billing::portal.products.available_heading') }}</flux:heading>

                @foreach ($availableGroups as $group)
                <div class="space-y-3">
                    @if ($group['name'])
                        <flux:subheading size="lg">{{ $group['name'] }}</flux:subheading>
                    @endif

                    @foreach ($group['products'] as $product)
                        <flux:card class="relative p-0! hover:shadow-md transition">
                            <div class="flex flex-col gap-4 px-6 py-5 sm:flex-row sm:items-center sm:justify-between">
                                {{-- Left: icon + info --}}
                                <div class="flex items-start gap-4">
                                    @if ($product['image_url'])
                                        <div class="size-12 shrink-0 overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                            <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}" class="size-full object-cover">
                                        </div>
                                    @else
                                        <div class="flex size-12 shrink-0 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                            <flux:icon.shopping-bag class="size-5 text-zinc-400" />
                                        </div>
                                    @endif
                                    <div class="space-y-1">
                                        <flux:heading size="lg">{{ $product['name'] }}</flux:heading>
                                        @if ($product['description'])
                                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ $product['description'] }}</flux:text>
                                        @endif
                                        @if ($product['usage_type'] && $product['quantity'])
                                            <div class="flex items-center gap-1.5 text-sm text-zinc-500 dark:text-zinc-400">
                                                <flux:icon.plus-circle class="size-3.5 text-emerald-500" />
                                                <span>{{ number_format($product['quantity']) }} {{ $product['usage_type'] }}</span>
                                            </div>
                                        @endif
                                    </div>
                                </div>

                                {{-- Right: price + buy button --}}
                                <div class="flex items-center gap-6 sm:shrink-0">
                                    <div class="text-right">
                                        <div class="flex items-baseline gap-1">
                                            <span class="text-2xl font-bold tabular-nums tracking-tight">{{ $currencySymbol }}{{ number_format($product['price_net'] / 100, 2) }}</span>
                                        </div>
                                        <flux:text class="text-xs text-zinc-400">{{ $reverseCharge ? __('billing::portal.prices_excl_vat') : __('billing::portal.prices_incl_vat') }}</flux:text>
                                    </div>

                                    <div class="w-36">
                                        <flux:modal.trigger name="purchase-{{ $product['code'] }}">
                                            <flux:button class="w-full" variant="primary" size="sm" icon="shopping-cart">
                                                {{ __('billing::portal.products.buy') }}
                                            </flux:button>
                                        </flux:modal.trigger>
                                    </div>
                                </div>
                            </div>
                        </flux:card>

                        {{-- Purchase confirm modal --}}
                        <flux:modal name="purchase-{{ $product['code'] }}" class="max-w-md">
                            <div class="space-y-6">
                                <div class="space-y-2">
                                    <flux:heading size="lg">{{ __('billing::portal.products.confirm.title') }}</flux:heading>
                                    <flux:text>
                                        {{ __('billing::portal.products.confirm.body', [
                                            'product' => $product['name'],
                                            'price' => $currencySymbol . number_format($product['price_net'] / 100, 2),
                                        ]) }}
                                    </flux:text>
                                </div>

                                <div class="space-y-2">
                                    @foreach (($appliedCouponInfo[$product['code']] ?? []) as $info)
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
                                                wire:click="removeCoupon('{{ $product['code'] }}', '{{ $info['code'] }}')"
                                                :aria-label="__('billing::checkout.remove_coupon')"
                                            />
                                        </div>
                                    @endforeach

                                    @if ($this->canAddMoreCouponsFor($product['code']))
                                        <flux:input.group>
                                            <flux:input
                                                wire:model="couponInputs.{{ $product['code'] }}"
                                                wire:keydown.enter.prevent="applyCoupon('{{ $product['code'] }}')"
                                                :placeholder="__('billing::portal.coupon_code_placeholder')"
                                            />
                                            <flux:button type="button" wire:click="applyCoupon('{{ $product['code'] }}')" icon="check">
                                                {{ __('billing::portal.coupon_redeem_button') }}
                                            </flux:button>
                                        </flux:input.group>
                                    @endif

                                    @if (($couponDiscounts[$product['code']] ?? 0) > 0)
                                        <flux:text class="text-xs text-emerald-600 dark:text-emerald-400">
                                            −{{ $currencySymbol }}{{ number_format(($couponDiscounts[$product['code']] ?? 0) / 100, 2) }} {{ __('billing::portal.coupon_discount_applied') }}
                                        </flux:text>
                                    @endif

                                    @if (! empty($couponErrors[$product['code']] ?? null))
                                        <flux:text class="text-xs text-rose-600 dark:text-rose-400">{{ $couponErrors[$product['code']] }}</flux:text>
                                    @endif
                                </div>

                                <div class="flex justify-end gap-2">
                                    <flux:modal.close>
                                        <flux:button variant="ghost">{{ __('billing::portal.products.confirm.cancel') }}</flux:button>
                                    </flux:modal.close>
                                    <flux:button
                                        variant="primary"
                                        icon="shopping-cart"
                                        wire:click="purchase('{{ $product['code'] }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="purchase"
                                        x-on:click="$flux.modal('purchase-{{ $product['code'] }}').close()"
                                    >
                                        <span wire:loading.remove wire:target="purchase('{{ $product['code'] }}')">{{ __('billing::portal.products.confirm.confirm') }}</span>
                                        <span wire:loading wire:target="purchase('{{ $product['code'] }}')">{{ __('billing::portal.products.confirm.processing') }}</span>
                                    </flux:button>
                                </div>
                            </div>
                        </flux:modal>
                    @endforeach
                </div>
                @endforeach
            </div>
        @endif

        {{-- Bought products --}}
        @if (! empty($boughtGroups))
            <div class="space-y-6">
                <flux:heading size="lg">{{ __('billing::portal.products.bought_heading') }}</flux:heading>

                @foreach ($boughtGroups as $group)
                <div class="space-y-3">
                    @if ($group['name'])
                        <flux:subheading size="lg">{{ $group['name'] }}</flux:subheading>
                    @endif

                    @foreach ($group['products'] as $product)
                        <flux:card class="relative p-0!">
                            <div class="flex flex-col gap-4 px-6 py-5 sm:flex-row sm:items-center sm:justify-between">
                                {{-- Left: icon + info --}}
                                <div class="flex items-start gap-4">
                                    @if ($product['image_url'])
                                        <div class="size-12 shrink-0 overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                            <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}" class="size-full object-cover grayscale-30">
                                        </div>
                                    @else
                                        <div class="flex size-12 shrink-0 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                            <flux:icon.shopping-bag class="size-5 text-zinc-400" />
                                        </div>
                                    @endif
                                    <div class="space-y-1">
                                        <div class="flex items-center gap-3">
                                            <flux:heading size="lg">{{ $product['name'] }}</flux:heading>
                                            <flux:badge size="sm" color="lime">{{ trans_choice('billing::portal.products.purchased_count', $product['purchase_count'], ['count' => $product['purchase_count']]) }}</flux:badge>
                                        </div>
                                        @if ($product['description'])
                                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ $product['description'] }}</flux:text>
                                        @endif
                                        @if ($product['last_purchased_at'])
                                            <flux:text class="text-xs text-zinc-400 dark:text-zinc-500">
                                                {{ __('billing::portal.products.last_purchased_at', ['date' => BillingTime::display($product['last_purchased_at'], $billable)->translatedFormat('d. M Y')]) }}
                                            </flux:text>
                                        @endif
                                    </div>
                                </div>

                                {{-- Right: price (no button) --}}
                                <div class="flex items-center sm:shrink-0">
                                    <div class="text-right">
                                        <span class="text-2xl font-bold tabular-nums tracking-tight text-zinc-400 dark:text-zinc-500">{{ $currencySymbol }}{{ number_format($product['price_net'] / 100, 2) }}</span>
                                    </div>
                                </div>
                            </div>
                        </flux:card>
                    @endforeach
                </div>
                @endforeach
            </div>
        @endif
    @endif
</div>
