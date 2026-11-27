<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceNumberGenerator;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use GraystackIT\MollieBilling\Services\Vat\CountryMatchService;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Services\Webhook\CountryCorrectionHandler;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mollie\Laravel\Facades\Mollie;

/**
 * A reissue is an ordinary paid invoice of its own: refunding it gives back the VAT it
 * charged. Its lines used to carry `parent_invoice_id` — the key that marks a credit
 * against another invoice — so every reader of the document's own charges skipped all of
 * them, and a full refund credited 0 % VAT and paid back only the net.
 */
beforeEach(function (): void {
    Notification::fake();
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');

    $this->app->bind(InvoiceService::class, fn ($app): InvoiceService => new class ($app->make(VatCalculationService::class), $app->make(InvoiceNumberGenerator::class)) extends InvoiceService
    {
        protected function generateAndStorePdf(BillingInvoice $invoice, Billable $billable): bool
        {
            return true;
        }
    });

    $this->refundedGross = [];
    $self = $this;
    $this->app->bind(RefundInvoiceService::class, fn ($app): RefundInvoiceService => new class ($app->make(InvoiceService::class), $app->make(WalletUsageService::class), $self) extends RefundInvoiceService
    {
        public function __construct(InvoiceService $invoices, WalletUsageService $wallet, private readonly object $test)
        {
            parent::__construct($invoices, $wallet);
        }

        protected function callMollieRefund(string $paymentId, int $grossCents, string $description, string $currency): ?string
        {
            $this->test->refundedGross[] = $grossCents;

            return 'rf_'.$paymentId.'_'.count($this->test->refundedGross);
        }
    });

    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'tr_reissue', 'status' => 'open']);
});

function reissueBillable(): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'name' => 'Reissue',
        'email' => uniqid('reissue-').'@x.test',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
        'tax_country_ip' => 'DE',
        'subscription_source' => SubscriptionSource::Local->value,
        'subscription_status' => SubscriptionStatus::Active,
        'mollie_customer_id' => 'cst_reissue',
        'mollie_mandate_id' => 'mdt_reissue',
    ])->save();

    return $b;
}

/** Resolve a mismatch over one AT invoice to DE and book the paid correction. */
function bookedReissue(TestBillable $billable): BillingInvoice
{
    $original = new BillingInvoice;
    $original->billable_type = $billable->getMorphClass();
    $original->billable_id = $billable->getKey();
    $original->mollie_payment_id = uniqid('tr_orig_');
    $original->serial_number = uniqid('IN-');
    $original->invoice_kind = InvoiceKind::Subscription;
    $original->status = InvoiceStatus::Paid;
    $original->country = 'AT';
    $original->currency = 'EUR';
    $original->amount_net = 10000;
    $original->amount_vat = 2000;
    $original->amount_gross = 12000;
    $original->line_items = [[
        'kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1,
        'unit_price_net' => 10000, 'amount_net' => 10000,
        'vat_rate' => 20.0, 'vat_amount' => 2000, 'amount_gross' => 12000,
    ]];
    $original->refunded_net = 0;
    $original->save();

    $service = app(CountryMatchService::class);
    $mismatch = $service->check($billable);
    $service->resolve($billable->fresh(), $mismatch, 'DE');

    app(CountryCorrectionHandler::class)->paid(
        (object) ['id' => 'tr_reissue', 'status' => 'paid'],
        $billable->fresh(),
        ['mismatch_id' => (int) $mismatch->id, 'original_invoice_id' => (int) $original->id],
    );

    /** @var BillingInvoice $reissue */
    $reissue = BillingInvoice::query()->where('mollie_payment_id', 'tr_reissue')->firstOrFail();

    expect((int) $reissue->amount_net)->toBe(10000)
        ->and((int) $reissue->amount_vat)->toBe(1900);

    return $reissue;
}

it('pays back the VAT when a reissue is refunded in full', function (): void {
    $billable = reissueBillable();
    $reissue = bookedReissue($billable);
    $this->refundedGross = [];

    $creditNote = app(RefundInvoiceService::class)->refundFully($reissue, RefundReasonCode::Goodwill);

    expect($this->refundedGross)->toBe([11900])
        ->and((int) $creditNote->amount_vat)->toBe(-1900)
        ->and((float) $creditNote->line_items[0]['vat_rate'])->toBe(19.0);
});

it('books a dashboard refund of a reissue with its VAT', function (): void {
    $billable = reissueBillable();
    $reissue = bookedReissue($billable);

    // 119.00 refunded in the Mollie dashboard maps onto the full 100.00 net — and the VAT
    // that went with it is 19.00, not 0.
    expect(app(InvoiceService::class)->creditNetForGross($reissue, 11900))->toBe(10000)
        ->and(app(InvoiceService::class)->creditNoteVat($reissue, 10000))->toBe(1900);
});

it('offers the lines of a reissue for a refund by line', function (): void {
    $billable = reissueBillable();
    $reissue = bookedReissue($billable);

    $lines = app(InvoiceService::class)->refundableLines($reissue);

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['remaining_net'])->toBe(10000)
        ->and($lines[0]['remaining_vat'])->toBe(1900);
});
