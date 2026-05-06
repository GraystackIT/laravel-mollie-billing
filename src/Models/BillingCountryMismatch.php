<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Models;

use GraystackIT\MollieBilling\Casts\UtcDatetime;
use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BillingCountryMismatch extends Model
{
    protected $table = 'billing_country_mismatches';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => CountryMismatchStatus::class,
            'resolved_at' => UtcDatetime::class,
            'notified_at' => UtcDatetime::class,
            'created_at' => UtcDatetime::class,
            'updated_at' => UtcDatetime::class,
        ];
    }

    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(BillingInvoice::class, 'mismatch_id');
    }
}
