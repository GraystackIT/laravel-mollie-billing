<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Concerns\HasBilling;
use GraystackIT\MollieBilling\Contracts\Billable;
use Illuminate\Database\Eloquent\Model;

/**
 * Stand-in for a consuming app that uses a `User`-style model as the billable:
 * `name` already stores the personal name of the owner, and the company /
 * billing name is persisted on a separate column (here: `practice_name`).
 *
 * The override is the documented "Option A" — pointing the trait at a different
 * attribute by overriding `billingNameAttribute()`.
 */
class BillingNameOverrideTest_BillableA extends Model implements Billable
{
    use HasBilling;

    protected $table = 'test_billables';
    protected $guarded = [];
    public $incrementing = true;
    protected $keyType = 'int';

    protected function billingNameAttribute(): string
    {
        return 'practice_name';
    }

    public function getUsedBillingSeats(): int
    {
        return 0;
    }
}

/**
 * "Option B" — explicit override of both methods. Same end behavior, but proves
 * the accessor/mutator pair works without touching $billingNameAttribute.
 */
class BillingNameOverrideTest_BillableB extends Model implements Billable
{
    use HasBilling;

    protected $table = 'test_billables';
    protected $guarded = [];
    public $incrementing = true;
    protected $keyType = 'int';

    public function getBillingName(): string
    {
        return (string) ($this->practice_name ?? '');
    }

    public function setBillingName(string $name): void
    {
        $this->practice_name = $name;
    }

    public function getUsedBillingSeats(): int
    {
        return 0;
    }
}

it('defaults to reading and writing the model name attribute', function (): void {
    /** @var \GraystackIT\MollieBilling\Testing\TestBillable $billable */
    $billable = \GraystackIT\MollieBilling\Testing\TestBillable::create([
        'name' => 'Acme Inc.',
        'email' => 'acme@example.test',
    ]);

    expect($billable->getBillingName())->toBe('Acme Inc.');

    $billable->setBillingName('Acme International');
    $billable->save();

    expect($billable->fresh()->name)->toBe('Acme International');
});

it('routes setBillingName() to the overridden attribute (property override)', function (): void {
    $billable = BillingNameOverrideTest_BillableA::create([
        'name' => 'Dr. Jane Doe',
        'email' => 'jane@example.test',
    ]);

    // Simulate what `checkout.blade.php::submit()` now does: setter call,
    // then forceFill of the unrelated address fields, then one save().
    $billable->setBillingName('Doe Family Practice');
    $billable->forceFill([
        'billing_street' => 'Hauptstrasse 1',
        'billing_postal_code' => '1010',
        'billing_city' => 'Vienna',
        'billing_country' => 'AT',
    ])->save();

    $fresh = $billable->fresh();

    expect($fresh->name)->toBe('Dr. Jane Doe');
    expect($fresh->practice_name)->toBe('Doe Family Practice');
    expect($fresh->getBillingName())->toBe('Doe Family Practice');
    expect($fresh->billing_city)->toBe('Vienna');
});

it('routes setBillingName() to the overridden attribute (method override)', function (): void {
    $billable = BillingNameOverrideTest_BillableB::create([
        'name' => 'Dr. John Roe',
        'email' => 'john@example.test',
    ]);

    $billable->setBillingName('Roe Dental Group');
    $billable->save();

    $fresh = $billable->fresh();

    expect($fresh->name)->toBe('Dr. John Roe');
    expect($fresh->practice_name)->toBe('Roe Dental Group');
    expect($fresh->getBillingName())->toBe('Roe Dental Group');
});

it('returns empty string when the overridden attribute is null', function (): void {
    $billable = BillingNameOverrideTest_BillableA::create([
        'name' => 'Personal Name',
        'email' => 'personal@example.test',
    ]);

    expect($billable->getBillingName())->toBe('');
});
