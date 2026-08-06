<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Concerns\ValidatesVatNumber;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\CountryResolver;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mpociot\VatCalculator\VatCalculator;

/**
 * Greece is `GR` in ISO 3166-1 (and therefore in the country select, in
 * `billing_country` and on invoices) but `EL` in the VIES VAT registry. Without
 * a mapping in both directions a Greek business can never obtain reverse charge:
 * the correct `EL…` number fails the prefix gate and `GR…` faults at VIES.
 */
final class GreekViesCalculator extends VatCalculator
{
    /** @var list<string> */
    public array $received = [];

    public function __construct()
    {
        parent::__construct([]);
    }

    public function shouldCollectVAT($countryCode): bool
    {
        return true;
    }

    public function getTaxRateForCountry($countryCode, $company = false, $type = null): float
    {
        return 0.24;
    }

    public function getVATDetails($vatNumber)
    {
        $this->received[] = (string) $vatNumber;

        // Mirror VIES: it only knows the EL country code for Greece.
        return str_starts_with((string) $vatNumber, 'EL')
            ? (object) ['valid' => true, 'countryCode' => 'EL']
            : false;
    }
}

function greekVatTraitHost(): object
{
    return new class
    {
        use ValidatesVatNumber;

        public ?string $vat_number = null;
        public string $billing_country = 'GR';
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

beforeEach(function (): void {
    $this->vies = new GreekViesCalculator();
    $this->app->instance(VatCalculator::class, $this->vies);
});

it('maps ISO GR to the EL VAT prefix and back', function (): void {
    expect(CountryResolver::vatPrefixFor('GR'))->toBe('EL');
    expect(CountryResolver::vatPrefixFor('gr'))->toBe('EL');
    expect(CountryResolver::isoFromVatPrefix('EL'))->toBe('GR');
    expect(CountryResolver::sameCountry('EL', 'GR'))->toBeTrue();

    // Everything else passes through untouched.
    expect(CountryResolver::vatPrefixFor('AT'))->toBe('AT');
    expect(CountryResolver::isoFromVatPrefix('AT'))->toBe('AT');
    expect(CountryResolver::sameCountry('AT', 'DE'))->toBeFalse();
});

it('accepts an EL VAT number for billing country GR', function (): void {
    $host = greekVatTraitHost();
    $host->vat_number = 'EL123456789';

    $host->validateVatNumberLive(app(VatCalculationService::class));

    expect($host->errors)->toBeEmpty();
    expect($host->vatNumberValid)->toBeTrue();
});

it('rewrites a GR-prefixed VAT number to the EL form VIES expects', function (): void {
    $host = greekVatTraitHost();
    $host->vat_number = 'GR123456789';

    $host->validateVatNumberLive(app(VatCalculationService::class));

    expect($host->vat_number)->toBe('EL123456789');
    expect($host->errors)->toBeEmpty();
    expect($host->vatNumberValid)->toBeTrue();
    expect($this->vies->received)->toBe(['EL123456789']);
});

it('still rejects a genuinely mismatched prefix', function (): void {
    $host = greekVatTraitHost();
    $host->billing_country = 'AT';
    $host->vat_number = 'EL123456789';

    $host->validateVatNumberLive(app(VatCalculationService::class));

    expect($host->vatNumberValid)->toBeFalse();
    expect($host->errors['vat_number'][0] ?? null)->toBe(__('billing::checkout.vat_country_mismatch'));
});

it('persists the ISO country code GR for an EL VAT number', function (): void {
    $billable = TestBillable::create(['name' => 'Hellas AE', 'billing_country' => 'GR']);

    $validation = app(VatCalculationService::class)->validateAndPersist($billable, 'EL123456789');

    // Every other country column in the schema stores ISO codes.
    expect($validation->country_code)->toBe('GR');
    expect($validation->vat_number)->toBe('EL123456789');
    expect($validation->valid)->toBeTrue();
});

it('normalises a GR-prefixed number before persisting it', function (): void {
    $billable = TestBillable::create(['name' => 'Hellas AE', 'billing_country' => 'GR']);

    $validation = app(VatCalculationService::class)->validateAndPersist($billable, 'gr 123 456 789');

    expect($validation->vat_number)->toBe('EL123456789');
    expect($validation->country_code)->toBe('GR');
    expect($validation->valid)->toBeTrue();
});

it('grants reverse charge to a Greek business buying cross-border', function (): void {
    config()->set('mollie-billing.vat.seller_country', 'AT');

    $billable = TestBillable::create([
        'name' => 'Hellas AE',
        'billing_country' => 'GR',
        'vat_number' => 'EL123456789',
    ]);
    app(VatCalculationService::class)->validateAndPersist($billable, 'EL123456789');

    $result = app(VatCalculationService::class)->calculate('GR', 10000, $billable->refresh());

    expect($result['vat'])->toBe(0);
    expect($result['rate'])->toBe(0.0);
});
