<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('description')->nullable();

            // CouponType enum (first_payment|recurring|credits|trial_extension|access_grant)
            $table->string('type');

            // Discount fields (for first_payment / recurring)
            $table->string('discount_type')->nullable();     // DiscountType enum
            $table->integer('discount_value')->nullable();
            $table->string('currency', 3)->default(config('mollie-billing.currency', 'EUR'));

            // Credits payload (for type=credits)
            $table->json('credits_payload')->nullable();

            // Trial extension (for type=trial_extension)
            $table->integer('trial_extension_days')->nullable();

            // Access grant (for type=access_grant)
            $table->string('grant_plan_code')->nullable();
            $table->string('grant_interval')->nullable();
            $table->json('grant_addon_codes')->nullable();
            $table->integer('grant_duration_days')->nullable();

            // Constraints
            $table->integer('minimum_order_amount_net')->nullable();
            $table->integer('max_redemptions')->nullable();
            $table->integer('redemptions_count')->default(0);
            $table->integer('max_redemptions_per_billable')->default(1);
            $table->boolean('stackable')->default(false);
            $table->string('auto_apply_token')->nullable()->unique();

            // Applicability filters
            $table->json('applicable_plans')->nullable();
            $table->json('applicable_intervals')->nullable();
            $table->json('applicable_addons')->nullable();
            $table->json('applicable_usages')->nullable();

            // Validity window
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->index('auto_apply_token');
            $table->index(['active', 'valid_from', 'valid_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
