<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A recurring coupon says for how many charges it discounts in a field of its own.
 *
 * Until now `max_redemptions_per_billable` carried that number for the `recurring` type
 * and meant "how often may one customer redeem this" for every other type. It means the
 * latter for all of them from here on; the period count moves to `discount_periods`.
 *
 * Existing recurring coupons keep the lifetime they were created with: their number is
 * copied over and their per-customer limit becomes 1 — which is what it effectively was,
 * since a customer could never redeem a recurring coupon a second time while its
 * redemption rows counted against that same number. Running discounts are untouched:
 * their marker already carries its end date, and the count is read from the coupon.
 *
 * The copy runs only in the run that adds the column. Afterwards an empty
 * `discount_periods` is a statement ("until the date, no count"), not a gap to fill.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array('discount_periods', Schema::getColumnListing('coupons'), true)) {
            return;
        }

        Schema::table('coupons', function (Blueprint $table): void {
            $table->integer('discount_periods')->nullable()->after('discount_value');
        });

        DB::table('coupons')
            ->where('type', 'recurring')
            ->where('max_redemptions_per_billable', '>', 0)
            ->update([
                'discount_periods' => DB::raw('max_redemptions_per_billable'),
                'max_redemptions_per_billable' => 1,
            ]);
    }

    public function down(): void
    {
        if (! in_array('discount_periods', Schema::getColumnListing('coupons'), true)) {
            return;
        }

        DB::table('coupons')
            ->where('type', 'recurring')
            ->whereNotNull('discount_periods')
            ->update(['max_redemptions_per_billable' => DB::raw('discount_periods')]);

        Schema::table('coupons', function (Blueprint $table): void {
            $table->dropColumn('discount_periods');
        });
    }
};
