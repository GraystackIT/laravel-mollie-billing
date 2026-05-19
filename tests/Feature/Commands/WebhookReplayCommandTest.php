<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingProcessedWebhook;
use GraystackIT\MollieBilling\Support\BillingTime;

class ReplayFakeWebhookController extends MollieWebhookController
{
    public static int $invocations = 0;
    public static ?object $nextPayment = null;

    public function __invoke(\Illuminate\Http\Request $request): \Illuminate\Http\Response
    {
        self::$invocations++;
        return parent::__invoke($request);
    }

    protected function fetchPayment(string $paymentId): object
    {
        if (self::$nextPayment === null) {
            throw new \RuntimeException('No payment stub configured.');
        }
        return self::$nextPayment;
    }
}

beforeEach(function (): void {
    ReplayFakeWebhookController::$invocations = 0;
    ReplayFakeWebhookController::$nextPayment = null;
    $this->app->bind(MollieWebhookController::class, ReplayFakeWebhookController::class);
});

it('refuses to run in production', function (): void {
    app()['env'] = 'production';

    $this->artisan('billing:webhook-replay', ['paymentId' => 'tr_x'])
        ->expectsOutputToContain('disabled in production')
        ->assertFailed();

    app()['env'] = 'testing';
});

it('deletes the existing processed-webhook reservation and re-invokes the controller', function (): void {
    ReplayFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_replay',
        'status' => 'paid',
        'amount' => (object) ['value' => '0.00', 'currency' => 'EUR'],
        'metadata' => [],
        'subscriptionId' => null,
    ];

    // Pre-existing final record from a previous "successful" delivery
    BillingProcessedWebhook::create([
        'mollie_payment_id' => 'tr_replay',
        'event_signature' => BillingProcessedWebhook::finalSignature('tr_replay', 'paid'),
        'received_at' => BillingTime::nowUtc(),
        'processed_at' => BillingTime::nowUtc(),
    ]);

    $this->artisan('billing:webhook-replay', ['paymentId' => 'tr_replay'])
        ->assertSuccessful();

    expect(ReplayFakeWebhookController::$invocations)->toBe(1);
});

it('with --force-reset deletes the existing invoice for the payment', function (): void {
    ReplayFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_force',
        'status' => 'paid',
        'amount' => (object) ['value' => '0.00', 'currency' => 'EUR'],
        'metadata' => [],
        'subscriptionId' => null,
    ];

    $invoice = BillingInvoice::create([
        'mollie_payment_id' => 'tr_force',
        'invoice_kind' => 'subscription',
        'status' => 'paid',
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 0,
        'amount_vat' => 0,
        'amount_gross' => 0,
        'line_items' => [],
        'billable_type' => 'X',
        'billable_id' => 1,
    ]);

    $this->artisan('billing:webhook-replay', [
        'paymentId' => 'tr_force',
        '--force-reset' => true,
    ])->assertSuccessful();

    expect(BillingInvoice::find($invoice->id))->toBeNull();
});
