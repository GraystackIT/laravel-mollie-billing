<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pins the locale each invoice PDF was rendered in.
 *
 * `InvoiceDownloadController` regenerates a PDF whose blob went missing, and
 * without a stored locale that regeneration follows whatever locale the
 * triggering request ran in — a German invoice re-downloaded by an admin running
 * with `BILLING_ADMIN_LOCALE=en` came back as `€1,234.56` with English labels and
 * permanently overwrote the archived document.
 *
 * `InvoiceService` treats the column as optional (it checks for it and falls back
 * to `mollie-billing.invoices.locale`), so existing rows keep working: they simply
 * render in the configured locale rather than their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('billing_invoices') || Schema::hasColumn('billing_invoices', 'locale')) {
            return;
        }

        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->string('locale', 10)->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('billing_invoices') || ! Schema::hasColumn('billing_invoices', 'locale')) {
            return;
        }

        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn('locale');
        });
    }
};
