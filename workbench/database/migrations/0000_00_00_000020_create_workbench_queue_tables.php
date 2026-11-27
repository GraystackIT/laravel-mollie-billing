<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's own `jobs` and `failed_jobs`, in the shared file.
 *
 * Both processes run the `sync` queue, which is what almost every scenario wants. A scenario
 * about a job that puts itself back on the queue (SyncSeatsJob meeting another change's
 * lock) cannot be told on `sync` — a copy dispatched there runs at once, in the run it was
 * meant to follow — so the test process switches itself to the `database` driver for that
 * one scenario and works the queue by hand, and `failed_jobs` is where a lost job would show.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('jobs');
    }
};
