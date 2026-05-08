<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\CleanupStalePendingCountryCorrectionJob;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceNumberGenerator;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Laravel\Facades\Mollie;

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
    config()->set('mollie-billing.invoices.path', 'billing/invoices');
    config()->set('mollie-billing.invoices.seller', [
        'company' => 'Test Company',
        'name' => 'Test Seller',
        'email' => 'seller@test.com',
        'tax_number' => 'ATU12345678',
        'address' => [
            'street' => 'Test Street 1',
            'city' => 'Vienna',
            'postal_code' => '1010',
            'country' => 'AT',
        ],
    ]);

    // Skip real PDF rendering — same trick as InvoiceServiceTest.
    app()->bind(InvoiceService::class, function ($app): InvoiceService {
        return new class (
            $app->make(\GraystackIT\MollieBilling\Services\Vat\VatCalculationService::class),
            $app->make(InvoiceNumberGenerator::class),
        ) extends InvoiceService {
            protected function generateAndStorePdf(BillingInvoice $invoice, \GraystackIT\MollieBilling\Contracts\Billable $billable): void
            {
                $invoice->pdf_disk = 'local';
                $invoice->pdf_path = 'billing/invoices/test/'.$invoice->serial_number.'.pdf';
                $invoice->save();
                Storage::disk('local')->put($invoice->pdf_path, 'fake-pdf');
            }
        };
    });
});

function makeCorrectionBillable(): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Iris Moore',
        'email' => 'iris@example.test',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
        'mollie_customer_id' => 'cst_iris',
        'mollie_mandate_id' => 'mdt_iris',
    ]);

    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'business',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
    ])->save();

    return $b->refresh();
}

function makeOriginalInvoice(TestBillable $b, string $country, int $net, float $vatRate): BillingInvoice
{
    $vat = (int) round($net * $vatRate / 100);

    $inv = new BillingInvoice;
    $inv->billable_type = $b->getMorphClass();
    $inv->billable_id = $b->getKey();
    $inv->mollie_payment_id = 'tr_orig_'.uniqid();
    $inv->serial_number = 'IN-'.uniqid();
    $inv->invoice_kind = InvoiceKind::Subscription;
    $inv->status = InvoiceStatus::Paid;
    $inv->country = $country;
    $inv->currency = 'EUR';
    $inv->amount_net = $net;
    $inv->amount_vat = $vat;
    $inv->amount_gross = $net + $vat;
    $inv->line_items = [[
        'kind' => 'plan',
        'description' => 'Business',
        'qty' => 1,
        'unit_price_net' => $net,
        'amount_net' => $net,
        'vat_rate' => $vatRate,
        'vat_amount' => $vat,
        'amount_gross' => $net + $vat,
    ]];
    $inv->refunded_net = 0;
    $inv->save();

    return $inv;
}

function seedPendingCorrection(TestBillable $b, BillingCountryMismatch $mismatch, BillingInvoice $original, string $paymentId, string $createdAtIso): void
{
    $meta = $b->getBillingSubscriptionMeta();
    $meta['country_corrections'][$paymentId] = [
        'mismatch_id' => (int) $mismatch->id,
        'original_invoice_id' => (int) $original->id,
        'old_country' => 'HU',
        'new_country' => 'AT',
        'credit_note_serial' => 'CN-test',
        'line_items' => [[
            'kind' => 'plan',
            'description' => 'Business',
            'qty' => 1,
            'unit_price_net' => 1900,
            'amount_net' => 1900,
            'vat_rate' => 20.0,
            'vat_amount' => 380,
            'amount_gross' => 2280,
        ]],
        'period_start' => now()->subMonth()->toIso8601String(),
        'period_end' => now()->toIso8601String(),
        'created_at' => $createdAtIso,
    ];
    $b->forceFill(['subscription_meta' => $meta])->save();
}

it('skips entries younger than 1 hour', function (): void {
    $b = makeCorrectionBillable();
    $mismatch = BillingCountryMismatch::create([
        'billable_type' => $b->getMorphClass(),
        'billable_id' => $b->getKey(),
        'tax_country_user' => 'HU',
        'tax_country_payment' => 'AT',
        'tax_country_ip' => 'AT',
        'status' => CountryMismatchStatus::Resolved,
        'chosen_country' => 'AT',
        'resolved_at' => now()->subMinutes(30),
    ]);
    $orig = makeOriginalInvoice($b, 'HU', 1900, 27.0);
    seedPendingCorrection($b, $mismatch, $orig, 'tr_recent', now()->subMinutes(30)->toIso8601String());

    Mollie::shouldReceive('send')->never();

    (new CleanupStalePendingCountryCorrectionJob())->handle();

    $b->refresh();
    expect($b->getBillingSubscriptionMeta()['country_corrections']['tr_recent'] ?? null)->not->toBeNull();
});

it('routes paid Mollie status into handleCountryCorrectionPaid on the webhook controller', function (): void {
    $b = makeCorrectionBillable();
    $mismatch = BillingCountryMismatch::create([
        'billable_type' => $b->getMorphClass(),
        'billable_id' => $b->getKey(),
        'tax_country_user' => 'HU',
        'tax_country_payment' => 'AT',
        'tax_country_ip' => 'AT',
        'status' => CountryMismatchStatus::Resolved,
        'chosen_country' => 'AT',
        'resolved_at' => now()->subHours(2),
    ]);
    $orig = makeOriginalInvoice($b, 'HU', 1900, 27.0);
    seedPendingCorrection($b, $mismatch, $orig, 'tr_paid', now()->subHours(2)->toIso8601String());

    Mollie::shouldReceive('send')->once()->with(\Mockery::on(function ($req) {
        return $req instanceof GetPaymentRequest;
    }))->andReturnUsing(function () use ($mismatch, $orig) {
        $p = new \stdClass;
        $p->id = 'tr_paid';
        $p->status = 'paid';
        $p->subscriptionId = null;
        $p->metadata = (object) [
            'type' => 'country_correction',
            'mismatch_id' => (string) $mismatch->id,
            'original_invoice_id' => (string) $orig->id,
        ];
        return $p;
    });

    // Spy on the webhook controller: the job must route a paid status into
    // handleCountryCorrectionPaid (and not into Failed/escalation).
    $controllerSpy = \Mockery::mock(\GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController::class)->makePartial();
    $controllerSpy->shouldReceive('handleCountryCorrectionPaid')->once();
    $controllerSpy->shouldReceive('handleCountryCorrectionFailed')->never();
    app()->instance(\GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController::class, $controllerSpy);

    (new CleanupStalePendingCountryCorrectionJob())->handle();
});

it('routes failed Mollie status into handleCountryCorrectionFailed on the webhook controller', function (): void {
    $b = makeCorrectionBillable();
    $mismatch = BillingCountryMismatch::create([
        'billable_type' => $b->getMorphClass(),
        'billable_id' => $b->getKey(),
        'tax_country_user' => 'HU',
        'tax_country_payment' => 'AT',
        'tax_country_ip' => 'AT',
        'status' => CountryMismatchStatus::Resolved,
        'chosen_country' => 'AT',
        'resolved_at' => now()->subHours(2),
    ]);
    $orig = makeOriginalInvoice($b, 'HU', 1900, 27.0);
    seedPendingCorrection($b, $mismatch, $orig, 'tr_failed', now()->subHours(2)->toIso8601String());

    Mollie::shouldReceive('send')->once()->andReturnUsing(function () use ($mismatch) {
        $p = new \stdClass;
        $p->id = 'tr_failed';
        $p->status = 'failed';
        $p->details = (object) ['failureReason' => 'Card declined'];
        $p->metadata = (object) [
            'type' => 'country_correction',
            'mismatch_id' => (string) $mismatch->id,
        ];
        return $p;
    });

    $controllerSpy = \Mockery::mock(\GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController::class)->makePartial();
    $controllerSpy->shouldReceive('handleCountryCorrectionFailed')->once();
    $controllerSpy->shouldReceive('handleCountryCorrectionPaid')->never();
    app()->instance(\GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController::class, $controllerSpy);

    (new CleanupStalePendingCountryCorrectionJob())->handle();
});

it('escalates entries stuck > 24h by routing through handleCountryCorrectionFailed', function (): void {
    $b = makeCorrectionBillable();
    $mismatch = BillingCountryMismatch::create([
        'billable_type' => $b->getMorphClass(),
        'billable_id' => $b->getKey(),
        'tax_country_user' => 'HU',
        'tax_country_payment' => 'AT',
        'tax_country_ip' => 'AT',
        'status' => CountryMismatchStatus::Resolved,
        'chosen_country' => 'AT',
        'resolved_at' => now()->subHours(25),
    ]);
    $orig = makeOriginalInvoice($b, 'HU', 1900, 27.0);
    seedPendingCorrection($b, $mismatch, $orig, 'tr_stuck', now()->subHours(25)->toIso8601String());

    Mollie::shouldReceive('send')->once()->andReturnUsing(function () {
        $p = new \stdClass;
        $p->id = 'tr_stuck';
        $p->status = 'open';
        $p->metadata = (object) ['type' => 'country_correction'];
        return $p;
    });

    // The job must convert the 24h+ open status into a synthesised failure and
    // route through the webhook controller's failed handler, so the recovery
    // path (mismatch -> Pending, subscription cancel-at-period-end, notification)
    // runs the same code as a real failed-webhook would.
    $controllerSpy = \Mockery::mock(\GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController::class)->makePartial();
    $controllerSpy->shouldReceive('handleCountryCorrectionFailed')
        ->once()
        ->with(\Mockery::on(function ($payment) {
            return ($payment->status ?? null) === 'failed';
        }), \Mockery::any(), \Mockery::any());
    $controllerSpy->shouldReceive('handleCountryCorrectionPaid')->never();
    app()->instance(\GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController::class, $controllerSpy);

    (new CleanupStalePendingCountryCorrectionJob())->handle();
});

it('keeps waiting on entries between 1h and 24h with pending Mollie status', function (): void {
    $b = makeCorrectionBillable();
    $mismatch = BillingCountryMismatch::create([
        'billable_type' => $b->getMorphClass(),
        'billable_id' => $b->getKey(),
        'tax_country_user' => 'HU',
        'tax_country_payment' => 'AT',
        'tax_country_ip' => 'AT',
        'status' => CountryMismatchStatus::Resolved,
        'chosen_country' => 'AT',
        'resolved_at' => now()->subHours(3),
    ]);
    $orig = makeOriginalInvoice($b, 'HU', 1900, 27.0);
    seedPendingCorrection($b, $mismatch, $orig, 'tr_waiting', now()->subHours(3)->toIso8601String());

    Mollie::shouldReceive('send')->once()->andReturnUsing(function () {
        $p = new \stdClass;
        $p->id = 'tr_waiting';
        $p->status = 'open';
        $p->metadata = (object) ['type' => 'country_correction'];
        return $p;
    });

    (new CleanupStalePendingCountryCorrectionJob())->handle();

    $b->refresh();
    expect($b->getBillingSubscriptionMeta()['country_corrections']['tr_waiting'] ?? null)->not->toBeNull();
});
