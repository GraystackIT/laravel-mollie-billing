<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Concerns\HasBilling;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Stand-in for a consuming app whose billable keeps its display name on a
 * separate column (`practice_name`) instead of the User-style `name`. Proves
 * the admin search/sort scopes can be redirected away from `name`/`email`.
 */
class BillableSearchSortTest_PracticeBillable extends Model implements Billable
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

    public function getBillingName(): string
    {
        return (string) ($this->practice_name ?? '');
    }

    public function scopeBillableSearch(Builder $query, string $term): Builder
    {
        return $query->where('practice_name', 'like', '%'.$term.'%');
    }

    public function scopeBillableOrderByName(Builder $query, string $direction): Builder
    {
        return $query->orderBy('practice_name', $direction);
    }

    public function getUsedBillingSeats(): int
    {
        return 0;
    }
}

it('searches the default name and email columns', function (): void {
    TestBillable::create(['name' => 'Acme Inc.', 'email' => 'acme@example.test']);
    TestBillable::create(['name' => 'Globex', 'email' => 'hello@globex.test']);

    expect(TestBillable::query()->billableSearch('acme')->pluck('email')->all())
        ->toBe(['acme@example.test']);

    expect(TestBillable::query()->billableSearch('globex.test')->pluck('name')->all())
        ->toBe(['Globex']);
});

it('sorts by the default name and email columns', function (): void {
    TestBillable::create(['name' => 'Zeta', 'email' => 'z@example.test']);
    TestBillable::create(['name' => 'Alpha', 'email' => 'a@example.test']);

    expect(TestBillable::query()->billableOrderByName('asc')->pluck('name')->all())
        ->toBe(['Alpha', 'Zeta']);

    expect(TestBillable::query()->billableOrderByEmail('desc')->pluck('email')->all())
        ->toBe(['z@example.test', 'a@example.test']);
});

it('routes search and sort through an overridden column', function (): void {
    BillableSearchSortTest_PracticeBillable::create([
        'name' => 'Dr. Jane Doe',
        'email' => 'jane@example.test',
        'practice_name' => 'Doe Family Practice',
    ]);
    BillableSearchSortTest_PracticeBillable::create([
        'name' => 'Dr. John Roe',
        'email' => 'john@example.test',
        'practice_name' => 'Anderson Dental',
    ]);

    // Search hits the practice_name column, not the personal name.
    expect(BillableSearchSortTest_PracticeBillable::query()->billableSearch('Family')->pluck('email')->all())
        ->toBe(['jane@example.test']);

    // Sort orders by practice_name: "Anderson Dental" before "Doe Family Practice".
    expect(BillableSearchSortTest_PracticeBillable::query()->billableOrderByName('asc')->pluck('practice_name')->all())
        ->toBe(['Anderson Dental', 'Doe Family Practice']);
});
