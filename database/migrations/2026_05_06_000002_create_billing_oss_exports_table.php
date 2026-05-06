<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_oss_exports', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->string('status', 16);

            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->unsignedInteger('rows_count')->nullable();

            $this->userIdColumn($table, 'requested_by_user_id');

            $table->timestamp('completed_at')->nullable();
            $table->text('failure_reason')->nullable();

            $table->timestamps();

            $table->index(['year', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_oss_exports');
    }

    private function userIdColumn(Blueprint $table, string $name): void
    {
        match (config('mollie-billing.user_key_type', 'int')) {
            'uuid' => $table->uuid($name)->nullable(),
            'ulid' => $table->ulid($name)->nullable(),
            default => $table->unsignedBigInteger($name)->nullable(),
        };
    }
};
