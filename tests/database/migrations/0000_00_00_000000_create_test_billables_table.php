<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_billables', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();

            $table->string('mollie_customer_id')->nullable()->index();
            $table->string('mollie_mandate_id')->nullable();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->string('pm_country', 2)->nullable();

            $table->string('tax_country_user', 2)->nullable();
            $table->string('tax_country_ip', 2)->nullable();
            $table->string('tax_country_payment', 2)->nullable();
            $table->boolean('tax_country_verified')->default(false);
            $table->timestamp('country_mismatch_flagged_at')->nullable();
            $table->string('vat_number')->nullable();
            $table->boolean('vat_exempt')->default(false);

            $table->string('billing_street')->nullable();
            $table->string('billing_city')->nullable();
            $table->string('billing_postal_code')->nullable();
            $table->string('billing_country', 2)->nullable();

            $table->string('subscription_source')->default(SubscriptionSource::None->value);
            $table->string('subscription_plan_code')->nullable();
            $table->string('subscription_interval')->nullable();
            $table->timestamp('subscription_period_starts_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('subscription_ends_at')->nullable();
            $table->json('active_addon_codes')->nullable();
            $table->json('subscription_meta')->nullable();
            $table->string('subscription_status')->default(SubscriptionStatus::New->value);
            $table->timestamp('scheduled_change_at')->nullable()->index();
            $table->boolean('allows_billing_overage')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_billables');
    }
};
