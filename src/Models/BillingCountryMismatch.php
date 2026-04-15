<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Models;

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BillingCountryMismatch extends Model
{
    protected $table = 'billing_country_mismatches';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => CountryMismatchStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    public function correctiveInvoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class, 'corrective_invoice_id');
    }
}
