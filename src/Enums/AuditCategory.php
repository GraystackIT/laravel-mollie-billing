<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Enums;

enum AuditCategory: string
{
    case Subscription = 'subscription';
    case Payment = 'payment';
    case Invoice = 'invoice';
    case PaymentMethod = 'payment_method';
    case Coupon = 'coupon';
    case Trial = 'trial';
    case Usage = 'usage';
    case Compliance = 'compliance';

    public function label(): string
    {
        return __('billing::audit.category.'.$this->value);
    }

    public function icon(): string
    {
        return match ($this) {
            self::Subscription => 'arrow-path',
            self::Payment => 'credit-card',
            self::Invoice => 'document-text',
            self::PaymentMethod => 'wallet',
            self::Coupon => 'ticket',
            self::Trial => 'sparkles',
            self::Usage => 'chart-bar',
            self::Compliance => 'shield-check',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Subscription => 'blue',
            self::Payment => 'green',
            self::Invoice => 'zinc',
            self::PaymentMethod => 'purple',
            self::Coupon => 'amber',
            self::Trial => 'teal',
            self::Usage => 'cyan',
            self::Compliance => 'red',
        };
    }
}
