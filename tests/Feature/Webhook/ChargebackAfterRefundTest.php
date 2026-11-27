<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Webhook\ChargebackHandler;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
 * A chargeback on a payment that was already refunded pays the same money back a second
 * time — the refund, then the bank's reversal, plus Mollie's chargeback fee.
 *
 * There is nothing (or only the rest) left to credit, so no credit note is written for the
 * part that was refunded already; that is right, the books are not wrong. But the handler
 * only logged a warning: no operator mail, no event, no record — so nobody disputed the
 * chargeback with the proof of the refund, which is the one way to get the money back.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
});

function chargebackAfterRefundBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Twice Paid Co', 'email' => uniqid('cbar-').'@x.test', 'billing_country' => 'DE']);
    $billable->forceFill(['subscription_status' => SubscriptionStatus::Active])->save();

    return $billable->refresh();
}

/** 100.00 net, 19 % → 119.00, with `$refundedNet` of it already refunded. */
function chargebackAfterRefundInvoice(TestBillable $billable, int $refundedNet): BillingInvoice
{
    $invoice = BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_cbar',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'DE',
        'currency' => 'EUR',
        'amount_net' => 10000,
        'amount_vat' => 1900,
        'amount_gross' => 11900,
        'serial_number' => 'IN-'.uniqid(),
        'period_start' => BillingTime::nowUtc()->subDays(5),
        'period_end' => BillingTime::nowUtc()->addDays(25),
        'line_items' => [[
            'kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1,
            'unit_price_net' => 10000, 'amount_net' => 10000,
            'vat_rate' => 19.0, 'vat_amount' => 1900, 'amount_gross' => 11900,
        ]],
    ])->refresh();

    app(InvoiceService::class)->createCreditNote(
        $invoice,
        $refundedNet,
        externalReference: ['key' => 'mollie_refund_id', 'value' => 're_earlier'],
    );
    $invoice->forceFill(['refunded_net' => $refundedNet])->save();

    return $invoice->refresh();
}

function chargebackAfterRefundPayment(string $chargedBack): object
{
    return new class($chargedBack)
    {
        public string $id = 'tr_cbar';

        public string $status = 'paid';

        public function __construct(private string $chargedBack)
        {
        }

        public function chargebacks(): array
        {
            return [(object) [
                'id' => 'chb_1',
                'amount' => (object) ['value' => $this->chargedBack, 'currency' => 'EUR'],
                'reversedAt' => null,
            ]];
        }

        public function refunds(): array
        {
            return [];
        }
    };
}

/** @return list<object> the events of the given class dispatched so far */
function chargebackAfterRefundEvents(ArrayObject $seen): array
{
    return array_values(array_filter(
        $seen->getArrayCopy(),
        fn (object $event): bool => $event::class === 'GraystackIT\\MollieBilling\\Events\\ChargebackOnRefundedPayment',
    ));
}

it('tells the operator, once, about a chargeback on a payment that was refunded in full', function (): void {
    Notification::fake();
    $billable = chargebackAfterRefundBillable();
    MollieBilling::notifyAdminUsing(fn (): array => [$billable]);

    $invoice = chargebackAfterRefundInvoice($billable, 10000);

    $seen = new ArrayObject;
    Event::listen('GraystackIT\\MollieBilling\\Events\\*', fn (string $name, array $payload) => $seen->append($payload[0]));

    app(ChargebackHandler::class)->handle(chargebackAfterRefundPayment('119.00'), $billable);

    // Nothing left to credit: no further credit note, and not past due over a payment that
    // was given back anyway.
    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(1)
        ->and($billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active);

    Notification::assertSentTo($billable, AdminPlanChangeFailedNotification::class, function (AdminPlanChangeFailedNotification $n): bool {
        return ($n->context['mollie_chargeback_id'] ?? null) === 'chb_1'
            && ($n->context['mollie_payment_id'] ?? null) === 'tr_cbar'
            && ($n->context['chargeback_amount_cents'] ?? null) === 11900
            && ($n->context['uncredited_amount_cents'] ?? null) === 11900;
    });

    $events = chargebackAfterRefundEvents($seen);
    expect($events)->toHaveCount(1)
        ->and($events[0]->mollieChargebackId)->toBe('chb_1')
        ->and($events[0]->original->getKey())->toBe($invoice->getKey())
        ->and($events[0]->chargebackAmount)->toBe(11900)
        ->and($events[0]->uncreditedAmount)->toBe(11900);

    $audit = \GraystackIT\MollieBilling\Support\BillingAuditEntry::model()::query()->where('event', 'chargeback_on_refunded_payment')->sole();
    expect($audit->description)->toBe('audit.chargeback_on_refunded_payment');

    // Delivered again: already reported.
    app(ChargebackHandler::class)->handle(chargebackAfterRefundPayment('119.00'), $billable);

    Notification::assertSentToTimes($billable, AdminPlanChangeFailedNotification::class, 1);
    expect(chargebackAfterRefundEvents($seen))->toHaveCount(1)
        ->and(\GraystackIT\MollieBilling\Support\BillingAuditEntry::model()::query()->where('event', 'chargeback_on_refunded_payment')->count())->toBe(1);
});

it('names the part of a chargeback that had already been refunded', function (): void {
    Notification::fake();
    $billable = chargebackAfterRefundBillable();
    MollieBilling::notifyAdminUsing(fn (): array => [$billable]);

    // 20.00 net (23.80) refunded, then the bank pulls back the whole 119.00.
    $invoice = chargebackAfterRefundInvoice($billable, 2000);

    $seen = new ArrayObject;
    Event::listen('GraystackIT\\MollieBilling\\Events\\*', fn (string $name, array $payload) => $seen->append($payload[0]));

    app(ChargebackHandler::class)->handle(chargebackAfterRefundPayment('119.00'), $billable);

    // The rest is credited as before, and the billable is past due.
    expect($invoice->fresh()->effectiveRefundedNet())->toBe(10000)
        ->and($billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::PastDue);

    Notification::assertSentTo($billable, AdminPlanChangeFailedNotification::class, function (AdminPlanChangeFailedNotification $n): bool {
        return ($n->context['mollie_chargeback_id'] ?? null) === 'chb_1'
            && ($n->context['uncredited_amount_cents'] ?? null) === 2380;
    });

    $events = chargebackAfterRefundEvents($seen);
    expect($events)->toHaveCount(1)
        ->and($events[0]->uncreditedAmount)->toBe(2380);

    app(ChargebackHandler::class)->handle(chargebackAfterRefundPayment('119.00'), $billable);
    expect(chargebackAfterRefundEvents($seen))->toHaveCount(1);
});

it('reports nothing extra for a chargeback on a payment nothing was refunded of', function (): void {
    Notification::fake();
    $billable = chargebackAfterRefundBillable();
    MollieBilling::notifyAdminUsing(fn (): array => [$billable]);

    $invoice = BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_cbar',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'DE',
        'currency' => 'EUR',
        'amount_net' => 10000,
        'amount_vat' => 1900,
        'amount_gross' => 11900,
        'serial_number' => 'IN-'.uniqid(),
        'line_items' => [[
            'kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1,
            'unit_price_net' => 10000, 'amount_net' => 10000,
            'vat_rate' => 19.0, 'vat_amount' => 1900, 'amount_gross' => 11900,
        ]],
    ]);

    $seen = new ArrayObject;
    Event::listen('GraystackIT\\MollieBilling\\Events\\*', fn (string $name, array $payload) => $seen->append($payload[0]));

    app(ChargebackHandler::class)->handle(chargebackAfterRefundPayment('119.00'), $billable);

    expect($invoice->fresh()->effectiveRefundedNet())->toBe(10000)
        ->and(chargebackAfterRefundEvents($seen))->toBe([]);

    // Only the ordinary past-due mail.
    Notification::assertSentToTimes($billable, AdminPlanChangeFailedNotification::class, 1);
});

/*
 * The report runs before markPastDue(). A throwing listener or mail transport left
 * handle() early with a 500; the re-delivery then found the chargeback booked (its credit
 * note carries the id), booked nothing, and returned before markPastDue() — the customer
 * kept Active access on money the bank had pulled back.
 */
it('puts the billable past due although the report of a chargeback on a refunded payment fails', function (string $failing): void {
    Notification::fake();
    Event::fake([\GraystackIT\MollieBilling\Events\PaymentFailed::class]);
    $billable = chargebackAfterRefundBillable();

    $notifyCalls = 0;
    MollieBilling::notifyAdminUsing(function () use ($failing, &$notifyCalls, $billable): array {
        $notifyCalls++;

        if ($failing === 'mail' && $notifyCalls === 1) {
            throw new RuntimeException('SMTP timeout');
        }

        return [$billable];
    });

    if ($failing === 'listener') {
        Event::listen(\GraystackIT\MollieBilling\Events\ChargebackOnRefundedPayment::class, function (): void {
            throw new RuntimeException('CRM push failed');
        });
    }

    // 20.00 net refunded, then the whole 119.00 charged back: the rest is credited and the
    // part refunded already is reported.
    chargebackAfterRefundInvoice($billable, 2000);

    // The delivery may fail; Mollie then re-delivers. Either way the billable ends up
    // past due once.
    try {
        app(ChargebackHandler::class)->handle(chargebackAfterRefundPayment('119.00'), $billable);
    } catch (RuntimeException) {
        app(ChargebackHandler::class)->handle(chargebackAfterRefundPayment('119.00'), $billable->fresh());
    }

    expect($billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::PastDue)
        ->and($billable->fresh()->getBillingSubscriptionMeta()['chargebacks_on_refunded_payment'] ?? null)->toBe(['chb_1']);

    Event::assertDispatchedTimes(\GraystackIT\MollieBilling\Events\PaymentFailed::class, 1);
})->with(['listener', 'mail']);
