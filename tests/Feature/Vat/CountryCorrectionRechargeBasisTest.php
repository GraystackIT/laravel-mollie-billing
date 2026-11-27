<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
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
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * A country correction re-issues a tax document: what remained paid at the old country's
 * rate is refunded and charged again at the new one. It is not a refund of the service —
 * so neither money the customer already had back before the mismatch, nor usage units
 * they consumed and paid for, may change hands through it.
 *
 * Real InvoiceService and RefundInvoiceService; only the Mollie calls and the PDF are
 * faked, so the amount asserted is the one that would have been sent.
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

    $this->charges = [];
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use ($self) {
        if ($request instanceof CreatePaymentRequest) {
            $amount = (new ReflectionProperty(CreatePaymentRequest::class, 'amount'))->getValue($request);
            $metadata = (new ReflectionProperty(CreatePaymentRequest::class, 'metadata'))->getValue($request);
            $self->charges[] = ['value' => $amount->value, 'original_invoice_id' => (int) ($metadata['original_invoice_id'] ?? 0)];
        }

        return (object) ['id' => 'tr_corr_'.count($self->charges), 'status' => 'open'];
    });
});

function basisBillable(): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'name' => 'Basis',
        'email' => uniqid('basis-').'@x.test',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
        'tax_country_ip' => 'DE',
        'subscription_source' => SubscriptionSource::Local->value,
        'subscription_status' => SubscriptionStatus::Active,
        'mollie_customer_id' => 'cst_basis',
        'mollie_mandate_id' => 'mdt_basis',
    ])->save();

    return $b;
}

/**
 * @param  list<array<string, mixed>>|null  $lines
 */
function basisInvoice(TestBillable $billable, int $net, InvoiceKind $kind = InvoiceKind::Subscription, ?array $lines = null): BillingInvoice
{
    $vat = (int) round($net * 0.20);

    $invoice = new BillingInvoice;
    $invoice->billable_type = $billable->getMorphClass();
    $invoice->billable_id = $billable->getKey();
    $invoice->mollie_payment_id = uniqid('tr_orig_');
    $invoice->serial_number = uniqid('IN-');
    $invoice->invoice_kind = $kind;
    $invoice->status = InvoiceStatus::Paid;
    $invoice->country = 'AT';
    $invoice->currency = 'EUR';
    $invoice->amount_net = $net;
    $invoice->amount_vat = $vat;
    $invoice->amount_gross = $net + $vat;
    $invoice->line_items = $lines ?? [[
        'kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1,
        'unit_price_net' => $net, 'amount_net' => $net,
        'vat_rate' => 20.0, 'vat_amount' => $vat, 'amount_gross' => $net + $vat,
    ]];
    $invoice->refunded_net = 0;
    $invoice->save();

    return $invoice;
}

function basisResolve(TestBillable $billable): void
{
    $service = app(CountryMatchService::class);
    $mismatch = $service->check($billable);

    expect($mismatch)->not->toBeNull();

    $service->resolve($billable->fresh(), $mismatch, 'DE');

    expect($mismatch->fresh()->status)->toBe(CountryMismatchStatus::Resolved);
}

it('charges again only what remained paid when part was refunded before the mismatch', function (): void {
    // 2000 net @ AT 20 %, 500 net given back as goodwill. The correction refunds the
    // remaining 1500 + 300 and must charge 1500 + 19 % = 17.85 — not the whole 2000 at
    // the new rate (23.80), which collected the goodwill refund a second time.
    $billable = basisBillable();
    $invoice = basisInvoice($billable, 2000);

    app(RefundInvoiceService::class)->refundPartially($invoice, 500, RefundReasonCode::Goodwill);
    $this->refundedGross = [];

    basisResolve($billable);

    expect($this->refundedGross)->toBe([1800])
        ->and($this->charges)->toHaveCount(1)
        ->and($this->charges[0]['value'])->toBe('17.85');

    $pending = (array) ($billable->fresh()->subscription_meta['country_corrections'] ?? []);
    $lines = (array) (reset($pending)['line_items'] ?? []);

    // The reissue books what was collected: the plan at its list price and the earlier
    // refund as a reduction of it, at the new rate.
    expect(array_sum(array_column($lines, 'amount_net')))->toBe(1500)
        ->and(array_sum(array_column($lines, 'amount_gross')))->toBe(1785);
});

it('does not charge again for an invoice refunded in full before the mismatch', function (): void {
    $billable = basisBillable();
    $refunded = basisInvoice($billable, 1000);
    $open = basisInvoice($billable, 2000);

    app(RefundInvoiceService::class)->refundFully($refunded, RefundReasonCode::Cancellation);
    $this->refundedGross = [];

    basisResolve($billable);

    // Only the open invoice is refunded and re-charged.
    expect($this->refundedGross)->toBe([2400])
        ->and($this->charges)->toHaveCount(1)
        ->and($this->charges[0]['original_invoice_id'])->toBe((int) $open->id)
        ->and($this->charges[0]['value'])->toBe('23.80');
});

it('re-charges the whole invoice when the correction itself refunded it in an earlier attempt', function (): void {
    // An earlier resolve refunded the invoice and then stopped before the charge (another
    // invoice's refund threw). That refund is the correction's own, so the retry still
    // owes the full reissue.
    $billable = basisBillable();
    $invoice = basisInvoice($billable, 2000);

    $mismatch = app(CountryMatchService::class)->check($billable);

    app(RefundInvoiceService::class)->refundFully($invoice, RefundReasonCode::BillingError, CountryMatchService::CORRECTION_REFUND_REASON);
    $this->refundedGross = [];

    app(CountryMatchService::class)->resolve($billable->fresh(), $mismatch, 'DE');

    expect($this->refundedGross)->toBe([])
        ->and($this->charges)->toHaveCount(1)
        ->and($this->charges[0]['value'])->toBe('23.80');
});

it('leaves the wallet alone when it corrects an overage invoice', function (): void {
    // 100 tokens of overage, paid; settlement brought the wallet back to 0. Refunding the
    // invoice for the correction credited the 100 units back while the correction charge
    // collected their price again — usage handed out a second time for free.
    $billable = basisBillable();
    $invoice = basisInvoice($billable, 1000, InvoiceKind::Overage, [[
        'kind' => 'overage', 'code' => 'tokens', 'label' => 'Tokens overage',
        'quantity' => 100, 'unit_price_net' => 10, 'amount_net' => 1000,
        'vat_rate' => 20.0, 'vat_amount' => 200, 'amount_gross' => 1200,
    ]]);

    $balanceBefore = (int) ($billable->getWallet('tokens')?->balanceInt ?? 0);

    basisResolve($billable);

    expect((int) ($billable->fresh()->getWallet('tokens')?->balanceInt ?? 0))->toBe($balanceBefore)
        ->and($this->refundedGross)->toBe([1200])
        ->and($this->charges)->toHaveCount(1)
        ->and($this->charges[0]['original_invoice_id'])->toBe((int) $invoice->id)
        ->and($this->charges[0]['value'])->toBe('11.90');
});
