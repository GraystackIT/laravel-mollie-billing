<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Jobs\CleanupOrphanedBillablesJob;
use GraystackIT\MollieBilling\Jobs\RevokeMollieMandateJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Bus;
use Mollie\Api\Http\Requests\GetPaginatedCustomerPaymentsRequest;
use Mollie\Laravel\Facades\Mollie;

beforeEach(function (): void {
    config()->set('mollie-billing.billable_model', TestBillable::class);
    config(['mollie-billing.cleanup.enabled' => true]);
    config(['mollie-billing.cleanup.threshold_minutes' => 60]);
});

function staleOrphan(array $attributes = []): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create(array_merge([
        'name' => 'Orphan '.uniqid(),
        'email' => uniqid('orph-').'@x.test',
    ], $attributes));

    $b->forceFill([
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ])->save();

    return $b->refresh();
}

it('never deletes a billable that already has an invoice', function (): void {
    Bus::fake([RevokeMollieMandateJob::class]);

    // Exactly the state the bug produced: activation threw after the paid invoice was
    // written, and the webhook had already cleared pending_first_payment_id.
    $b = staleOrphan([
        'mollie_customer_id' => 'cst_paid',
        'mollie_mandate_id' => 'mdt_paid',
    ]);

    BillingInvoice::create([
        'billable_type' => $b->getMorphClass(),
        'billable_id' => $b->getKey(),
        'mollie_payment_id' => 'tr_paid_orphan',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'serial_number' => 'TEST-'.uniqid(),
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 1000,
        'amount_vat' => 200,
        'amount_gross' => 1200,
        'line_items' => [],
    ]);

    CleanupOrphanedBillablesJob::dispatchSync();

    expect(TestBillable::find($b->getKey()))->not->toBeNull();
    Bus::assertNotDispatched(RevokeMollieMandateJob::class);
});

it('never deletes a billable whose Mollie customer has a paid payment', function (): void {
    Bus::fake([RevokeMollieMandateJob::class]);

    Mollie::shouldReceive('send')
        ->withArgs(fn ($req) => $req instanceof GetPaginatedCustomerPaymentsRequest)
        ->andReturn([(object) ['id' => 'tr_x', 'status' => 'paid']]);

    $b = staleOrphan([
        'mollie_customer_id' => 'cst_has_paid',
        'mollie_mandate_id' => 'mdt_has_paid',
    ]);

    CleanupOrphanedBillablesJob::dispatchSync();

    expect(TestBillable::find($b->getKey()))->not->toBeNull();
    Bus::assertNotDispatched(RevokeMollieMandateJob::class);
});

it('keeps the billable when Mollie cannot be reached', function (): void {
    Mollie::shouldReceive('send')
        ->withArgs(fn ($req) => $req instanceof GetPaginatedCustomerPaymentsRequest)
        ->andThrow(new RuntimeException('connection refused'));

    $b = staleOrphan(['mollie_customer_id' => 'cst_unreachable']);

    CleanupOrphanedBillablesJob::dispatchSync();

    expect(TestBillable::find($b->getKey()))->not->toBeNull();
});

it('still deletes an orphan whose Mollie customer never paid', function (): void {
    Mollie::shouldReceive('send')
        ->withArgs(fn ($req) => $req instanceof GetPaginatedCustomerPaymentsRequest)
        ->andReturn([(object) ['id' => 'tr_open', 'status' => 'expired']]);

    $b = staleOrphan(['mollie_customer_id' => 'cst_never_paid']);

    CleanupOrphanedBillablesJob::dispatchSync();

    expect(TestBillable::find($b->getKey()))->toBeNull();
});

it('reports a candidate count for the command to confirm against', function (): void {
    staleOrphan();
    staleOrphan();

    expect(CleanupOrphanedBillablesJob::candidateCount())->toBe(2);
});
