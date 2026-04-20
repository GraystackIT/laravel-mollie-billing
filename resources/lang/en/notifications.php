<?php

return [
    'trial_ending_soon' => [
        'subject' => 'Your trial ends in :days days',
        'body_with_mandate' => 'Your :app trial ends on :date. We will automatically convert it to a paid subscription using your saved payment method — no action required.',
        'body_without_mandate' => 'Your :app trial ends on :date. To keep using the service, please add a payment method before then.',
    ],
    'trial_expired' => [
        'subject' => 'Your :app trial has expired',
        'body' => 'Your trial ended on :date and no payment method was added in time. Subscribe at any time to restore access — your previous configuration is remembered.',
    ],
    'trial_converted' => [
        'subject' => 'Welcome to :app',
        'body' => 'Your trial has been converted to a paid :plan subscription. Your first invoice of :amount has been issued.',
    ],
    'payment_failed' => [
        'subject' => 'Action required: payment failed',
        'body' => 'We were unable to process your last payment of :amount on :date. Please review your payment method to keep your :app subscription active.',
    ],
    'subscription_cancelled' => [
        'subject' => 'Your :app subscription has been cancelled',
        'body' => 'Your subscription has been cancelled and will remain active until :date. You can resubscribe any time before then to keep your current plan.',
    ],
    'invoice_available' => [
        'subject' => 'New invoice from :app',
        'body' => 'A new invoice of :amount is now available in your billing portal.',
    ],
    'usage_threshold' => [
        'subject' => ':type usage at :percent%',
        'body' => 'You have used :percent% of your :type quota for the current billing period. :overage_hint',
        'overage_allowed' => 'Additional usage will be billed at the end of the period.',
        'overage_blocked' => 'No further :type usage will be allowed until the next period or your plan is upgraded.',
    ],
    'overage_billing_failed' => [
        'subject' => 'Service paused: usage charge failed',
        'body' => 'We tried three times to charge :amount for your overage usage but the payment failed. Service is paused until you update your payment method.',
    ],
    'admin_overage_billing_failed' => [
        'subject' => '[Admin] Overage charge failed for :customer',
        'body' => 'After 3 retries, the overage charge for :customer (:amount) could not be collected. The customer has been moved to past_due.',
    ],
    'country_mismatch' => [
        'subject' => '[Admin] Country mismatch flagged for :customer',
        'body' => 'Country verification failed for :customer. Declared: :user, IP: :ip, Payment: :payment. Manual review required.',
    ],
    'refund_processed' => [
        'subject' => 'Your refund of :amount has been processed',
        'body' => 'A credit note for :amount has been issued to your account. The refund will appear on your statement within 5 business days, depending on your bank.',
    ],
    'admin_refund_failed' => [
        'subject' => '[Admin] Refund failed for invoice :invoice',
        'body' => 'A refund attempt failed: :reason. Manual intervention required.',
    ],
    'plan_change_failed' => [
        'subject' => 'Your plan upgrade could not be completed',
        'body' => 'We were unable to process the payment for your plan upgrade. Please update your payment method and try again, or contact :app support.',
    ],
];
