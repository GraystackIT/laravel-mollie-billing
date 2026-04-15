<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_processed_webhooks', function (Blueprint $table): void {
            $table->id();
            $table->string('mollie_payment_id');

            // '{payment_id}:pending' during processing, '{payment_id}:{final_status}' afterwards.
            $table->string('event_signature');

            $table->timestamp('processed_at')->nullable();
            $table->timestamp('received_at');

            $table->unique(['mollie_payment_id', 'event_signature']);
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_processed_webhooks');
    }
};
