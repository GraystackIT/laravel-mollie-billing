<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Testing;

use GraystackIT\MollieBilling\Concerns\HasBilling;
use GraystackIT\MollieBilling\Contracts\Billable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Minimal Eloquent model implementing Billable — for unit/feature tests.
 */
class TestBillable extends Model implements Billable
{
    use HasBilling;
    use HasFactory;

    protected $table = 'test_billables';

    protected $guarded = [];

    public $incrementing = true;

    protected $keyType = 'int';
}
