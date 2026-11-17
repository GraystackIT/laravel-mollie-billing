<?php

return [
    // Stands in for :amount when the amount behind a failed charge cannot be
    // resolved, so no notification ever renders a wrong or zero amount.
    'amount_unknown' => 'an unknown amount',
    'trial_ending_soon' => [
        'subject' => 'Your trial ends in :days days',
        'body_with_mandate' => 'Your :app trial ends on :date. We will automatically convert it to a paid subscription using your saved payment method — no action required.',
        'body_without_mandate' => 'Your :app trial ends on :date. To keep using the service, please add a payment method before then.',
    ],
    'trial_expired' => [
        'subject' => 'Your :app trial has expired',
        'body' => 'Your trial ended on :date and no payment method was added in time. Subscribe at any time to restore access — your previous configuration is remembered.',
        'body_with_mandate' => 'Your trial ended on :date, but the first payment for your subscription has not reached us. Please check your payment method and subscribe again to restore access — your previous configuration is remembered.',
    ],
    'unwanted_payment_refunded' => [
        'subject' => 'Your payment at :app is being refunded',
        'refund' => 'The amount of :amount is being refunded to the payment method you used. Depending on your bank this can take a few days.',
        'reasons' => [
            'default' => 'We received a payment from you that could not be applied.',
            'renewal_after_end' => 'We collected a renewal for your subscription although it has already ended. That was a mistake on our side; the subscription will not be charged again.',
            'activation_failed' => 'We received your payment, but your subscription could not be set up despite several attempts.',
            'change_cancelled' => 'We received the payment for a change to your subscription that you had cancelled in the meantime. The change was not carried out.',
            'subscription_ended' => 'We received the payment for a change to your subscription, but the subscription had ended in the meantime. The change was not carried out.',
        ],
    ],
    'scheduled_change_not_applied' => [
        'subject' => 'Your scheduled change at :app was not carried out',
        'body' => 'The change to :plan that you had scheduled could not be carried out. Your subscription continues unchanged.',
        'next' => 'You can make the change again at any time.',
        'payment_returned' => 'A payment for this change was already on its way and could not be stopped. We will refund it in full as soon as it arrives, and confirm that separately.',
        'action' => 'Manage subscription',
        'reasons' => [
            'default' => 'The change is not possible with your subscription as it is now.',
            'attempts_exhausted' => 'We tried several times and a technical problem prevented it each time.',
            'seats_in_use' => 'More seats are in use than the selected plan allows. Please release seats first.',
            'payment_outstanding' => 'There is an outstanding payment. Please settle it first.',
            'not_available' => 'The selected plan or one of its options is no longer available for your subscription.',
        ],
    ],
    'undercollection_charged' => [
        'subject' => 'A balance for your :app subscription',
        'charge' => 'We are collecting the difference of :amount from your payment method as a separate payment.',
        'invoice' => 'You will receive a separate invoice for it.',
        'reasons' => [
            'default' => 'Your last regular payment did not cover everything your subscription included in that billing period.',
            'renewal_created_before_change' => 'You added seats or an add-on shortly before your subscription renewed. The renewal had already been prepared at the previous amount, so it did not include them.',
            'plan_change_spanned_renewal' => 'Your plan change took effect after your subscription had already renewed at the previous plan\'s price, so the renewal did not cover the new plan.',
        ],
    ],
    'grant_extras_purchase_refunded' => [
        'subject' => 'Your purchase at :app could not be completed',
        'body' => 'Your purchase of extras could not be carried out, because your free period has ended in the meantime.',
        'refund' => 'The amount of :amount is being refunded to the payment method you used. Depending on your bank this can take a few days.',
        'next' => 'You can book a plan at any time.',
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
        'subject' => 'Usage charge failed',
        'body' => 'We tried three times to charge :amount for your overage usage but the payment failed.',
        'will_retry' => 'We will try again automatically with your next renewal. Please make sure your payment method is up to date. Until the amount is paid, nothing new can be booked on your subscription.',
        'final' => 'We will not try to collect this amount again.',
    ],
    'admin_overage_billing_failed' => [
        'subject' => '[Admin] Overage written off for :customer',
        'body' => 'The overage charge for :customer (:amount) could not be collected — neither in its own dunning cycle nor at the :attempts renewals that tried again. It has been written off; nothing is owed in the wallet any more. The lock on new bookings has been lifted; if a renewal is unpaid as well, the subscription stays past due for that.',
    ],
    'country_mismatch_self' => [
        'subject' => 'We need to verify your billing country',
        'body_intro' => 'We could not confirm the billing country you entered. The information from your payment method or your IP address does not match.',
        'body_user' => 'You entered: :country',
        'body_payment' => 'Payment method country: :country',
        'body_ip' => 'IP address country: :country',
        'body_consequence' => 'Your subscription has been scheduled to be cancelled at the end of the current period. Please correct your billing address from your dashboard to keep it active.',
        'cta' => 'Open billing dashboard',
    ],
    'country_mismatch_reissue_failed' => [
        'subject' => '[Admin] Country correction reissue failed (mismatch #:mismatch)',
        'body' => 'A country-correction re-charge for :customer failed at Mollie: :reason. The mismatch was rolled back to Pending so the customer can retry, but you may want to follow up manually.',
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
    'admin_plan_change_failed' => [
        'subject' => '[Admin] Plan change operation failed permanently',
        'reason' => 'Reason: :reason',
        'context' => 'Context: :context',
    ],
];
