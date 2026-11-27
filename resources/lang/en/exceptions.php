<?php

/*
 * What a screen shows when a service refuses. The exception message stays English and is
 * written for the log; these are the sentences for people, resolved through
 * GraystackIT\MollieBilling\Support\ExceptionMessage. They reach both the admin panel and
 * the customer portal, so the German ones address nobody directly.
 */
return [
    'generic' => 'The action could not be completed.',
    'refused_with_detail' => 'The action was refused: :message',

    'subscription_change_in_progress' => 'Another change to this subscription is still being processed. Please try again in a moment.',
    'refund_in_progress' => 'A refund for this invoice is already being processed. Please try again once it has finished.',
    'previous_subscription_still_running' => 'The subscription cannot be resumed yet: the previous Mollie subscription has not been cancelled yet. Please try again shortly.',
    'refund_exceeds_invoice' => 'The refund amount exceeds the remaining refundable total of this invoice.',
    'refund_exceeds_line' => 'The refund amount exceeds what is left of this invoice line.',
    'seat_count_exceeds_maximum' => ':requested seats exceed the maximum of :max.',
    'seat_downgrade_required' => 'This plan includes :included seats, but :used are in use. Reduce the seats first.',
    'hidden_plan_not_selectable' => 'The plan :plan cannot be selected.',
    'interval_not_offered' => ':code is not offered with this billing interval (:interval).',
    'addon_not_allowed' => 'The add-on :addon is not available on the plan :plan.',
    'local_cannot_purchase_products' => 'A free plan cannot purchase products. Upgrade to a paid plan first.',
    'local_no_paid_extras' => 'A free plan cannot include paid add-ons or paid extra seats. Upgrade to a paid plan first.',
    'local_upgrade_requires_checkout' => 'Switching from a free plan to the paid plan :plan requires a checkout with a payment method.',
    'non_eu_country' => 'VAT cannot be calculated for the country :country: it is neither in the EU nor configured as an additional country.',
    'billing_country_required' => 'No billing country is on file. The billing details have to be completed before anything can be purchased.',
    'downgrade_requires_mandate' => 'A payment method is required to change to the plan :plan.',
    'usage_limit_exceeded' => 'The :type limit for the current period has been reached.',
    'grant_mismatch' => 'The access grant does not match the current plan or interval.',
    'access_grant_conflicts_with_mollie' => 'Access grants cannot be applied while a paid subscription is active.',
    'access_grant_requires_active_subscription' => 'An active subscription is required before this add-on grant can be applied.',
    'coupon_not_stackable' => 'This coupon cannot be combined with other active coupons.',
    'vies_unavailable' => 'The EU VAT number service (VIES) is not available right now. Please try again later.',

    'state' => [
        'no_subscription_to_cancel' => 'There is no subscription to cancel.',
        'schedule_not_running' => 'A change cannot be scheduled while the subscription\'s status is :status. Resubscribe first.',
        'period_not_renewed' => 'The subscription cannot be changed: the current billing period ended on :date and has not renewed yet. Wait for the renewal to complete.',
        'no_active_subscription' => 'The plan cannot be changed: there is no active subscription. Complete the checkout first.',
        'interval_locked_while_free' => 'The billing interval cannot be changed while the plan is free of charge. Change it once the free period has ended.',
        'interval_locked_while_free_until' => 'The billing interval cannot be changed while the plan is free of charge (until :date). Change it once the free period has ended.',
        'plan_change_pending_payment' => 'A plan change is already pending payment confirmation. Cancel it first or wait for the payment to resolve.',
        'upgrade_no_mandate' => 'The upgrade cannot be charged: no payment method (Mollie mandate) is on file.',
        'upgrade_no_mollie_subscription' => 'The upgrade cannot be charged: the Mollie subscription is missing.',
        'resubscribe_mismatch_open' => 'The subscription cannot be resumed while a country mismatch is open. Resolve it first.',
        'not_extendable' => 'This billable cannot be extended.',
        'no_running_grant' => 'There is no running grant to extend on this subscription.',
        'no_running_grant_free_periods' => 'There is no running grant to extend on this subscription. Free plan periods are extended by giving more periods.',
        'trial_not_while_status' => 'A trial cannot be extended while the subscription\'s status is :status. Resubscribe instead of extending.',
        'trial_local_not_on_trial' => 'A trial cannot be extended on a free subscription that is not on one. Extend the grant or the period instead.',
        'trial_before_next_charge' => 'This subscription is not charged before :date. A trial ending on or before that date would not give the customer anything — pick a later date.',
    ],

    'refund' => [
        'only_paid' => 'Only paid invoices can be refunded.',
        'no_payment' => 'This invoice has no Mollie payment to refund against: it collected none, and no invoice it was settled out of did.',
        'only_overage' => 'Units can only be refunded on overage invoices.',
        'usage_type_not_found' => 'The usage type :type is not on this invoice.',
        'line_not_refundable' => 'This invoice line cannot be refunded on its own.',
        'reason_required' => 'A refund reason is required.',
        'line_and_items' => 'A refund by line cannot be combined with custom line items.',
        'reason_text_required' => 'A description is required when the reason is "Other".',
        'amount_positive' => 'The refund amount must be greater than zero.',
        'units_positive' => 'The units to refund for :type must be a positive whole number.',
        'units_exceed' => 'Cannot refund :units units of :type: only :remaining remain refundable on this invoice.',
    ],

    'coupon' => [
        'code_required' => 'Please enter a coupon code.',
        'code_taken' => 'A coupon with the code :code already exists.',
        'code_locked' => 'Cannot change the code of a coupon that has already been redeemed.',
        'type_locked' => 'Cannot change the type of a coupon that has already been redeemed.',
        'revoke_only_grants' => 'Only access grants and plan waivers can be revoked.',
        'value_positive' => 'A discount value must be greater than zero.',
        'percent_max' => 'A discount value greater than 100% is not allowed.',
        'discount_required' => 'Please choose a discount type and enter a discount value.',
        'periods_positive' => 'The number of discounted billing periods must be greater than zero.',
        'recurring_needs_limit' => 'Recurring coupons need a limit. Set either "Discount applies for … periods" (number of discounted charges) or a "Valid until" date.',
        'credits_required' => 'Credits coupons require a credits payload.',
        'trial_days_required' => 'Please enter how many days the trial should be extended by.',
        'grant_fields_required' => 'Please pick a plan, an interval and enter a duration in days.',
        'grant_or_addons_required' => 'Please pick a plan with interval and duration, or select at least one addon for an addon-only grant.',
        'extension_days_required' => 'Please enter how many days the billing period should be extended by.',
        'waiver_periods_required' => 'Please enter for how many billing periods the plan should be free.',
    ],

    'extension' => [
        'days_positive' => 'The number of days must be greater than zero.',
        'periods_positive' => 'The number of periods must be greater than zero.',
    ],

    'country' => [
        'invalid' => ':country is not a valid two-letter country code.',
        'mismatch_already_resolved' => 'This mismatch is already resolved to :chosen. Flag a new mismatch to correct it to :country.',
        'needs_justification' => 'The country :country matches neither the payment (:payment) nor the IP signal (:ip). A justification is required to record it as verified anyway.',
    ],
];
