<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables owned by the browser-test workbench: the fake Mollie server's state and
 * a tiny cross-process key-value store. They live in the shared SQLite file so
 * the Dusk HTTP server and the test process see the same world.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        // One row per key. The test process flips switches (fake IP country, the
        // outcome of the next recurring charge); the server reads them live.
        Schema::create('workbench_settings', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->text('value')->nullable();
        });

        Schema::create('fake_mollie_customers', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });

        Schema::create('fake_mollie_mandates', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('customer_id')->index();
            $table->string('status')->default('valid');
            $table->string('method')->default('creditcard');
            $table->timestamps();
        });

        Schema::create('fake_mollie_payments', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('status')->default('open');
            $table->string('amount_value');
            $table->string('amount_currency', 3);
            $table->string('description')->nullable();
            $table->string('sequence_type')->default('oneoff');
            $table->text('redirect_url')->nullable();
            $table->text('webhook_url')->nullable();
            $table->string('customer_id')->nullable()->index();
            $table->string('mandate_id')->nullable();
            $table->string('subscription_id')->nullable()->index();
            $table->string('country_code', 2)->nullable();
            $table->json('metadata')->nullable();
            $table->string('amount_refunded_value')->default('0.00');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('fake_mollie_subscriptions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('customer_id')->index();
            $table->string('status')->default('active');
            $table->string('amount_value');
            $table->string('amount_currency', 3);
            $table->string('interval')->default('1 month');
            $table->string('description')->nullable();
            $table->string('mandate_id')->nullable();
            $table->text('webhook_url')->nullable();
            $table->json('metadata')->nullable();
            $table->date('start_date')->nullable();
            $table->date('next_payment_date')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('fake_mollie_refunds', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('payment_id')->index();
            $table->string('amount_value');
            $table->string('amount_currency', 3);
            $table->string('description')->nullable();
            $table->string('status')->default('refunded');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fake_mollie_refunds');
        Schema::dropIfExists('fake_mollie_subscriptions');
        Schema::dropIfExists('fake_mollie_payments');
        Schema::dropIfExists('fake_mollie_mandates');
        Schema::dropIfExists('fake_mollie_customers');
        Schema::dropIfExists('workbench_settings');
        Schema::dropIfExists('admin_users');
    }
};
