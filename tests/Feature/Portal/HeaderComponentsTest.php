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
    // The admin layout hands `routeParameters` to its component, so it needs a
    // real admin screen rather than the portal dashboard.
    $component = $layout === 'admin'
        ? 'mollie-billing::admin.dashboard'
        : 'mollie-billing::dashboard';

    return view('mollie-billing::layouts.'.$layout, [
        'livewireComponent' => $component,
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

it('renders configured header components in the admin layout', function (): void {
    config()->set('mollie-billing.header_components', ['billing-header-probe']);

    expect(renderBillingLayout('admin'))->toContain('header-probe-marker');
});

it('renders no header component in the admin layout when none are configured', function (): void {
    config()->set('mollie-billing.header_components', []);

    expect(renderBillingLayout('admin'))->not->toContain('header-probe-marker');
});

it('renders the admin layout in the active locale', function (): void {
    app()->setLocale('de');

    $html = renderBillingLayout('admin');

    expect($html)->toContain('<html lang="de"')
        ->and($html)->toContain('Übersicht');
});

it('renders every configured header component, not just the first', function (): void {
    Livewire::component('billing-header-probe-two', HeaderProbeComponent::class);
    config()->set('mollie-billing.header_components', ['billing-header-probe', 'billing-header-probe-two']);

    expect(substr_count(renderBillingLayout('portal'), 'header-probe-marker'))->toBe(2);
});
