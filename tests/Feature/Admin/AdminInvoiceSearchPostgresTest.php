<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Support\AdminInvoiceFilter;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The admin search for invoices and credit notes on PostgreSQL.
 *
 * Postgres LIKE is case-sensitive, so "re-2026" never found "RE-2026" and "tr_abc" never
 * found "tr_AbC123" — SQLite and MySQL compare case-insensitively, which is why the rest of
 * the suite passed. (A review also expected `billable_id LIKE` to fail on a bigint or uuid
 * column; it does not — Laravel's Postgres grammar casts the column to text for LIKE — but
 * the id is now matched exactly, as the id it is.)
 *
 * Skipped unless a Postgres server is reachable (`BILLING_PG_HOST` / `BILLING_PG_PORT` /
 * `BILLING_PG_USER` / `BILLING_PG_PASSWORD` / `BILLING_PG_DATABASE`).
 */
function invoiceSearchOnPostgres(string $keyType): void
{
    config()->set('database.connections.pgsearch', [
        'driver' => 'pgsql',
        'host' => env('BILLING_PG_HOST', '127.0.0.1'),
        'port' => (int) env('BILLING_PG_PORT', 5432),
        'username' => env('BILLING_PG_USER', 'postgres'),
        'password' => (string) env('BILLING_PG_PASSWORD', ''),
        'database' => env('BILLING_PG_DATABASE', 'mollie_billing_pgcheck'),
        'charset' => 'utf8',
        'prefix' => '',
        'search_path' => 'public',
        'sslmode' => 'prefer',
    ]);

    try {
        DB::connection('pgsearch')->getPdo();
    } catch (\Throwable $e) {
        test()->markTestSkipped('No PostgreSQL server for the invoice search: '.$e->getMessage());
    }

    config()->set('database.default', 'pgsearch');
    config()->set('mollie-billing.billable_key_type', $keyType);

    Schema::dropIfExists('billing_invoices');
    Schema::dropIfExists('test_billables');

    Schema::create('test_billables', function (Blueprint $table) use ($keyType): void {
        $keyType === 'int' ? $table->id() : $table->uuid('id')->primary();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->timestamps();
    });

    Schema::create('billing_invoices', function (Blueprint $table) use ($keyType): void {
        $table->id();
        $table->string('billable_type');
        $keyType === 'int' ? $table->unsignedBigInteger('billable_id') : $table->uuid('billable_id');
        $table->string('serial_number')->nullable();
        $table->string('mollie_payment_id')->nullable();
        $table->string('invoice_kind');
        $table->string('status');
        $table->timestamps();
    });
}

function invoiceSearchRow(mixed $billableId, string $serial, ?string $payment = null): void
{
    DB::table('billing_invoices')->insert([
        'billable_type' => (new TestBillable)->getMorphClass(),
        'billable_id' => $billableId,
        'serial_number' => $serial,
        'mollie_payment_id' => $payment,
        'invoice_kind' => 'subscription',
        'status' => 'paid',
        'created_at' => '2026-10-01 10:00:00',
        'updated_at' => '2026-10-01 10:00:00',
    ]);
}

/** @return list<string> */
function invoiceSearchSerials(string $term): array
{
    // Sorted here, byte-wise: the database's collation orders "ΟΣ" differently.
    $serials = (new AdminInvoiceFilter(search: $term))->query()->pluck('serial_number')->all();
    sort($serials, SORT_STRING);

    return $serials;
}

afterEach(function (): void {
    if (config('database.default') !== 'pgsearch') {
        return;
    }

    Schema::dropIfExists('billing_invoices');
    Schema::dropIfExists('test_billables');

    // Back to the suite's own connection before RefreshDatabase tears down (its VACUUM
    // belongs to the in-memory SQLite database).
    config()->set('database.default', 'testing');
});

it('searches invoices on PostgreSQL by any text, case-insensitively, whatever the billable key type', function (string $keyType): void {
    invoiceSearchOnPostgres($keyType);

    $acmeId = $keyType === 'int' ? 7 : (string) Str::uuid();
    $globexId = $keyType === 'int' ? 8 : (string) Str::uuid();

    DB::table('test_billables')->insert([
        ['id' => $acmeId, 'name' => 'Acme GmbH', 'email' => 'billing@acme.test'],
        ['id' => $globexId, 'name' => 'Globex', 'email' => 'billing@globex.test'],
    ]);

    invoiceSearchRow($acmeId, 'RE-2026-0001', 'tr_AbC123');
    invoiceSearchRow($globexId, 'RE-2026-0002');
    invoiceSearchRow($globexId, 'GS_2026_0001');
    invoiceSearchRow($globexId, 'ΟΣ-0001');

    // Text, not an id: answered, not a 500.
    expect(invoiceSearchSerials('re-2026'))->toBe(['RE-2026-0001', 'RE-2026-0002'])
        ->and(invoiceSearchSerials('tr_abc'))->toBe(['RE-2026-0001'])
        // The billable's own search, by name.
        ->and(invoiceSearchSerials('acme'))->toBe(['RE-2026-0001'])
        ->and(invoiceSearchSerials('BILLING@GLOBEX'))->toBe(['GS_2026_0001', 'RE-2026-0002', 'ΟΣ-0001'])
        // The exact id still finds the billable's invoices.
        ->and(invoiceSearchSerials((string) $globexId))->toBe(['GS_2026_0001', 'RE-2026-0002', 'ΟΣ-0001'])
        // `_` and `%` are characters, not wildcards.
        ->and(invoiceSearchSerials('S_2'))->toBe(['GS_2026_0001'])
        ->and(invoiceSearchSerials('%'))->toBe([])
        // Folded by Postgres on both sides: PHP's mb_strtolower() turns a final "Σ" into
        // "ς", Postgres' lower() into "σ".
        ->and(invoiceSearchSerials('ΟΣ'))->toBe(['ΟΣ-0001']);

    if ($keyType === 'int') {
        // Past the bigint range: text to search, not an id to compare (a conversion error).
        expect(invoiceSearchSerials('9999999999999999999'))->toBe([]);
    }

    expect(BillingInvoice::query()->count())->toBe(4);
})->with(['int', 'uuid']);
