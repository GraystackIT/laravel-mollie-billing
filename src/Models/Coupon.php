<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Models;

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    protected $table = 'coupons';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => CouponType::class,
            'discount_type' => DiscountType::class,
            'active' => 'bool',
            'stackable' => 'bool',
            'credits_payload' => 'array',
            'grant_addon_codes' => 'array',
            'applicable_plans' => 'array',
            'applicable_intervals' => 'array',
            'applicable_addons' => 'array',
            'applicable_usages' => 'array',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
        ];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    public function isWithinValidity(\DateTimeInterface $at): bool
    {
        $timestamp = $at->getTimestamp();

        if ($this->valid_from && $this->valid_from->getTimestamp() > $timestamp) {
            return false;
        }

        if ($this->valid_until && $this->valid_until->getTimestamp() < $timestamp) {
            return false;
        }

        return $this->active;
    }

    public function hasGlobalRedemptionsLeft(): bool
    {
        return $this->max_redemptions === null
            || $this->redemptions_count < $this->max_redemptions;
    }
}
