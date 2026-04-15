<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();

            $table->string('billable_type');
            $this->morphIdColumn($table);

            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();

            $table->integer('discount_amount_net')->default(0);
            $table->json('credits_applied')->nullable();
            $table->integer('trial_days_added')->nullable();
            $table->integer('grant_days_added')->nullable();
            $table->json('grant_applied_snapshot')->nullable();

            $table->timestamp('applied_at');

            $table->index(['coupon_id', 'billable_type', 'billable_id'], 'coupon_redemptions_lookup_index');
            $table->index(['billable_type', 'billable_id']);
            $table->foreign('invoice_id')->references('id')->on('billing_invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
    }

    private function morphIdColumn(Blueprint $table): void
    {
        match (config('mollie-billing.billable_key_type', 'uuid')) {
            'int' => $table->unsignedBigInteger('billable_id'),
            'ulid' => $table->ulid('billable_id'),
            default => $table->uuid('billable_id'),
        };
    }
};
