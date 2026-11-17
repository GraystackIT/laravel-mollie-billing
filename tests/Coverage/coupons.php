<?php

declare(strict_types=1);

/*
 * Coupons and free time: which test exercises which option.
 *
 * Read by tests/Feature/Coverage/OptionCoverageTest.php. A reference is
 * `file::fragment of the test name` and is checked to exist; what it promises is that
 * the test named there exercises the option and asserts its result.
 */

$feature = 'tests/Feature/';
$browser = 'tests/Browser/';
$matrix = 'tests/Browser/Matrix/';

// The tests most options share.
$entryPoints = $feature.'Coupons/CouponEntryPointMatrixTest.php::takes or refuses each coupon type at each place a code can be typed';
$portal = $feature.'Portal/RedeemCouponFromPortalTest.php::';
$formFeature = $feature.'Admin/CouponFormIntentTest.php::';
$tabFeature = $feature.'Admin/AdminExtensionStateMatrixTest.php::';

$dashboardMatrix = $matrix.'CouponDashboardRedeemMatrixTest.php::test_a_code_typed_into_the_dashboard';
$formMatrix = $matrix.'AdminCouponFormMatrixTest.php::test_the_form_creates_this_kind_and_the_coupon_page_shows_it';
$e2eCheckout = $matrix.'CouponEndToEndMatrixTest.php::test_a_discount_created_by_the_operator_is_what_checkout_charges';
$e2eRestricted = $matrix.'CouponEndToEndMatrixTest.php::test_a_restriction_set_by_the_operator_refuses_the_order_it_excludes';
$tabOffers = $matrix.'AdminSubscriptionTabMatrixTest.php::test_the_tab_offers_exactly_the_actions_that_can_work';
$tabAction = $matrix.'AdminSubscriptionTabMatrixTest.php::test_an_action_does_what_it_says';
$tabRevoke = $matrix.'AdminSubscriptionTabMatrixTest.php::test_a_grant_is_revoked_with_a_reason';
$grantIssue = $matrix.'AdminGrantMatrixTest.php::test_the_operator_issues_a_grant';

return [
    // ── Coupon types ────────────────────────────────────────────────────────
    'enum:CouponType.SinglePayment' => [
        'feature' => [$entryPoints, $feature.'Coupons/CouponServiceTest.php::creates a first-payment coupon and validates and redeems it', $portal.'says what a single-payment code is for instead of redeeming it'],
        'browser' => [$e2eCheckout, $matrix.'CouponEndToEndMatrixTest.php::test_a_discount_restricted_to_a_product_is_what_the_product_page_charges', $dashboardMatrix],
    ],
    'enum:CouponType.Recurring' => [
        'feature' => [$entryPoints, $feature.'Coupons/RecurringDiscountPeriodsTest.php::discounts exactly discount_periods charges', $portal.'applies a recurring code to a running subscription', $feature.'Coupons/RecurringCouponWithChangePatchesMollieTest.php::sends the discounted amount with the one and only PATCH'],
        'browser' => [$e2eCheckout, $matrix.'CouponEndToEndMatrixTest.php::test_a_recurring_discount_entered_with_a_change_is_what_mollie_then_collects', $dashboardMatrix, $browser.'CouponMidCycleTest.php::test_a_recurring_code_discounts_a_plan_change_it_is_entered_on'],
    ],
    'enum:CouponType.Credits' => [
        'feature' => [$entryPoints, $portal.'still redeems credits at once'],
        'browser' => [$dashboardMatrix, $browser.'CouponMidCycleTest.php::test_a_credits_code_fills_the_wallet_the_meter_shows'],
    ],
    'enum:CouponType.TrialExtension' => [
        'feature' => [$entryPoints, $feature.'Coupons/TrialExtensionTest.php::extends trial_ends_at on a Mollie subscription and patches the Mollie startDate to match'],
        'browser' => [$dashboardMatrix, $browser.'CouponCheckoutTest.php::test_a_trial_extension_coupon_lengthens_the_trial_it_is_applied_to'],
    ],
    'enum:CouponType.AccessGrant' => [
        'feature' => [$entryPoints, $portal.'activates a gifted plan for a customer without a subscription', $feature.'Coupons/CouponServiceTest.php::redeems a full access-grant coupon and activates a local subscription'],
        'browser' => [$grantIssue, $dashboardMatrix, $browser.'CouponCatalogCheckoutTest.php::test_an_access_grant_activates_without_taking_money'],
    ],
    'enum:CouponType.PeriodExtension' => [
        'feature' => [$entryPoints, $tabFeature.'moves a paying subscription by exactly N days, and by 2N when given twice'],
        'browser' => [$dashboardMatrix, $tabAction],
    ],
    'enum:CouponType.PlanWaiver' => [
        'feature' => [$entryPoints, $feature.'Coupons/WaiverPeriodsAlignToBillingCycleTest.php::defers a plan-only subscription by whole periods'],
        'browser' => [$dashboardMatrix, $tabAction],
    ],
    'enum:DiscountType.Percentage' => [
        'feature' => [$feature.'Coupons/RecurringDiscountPeriodsTest.php::discounts exactly discount_periods charges', $feature.'Coupons/CouponRedemptionCapsTest.php::rejects raising a percentage discount above 100% on update'],
        'browser' => [$e2eCheckout, $formMatrix],
    ],
    'enum:DiscountType.Fixed' => [
        'feature' => [$feature.'Coupons/ChargeDeferralLifecycleTest.php::does not call a fixed discount that covers only part of the charge a deferral', $feature.'Coupons/PlanWaiverCeilingMatrixTest.php::never takes off more than the charge'],
        'browser' => [$e2eCheckout, $formMatrix],
    ],

    // ── Coupon fields ───────────────────────────────────────────────────────
    'coupon_field:code' => [
        'feature' => [$feature.'Coupons/CouponBasicsAuditTest.php::treats a code as case-insensitive and ignores surrounding whitespace'],
        'browser' => [$formMatrix, $matrix.'AdminCouponFormMatrixTest.php::test_the_form_says_why_a_coupon_cannot_be_created'],
    ],
    'coupon_field:name' => [
        'feature' => [$formFeature.'shows on the coupon page everything the coupon was created with'],
        'browser' => [$formMatrix, $matrix.'AdminCouponFormMatrixTest.php::test_the_list_searches_sorts_by_every_column_and_deactivates_in_bulk'],
    ],
    'coupon_field:description' => [
        'feature' => [$formFeature.'shows on the coupon page everything the coupon was created with'],
        'browser' => [$formMatrix],
    ],
    'coupon_field:type' => [
        'feature' => [$entryPoints, $feature.'Coupons/CouponBasicsAuditTest.php::refuses to change the type of a coupon that has been redeemed', $formFeature.'derives the intent when only the type is set'],
        'browser' => [$formMatrix, $dashboardMatrix],
    ],
    'coupon_field:discount_type' => [
        'feature' => [$feature.'Coupons/ChargeDeferralLifecycleTest.php::does not call a fixed discount that covers only part of the charge a deferral'],
        'browser' => [$formMatrix, $e2eCheckout],
    ],
    'coupon_field:discount_value' => [
        'feature' => [$feature.'Coupons/CouponBasicsAuditTest.php::rejects an edit that would make a redeemed recurring coupon exceed 100%', $feature.'Coupons/CouponBasicsAuditTest.php::leaves running discounts at the value they were granted at when the coupon is edited'],
        'browser' => [$formMatrix, $e2eCheckout],
    ],
    'coupon_field:discount_periods' => [
        'feature' => [$feature.'Coupons/RecurringDiscountPeriodsTest.php::discounts exactly discount_periods charges', $feature.'Migrations/DiscountPeriodsMigrationTest.php::keeps a running discount at exactly the charges it was sold with', $feature.'Coupons/CouponRoundFourGapsTest.php::rolls the discount_periods migration back', $feature.'Coupons/CouponBasicsAuditTest.php::leaves running discounts at the number of periods they were granted with when the coupon is edited', $feature.'Coupons/CouponWindowInBillingPeriodsTest.php::first collects a fully covering code on the charge N periods on, on the billing day', $feature.'Coupons/CouponWindowInBillingPeriodsTest.php::discounts exactly N charges with a partial code, on the billing day', $feature.'Coupons/CouponWindowDatedAndLegacyTest.php::keeps the date a marker of the previous release was given, and charges on it'],
        'browser' => [$formMatrix, $dashboardMatrix, $matrix.'CouponEndToEndMatrixTest.php::test_a_recurring_discount_entered_with_a_change_is_what_mollie_then_collects', $matrix.'CouponWindowMatrixTest.php::test_a_code_for_two_free_periods_on_a_cycle_on_the_31st_returns_on_the_31st'],
    ],
    'coupon_field:credits_payload' => [
        'feature' => [$portal.'still redeems credits at once'],
        'browser' => [$formMatrix, $dashboardMatrix],
    ],
    'coupon_field:trial_extension_days' => [
        'feature' => [$feature.'Coupons/TrialExtensionTest.php::extends trial_ends_at on a Mollie subscription and patches the Mollie startDate to match'],
        'browser' => [$formMatrix, $dashboardMatrix],
    ],
    'coupon_field:grant_plan_code' => [
        'feature' => [$portal.'activates a gifted plan for a customer without a subscription', $feature.'Coupons/AccessGrantStrictMatchTest.php::rejects a grant when the chosen plan does not match the grant plan'],
        'browser' => [$formMatrix, $grantIssue, $matrix.'AdminGrantMatrixTest.php::test_a_grant_that_cannot_apply_is_refused_and_leaves_no_code'],
    ],
    'coupon_field:grant_interval' => [
        'feature' => [$feature.'Coupons/AccessGrantStrictMatchTest.php::rejects a grant when the chosen interval does not match the grant interval'],
        'browser' => [$formMatrix, $grantIssue],
    ],
    'coupon_field:grant_addon_codes' => [
        'feature' => [$feature.'Coupons/PlanWaiverCeilingMatrixTest.php::waives a granted addon while it is active and stops when it is switched off', $formFeature.'creates a full grant under "free time" and keeps the addon-only grant under "advanced"'],
        'browser' => [$formMatrix, $grantIssue, $matrix.'AdminGrantMatrixTest.php::test_an_addon_that_came_with_the_gift_stays_free_next_to_a_bought_one'],
    ],
    'coupon_field:grant_duration_days' => [
        'feature' => [$feature.'Coupons/PeriodExtensionTest.php::extends a local subscription end date by N days when redeemed', $portal.'adds a second gift for the same plan to the end of the first'],
        'browser' => [$formMatrix, $grantIssue, $dashboardMatrix],
    ],
    'coupon_field:grant_on_expiry' => [
        'feature' => [$formFeature.'shows on the coupon page everything the coupon was created with', $feature.'Coupons/PlanWaiverLifecycleAuditTest.php::ends the subscription exactly when the gift no longer reaches the next charge'],
        'browser' => [$formMatrix, $grantIssue, $matrix.'AdminGrantMatrixTest.php::test_a_gift_with_extras_ends_the_way_it_was_given'],
    ],
    'coupon_field:waiver_periods' => [
        'feature' => [$feature.'Coupons/WaiverPeriodsAlignToBillingCycleTest.php::defers a plan-only subscription by whole periods', $formFeature.'shows on the coupon page everything the coupon was created with'],
        'browser' => [$formMatrix, $dashboardMatrix],
    ],
    'coupon_field:minimum_order_amount_net' => [
        'feature' => [$feature.'Coupons/CouponFieldEffectsTest.php::refuses an order below the minimum and takes one at it'],
        'browser' => [$formMatrix, $e2eRestricted],
    ],
    'coupon_field:max_redemptions' => [
        'feature' => [$feature.'Coupons/CouponBasicsAuditTest.php::enforces the campaign cap in redeem() as well as in validate()'],
        'browser' => [$formMatrix, $matrix.'CouponEndToEndMatrixTest.php::test_a_campaign_limit_and_a_per_customer_limit_set_by_the_operator_hold'],
    ],
    'coupon_field:max_redemptions_per_billable' => [
        'feature' => [$feature.'Coupons/CouponBasicsAuditTest.php::enforces the per-billable cap in redeem() as well as in validate()', $feature.'Coupons/RecurringDiscountPeriodsTest.php::lets a customer redeem a recurring coupon again when the per-customer limit allows it', $feature.'Coupons/CouponRoundFourGapsTest.php::counts unmarked renewal rows of an older release as redemptions'],
        'browser' => [$formMatrix, $matrix.'CouponEndToEndMatrixTest.php::test_a_campaign_limit_and_a_per_customer_limit_set_by_the_operator_hold'],
    ],
    'coupon_field:stackable' => [
        'feature' => [$feature.'Coupons/MultiCouponStackingTest.php::rejects a non-stackable coupon when another coupon is already in the apply set'],
        'browser' => [$formMatrix, $matrix.'CouponEndToEndMatrixTest.php::test_stackable_codes_combine_and_a_non_stackable_one_stands_alone'],
    ],
    'coupon_field:auto_apply_token' => [
        'feature' => [$feature.'Coupons/CouponServiceTest.php::resolves a coupon by auto-apply token and falls back to code', $feature.'Coupons/CouponMoneyCorrectnessTest.php::keeps an auto-applied coupon when the amount does reflect it'],
        'browser' => [$formMatrix, $matrix.'CouponEndToEndMatrixTest.php::test_a_campaign_link_applies_the_operators_code_without_showing_it_being_typed'],
    ],
    'coupon_field:applicable_plans' => [
        'feature' => [$formFeature.'restricts a discount to plans and intervals from the form', $feature.'Coupons/CouponScopeAndFulfilmentTest.php::refuses a plan-restricted coupon on a product order'],
        'browser' => [$formMatrix, $e2eRestricted],
    ],
    'coupon_field:applicable_intervals' => [
        'feature' => [$formFeature.'restricts a discount to plans and intervals from the form', $feature.'Coupons/CouponScopeAndFulfilmentTest.php::refuses an interval-restricted coupon when no interval is named'],
        'browser' => [$formMatrix, $e2eRestricted],
    ],
    'coupon_field:applicable_addons' => [
        'feature' => [$feature.'Coupons/CouponScopeAndFulfilmentTest.php::refuses an addon-restricted coupon on an order with no addons'],
        'browser' => [$formMatrix, $e2eRestricted],
    ],
    'coupon_field:applicable_products' => [
        'feature' => [$feature.'Coupons/CouponScopeAndFulfilmentTest.php::refuses a product-restricted coupon on a subscription order'],
        'browser' => [$formMatrix, $e2eRestricted, $matrix.'CouponEndToEndMatrixTest.php::test_a_discount_restricted_to_a_product_is_what_the_product_page_charges'],
    ],
    'coupon_field:applicable_usages' => [
        'feature' => [$feature.'Coupons/CouponFieldEffectsTest.php::refuses a usage-restricted coupon everywhere', $formFeature.'shows on the coupon page everything the coupon was created with'],
        'browser' => [$matrix.'AdminCouponFormMatrixTest.php::test_a_usage_restricted_coupon_is_shown_as_such_and_refused'],
    ],
    'coupon_field:valid_from' => [
        'feature' => [$feature.'Coupons/CouponBasicsAuditTest.php::opens and closes the validity window on the second'],
        'browser' => [$formMatrix, $e2eRestricted],
    ],
    'coupon_field:valid_until' => [
        'feature' => [$feature.'Coupons/CouponBasicsAuditTest.php::opens and closes the validity window on the second', $feature.'Coupons/RecurringDiscountPeriodsTest.php::discounts until the date, without a count, when only valid_until is set', $feature.'Coupons/CouponWindowDatedAndLegacyTest.php::first charges a code that ends on a date on the first regular charge it no longer covers'],
        'browser' => [$formMatrix, $e2eRestricted],
    ],
    'coupon_field:active' => [
        'feature' => [$feature.'Coupons/PlanWaiverLifecycleAuditTest.php::still ends an ordinary recurring coupon when it is deactivated', $feature.'Coupons/ChargeDeferralLifecycleTest.php::ends the deferral when the coupon behind it is deactivated'],
        'browser' => [$matrix.'AdminCouponFormMatrixTest.php::test_an_inactive_coupon_can_be_created_and_is_refused_at_once', $formMatrix, $e2eRestricted],
    ],

    // ── Admin: coupons ──────────────────────────────────────────────────────
    'action:admin.coupons.create@save' => [
        'feature' => [$formFeature.'asks a recurring coupon for its number of periods in a field of its own', $formFeature.'refuses a recurring coupon from the form that has neither periods nor a date'],
        'browser' => [$formMatrix, $matrix.'AdminCouponFormMatrixTest.php::test_the_form_says_why_a_coupon_cannot_be_created'],
    ],
    'action:admin.coupons.index@sort' => [
        'feature' => [$feature.'Admin/CouponListAndDetailActionsTest.php::sorts by an allowed column and ignores any other'],
        'browser' => [$matrix.'AdminCouponFormMatrixTest.php::test_the_list_searches_sorts_by_every_column_and_deactivates_in_bulk'],
    ],
    'action:admin.coupons.index@deactivateSelected' => [
        'feature' => [$feature.'Admin/CouponListAndDetailActionsTest.php::deactivates exactly the selected coupons'],
        'browser' => [$matrix.'AdminCouponFormMatrixTest.php::test_the_list_searches_sorts_by_every_column_and_deactivates_in_bulk'],
    ],
    'action:admin.coupons.show@deactivate' => [
        'feature' => [$feature.'Admin/CouponListAndDetailActionsTest.php::deactivates the coupon the page is locked to'],
        'browser' => [$formMatrix, $matrix.'AdminCouponFormMatrixTest.php::test_a_redeemed_coupon_cannot_be_deleted_only_deactivated'],
    ],
    'action:admin.coupons.show@delete' => [
        'feature' => [$feature.'Admin/CouponListAndDetailActionsTest.php::deletes an unused coupon and refuses a redeemed one'],
        'browser' => [$formMatrix, $matrix.'AdminCouponFormMatrixTest.php::test_a_redeemed_coupon_cannot_be_deleted_only_deactivated'],
    ],
    'screen:billing.admin.coupons.create' => [
        'feature' => [$formFeature.'starts with the three intents, each explained in a sentence'],
        'browser' => [$formMatrix, $matrix.'AdminCouponFormMatrixTest.php::test_every_tooltip_of_the_form_opens_and_wraps'],
    ],
    'screen:billing.admin.coupons.index' => [
        'feature' => [$feature.'Admin/CouponListAndDetailActionsTest.php::sorts by an allowed column and ignores any other'],
        'browser' => [$matrix.'AdminCouponFormMatrixTest.php::test_the_list_searches_sorts_by_every_column_and_deactivates_in_bulk'],
    ],
    'screen:billing.admin.coupons.show' => [
        'feature' => [$formFeature.'shows on the coupon page everything the coupon was created with'],
        'browser' => [$formMatrix, $matrix.'AdminCouponFormMatrixTest.php::test_a_redeemed_coupon_cannot_be_deleted_only_deactivated'],
    ],

    // ── Admin: grants ───────────────────────────────────────────────────────
    'action:admin.grants.issue@issue' => [
        'feature' => [$feature.'Coupons/CouponBasicsAuditTest.php::issues a grant from the admin panel as a code that works once', $feature.'Admin/GrantIssueGuardsTest.php::refuses a full grant on a live Mollie subscription'],
        'browser' => [$grantIssue, $matrix.'AdminGrantMatrixTest.php::test_a_grant_that_cannot_apply_is_refused_and_leaves_no_code'],
    ],
    'screen:billing.admin.grants.create' => [
        'feature' => [$feature.'Coupons/CouponBasicsAuditTest.php::issues a grant from the admin panel as a code that works once'],
        'browser' => [$grantIssue],
    ],

    // ── Admin: subscription tab ─────────────────────────────────────────────
    'action:admin.billables.subscription-tab@billable' => [
        'feature' => [$feature.'Admin/FreeTimeActionsAndCouponFormTest.php::extends a paying subscription by days from the subscription tab'],
        'browser' => [$tabOffers],
    ],
    'action:admin.billables.subscription-tab@grants' => [
        'feature' => [$feature.'Admin/FreeTimeActionsAndCouponFormTest.php::gives free plan periods from the subscription tab and lists them with a revoke button'],
        'browser' => [$tabRevoke, $matrix.'AdminSubscriptionTabMatrixTest.php::test_a_period_extension_is_listed_but_cannot_be_revoked'],
    ],
    'action:admin.billables.subscription-tab@extendTrial' => [
        'feature' => [$tabFeature.'extends a running trial by date, later only', $tabFeature.'charge earlier with a trial extension'],
        'browser' => [$tabOffers, $tabAction],
    ],
    'action:admin.billables.subscription-tab@extendSubscription' => [
        'feature' => [$feature.'Admin/FreeTimeActionsAndCouponFormTest.php::extends a paying subscription by days from the subscription tab', $tabFeature.'refuses "extend by days" where there is nothing to extend, and leaves nothing behind'],
        'browser' => [$tabOffers, $tabAction, $matrix.'AdminSubscriptionTabMatrixTest.php::test_a_second_identical_extension_within_seconds_is_refused_on_screen', $matrix.'AdminSubscriptionTabMatrixTest.php::test_an_extension_that_cannot_work_is_refused_with_its_reason'],
    ],
    'action:admin.billables.subscription-tab@giveFreePlanPeriods' => [
        'feature' => [$feature.'Admin/FreeTimeActionsAndCouponFormTest.php::gives free plan periods from the subscription tab and lists them with a revoke button', $tabFeature.'refuses free plan periods where they cannot apply, and leaves nothing behind'],
        'browser' => [$tabOffers, $tabAction],
    ],
    'action:admin.billables.subscription-tab@extendGrant' => [
        'feature' => [$feature.'Admin/FreeTimeActionsAndCouponFormTest.php::extends a gift and changes what happens at its end from the subscription tab', $tabFeature.'refuses "extend the gift" where no gift is running, and leaves nothing behind', $feature.'Coupons/GrantKeepsRunningWithPaidExtrasTest.php::leaves no gift to extend once the plan became chargeable at carry-over'],
        'browser' => [$tabOffers, $tabAction],
    ],
    'action:admin.billables.subscription-tab@openCancel' => [
        'feature' => [$feature.'Admin/SubscriptionTabCancelAndRevokeTest.php::opens the cancel prompt for the variant that was asked for'],
        'browser' => [$tabOffers, $tabAction],
    ],
    'action:admin.billables.subscription-tab@confirmCancel' => [
        'feature' => [$feature.'Admin/SubscriptionTabCancelAndRevokeTest.php::cancels only with a reason, and records it'],
        'browser' => [$tabAction, $browser.'AdminPanelTest.php::test_an_operator_cancels_a_subscription_and_the_reason_lands_in_the_audit_trail'],
    ],
    'action:admin.billables.subscription-tab@resubscribe' => [
        'feature' => [$feature.'Admin/SubscriptionTabCancelAndRevokeTest.php::resubscribes a subscription that is running out'],
        'browser' => [$tabOffers, $tabAction],
    ],
    'action:admin.billables.subscription-tab@openRevoke' => [
        'feature' => [$feature.'Admin/SubscriptionTabCancelAndRevokeTest.php::opens the revoke prompt only for a grant of this billable that can be revoked'],
        'browser' => [$tabRevoke, $matrix.'AdminSubscriptionTabMatrixTest.php::test_a_period_extension_is_listed_but_cannot_be_revoked'],
    ],
    'action:admin.billables.subscription-tab@revokeGrant' => [
        'feature' => [$feature.'Admin/SubscriptionTabCancelAndRevokeTest.php::revokes only with a reason, and records it', $feature.'Coupons/CouponBasicsAuditTest.php::does not put a one-off admin code back on offer when it is revoked'],
        'browser' => [$tabRevoke, $matrix.'AdminGrantMatrixTest.php::test_a_gifted_tenant_buys_seats_then_the_gift_is_revoked'],
    ],

    // ── Customer dashboard ──────────────────────────────────────────────────
    'action:dashboard@redeemCoupon' => [
        'feature' => [$portal.'applies a recurring code to a running subscription', $feature.'Coupons/DashboardCouponRedemptionAuditTest.php::applies a recurring discount straight from the dashboard and says from when it counts', $feature.'Portal/PortalMutatingActionsAuthorizationTest.php::forbids redeeming a coupon from the dashboard when authorization is denied'],
        'browser' => [$dashboardMatrix, $matrix.'CouponDashboardRedeemMatrixTest.php::test_the_discount_shown_after_redeeming_is_what_the_next_renewal_books'],
    ],
    'action:dashboard@dashboardData' => [
        'feature' => [$feature.'Coupons/DashboardCouponRedemptionAuditTest.php::gives a paying customer free plan periods, once per customer'],
        'browser' => [$dashboardMatrix, $matrix.'CouponDashboardActionsMatrixTest.php::test_a_customer_whose_first_charge_is_still_scheduled_asks_for_it_now'],
    ],
    'action:dashboard@cancelSubscription' => [
        'feature' => [$feature.'Portal/PortalMutatingActionsAuthorizationTest.php::does not cancel the subscription when authorization is denied', $feature.'Portal/PortalMutatingActionsAuthorizationTest.php::allows the dashboard actions again once the host app grants'],
        'browser' => [$browser.'PortalDashboardTest.php::test_cancel_keeps_access_until_period_end_and_resubscribe_recovers'],
    ],
    'action:dashboard@resubscribe' => [
        'feature' => [$feature.'Portal/PortalMutatingActionsAuthorizationTest.php::forbids dashboard action when authorization is denied', $feature.'Portal/DashboardStateActionsTest.php::resubscribes during the grace period'],
        'browser' => [$browser.'PortalDashboardTest.php::test_cancel_keeps_access_until_period_end_and_resubscribe_recovers'],
    ],
    'action:dashboard@endTrial' => [
        'feature' => [$feature.'Portal/PortalMutatingActionsAuthorizationTest.php::forbids dashboard action when authorization is denied', $feature.'Portal/DashboardStateActionsTest.php::ends a running trial at once and does nothing without one'],
        'browser' => [$matrix.'CouponDashboardActionsMatrixTest.php::test_a_customer_ends_their_trial_early'],
    ],
    'action:dashboard@forceImmediateMollieCharge' => [
        'feature' => [$feature.'Portal/PortalMutatingActionsAuthorizationTest.php::forbids dashboard action when authorization is denied', $feature.'Portal/DashboardStateActionsTest.php::brings the first charge forward only while the billable is past due'],
        'browser' => [$matrix.'CouponDashboardActionsMatrixTest.php::test_a_customer_whose_first_charge_is_still_scheduled_asks_for_it_now'],
    ],
    'action:dashboard@reactivateAfterMismatch' => [
        'feature' => [$feature.'Portal/PortalMutatingActionsAuthorizationTest.php::forbids dashboard action when authorization is denied', $feature.'Portal/DashboardStateActionsTest.php::reactivates after a resolved mismatch and refuses while one is open'],
        'browser' => [$matrix.'CouponDashboardActionsMatrixTest.php::test_a_customer_reactivates_after_their_country_mismatch_was_resolved'],
    ],
    'action:dashboard@cancelScheduledChange' => [
        'feature' => [$feature.'Portal/PortalMutatingActionsAuthorizationTest.php::forbids dashboard action when authorization is denied', $feature.'Portal/DashboardStateActionsTest.php::cancels a scheduled change from the dashboard'],
        'browser' => [$browser.'PlanChangeTest.php::test_a_downgrade_is_scheduled_for_the_period_end_and_can_be_cancelled'],
    ],
    'action:dashboard@openCountryFix' => [
        'feature' => [$feature.'Portal/PortalMutatingActionsAuthorizationTest.php::forbids dashboard action when authorization is denied'],
        'browser' => [$browser.'CountryMismatchTest.php::test_the_customer_corrects_the_country_and_the_invoice_is_reissued_at_the_new_rate'],
    ],
    'action:dashboard@saveCountryFix' => [
        'feature' => [$feature.'Portal/PortalMutatingActionsAuthorizationTest.php::forbids dashboard action when authorization is denied', $feature.'Portal/PortalMutatingActionsAuthorizationTest.php::forbids resolving a country mismatch when authorization is denied'],
        'browser' => [$browser.'CountryMismatchTest.php::test_the_customer_corrects_the_country_and_the_invoice_is_reissued_at_the_new_rate'],
    ],

    // Added in this round: the record that a redemption row is missing.
    'event:CouponRedemptionRecordMissing' => [
        'feature' => [$feature.'Coupons/FailedRedemptionAfterPaymentTest.php::records a missing redemption row as an audit entry, not as a mail to the admins'],
    ],
];
