<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Models;

use GraystackIT\MollieBilling\Casts\UtcDatetime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Audit record of a VIES validation attempt for an EU VAT number.
 *
 * Persisted only after a successful round-trip to VIES (`valid` is always
 * set to true or false). The full VIES response payload is stored in
 * `vies_response` for tax-audit-grade evidence — including request date,
 * registered company name and address as returned by VIES.
 *
 * Each `BillingInvoice` references the validation that was active when
 * the invoice was issued via `vat_validation_id`, so a tax authority can
 * always trace which validation a given invoice was based on.
 */
class BillingVatValidation extends Model
{
    protected $table = 'billing_vat_validations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'valid' => 'bool',
            'vies_response' => 'array',
            'checked_at' => UtcDatetime::class,
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
        return $this->hasMany(BillingInvoice::class, 'vat_validation_id');
    }
}
