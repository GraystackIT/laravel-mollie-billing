<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\MollieSubscriptionStatus;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use UnitEnum;

/**
 * Human-readable labels and Flux badge colors for enums used across the admin UI.
 * Kept as a separate helper (rather than methods on the enums) so the package's
 * domain enums stay presentation-agnostic.
 */
final class EnumLabels
{
    public static function label(mixed $value): string
    {
        $enum = self::toEnum($value);
        if ($enum === null) {
            return is_scalar($value) ? (string) $value : '—';
        }

        return match (true) {
            $enum instanceof SubscriptionStatus => match ($enum) {
                SubscriptionStatus::Active => 'Active',
                SubscriptionStatus::Trial => 'Trial',
                SubscriptionStatus::PastDue => 'Past due',
                SubscriptionStatus::Cancelled => 'Cancelled',
                SubscriptionStatus::Expired => 'Expired',
            },
            $enum instanceof CouponType => match ($enum) {
                CouponType::FirstPayment => 'First payment',
                CouponType::Recurring => 'Recurring',
                CouponType::Credits => 'Credits',
                CouponType::TrialExtension => 'Trial extension',
                CouponType::AccessGrant => 'Access grant',
            },
            $enum instanceof DiscountType => match ($enum) {
                DiscountType::Percentage => 'Percentage',
                DiscountType::Fixed => 'Fixed amount',
            },
            $enum instanceof InvoiceStatus => match ($enum) {
                InvoiceStatus::Paid => 'Paid',
                InvoiceStatus::Open => 'Open',
                InvoiceStatus::Failed => 'Failed',
                InvoiceStatus::Refunded => 'Refunded',
            },
            $enum instanceof RefundReasonCode => match ($enum) {
                RefundReasonCode::ServiceOutage => 'Service outage',
                RefundReasonCode::BillingError => 'Billing error',
                RefundReasonCode::Goodwill => 'Goodwill',
                RefundReasonCode::Chargeback => 'Chargeback',
                RefundReasonCode::Cancellation => 'Cancellation',
                RefundReasonCode::Other => 'Other',
            },
            $enum instanceof SubscriptionInterval => match ($enum) {
                SubscriptionInterval::Monthly => 'Monthly',
                SubscriptionInterval::Yearly => 'Yearly',
            },
            $enum instanceof CountryMismatchStatus => match ($enum) {
                CountryMismatchStatus::Pending => 'Pending',
                CountryMismatchStatus::Resolved => 'Resolved',
            },
            $enum instanceof MollieSubscriptionStatus => match ($enum) {
                MollieSubscriptionStatus::Pending => 'Pending',
                MollieSubscriptionStatus::Active => 'Active',
                MollieSubscriptionStatus::Canceled => 'Canceled',
                MollieSubscriptionStatus::Suspended => 'Suspended',
                MollieSubscriptionStatus::Completed => 'Completed',
            },
            $enum instanceof SubscriptionSource => match ($enum) {
                SubscriptionSource::None => 'None',
                SubscriptionSource::Local => 'Local',
                SubscriptionSource::Mollie => 'Mollie',
            },
            default => (string) ($enum->value ?? $enum->name),
        };
    }

    /**
     * Flux badge color name (green, red, amber, blue, zinc, …).
     */
    public static function color(mixed $value): string
    {
        $enum = self::toEnum($value);
        if ($enum === null) {
            return 'zinc';
        }

        return match (true) {
            $enum instanceof SubscriptionStatus => match ($enum) {
                SubscriptionStatus::Active => 'green',
                SubscriptionStatus::Trial => 'blue',
                SubscriptionStatus::PastDue => 'red',
                SubscriptionStatus::Cancelled => 'zinc',
                SubscriptionStatus::Expired => 'zinc',
            },
            $enum instanceof InvoiceStatus => match ($enum) {
                InvoiceStatus::Paid => 'green',
                InvoiceStatus::Open => 'amber',
                InvoiceStatus::Failed => 'red',
                InvoiceStatus::Refunded => 'zinc',
            },
            $enum instanceof MollieSubscriptionStatus => match ($enum) {
                MollieSubscriptionStatus::Active => 'green',
                MollieSubscriptionStatus::Pending => 'amber',
                MollieSubscriptionStatus::Suspended => 'red',
                MollieSubscriptionStatus::Canceled => 'zinc',
                MollieSubscriptionStatus::Completed => 'zinc',
            },
            $enum instanceof CountryMismatchStatus => match ($enum) {
                CountryMismatchStatus::Pending => 'amber',
                CountryMismatchStatus::Resolved => 'green',
            },
            $enum instanceof CouponType => match ($enum) {
                CouponType::FirstPayment => 'blue',
                CouponType::Recurring => 'violet',
                CouponType::Credits => 'emerald',
                CouponType::TrialExtension => 'amber',
                CouponType::AccessGrant => 'cyan',
            },
            $enum instanceof RefundReasonCode => match ($enum) {
                RefundReasonCode::ServiceOutage => 'red',
                RefundReasonCode::BillingError => 'amber',
                RefundReasonCode::Chargeback => 'red',
                RefundReasonCode::Goodwill => 'blue',
                RefundReasonCode::Cancellation => 'zinc',
                RefundReasonCode::Other => 'zinc',
            },
            $enum instanceof SubscriptionSource => match ($enum) {
                SubscriptionSource::Mollie => 'blue',
                SubscriptionSource::Local => 'emerald',
                SubscriptionSource::None => 'zinc',
            },
            default => 'zinc',
        };
    }

    private static function toEnum(mixed $value): ?UnitEnum
    {
        if ($value instanceof UnitEnum) {
            return $value;
        }

        return null;
    }
}
