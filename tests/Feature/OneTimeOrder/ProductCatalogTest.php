<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.products', [
        'token-pack-500' => [
            'name' => '500 Token Pack',
            'description' => 'Top up your account with 500 tokens.',
            'image_url' => 'https://example.com/tokens.png',
            'price_net' => 4900,
            'usage_type' => 'Tokens',
            'quantity' => 500,
        ],
        'consulting-hour' => [
            'name' => '1h Consulting',
            'description' => 'Book a one-hour consulting session.',
            'image_url' => null,
            'price_net' => 14900,
            'onetimeonly' => true,
        ],
    ]);
});

it('returns all product codes', function (): void {
    $catalog = app(SubscriptionCatalogInterface::class);

    expect($catalog->allProducts())->toBe(['token-pack-500', 'consulting-hour']);
});

it('returns full product config', function (): void {
    $catalog = app(SubscriptionCatalogInterface::class);

    $product = $catalog->product('token-pack-500');

    expect($product['name'])->toBe('500 Token Pack');
    expect($product['price_net'])->toBe(4900);
    expect($product['usage_type'])->toBe('Tokens');
    expect($product['quantity'])->toBe(500);
});

it('returns empty array for unknown product', function (): void {
    $catalog = app(SubscriptionCatalogInterface::class);

    expect($catalog->product('nonexistent'))->toBe([]);
});

it('returns product name', function (): void {
    $catalog = app(SubscriptionCatalogInterface::class);

    expect($catalog->productName('token-pack-500'))->toBe('500 Token Pack');
    expect($catalog->productName('nonexistent'))->toBeNull();
});

it('returns product description', function (): void {
    $catalog = app(SubscriptionCatalogInterface::class);

    expect($catalog->productDescription('token-pack-500'))->toBe('Top up your account with 500 tokens.');
    expect($catalog->productDescription('nonexistent'))->toBeNull();
});

it('returns product image url', function (): void {
    $catalog = app(SubscriptionCatalogInterface::class);

    expect($catalog->productImageUrl('token-pack-500'))->toBe('https://example.com/tokens.png');
    expect($catalog->productImageUrl('consulting-hour'))->toBeNull();
});

it('returns product price net', function (): void {
    $catalog = app(SubscriptionCatalogInterface::class);

    expect($catalog->productPriceNet('token-pack-500'))->toBe(4900);
    expect($catalog->productPriceNet('consulting-hour'))->toBe(14900);
    expect($catalog->productPriceNet('nonexistent'))->toBe(0);
});

it('returns usage type for usage-linked products', function (): void {
    $catalog = app(SubscriptionCatalogInterface::class);

    expect($catalog->productUsageType('token-pack-500'))->toBe('Tokens');
    expect($catalog->productUsageType('consulting-hour'))->toBeNull();
});

it('returns quantity for usage-linked products', function (): void {
    $catalog = app(SubscriptionCatalogInterface::class);

    expect($catalog->productQuantity('token-pack-500'))->toBe(500);
    expect($catalog->productQuantity('consulting-hour'))->toBeNull();
});

it('returns onetimeonly flag', function (): void {
    $catalog = app(SubscriptionCatalogInterface::class);

    expect($catalog->productOneTimeOnly('consulting-hour'))->toBeTrue();
    expect($catalog->productOneTimeOnly('token-pack-500'))->toBeFalse();
    expect($catalog->productOneTimeOnly('nonexistent'))->toBeFalse();
});
