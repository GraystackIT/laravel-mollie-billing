<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceNumberGenerator;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The serial number must be allocated and consumed in one transaction: the lock
 * taken while reading the highest serial is released on commit, so a generator
 * that commits on its own hands the same number to a concurrent caller.
 */

/** Records the transaction nesting level the generator is called at. */
class RecordingInvoiceNumberGenerator extends InvoiceNumberGenerator
{
    /** @var list<int> */
    public static array $levels = [];

    public function generate(string $invoiceKind): string
    {
        self::$levels[] = DB::transactionLevel();

        return parent::generate($invoiceKind);
    }
}

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
    RecordingInvoiceNumberGenerator::$levels = [];

    $this->app->singleton(InvoiceNumberGenerator::class, fn (): InvoiceNumberGenerator => new RecordingInvoiceNumberGenerator);

    $this->app->bind(InvoiceService::class, fn ($app): InvoiceService => new class ($app->make(VatCalculationService::class), $app->make(InvoiceNumberGenerator::class)) extends InvoiceService
    {
        protected function generateAndStorePdf(BillingInvoice $invoice, Billable $billable): bool
        {
            $invoice->pdf_disk = 'local';
            $invoice->pdf_path = 'billing/invoices/test/'.$invoice->serial_number.'.pdf';
            $invoice->save();

            return true;
        }
    });
});

function serialBillable(): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => 'serial@example.com',
        'name' => 'Serial Test',
        'billing_country' => 'DE',
    ])->save();

    return $b;
}

function serialLineItems(): array
{
    return [[
        'kind' => 'plan', 'label' => 'Pro', 'code' => 'pro',
        'quantity' => 1, 'unit_price' => 1000, 'unit_price_net' => 1000, 'total_net' => 1000,
    ]];
}

it('allocates the serial inside the transaction that inserts the row', function (): void {
    $b = serialBillable();
    $levelBefore = DB::transactionLevel();

    app(InvoiceService::class)->createForPayment(
        (object) ['id' => 'tr_serial_1', 'subscriptionId' => null], 'subscription', serialLineItems(), $b,
    );

    expect(RecordingInvoiceNumberGenerator::$levels)->toHaveCount(1)
        ->and(RecordingInvoiceNumberGenerator::$levels[0])->toBeGreaterThan($levelBefore);
});

it('frees the serial again when the inserting transaction rolls back', function (): void {
    $b = serialBillable();

    try {
        DB::transaction(function () use ($b): void {
            app(InvoiceService::class)->createForPayment(
                (object) ['id' => 'tr_serial_rollback', 'subscriptionId' => null], 'subscription', serialLineItems(), $b,
            );

            throw new \RuntimeException('caller failed after the invoice was written');
        });
    } catch (\RuntimeException) {
        // expected
    }

    expect(BillingInvoice::count())->toBe(0);

    $invoice = app(InvoiceService::class)->createForPayment(
        (object) ['id' => 'tr_serial_after_rollback', 'subscriptionId' => null], 'subscription', serialLineItems(), $b,
    );

    // Counter 1 is free again — the rolled-back allocation left nothing behind.
    expect($invoice->serial_number)->toBe('IN-'.date('y').'000001');
});

it('keeps handing out increasing serials while a caller transaction is open', function (): void {
    $b = serialBillable();

    [$first, $second] = DB::transaction(fn (): array => [
        app(InvoiceService::class)->createForPayment((object) ['id' => 'tr_serial_a', 'subscriptionId' => null], 'subscription', serialLineItems(), $b),
        app(InvoiceService::class)->createForPayment((object) ['id' => 'tr_serial_b', 'subscriptionId' => null], 'subscription', serialLineItems(), $b),
    ]);

    expect($first->serial_number)->not->toBe($second->serial_number)
        ->and($second->serial_number)->toBeGreaterThan($first->serial_number);
});

it('allocates credit-note serials inside the refund transaction', function (): void {
    $b = serialBillable();

    $invoice = app(InvoiceService::class)->createForPayment(
        (object) ['id' => 'tr_serial_cn', 'subscriptionId' => null], 'subscription', serialLineItems(), $b,
    );

    RecordingInvoiceNumberGenerator::$levels = [];
    $levelBefore = DB::transactionLevel();

    app(InvoiceService::class)->createCreditNote($invoice, 1000);

    expect(RecordingInvoiceNumberGenerator::$levels[0])->toBeGreaterThan($levelBefore);
});
