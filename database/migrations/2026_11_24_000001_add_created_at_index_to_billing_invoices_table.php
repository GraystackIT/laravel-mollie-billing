<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The admin invoice list reads across all billables, newest first, usually within a date
 * range. The existing indexes all start with the billable, so that list scanned and sorted
 * the whole table on every render; with (created_at, id) the first page reads its 25 rows
 * off the index, and a date range is an index range. `id` is the list's tie-breaker.
 */
return new class extends Migration
{
    private const INDEX = 'billing_invoices_created_at_id_index';

    public function up(): void
    {
        if ($this->hasIndex()) {
            return;
        }

        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->index(['created_at', 'id'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (! $this->hasIndex()) {
            return;
        }

        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }

    private function hasIndex(): bool
    {
        foreach (Schema::getIndexes('billing_invoices') as $index) {
            if (($index['name'] ?? null) === self::INDEX) {
                return true;
            }
        }

        return false;
    }
};
