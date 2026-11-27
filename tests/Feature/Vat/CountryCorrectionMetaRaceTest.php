<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Webhook\CountryCorrectionHandler;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Mollie\Laravel\Facades\Mollie;

/**
 * `subscription_meta.country_corrections` is written by `resolve()` (one entry per
 * correction charge), by the paid webhook (clears its entry) and by the failed webhook and
 * the hourly sweeper (clear theirs) — in different processes. Every one of those writes has
 * to re-read the column under the row lock: a writer replaying the snapshot it loaded
 * earlier deletes, or brings back, an entry somebody else has just written or cleared.
 *
 * The concurrent writer is modelled with a raw update, the one thing the in-memory
 * instance the code under test holds cannot see.
 */
beforeEach(function (): void {
    Notification::fake();
});

function countryCorrectionRaceBillable(): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Race',
        'email' => uniqid('race-').'@x.test',
        'billing_country' => 'DE',
        'mollie_customer_id' => 'cst_race',
        'mollie_mandate_id' => 'mdt_race',
    ]);

    return $b;
}

function raceOriginal(TestBillable $billable, int $net = 1000): BillingInvoice
{
    $vat = (int) round($net * 0.20);

    /** @var BillingInvoice $invoice */
    $invoice = BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => uniqid('tr_orig_'),
        'serial_number' => uniqid('IN-'),
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => $net,
        'amount_vat' => $vat,
        'amount_gross' => $net + $vat,
        'line_items' => [[
            'kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1,
            'unit_price_net' => $net, 'amount_net' => $net,
            'vat_rate' => 20.0, 'vat_amount' => $vat, 'amount_gross' => $net + $vat,
        ]],
    ]);

    return $invoice;
}

function racePendingEntry(BillingInvoice $original, int $mismatchId): array
{
    return [
        'mismatch_id' => $mismatchId,
        'original_invoice_id' => (int) $original->id,
        'old_country' => 'AT',
        'new_country' => 'DE',
        'credit_note_serial' => null,
        'line_items' => [[
            'kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1,
            'unit_price_net' => 1000, 'amount_net' => 1000,
            'vat_rate' => 19.0, 'vat_amount' => 190, 'amount_gross' => 1190,
        ]],
        'period_start' => null,
        'period_end' => null,
        'created_at' => now()->toIso8601String(),
    ];
}

/** Write the column behind the model's back, as another process would. */
function raceWriteMeta(TestBillable $billable, array $meta): void
{
    DB::table($billable->getTable())
        ->where('id', $billable->getKey())
        ->update(['subscription_meta' => json_encode($meta)]);
}

function raceMismatch(TestBillable $billable, CountryMismatchStatus $status = CountryMismatchStatus::Resolved): BillingCountryMismatch
{
    /** @var BillingCountryMismatch $mismatch */
    $mismatch = BillingCountryMismatch::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
        'tax_country_ip' => 'DE',
        'status' => $status,
        'chosen_country' => 'DE',
    ]);

    return $mismatch;
}

it('does not bring back a correction the paid webhook cleared while the next charge was being created', function (): void {
    $billable = countryCorrectionRaceBillable();
    $first = raceOriginal($billable);
    $second = raceOriginal($billable, 2000);
    $mismatch = raceMismatch($billable);

    // resolve() has issued tr_1 for the first invoice; mutateBillingMeta left the
    // in-memory instance holding it.
    $billable->forceFill(['subscription_meta' => ['country_corrections' => ['tr_1' => racePendingEntry($first, (int) $mismatch->id)]]])->save();

    // While the CreatePaymentRequest for the second invoice is in flight, tr_1's paid
    // webhook books the reissue and clears its entry in another process.
    Mollie::shouldReceive('send')->andReturnUsing(function () use ($billable) {
        raceWriteMeta($billable, ['country_corrections' => []]);

        return (object) ['id' => 'tr_2', 'status' => 'open'];
    });

    app(InvoiceService::class)->issueCorrectionCharge($second, $billable, 'DE', (int) $mismatch->id);

    $stored = (array) ($billable->fresh()->subscription_meta['country_corrections'] ?? []);

    expect(array_keys($stored))->toBe(['tr_2']);
});

it('keeps what other writers stored while dropping a failed correction', function (): void {
    $billable = countryCorrectionRaceBillable();
    $first = raceOriginal($billable);
    $second = raceOriginal($billable, 2000);
    $mismatch = raceMismatch($billable);

    $billable->forceFill(['subscription_meta' => ['country_corrections' => ['tr_1' => racePendingEntry($first, (int) $mismatch->id)]]])->save();

    // The sweeper loaded this billable a chunk ago; since then a re-resolve issued tr_2 and
    // another writer stored a key of its own.
    $stale = TestBillable::query()->findOrFail($billable->getKey());

    raceWriteMeta($billable, [
        'country_corrections' => [
            'tr_1' => racePendingEntry($first, (int) $mismatch->id),
            'tr_2' => racePendingEntry($second, (int) $mismatch->id),
        ],
        'pending_refund_retries' => ['kept'],
    ]);

    app(CountryCorrectionHandler::class)->failed(
        (object) ['id' => 'tr_1', 'status' => 'failed', 'details' => (object) ['failureReason' => 'insufficient_funds']],
        $stale,
        ['mismatch_id' => (int) $mismatch->id, 'original_invoice_id' => (int) $first->id],
    );

    $meta = (array) $billable->fresh()->subscription_meta;

    expect(array_keys((array) ($meta['country_corrections'] ?? [])))->toBe(['tr_2'])
        ->and($meta['pending_refund_retries'] ?? null)->toBe(['kept'])
        ->and($mismatch->fresh()->status)->toBe(CountryMismatchStatus::Pending);
});

it('does not answer a paid correction it could not book as handled', function (): void {
    // Money collected, no reissue invoice written: swallowing the failure made the webhook
    // answer 200, so Mollie never re-delivered and nobody was told. Thrown, the controller
    // answers 5xx and the re-delivery — idempotent on the reissue's payment id — books it
    // once whatever went wrong has passed.
    $billable = countryCorrectionRaceBillable();
    $original = raceOriginal($billable);
    $mismatch = raceMismatch($billable);

    expect(fn () => app(CountryCorrectionHandler::class)->paid(
        (object) ['id' => 'tr_lost', 'status' => 'paid'],
        $billable,
        ['mismatch_id' => (int) $mismatch->id, 'original_invoice_id' => (int) $original->id],
    ))->toThrow(LogicException::class, 'no pending state');

    expect(BillingInvoice::query()->where('mollie_payment_id', 'tr_lost')->exists())->toBeFalse();
});

it('clears a pending entry whose reissue is already booked', function (): void {
    // A duplicate delivery — or the hourly sweeper polling a payment it found paid — sees
    // the reissue already persisted. Returning without clearing the entry left the
    // dashboard's "re-charge pending" callout up for good and had the sweeper poll Mollie
    // for the same payment every hour.
    $billable = countryCorrectionRaceBillable();
    $original = raceOriginal($billable);
    $mismatch = raceMismatch($billable);

    BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_done',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'DE',
        'currency' => 'EUR',
        'amount_net' => 1000,
        'amount_vat' => 190,
        'amount_gross' => 1190,
        'line_items' => [],
    ]);

    $billable->forceFill(['subscription_meta' => ['country_corrections' => ['tr_done' => racePendingEntry($original, (int) $mismatch->id)]]])->save();

    app(CountryCorrectionHandler::class)->paid(
        (object) ['id' => 'tr_done', 'status' => 'paid'],
        $billable,
        ['mismatch_id' => (int) $mismatch->id, 'original_invoice_id' => (int) $original->id],
    );

    expect($billable->fresh()->subscription_meta['country_corrections'] ?? [])->toBe([])
        ->and($billable->fresh()->hasPendingCountryCorrections())->toBeFalse();
});
