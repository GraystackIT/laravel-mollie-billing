<?php

return [
    // Layout
    'title' => 'Checkout — :app',
    'back' => 'Back',

    // Step counter
    'step_counter' => 'Step :current of :total',

    // Step labels (timeline)
    'step_billing_details' => 'Billing details',
    'step_plan' => 'Plan',
    'step_addons' => 'Extras',
    'step_confirm' => 'Confirm',

    // Step headlines
    'headline_billing' => 'Where should we send the invoices?',
    'headline_plan' => 'Pick a plan that fits',
    'headline_addons' => 'Fine-tune your subscription',
    'headline_confirm' => 'One last look',

    // Step descriptions
    'description_billing' => 'These details appear on every invoice and receipt.',
    'description_plan' => 'You can change your plan anytime from billing settings.',
    'description_addons' => 'Add optional add-ons or extra seats beyond what your plan includes.',
    'description_confirm' => 'Review your choices. You will be redirected to Mollie after confirming.',

    // Step 1: billing address
    'company_name' => 'Company name',
    'street' => 'Street and number',
    'postal_code' => 'Postal code',
    'city' => 'City',
    'country' => 'Country',
    'address' => 'Address',
    'vat_number' => 'VAT number (optional)',

    // VAT validation
    'vat_invalid_format' => 'Invalid VAT number format.',
    'vat_country_mismatch' => 'VAT number prefix must match the selected country.',
    'vies_unavailable' => 'VIES is temporarily unavailable. We will re-check before the first invoice.',
    'vies_validation_failed' => 'The VAT number could not be verified by VIES.',
    'vat_verified' => 'VAT number verified — reverse-charge applies.',
    'vat_correct_or_clear' => 'Please correct the VAT number or leave it blank.',

    // Step 2: plan
    'monthly' => 'Monthly',
    'yearly' => 'Yearly',
    'prices_net' => 'Prices shown net (reverse-charge applies).',
    'prices_incl_vat' => 'Prices include :rate% VAT for :country.',
    'tier' => 'Tier :tier',
    'trial_days' => ':days-day trial',
    'per_month' => 'mo',
    'per_year' => 'yr',
    'seats_included' => ':count seats included',

    // Step 3: addons + seats
    'addons_heading' => 'Add-ons',
    'addons_description' => 'Extra capabilities you can enable on top of your plan.',
    'extra_seats' => 'Additional seats',
    'extra_seat_price' => 'Each extra seat:',

    // Step 4: confirm
    'billing_details' => 'Billing details',
    'order' => 'Order',
    'plan' => 'Plan',
    'billing_interval' => 'Billing',
    'coupon_code' => 'Coupon code',
    'coupon_placeholder' => 'Enter coupon code',
    'apply_coupon' => 'Apply',
    'remove_coupon' => 'Remove coupon',
    'subtotal' => 'Subtotal',
    'discount' => 'Discount (:code)',
    'net' => 'Net',
    'vat_label' => 'VAT',
    'vat_with_rate' => 'VAT (:rate%)',
    'reverse_charge' => 'Reverse-charge',
    'total' => 'Total',
    'redirect_notice' => 'After confirming you will be redirected to Mollie to authorise your payment method. You can cancel anytime.',
    'confirm_and_pay' => 'Confirm and pay',
    'processing' => 'Processing…',
    'continue' => 'Continue',

    // Coupon errors
    'coupon_empty' => 'Please enter a coupon code.',
    'coupon_already_applied' => 'Remove the current coupon before adding a new one.',
    'coupon_failed' => 'Coupon could not be applied.',
    'coupon_not_found' => 'Coupon code not found.',
    'coupon_inactive' => 'This coupon is no longer active.',
    'coupon_not_yet_valid' => 'This coupon is not yet valid.',
    'coupon_expired' => 'This coupon has expired.',
    'coupon_exhausted' => 'This coupon has reached its redemption limit.',
    'coupon_plan_mismatch' => 'This coupon does not apply to the selected plan.',
    'coupon_interval_mismatch' => 'This coupon does not apply to the selected billing interval.',
    'coupon_addon_mismatch' => 'This coupon does not apply to the selected add-ons.',
    'coupon_min_order' => 'The order does not meet the minimum amount for this coupon.',
    'coupon_requires_billable' => 'This coupon cannot be applied during checkout.',

    // Promo (existing)
    'coupon_applied' => 'Coupon :code applied.',
    'promo_auto_applied' => 'Promotion :code automatically applied.',
    'promo_invalid' => 'This promotion link has expired or is no longer valid.',
    'yearly_savings' => 'Save :percent% with yearly billing',

    // Errors
    'error_billable_creation' => 'Could not create the billing account. Please try again.',
    'error_no_billable' => 'Could not resolve the billing account. Please try again.',
    'error_payment_creation' => 'Could not create the payment. Please try again.',
];
