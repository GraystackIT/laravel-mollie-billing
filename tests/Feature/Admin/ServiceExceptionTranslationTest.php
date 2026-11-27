<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\InvalidSubscriptionStateException;
use GraystackIT\MollieBilling\Exceptions\PastDueExpansionNotAllowedException;
use GraystackIT\MollieBilling\Exceptions\SubscriptionChangeInProgressException;
use GraystackIT\MollieBilling\Exceptions\TranslatableException;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ExceptionMessage;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Livewire;

/*
 * A service refuses with an exception, and the admin screens used to show that exception's
 * English developer message as it came — to a German operator as well. The refusal now
 * carries a translation key; the screen shows the sentence in the operator's language, and
 * the exception message (what the log reads) stays English.
 */

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro', 'tier' => 2, 'included_seats' => 1, 'feature_keys' => [], 'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => []]],
    ]);
});

function excTrBillable(SubscriptionStatus $status = SubscriptionStatus::Active): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => 'exc-tr-'.uniqid().'@example.com',
        'name' => 'Exception Co',
        'billing_country' => 'DE',
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => $status,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
    ])->save();

    return $b;
}

function excTrInvoice(TestBillable $billable, InvoiceStatus $status): BillingInvoice
{
    $invoice = new BillingInvoice;
    $invoice->billable_type = $billable->getMorphClass();
    $invoice->billable_id = $billable->getKey();
    $invoice->mollie_payment_id = 'tr_'.uniqid();
    $invoice->invoice_kind = 'subscription';
    $invoice->status = $status;
    $invoice->country = 'DE';
    $invoice->amount_net = 10000;
    $invoice->amount_vat = 1900;
    $invoice->amount_gross = 11900;
    $invoice->refunded_net = 0;
    $invoice->line_items = [];
    $invoice->save();

    return $invoice;
}

it('tells a German operator in German that there is nothing to cancel', function (): void {
    app()->setLocale('de');
    $b = excTrBillable();

    $tab = Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $b->getKey()])
        ->call('openCancel', true)
        ->set('cancelNote', 'Telefonisch gewünscht');

    $b->forceFill(['subscription_status' => SubscriptionStatus::Expired])->save();

    $tab->call('confirmCancel')
        ->assertSet('error', 'Es gibt kein Abonnement, das gekündigt werden kann.');

    // The log keeps reading English.
    expect(fn () => app(CancelSubscription::class)->handle($b->fresh(), true, 'x'))
        ->toThrow(InvalidSubscriptionStateException::class, 'There is no subscription to cancel.');
});

it('tells a German operator in German that only a paid invoice can be refunded', function (): void {
    app()->setLocale('de');
    $b = excTrBillable();
    $invoice = excTrInvoice($b, InvoiceStatus::Open);

    Livewire::test('mollie-billing::admin.billables.invoices-tab', ['billableId' => $b->getKey()])
        ->call('openRefund', $invoice->id)
        ->set('refundAmount', '10')
        ->call('refund')
        ->assertSet('error', 'Nur bezahlte Rechnungen können erstattet werden.');
});

it('says a refund is already running instead of reporting a failure', function (): void {
    app()->setLocale('de');
    Exceptions::fake();
    $b = excTrBillable();
    $invoice = excTrInvoice($b, InvoiceStatus::Paid);

    $held = Cache::lock('billing:invoice-refund:'.$invoice->id, 120);
    expect($held->get())->toBeTrue();

    try {
        Livewire::test('mollie-billing::admin.billables.invoices-tab', ['billableId' => $b->getKey()])
            ->call('openRefund', $invoice->id)
            ->set('refundAmount', '10')
            ->call('refund')
            ->assertSet('error', 'Für diese Rechnung läuft bereits eine Erstattung. Bitte erneut versuchen, sobald sie abgeschlossen ist.');
    } finally {
        $held->release();
    }

    // A refusal of the state, not an error: nothing for the error tracker.
    Exceptions::assertNothingReported();
    expect((int) $invoice->fresh()->refunded_net)->toBe(0);
});

it('tells a German operator in German that the coupon code is taken', function (): void {
    app()->setLocale('de');
    Coupon::create([
        'code' => 'WINTER26', 'name' => 'Winter', 'type' => 'single_payment',
        'discount_type' => 'percentage', 'discount_value' => 10, 'active' => true,
    ]);

    Livewire::test('mollie-billing::admin.coupons.create')
        ->set('type', 'plan_waiver')
        ->set('code', 'winter26')
        ->set('waiver_periods', 3)
        ->call('save')
        ->assertSet('error', 'Ein Coupon mit dem Code WINTER26 existiert bereits.');
});

it('tells a German operator in German why a trial cannot be extended', function (): void {
    app()->setLocale('de');
    $b = excTrBillable(SubscriptionStatus::Cancelled);
    $b->forceFill(['trial_ends_at' => BillingTime::nowUtc()->addDays(3)])->save();

    Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $b->getKey()])
        ->set('trialUntil', BillingTime::nowUtc()->addDays(20)->format('Y-m-d'))
        ->call('extendTrial')
        ->assertSet('error', 'Die Testphase kann nicht verlängert werden, solange das Abonnement den Status „Gekündigt“ hat. Stattdessen das Abonnement fortsetzen.');
});

it('keeps the customer-facing exception messages English and translates them for the screen', function (): void {
    app()->setLocale('de');
    $b = excTrBillable();

    $e = PastDueExpansionNotAllowedException::booking($b);

    expect($e->getMessage())->toBe(app('translator')->get('billing::portal.error_past_due_booking', [], 'en'))
        ->and(ExceptionMessage::for($e))->toBe(app('translator')->get('billing::portal.error_past_due_booking', [], 'de'))
        ->and(ExceptionMessage::for($e))->not->toBe($e->getMessage());
});

it('translates a package refusal and falls back for anything else', function (): void {
    app()->setLocale('de');
    $b = excTrBillable();

    $inProgress = new SubscriptionChangeInProgressException($b);

    expect($inProgress)->toBeInstanceOf(TranslatableException::class)
        ->and($inProgress->getMessage())->toBe('Another subscription change for this billable is already in progress.')
        ->and(ExceptionMessage::for($inProgress))->toBe((string) __('billing::exceptions.subscription_change_in_progress'))
        ->and(ExceptionMessage::for($inProgress))->not->toContain('billing::');

    // Unknown: the customer gets the generic sentence, the operator the sentence plus the detail.
    $foreign = new RuntimeException('Something deep down broke.');

    expect(ExceptionMessage::for($foreign))->toBe('Die Aktion konnte nicht ausgeführt werden.')
        ->and(ExceptionMessage::forOperator($foreign))->toBe('Die Aktion wurde abgelehnt: Something deep down broke.');

    // A package exception thrown without a key (an app's own `throw`) falls back the same way.
    expect(ExceptionMessage::forOperator(new InvalidSubscriptionStateException('App refusal.')))
        ->toBe('Die Aktion wurde abgelehnt: App refusal.');
});
