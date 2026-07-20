<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

use GraystackIT\MollieBilling\Enums\AuditCategory;
use GraystackIT\MollieBilling\Events;
use Throwable;

/**
 * The single place mapping a billing event onto an audit-trail row.
 *
 * Adding an event here is all that is needed to have it audited — the listener
 * registration and the translation-coverage test both derive from this map.
 *
 * Property closures must return *raw scalars only*. Two rules matter:
 *  - Never dump the free-form arrays some events carry (`request`, `diff`,
 *    `pendingChange`, `lineItems`, `metadata`): they can be large and can hold
 *    customer data. Pull named fields out instead.
 *  - Never store a Throwable or its trace — class name plus a truncated message
 *    is enough to identify the failure in a timeline.
 */
final class BillingAuditMap
{
    private const MAX_MESSAGE_LENGTH = 500;

    /** @var array<class-string, BillingAuditDescriptor>|null */
    private static ?array $map = null;

    /**
     * @return array<class-string, BillingAuditDescriptor>
     */
    public static function all(): array
    {
        return self::$map ??= self::build();
    }

    public static function for(object $event): ?BillingAuditDescriptor
    {
        return self::all()[$event::class] ?? null;
    }

    /** Test seam — the map is memoised because it is built on every event dispatch. */
    public static function flush(): void
    {
        self::$map = null;
    }

    /**
     * @return array<class-string, BillingAuditDescriptor>
     */
    private static function build(): array
    {
        return [
            // ---------------------------------------------------------------
            // Subscription lifecycle
            // ---------------------------------------------------------------
            Events\SubscriptionCreated::class => self::make('subscription_created', AuditCategory::Subscription,
                fn (Events\SubscriptionCreated $e): array => [
                    'plan' => $e->planCode,
                    'interval' => $e->interval,
                ]),

            Events\SubscriptionCancelled::class => self::make('subscription_cancelled', AuditCategory::Subscription,
                fn (Events\SubscriptionCancelled $e): array => [
                    'immediately' => $e->immediately,
                ]),

            Events\SubscriptionResumed::class => self::make('subscription_resumed', AuditCategory::Subscription,
                fn (Events\SubscriptionResumed $e): array => []),

            Events\SubscriptionExpired::class => self::make('subscription_expired', AuditCategory::Subscription,
                fn (Events\SubscriptionExpired $e): array => []),

            Events\SubscriptionUpdated::class => self::make('subscription_updated', AuditCategory::Subscription,
                fn (Events\SubscriptionUpdated $e): array => [
                    // Only the changed keys, never the diff payload itself.
                    'changes' => implode(', ', array_keys($e->diff)),
                    'change_count' => count($e->diff),
                ]),

            Events\SubscriptionExtended::class => self::make('subscription_extended', AuditCategory::Subscription,
                fn (Events\SubscriptionExtended $e): array => [
                    'code' => $e->coupon->code,
                    'previous_ends_at' => $e->previousEndsAt?->toIso8601String(),
                    'new_ends_at' => $e->newEndsAt->toIso8601String(),
                ]),

            Events\SubscriptionUpgradedFromLocal::class => self::make('subscription_upgraded_from_local', AuditCategory::Subscription,
                fn (Events\SubscriptionUpgradedFromLocal $e): array => [
                    'old_plan' => $e->oldPlanCode,
                    'old_interval' => $e->oldInterval,
                    'new_plan' => $e->newPlanCode,
                    'new_interval' => $e->newInterval,
                ]),

            Events\SubscriptionActivationFailed::class => self::make('subscription_activation_failed', AuditCategory::Subscription,
                fn (Events\SubscriptionActivationFailed $e): array => [
                    'plan' => $e->planCode,
                    'interval' => $e->interval,
                    'payment_id' => $e->paymentId,
                    'invoice_id' => $e->invoiceId,
                    'reason' => self::truncate($e->reason),
                ]),

            Events\PlanChanged::class => self::make('plan_changed', AuditCategory::Subscription,
                fn (Events\PlanChanged $e): array => [
                    'old_plan' => $e->oldPlan,
                    'new_plan' => $e->newPlan,
                    'interval' => $e->interval,
                ]),

            Events\PlanChangePending::class => self::make('plan_change_pending', AuditCategory::Subscription,
                fn (Events\PlanChangePending $e): array => [
                    'new_plan' => self::stringOrNull($e->pendingChange['plan_code'] ?? null),
                    'interval' => self::stringOrNull($e->pendingChange['interval'] ?? null),
                    'payment_id' => $e->paymentId,
                ]),

            Events\PlanChangeFailed::class => self::make('plan_change_failed', AuditCategory::Subscription,
                fn (Events\PlanChangeFailed $e): array => [
                    'new_plan' => self::stringOrNull($e->pendingChange['plan_code'] ?? null),
                    'interval' => self::stringOrNull($e->pendingChange['interval'] ?? null),
                    'payment_id' => $e->paymentId,
                    'reason' => self::truncate($e->reason),
                ]),

            Events\SubscriptionChangeScheduled::class => self::make('subscription_change_scheduled', AuditCategory::Subscription,
                fn (Events\SubscriptionChangeScheduled $e): array => [
                    'new_plan' => self::stringOrNull($e->scheduledChange['plan_code'] ?? null),
                    'interval' => self::stringOrNull($e->scheduledChange['interval'] ?? null),
                    'scheduled_at' => $e->scheduledAt->toIso8601String(),
                ]),

            Events\SubscriptionChangeRescheduled::class => self::make('subscription_change_rescheduled', AuditCategory::Subscription,
                fn (Events\SubscriptionChangeRescheduled $e): array => [
                    'new_plan' => self::stringOrNull($e->newScheduledChange['plan_code'] ?? null),
                    'old_plan' => self::stringOrNull($e->previousScheduledChange['plan_code'] ?? null),
                ]),

            Events\SubscriptionChangeCancelled::class => self::make('subscription_change_cancelled', AuditCategory::Subscription,
                fn (Events\SubscriptionChangeCancelled $e): array => [
                    'new_plan' => self::stringOrNull($e->previousScheduledChange['plan_code'] ?? null),
                ]),

            Events\SubscriptionChangeApplyFailed::class => self::make('subscription_change_apply_failed', AuditCategory::Subscription,
                fn (Events\SubscriptionChangeApplyFailed $e): array => [
                    'new_plan' => self::stringOrNull($e->scheduledChange['plan_code'] ?? null),
                    ...self::exception($e->exception),
                ]),

            Events\SeatsChanged::class => self::make('seats_changed', AuditCategory::Subscription,
                fn (Events\SeatsChanged $e): array => [
                    'old_count' => $e->oldCount,
                    'new_count' => $e->newCount,
                ]),

            Events\AddonEnabled::class => self::make('addon_enabled', AuditCategory::Subscription,
                fn (Events\AddonEnabled $e): array => ['addon' => $e->addonCode]),

            Events\AddonDisabled::class => self::make('addon_disabled', AuditCategory::Subscription,
                fn (Events\AddonDisabled $e): array => ['addon' => $e->addonCode]),

            // ---------------------------------------------------------------
            // Payments
            // ---------------------------------------------------------------
            Events\PaymentSucceeded::class => self::make('payment_succeeded', AuditCategory::Payment,
                fn (Events\PaymentSucceeded $e): array => self::invoice($e->invoice)),

            Events\PaymentFailed::class => self::make('payment_failed', AuditCategory::Payment,
                fn (Events\PaymentFailed $e): array => [
                    'payment_id' => $e->paymentId,
                    'reason' => self::truncate($e->reason),
                ]),

            Events\PaymentAmountMismatch::class => self::make('payment_amount_mismatch', AuditCategory::Payment,
                fn (Events\PaymentAmountMismatch $e): array => [
                    'payment_id' => $e->paymentId,
                    'expected_cents' => $e->expectedGross,
                    'actual_cents' => $e->actualGross,
                ]),

            Events\DuplicatePaymentReceived::class => self::make('duplicate_payment_received', AuditCategory::Payment,
                fn (Events\DuplicatePaymentReceived $e): array => ['payment_id' => $e->paymentId]),

            Events\CheckoutStarted::class => self::make('checkout_started', AuditCategory::Payment,
                fn (Events\CheckoutStarted $e): array => []),

            Events\CheckoutAbandoned::class => self::make('checkout_abandoned', AuditCategory::Payment,
                fn (Events\CheckoutAbandoned $e): array => ['payment_id' => $e->paymentId]),

            Events\OneTimeOrderCompleted::class => self::make('one_time_order_completed', AuditCategory::Payment,
                fn (Events\OneTimeOrderCompleted $e): array => [
                    'product' => $e->productCode,
                    ...self::invoice($e->invoice),
                ]),

            Events\OneTimeOrderFailed::class => self::make('one_time_order_failed', AuditCategory::Payment,
                fn (Events\OneTimeOrderFailed $e): array => [
                    'product' => $e->productCode,
                    'payment_id' => $e->paymentId,
                    'reason' => self::truncate($e->reason),
                ]),

            Events\OverageCharged::class => self::make('overage_charged', AuditCategory::Payment,
                fn (Events\OverageCharged $e): array => [
                    // Count only — line items carry per-usage-type detail we don't
                    // want duplicated into every audit row.
                    'line_count' => count($e->lineItems),
                    ...self::invoice($e->invoice),
                ]),

            Events\OverageChargeFailed::class => self::make('overage_charge_failed', AuditCategory::Payment,
                fn (Events\OverageChargeFailed $e): array => [
                    'attempt' => $e->attempt,
                    ...self::exception($e->exception),
                ]),

            // ---------------------------------------------------------------
            // Invoices
            // ---------------------------------------------------------------
            Events\InvoiceCreated::class => self::make('invoice_created', AuditCategory::Invoice,
                fn (Events\InvoiceCreated $e): array => self::invoice($e->invoice)),

            Events\InvoiceRefunded::class => self::make('invoice_refunded', AuditCategory::Invoice,
                fn (Events\InvoiceRefunded $e): array => [
                    'serial' => $e->originalInvoice->serial_number,
                    'credit_note_serial' => $e->creditNote->serial_number,
                    'amount_cents' => (int) abs((int) $e->creditNote->amount_gross),
                    'reason_code' => self::stringOrNull($e->request['reason_code'] ?? null),
                ]),

            Events\CreditNoteIssued::class => self::make('credit_note_issued', AuditCategory::Invoice,
                fn (Events\CreditNoteIssued $e): array => [
                    'serial' => $e->creditNote->serial_number,
                    'original_serial' => $e->originalInvoice?->serial_number,
                    'amount_cents' => (int) abs((int) $e->creditNote->amount_gross),
                ]),

            Events\InvoicePdfRegenerated::class => self::make('invoice_pdf_regenerated', AuditCategory::Invoice,
                fn (Events\InvoicePdfRegenerated $e): array => [
                    'serial' => $e->invoice->serial_number,
                    'invoice_id' => $e->invoice->getKey(),
                ]),

            // ---------------------------------------------------------------
            // Payment method
            // ---------------------------------------------------------------
            Events\MandateUpdated::class => self::make('mandate_updated', AuditCategory::PaymentMethod,
                fn (Events\MandateUpdated $e): array => [
                    'previous_mandate_id' => $e->previousMandateId,
                    'new_mandate_id' => $e->newMandateId,
                ]),

            // ---------------------------------------------------------------
            // Coupons / grants
            // ---------------------------------------------------------------
            Events\CouponRedeemed::class => self::make('coupon_redeemed', AuditCategory::Coupon,
                fn (Events\CouponRedeemed $e): array => [
                    'code' => $e->coupon->code,
                    'coupon_type' => self::enumValue($e->coupon->type),
                    'redemption_id' => $e->redemption->getKey(),
                ]),

            Events\GrantRevoked::class => self::make('grant_revoked', AuditCategory::Coupon,
                fn (Events\GrantRevoked $e): array => [
                    'code' => $e->coupon->code,
                    'redemption_id' => $e->redemption->getKey(),
                    'reason' => self::truncate($e->reason),
                ]),

            // ---------------------------------------------------------------
            // Trial
            // ---------------------------------------------------------------
            Events\TrialStarted::class => self::make('trial_started', AuditCategory::Trial,
                fn (Events\TrialStarted $e): array => [
                    'plan' => $e->planCode,
                    'days' => $e->trialDays,
                ]),

            Events\TrialConverted::class => self::make('trial_converted', AuditCategory::Trial,
                fn (Events\TrialConverted $e): array => ['plan' => $e->planCode]),

            Events\TrialExpired::class => self::make('trial_expired', AuditCategory::Trial,
                fn (Events\TrialExpired $e): array => []),

            Events\TrialExtended::class => self::make('trial_extended', AuditCategory::Trial,
                fn (Events\TrialExtended $e): array => [
                    'previous_ends_at' => $e->previousEndsAt?->toIso8601String(),
                    'new_ends_at' => $e->newEndsAt->toIso8601String(),
                ]),

            // ---------------------------------------------------------------
            // Usage / wallet
            // ---------------------------------------------------------------
            Events\WalletCredited::class => self::make('wallet_credited', AuditCategory::Usage,
                fn (Events\WalletCredited $e): array => [
                    'usage_type' => $e->usageType,
                    'units' => $e->units,
                    'reason' => self::truncate($e->reasonText),
                ]),

            Events\WalletReset::class => self::make('wallet_reset', AuditCategory::Usage,
                fn (Events\WalletReset $e): array => [
                    'usage_type' => $e->usageType,
                    'previous_balance' => $e->previousBalance,
                    'new_balance' => $e->newBalance,
                    'reason' => self::truncate($e->reason),
                ]),

            Events\UsageLimitReached::class => self::make('usage_limit_reached', AuditCategory::Usage,
                fn (Events\UsageLimitReached $e): array => [
                    'usage_type' => $e->usageType,
                    'remaining' => $e->remaining,
                    'attempted' => $e->attemptedQuantity,
                ]),

            // ---------------------------------------------------------------
            // VAT / OSS compliance
            // ---------------------------------------------------------------
            Events\CountryMismatchFlagged::class => self::make('country_mismatch_flagged', AuditCategory::Compliance,
                fn (Events\CountryMismatchFlagged $e): array => [
                    'declared_country' => $e->mismatch->tax_country_user,
                    'payment_country' => $e->mismatch->tax_country_payment,
                    'mismatch_id' => $e->mismatch->getKey(),
                ]),

            Events\CountryMismatchResolved::class => self::make('country_mismatch_resolved', AuditCategory::Compliance,
                fn (Events\CountryMismatchResolved $e): array => [
                    'declared_country' => $e->mismatch->tax_country_user,
                    'payment_country' => $e->mismatch->tax_country_payment,
                    'mismatch_id' => $e->mismatch->getKey(),
                ]),
        ];
    }

    /**
     * @param  \Closure(never): array<string, scalar|null>  $properties
     */
    private static function make(string $key, AuditCategory $category, \Closure $properties): BillingAuditDescriptor
    {
        /** @var \Closure(object): array<string, scalar|null> $properties */
        return new BillingAuditDescriptor($key, $category, $properties);
    }

    /** @return array<string, scalar|null> */
    private static function invoice(object $invoice): array
    {
        return [
            'invoice_id' => $invoice->getKey(),
            'serial' => $invoice->serial_number,
            'amount_cents' => (int) $invoice->amount_gross,
            'payment_id' => $invoice->mollie_payment_id,
        ];
    }

    /** @return array<string, scalar|null> */
    private static function exception(Throwable $exception): array
    {
        // Class + message only. A stack trace in an audit row is noise at best
        // and a leak of internal paths at worst.
        return [
            'exception_class' => $exception::class,
            'exception_message' => self::truncate($exception->getMessage()),
        ];
    }

    private static function truncate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_strimwidth($value, 0, self::MAX_MESSAGE_LENGTH, '…');
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private static function enumValue(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return self::stringOrNull($value);
    }
}
