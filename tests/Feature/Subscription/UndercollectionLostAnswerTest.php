<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Events\RenewalUndercollected;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Notifications\UndercollectionChargedNotification;
use GraystackIT\MollieBilling\Services\Billing\UndercollectionCharge;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\ChangeMatrixWorld as World;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/**
 * The balance of an undercollected period is its own payment. When the answer to its
 * creation is lost — Mollie created it, the response never came back — the throw looks
 * exactly like a refusal. It used to be read as one: the case was recorded "Mollie refused
 * to create the payment", the operators were told to charge it by hand, and the payment
 * Mollie was collecting all the same was booked from fallback lines without a period, while
 * the subscription tab kept saying "not possible" next to a paid balance. An operator who
 * followed the mail charged the customer twice.
 *
 * An unanswered question is not "none" (the rule `InvoiceService::createCharge()` follows
 * for a lost prorata payment): Mollie's payment list is asked for the case's payment.
 */
beforeEach(function (): void {
    World::boot();
    config()->set('mollie-billing.billable_model', TestBillable::class);
});

function lostAnswerRecipients(): TestBillable
{
    $admin = TestBillable::create(['name' => 'Operator', 'email' => uniqid('operator-').'@x.test']);
    MollieBilling::notifyAdminUsing(fn (): array => [$admin]);
    MollieBilling::notifyBillingAdminsUsing(fn ($billable): array => [$billable]);

    return $admin;
}

/** @return array{0: string, 1: string, 2: array<string, mixed>}|null */
function lostAnswerBackCharge(): ?array
{
    foreach (World::$payments as $id => $payment) {
        if (isset($payment['metadata'][UndercollectionCharge::METADATA_KEY])) {
            return [$id, $payment['value'], $payment['metadata']];
        }
    }

    return null;
}

it('takes over the balance payment whose creation answer was lost, instead of reporting it as refused', function (): void {
    $admin = lostAnswerRecipients();
    Event::fake([RenewalUndercollected::class]);

    // A seat added on 31 Jan after Mollie created the February debit; that debit settles
    // on 1 Feb, and the next payment the package creates is the balance — whose answer is lost.
    $billable = World::subscription('none', 'pro', 2, [], at: '2026-01-31 12:00:00');
    $createdAt = World::$heldValue;
    $createdFor = World::$heldMetadata;
    World::change($billable, ['seats' => 3]);

    test()->travelTo(Carbon::parse('2026-02-01 09:01:00', 'UTC'));
    World::$acceptThenTimeout = true;
    World::deliver($billable, 'tr_cm_early', 'paid', (string) $createdAt, $createdFor, '2026-02-01T09:00:00+00:00', recurring: true);
    $billable->refresh();

    $charge = lostAnswerBackCharge();
    expect($charge)->not->toBeNull();

    $case = UndercollectionCharge::cases($billable)[0];

    // Mollie holds it: it is the case's payment, announced like any other.
    expect($case['status'])->toBe(UndercollectionCharge::STATUS_PENDING)
        ->and($case['payment_id'])->toBe($charge[0]);
    Notification::assertNotSentTo($admin, AdminPlanChangeFailedNotification::class);
    Notification::assertSentTo($billable, UndercollectionChargedNotification::class);
    Event::assertDispatched(RenewalUndercollected::class, fn ($e): bool => $e->chargePaymentId === $charge[0]);

    World::deliver($billable, $charge[0], 'paid', $charge[1], $charge[2]);

    $invoice = BillingInvoice::query()->where('mollie_payment_id', $charge[0])->sole();

    expect(UndercollectionCharge::cases($billable->refresh())[0]['status'])->toBe(UndercollectionCharge::STATUS_PAID)
        ->and($invoice->period_start)->not->toBeNull();
});

it('says the balance payment is unknown, not refused, when Mollie cannot be asked either — and adopts it when it arrives', function (): void {
    $admin = lostAnswerRecipients();

    $billable = World::subscription('none', 'pro', 2, [], at: '2026-01-31 12:00:00');
    $createdAt = World::$heldValue;
    $createdFor = World::$heldMetadata;
    World::change($billable, ['seats' => 3]);

    test()->travelTo(Carbon::parse('2026-02-01 09:01:00', 'UTC'));
    World::$acceptThenTimeout = true;
    World::$failPaymentLists = true;
    World::deliver($billable, 'tr_cm_early', 'paid', (string) $createdAt, $createdFor, '2026-02-01T09:00:00+00:00', recurring: true);
    World::$failPaymentLists = false;
    $billable->refresh();

    $charge = lostAnswerBackCharge();
    $case = UndercollectionCharge::cases($billable)[0];

    expect($charge)->not->toBeNull()
        ->and($case['obstacle'])->toBe('payment_unknown');
    Notification::assertSentTo($admin, AdminPlanChangeFailedNotification::class, fn ($n): bool => ($n->context['obstacle'] ?? null) === 'payment_unknown');

    // Mollie collects it all the same: booked as the case's balance, with its period.
    World::deliver($billable, $charge[0], 'paid', $charge[1], $charge[2]);

    $invoice = BillingInvoice::query()->where('mollie_payment_id', $charge[0])->sole();
    $case = UndercollectionCharge::cases($billable->refresh())[0];

    expect($case['status'])->toBe(UndercollectionCharge::STATUS_PAID)
        ->and($case['payment_id'])->toBe($charge[0])
        ->and($invoice->period_start)->not->toBeNull();
});
