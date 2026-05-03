<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_vat_validations', function (Blueprint $table): void {
            $table->id();
            $table->string('billable_type');
            $this->morphIdColumn($table);

            // Snapshot of the VAT number that was validated. Includes country
            // prefix (e.g. "CZ45806497") so a later VAT-number change leaves
            // the historical entry intact.
            $table->string('vat_number');
            $table->string('country_code', 2)->nullable();

            // VIES result: true = valid, false = invalid. Never null — entries
            // are only persisted after a successful round-trip to VIES.
            $table->boolean('valid');

            // Full VIES response payload (countryCode, vatNumber, requestDate,
            // name, address, …) for audit-grade evidence.
            $table->json('vies_response')->nullable();

            $table->timestamp('checked_at');

            $table->timestamps();

            $table->index(['billable_type', 'billable_id']);
            $table->index(['billable_type', 'billable_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_vat_validations');
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
