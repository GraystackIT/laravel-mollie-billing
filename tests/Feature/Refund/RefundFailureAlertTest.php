<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Notifications\AdminRefundFailedNotification;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * The refund transaction rolls back when Mollie rejects the call — so the admin
 * alert must not be enqueued inside it. The notification is queued and
 * UsesBillingQueue defers it to the commit that never comes: the job row
 * disappears with the rollback and nobody is told the refund failed.
 */

/** Refund service whose Mollie call always fails. */
final class FailingMollieRefundService extends RefundInvoiceService
{
    protected function callMollieRefund(string $paymentId, int $grossCents, string $description, string $currency): ?string
    {
        throw new RuntimeException('Mollie declined the refund');
    }
}

beforeEach(function (): void {
    config()->set('mollie-billing.currency', 'EUR');

    $this->app->bind(RefundInvoiceService::class, fn ($app): RefundInvoiceService => new FailingMollieRefundService(
        $app->make(InvoiceService::class),
        $app->make(WalletUsageService::class),
    ));
});

function alertBillable(): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => 'alert@example.com',
        'name' => 'Alert Test',
        'billing_country' => 'DE',
    ])->save();

    return $b;
}

function alertInvoice(TestBillable $billable): BillingInvoice
{
    $invoice = new BillingInvoice;
    $invoice->billable_type = $billable->getMorphClass();
    $invoice->billable_id = $billable->getKey();
    $invoice->mollie_payment_id = 'tr_alert_'.uniqid();
    $invoice->serial_number = 'IN-26'.random_int(100000, 999999);
    $invoice->invoice_kind = InvoiceKind::Subscription;
    $invoice->status = InvoiceStatus::Paid;
    $invoice->country = 'DE';
    $invoice->currency = 'EUR';
    $invoice->amount_net = 1000;
    $invoice->amount_vat = 190;
    $invoice->amount_gross = 1190;
    $invoice->line_items = [[
        'kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1,
        'unit_price_net' => 1000, 'amount_net' => 1000,
        'vat_rate' => 19.0, 'vat_amount' => 190, 'amount_gross' => 1190,
    ]];
    $invoice->refunded_net = 0;
    $invoice->save();

    return $invoice;
}

it('alerts the admins about a failed refund after the transaction is gone', function (): void {
    Notification::fake();

    $transactionLevels = [];
    MollieBilling::notifyAdminUsing(function () use (&$transactionLevels): array {
        // The recipient callback runs at send time, so it witnesses whether a
        // transaction is still open around the queued notification.
        $transactionLevels[] = DB::transactionLevel();

        return [new AnonymousNotifiable];
    });

    $billable = alertBillable();
    $invoice = alertInvoice($billable);

    // RefreshDatabase already holds a transaction around the test, so the
    // baseline is what "no refund transaction is open" looks like here.
    $baseline = DB::transactionLevel();

    expect(fn () => app(RefundInvoiceService::class)->refundFully($invoice, RefundReasonCode::Goodwill))
        ->toThrow(RuntimeException::class);

    // Sent with the refund transaction already gone — a queued job row written
    // inside the transaction the failure rolls back never reaches a worker.
    expect($transactionLevels)->toBe([$baseline]);

    Notification::assertSentTo(new AnonymousNotifiable, AdminRefundFailedNotification::class);

    // The refund itself must still be fully rolled back.
    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(0)
        ->and((int) $invoice->fresh()->refunded_net)->toBe(0);
});

it('does not alert when the refund succeeds', function (): void {
    Notification::fake();
    MollieBilling::notifyAdminUsing(fn (): array => [new AnonymousNotifiable]);

    $billable = alertBillable();
    $invoice = alertInvoice($billable);

    $this->app->bind(RefundInvoiceService::class, fn ($app): RefundInvoiceService => new class ($app->make(InvoiceService::class), $app->make(WalletUsageService::class)) extends RefundInvoiceService
    {
        protected function callMollieRefund(string $paymentId, int $grossCents, string $description, string $currency): ?string
        {
            return 'rf_ok';
        }
    });

    app(RefundInvoiceService::class)->refundFully($invoice, RefundReasonCode::Goodwill);

    Notification::assertNotSentTo(new AnonymousNotifiable, AdminRefundFailedNotification::class);
    expect((int) $invoice->fresh()->refunded_net)->toBe(1000);
});
