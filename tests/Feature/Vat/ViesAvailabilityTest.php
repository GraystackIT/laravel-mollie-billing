<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Exceptions\ViesUnavailableException;
use GraystackIT\MollieBilling\Models\BillingVatValidation;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mpociot\VatCalculator\Exceptions\VATCheckUnavailableException;
use Mpociot\VatCalculator\VatCalculator;

/**
 * Scriptable VIES stub. `$response` mirrors what mpociot's `getVATDetails()`
 * can hand back: a response object, a `false` (a SoapFault it swallowed because
 * `forward_soap_faults` defaults to off), or a thrown exception.
 */
final class ScriptedViesCalculator extends VatCalculator
{
    public mixed $response = null;

    public ?\Throwable $throws = null;

    public int $calls = 0;

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
        return 0.2;
    }

    public function getVATDetails($vatNumber)
    {
        $this->calls++;
        $this->received[] = (string) $vatNumber;

        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->response;
    }
}

beforeEach(function (): void {
    $this->vies = new ScriptedViesCalculator();
    $this->app->instance(VatCalculator::class, $this->vies);
});

it('reports a swallowed SOAP fault as unavailable, not as an invalid number', function (): void {
    // mpociot returns false from the SOAP branch only after catching a SoapFault
    // (MS_UNAVAILABLE, SERVER_BUSY, timeout, …). A well-formed but unregistered
    // number comes back as an object with valid=false, never as false.
    $this->vies->response = false;

    expect(fn () => app(VatCalculationService::class)->validateVatNumber('ATU12345678'))
        ->toThrow(ViesUnavailableException::class);
});

it('reports a forwarded VIES outage fault as unavailable', function (): void {
    $this->vies->throws = new VATCheckUnavailableException('MS_UNAVAILABLE');

    expect(fn () => app(VatCalculationService::class)->validateVatNumber('ATU12345678'))
        ->toThrow(ViesUnavailableException::class);
});

it('reports an INVALID_INPUT fault as invalid rather than unavailable', function (): void {
    // VIES faults on malformed input too. That is a verdict, not an outage —
    // surfacing it as "try again later" would loop the customer forever.
    $this->vies->throws = new VATCheckUnavailableException('INVALID_INPUT');

    expect(app(VatCalculationService::class)->validateVatNumber('ATU00000000'))->toBeFalse();
});

it('reports a well-formed unregistered number as invalid', function (): void {
    $this->vies->response = (object) ['valid' => false, 'countryCode' => 'AT'];

    expect(app(VatCalculationService::class)->validateVatNumber('ATU99999999'))->toBeFalse();
});

it('reports a registered number as valid', function (): void {
    $this->vies->response = (object) ['valid' => true, 'countryCode' => 'AT', 'name' => 'Acme GmbH'];

    expect(app(VatCalculationService::class)->validateVatNumber('ATU12345678'))->toBeTrue();
});

it('persists no validation row when VIES is unavailable', function (): void {
    $this->vies->response = false;

    $billable = TestBillable::create(['name' => 'Acme', 'billing_country' => 'AT']);

    expect(fn () => app(VatCalculationService::class)->validateAndPersist($billable, 'ATU12345678'))
        ->toThrow(ViesUnavailableException::class);

    // A valid=false row would suppress reverse-charge permanently for this number.
    expect(BillingVatValidation::count())->toBe(0);
});

it('still persists an invalid verdict when VIES answers', function (): void {
    $this->vies->response = (object) ['valid' => false, 'countryCode' => 'AT'];

    $billable = TestBillable::create(['name' => 'Acme', 'billing_country' => 'AT']);

    $validation = app(VatCalculationService::class)->validateAndPersist($billable, 'ATU99999999');

    expect($validation->valid)->toBeFalse();
    expect(BillingVatValidation::count())->toBe(1);
});

it('treats a false from the GB/HMRC branch as invalid', function (): void {
    // The UK path is REST and answers false for a genuine 400/404.
    $this->vies->response = false;

    expect(app(VatCalculationService::class)->validateVatNumber('GB123456789'))->toBeFalse();
});

it('turns forward_soap_faults on so real outages surface as exceptions', function (): void {
    config()->set('vat_calculator.forward_soap_faults', false);

    app(VatCalculationService::class);

    expect(config('vat_calculator.forward_soap_faults'))->toBeTrue();
});
