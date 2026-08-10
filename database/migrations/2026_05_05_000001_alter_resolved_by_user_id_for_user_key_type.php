<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retypes `billing_country_mismatches.resolved_by_user_id` to match
 * `mollie-billing.user_key_type` when the create migration sized it differently.
 *
 * Drop + recreate is destructive — it wipes the record of who resolved which
 * VAT-country mismatch — so it only runs when the column type actually has to
 * change. For the default `user_key_type = 'int'` the create migration already
 * produced an unsignedBigInteger, and this migration is then a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('billing_country_mismatches')) {
            return;
        }

        $userKeyType = (string) config('mollie-billing.user_key_type', 'int');

        if (! $this->needsRetype($userKeyType)) {
            return;
        }

        Schema::table('billing_country_mismatches', function (Blueprint $t): void {
            if (Schema::hasColumn('billing_country_mismatches', 'resolved_by_user_id')) {
                $t->dropColumn('resolved_by_user_id');
            }
        });

        Schema::table('billing_country_mismatches', function (Blueprint $t) use ($userKeyType): void {
            match ($userKeyType) {
                'uuid' => $t->uuid('resolved_by_user_id')->nullable()->after('resolved_at'),
                'ulid' => $t->ulid('resolved_by_user_id')->nullable()->after('resolved_at'),
                default => $t->unsignedBigInteger('resolved_by_user_id')->nullable()->after('resolved_at'),
            };
        });
    }

    public function down(): void
    {
        // Not reversible: dropping and recreating the column would destroy resolver references.
        // Intentionally a no-op.
    }

    /**
     * True only when the existing column cannot already hold the configured key type.
     * A missing column also needs the (re)creation branch.
     */
    private function needsRetype(string $userKeyType): bool
    {
        if (! Schema::hasColumn('billing_country_mismatches', 'resolved_by_user_id')) {
            return true;
        }

        try {
            $type = strtolower(Schema::getColumnType('billing_country_mismatches', 'resolved_by_user_id'));
        } catch (\Throwable) {
            // Type unknown — leave the data alone rather than guess.
            return false;
        }

        $isNumeric = in_array($type, ['integer', 'bigint', 'int', 'int4', 'int8', 'numeric', 'decimal'], true);

        return $userKeyType === 'int' ? ! $isNumeric : $isNumeric;
    }
};
