<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\RetryUsageOverageChargeJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * Operator actions have to agree with the screen they sit on: the target must be
 * one the list offers, and anything that moves money or access has to say why.
 */
beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans', [
        'pro' => [
            'name' => 'Pro',
            'tier' => 2,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => [
                    'base_price_net' => 1000,
                    'seat_price_net' => null,
                    'included_usages' => ['emails' => 100],
                    'usage_overage_prices' => ['emails' => 5],
                ],
                'yearly' => [
                    'base_price_net' => 10000,
                    'seat_price_net' => null,
                    'included_usages' => ['emails' => 1200],
                    'usage_overage_prices' => ['emails' => 5],
                ],
            ],
        ],
    ]);
});

function actionTargetBillable(array $attributes = []): TestBillable
{
    $b = new TestBillable;
    $b->forceFill(array_merge([
        'email' => 'target-'.uniqid().'@example.com',
        'name' => 'Target Co',
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_source' => SubscriptionSource::Local,
    ], $attributes))->save();

    return $b->refresh();
}

function actionTargetInvoice(TestBillable $billable, InvoiceKind $kind): BillingInvoice
{
    $invoice = new BillingInvoice;
    $invoice->billable_type = $billable->getMorphClass();
    $invoice->billable_id = $billable->getKey();
    $invoice->mollie_payment_id = 'tr_'.uniqid();
    $invoice->invoice_kind = $kind;
    $invoice->status = InvoiceStatus::Paid;
    $invoice->country = 'DE';
    $invoice->serial_number = strtoupper($kind->value).'-'.uniqid();
    $invoice->amount_net = 1000;
    $invoice->amount_vat = 190;
    $invoice->amount_gross = 1190;
    $invoice->refunded_net = 0;
    $invoice->line_items = [];
    $invoice->save();

    return $invoice;
}

// ---------------------------------------------------------------------------
// past-due: retry only what the list offers
// ---------------------------------------------------------------------------

it('dispatches a dunning retry for a past-due billable', function (): void {
    Queue::fake();
    $b = actionTargetBillable(['subscription_status' => SubscriptionStatus::PastDue]);

    Livewire::test('mollie-billing::admin.past-due.index')
        ->call('retry', $b->getKey())
        ->assertSet('error', null)
        ->assertSet('flash', fn (?string $f) => $f !== null);

    Queue::assertPushed(RetryUsageOverageChargeJob::class, 1);
});

it('refuses a dunning retry for a billable the past-due list never showed', function (): void {
    Queue::fake();
    $active = actionTargetBillable(['subscription_status' => SubscriptionStatus::Active]);

    $component = Livewire::test('mollie-billing::admin.past-due.index')
        ->call('retry', $active->getKey());

    // Queue first: a pushed job is the consequence, the flash only its echo.
    Queue::assertNothingPushed();

    $component
        ->assertSet('flash', null)
        ->assertSet('error', __('billing::admin.past_due.not_past_due', ['name' => $active->getBillingName()]));
});

// ---------------------------------------------------------------------------
// refunds: the detail modal shows credit notes only
// ---------------------------------------------------------------------------

it('opens the credit-note detail for a refund invoice', function (): void {
    $b = actionTargetBillable(['subscription_status' => SubscriptionStatus::Active]);
    $creditNote = actionTargetInvoice($b, InvoiceKind::Refund);

    Livewire::test('mollie-billing::admin.refunds.index')
        ->call('openDetail', $creditNote->id)
        ->assertSet('error', null)
        ->assertSet('showDetail', true)
        ->assertSet('detailInvoiceId', $creditNote->id);
});

it('refuses to render a regular invoice as a credit note', function (): void {
    $b = actionTargetBillable(['subscription_status' => SubscriptionStatus::Active]);
    $charge = actionTargetInvoice($b, InvoiceKind::Subscription);

    $component = Livewire::test('mollie-billing::admin.refunds.index')
        ->call('openDetail', $charge->id)
        ->assertSet('showDetail', false)
        ->assertSet('detailInvoiceId', null)
        ->assertSet('error', __('billing::admin.refunds.not_a_credit_note'));

    expect($component->html())->not->toContain((string) $charge->serial_number);
});

it('keeps the detail query itself scoped to credit notes', function (): void {
    $b = actionTargetBillable(['subscription_status' => SubscriptionStatus::Active]);
    $creditNote = actionTargetInvoice($b, InvoiceKind::Refund);

    $component = Livewire::test('mollie-billing::admin.refunds.index')
        ->call('openDetail', $creditNote->id)
        ->assertViewHas('detail', fn ($detail) => $detail !== null);

    // The kind is re-checked on every read, so a row that stops being a credit
    // note after the modal was opened drops out of the modal too.
    BillingInvoice::whereKey($creditNote->id)->update(['invoice_kind' => InvoiceKind::Subscription->value]);

    // A closure, not null: assertViewHas($key, null) only checks that the key exists.
    $component->call('$refresh')->assertViewHas('detail', fn ($detail) => $detail === null);
});

// ---------------------------------------------------------------------------
// bulk: bounds, catalog-checked usage type, recorded reason
// ---------------------------------------------------------------------------

it('refuses a bulk credit without a reason', function (): void {
    $b = actionTargetBillable(['subscription_status' => SubscriptionStatus::Active]);

    Livewire::test('mollie-billing::admin.bulk.index')
        ->set('planFilter', 'pro')
        ->set('creditType', 'emails')
        ->set('creditUnits', 500)
        ->set('creditReason', '   ')
        ->call('creditWallets')
        ->assertSet('flash', null)
        ->assertSet('error', __('billing::admin.bulk.reason_required'));

    expect($b->refresh()->getWallet('emails'))->toBeNull();
});

it('carries the operator reason into the audit trail of every credited billable', function (): void {
    $b = actionTargetBillable(['subscription_status' => SubscriptionStatus::Active]);

    Livewire::test('mollie-billing::admin.bulk.index')
        ->set('planFilter', 'pro')
        ->set('creditType', 'emails')
        ->set('creditUnits', 500)
        ->set('creditReason', '  Goodwill after the March outage (ticket #1234)  ')
        ->call('creditWallets')
        ->assertSet('error', null);

    $entry = $b->refresh()->billingAuditTrail()->where('event', 'wallet_credited')->first();

    expect($entry)->not->toBeNull();
    expect($entry->properties['replace']['reason'] ?? null)
        ->toBe('Goodwill after the March outage (ticket #1234)');
});

// The two guards below run before the reason check, so no reason is set: what
// must fail without them is the wallet write, not a missing form field.
it('refuses a bulk credit for a usage type that is not in the catalog', function (): void {
    $b = actionTargetBillable(['subscription_status' => SubscriptionStatus::Active]);

    Livewire::test('mollie-billing::admin.bulk.index')
        ->set('planFilter', 'pro')
        ->set('creditType', 'not_a_usage_type')
        ->set('creditUnits', 500)
        ->call('creditWallets')
        ->assertSet('flash', null)
        ->assertSet('error', __('billing::admin.bulk.unknown_usage_type', ['type' => 'not_a_usage_type']));

    expect($b->refresh()->getWallet('not_a_usage_type'))->toBeNull();
});

it('refuses a bulk credit whose unit count is out of proportion', function (): void {
    $b = actionTargetBillable(['subscription_status' => SubscriptionStatus::Active]);

    Livewire::test('mollie-billing::admin.bulk.index')
        ->set('planFilter', 'pro')
        ->set('creditType', 'emails')
        ->set('creditUnits', 1_000_001)
        ->call('creditWallets')
        ->assertSet('flash', null)
        ->assertSet('error', __('billing::admin.bulk.units_too_large', ['max' => 1_000_000]));

    expect($b->refresh()->getWallet('emails'))->toBeNull();
});

it('refuses a bulk trial extension beyond a year', function (): void {
    $trialing = actionTargetBillable([
        'subscription_status' => SubscriptionStatus::Trial,
        'trial_ends_at' => BillingTime::nowUtc()->addDays(2),
    ]);
    $before = $trialing->trial_ends_at->toIso8601String();

    Livewire::test('mollie-billing::admin.bulk.index')
        ->set('planFilter', 'pro')
        ->set('trialDays', 4000)
        ->call('extendTrials')
        ->assertSet('flash', null)
        ->assertSet('error', __('billing::admin.bulk.trial_days_too_large', ['max' => 365]));

    expect($trialing->refresh()->trial_ends_at->toIso8601String())->toBe($before);
});

it('refuses a bulk trial extension of zero or negative days', function (): void {
    $trialing = actionTargetBillable([
        'subscription_status' => SubscriptionStatus::Trial,
        'trial_ends_at' => BillingTime::nowUtc()->addDays(2),
    ]);
    $before = $trialing->trial_ends_at->toIso8601String();

    Livewire::test('mollie-billing::admin.bulk.index')
        ->set('planFilter', 'pro')
        ->set('trialDays', -30)
        ->call('extendTrials')
        ->assertSet('flash', null)
        ->assertSet('error', __('billing::admin.bulk.trial_days_positive'));

    expect($trialing->refresh()->trial_ends_at->toIso8601String())->toBe($before);
});

// ---------------------------------------------------------------------------
// wallet tab: the same catalog check per billable
// ---------------------------------------------------------------------------

it('refuses a per-billable credit for a usage type that is not in the catalog', function (): void {
    $b = actionTargetBillable(['subscription_status' => SubscriptionStatus::Active]);

    Livewire::test('mollie-billing::admin.billables.wallet-tab', ['billableId' => $b->getKey()])
        ->set('creditType', 'not_a_usage_type')
        ->set('creditUnits', 100)
        ->set('creditReason', 'goodwill')
        ->call('credit')
        ->assertSet('flash', null)
        ->assertSet('error', __('billing::admin.billables.wallet.flash.unknown_usage_type', ['type' => 'not_a_usage_type']));

    expect($b->refresh()->getWallet('not_a_usage_type'))->toBeNull();
});
