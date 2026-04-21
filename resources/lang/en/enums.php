<?php

return [
    'subscription_status' => [
        'new' => 'New',
        'active' => 'Active',
        'trial' => 'Trial',
        'past_due' => 'Past due',
        'cancelled' => 'Cancelled',
        'expired' => 'Expired',
    ],
    'invoice_status' => [
        'paid' => 'Paid',
        'open' => 'Open',
        'failed' => 'Failed',
        'refunded' => 'Refunded',
    ],
    'invoice_kind' => [
        'subscription' => 'Subscription',
        'prorata' => 'Pro-rata',
        'addon' => 'Addon',
        'seats' => 'Seats',
        'overage' => 'Overage',
        'credit_note' => 'Credit note',
    ],
    'subscription_source' => [
        'none' => 'None',
        'local' => 'Local',
        'mollie' => 'Mollie',
    ],
    'subscription_interval' => [
        'monthly' => 'Monthly',
        'yearly' => 'Yearly',
    ],
    'coupon_type' => [
        'first_payment' => 'First payment',
        'recurring' => 'Recurring',
        'credits' => 'Credits',
        'trial_extension' => 'Trial extension',
        'access_grant' => 'Access grant',
    ],
    'discount_type' => [
        'percentage' => 'Percentage',
        'fixed' => 'Fixed amount',
    ],
    'refund_reason_code' => [
        'service_outage' => 'Service outage',
        'billing_error' => 'Billing error',
        'goodwill' => 'Goodwill',
        'chargeback' => 'Chargeback',
        'cancellation' => 'Cancellation',
        'plan_downgrade' => 'Plan downgrade',
        'other' => 'Other',
    ],
    'country_mismatch_status' => [
        'pending' => 'Pending',
        'resolved' => 'Resolved',
    ],
    'plan_change_mode' => [
        'immediate' => 'Immediate',
        'end_of_period' => 'End of period',
        'user_choice' => 'User choice',
    ],
    'mollie_subscription_status' => [
        'pending' => 'Pending',
        'active' => 'Active',
        'canceled' => 'Canceled',
        'suspended' => 'Suspended',
        'completed' => 'Completed',
    ],
];
