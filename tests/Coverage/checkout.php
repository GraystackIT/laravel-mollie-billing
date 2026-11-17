<?php

declare(strict_types=1);

/*
 * Option coverage, area CHECKOUT: the checkout, the products page, the billing-data page,
 * the return page, the blocked page — and the catalog fields, configuration keys and enum
 * values they are driven by. See tests/Feature/Coverage/OptionCoverageTest.php.
 */

return [
    'enum:CountryMismatchStatus.Pending' => [
        'feature' => [
            'tests/Feature/Vat/CountryMatchServiceTest.php::flags a mismatch when no signal matches the user country',
            'tests/Feature/Middleware/RequireResolvedCountryMismatchTest.php::redirects to the dashboard when an open country mismatch exists',
        ],
        'browser' => [
            'tests/Browser/CountryMismatchTest.php::test_a_renewal_from_a_foreign_card_flags_a_mismatch_and_warns_the_customer',
        ],
    ],
    'enum:CountryMismatchStatus.Resolved' => [
        'feature' => [
            'tests/Feature/Middleware/RequireResolvedCountryMismatchTest.php::allows access when the mismatch was resolved',
        ],
        'browser' => [
            'tests/Browser/CountryMismatchTest.php::test_the_customer_corrects_the_country_and_the_invoice_is_reissued_at_the_new_rate',
        ],
    ],
    'enum:SubscriptionInterval.Monthly' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::plan_config plans.intervals.base_price_net',
            'tests/Feature/Checkout/BookingMatrixCouponCrossTest.php::first booking × order shape × coupon',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutSubscriptionMatrixTest.php::test_shown_equals_charged_equals_invoiced_equals_subscription_equals_renewal',
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_plan_cards_state_name_description_price_seats_usages_features_trial_and_addons',
        ],
    ],
    'enum:SubscriptionInterval.Yearly' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::plan_config plans.intervals.base_price_net',
            'tests/Feature/Checkout/BookingMatrixCouponCrossTest.php::upgrade free → yearly plan × coupon',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutSubscriptionMatrixTest.php::test_shown_equals_charged_equals_invoiced_equals_subscription_equals_renewal',
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_plan_cards_state_name_description_price_seats_usages_features_trial_and_addons',
            'tests/Browser/Matrix/CheckoutOtherPathsMatrixTest.php::test_an_upgrade_from_a_free_plan_shows_what_it_charges_invoices_and_renews',
            'tests/Browser/Matrix/CheckoutOtherPathsMatrixTest.php::test_an_upgrade_a_code_covers_in_full_goes_through_a_zero_authorisation',
        ],
    ],
    'config:checkout_back_url' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutConfigKeysTest.php::config checkout_back_url:',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_config_back_url_countries_default_country_and_ip_country_shape_the_first_step',
        ],
    ],
    'config:checkout_countries.regions' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutConfigKeysTest.php::config checkout_countries.regions:',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_config_back_url_countries_default_country_and_ip_country_shape_the_first_step',
        ],
    ],
    'config:checkout_countries.include' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutConfigKeysTest.php::config checkout_countries.include:',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_config_back_url_countries_default_country_and_ip_country_shape_the_first_step',
        ],
    ],
    'config:checkout_countries.exclude' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutConfigKeysTest.php::config checkout_countries.exclude:',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_config_back_url_countries_default_country_and_ip_country_shape_the_first_step',
        ],
    ],
    'config:default_billing_country' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutConfigKeysTest.php::config default_billing_country:',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_config_back_url_countries_default_country_and_ip_country_shape_the_first_step',
        ],
    ],
    'config:vat.seller_country' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutConfigKeysTest.php::config vat.seller_country:',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_config_seller_country_decides_whether_a_business_customer_sees_net_prices',
        ],
    ],
    'config:vat.require_seller_country' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutConfigKeysTest.php::config vat.require_seller_country:',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_config_seller_country_decides_whether_a_business_customer_sees_net_prices',
        ],
    ],
    'config:checkout_country_fallback' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutConfigKeysTest.php::config checkout_country_fallback:',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_config_local_one_time_orders_and_country_fallback_on_the_products_page',
        ],
    ],
    'config:redirect_after_return' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutConfigKeysTest.php::config redirect_after_return:',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_config_redirect_after_return_is_where_a_free_booking_lands',
        ],
    ],
    'config:currency' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutConfigKeysTest.php::config currency:',
        ],
    ],
    'config:currency_symbol' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutConfigKeysTest.php::config currency_symbol:',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_config_currency_symbol_and_vat_rate_override_change_the_prices_a_customer_reads',
        ],
    ],
    'config:mollie_api_key' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutConfigKeysTest.php::config mollie_api_key:',
        ],
    ],
    'config:mollie_locale' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutConfigKeysTest.php::config mollie_locale:',
        ],
    ],
    'config:ip_geolocation.driver' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutConfigKeysTest.php::config ip_geolocation.driver:',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_config_back_url_countries_default_country_and_ip_country_shape_the_first_step',
        ],
    ],
    'config:ip_geolocation.drivers' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutConfigKeysTest.php::config ip_geolocation.drivers:',
        ],
    ],
    'config:ip_block.enabled' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutConfigKeysTest.php::config ip_block.enabled:',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_a_visitor_from_a_blocked_country_gets_the_blocked_page_instead_of_the_checkout',
        ],
    ],
    'config:ip_block.mode' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutConfigKeysTest.php::config ip_block.mode:',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_a_visitor_from_a_blocked_country_gets_the_blocked_page_instead_of_the_checkout',
        ],
    ],
    'config:ip_block.countries' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutConfigKeysTest.php::config ip_block.countries:',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_a_visitor_from_a_blocked_country_gets_the_blocked_page_instead_of_the_checkout',
        ],
    ],
    'config:ip_block.block_unknown' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutConfigKeysTest.php::config ip_block.block_unknown:',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_a_visitor_from_a_blocked_country_gets_the_blocked_page_instead_of_the_checkout',
        ],
    ],
    'config:trial_lifecycle_job_time' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutConfigKeysTest.php::config trial_lifecycle_job_time:',
        ],
    ],
    'config:trial_ending_soon_notice_days' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutConfigKeysTest.php::config trial_ending_soon_notice_days:',
        ],
    ],
    'config:show_yearly_savings' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutConfigKeysTest.php::config show_yearly_savings:',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_config_yearly_savings_badge_can_be_switched_off',
        ],
    ],
    'config:local_subscription.allow_one_time_orders' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutConfigKeysTest.php::config local_subscription.allow_one_time_orders:',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_config_local_one_time_orders_and_country_fallback_on_the_products_page',
        ],
    ],
    'config:vat_rate_overrides' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutConfigKeysTest.php::config vat_rate_overrides:',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_config_currency_symbol_and_vat_rate_override_change_the_prices_a_customer_reads',
        ],
    ],
    'config:additional_countries' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutConfigKeysTest.php::config additional_countries:',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_config_back_url_countries_default_country_and_ip_country_shape_the_first_step',
            'tests/Browser/Matrix/CheckoutSubscriptionMatrixTest.php::test_shown_equals_charged_equals_invoiced_equals_subscription_equals_renewal',
        ],
    ],
    'config:billing_timezone' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutConfigKeysTest.php::config billing_timezone:',
        ],
    ],
    'plan_config:addons.*.feature_keys' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::plan_config addons.feature_keys',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_an_addons_features_are_listed_on_the_addons_page_and_a_hidden_one_is_not',
        ],
    ],
    'plan_config:addons.*.included_seats' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::plan_config addons.name, addons.intervals.price_net, seat_price_net, included_seats',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_the_extras_step_follows_allowed_addons_seat_price_and_per_seat_addon_pricing',
            'tests/Browser/Matrix/CheckoutSubscriptionMatrixTest.php::test_shown_equals_charged_equals_invoiced_equals_subscription_equals_renewal',
        ],
    ],
    'plan_config:addons.*.intervals.*.price_net' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::plan_config addons.name, addons.intervals.price_net, seat_price_net, included_seats',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_plan_cards_state_name_description_price_seats_usages_features_trial_and_addons',
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_the_extras_step_follows_allowed_addons_seat_price_and_per_seat_addon_pricing',
            'tests/Browser/Matrix/CheckoutSubscriptionMatrixTest.php::test_shown_equals_charged_equals_invoiced_equals_subscription_equals_renewal',
        ],
    ],
    'plan_config:addons.*.intervals.*.seat_price_net' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::plan_config addons.name, addons.intervals.price_net, seat_price_net, included_seats',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_the_extras_step_follows_allowed_addons_seat_price_and_per_seat_addon_pricing',
            'tests/Browser/Matrix/CheckoutSubscriptionMatrixTest.php::test_shown_equals_charged_equals_invoiced_equals_subscription_equals_renewal',
        ],
    ],
    'plan_config:addons.*.name' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::plan_config addons.name, addons.intervals.price_net, seat_price_net, included_seats',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_plan_cards_state_name_description_price_seats_usages_features_trial_and_addons',
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_the_extras_step_follows_allowed_addons_seat_price_and_per_seat_addon_pricing',
        ],
    ],
    'plan_config:features.*.description' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::plan_config plans.feature_keys, features.name, features.description, features.visible',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_plan_cards_state_name_description_price_seats_usages_features_trial_and_addons',
        ],
    ],
    'plan_config:features.*.name' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::plan_config plans.feature_keys, features.name, features.description, features.visible',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_plan_cards_state_name_description_price_seats_usages_features_trial_and_addons',
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_an_addons_features_are_listed_on_the_addons_page_and_a_hidden_one_is_not',
        ],
    ],
    'plan_config:features.*.visible' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::plan_config plans.feature_keys, features.name, features.description, features.visible',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_plan_cards_state_name_description_price_seats_usages_features_trial_and_addons',
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_an_addons_features_are_listed_on_the_addons_page_and_a_hidden_one_is_not',
        ],
    ],
    'plan_config:plans.*.allowed_addons' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::plan_config plans.allowed_addons',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_the_extras_step_follows_allowed_addons_seat_price_and_per_seat_addon_pricing',
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_a_plan_without_seat_price_or_addons_has_no_extras_step',
        ],
    ],
    'plan_config:plans.*.description' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::plan_config plans.name and plans.description',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_plan_cards_state_name_description_price_seats_usages_features_trial_and_addons',
        ],
    ],
    'plan_config:plans.*.feature_keys' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::plan_config plans.feature_keys, features.name, features.description, features.visible',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_plan_cards_state_name_description_price_seats_usages_features_trial_and_addons',
        ],
    ],
    'plan_config:plans.*.hidden' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::plan_config plans.hidden',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_plan_cards_state_name_description_price_seats_usages_features_trial_and_addons',
        ],
    ],
    'plan_config:plans.*.included_seats' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::plan_config plans.included_seats',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_plan_cards_state_name_description_price_seats_usages_features_trial_and_addons',
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_the_extras_step_follows_allowed_addons_seat_price_and_per_seat_addon_pricing',
        ],
    ],
    'plan_config:plans.*.intervals.*.base_price_net' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::plan_config plans.intervals.base_price_net',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_plan_cards_state_name_description_price_seats_usages_features_trial_and_addons',
            'tests/Browser/Matrix/CheckoutSubscriptionMatrixTest.php::test_shown_equals_charged_equals_invoiced_equals_subscription_equals_renewal',
        ],
    ],
    'plan_config:plans.*.intervals.*.included_usages' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::plan_config plans.intervals.included_usages and usage_overage_prices',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_plan_cards_state_name_description_price_seats_usages_features_trial_and_addons',
        ],
    ],
    'plan_config:plans.*.intervals.*.seat_price_net' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::plan_config plans.intervals.seat_price_net',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_plan_cards_state_name_description_price_seats_usages_features_trial_and_addons',
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_the_extras_step_follows_allowed_addons_seat_price_and_per_seat_addon_pricing',
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_a_plan_without_seat_price_or_addons_has_no_extras_step',
        ],
    ],
    'plan_config:plans.*.intervals.*.trial_days' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::plan_config plans.intervals.trial_days',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_plan_cards_state_name_description_price_seats_usages_features_trial_and_addons',
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_a_trial_checkout_names_its_days_and_the_date_of_the_first_charge',
        ],
    ],
    'plan_config:plans.*.intervals.*.usage_overage_prices' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::plan_config plans.intervals.included_usages and usage_overage_prices',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_plan_cards_state_name_description_price_seats_usages_features_trial_and_addons',
        ],
    ],
    'plan_config:plans.*.name' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::plan_config plans.name and plans.description',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_plan_cards_state_name_description_price_seats_usages_features_trial_and_addons',
        ],
    ],
    'plan_config:plans.*.tier' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::plan_config plans.tier',
        ],
        'no_browser' => 'The package renders and prices nothing by a plan tier: it is the consuming app\'s own rank, read only by billing:check-config. The feature test pins that plan order and prices do not follow it.',
    ],
    'plan_config:usage_types.*.rollover' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::plan_config usage_types.rollover',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_a_rollover_usage_type_keeps_unused_units_across_the_renewal_and_another_is_reset',
        ],
    ],
    'action:billing-data@changePaymentMethod' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions billing-data@changePaymentMethod, @checkForMandateUpdate',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_billing_data_shows_the_mandate_and_a_new_payment_method_replaces_it',
        ],
    ],
    'action:billing-data@checkForMandateUpdate' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions billing-data@changePaymentMethod, @checkForMandateUpdate',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_billing_data_shows_the_mandate_and_a_new_payment_method_replaces_it',
        ],
    ],
    'action:billing-data@countries' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions billing-data@countries, @save',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_billing_data_warns_when_the_address_country_differs_from_the_payment_country',
        ],
    ],
    'action:billing-data@isReverseCharge' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions billing-data@countries, @save',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_billing_data_marks_a_verified_cross_border_business_as_reverse_charge',
        ],
    ],
    'action:billing-data@mandateInfo' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions billing-data@countries, @save',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_billing_data_shows_the_mandate_and_a_new_payment_method_replaces_it',
        ],
    ],
    'action:billing-data@paymentCountryMismatch' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions billing-data@countries, @save',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_billing_data_warns_when_the_address_country_differs_from_the_payment_country',
        ],
    ],
    'action:plan-change@fullCoverageFirstCharge' => [
        'feature' => [
            'tests/Feature/Checkout/FullCoverageUpgradeFromFreeTest.php::tells the customer on the plan screen that nothing is due today',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOtherPathsMatrixTest.php::test_an_upgrade_a_code_covers_in_full_goes_through_a_zero_authorisation',
        ],
    ],
    'action:billing-data@recheckMandateUpdate' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions billing-data@recheckMandateUpdate',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_billing_data_stops_waiting_for_a_payment_method_change_that_is_never_confirmed',
        ],
    ],
    'action:billing-data@save' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions billing-data@countries, @save',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_billing_data_warns_when_the_address_country_differs_from_the_payment_country',
        ],
    ],
    'action:checkout@addonSeatsNet' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@subtotalNet, @totalNet, @lineNets',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_the_extras_step_follows_allowed_addons_seat_price_and_per_seat_addon_pricing',
        ],
    ],
    'action:checkout@addons' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@countries, @plans, @addons',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_the_extras_step_follows_allowed_addons_seat_price_and_per_seat_addon_pricing',
        ],
    ],
    'action:checkout@announcedTrialDays' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@announcedTrialDays, @firstChargeDate',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_a_trial_checkout_names_its_days_and_the_date_of_the_first_charge',
        ],
    ],
    'action:checkout@applyCoupon' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@applyCoupon, @removeCoupon, @submit',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_the_confirm_step_states_subtotal_discount_net_vat_and_total_and_removing_the_coupon_restores_them',
            'tests/Browser/Matrix/CheckoutSubscriptionMatrixTest.php::test_shown_equals_charged_equals_invoiced_equals_subscription_equals_renewal',
        ],
    ],
    'action:checkout@back' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@next, @back, @displayStep',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_next_and_back_walk_the_steps_and_the_stepper_follows',
        ],
    ],
    'action:checkout@countries' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@countries, @plans, @addons',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_config_back_url_countries_default_country_and_ip_country_shape_the_first_step',
        ],
    ],
    'action:checkout@customStepCount' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@next, @back, @displayStep',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_an_app_provided_step_comes_first_is_counted_and_is_validated',
        ],
    ],
    'action:checkout@displayAmount' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@subtotalNet, @totalNet, @lineNets',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_plan_cards_state_name_description_price_seats_usages_features_trial_and_addons',
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_changing_the_country_reprices_and_a_vat_number_is_checked_as_it_is_typed',
        ],
    ],
    'action:checkout@displayStep' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@next, @back, @displayStep',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_next_and_back_walk_the_steps_and_the_stepper_follows',
        ],
    ],
    'action:checkout@firstChargeDate' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@announcedTrialDays, @firstChargeDate',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_a_trial_checkout_names_its_days_and_the_date_of_the_first_charge',
        ],
    ],
    'action:checkout@hasAddonsOrSeatsStep' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@next, @back, @displayStep',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_a_plan_without_seat_price_or_addons_has_no_extras_step',
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_next_and_back_walk_the_steps_and_the_stepper_follows',
        ],
    ],
    'action:checkout@lineNets' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@subtotalNet, @totalNet, @lineNets',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_the_confirm_step_states_subtotal_discount_net_vat_and_total_and_removing_the_coupon_restores_them',
        ],
    ],
    'action:checkout@next' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@next, @back, @displayStep',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_next_and_back_walk_the_steps_and_the_stepper_follows',
        ],
    ],
    'action:checkout@offeredIntervals' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@countries, @plans, @addons',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_plan_cards_state_name_description_price_seats_usages_features_trial_and_addons',
        ],
    ],
    'action:checkout@planAddons' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@countries, @plans, @addons',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_plan_cards_state_name_description_price_seats_usages_features_trial_and_addons',
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_the_extras_step_follows_allowed_addons_seat_price_and_per_seat_addon_pricing',
        ],
    ],
    'action:checkout@planFeatures' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@countries, @plans, @addons',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_plan_cards_state_name_description_price_seats_usages_features_trial_and_addons',
        ],
    ],
    'action:checkout@planTrialDays' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@countries, @plans, @addons',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_plan_cards_state_name_description_price_seats_usages_features_trial_and_addons',
        ],
    ],
    'action:checkout@planUsages' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@countries, @plans, @addons',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_plan_cards_state_name_description_price_seats_usages_features_trial_and_addons',
        ],
    ],
    'action:checkout@plans' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@countries, @plans, @addons',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_plan_cards_state_name_description_price_seats_usages_features_trial_and_addons',
        ],
    ],
    'action:checkout@removeCoupon' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@applyCoupon, @removeCoupon, @submit',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_the_confirm_step_states_subtotal_discount_net_vat_and_total_and_removing_the_coupon_restores_them',
        ],
    ],
    'action:checkout@selectedPlan' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@countries, @plans, @addons',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_the_extras_step_follows_allowed_addons_seat_price_and_per_seat_addon_pricing',
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_the_confirm_step_states_subtotal_discount_net_vat_and_total_and_removing_the_coupon_restores_them',
        ],
    ],
    'action:checkout@showsNetPrices' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@subtotalNet, @totalNet, @lineNets',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_changing_the_country_reprices_and_a_vat_number_is_checked_as_it_is_typed',
        ],
    ],
    'action:checkout@stepDescription' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@next, @back, @displayStep',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_next_and_back_walk_the_steps_and_the_stepper_follows',
        ],
    ],
    'action:checkout@stepHeadline' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@next, @back, @displayStep',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_next_and_back_walk_the_steps_and_the_stepper_follows',
        ],
    ],
    'action:checkout@submit' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@applyCoupon, @removeCoupon, @submit',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutSubscriptionMatrixTest.php::test_shown_equals_charged_equals_invoiced_equals_subscription_equals_renewal',
        ],
    ],
    'action:checkout@subtotalNet' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@subtotalNet, @totalNet, @lineNets',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_the_confirm_step_states_subtotal_discount_net_vat_and_total_and_removing_the_coupon_restores_them',
        ],
    ],
    'action:checkout@timelineStatus' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@next, @back, @displayStep',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_next_and_back_walk_the_steps_and_the_stepper_follows',
        ],
    ],
    'action:checkout@timelineSteps' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@next, @back, @displayStep',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_next_and_back_walk_the_steps_and_the_stepper_follows',
        ],
    ],
    'action:checkout@totalNet' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@subtotalNet, @totalNet, @lineNets',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_the_confirm_step_states_subtotal_discount_net_vat_and_total_and_removing_the_coupon_restores_them',
        ],
    ],
    'action:checkout@totalSteps' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@next, @back, @displayStep',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_next_and_back_walk_the_steps_and_the_stepper_follows',
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_a_plan_without_seat_price_or_addons_has_no_extras_step',
        ],
    ],
    'action:checkout@totals' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@subtotalNet, @totalNet, @lineNets',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_the_confirm_step_states_subtotal_discount_net_vat_and_total_and_removing_the_coupon_restores_them',
            'tests/Browser/Matrix/CheckoutSubscriptionMatrixTest.php::test_shown_equals_charged_equals_invoiced_equals_subscription_equals_renewal',
        ],
    ],
    'action:checkout@trialApplies' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@countries, @plans, @addons',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_a_trial_checkout_names_its_days_and_the_date_of_the_first_charge',
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_a_returning_customer_is_offered_no_trial',
        ],
    ],
    'action:checkout@vatRate' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@subtotalNet, @totalNet, @lineNets',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_the_confirm_step_states_subtotal_discount_net_vat_and_total_and_removing_the_coupon_restores_them',
        ],
    ],
    'action:products@applyCoupon' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions products@applyCoupon, @removeCoupon',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOtherPathsMatrixTest.php::test_a_one_time_product_shows_what_it_charges_and_invoices',
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_a_coupon_on_a_product_can_be_removed_again_and_the_full_price_is_charged',
        ],
    ],
    'action:products@canAddMoreCouponsFor' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions products@applyCoupon, @removeCoupon',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOtherPathsMatrixTest.php::test_a_one_time_product_shows_what_it_charges_and_invoices',
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_a_coupon_on_a_product_can_be_removed_again_and_the_full_price_is_charged',
        ],
    ],
    'action:products@purchase' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions products@applyCoupon, @removeCoupon',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOtherPathsMatrixTest.php::test_a_one_time_product_shows_what_it_charges_and_invoices',
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_a_coupon_on_a_product_can_be_removed_again_and_the_full_price_is_charged',
        ],
    ],
    'action:products@removeCoupon' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions products@applyCoupon, @removeCoupon',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_a_coupon_on_a_product_can_be_removed_again_and_the_full_price_is_charged',
        ],
    ],
    'action:return@checkStatus' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions return@checkStatus, @shouldPoll',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_the_return_page_waits_while_the_payment_is_still_open_and_activates_when_it_settles',
        ],
    ],
    'action:return@checkoutUrl' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions return@checkStatus, @shouldPoll',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_the_return_page_names_a_payment_that_did_not_go_through_and_offers_the_way_back',
        ],
    ],
    'action:return@pollInterval' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions return@checkStatus, @shouldPoll',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_the_return_page_gives_up_waiting_after_its_tick_budget_and_check_again_resumes',
        ],
    ],
    'action:return@retry' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions return@checkStatus, @shouldPoll',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_the_return_page_gives_up_waiting_after_its_tick_budget_and_check_again_resumes',
        ],
    ],
    'action:return@retryUrl' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions return@checkStatus, @shouldPoll',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_the_return_page_names_a_payment_that_did_not_go_through_and_offers_the_way_back',
        ],
    ],
    'action:return@returnUrl' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions return@checkStatus, @shouldPoll',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_the_return_page_waits_while_the_payment_is_still_open_and_activates_when_it_settles',
        ],
    ],
    'action:return@shouldPoll' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions return@checkStatus, @shouldPoll',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_the_return_page_waits_while_the_payment_is_still_open_and_activates_when_it_settles',
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_the_return_page_gives_up_waiting_after_its_tick_budget_and_check_again_resumes',
        ],
    ],
    'screen:billing.billing-data' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions billing-data@countries, @save',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_billing_data_warns_when_the_address_country_differs_from_the_payment_country',
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_billing_data_marks_a_verified_cross_border_business_as_reverse_charge',
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_billing_data_shows_the_mandate_and_a_new_payment_method_replaces_it',
        ],
    ],
    'screen:billing.blocked' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::screens billing.index and billing.blocked',
            'tests/Feature/Coverage/CheckoutConfigKeysTest.php::config ip_block.enabled:',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_a_visitor_from_a_blocked_country_gets_the_blocked_page_instead_of_the_checkout',
        ],
    ],
    'screen:billing.checkout' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutConfigKeysTest.php::config checkout_back_url:',
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions checkout@next, @back, @displayStep',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutSubscriptionMatrixTest.php::test_shown_equals_charged_equals_invoiced_equals_subscription_equals_renewal',
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_next_and_back_walk_the_steps_and_the_stepper_follows',
        ],
    ],
    'screen:billing.index' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::screens billing.index and billing.blocked',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_the_dashboard_states_the_plan_the_checkout_booked',
        ],
    ],
    'screen:billing.products' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions products@applyCoupon, @removeCoupon',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOtherPathsMatrixTest.php::test_a_one_time_product_shows_what_it_charges_and_invoices',
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_a_coupon_on_a_product_can_be_removed_again_and_the_full_price_is_charged',
        ],
    ],
    'screen:billing.promotion' => [
        'feature' => [
            'tests/Feature/Checkout/BookingMatrixCouponPathsTest.php::promotion link: the code is applied on arrival and priced through to the renewal',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutSubscriptionMatrixTest.php::test_shown_equals_charged_equals_invoiced_equals_subscription_equals_renewal',
        ],
    ],
    'screen:billing.return' => [
        'feature' => [
            'tests/Feature/Coverage/CheckoutCatalogAndActionsTest.php::actions return@checkStatus, @shouldPoll',
        ],
        'browser' => [
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_the_return_page_names_a_payment_that_did_not_go_through_and_offers_the_way_back',
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_the_return_page_waits_while_the_payment_is_still_open_and_activates_when_it_settles',
            'tests/Browser/Matrix/CheckoutOptionsMatrixTest.php::test_the_return_page_gives_up_waiting_after_its_tick_budget_and_check_again_resumes',
        ],
    ],
];
