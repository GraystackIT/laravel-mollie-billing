<?php

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use Livewire\Component;

new class extends Component {
    public bool $processing = false;

    private function resolveBillable(): ?Billable
    {
        return MollieBilling::resolveBillable(request());
    }

    public function purchase(string $productCode): void
    {
        $billable = $this->resolveBillable();
        if (! $billable) return;

        $this->processing = true;

        try {
            $result = $billable->purchaseOneTimeOrder($productCode);

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

        $buildProduct = function (string $code) use ($catalog): array {
            return [
                'code' => $code,
                'name' => $catalog->productName($code) ?? $code,
                'description' => $catalog->productDescription($code),
                'image_url' => $catalog->productImageUrl($code),
                'price_net' => $catalog->productPriceNet($code),
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
                                        <flux:text class="text-xs text-zinc-400">{{ __('billing::portal.prices_excl_vat') }}</flux:text>
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
                                                {{ __('billing::portal.products.last_purchased_at', ['date' => $product['last_purchased_at']->translatedFormat('d. M Y')]) }}
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
