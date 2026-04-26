<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Concerns\ValidatesVatNumber;
use GraystackIT\MollieBilling\Exceptions\ViesUnavailableException;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;

/**
 * Minimal Livewire-shaped stub: emulates the addError/resetErrorBag surface the trait calls,
 * so we can exercise the trait without booting Livewire.
 */
function makeVatTraitHost(): object
{
    return new class {
        use ValidatesVatNumber;

        public ?string $vat_number = null;
        public string $billing_country = 'AT';
        public ?bool $vatNumberValid = null;
        public ?string $vatStatusMessage = null;

        /** @var array<string, array<int, string>> */
        public array $errors = [];

        public function addError(string $field, string $message): void
        {
            $this->errors[$field][] = $message;
        }

        public function resetErrorBag(string $field): void
        {
            unset($this->errors[$field]);
        }
    };
}

it('rejects malformed VAT numbers', function (): void {
    $host = makeVatTraitHost();
    $host->vat_number = 'not-a-vat';

    $vat = Mockery::mock(VatCalculationService::class);
    $vat->shouldNotReceive('validateVatNumber');

    $host->validateVatNumberLive($vat);

    expect($host->vatNumberValid)->toBeFalse();
    expect($host->errors)->toHaveKey('vat_number');
});

it('rejects when prefix does not match the country', function (): void {
    $host = makeVatTraitHost();
    $host->billing_country = 'DE';
    $host->vat_number = 'ATU12345678';

    $vat = Mockery::mock(VatCalculationService::class);
    $vat->shouldNotReceive('validateVatNumber');

    $host->validateVatNumberLive($vat);

    expect($host->vatNumberValid)->toBeFalse();
    expect($host->errors['vat_number'][0] ?? null)
        ->toBe(__('billing::checkout.vat_country_mismatch'));
});

it('marks the VAT number valid on a successful VIES check', function (): void {
    $host = makeVatTraitHost();
    $host->vat_number = 'ATU12345678';

    $vat = Mockery::mock(VatCalculationService::class);
    $vat->shouldReceive('validateVatNumber')->once()->with('ATU12345678')->andReturnTrue();

    $host->validateVatNumberLive($vat);

    expect($host->vatNumberValid)->toBeTrue();
    expect($host->errors)->toBeEmpty();
});

it('flags VIES outage as indeterminate (no error, no green check)', function (): void {
    $host = makeVatTraitHost();
    $host->vat_number = 'ATU12345678';

    $vat = Mockery::mock(VatCalculationService::class);
    $vat->shouldReceive('validateVatNumber')->once()->andThrow(new ViesUnavailableException('down'));

    $host->validateVatNumberLive($vat);

    expect($host->vatNumberValid)->toBeNull();
    expect($host->vatStatusMessage)->toBe(__('billing::checkout.vies_unavailable'));
    expect($host->errors)->toBeEmpty();
});

it('clears state for an empty VAT number', function (): void {
    $host = makeVatTraitHost();
    $host->vat_number = '';
    $host->vatNumberValid = false;
    $host->vatStatusMessage = 'previous';

    $vat = Mockery::mock(VatCalculationService::class);
    $vat->shouldNotReceive('validateVatNumber');

    $host->validateVatNumberLive($vat);

    expect($host->vatNumberValid)->toBeNull();
    expect($host->vatStatusMessage)->toBeNull();
});
