<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $columns = Schema::getColumnListing('billing_invoices');

            if (! in_array('serial_number', $columns, true)) {
                $table->string('serial_number')->nullable()->unique()->after('mollie_subscription_id');
            }
            if (! in_array('pdf_disk', $columns, true)) {
                $table->string('pdf_disk')->nullable()->after('serial_number');
            }
            if (! in_array('pdf_path', $columns, true)) {
                $table->string('pdf_path')->nullable()->after('pdf_disk');
            }
        });

        Schema::table('billing_invoices', function (Blueprint $table): void {
            $columns = Schema::getColumnListing('billing_invoices');

            if (in_array('mollie_sales_invoice_id', $columns, true)) {
                $table->dropUnique(['mollie_sales_invoice_id']);
                $table->dropColumn('mollie_sales_invoice_id');
            }
            if (in_array('mollie_invoice_url', $columns, true)) {
                $table->dropColumn('mollie_invoice_url');
            }
            if (in_array('mollie_pdf_url', $columns, true)) {
                $table->dropColumn('mollie_pdf_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $columns = Schema::getColumnListing('billing_invoices');

            if (! in_array('mollie_sales_invoice_id', $columns, true)) {
                $table->string('mollie_sales_invoice_id')->nullable()->unique()->after('mollie_subscription_id');
            }
            if (! in_array('mollie_invoice_url', $columns, true)) {
                $table->string('mollie_invoice_url')->nullable()->after('mollie_sales_invoice_id');
            }
            if (! in_array('mollie_pdf_url', $columns, true)) {
                $table->string('mollie_pdf_url')->nullable()->after('mollie_invoice_url');
            }
        });

        Schema::table('billing_invoices', function (Blueprint $table): void {
            $columns = Schema::getColumnListing('billing_invoices');

            if (in_array('serial_number', $columns, true)) {
                $table->dropUnique(['serial_number']);
                $table->dropColumn('serial_number');
            }
            if (in_array('pdf_disk', $columns, true)) {
                $table->dropColumn('pdf_disk');
            }
            if (in_array('pdf_path', $columns, true)) {
                $table->dropColumn('pdf_path');
            }
        });
    }
};
