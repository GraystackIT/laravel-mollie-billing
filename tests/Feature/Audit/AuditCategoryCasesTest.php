<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\AuditCategory;
use GraystackIT\MollieBilling\Events\MandateUpdated;
use GraystackIT\MollieBilling\Events\OverageWrittenOff;
use GraystackIT\MollieBilling\Events\SubscriptionCancelled;
use GraystackIT\MollieBilling\Events\TrialStarted;
use GraystackIT\MollieBilling\Events\WalletCredited;
use GraystackIT\MollieBilling\Support\BillingAuditEntry;
use GraystackIT\MollieBilling\Support\BillingAuditMap;
use GraystackIT\MollieBilling\Testing\TestBillable;

/**
 * Every audit category: an event is filed under it, the trail can be filtered by it, it
 * can be switched off on its own, and it has a label in both shipped languages.
 */
function auditCategoryCaseBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Audit Co', 'email' => uniqid('audit-case-').'@x.test', 'billing_country' => 'DE']);

    return $billable;
}

it('has at least one audited event in every category', function (): void {
    $used = array_map(
        static fn ($descriptor): string => $descriptor->category->value,
        array_values(BillingAuditMap::all()),
    );

    expect(array_values(array_unique($used)))
        ->toEqualCanonicalizing(array_map(fn (AuditCategory $c): string => $c->value, AuditCategory::cases()));
});

it('files an event under its category, filters by it and can switch it off', function (AuditCategory $category): void {
    $billable = auditCategoryCaseBillable();

    $event = match ($category) {
        AuditCategory::Subscription => new SubscriptionCancelled($billable, false, null),
        AuditCategory::Payment => new OverageWrittenOff($billable, [], 370, 440, 3),
        AuditCategory::PaymentMethod => new MandateUpdated($billable, 'mdt_old', 'mdt_new'),
        AuditCategory::Trial => new TrialStarted($billable, 'pro', 14),
        AuditCategory::Usage => new WalletCredited($billable, 'Tokens', 25, 'goodwill'),
        default => null,
    };

    if ($event === null) {
        // Invoice, Coupon and Compliance events carry models; write the row the listener
        // would, through the same logger and with the same properties.
        activity((string) config('mollie-billing.audit.log_name', 'billing'))
            ->performedOn($billable)
            ->event('case_'.$category->value)
            ->withProperties(['category' => $category->value, 'actor' => 'system', 'replace' => []])
            ->log('billing::audit.case_'.$category->value);
    } else {
        event($event);
    }

    // A second event of another category, so the filter has something to leave out.
    event($category === AuditCategory::Trial
        ? new WalletCredited($billable, 'Tokens', 1, 'other')
        : new TrialStarted($billable, 'pro', 7));

    $filtered = $billable->billingAuditTrail()->where('properties->category', $category->value)->get();

    expect($filtered)->toHaveCount(1)
        ->and((new BillingAuditEntry($filtered->first()))->category())->toBe($category)
        ->and($billable->billingAuditTrail()->count())->toBe(2);

    // Labels in both languages.
    foreach (['en', 'de'] as $locale) {
        app()->setLocale($locale);
        expect($category->label())->not->toContain('billing::')->not->toBe('');
    }
    app()->setLocale('en');

    // Switched off, an event of this category leaves no row.
    if ($event !== null) {
        config()->set('mollie-billing.audit.categories', array_values(array_filter(
            array_map(fn (AuditCategory $c): string => $c->value, AuditCategory::cases()),
            fn (string $value): bool => $value !== $category->value,
        )));

        event($event);

        expect($billable->billingAuditTrail()->where('properties->category', $category->value)->count())->toBe(1);
    }
})->with(AuditCategory::cases());
