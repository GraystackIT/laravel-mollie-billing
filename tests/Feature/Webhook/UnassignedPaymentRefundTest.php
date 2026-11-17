<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Jobs\RefundUnassignedPaymentsJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingProcessedWebhook;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPaidWithoutBillableNotification;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Laravel\Facades\Mollie;

/*
 * A paid payment whose metadata names nobody.
 *
 * It is reported once and leaves a trace row. Fourteen days later, still unassigned, it
 * is refunded at Mollie — nobody has to do it by hand. If its billable exists by then it
 * is booked as the ordinary payment it is.
 */
class UnassignedPaymentFakeWebhookController extends MollieWebhookController
{
    /** @var array<string, object> */
    public static array $payments = [];

    protected function fetchPayment(string $paymentId): object
    {
        return self::$payments[$paymentId] ?? throw new RuntimeException("No stub for {$paymentId}.");
    }
}

/** What the fake Mollie was asked to refund: payment id => list of amounts. */
final class UnassignedPaymentMollie
{
    /** @var list<array{payment: string, value: string, key: ?string}> */
    public static array $refunds = [];

    public static int $refuse = 0;

    public static ?string $key = null;
}

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');

    UnassignedPaymentFakeWebhookController::$payments = [];
    UnassignedPaymentMollie::$refunds = [];
    UnassignedPaymentMollie::$refuse = 0;
    UnassignedPaymentMollie::$key = null;
    $this->app->bind(MollieWebhookController::class, UnassignedPaymentFakeWebhookController::class);

    Notification::fake();
    MollieBilling::notifyAdminUsing(fn (): array => [new AnonymousNotifiable]);

    Mollie::shouldReceive('setIdempotencyKey')->andReturnUsing(function (?string $key) {
        UnassignedPaymentMollie::$key = $key;

        return Mollie::getFacadeRoot();
    });
    Mollie::shouldReceive('api')->andThrow(new RuntimeException('no client in tests'));
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) {
        if ($request instanceof GetPaymentRequest) {
            $id = (new ReflectionClass($request))->getProperty('id');
            $id->setAccessible(true);

            return UnassignedPaymentFakeWebhookController::$payments[$id->getValue($request)];
        }

        if ($request instanceof CreatePaymentRefundRequest) {
            if (UnassignedPaymentMollie::$refuse > 0) {
                UnassignedPaymentMollie::$refuse--;

                throw new RuntimeException('Mollie is unavailable');
            }

            $class = new ReflectionClass($request);
            $payment = $class->getProperty('paymentId');
            $payment->setAccessible(true);
            $amount = $class->getProperty('amount');
            $amount->setAccessible(true);

            UnassignedPaymentMollie::$refunds[] = [
                'payment' => $payment->getValue($request),
                'value' => $amount->getValue($request)->value,
                'key' => UnassignedPaymentMollie::$key,
            ];

            return (object) ['id' => 're_unassigned_'.count(UnassignedPaymentMollie::$refunds)];
        }

        return (object) ['id' => 'unexpected'];
    });
});

afterEach(function (): void {
    Carbon::setTestNow();
    MollieBilling::notifyAdminUsing(fn (): array => []);
});

function unassignedPayment(string $id, string $gross = '29.00', ?string $refunded = null, array $metadata = []): object
{
    return UnassignedPaymentFakeWebhookController::$payments[$id] = (object) [
        'id' => $id,
        'status' => 'paid',
        'amount' => (object) ['value' => $gross, 'currency' => 'EUR'],
        'amountRefunded' => $refunded !== null ? (object) ['value' => $refunded, 'currency' => 'EUR'] : null,
        'paidAt' => now()->toIso8601String(),
        'subscriptionId' => null,
        'customerId' => 'cst_nobody',
        'mandateId' => 'mdt_nobody',
        'countryCode' => 'DE',
        'metadata' => $metadata + [
            'billable_type' => TestBillable::class,
            'billable_id' => '987654',
        ],
    ];
}

function unassignedRunNightly(string $at): void
{
    Carbon::setTestNow(Carbon::parse($at, 'UTC'));
    app()->call([new RefundUnassignedPaymentsJob, 'handle']);
}

it('reports an unassignable payment once and leaves a trace', function (): void {
    Carbon::setTestNow(Carbon::parse('2027-03-01 10:00:00', 'UTC'));
    unassignedPayment('tr_nobody');

    $this->postJson(route('billing.webhook'), ['id' => 'tr_nobody'])->assertStatus(200);
    $this->postJson(route('billing.webhook'), ['id' => 'tr_nobody'])->assertStatus(200);

    $trace = BillingProcessedWebhook::query()->where('event_signature', BillingProcessedWebhook::unassignedSignature('tr_nobody'))->sole();

    expect($trace->processed_at)->toBeNull()
        ->and($trace->received_at->toDateString())->toBe('2027-03-01');

    Notification::assertSentToTimes(new AnonymousNotifiable, AdminPaidWithoutBillableNotification::class, 1);
});

it('refunds the payment at Mollie after fourteen days, and not a day earlier', function (): void {
    Carbon::setTestNow(Carbon::parse('2027-03-01 10:00:00', 'UTC'));
    unassignedPayment('tr_nobody');
    $this->postJson(route('billing.webhook'), ['id' => 'tr_nobody'])->assertStatus(200);

    unassignedRunNightly('2027-03-14 04:15:00');
    expect(UnassignedPaymentMollie::$refunds)->toBe([]);

    unassignedRunNightly('2027-03-15 10:00:01');

    expect(UnassignedPaymentMollie::$refunds)->toHaveCount(1)
        ->and(UnassignedPaymentMollie::$refunds[0]['payment'])->toBe('tr_nobody')
        ->and(UnassignedPaymentMollie::$refunds[0]['value'])->toBe('29.00')
        // Keyed, so a run whose answer was lost is replayed rather than paid twice.
        ->and(UnassignedPaymentMollie::$refunds[0]['key'])->toBe('unassigned-refund:tr_nobody:2900')
        ->and(BillingProcessedWebhook::query()->where('event_signature', BillingProcessedWebhook::unassignedSignature('tr_nobody'))->sole()->processed_at)->not->toBeNull();

    // Done is done: later runs, and the refund's own webhook, change nothing.
    unassignedRunNightly('2027-03-16 04:15:00');
    UnassignedPaymentFakeWebhookController::$payments['tr_nobody']->amountRefunded = (object) ['value' => '29.00', 'currency' => 'EUR'];
    $this->postJson(route('billing.webhook'), ['id' => 'tr_nobody'])->assertStatus(200);

    expect(UnassignedPaymentMollie::$refunds)->toHaveCount(1);
    Notification::assertSentToTimes(new AnonymousNotifiable, AdminPaidWithoutBillableNotification::class, 1);
    Notification::assertSentToTimes(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class, 0);
});

it('refunds only what has not gone back already', function (): void {
    Carbon::setTestNow(Carbon::parse('2027-03-01 10:00:00', 'UTC'));
    unassignedPayment('tr_partly', '29.00', '10.00');
    $this->postJson(route('billing.webhook'), ['id' => 'tr_partly'])->assertStatus(200);

    unassignedRunNightly('2027-03-16 04:15:00');

    expect(UnassignedPaymentMollie::$refunds)->toHaveCount(1)
        ->and(UnassignedPaymentMollie::$refunds[0]['value'])->toBe('19.00');
});

it('books the payment instead of refunding it when its billable exists by then', function (): void {
    Carbon::setTestNow(Carbon::parse('2027-03-01 10:00:00', 'UTC'));

    $payment = unassignedPayment('tr_early', '11.90', null, [
        'type' => 'overage',
        'line_items' => [[
            'kind' => 'overage', 'type' => 'Tokens', 'label' => 'Tokens', 'quantity' => 100,
            'unit_price' => 10, 'unit_price_net' => 10, 'total_net' => 1000,
        ]],
    ]);
    $this->postJson(route('billing.webhook'), ['id' => 'tr_early'])->assertStatus(200);

    // The billable the payment names is created a week later.
    $billable = TestBillable::create(['name' => 'Late Co', 'email' => 'late@x.test', 'billing_country' => 'DE']);
    $payment->metadata['billable_id'] = (string) $billable->getKey();

    unassignedRunNightly('2027-03-16 04:15:00');

    expect(UnassignedPaymentMollie::$refunds)->toBe([])
        ->and((int) BillingInvoice::query()->where('mollie_payment_id', 'tr_early')->sole()->amount_gross)->toBe(1190)
        ->and(BillingProcessedWebhook::query()->where('event_signature', BillingProcessedWebhook::unassignedSignature('tr_early'))->sole()->processed_at)->not->toBeNull();
});

it('tries a refused refund again the next night and tells an operator only when it keeps failing', function (): void {
    Carbon::setTestNow(Carbon::parse('2027-03-01 10:00:00', 'UTC'));
    unassignedPayment('tr_stuck');
    $this->postJson(route('billing.webhook'), ['id' => 'tr_stuck'])->assertStatus(200);

    UnassignedPaymentMollie::$refuse = 4;

    // Nights one to three of failing: logged, retried, nobody mailed.
    unassignedRunNightly('2027-03-15 10:00:01');
    unassignedRunNightly('2027-03-16 04:15:00');
    unassignedRunNightly('2027-03-17 04:15:00');
    Notification::assertSentToTimes(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class, 0);

    // Three days past the fourteen: one mail.
    unassignedRunNightly('2027-03-18 10:00:01');
    Notification::assertSentToTimes(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class, 1);

    // The night Mollie takes it, it is done — and no second mail was sent in between.
    unassignedRunNightly('2027-03-19 04:15:00');

    expect(UnassignedPaymentMollie::$refunds)->toHaveCount(1)
        ->and(UnassignedPaymentMollie::$refunds[0]['value'])->toBe('29.00');
    Notification::assertSentToTimes(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class, 1);
});
