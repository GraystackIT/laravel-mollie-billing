<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Tests\Support\HeaderProbeComponent;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->withoutVite();

    Livewire::component('billing-header-probe', HeaderProbeComponent::class);
});

function renderBillingLayout(string $layout): string
{
    return view('mollie-billing::layouts.'.$layout, [
        'livewireComponent' => 'mollie-billing::dashboard',
        'backUrl' => null,
    ])->render();
}

it('renders configured header components in the portal layout', function (): void {
    config()->set('mollie-billing.header_components', ['billing-header-probe']);

    expect(renderBillingLayout('portal'))->toContain('header-probe-marker');
});

it('renders no header component in the portal layout when none are configured', function (): void {
    config()->set('mollie-billing.header_components', []);

    expect(renderBillingLayout('portal'))->not->toContain('header-probe-marker');
});

it('renders configured header components in the checkout layout', function (): void {
    config()->set('mollie-billing.header_components', ['billing-header-probe']);

    expect(renderBillingLayout('checkout'))->toContain('header-probe-marker');
});

it('renders no header component in the checkout layout when none are configured', function (): void {
    config()->set('mollie-billing.header_components', []);

    expect(renderBillingLayout('checkout'))->not->toContain('header-probe-marker');
});

it('renders every configured header component, not just the first', function (): void {
    Livewire::component('billing-header-probe-two', HeaderProbeComponent::class);
    config()->set('mollie-billing.header_components', ['billing-header-probe', 'billing-header-probe-two']);

    expect(substr_count(renderBillingLayout('portal'), 'header-probe-marker'))->toBe(2);
});
