<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\AuditCategory;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Events\MandateUpdated;
use GraystackIT\MollieBilling\Events\OverageChargeFailed;
use GraystackIT\MollieBilling\Events\PaymentFailed;
use GraystackIT\MollieBilling\Events\PlanChanged;
use GraystackIT\MollieBilling\Events\SubscriptionUpdated;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Support\BillingAuditEntry;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Schema;

function auditBillable(): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create(['name' => 'Acme', 'email' => 'audit@example.test']);

    return $b;
}

/** @return \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model> */
function auditRows(): \Illuminate\Database\Eloquent\Collection
{
    return BillingAuditEntry::model()::query()->orderBy('id')->get();
}

it('records one row per billing event with the translation key as description', function (): void {
    $billable = auditBillable();

    event(new PlanChanged($billable, 'starter', 'pro', 'monthly'));

    $rows = auditRows();
    expect($rows)->toHaveCount(1);

    $row = $rows->first();
    expect($row->log_name)->toBe('billing')
        ->and($row->event)->toBe('plan_changed')
        ->and($row->subject_type)->toBe($billable->getMorphClass())
        ->and((string) $row->subject_id)->toBe((string) $billable->getKey());

    // The stored description must be the *key*, never a rendered sentence.
    expect($row->description)->toBe('audit.plan_changed');

    expect($row->properties['category'])->toBe(AuditCategory::Subscription->value)
        ->and($row->properties['replace'])->toMatchArray([
            'old_plan' => 'starter',
            'new_plan' => 'pro',
            'interval' => 'monthly',
        ]);
});

it('stores the description key verbatim without spatie placeholder substitution', function (): void {
    // Spatie's ActivityLogger runs preg_replace_callback('/:[a-z0-9._-]+(?<![.])/i')
    // over the description before saving. A namespaced key like
    // "billing::audit.plan_changed" would be mangled; "audit.plan_changed" is safe.
    // This test locks that in.
    event(new PlanChanged(auditBillable(), 'starter', 'pro', 'monthly'));

    expect(auditRows()->first()->description)
        ->toBe('audit.plan_changed')
        ->not->toContain('::');
});

it('attributes events without an authenticated user to the system', function (): void {
    event(new PaymentFailed(auditBillable(), 'tr_123', 'insufficient funds'));

    $row = auditRows()->first();

    expect($row->causer_id)->toBeNull()
        ->and($row->causer_type)->toBeNull()
        ->and($row->properties['actor'])->toBe('system');
});

it('never stores a stack trace for events carrying an exception', function (): void {
    $exception = new RuntimeException('mollie rejected the charge');

    event(new OverageChargeFailed(auditBillable(), 2, $exception));

    $replace = auditRows()->first()->properties['replace'];

    expect($replace['exception_class'])->toBe(RuntimeException::class)
        ->and($replace['exception_message'])->toBe('mollie rejected the charge')
        ->and($replace['attempt'])->toBe(2)
        ->and(array_keys($replace))->not->toContain('trace')
        ->and(json_encode($replace))->not->toContain('vendor/');
});

it('summarises free-form array payloads instead of dumping them', function (): void {
    $billable = auditBillable();

    event(new SubscriptionUpdated(
        $billable,
        request: ['card_token' => 'secret-token', 'note' => 'internal'],
        diff: ['plan_code' => ['starter', 'pro'], 'seats' => [1, 5]],
    ));

    $replace = auditRows()->first()->properties['replace'];

    expect($replace['changes'])->toBe('plan_code, seats')
        ->and($replace['change_count'])->toBe(2)
        // The raw request/diff payloads must not leak into the audit row.
        ->and(json_encode($replace))->not->toContain('secret-token');
});

it('writes nothing when auditing is disabled', function (): void {
    config()->set('mollie-billing.audit.enabled', false);

    event(new MandateUpdated(auditBillable(), 'mdt_old', 'mdt_new'));

    expect(auditRows())->toHaveCount(0);
});

it('writes nothing for a category that is switched off', function (): void {
    config()->set('mollie-billing.audit.categories', ['payment']);

    $billable = auditBillable();
    event(new MandateUpdated($billable, 'mdt_old', 'mdt_new')); // payment_method
    event(new PaymentFailed($billable, 'tr_1', 'declined'));    // payment

    $rows = auditRows();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->event)->toBe('payment_failed');
});

it('keeps the billing flow alive when the audit write fails', function (): void {
    $billable = auditBillable();

    // Real failure, not a simulated one: without the table every insert throws.
    // The billing flow must not care.
    Schema::drop('activity_log');

    expect(fn () => event(new PlanChanged($billable, 'starter', 'pro', 'monthly')))
        ->not->toThrow(Throwable::class);
});

it('exposes the trail through the billable and scopes it to the billing log', function (): void {
    $billable = auditBillable();

    event(new PlanChanged($billable, 'starter', 'pro', 'monthly'));
    activity('some-app-log')->performedOn($billable)->log('unrelated');

    $trail = $billable->billingAuditTrail()->get();

    expect($trail)->toHaveCount(1)
        ->and($trail->first()->description)->toBe('audit.plan_changed');
});

it('renders a stored row in the reader\'s locale, resolving plan codes to names', function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro Plan',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 1000, 'seat_price_net' => null]],
    ]);

    event(new PlanChanged(auditBillable(), 'starter', 'pro', 'monthly'));

    $entry = new BillingAuditEntry(auditRows()->first());

    app()->setLocale('en');
    $english = $entry->title();

    app()->setLocale('de');
    $german = $entry->title();

    expect($english)->toContain('Pro Plan')
        // The raw code must not survive into the rendered text.
        ->and($english)->not->toContain('audit.plan_changed')
        ->and($german)->toContain('Pro Plan')
        ->and($german)->not->toBe($english);
});

it('falls back to a readable label when the translation key is missing', function (): void {
    $billable = auditBillable();
    event(new PlanChanged($billable, 'starter', 'pro', 'monthly'));

    $row = auditRows()->first();
    $row->description = 'audit.some_removed_event';
    $row->save();

    expect((new BillingAuditEntry($row))->title())
        ->toBe('Unrecognised billing event');
});

it('filters the trail by category the way the admin tab does', function (): void {
    $billable = auditBillable();

    event(new PlanChanged($billable, 'starter', 'pro', 'monthly')); // subscription
    event(new PaymentFailed($billable, 'tr_1', 'declined'));        // payment
    event(new MandateUpdated($billable, null, 'mdt_1'));            // payment_method

    // Same JSON path expression the audit-tab component uses.
    $payments = $billable->billingAuditTrail()
        ->where('properties->category', AuditCategory::Payment->value)
        ->get();

    expect($payments)->toHaveCount(1)
        ->and($payments->first()->event)->toBe('payment_failed')
        ->and($billable->billingAuditTrail()->count())->toBe(3);
});

it('orders the trail newest first', function (): void {
    $billable = auditBillable();

    event(new PlanChanged($billable, 'starter', 'pro', 'monthly'));
    event(new PaymentFailed($billable, 'tr_1', 'declined'));

    expect($billable->billingAuditTrail()->pluck('event')->all())
        ->toBe(['payment_failed', 'plan_changed']);
});

it('prunes only its own log rows past the retention window', function (): void {
    $billable = auditBillable();

    event(new PlanChanged($billable, 'starter', 'pro', 'monthly'));
    activity('some-app-log')->performedOn($billable)->log('unrelated');

    // Age both rows well past the window.
    BillingAuditEntry::model()::query()->update(['created_at' => now()->subDays(400)]);

    config()->set('mollie-billing.audit.retention_days', 90);
    (new GraystackIT\MollieBilling\Jobs\PruneBillingAuditJob)->handle();

    $remaining = BillingAuditEntry::model()::query()->get();

    expect($remaining)->toHaveCount(1)
        ->and($remaining->first()->log_name)->toBe('some-app-log');
});

it('keeps everything when no retention window is configured', function (): void {
    $billable = auditBillable();
    event(new PlanChanged($billable, 'starter', 'pro', 'monthly'));
    BillingAuditEntry::model()::query()->update(['created_at' => now()->subDays(4000)]);

    config()->set('mollie-billing.audit.retention_days', null);
    (new GraystackIT\MollieBilling\Jobs\PruneBillingAuditJob)->handle();

    expect(auditRows())->toHaveCount(1);
});

it('renders invoice events with the serial number', function (): void {
    $billable = auditBillable();

    $invoice = BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_inv_1',
        'serial_number' => 'INV-2026-0007',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 1000,
        'amount_vat' => 200,
        'amount_gross' => 1200,
        'line_items' => [],
    ]);

    event(new GraystackIT\MollieBilling\Events\InvoiceCreated($billable, $invoice));

    $entry = new BillingAuditEntry(auditRows()->first());

    expect($entry->title())->toContain('INV-2026-0007')
        ->and($entry->meta()['amount_cents'])->toBe(1200)
        ->and($entry->category())->toBe(AuditCategory::Invoice);
});
