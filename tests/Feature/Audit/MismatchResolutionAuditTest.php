<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Events\CountryMismatchResolved;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Support\BillingAuditEntry;
use GraystackIT\MollieBilling\Testing\TestBillable;

/**
 * Resolving a country mismatch is a tax decision an operator makes by hand: the
 * three signals disagree, and a human picks one. The audit row recorded that it
 * happened and which two countries disagreed, but neither which country won nor on
 * what grounds — so the entry could not answer the only two questions an auditor
 * asks of it.
 */
function mismatchAuditBillable(): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Mismatch Audit',
        'email' => uniqid('mismatch-audit-').'@x.test',
        'billing_country' => 'AT',
    ]);

    return $b;
}

function mismatchFor(TestBillable $billable, string $chosen): BillingCountryMismatch
{
    /** @var BillingCountryMismatch $m */
    $m = BillingCountryMismatch::create([
        'billable_type' => $billable::class,
        'billable_id' => $billable->getKey(),
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
        'tax_country_ip' => 'CH',
        'status' => CountryMismatchStatus::Resolved,
        'chosen_country' => $chosen,
    ]);

    return $m;
}

it('records the chosen country and the operator justification', function (): void {
    $billable = mismatchAuditBillable();
    $mismatch = mismatchFor($billable, 'DE');

    event(new CountryMismatchResolved(
        $billable,
        $mismatch,
        null,
        'Customer produced a German commercial register extract; the AT address is a mail drop.',
    ));

    $row = BillingAuditEntry::model()::query()->orderBy('id')->get()->last();

    expect($row)->not->toBeNull()
        ->and($row->event)->toBe('country_mismatch_resolved');

    $replace = $row->properties['replace'];

    expect($replace['chosen_country'])->toBe('DE')
        ->and($replace['justification'])->toContain('commercial register')
        ->and($replace['declared_country'])->toBe('AT')
        ->and($replace['payment_country'])->toBe('DE');
});

it('records a resolution that carries no justification without failing', function (): void {
    // The event's parameter is optional so an app dispatching it directly, or an
    // older queued payload, still logs rather than throwing.
    $billable = mismatchAuditBillable();

    event(new CountryMismatchResolved($billable, mismatchFor($billable, 'CH'), null));

    $replace = BillingAuditEntry::model()::query()->orderBy('id')->get()->last()->properties['replace'];

    expect($replace['chosen_country'])->toBe('CH')
        ->and($replace['justification'])->toBeNull();
});

it('truncates an over-long justification rather than storing it whole', function (): void {
    $billable = mismatchAuditBillable();

    event(new CountryMismatchResolved($billable, mismatchFor($billable, 'DE'), null, str_repeat('x', 5000)));

    $replace = BillingAuditEntry::model()::query()->orderBy('id')->get()->last()->properties['replace'];

    expect(strlen((string) $replace['justification']))->toBeLessThan(5000);
});
