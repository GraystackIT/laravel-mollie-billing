<?php

return [
    'non_eu_country' => 'Country :country is not supported. This service is only available within the EU.',
    'invalid_coupon' => 'The coupon :code is not valid: :reason.',
    'coupon_not_stackable' => 'This coupon cannot be combined with other active coupons.',
    'coupon_already_used' => 'You have already redeemed this coupon.',
    'downgrade_requires_mandate' => 'A payment method is required to downgrade from your current plan.',
    'usage_limit_exceeded' => 'You have reached your :type limit for the current period.',
    'grant_mismatch' => 'The access grant does not match your current plan or interval.',
    'access_grant_conflicts_with_mollie' => 'Access grants cannot be applied while a paid subscription is active.',
    'access_grant_requires_active_subscription' => 'An active subscription is required before this addon grant can be applied.',
    'refund_exceeds_invoice_amount' => 'The refund amount exceeds the remaining refundable total of this invoice.',
];
