<?php

declare(strict_types=1);

/*
 * Lifecycle: every subscription/invoice status, every event and every mail.
 *
 * Events and mails are claimed by a test that drives their REAL cause and asserts payload
 * (or recipient and text) plus, for events, the audit row. Enum values are additionally
 * shown in Chrome, in the customer portal and the admin panel.
 */

$subscription = 'tests/Feature/Lifecycle/EventCatalogSubscriptionTest.php';
$money = 'tests/Feature/Lifecycle/EventCatalogMoneyTest.php';
$mails = 'tests/Feature/Lifecycle/NotificationCatalogTest.php';
$transitions = 'tests/Feature/Lifecycle/StatusTransitionMatrixTest.php';
$browser = 'tests/Browser/Matrix/LifecycleStateMatrixTest.php';
$noUi = 'An event or mail has no screen of its own; what it leaves behind — the audit row — is asserted by the feature test, and the audit tab is covered by the operations inventory.';

$event = static fn (string ...$tests): array => ['feature' => $tests, 'no_browser' => $noUi];

return [
    // ── Subscription status ─────────────────────────────────────────────────────
    'enum:SubscriptionStatus.New' => [
        'feature' => [$transitions.'::moves a subscription only along the allowed transitions'],
        'browser' => [$browser.'::test_checkout_takes_a_new_customer_to_an_active_mollie_subscription'],
    ],
    'enum:SubscriptionStatus.Active' => [
        'feature' => [$transitions.'::moves a subscription only along the allowed transitions', 'tests/Feature/Webhook/RenewalLongRunTest.php::runs a monthly subscription through 24 renewals without drifting'],
        'browser' => [$browser.'::test_checkout_takes_a_new_customer_to_an_active_mollie_subscription', $browser.'::test_a_gifted_plan_is_an_active_local_subscription_until_it_ends'],
    ],
    'enum:SubscriptionStatus.Trial' => [
        'feature' => [$transitions.'::moves a subscription only along the allowed transitions', 'tests/Feature/Webhook/RenewalLongRunVariantsTest.php::converts a trial with its first charge and runs on from the day the trial ended'],
        'browser' => [$browser.'::test_a_trial_converts_with_its_first_charge', $browser.'::test_a_trial_whose_first_charge_never_arrives_lapses', $browser.'::test_a_trial_without_a_mandate_ends_without_a_subscription'],
    ],
    'enum:SubscriptionStatus.PastDue' => [
        'feature' => [$transitions.'::moves a subscription only along the allowed transitions', 'tests/Feature/Webhook/RenewalLongRunDeliveryTest.php::recovers from a failed renewal with the next successful one'],
        'browser' => [$browser.'::test_a_failed_renewal_runs_through_dunning_to_cancellation_or_recovers', $browser.'::test_a_past_due_dashboard_follows_the_status_mollie_reports'],
    ],
    'enum:SubscriptionStatus.Cancelled' => [
        'feature' => [$transitions.'::moves a subscription only along the allowed transitions', 'tests/Feature/Webhook/EndedSubscriptionRenewalRefundTest.php::refunds the renewal in full, cancels the Mollie subscription and tells the customer'],
        'browser' => [$browser.'::test_cancel_at_period_end_resubscribe_cancel_again_and_expire', $browser.'::test_an_immediate_cancellation_ends_access_at_once'],
    ],
    'enum:SubscriptionStatus.Expired' => [
        'feature' => [$transitions.'::moves a subscription only along the allowed transitions', $subscription.'::dispatches SubscriptionCancelled, SubscriptionResumed and SubscriptionExpired along a cancellation'],
        'browser' => [$browser.'::test_cancel_at_period_end_resubscribe_cancel_again_and_expire'],
    ],

    // ── Subscription source ─────────────────────────────────────────────────────
    'enum:SubscriptionSource.None' => [
        'feature' => [$transitions.'::moves a subscription only along the allowed transitions'],
        'browser' => [$browser.'::test_checkout_takes_a_new_customer_to_an_active_mollie_subscription', $browser.'::test_cancel_at_period_end_resubscribe_cancel_again_and_expire'],
    ],
    'enum:SubscriptionSource.Local' => [
        'feature' => [$money.'::dispatches SubscriptionUpgradedFromLocal when a free subscription becomes a paid one', $money.'::dispatches GrantRevoked when a gift is taken back'],
        'browser' => [$browser.'::test_a_gifted_plan_is_an_active_local_subscription_until_it_ends', $browser.'::test_a_trial_without_a_mandate_ends_without_a_subscription'],
    ],
    'enum:SubscriptionSource.Mollie' => [
        'feature' => [$subscription.'::dispatches SubscriptionCreated when the first payment activates the subscription'],
        'browser' => [$browser.'::test_checkout_takes_a_new_customer_to_an_active_mollie_subscription'],
    ],

    // ── Invoices ────────────────────────────────────────────────────────────────
    'enum:InvoiceKind.Subscription' => [
        'feature' => [$subscription.'::dispatches InvoiceCreated and PaymentSucceeded for the invoice a payment is booked on'],
        'browser' => [$browser.'::test_every_invoice_kind_and_status_is_labelled_for_customer_and_operator'],
    ],
    'enum:InvoiceKind.OneTimeOrder' => [
        'feature' => [$money.'::dispatches OneTimeOrderCompleted when a product purchase is paid'],
        'browser' => [$browser.'::test_every_invoice_kind_and_status_is_labelled_for_customer_and_operator'],
    ],
    'enum:InvoiceKind.Overage' => [
        'feature' => [$money.'::dispatches OverageCharged when the overage of a period is collected', 'tests/Feature/Webhook/RenewalLongRunVariantsTest.php::collects the overage of every period before it renews, six months running'],
        'browser' => [$browser.'::test_every_invoice_kind_and_status_is_labelled_for_customer_and_operator'],
    ],
    'enum:InvoiceKind.Refund' => [
        'feature' => [$money.'::dispatches InvoiceRefunded and CreditNoteIssued when an invoice is refunded'],
        'browser' => [$browser.'::test_every_invoice_kind_and_status_is_labelled_for_customer_and_operator'],
    ],
    'enum:InvoiceStatus.Paid' => [
        'feature' => [$subscription.'::dispatches InvoiceCreated and PaymentSucceeded for the invoice a payment is booked on'],
        'browser' => [$browser.'::test_every_invoice_kind_and_status_is_labelled_for_customer_and_operator'],
    ],
    'enum:InvoiceStatus.Refunded' => [
        'feature' => [$money.'::dispatches InvoiceRefunded and CreditNoteIssued when an invoice is refunded'],
        'browser' => [$browser.'::test_every_invoice_kind_and_status_is_labelled_for_customer_and_operator'],
    ],
    'enum:InvoiceStatus.Open' => [
        'no_feature' => 'The package never writes this status: every invoice is created from a settled payment. The value exists for documents an app imports; all the package does with it is label it, which the browser test asserts.',
        'browser' => [$browser.'::test_every_invoice_kind_and_status_is_labelled_for_customer_and_operator'],
    ],
    'enum:InvoiceStatus.Failed' => [
        'no_feature' => 'The package never writes this status: a failed payment produces no invoice at all. The value exists for documents an app imports; all the package does with it is label it, which the browser test asserts.',
        'browser' => [$browser.'::test_every_invoice_kind_and_status_is_labelled_for_customer_and_operator'],
    ],

    // ── What Mollie reports for a subscription ──────────────────────────────────
    'enum:MollieSubscriptionStatus.Pending' => [
        'feature' => ['tests/Feature/Lifecycle/MollieSubscriptionStatusTest.php::reads every Mollie subscription status the way the lifecycle needs it'],
        'browser' => [$browser.'::test_a_past_due_dashboard_follows_the_status_mollie_reports'],
    ],
    'enum:MollieSubscriptionStatus.Active' => [
        'feature' => ['tests/Feature/Lifecycle/MollieSubscriptionStatusTest.php::reads every Mollie subscription status the way the lifecycle needs it'],
        'browser' => [$browser.'::test_a_past_due_dashboard_follows_the_status_mollie_reports'],
    ],
    'enum:MollieSubscriptionStatus.Suspended' => [
        'feature' => ['tests/Feature/Lifecycle/MollieSubscriptionStatusTest.php::reads every Mollie subscription status the way the lifecycle needs it'],
        'browser' => [$browser.'::test_a_past_due_dashboard_follows_the_status_mollie_reports'],
    ],
    'enum:MollieSubscriptionStatus.Canceled' => [
        'feature' => ['tests/Feature/Lifecycle/MollieSubscriptionStatusTest.php::reads every Mollie subscription status the way the lifecycle needs it'],
        'browser' => [$browser.'::test_a_past_due_dashboard_follows_the_status_mollie_reports'],
    ],
    'enum:MollieSubscriptionStatus.Completed' => [
        'feature' => ['tests/Feature/Lifecycle/MollieSubscriptionStatusTest.php::reads every Mollie subscription status the way the lifecycle needs it'],
        'browser' => [$browser.'::test_a_past_due_dashboard_follows_the_status_mollie_reports'],
    ],

    // ── Events ──────────────────────────────────────────────────────────────────
    'event:CheckoutStarted' => $event($subscription.'::dispatches CheckoutStarted when a checkout creates its payment at Mollie'),
    'event:SubscriptionCreated' => $event($subscription.'::dispatches SubscriptionCreated when the first payment activates the subscription'),
    'event:InvoiceCreated' => $event($subscription.'::dispatches InvoiceCreated and PaymentSucceeded for the invoice a payment is booked on'),
    'event:PaymentSucceeded' => $event($subscription.'::dispatches InvoiceCreated and PaymentSucceeded for the invoice a payment is booked on'),
    'event:WalletCredited' => $event($subscription.'::dispatches WalletCredited for the included quota of a new subscription'),
    'event:CouponRedeemed' => $event($subscription.'::dispatches CouponRedeemed when a checkout coupon is redeemed'),
    'event:MandateUpdated' => $event($subscription.'::dispatches MandateUpdated and TrialStarted when a trial signup captures its mandate'),
    'event:TrialStarted' => $event($subscription.'::dispatches MandateUpdated and TrialStarted when a trial signup captures its mandate'),
    'event:TrialConverted' => $event($subscription.'::dispatches TrialConverted when the first charge after a trial settles'),
    'event:TrialExtended' => $event($subscription.'::dispatches TrialExtended when a running trial is given more time'),
    'event:TrialExpired' => $event($subscription.'::dispatches TrialExpired when a trial ends without its first charge'),
    'event:PaymentFailed' => $event($subscription.'::dispatches PaymentFailed when a renewal fails'),
    'event:PaymentAmountMismatch' => $event($subscription.'::dispatches PaymentAmountMismatch when Mollie collected another amount than the lines add up to'),
    'event:WalletReset' => $event($subscription.'::dispatches WalletReset when a renewal refills a quota that does not roll over'),
    'event:DuplicatePaymentReceived' => $event($subscription.'::dispatches DuplicatePaymentReceived for a second first payment'),
    'event:SeatsChanged' => $event($subscription.'::dispatches SeatsChanged and SubscriptionUpdated when seats are added'),
    'event:SubscriptionUpdated' => $event($subscription.'::dispatches SeatsChanged and SubscriptionUpdated when seats are added'),
    'event:AddonEnabled' => $event($subscription.'::dispatches AddonEnabled and AddonDisabled when an addon is booked and dropped'),
    'event:AddonDisabled' => $event($subscription.'::dispatches AddonEnabled and AddonDisabled when an addon is booked and dropped'),
    'event:PlanChangePending' => $event($subscription.'::dispatches PlanChangePending, then PlanChanged once the prorata charge settles'),
    'event:PlanChanged' => $event($subscription.'::dispatches PlanChangePending, then PlanChanged once the prorata charge settles'),
    'event:PlanChangeFailed' => $event($subscription.'::dispatches PlanChangeFailed when the prorata charge of a plan change fails'),
    'event:SubscriptionChangeScheduled' => $event($subscription.'::dispatches SubscriptionChangeScheduled, Rescheduled and Cancelled for a change booked for the period end'),
    'event:SubscriptionChangeRescheduled' => $event($subscription.'::dispatches SubscriptionChangeScheduled, Rescheduled and Cancelled for a change booked for the period end'),
    'event:SubscriptionChangeCancelled' => $event($subscription.'::dispatches SubscriptionChangeScheduled, Rescheduled and Cancelled for a change booked for the period end'),
    'event:SubscriptionChangeApplyFailed' => $event('tests/Feature/Subscription/ScheduledChangeAutoRetryTest.php::SubscriptionChangeApplyFailed'),
    'event:SubscriptionCancelled' => $event($subscription.'::dispatches SubscriptionCancelled, SubscriptionResumed and SubscriptionExpired along a cancellation'),
    'event:SubscriptionResumed' => $event($subscription.'::dispatches SubscriptionCancelled, SubscriptionResumed and SubscriptionExpired along a cancellation'),
    'event:SubscriptionExpired' => $event($subscription.'::dispatches SubscriptionCancelled, SubscriptionResumed and SubscriptionExpired along a cancellation'),
    'event:SubscriptionExtended' => $event($subscription.'::dispatches SubscriptionExtended when an operator gives a running gift more days', $subscription.'::moves the next charge, not an end date, when free days go to a paying subscriber'),
    'event:SubscriptionActivationFailed' => $event('tests/Feature/Webhook/ActivationRetryTest.php::refunds the payment and tells customer and operator once every retry is spent'),
    'event:UnwantedPaymentRefunded' => $event('tests/Feature/Webhook/EndedSubscriptionRenewalRefundTest.php::refunds the renewal in full, cancels the Mollie subscription and tells the customer', 'tests/Feature/Webhook/ActivationRetryTest.php::refunds the payment and tells customer and operator once every retry is spent'),
    'event:RenewalCollectedForEndedSubscription' => $event('tests/Feature/Webhook/EndedSubscriptionRenewalRefundTest.php::refunds the renewal in full, cancels the Mollie subscription and tells the customer'),
    'event:RenewalUndercollected' => $event('tests/Feature/Subscription/ChangeMatrixKnownLimitsTest.php::writes the automatic back-charge of a spanned plan change to the audit trail'),
    'event:InvoiceRefunded' => $event($money.'::dispatches InvoiceRefunded and CreditNoteIssued when an invoice is refunded'),
    'event:CreditNoteIssued' => $event($money.'::dispatches InvoiceRefunded and CreditNoteIssued when an invoice is refunded'),
    'event:RefundReversed' => $event($money.'::dispatches RefundReversed when Mollie reports a booked refund as failed'),
    'event:ChargebackReversed' => $event('tests/Feature/Webhook/ChargebackReversalTest.php::takes a chargeback back out of the books when it is reversed'),
    'event:ChargebackOnRefundedPayment' => $event('tests/Feature/Webhook/ChargebackAfterRefundTest.php::tells the operator, once, about a chargeback on a payment that was refunded in full'),
    'event:InvoicePdfRegenerated' => $event($money.'::dispatches InvoicePdfRegenerated when an invoice document is rendered again'),
    'event:OneTimeOrderCompleted' => $event($money.'::dispatches OneTimeOrderCompleted when a product purchase is paid, and OneTimeOrderFailed when it is not'),
    'event:OneTimeOrderFailed' => $event($money.'::dispatches OneTimeOrderCompleted when a product purchase is paid, and OneTimeOrderFailed when it is not'),
    'event:UsageLimitReached' => $event($money.'::dispatches UsageLimitReached when usage hits a quota that cannot be exceeded'),
    'event:OverageCharged' => $event($money.'::dispatches OverageCharged when the overage of a period is collected'),
    'event:OverageChargeFailed' => $event($money.'::dispatches OverageChargeFailed when the retry of an overage charge cannot reach Mollie'),
    'event:CountryMismatchFlagged' => $event($money.'::dispatches CountryMismatchFlagged at a renewal whose signals disagree'),
    'event:CountryMismatchResolved' => $event($money.'::dispatches CountryMismatchFlagged at a renewal whose signals disagree, and CountryMismatchResolved when it is corrected'),
    'event:MollieSubscriptionReplaced' => $event(
        $money.'::dispatches MollieSubscriptionReplaced when the next charge of a charged subscription has to move',
        'tests/Feature/Billing/ChargedSubscriptionReplacementTest.php::replaces a charged subscription to keep the anchor day',
    ),
    'event:MollieSubscriptionReferenceCleared' => $event($money.'::dispatches MollieSubscriptionReferenceCleared when Mollie says the subscription is gone'),
    'event:CheckoutAbandoned' => $event($money.'::dispatches CheckoutAbandoned when a signup that never paid is cleaned up'),
    'event:GrantRevoked' => $event($money.'::dispatches GrantRevoked when a gift is taken back'),
    'event:GrantExtrasPurchaseRefunded' => $event('tests/Feature/Checkout/GrantExtrasGiftGoneRefundTest.php::writes an audit entry and words the customer mail in both languages'),
    'event:SubscriptionUpgradedFromLocal' => $event($money.'::dispatches SubscriptionUpgradedFromLocal when a free subscription becomes a paid one'),

    // ── Mails ───────────────────────────────────────────────────────────────────
    'notification:InvoiceAvailableNotification' => $event($mails.'::sends InvoiceAvailableNotification to the customer when a payment is invoiced'),
    'notification:SubscriptionPaymentFailedNotification' => $event($mails.'::sends SubscriptionPaymentFailedNotification with the amount and the day the renewal failed'),
    'notification:TrialConvertedNotification' => $event($mails.'::sends TrialConvertedNotification with the plan and the first invoice amount'),
    'notification:TrialEndingSoonNotification' => $event($mails.'::sends TrialEndingSoonNotification the day before a trial ends, naming the day'),
    'notification:TrialExpiredNotification' => $event($mails.'::sends TrialExpiredNotification when a trial lapses, naming the day it ended'),
    'notification:SubscriptionCancelledNotification' => $event($mails.'::sends SubscriptionCancelledNotification with the day access ends'),
    'notification:RefundProcessedNotification' => $event($mails.'::sends RefundProcessedNotification with the refunded amount'),
    'notification:AdminRefundFailedNotification' => $event($mails.'::sends AdminRefundFailedNotification to the operator when Mollie refuses a refund'),
    'notification:PlanChangeFailedNotification' => $event($mails.'::sends PlanChangeFailedNotification to the customer when the charge of a plan change fails'),
    'notification:AdminPlanChangeFailedNotification' => $event($mails.'::sends AdminPlanChangeFailedNotification to the operator when an activation is given up'),
    'notification:UnwantedPaymentRefundedNotification' => $event($mails.'::sends UnwantedPaymentRefundedNotification to the customer with the amount and the reason'),
    'notification:AdminPaidWithoutBillableNotification' => $event($mails.'::sends AdminPaidWithoutBillableNotification to the operator for a payment that names nobody'),
    'notification:CountryMismatchSelfNotification' => $event($mails.'::sends CountryMismatchSelfNotification to the customer when the country signals disagree'),
    'notification:CountryMismatchReissueFailedNotification' => $event($mails.'::sends CountryMismatchReissueFailedNotification to the customer when the corrected charge fails'),
    'notification:UsageThresholdNotification' => $event($mails.'::sends UsageThresholdNotification when usage crosses the warning threshold'),
    'notification:AdminOverageSweepFailedNotification' => $event($mails.'::sends AdminOverageSweepFailedNotification to the operator when the nightly overage pass dies'),
    'notification:UndercollectionChargedNotification' => $event($mails.'::sends UndercollectionChargedNotification with the amount charged on top', 'tests/Feature/Subscription/ChangeMatrixKnownLimitsTest.php::UndercollectionChargedNotification'),
    'notification:ScheduledChangeNotAppliedNotification' => $event($mails.'::sends ScheduledChangeNotAppliedNotification when a scheduled change cannot be carried out', 'tests/Feature/Subscription/ScheduledChangeAutoRetryTest.php::drops the change after the last attempt, tells the customer, and only then an admin'),
    'notification:GrantExtrasPurchaseRefundedNotification' => $event('tests/Feature/Checkout/GrantExtrasGiftGoneRefundTest.php::writes an audit entry and words the customer mail in both languages'),
    'notification:OverageBillingFailedNotification' => $event('tests/Feature/Jobs/OverageDunningBudgetTest.php::gives the cycle up once three distinct payments have failed, tells the customer and leaves the debt standing', 'tests/Feature/Notifications/NotificationAmountTest.php::OverageBillingFailedNotification'),
    'notification:AdminOverageBillingFailedNotification' => $event('tests/Feature/Jobs/OverageDunningBudgetTest.php::tells the admins when the retries run out without ever reaching Mollie', 'tests/Feature/Notifications/NotificationAmountTest.php::AdminOverageBillingFailedNotification'),

    // ── Jobs the automatic resolution added ─────────────────────────────────────
    'job:RetrySubscriptionActivationJob' => [
        'feature' => ['tests/Feature/Webhook/ActivationRetrySyncQueueTest.php::does not run the retry inside the delivery when the queue is sync', 'tests/Feature/Webhook/ActivationRetrySyncQueueTest.php::picks an activation up again from the sweep when its job was lost', 'tests/Feature/Webhook/ActivationRetryTest.php::activates on the retry when creating the Mollie subscription failed the first time', 'tests/Feature/Webhook/ActivationRetryTest.php::adopts the subscription Mollie did create when only its answer was lost'],
        'no_browser' => 'A queued job has no screen; what it does to the subscription is asserted on the billable, the invoice and the requests sent to Mollie.',
    ],
    'job:RefundUnassignedPaymentsJob' => [
        'feature' => ['tests/Feature/Webhook/UnassignedPaymentRefundTest.php::refunds the payment at Mollie after fourteen days, and not a day earlier'],
        'no_browser' => 'A nightly job for payments that belong to no billable: there is no billable whose screens could show anything.',
    ],
];
