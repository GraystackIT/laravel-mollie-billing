<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = Schema::getColumnListing('coupons');

        Schema::table('coupons', function (Blueprint $table) use ($columns): void {
            // Access grant: what happens to a plan waiver when the granted time ends —
            // `end` (the subscription ends) or `continue_paid` (the plan becomes
            // chargeable). Null reads as `end`: a gift never turns into a charge on its own.
            if (! in_array('grant_on_expiry', $columns, true)) {
                $table->string('grant_on_expiry')->nullable()->after('grant_duration_days');
            }

            // Plan-waiver coupon: for how many billing periods the plan line is waived.
            if (! in_array('waiver_periods', $columns, true)) {
                $table->integer('waiver_periods')->nullable()->after('grant_on_expiry');
            }
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table): void {
            $table->dropColumn(['grant_on_expiry', 'waiver_periods']);
        });
    }
};
