<?php

declare(strict_types=1);

/*
 * Option coverage, area OPERATIONS: the admin panel outside coupons, grants and the
 * subscription tab (billables list and detail, invoice, wallet and audit tabs, bulk
 * actions, dashboard, mismatches, OSS, past due, refunds, scheduled changes), the
 * customer's usage and invoice pages, every artisan command and every job, and the
 * configuration keys for branding, routes, invoices, OSS, audit, cleanup, queue, job times
 * and thresholds. See tests/Feature/Coverage/OptionCoverageTest.php.
 *
 * A command or a job has no page; where its effect can be seen on one, the browser test
 * that shows it is named, otherwise the entry says why there is none.
 */

return [
    'enum:AuditCategory.Subscription' => [
        'feature' => [
            'tests/Feature/Audit/AuditCategoryCasesTest.php::files an event under its category, filters by it and can switch it off',
            'tests/Feature/Audit/AuditCategoryCasesTest.php::has at least one audited event in every category',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_audit_tab_filters_by_every_category',
        ],
    ],
    'enum:AuditCategory.Payment' => [
        'feature' => [
            'tests/Feature/Audit/AuditCategoryCasesTest.php::files an event under its category, filters by it and can switch it off',
            'tests/Feature/Audit/AuditCategoryCasesTest.php::has at least one audited event in every category',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_audit_tab_filters_by_every_category',
        ],
    ],
    'enum:AuditCategory.Invoice' => [
        'feature' => [
            'tests/Feature/Audit/AuditCategoryCasesTest.php::files an event under its category, filters by it and can switch it off',
            'tests/Feature/Audit/AuditCategoryCasesTest.php::has at least one audited event in every category',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_audit_tab_filters_by_every_category',
        ],
    ],
    'enum:AuditCategory.PaymentMethod' => [
        'feature' => [
            'tests/Feature/Audit/AuditCategoryCasesTest.php::files an event under its category, filters by it and can switch it off',
            'tests/Feature/Audit/AuditCategoryCasesTest.php::has at least one audited event in every category',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_audit_tab_filters_by_every_category',
        ],
    ],
    'enum:AuditCategory.Coupon' => [
        'feature' => [
            'tests/Feature/Audit/AuditCategoryCasesTest.php::files an event under its category, filters by it and can switch it off',
            'tests/Feature/Audit/AuditCategoryCasesTest.php::has at least one audited event in every category',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_audit_tab_filters_by_every_category',
        ],
    ],
    'enum:AuditCategory.Trial' => [
        'feature' => [
            'tests/Feature/Audit/AuditCategoryCasesTest.php::files an event under its category, filters by it and can switch it off',
            'tests/Feature/Audit/AuditCategoryCasesTest.php::has at least one audited event in every category',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_audit_tab_filters_by_every_category',
        ],
    ],
    'enum:AuditCategory.Usage' => [
        'feature' => [
            'tests/Feature/Audit/AuditCategoryCasesTest.php::files an event under its category, filters by it and can switch it off',
            'tests/Feature/Audit/AuditCategoryCasesTest.php::has at least one audited event in every category',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_audit_tab_filters_by_every_category',
        ],
    ],
    'enum:AuditCategory.Compliance' => [
        'feature' => [
            'tests/Feature/Audit/AuditCategoryCasesTest.php::files an event under its category, filters by it and can switch it off',
            'tests/Feature/Audit/AuditCategoryCasesTest.php::has at least one audited event in every category',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_audit_tab_filters_by_every_category',
        ],
    ],
    'enum:OssExportStatus.Queued' => [
        'feature' => [
            'tests/Feature/Admin/OperationsAdminActionsTest.php::oss: generate queues one export per year',
            'tests/Feature/Jobs/GenerateOssExportJobTest.php::skips processing when status is not queued',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_oss_export_is_generated_downloaded_and_every_status_is_shown',
        ],
    ],
    'enum:OssExportStatus.Processing' => [
        'feature' => [
            'tests/Feature/Admin/OssExportStuckRunTest.php::still refuses a second run while the first one is genuinely in flight',
            'tests/Feature/Admin/OssExportStuckRunTest.php::lets a new export start once an abandoned run is past the stale cutoff',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_oss_export_is_generated_downloaded_and_every_status_is_shown',
        ],
    ],
    'enum:OssExportStatus.Ready' => [
        'feature' => [
            'tests/Feature/Jobs/GenerateOssExportJobTest.php::generates an OSS export file and marks the row as ready',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_oss_export_is_generated_downloaded_and_every_status_is_shown',
        ],
    ],
    'enum:OssExportStatus.Failed' => [
        'feature' => [
            'tests/Feature/Jobs/GenerateOssExportJobTest.php::marks the row as failed when the service throws',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_oss_export_is_generated_downloaded_and_every_status_is_shown',
        ],
    ],
    'enum:RefundReasonCode.ServiceOutage' => [
        'feature' => [
            'tests/Feature/Refund/RefundReasonCodesTest.php::refunds under every reason code and records it on the credit note',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminRefundMatrixTest.php::test_refund_from_the_invoice_tab',
        ],
    ],
    'enum:RefundReasonCode.BillingError' => [
        'feature' => [
            'tests/Feature/Refund/RefundReasonCodesTest.php::refunds under every reason code and records it on the credit note',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminRefundMatrixTest.php::test_refund_from_the_invoice_tab',
        ],
    ],
    'enum:RefundReasonCode.Goodwill' => [
        'feature' => [
            'tests/Feature/Refund/RefundReasonCodesTest.php::refunds under every reason code and records it on the credit note',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminRefundMatrixTest.php::test_refund_from_the_invoice_tab',
        ],
    ],
    'enum:RefundReasonCode.Chargeback' => [
        'feature' => [
            'tests/Feature/Refund/RefundReasonCodesTest.php::refunds under every reason code and records it on the credit note',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminRefundMatrixTest.php::test_refund_from_the_invoice_tab',
        ],
    ],
    'enum:RefundReasonCode.Cancellation' => [
        'feature' => [
            'tests/Feature/Refund/RefundReasonCodesTest.php::refunds under every reason code and records it on the credit note',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminRefundMatrixTest.php::test_refund_from_the_invoice_tab',
        ],
    ],
    'enum:RefundReasonCode.PlanDowngrade' => [
        'feature' => [
            'tests/Feature/Refund/RefundReasonCodesTest.php::refunds under every reason code and records it on the credit note',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminRefundMatrixTest.php::test_refund_from_the_invoice_tab',
        ],
    ],
    'enum:RefundReasonCode.Other' => [
        'feature' => [
            'tests/Feature/Refund/RefundReasonCodesTest.php::refunds under every reason code and records it on the credit note',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminRefundMatrixTest.php::test_refund_from_the_invoice_tab',
            'tests/Browser/Matrix/AdminRefundMatrixTest.php::test_reason_other_demands_a_text_in_the_operators_language',
        ],
    ],
    'config:billable_model' => [
        'feature' => [
            'tests/Feature/Config/OperationsConfigKeysTest.php::billable_model — is the model the nightly pass walks',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_billables_list_searches_filters_by_every_status_and_sorts_by_every_column',
        ],
    ],
    'config:logo_url' => [
        'feature' => [
            'tests/Feature/Config/OperationsConfigKeysTest.php::logo_url — replaces the title mark',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminBrandingMatrixTest.php::test_logo_favicon_and_company_name_brand_both_layouts',
        ],
    ],
    'config:favicon_url' => [
        'feature' => [
            'tests/Feature/Config/OperationsConfigKeysTest.php::favicon_url — is what the page links as its icon',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminBrandingMatrixTest.php::test_logo_favicon_and_company_name_brand_both_layouts',
        ],
    ],
    'config:primary_color' => [
        'feature' => [
            'tests/Feature/Config/OperationsConfigKeysTest.php::primary_color — is the accent the layout is wrapped in',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminBrandingMatrixTest.php::test_primary_color_changes_the_accent',
        ],
    ],
    'config:company_name' => [
        'feature' => [
            'tests/Feature/Config/OperationsConfigKeysTest.php::company_name — names the product in the page title',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminBrandingMatrixTest.php::test_logo_favicon_and_company_name_brand_both_layouts',
        ],
    ],
    'config:dashboard_url' => [
        'feature' => [
            'tests/Feature/Portal/AdminBackLinkTest.php::falls back to the portal dashboard URL when no admin URL is configured',
            'tests/Feature/Portal/AdminBackLinkTest.php::expands a named route',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminBrandingMatrixTest.php::test_back_links_lead_where_they_are_configured_to',
        ],
    ],
    'config:admin_back_url' => [
        'feature' => [
            'tests/Feature/Portal/AdminBackLinkTest.php::prefers the admin URL over the portal one when both are set',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminBrandingMatrixTest.php::test_back_links_lead_where_they_are_configured_to',
        ],
    ],
    'config:header_components' => [
        'feature' => [
            'tests/Feature/Portal/HeaderComponentsTest.php::renders configured header components in the admin layout',
            'tests/Feature/Portal/HeaderComponentsTest.php::renders configured header components in the portal layout',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminBrandingMatrixTest.php::test_header_components_are_rendered_in_admin_and_portal',
        ],
    ],
    'config:admin_locale' => [
        'feature' => [
            'tests/Feature/Admin/AdminLocaleTest.php::pins the locale when admin_locale is configured',
            'tests/Feature/Admin/AdminLocaleTest.php::leaves the application locale untouched by default',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminBrandingMatrixTest.php::test_admin_locale_pins_the_panel_language_and_leaves_the_portal_alone',
        ],
    ],
    'config:routes.auto_load_admin' => [
        'feature' => [
            'tests/Feature/Middleware/AdminRouteAutoLoadTest.php::does not auto-load the admin routes when the app mounts them itself',
            'tests/Feature/Middleware/AdminRouteAutoLoadTest.php::auto-loads the admin routes by default',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminBrandingMatrixTest.php::test_admin_routes_can_be_left_to_the_app',
        ],
    ],
    'config:invoices.date_format' => [
        'feature' => [
            'tests/Feature/Invoice/EInvoiceTest.php::takes the PDF date format from BILLING_INVOICE_DATE_FORMAT when set',
            'tests/Feature/Invoice/EInvoiceTest.php::prints the service period, German dates, the payment method in words and labelled tax numbers',
        ],
        'no_browser' => 'The date format inside the generated PDF; a browser downloads the file but cannot read inside it.',
    ],
    'config:invoices.disk' => [
        'feature' => [
            'tests/Feature/Config/OperationsConfigKeysTest.php::invoices.disk and invoices.path — decide where an invoice PDF is written',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminRefundMatrixTest.php::test_pdf_download_and_regeneration',
        ],
    ],
    'config:invoices.e_invoice' => [
        'feature' => [
            'tests/Feature/Invoice/EInvoiceTest.php::embeds a schema-valid EN 16931 invoice into the stored PDF',
            'tests/Feature/Invoice/EInvoiceTest.php::stores a plain PDF when e-invoicing is disabled',
            'tests/Feature/Invoice/EInvoiceTest.php::is on by default and switched off with BILLING_E_INVOICE_ENABLED=false',
            'tests/Feature/Invoice/EInvoiceTest.php::produces a schema-valid XRechnung with a buyer reference and seller contact',
            'tests/Feature/Invoice/EInvoiceTest.php::states a domestic zero-rated invoice as exempt, with the configured reason',
            'tests/Feature/Invoice/EInvoiceTest.php::renders the e-invoice PDF in an embedded font',
            'tests/Feature/Invoice/EInvoiceTest.php::takes the reverse-charge statement from BILLING_REVERSE_CHARGE_TEXT when set',
            'tests/Feature/Invoice/EInvoiceTest.php::words the reverse-charge statement as Austrian law does for an Austrian seller',
        ],
        'no_browser' => 'The XML attached inside the generated PDF; a browser downloads the file but cannot read its attachments.',
    ],
    'config:invoices.locale' => [
        'feature' => [
            'tests/Feature/Invoice/InvoicePdfLocaleTest.php::honours an explicitly configured invoice locale',
            'tests/Feature/Invoice/InvoicePdfLocaleTest.php::renders in the pinned locale rather than the active request locale',
        ],
        'no_browser' => 'The language of the generated PDF document; a browser downloads the file but cannot read inside it.',
    ],
    'config:invoices.path' => [
        'feature' => [
            'tests/Feature/Config/OperationsConfigKeysTest.php::invoices.disk and invoices.path — decide where an invoice PDF is written',
        ],
        'no_browser' => 'The directory a PDF is stored under on the disk; the download streams the file and never shows its path.',
    ],
    'config:invoices.logo' => [
        'feature' => [
            'tests/Feature/Config/OperationsConfigKeysTest.php::invoices.logo — puts the configured logo on the document',
        ],
        'no_browser' => 'An image inside the generated PDF; a browser downloads the file but cannot look inside it.',
    ],
    'config:invoices.seller' => [
        'feature' => [
            'tests/Feature/Config/OperationsConfigKeysTest.php::invoices.seller — is the seller printed on the document',
        ],
        'browser' => [
            'tests/Browser/Matrix/InvoicesPortalMatrixTest.php::test_invoice_list_totals_credit_note_and_every_kind_of_line_on_the_pdf',
        ],
    ],
    'config:invoices.serial_number' => [
        'feature' => [
            'tests/Feature/Config/OperationsConfigKeysTest.php::invoices.serial_number — format and prefixes shape the number an invoice gets',
            'tests/Feature/Invoice/InvoiceSerialRangeTest.php::honours a custom format, including one with no separator',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminRefundMatrixTest.php::test_refund_from_the_invoice_tab',
        ],
    ],
    'config:invoices.export_max' => [
        'feature' => [
            'tests/Feature/Admin/AdminInvoiceListTest.php::refuses an empty export and one above invoices.export_max',
        ],
        'no_browser' => 'A cap the browser suite never reaches with its handful of invoices; the disabled button is decided by the count the feature test asserts, and the 422 behind it by the same test.',
    ],
    'config:invoices.temporary_url_expiry' => [
        'feature' => [
            'tests/Feature/Config/OperationsConfigKeysTest.php::invoices.temporary_url_expiry — is how long a signed download link of a remote disk lives',
            'tests/Feature/Config/RemoteDiskSignedUrlTest.php::signs the invoice download link for exactly invoices.temporary_url_expiry minutes',
            'tests/Feature/Config/RemoteDiskLiveS3Test.php::serves the invoice through a signed link inside invoices.temporary_url_expiry',
        ],
        'no_browser' => 'Only applies to a remote (S3) disk, which the browser suite does not have: its disk is local and streams the file. The signature itself — X-Amz-Expires = the configured minutes — is checked against a real S3 adapter in RemoteDiskSignedUrlTest, and against a real S3 server (200 inside the window, 403 after it) in RemoteDiskLiveS3Test (`composer test:s3`).',
    ],
    'config:oss.disk' => [
        'feature' => [
            'tests/Feature/Config/OperationsConfigKeysTest.php::oss.disk and oss.path — decide where an export is written',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_oss_export_is_generated_downloaded_and_every_status_is_shown',
        ],
    ],
    'config:oss.path' => [
        'feature' => [
            'tests/Feature/Config/OperationsConfigKeysTest.php::oss.disk and oss.path — decide where an export is written',
        ],
        'no_browser' => 'The directory an export is stored under on the disk; the download streams the file and never shows its path.',
    ],
    'config:oss.temporary_url_expiry' => [
        'feature' => [
            'tests/Feature/Config/OperationsConfigKeysTest.php::oss.temporary_url_expiry — is how long a signed export link of a remote disk lives',
            'tests/Feature/Config/RemoteDiskSignedUrlTest.php::signs the OSS export link for exactly oss.temporary_url_expiry minutes',
            'tests/Feature/Config/RemoteDiskLiveS3Test.php::serves the OSS export through a signed link as an attachment',
        ],
        'no_browser' => 'Only applies to a remote (S3) disk, which the browser suite does not have: its disk is local and streams the file. The signature itself — X-Amz-Expires = the configured minutes — is checked against a real S3 adapter in RemoteDiskSignedUrlTest, and against a real S3 server (200 inside the window, 403 after it) in RemoteDiskLiveS3Test (`composer test:s3`).',
    ],
    'config:allow_overage_default' => [
        'feature' => [
            'tests/Feature/Config/OperationsConfigKeysTest.php::allow_overage_default — decides for a billable that has not chosen for itself',
        ],
        'no_browser' => 'Decides whether a usage booking past the quota is accepted by the API; no screen offers or shows the switch.',
    ],
    'config:billable_key_type' => [
        'feature' => [
            'tests/Feature/Migrations/WalletMorphKeyTypeTest.php::retypes the wallet holder morph for uuid billable keys',
            'tests/Feature/Audit/AuditMigrationKeyTypeTest.php::sizes the subject column so uuid billable keys fit',
        ],
        'no_browser' => 'A migration-time column type; it has to be set before the tables exist and cannot be changed for one browser scenario.',
    ],
    'config:user_key_type' => [
        'feature' => [
            'tests/Feature/Audit/AuditMigrationKeyTypeTest.php::sizes the causer column from user_key_type independently of the billable',
            'tests/Feature/Migrations/ResolvedByUserIdGuardTest.php::keeps resolver references when the column type already matches user_key_type',
        ],
        'no_browser' => 'A migration-time column type; it has to be set before the tables exist and cannot be changed for one browser scenario.',
    ],
    'config:audit.enabled' => [
        'feature' => [
            'tests/Feature/Audit/BillingAuditTest.php::writes nothing when auditing is disabled',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_audit_tab_filters_by_every_category',
        ],
    ],
    'config:audit.log_name' => [
        'feature' => [
            'tests/Feature/Config/OperationsConfigKeysTest.php::audit.log_name — is the log the trail is written to and read from',
        ],
        'no_browser' => 'The internal name of the activity log the rows are stored under; the audit tab shows the rows, never the name.',
    ],
    'config:audit.categories' => [
        'feature' => [
            'tests/Feature/Audit/BillingAuditTest.php::writes nothing for a category that is switched off',
            'tests/Feature/Audit/AuditCategoryCasesTest.php::files an event under its category, filters by it and can switch it off',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_audit_tab_filters_by_every_category',
        ],
    ],
    'config:audit.retention_days' => [
        'feature' => [
            'tests/Feature/Audit/BillingAuditTest.php::prunes only its own log rows past the retention window',
        ],
        'no_browser' => 'How long audit rows are kept before a monthly job deletes them; nothing on a page changes with it.',
    ],
    'config:overage_job_time' => [
        'feature' => [
            'tests/Feature/Config/OperationsConfigKeysTest.php::overage_job_time — is when the nightly overage pass is scheduled',
        ],
        'no_browser' => 'A schedule time or cron expression: it decides when a background job runs and has nothing a person could see on a page.',
    ],
    'config:cleanup.enabled' => [
        'feature' => [
            'tests/Feature/Config/OperationsConfigKeysTest.php::cleanup.cron_expression and cleanup.enabled — schedule the orphan cleanup, or do not',
            'tests/Feature/Jobs/CleanupOrphanedBillablesJobTest.php::is a no-op when cleanup is disabled',
        ],
        'no_browser' => 'Switches a background job that deletes abandoned sign-ups; it has no page and no control.',
    ],
    'config:cleanup.threshold_minutes' => [
        'feature' => [
            'tests/Feature/Jobs/CleanupOrphanedBillablesJobTest.php::respects the threshold_minutes config',
        ],
        'no_browser' => 'The age at which a background job deletes an abandoned sign-up; it has no page and no control.',
    ],
    'config:cleanup.cron_expression' => [
        'feature' => [
            'tests/Feature/Config/OperationsConfigKeysTest.php::cleanup.cron_expression and cleanup.enabled — schedule the orphan cleanup, or do not',
            'tests/Feature/Commands/CheckConfigValidationGapsTest.php::rejects an invalid cleanup cron expression',
        ],
        'no_browser' => 'A schedule time or cron expression: it decides when a background job runs and has nothing a person could see on a page.',
    ],
    'config:past_due_max_days' => [
        'feature' => [
            'tests/Feature/Jobs/PrepareUsageOveragePastDueAutoCancelTest.php::auto-cancels past_due billables older than past_due_max_days',
            'tests/Feature/Jobs/PrepareUsageOveragePastDueAutoCancelTest.php::skips auto-cancel when past_due_max_days is 0 (disabled)',
        ],
        'no_browser' => 'The number of days before the nightly job cancels a past-due subscription; the wait cannot be shown in a browser scenario, only its result, which the lifecycle area covers.',
    ],
    'config:usage_threshold_percent' => [
        'feature' => [
            'tests/Feature/Config/OperationsConfigKeysTest.php::usage_threshold_percent — is where the usage meter turns to its warning colour',
            'tests/Feature/Wallet/UsageThresholdDebitTest.php::notifies once when usage crosses the threshold',
        ],
        'browser' => [
            'tests/Browser/Matrix/UsageWalletMatrixTest.php::test_usage_threshold_decides_when_the_meter_warns',
        ],
    ],
    'config:usage_rollover_fallback' => [
        'feature' => [
            'tests/Feature/Wallet/UsageRolloverTest.php::reads rollover from fallback config',
        ],
        'no_browser' => 'Decides what a period reset does to a usage type that declares no rollover of its own; it acts in the nightly job and the renewal webhook, not on a page.',
    ],
    'config:admin_kpi_cache_ttl' => [
        'feature' => [
            'tests/Feature/Config/OperationsConfigKeysTest.php::admin_kpi_cache_ttl — is how long a dashboard figure is served from the cache',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_dashboard_tiles_show_the_figures_the_data_gives',
        ],
    ],
    'config:queue.connection' => [
        'feature' => [
            'tests/Feature/Jobs/QueueConfigTest.php::routes all package jobs onto the configured queue',
            'tests/Feature/Jobs/QueueConfigTest.php::falls back to framework default when queue config is null',
            'tests/Feature/Commands/CheckConfigQueueTest.php::warns when only the billing connection is sync, and names it',
            'tests/Feature/Jobs/RetryJobsOnSyncQueueTest.php::keeps a rejected refund line for the hourly sweep',
        ],
        'no_browser' => 'The queue connection jobs are pushed to; a pure infrastructure setting with no surface.',
    ],
    'config:queue.name' => [
        'feature' => [
            'tests/Feature/Jobs/QueueConfigTest.php::routes all package jobs onto the configured queue',
            'tests/Feature/Jobs/QueueConfigTest.php::falls back to framework default when queue config is empty string',
        ],
        'no_browser' => 'The queue name jobs are pushed to; a pure infrastructure setting with no surface.',
    ],
    'action:admin.billables.audit-tab@billable' => [
        'feature' => [
            'tests/Feature/Admin/OperationsAdminActionsTest.php::tabs: billable() resolves the billable the tab was mounted for',
            'tests/Feature/Admin/AdminTargetPropertyLockingTest.php::locks the audit tab billableId so a scoped operator cannot read another trail',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_audit_tab_filters_by_every_category',
        ],
    ],
    'action:admin.billables.index@sort' => [
        'feature' => [
            'tests/Feature/Admin/OperationsAdminActionsTest.php::sort: every list toggles direction on its own columns',
            'tests/Feature/Admin/BillableSearchSortTest.php::sorts by the default name and email columns',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_billables_list_searches_filters_by_every_status_and_sorts_by_every_column',
        ],
    ],
    'action:admin.billables.invoices-tab@billable' => [
        'feature' => [
            'tests/Feature/Admin/OperationsAdminActionsTest.php::tabs: billable() resolves the billable the tab was mounted for',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminRefundMatrixTest.php::test_refund_from_the_invoice_tab',
        ],
    ],
    'action:admin.billables.invoices-tab@openRefund' => [
        'feature' => [
            'tests/Feature/Admin/OperationsAdminActionsTest.php::invoices-tab: openRefund targets one invoice',
            'tests/Feature/Admin/InvoicesTabRefundAmountTest.php::clears the pending refund target when the modal is closed from the client',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminRefundMatrixTest.php::test_refund_from_the_invoice_tab',
        ],
    ],
    'action:admin.billables.invoices-tab@refund' => [
        'feature' => [
            'tests/Feature/Admin/InvoicesTabRefundAmountTest.php::accepts the comma-decimal example the hint shows',
            'tests/Feature/Admin/InvoicesTabRefundAmountTest.php::refuses an empty amount instead of refunding the whole invoice',
            // `refundLine` (the line select) is a property, not an action: refunds by line.
            'tests/Feature/Refund/RefundByLineTest.php::offers the lines in the refund modal',
            'tests/Feature/Refund/RefundByLineTest.php::refuses a line index the modal never offered',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminRefundMatrixTest.php::test_refund_from_the_invoice_tab',
            'tests/Browser/Matrix/AdminRefundMatrixTest.php::test_a_refund_beyond_what_is_left_is_refused_on_screen',
            'tests/Browser/Matrix/AdminRefundMatrixTest.php::test_a_refund_by_line_is_booked_against_that_line',
            'tests/Browser/Matrix/AdminRefundMatrixTest.php::test_a_line_of_a_settled_plan_switch_invoice_is_refunded',
        ],
    ],
    'action:admin.billables.invoices-tab@refundFull' => [
        'feature' => [
            'tests/Feature/Admin/InvoicesTabRefundAmountTest.php::refunds the full remaining net only through the dedicated action',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminRefundMatrixTest.php::test_refund_from_the_invoice_tab',
        ],
    ],
    'action:admin.billables.invoices-tab@regeneratePdf' => [
        'feature' => [
            'tests/Feature/Admin/OperationsAdminActionsTest.php::invoices-tab: openRefund targets one invoice, and regeneratePdf writes its document again',
            'tests/Feature/Invoice/RegeneratePdfTest.php::regenerates the PDF and deletes the previous file',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminRefundMatrixTest.php::test_pdf_download_and_regeneration',
        ],
    ],
    'action:admin.billables.show@billableChanged' => [
        'feature' => [
            'tests/Feature/Admin/BillableHeaderRefreshTest.php::the page listens for the announcement and re-reads the billable for its header',
        ],
        'browser' => [
            'tests/Browser/AdminHeaderRefreshTest.php::test_resuming_a_cancelled_subscription_turns_the_header_badge_active_without_a_reload',
            'tests/Browser/AdminHeaderRefreshTest.php::test_cancelling_turns_the_header_badge_cancelled_without_a_reload',
        ],
    ],
    'action:admin.billables.show@mandate' => [
        'feature' => [
            'tests/Feature/Admin/OperationsAdminActionsTest.php::show: planName() answers the catalog name and mandate() what the inspector reports',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_billable_detail_names_the_plan_and_shows_the_mandate_mollie_holds',
        ],
    ],
    'action:admin.billables.show@planName' => [
        'feature' => [
            'tests/Feature/Admin/OperationsAdminActionsTest.php::show: planName() answers the catalog name and mandate() what the inspector reports',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_billable_detail_names_the_plan_and_shows_the_mandate_mollie_holds',
        ],
    ],
    'action:admin.billables.wallet-tab@billable' => [
        'feature' => [
            'tests/Feature/Admin/OperationsAdminActionsTest.php::tabs: billable() resolves the billable the tab was mounted for',
            'tests/Feature/Admin/BulkActionsTest.php::locks billableId on the wallet tab',
        ],
        'browser' => [
            'tests/Browser/Matrix/UsageWalletMatrixTest.php::test_wallet_credit_shows_in_the_portal_and_lowers_the_next_overage_charge',
        ],
    ],
    'action:admin.billables.wallet-tab@credit' => [
        'feature' => [
            'tests/Feature/Admin/BulkActionsTest.php::registers per-billable admin wallet credits as purchased balance',
            'tests/Feature/Admin/AdminActionTargetAndReasonTest.php::refuses a per-billable credit for a usage type that is not in the catalog',
        ],
        'browser' => [
            'tests/Browser/Matrix/UsageWalletMatrixTest.php::test_wallet_credit_shows_in_the_portal_and_lowers_the_next_overage_charge',
        ],
    ],
    'action:admin.bulk.index@creditWallets' => [
        'feature' => [
            'tests/Feature/Admin/BulkActionsTest.php::registers bulk wallet credits as purchased balance so they survive a renewal reset',
            'tests/Feature/Admin/AdminActionTargetAndReasonTest.php::refuses a bulk credit without a reason',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_bulk_credit_reaches_every_billable_on_the_plan_and_only_those',
        ],
    ],
    'action:admin.bulk.index@extendTrials' => [
        'feature' => [
            'tests/Feature/Admin/BulkActionsTest.php::extends only actual trials, leaving active paying customers alone',
            'tests/Feature/Admin/BulkActionsTest.php::reports that nothing matched instead of flashing success',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_bulk_trial_extension_moves_every_trial_on_the_plan_and_no_paying_customer',
        ],
    ],
    'action:admin.mismatches.index@cancelResolve' => [
        'feature' => [
            'tests/Feature/Admin/OperationsAdminActionsTest.php::mismatches: openResolve arms the modal for a pending row only, cancelResolve disarms it',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_mismatch_is_resolved_with_a_justification_and_the_invoice_reissued',
        ],
    ],
    'action:admin.mismatches.index@confirmResolve' => [
        'feature' => [
            'tests/Feature/Admin/MismatchResolutionJustificationTest.php::records the operator justification with the resolution',
            'tests/Feature/Admin/MismatchResolutionJustificationTest.php::refuses to resolve a mismatch without a justification',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_mismatch_is_resolved_with_a_justification_and_the_invoice_reissued',
        ],
    ],
    'action:admin.mismatches.index@openResolve' => [
        'feature' => [
            'tests/Feature/Admin/OperationsAdminActionsTest.php::mismatches: openResolve arms the modal for a pending row only, cancelResolve disarms it',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_mismatch_is_resolved_with_a_justification_and_the_invoice_reissued',
        ],
    ],
    'action:admin.mismatches.index@sort' => [
        'feature' => [
            'tests/Feature/Admin/OperationsAdminActionsTest.php::sort: every list toggles direction on its own columns',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_mismatch_is_resolved_with_a_justification_and_the_invoice_reissued',
        ],
    ],
    'action:admin.oss.index@generate' => [
        'feature' => [
            'tests/Feature/Admin/OperationsAdminActionsTest.php::oss: generate queues one export per year and refuses nonsense and a second run',
            'tests/Feature/Admin/OssExportStuckRunTest.php::lets a new export start once an abandoned run is past the stale cutoff',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_oss_export_is_generated_downloaded_and_every_status_is_shown',
        ],
    ],
    'action:admin.past-due.index@retry' => [
        'feature' => [
            'tests/Feature/Admin/AdminActionTargetAndReasonTest.php::dispatches a dunning retry for a past-due billable',
            'tests/Feature/Admin/AdminActionTargetAndReasonTest.php::refuses a dunning retry for a billable the past-due list never showed',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_past_due_list_sorts_and_retries_the_open_overage_charge',
        ],
    ],
    'action:admin.past-due.index@sort' => [
        'feature' => [
            'tests/Feature/Admin/OperationsAdminActionsTest.php::sort: every list toggles direction on its own columns',
            'tests/Feature/Admin/AdminMoneyAndSortingTest.php::ignores sort columns outside the allow-list on the past-due list',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_past_due_list_sorts_and_retries_the_open_overage_charge',
        ],
    ],
    'action:admin.invoices.index@applyPreset' => [
        'feature' => ['tests/Feature/Admin/AdminInvoiceListTest.php::sets the range from the quick filters, and clears it when the active one is clicked again'],
        'browser' => ['tests/Browser/Matrix/AdminListsMatrixTest.php::test_invoice_list_filters_by_range_exports_a_zip_and_leads_to_the_billable'],
    ],
    'action:admin.invoices.index@clearFilters' => [
        'feature' => ['tests/Feature/Admin/AdminInvoiceListTest.php::filters by type, status and search, and clears every filter at once'],
        'browser' => ['tests/Browser/Matrix/AdminListsMatrixTest.php::test_invoice_list_filters_by_range_exports_a_zip_and_leads_to_the_billable'],
    ],
    'action:admin.invoices.index@openDetail' => [
        'feature' => ['tests/Feature/Admin/AdminInvoiceListTest.php::opens the detail of an invoice with its PDF links and the way to its billable'],
        'browser' => ['tests/Browser/Matrix/AdminListsMatrixTest.php::test_invoice_list_filters_by_range_exports_a_zip_and_leads_to_the_billable'],
    ],
    'action:admin.invoices.index@sort' => [
        'feature' => ['tests/Feature/Admin/AdminInvoiceListTest.php::sorts the invoice list by an allowed column and ignores any other'],
        'browser' => ['tests/Browser/Matrix/AdminListsMatrixTest.php::test_invoice_list_filters_by_range_exports_a_zip_and_leads_to_the_billable'],
    ],
    'action:admin.refunds.index@openDetail' => [
        'feature' => [
            'tests/Feature/Admin/OperationsAdminActionsTest.php::refunds: the list really orders by the chosen column',
            'tests/Feature/Admin/AdminActionTargetAndReasonTest.php::opens the credit-note detail for a refund invoice',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_refunds_list_sorts_by_every_column_and_opens_the_credit_note',
            'tests/Browser/Matrix/AdminRefundMatrixTest.php::test_refund_from_the_invoice_tab',
        ],
    ],
    'action:admin.refunds.index@sort' => [
        'feature' => [
            'tests/Feature/Admin/OperationsAdminActionsTest.php::refunds: the list really orders by the chosen column',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_refunds_list_sorts_by_every_column_and_opens_the_credit_note',
        ],
    ],
    'action:admin.scheduled-changes.index@applyNow' => [
        'feature' => [
            'tests/Feature/Admin/OperationsAdminActionsTest.php::scheduled-changes: applyNow applies the change and cancel drops it',
            'tests/Feature/Admin/ScheduledChangesOutcomeTest.php::reports a no-op instead of flashing success when nothing is scheduled',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_scheduled_changes_list_sorts_cancels_and_applies',
        ],
    ],
    'action:admin.scheduled-changes.index@cancel' => [
        'feature' => [
            'tests/Feature/Admin/OperationsAdminActionsTest.php::scheduled-changes: applyNow applies the change and cancel drops it',
            'tests/Feature/Admin/ScheduledChangesOutcomeTest.php::confirms a real cancel',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_scheduled_changes_list_sorts_cancels_and_applies',
        ],
    ],
    'action:admin.scheduled-changes.index@sort' => [
        'feature' => [
            'tests/Feature/Admin/OperationsAdminActionsTest.php::sort: every list toggles direction on its own columns',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_scheduled_changes_list_sorts_cancels_and_applies',
        ],
    ],
    'action:components.usage-meter@data' => [
        'feature' => [
            'tests/Feature/Admin/OperationsAdminActionsTest.php::usage-meter: data() splits plan and purchased units, and counts overage',
            'tests/Feature/Config/OperationsConfigKeysTest.php::usage_threshold_percent — is where the usage meter turns to its warning colour',
        ],
        'browser' => [
            'tests/Browser/Matrix/UsageWalletMatrixTest.php::test_usage_threshold_decides_when_the_meter_warns',
            'tests/Browser/Matrix/UsageWalletMatrixTest.php::test_wallet_credit_shows_in_the_portal_and_lowers_the_next_overage_charge',
        ],
    ],
    'screen:billing.admin.billables.index' => [
        'feature' => [
            'tests/Feature/Admin/BillableSearchSortTest.php::searches the default name and email columns',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_billables_list_searches_filters_by_every_status_and_sorts_by_every_column',
        ],
    ],
    'screen:billing.admin.billables.show' => [
        'feature' => [
            'tests/Feature/Admin/OperationsAdminActionsTest.php::show: planName() answers the catalog name',
            'tests/Feature/Admin/AdminTargetPropertyLockingTest.php::locks the routed billable on the billables show page',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_billable_detail_names_the_plan_and_shows_the_mandate_mollie_holds',
        ],
    ],
    'screen:billing.admin.bulk.index' => [
        'feature' => [
            'tests/Feature/Admin/BulkActionsTest.php::extends only actual trials, leaving active paying customers alone',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_bulk_credit_reaches_every_billable_on_the_plan_and_only_those',
        ],
    ],
    'screen:billing.admin.dashboard' => [
        'feature' => [
            'tests/Feature/Admin/AdminMoneyAndSortingTest.php::renders MRR and ARR through the shared formatter, honouring locale and currency',
            'tests/Feature/Admin/AdminKpiAccuracyTest.php::counts a monthly invoice in full',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_dashboard_tiles_show_the_figures_the_data_gives',
        ],
    ],
    'screen:billing.admin.invoice.download' => [
        'feature' => [
            'tests/Feature/Invoice/InvoiceServiceTest.php::invoice has a download URL when PDF exists',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminRefundMatrixTest.php::test_pdf_download_and_regeneration',
        ],
    ],
    'screen:billing.admin.invoice.view' => [
        'feature' => ['tests/Feature/Admin/AdminInvoiceListTest.php::shows a PDF in the browser on the view route and sends it as an attachment on download'],
        'browser' => ['tests/Browser/Matrix/AdminListsMatrixTest.php::test_invoice_list_filters_by_range_exports_a_zip_and_leads_to_the_billable'],
    ],
    'screen:billing.admin.invoices.export' => [
        'feature' => [
            'tests/Feature/Admin/AdminInvoiceListTest.php::exports exactly the filtered invoices as a ZIP of their PDFs',
            'tests/Feature/Admin/AdminInvoiceListTest.php::regenerates a missing PDF for the export and names the one it cannot',
        ],
        'browser' => ['tests/Browser/Matrix/AdminListsMatrixTest.php::test_invoice_list_filters_by_range_exports_a_zip_and_leads_to_the_billable'],
    ],
    'screen:billing.admin.invoices.index' => [
        'feature' => [
            'tests/Feature/Admin/AdminInvoiceListTest.php::lists every invoice across billables, newest first',
            'tests/Feature/Admin/AdminInvoiceListTest.php::filters by an inclusive date range, in UTC while no admin timezone is set',
        ],
        'browser' => ['tests/Browser/Matrix/AdminListsMatrixTest.php::test_invoice_list_filters_by_range_exports_a_zip_and_leads_to_the_billable', 'tests/Browser/AdminPanelTest.php::test_walks_the_admin_screens'],
    ],
    'screen:billing.admin.mismatches.index' => [
        'feature' => [
            'tests/Feature/Admin/MismatchResolutionJustificationTest.php::renders a justification field bound to the resolve modal',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_mismatch_is_resolved_with_a_justification_and_the_invoice_reissued',
        ],
    ],
    'screen:billing.admin.oss.download' => [
        'feature' => [
            'tests/Feature/Config/OperationsConfigKeysTest.php::oss.temporary_url_expiry — is how long a signed export link of a remote disk lives',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_oss_export_is_generated_downloaded_and_every_status_is_shown',
        ],
    ],
    'screen:billing.admin.oss.index' => [
        'feature' => [
            'tests/Feature/Admin/OperationsAdminActionsTest.php::oss: generate queues one export per year',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_oss_export_is_generated_downloaded_and_every_status_is_shown',
        ],
    ],
    'screen:billing.admin.past_due.index' => [
        'feature' => [
            'tests/Feature/Admin/AdminActionTargetAndReasonTest.php::dispatches a dunning retry for a past-due billable',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_past_due_list_sorts_and_retries_the_open_overage_charge',
        ],
    ],
    'screen:billing.admin.refunds.index' => [
        'feature' => [
            'tests/Feature/Admin/AdminMoneyAndSortingTest.php::renders refund-list and refund-detail amounts in the credit notes own currency',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_refunds_list_sorts_by_every_column_and_opens_the_credit_note',
        ],
    ],
    'screen:billing.admin.scheduled_changes.index' => [
        'feature' => [
            'tests/Feature/Admin/ScheduledChangesOutcomeTest.php::confirms a real cancel',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_scheduled_changes_list_sorts_cancels_and_applies',
        ],
    ],
    'screen:billing.invoice.download' => [
        'feature' => [
            'tests/Feature/Config/OperationsConfigKeysTest.php::invoices.temporary_url_expiry — is how long a signed download link of a remote disk lives',
        ],
        'browser' => [
            'tests/Browser/Matrix/InvoicesPortalMatrixTest.php::test_invoice_list_totals_credit_note_and_every_kind_of_line_on_the_pdf',
            'tests/Browser/Matrix/InvoicesPortalMatrixTest.php::test_a_customer_cannot_download_another_customers_invoice',
        ],
    ],
    'screen:billing.invoices' => [
        'feature' => [
            'tests/Feature/Portal/InvoiceTotalsCurrencyScopeTest.php::scopes the invoice summary totals to the configured currency',
            'tests/Feature/Portal/PortalMoneyFormatTest.php::renders each invoice row in its own currency',
        ],
        'browser' => [
            'tests/Browser/Matrix/InvoicesPortalMatrixTest.php::test_invoice_list_totals_credit_note_and_every_kind_of_line_on_the_pdf',
            'tests/Browser/Matrix/InvoicesPortalMatrixTest.php::test_an_empty_invoice_page_says_so',
        ],
    ],
    'screen:billing.usage' => [
        'feature' => [
            'tests/Feature/Admin/OperationsAdminActionsTest.php::usage-history: counts consumption only, follows the type and date filters and shows an open overage charge',
        ],
        'browser' => [
            'tests/Browser/Matrix/UsageWalletMatrixTest.php::test_usage_history_in_every_filter',
            'tests/Browser/Matrix/UsageWalletMatrixTest.php::test_a_failed_overage_charge_is_shown_as_open_until_a_renewal_collects_it',
        ],
    ],
    'command:CheckConfigCommand' => [
        'feature' => [
            'tests/Feature/Commands/CheckConfigCommandTest.php::passes on a valid configuration',
            'tests/Feature/Commands/CheckConfigRuntimeFlagsTest.php::warns when the wallet lock cannot serialise across processes',
        ],
        'no_browser' => 'An artisan command: it is run from a shell or the scheduler and has no page.',
    ],
    'command:CleanupOrphanedBillablesCommand' => [
        'feature' => [
            'tests/Feature/Commands/CommandGuardsTest.php::deletes nothing on a dry run',
            'tests/Feature/Commands/CommandGuardsTest.php::deletes with --force and reports the counts',
            'tests/Feature/Commands/CommandGuardsTest.php::asks before deleting and keeps everything when declined',
        ],
        'no_browser' => 'An artisan command: it is run from a shell or the scheduler and has no page.',
    ],
    'command:OssExportCommand' => [
        'feature' => [
            'tests/Feature/Commands/OperationsCommandOptionsTest.php::billing:oss-export {year} — writes the export for that year',
            'tests/Feature/Commands/CommandGuardsTest.php::rejects a year outside the supported range',
        ],
        'no_browser' => 'An artisan command: it is run from a shell or the scheduler and has no page.',
    ],
    'command:PrepareOverageCommand' => [
        'feature' => [
            'tests/Feature/Commands/CommandGuardsTest.php::asks before charging overage for every billable',
            'tests/Feature/Commands/CommandGuardsTest.php::reports which billable model it prepared overage for',
        ],
        'no_browser' => 'An artisan command: it is run from a shell or the scheduler and has no page.',
    ],
    'command:SimulateCommand' => [
        'feature' => [
            'tests/Feature/Commands/OperationsCommandOptionsTest.php::billing:simulate overage-charge --usage-type --withdraw',
            'tests/Feature/Commands/OperationsCommandOptionsTest.php::billing:simulate renewal --gross',
            'tests/Feature/Commands/OperationsCommandOptionsTest.php::billing:simulate apply-scheduled-change --plan',
            'tests/Feature/Commands/SimulateCommandTest.php::runs trial-expired flow non-interactively and flips billable to PastDue',
            'tests/Feature/Commands/SimulateCommandTest.php::runs trial-ending-soon flow and sends the ending-soon notification',
            'tests/Feature/Commands/SimulateCommandTest.php::runs cancelled-to-expired flow',
            'tests/Feature/Commands/SimulateCommandTest.php::runs past-due-auto-cancel flow',
        ],
        'no_browser' => 'An artisan command: it is run from a shell or the scheduler and has no page.',
    ],
    'command:SyncPurchasedBalanceCommand' => [
        'feature' => [
            'tests/Feature/Commands/CommandGuardsTest.php::writes nothing on a dry run',
            'tests/Feature/Commands/CommandGuardsTest.php::asks before overwriting purchased balances',
            'tests/Feature/Commands/SyncPurchasedBalanceAfterPlanChangeTest.php::does not resurrect purchased credits that were consumed before a plan change',
        ],
        'no_browser' => 'An artisan command: it is run from a shell or the scheduler and has no page.',
    ],
    'command:WebhookReplayCommand' => [
        'feature' => [
            'tests/Feature/Commands/WebhookReplayCommandTest.php::deletes the existing processed-webhook reservation and re-invokes the controller',
            'tests/Feature/Commands/WebhookReplayCommandTest.php::with --force-reset deletes the existing invoice for the payment',
        ],
        'no_browser' => 'An artisan command: it is run from a shell or the scheduler and has no page.',
    ],
    'job:ApplyScheduledChangesJob' => [
        'feature' => [
            'tests/Feature/Commands/OperationsCommandOptionsTest.php::billing:simulate apply-scheduled-change --plan',
            'tests/Feature/Jobs/JobQueueBoundsTest.php::pins ApplyScheduledChangesJob to a single, non-duplicable attempt',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_scheduled_changes_list_sorts_cancels_and_applies',
        ],
    ],
    'job:CleanupOrphanedBillablesJob' => [
        'feature' => [
            'tests/Feature/Jobs/CleanupOrphanedBillablesJobTest.php::deletes orphaned billables that exceed the age threshold',
            'tests/Feature/Jobs/CleanupOrphanedBillablesJobTest.php::only deletes when Mollie reports a terminal failure for a pending payment',
            'tests/Feature/Jobs/CleanupOrphanedBillablesJobTest.php::is a no-op when cleanup is disabled',
        ],
        'no_browser' => 'A queued job: it runs in a worker, not in a browser. Where its result is visible, the browser test named here shows it.',
    ],
    'job:CleanupStalePendingCountryCorrectionJob' => [
        'feature' => [
            'tests/Feature/Jobs/CleanupStalePendingCountryCorrectionJobTest.php::skips entries younger than 1 hour',
            'tests/Feature/Jobs/CleanupStalePendingCountryCorrectionJobTest.php::routes paid Mollie status into handleCountryCorrectionPaid',
            'tests/Feature/Jobs/CleanupStalePendingCountryCorrectionJobTest.php::routes failed Mollie status into handleCountryCorrectionFailed',
        ],
        'no_browser' => 'A queued job: it runs in a worker, not in a browser. Where its result is visible, the browser test named here shows it.',
    ],
    'job:CleanupStalePendingOverageChargeJob' => [
        'feature' => [
            'tests/Feature/Jobs/StaleOverageChargeSweepTest.php::books a paid charge whose webhook was lost, by replaying the delivery',
            'tests/Feature/Jobs/StaleOverageChargeSweepTest.php::ends the cycle of a charge Mollie reports as failed and leaves the debt for the renewals',
            'tests/Feature/Jobs/StaleOverageChargeSweepTest.php::cancels a charge still sitting at open past the hard limit before it frees the slot',
            'tests/Feature/Jobs/StaleOverageChargeSweepTest.php::keeps the slot for a stale charge Mollie will not cancel',
            'tests/Feature/Jobs/OverageJobsUncoveredPathsTest.php::does not write off a retry payment because the dunning cycle around it is old',
            'tests/Feature/Wallet/LostOveragePaymentReconciliationTest.php::adopts the payment Mollie turns out to hold',
        ],
        'no_browser' => 'A queued job: it runs in a worker, not in a browser. Where its result is visible, the browser test named here shows it.',
    ],
    'job:CleanupStalePendingProrataChangeJob' => [
        'feature' => [
            'tests/Feature/Jobs/CleanupStalePendingProrataChangeJobTest.php::records an orphan and alerts when the charge was paid but no webhook arrived',
            'tests/Feature/Jobs/CleanupStalePendingProrataChangeJobTest.php::keeps waiting while Mollie still reports the charge as unsettled',
            'tests/Feature/Jobs/RetryJobsTest.php::CleanupStalePendingProrataChangeJob clears pending state when Mollie says failed',
        ],
        'no_browser' => 'A queued job: it runs in a worker, not in a browser. Where its result is visible, the browser test named here shows it.',
    ],
    'job:GenerateOssExportJob' => [
        'feature' => [
            'tests/Feature/Jobs/GenerateOssExportJobTest.php::generates an OSS export file and marks the row as ready',
            'tests/Feature/Jobs/GenerateOssExportJobTest.php::marks the row as failed when the service throws',
            'tests/Feature/Jobs/GenerateOssExportJobTest.php::skips processing when status is not queued',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_oss_export_is_generated_downloaded_and_every_status_is_shown',
        ],
    ],
    'job:PrepareUsageOverageJob' => [
        'feature' => [
            'tests/Feature/Wallet/UsageLongRunCardTest.php::books twelve periods of mixed usage exactly once each',
            'tests/Feature/Wallet/UsageLongRunLocalTest.php::refills a free plan once per period for a year and never charges it',
            'tests/Feature/Wallet/UsageLongRunLocalTest.php::gives an access grant one quota per period it covers and nothing after it ends',
            'tests/Feature/Wallet/UsageLongRunDeferredChargeTest.php::credits each period of a 100 % coupon once, and the first paid period once',
            'tests/Feature/Wallet/UsageLongRunDeferredChargeTest.php::keeps turning the period over when an extension is appended to a deferred charge',
            'tests/Feature/Wallet/UsageLongRunDeferredChargeTest.php::turns a yearly period over under a deferred charge',
            'tests/Feature/Wallet/UsageLongRunLifecycleTest.php::bills a cancelled subscription its last period',
            'tests/Feature/Jobs/PrepareUsageOveragePastDueAutoCancelTest.php::auto-cancels past_due billables older than past_due_max_days',
            'tests/Feature/Jobs/PrepareUsageOverageLifecycleTest.php::retries in pass 2 when the charge never reached Mollie',
            'tests/Feature/Wallet/UsageLongRunDebtRecollectionTest.php::keeps a failed overage debt standing and collects it at a later renewal',
        ],
        'browser' => [
            'tests/Browser/Matrix/UsageWalletMatrixTest.php::test_wallet_credit_shows_in_the_portal_and_lowers_the_next_overage_charge',
        ],
    ],
    'job:ProcessTrialLifecycleJob' => [
        'feature' => [
            'tests/Feature/Jobs/ProcessTrialLifecycleJobTest.php::flips a trial billable to PastDue and notifies once trial_ends_at has passed',
            'tests/Feature/Jobs/ProcessTrialLifecycleJobTest.php::sends TrialEndingSoonNotification for billable WITHOUT mandate whose trial ends tomorrow',
            'tests/Feature/Wallet/UsageLongRunLifecycleTest.php::runs a trial into its first charge and on through three paid periods',
        ],
        'no_browser' => 'A queued job: it runs in a worker, not in a browser. Where its result is visible, the browser test named here shows it.',
    ],
    'job:PruneBillingAuditJob' => [
        'feature' => [
            'tests/Feature/Audit/BillingAuditTest.php::prunes only its own log rows past the retention window',
        ],
        'no_browser' => 'A queued job: it runs in a worker, not in a browser. Where its result is visible, the browser test named here shows it.',
    ],
    'job:PruneProcessedWebhooksJob' => [
        'feature' => [
            'tests/Feature/Jobs/OverageJobsUncoveredPathsTest.php::prunes webhook records older than 180 days and nothing younger',
            'tests/Feature/Jobs/OverageJobsUncoveredPathsTest.php::does not book a renewal a second time when it is re-delivered after its record was pruned',
        ],
        'no_browser' => 'A queued job: it runs in a worker, not in a browser. Where its result is visible, the browser test named here shows it.',
    ],
    'job:RetryRefundLineJob' => [
        'feature' => [
            'tests/Feature/Jobs/RetryRefundLineRetryLoopTest.php::clears the marker when createRefund reports nothing left to refund',
            'tests/Feature/Jobs/RetryRefundLineRetryLoopTest.php::dead-letters and notifies when the retry budget is exhausted',
            'tests/Feature/Jobs/RetryJobsTest.php::RetryRefundLineJob moves to dead_letter after 7 days',
        ],
        'no_browser' => 'A queued job: it runs in a worker, not in a browser. Where its result is visible, the browser test named here shows it.',
    ],
    'job:RetrySubscriptionCancelJob' => [
        'feature' => [
            'tests/Feature/Jobs/RetrySubscriptionCancelJobTest.php::clears the marker once Mollie accepts the cancellation',
            'tests/Feature/Jobs/RetrySubscriptionCancelJobTest.php::treats a 404 from Mollie as already cancelled',
            'tests/Feature/Jobs/RetrySubscriptionCancelJobTest.php::surfaces a permanent failure to admins and drops the marker',
        ],
        'no_browser' => 'A queued job: it runs in a worker, not in a browser. Where its result is visible, the browser test named here shows it.',
    ],
    'job:RetrySubscriptionReplacementJob' => [
        'feature' => [
            'tests/Feature/Billing/ChargedSubscriptionReplacementTest.php::finishes a replacement interrupted between the cancel and the create, from the hourly sweep',
            'tests/Feature/Billing/ChargedSubscriptionReplacementTest.php::adopts the replacement whose create answer was lost instead of creating a second one',
            'tests/Feature/Billing/ChargedSubscriptionReplacementTest.php::never starts a replacement today or earlier, even when the retry comes after the date',
            'tests/Feature/Billing/ChargedSubscriptionReplacementTest.php::tells the admins once when a replacement has not finished after 24 hours, and keeps retrying',
        ],
        'no_browser' => 'A queued job: it runs in a worker, not in a browser. Where its result is visible, the browser test named here shows it.',
    ],
    'job:RetrySubscriptionPatchJob' => [
        'feature' => [
            'tests/Feature/Jobs/OperationsOpenJobPathsTest.php::sweeps a next_charge_date marker through to Mollie and clears it',
            'tests/Feature/Jobs/OperationsOpenJobPathsTest.php::sweeps a plan_waiver_sync marker',
            'tests/Feature/Jobs/RetryPatchIntentIdentityTest.php::clears the marker it does own',
            'tests/Feature/Jobs/RetryJobsTest.php::RetrySubscriptionPatchJob notifies admin after 24h hard limit',
        ],
        'no_browser' => 'A queued job: it runs in a worker, not in a browser. Where its result is visible, the browser test named here shows it.',
    ],
    'job:RetryUsageOverageChargeJob' => [
        'feature' => [
            'tests/Feature/Jobs/OverageDunningBudgetTest.php::gives the cycle up once three distinct payments have failed, tells the customer and leaves the debt standing',
            'tests/Feature/Jobs/OverageDunningBudgetTest.php::does not spend the dunning budget on repeated API errors for the same payment',
            'tests/Feature/Jobs/OverageJobsUncoveredPathsTest.php::does not charge again when the payment it would retry was invoiced after all',
            'tests/Feature/Wallet/UsageLongRunDebtRecollectionTest.php::writes the debt off and tells an operator only after the renewals have had their attempts',
        ],
        'browser' => [
            'tests/Browser/Matrix/AdminListsMatrixTest.php::test_past_due_list_sorts_and_retries_the_open_overage_charge',
            'tests/Browser/Matrix/UsageWalletMatrixTest.php::test_a_failed_overage_charge_is_shown_as_open_until_a_renewal_collects_it',
        ],
    ],
    'job:RevokeMollieMandateJob' => [
        'feature' => [
            'tests/Feature/Jobs/RevokeMollieMandateJobTest.php::revokes the mandate through a typed request object',
            'tests/Feature/Jobs/RevokeMollieMandateJobTest.php::treats an already revoked mandate as success',
            'tests/Feature/Jobs/RevokeMollieMandateJobTest.php::still retries a genuine Mollie failure',
        ],
        'no_browser' => 'A queued job: it runs in a worker, not in a browser. Where its result is visible, the browser test named here shows it.',
    ],
    'job:SweepPendingRetriesJob' => [
        'feature' => [
            'tests/Feature/Jobs/OperationsOpenJobPathsTest.php::sweeps a next_charge_date marker through to Mollie and clears it',
            'tests/Feature/Jobs/OperationsOpenJobPathsTest.php::keeps a next_charge_date marker while Mollie is still unreachable',
            'tests/Feature/Jobs/SweepPendingRetriesJobTest.php::dispatches one refund retry per pending_refund_retries entry',
            'tests/Feature/Jobs/SweepPendingRetriesJobTest.php::dispatches a patch retry for an intent-shaped pending_subscription_patch marker',
            'tests/Feature/Jobs/RetrySubscriptionCancelJobTest.php::is dispatched by the sweep for a pending_subscription_cancel marker',
        ],
        'no_browser' => 'A queued job: it runs in a worker, not in a browser. Where its result is visible, the browser test named here shows it.',
    ],
    'job:SyncSeatsJob' => [
        'feature' => [
            'tests/Feature/Jobs/OperationsOpenJobPathsTest.php::puts a seat change back on the queue when another one holds the lock',
            'tests/Feature/Jobs/OperationsOpenJobPathsTest.php::gives up on a seat lock that never frees, loudly',
            'tests/Feature/Jobs/OverageJobsUncoveredPathsTest.php::adds two seats for two delta jobs, and removes them again one by one',
            'tests/Feature/Jobs/SeatSyncSerialisationTest.php::holds one serialisation across resolving a seat delta and writing it',
        ],
        'no_browser' => 'A queued job: it runs in a worker, not in a browser. Where its result is visible, the browser test named here shows it.',
    ],
    // Introduced with the standing-debt rule (case 10); an event has no page of its own.
    'event:OverageWrittenOff' => [
        'feature' => [
            'tests/Feature/Wallet/UsageLongRunDebtRecollectionTest.php::writes the debt off and tells an operator only after the renewals have had their attempts',
            'tests/Feature/Audit/AuditCategoryCasesTest.php::files an event under its category, filters by it and can switch it off',
        ],
    ],
];
