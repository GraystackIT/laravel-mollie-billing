<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Component;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * @param  array<string, mixed>  $lines
 * @return array<string, string>
 */
function flattenAdminLang(array $lines, string $prefix = ''): array
{
    $out = [];
    foreach ($lines as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
        if (is_array($value)) {
            $out += flattenAdminLang($value, $path);
        } else {
            $out[$path] = (string) $value;
        }
    }

    return $out;
}

/** @return array<int, string> */
function adminLangPlaceholders(string $value): array
{
    preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $value, $matches);
    $found = array_values(array_unique($matches[0]));
    sort($found);

    return $found;
}

it('keeps the admin lang files in exact key parity', function (): void {
    $base = dirname(__DIR__, 3).'/resources/lang';
    $en = flattenAdminLang(require $base.'/en/admin.php');
    $de = flattenAdminLang(require $base.'/de/admin.php');

    expect(array_diff(array_keys($en), array_keys($de)))->toBe([]);
    expect(array_diff(array_keys($de), array_keys($en)))->toBe([]);
});

it('keeps the admin lang files in exact placeholder parity', function (): void {
    $base = dirname(__DIR__, 3).'/resources/lang';
    $en = flattenAdminLang(require $base.'/en/admin.php');
    $de = flattenAdminLang(require $base.'/de/admin.php');

    $mismatched = [];
    foreach ($en as $key => $value) {
        if (! isset($de[$key])) {
            continue;
        }
        if (adminLangPlaceholders($value) !== adminLangPlaceholders($de[$key])) {
            $mismatched[] = $key;
        }
    }

    expect($mismatched)->toBe([]);
});

it('keeps currency symbols out of the admin lang files', function (): void {
    $base = dirname(__DIR__, 3).'/resources/lang';

    foreach (['en', 'de'] as $locale) {
        $offenders = [];
        foreach (flattenAdminLang(require $base.'/'.$locale.'/admin.php') as $key => $value) {
            if (preg_match('/[€£$]/u', $value) === 1) {
                $offenders[] = $key;
            }
        }

        expect($offenders)->toBe([]);
    }
});

it('drops the lang keys of the deleted refund-modal component', function (): void {
    $base = dirname(__DIR__, 3).'/resources/lang';

    foreach (['en', 'de'] as $locale) {
        $keys = array_keys(flattenAdminLang(require $base.'/'.$locale.'/admin.php'));
        $orphans = array_values(array_filter($keys, fn (string $k) => str_starts_with($k, 'refunds.form.')));

        expect($orphans)->toBe([]);
    }

    expect(file_exists(dirname(__DIR__, 3).'/resources/views/livewire/billing/admin/refunds/refund-modal.blade.php'))
        ->toBeFalse();
});

it('gives every admin header component its own wire key', function (): void {
    $this->withoutVite();
    config()->set('mollie-billing.header_components', ['billing-header-key-a', 'billing-header-key-b']);

    Livewire::component('billing-header-key-a', LayoutHeaderProbe::class);
    Livewire::component('billing-header-key-b', LayoutHeaderProbe::class);

    $html = view('mollie-billing::layouts.admin', [
        'livewireComponent' => 'mollie-billing::admin.dashboard',
    ])->render();

    expect($html)->toContain('billing-header-0')
        ->and($html)->toContain('billing-header-1');
});

it('locks resolvingId on the mismatches screen', function (): void {
    $b = new TestBillable;
    $b->forceFill(['email' => 'mismatch@example.com', 'name' => 'Mismatch'])->save();

    $mismatch = new BillingCountryMismatch;
    $mismatch->billable_type = $b->getMorphClass();
    $mismatch->billable_id = $b->getKey();
    $mismatch->status = CountryMismatchStatus::Pending;
    $mismatch->tax_country_user = 'DE';
    $mismatch->tax_country_payment = 'AT';
    $mismatch->save();

    $component = Livewire::test('mollie-billing::admin.mismatches.index')
        ->call('openResolve', $mismatch->id);

    expect(fn () => $component->set('resolvingId', 999))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

class LayoutHeaderProbe extends Component
{
    public function render(): string
    {
        return '<div>layout-header-probe</div>';
    }
}
