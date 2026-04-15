<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CouponRedemption extends Model
{
    public $timestamps = false;

    protected $table = 'coupon_redemptions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'credits_applied' => 'array',
            'grant_applied_snapshot' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class, 'invoice_id');
    }
}
