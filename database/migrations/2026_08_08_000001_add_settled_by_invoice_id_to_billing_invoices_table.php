<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records that an invoice's charges were settled out of *another* invoice's payment.
 *
 * One document needs this: the saldo-zero plan-switch invoice
 * (`InvoiceService::createPlanSwitchInvoice()`). An equal-price plan change mid-period
 * charges the new plan for the days that are left and credits the old one for the same
 * amount, so no new money moves — the new plan's days are paid for out of the invoice that
 * collected the period. The switch therefore has no `mollie_payment_id` of its own, and
 * `mollie_payment_id` cannot simply be shared: it is unique, and that uniqueness is what
 * keeps the webhook idempotent.
 *
 * Without a way to express the settlement, the switch's charge line was unrefundable —
 * `BillingInvoice::currentPeriodLines()` only offers invoices a refund can be posted
 * against — so a *third* change inside the same period credited the components the switch
 * had consumed at 0. On a 2900 plan switched on day 11 and downgraded on day 20 that is
 * 1123 net of paid, unused service the customer never got back.
 *
 * Nullable and additive: every existing invoice keeps `null` and behaves exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('billing_invoices') || Schema::hasColumn('billing_invoices', 'settled_by_invoice_id')) {
            return;
        }

        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('settled_by_invoice_id')->nullable()->after('mollie_payment_id');
            $table->index('settled_by_invoice_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('billing_invoices') || ! Schema::hasColumn('billing_invoices', 'settled_by_invoice_id')) {
            return;
        }

        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropIndex(['settled_by_invoice_id']);
            $table->dropColumn('settled_by_invoice_id');
        });
    }
};
