<?php

// Audit-trail strings. The database stores the *key* (e.g. `audit.plan_changed`)
// plus raw placeholder values — never a rendered sentence — so existing rows
// re-render in whatever locale the reader uses. Every key in
// GraystackIT\MollieBilling\Support\BillingAuditMap must exist here; the
// AuditTranslationCoverageTest enforces that.

return [
    'unknown_event' => 'Unrecognised billing event',

    // Admin timeline UI
    'tab_label' => 'Audit',
    'tab_title' => 'Activity timeline',
    'tab_description' => 'Every billing event recorded for this billable, newest first.',
    'all_categories' => 'All categories',
    'details' => 'Details',
    'empty_title' => 'No activity yet',
    'empty_description' => 'Plan changes, payments, invoices and promo code redemptions will show up here.',

    'category' => [
        'subscription' => 'Subscription',
        'payment' => 'Payment',
        'invoice' => 'Invoice',
        'payment_method' => 'Payment method',
        'coupon' => 'Promo code',
        'trial' => 'Trial',
        'usage' => 'Usage',
        'compliance' => 'Compliance',
    ],

    'actor' => [
        'system' => 'System',
        'admin' => 'Admin',
        'customer' => 'Customer',
    ],

    // Subscription lifecycle
    'subscription_created' => 'Subscription started on :plan (:interval)',
    'subscription_cancelled' => 'Subscription cancelled',
    'subscription_resumed' => 'Subscription resumed',
    'subscription_expired' => 'Subscription expired',
    'subscription_updated' => 'Subscription updated (:changes)',
    'subscription_extended' => 'Billing period extended via promo code :code',
    'subscription_upgraded_from_local' => 'Upgraded from free plan :old_plan to :new_plan (:new_interval)',
    'subscription_activation_failed' => 'Activation of :plan failed: :reason',
    'plan_changed' => 'Plan changed from :old_plan to :new_plan (:interval)',
    'plan_change_pending' => 'Plan change to :new_plan awaiting payment',
    'plan_change_failed' => 'Plan change to :new_plan failed: :reason',
    'subscription_change_scheduled' => 'Change to :new_plan scheduled',
    'subscription_change_rescheduled' => 'Scheduled change updated from :old_plan to :new_plan',
    'subscription_change_cancelled' => 'Scheduled change to :new_plan cancelled',
    'subscription_change_apply_failed' => 'Scheduled change to :new_plan could not be applied: :exception_message',
    'seats_changed' => 'Seats changed from :old_count to :new_count',
    'addon_enabled' => 'Add-on :addon enabled',
    'addon_disabled' => 'Add-on :addon disabled',

    // Payments
    'payment_succeeded' => 'Payment received',
    'payment_failed' => 'Payment failed: :reason',
    'payment_amount_mismatch' => 'Paid amount differs from the expected amount',
    'duplicate_payment_received' => 'Duplicate payment received and ignored',
    'checkout_started' => 'Checkout started',
    'checkout_abandoned' => 'Checkout abandoned',
    'one_time_order_completed' => 'One-time purchase :product completed',
    'one_time_order_failed' => 'One-time purchase :product failed: :reason',
    'overage_charged' => 'Usage overage charged',
    'overage_charge_failed' => 'Usage overage charge failed (attempt :attempt)',

    // Invoices
    'invoice_created' => 'Invoice :serial issued',
    'invoice_refunded' => 'Invoice :serial refunded',
    'credit_note_issued' => 'Credit note :serial issued',
    'invoice_pdf_regenerated' => 'Invoice :serial PDF regenerated',

    // Payment method
    'mandate_updated' => 'Payment method updated',

    // Promo codes / grants
    'coupon_redeemed' => 'Promo code :code redeemed',
    'grant_revoked' => 'Access granted by promo code :code revoked',

    // Trial
    'trial_started' => 'Trial started on :plan (:days days)',
    'trial_converted' => 'Trial converted to a paid subscription on :plan',
    'trial_expired' => 'Trial expired',
    'trial_extended' => 'Trial extended',

    // Usage / wallet
    'wallet_credited' => ':units :usage_type credited',
    'wallet_reset' => ':usage_type balance reset from :previous_balance to :new_balance',
    'usage_limit_reached' => ':usage_type quota reached (:remaining left, :attempted requested)',

    // VAT / OSS compliance
    'country_mismatch_flagged' => 'Country mismatch flagged: declared :declared_country, paid from :payment_country',
    'country_mismatch_resolved' => 'Country mismatch resolved',
];
