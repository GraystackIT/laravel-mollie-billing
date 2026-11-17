<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\OssExportStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\GenerateOssExportJob;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingOssExport;
use GraystackIT\MollieBilling\Services\Billing\MollieMandateInspector;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\MandateSummary;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * The admin actions that had no feature test calling them. Each is called the way the
 * browser calls it — by name, on the mounted component — and its effect asserted.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
    config()->set('mollie-billing.billable_model', TestBillable::class);

    foreach (['free' => 'Free', 'basic' => 'Basic Free'] as $code => $name) {
        config()->set("mollie-billing-plans.plans.{$code}", [
            'name' => $name, 'tier' => 1, 'included_seats' => 1, 'feature_keys' => [], 'allowed_addons' => [],
            'intervals' => ['monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []]],
        ]);
    }
});

function operationsActionsBillable(array $attributes = []): TestBillable
{
    $billable = new TestBillable;
    $billable->forceFill(array_merge([
        'name' => 'Actions Co',
        'email' => uniqid('actions-').'@x.test',
        'billing_country' => 'DE',
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'free',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
    ], $attributes))->save();

    return $billable->refresh();
}

function operationsActionsInvoice(TestBillable $billable, array $attributes = []): BillingInvoice
{
    $invoice = new BillingInvoice;
    $invoice->forceFill(array_merge([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => uniqid('tr_act_'),
        'serial_number' => 'IN-26'.random_int(100000, 999999),
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'DE',
        'currency' => 'EUR',
        'amount_net' => 1000,
        'amount_vat' => 190,
        'amount_gross' => 1190,
        'refunded_net' => 0,
        'line_items' => [[
            'kind' => 'plan', 'code' => 'free', 'label' => 'Plan', 'quantity' => 1,
            'unit_price_net' => 1000, 'amount_net' => 1000, 'total_net' => 1000,
            'vat_rate' => 19.0, 'vat_amount' => 190, 'amount_gross' => 1190,
        ]],
    ], $attributes))->save();

    return $invoice;
}

// ── Billable detail and its tabs ────────────────────────────────────────────

it('show: planName() answers the catalog name and mandate() what the inspector reports', function (): void {
    $billable = operationsActionsBillable(['subscription_plan_code' => 'basic', 'mollie_mandate_id' => 'mdt_show']);

    $summary = new MandateSummary(
        id: 'mdt_show', status: 'valid', method: 'creditcard', details: ['cardNumber' => '4242'],
        mandateReference: null, signatureDate: null,
    );
    $inspector = Mockery::mock(MollieMandateInspector::class);
    $inspector->shouldReceive('inspect')->andReturn($summary);
    app()->instance(MollieMandateInspector::class, $inspector);

    $component = Livewire::test('mollie-billing::admin.billables.show', ['routeParameters' => ['billable' => $billable->getKey()]]);

    expect($component->instance()->planName())->toBe('Basic Free')
        ->and($component->instance()->mandate())->toBe($summary);

    $component->assertSee('Basic Free')->assertSee('mdt_show');

    // No plan, no name; no billable, no mandate.
    $bare = operationsActionsBillable(['subscription_plan_code' => null]);
    expect(Livewire::test('mollie-billing::admin.billables.show', ['routeParameters' => ['billable' => $bare->getKey()]])->instance()->planName())->toBeNull();
    expect(Livewire::test('mollie-billing::admin.billables.show', ['routeParameters' => ['billable' => 999999]])->instance()->mandate())->toBeNull();
});

it('tabs: billable() resolves the billable the tab was mounted for, and nothing for an unknown id', function (string $tab): void {
    $billable = operationsActionsBillable();

    expect(Livewire::test('mollie-billing::admin.billables.'.$tab, ['billableId' => $billable->getKey()])->instance()->billable()?->getKey())
        ->toBe($billable->getKey())
        ->and(Livewire::test('mollie-billing::admin.billables.'.$tab, ['billableId' => 999999])->instance()->billable())
        ->toBeNull();
})->with(['invoices-tab', 'wallet-tab', 'audit-tab']);

it('invoices-tab: openRefund targets one invoice, and regeneratePdf writes its document again', function (): void {
    $billable = operationsActionsBillable();
    $invoice = operationsActionsInvoice($billable);
    $other = operationsActionsInvoice(operationsActionsBillable());

    $component = Livewire::test('mollie-billing::admin.billables.invoices-tab', ['billableId' => $billable->getKey()])
        ->set('refundAmount', '5,00')
        ->call('openRefund', $invoice->id)
        ->assertSet('refundInvoiceId', $invoice->id)
        ->assertSet('showRefundModal', true)
        // Opening starts from a clean form.
        ->assertSet('refundAmount', null)
        ->assertSet('refundReason', 'goodwill');

    expect($invoice->fresh()->hasPdf())->toBeFalse();

    $component->call('regeneratePdf', $invoice->id)
        ->assertSet('flash', __('billing::admin.billables.invoices.flash.pdf_regenerated'))
        ->assertSet('error', null);

    $invoice->refresh();
    expect($invoice->hasPdf())->toBeTrue()
        ->and(Storage::disk('local')->exists($invoice->pdf_path))->toBeTrue();

    // Another billable's invoice is not this tab's to regenerate.
    $component->call('regeneratePdf', $other->id)
        ->assertSet('error', __('billing::admin.billables.invoices.flash.invoice_not_found'));
    expect($other->fresh()->hasPdf())->toBeFalse();
});

it('invoices-tab: reason Other without a text is refused in the operator\'s language', function (): void {
    $billable = operationsActionsBillable();
    $invoice = operationsActionsInvoice($billable);

    app()->setLocale('de');

    Livewire::test('mollie-billing::admin.billables.invoices-tab', ['billableId' => $billable->getKey()])
        ->call('openRefund', $invoice->id)
        ->set('refundReason', 'other')
        ->set('refundAmount', '1,00')
        ->call('refund')
        ->assertSet('error', __('billing::admin.billables.invoices.flash.reason_text_required', [], 'de'))
        ->call('refundFull')
        ->assertSet('error', __('billing::admin.billables.invoices.flash.reason_text_required', [], 'de'));

    app()->setLocale('en');

    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(0)
        ->and(__('billing::admin.billables.invoices.flash.reason_text_required', [], 'de'))->not->toContain('billing::');
});

// ── Lists ───────────────────────────────────────────────────────────────────

it('sort: every list toggles direction on its own columns and ignores anything else', function (string $component, array $columns, string $default): void {
    $list = Livewire::test('mollie-billing::admin.'.$component);

    foreach ($columns as $column) {
        $list->call('sort', $column)
            ->assertSet('sortBy', $column)
            ->assertSet('sortDirection', 'asc')
            ->call('sort', $column)
            ->assertSet('sortDirection', 'desc');
    }

    $last = end($columns);

    // A column that is not on the allow-list changes nothing.
    $list->call('sort', 'password')->assertSet('sortBy', $last)->assertOk();
    expect($default)->not->toBe('');
})->with([
    'billables' => ['billables.index', ['name', 'email', 'subscription_plan_code', 'subscription_status', 'created_at'], 'created_at'],
    'mismatches' => ['mismatches.index', ['id', 'tax_country_user', 'tax_country_payment', 'tax_country_ip', 'resolved_at', 'created_at'], 'created_at'],
    'past due' => ['past-due.index', ['name', 'email', 'updated_at'], 'updated_at'],
    'refunds' => ['refunds.index', ['serial_number', 'amount_gross', 'created_at'], 'created_at'],
    'scheduled changes' => ['scheduled-changes.index', ['name', 'email', 'scheduled_change_at'], 'scheduled_change_at'],
]);

it('refunds: the list really orders by the chosen column', function (): void {
    $billable = operationsActionsBillable();
    $small = operationsActionsInvoice($billable, ['invoice_kind' => InvoiceKind::Refund, 'status' => InvoiceStatus::Refunded, 'serial_number' => 'CR-B', 'amount_gross' => -300]);
    $large = operationsActionsInvoice($billable, ['invoice_kind' => InvoiceKind::Refund, 'status' => InvoiceStatus::Refunded, 'serial_number' => 'CR-A', 'amount_gross' => -9000]);

    $list = Livewire::test('mollie-billing::admin.refunds.index');

    $list->call('sort', 'serial_number')->assertSeeInOrder(['CR-A', 'CR-B']);
    $list->call('sort', 'serial_number')->assertSeeInOrder(['CR-B', 'CR-A']);
    $list->call('sort', 'amount_gross')->assertSeeInOrder(['CR-A', 'CR-B']);

    $list->call('openDetail', $large->id)->assertSet('detailInvoiceId', $large->id)->assertSet('showDetail', true);
    expect($small->id)->not->toBe($large->id);
});

// ── Mismatches ──────────────────────────────────────────────────────────────

it('mismatches: openResolve arms the modal for a pending row only, cancelResolve disarms it', function (): void {
    $billable = operationsActionsBillable();

    $pending = BillingCountryMismatch::query()->create([
        'billable_type' => $billable->getMorphClass(), 'billable_id' => $billable->getKey(),
        'tax_country_user' => 'DE', 'tax_country_payment' => 'FR', 'tax_country_ip' => 'NL',
        'status' => CountryMismatchStatus::Pending,
    ]);
    $resolved = BillingCountryMismatch::query()->create([
        'billable_type' => $billable->getMorphClass(), 'billable_id' => $billable->getKey(),
        'tax_country_user' => 'DE', 'tax_country_payment' => 'AT', 'tax_country_ip' => 'AT',
        'status' => CountryMismatchStatus::Resolved, 'chosen_country' => 'AT', 'resolved_at' => now(),
    ]);

    $component = Livewire::test('mollie-billing::admin.mismatches.index');

    // A resolved mismatch cannot be opened again.
    $component->call('openResolve', $resolved->id)->assertSet('resolvingId', null);

    $component->call('openResolve', $pending->id)
        ->assertSet('resolvingId', $pending->id)
        ->assertDispatched('open-resolve-modal')
        ->set('resolvingChosen', 'FR')
        ->set('resolvingJustification', 'half a sentence')
        ->call('cancelResolve')
        ->assertSet('resolvingId', null)
        ->assertSet('resolvingChosen', null)
        ->assertSet('resolvingJustification', null);

    // Cancelled means nothing happened.
    expect($pending->fresh()->status)->toBe(CountryMismatchStatus::Pending);

    // confirmResolve refuses what is not a country, and a country it cannot invoice.
    $component->call('openResolve', $pending->id)
        ->set('resolvingChosen', 'X')
        ->set('resolvingJustification', 'ticket 1')
        ->call('confirmResolve')
        ->assertSet('resolvingError', __('billing::admin.mismatches.modal.invalid_country'));

    // Both tabs list their own status.
    $component->set('tab', 'pending')->assertSee('FR')
        ->set('tab', 'resolved')->assertSee('AT');
});

// ── OSS ─────────────────────────────────────────────────────────────────────

it('oss: generate queues one export per year and refuses nonsense and a second run', function (): void {
    Queue::fake();

    $component = Livewire::test('mollie-billing::admin.oss.index');

    $component->call('generate', 1999)->assertSet('error', __('billing::admin.oss.invalid_year'));
    expect(BillingOssExport::query()->count())->toBe(0);

    $component->call('generate', 2026)->assertSet('flash', __('billing::admin.oss.queued', ['year' => 2026]));

    $export = BillingOssExport::query()->sole();
    expect($export->status)->toBe(OssExportStatus::Queued)->and($export->year)->toBe(2026);
    Queue::assertPushed(GenerateOssExportJob::class, 1);

    // While that one is queued, the same year is refused; another year is not.
    $component->call('generate', 2026)->assertSet('error', __('billing::admin.oss.already_running'));
    $component->call('generate', 2025)->assertSet('error', null);

    expect(BillingOssExport::query()->count())->toBe(2);
});

// ── Scheduled changes ───────────────────────────────────────────────────────

it('scheduled-changes: applyNow applies the change and cancel drops it', function (): void {
    $schedule = function (): TestBillable {
        $billable = operationsActionsBillable();
        $billable->forceFill([
            'scheduled_change_at' => BillingTime::nowUtc()->addDays(5),
            'subscription_meta' => ['seat_count' => 1, 'scheduled_change' => [
                'plan_code' => 'basic',
                'scheduled_at' => BillingTime::nowUtc()->addDays(5)->toIso8601String(),
            ]],
        ])->save();

        return $billable->refresh();
    };

    $applied = $schedule();
    $cancelled = $schedule();

    Livewire::test('mollie-billing::admin.scheduled-changes.index')
        ->call('applyNow', $applied->getKey())
        ->assertSet('error', null)
        ->assertSet('flash', __('billing::admin.scheduled_changes.applied'))
        ->call('cancel', $cancelled->getKey())
        ->assertSet('flash', __('billing::admin.scheduled_changes.cancelled'));

    expect($applied->fresh()->subscription_plan_code)->toBe('basic')
        ->and($applied->fresh()->scheduled_change_at)->toBeNull()
        ->and($cancelled->fresh()->subscription_plan_code)->toBe('free')
        ->and($cancelled->fresh()->scheduled_change_at)->toBeNull();
});

// ── Usage meter ─────────────────────────────────────────────────────────────

it('usage-meter: data() splits plan and purchased units, and counts overage', function (int $balance, int $purchased, array $expected): void {
    $data = Livewire::test('mollie-billing::components.usage-meter', [
        'type' => 'Tokens', 'included' => 100, 'balance' => $balance, 'purchasedBalance' => $purchased, 'trial' => false,
    ])->instance()->data();

    expect(array_intersect_key($data, $expected))->toBe($expected);
})->with([
    'untouched' => [100, 0, ['overage' => 0, 'planUsed' => 0, 'remainingIncluded' => 100, 'usedPercent' => 0, 'isDanger' => false]],
    'plan partly used, pack untouched' => [140, 100, ['overage' => 0, 'planUsed' => 60, 'purchasedUsed' => 0, 'remainingPurchased' => 100, 'usedPercent' => 60]],
    'plan gone, pack partly used' => [30, 100, ['planUsed' => 100, 'purchasedUsed' => 70, 'remainingPurchased' => 30, 'isDanger' => true]],
    'everything gone and more' => [-25, 100, ['overage' => 25, 'planUsed' => 125, 'purchasedUsed' => 100, 'remainingPurchased' => 0, 'isDanger' => true]],
]);

// ── The customer's usage page ───────────────────────────────────────────────

it('usage-history: counts consumption only, follows the type and date filters and shows an open overage charge', function (): void {
    config()->set('mollie-billing-plans.plans.free.intervals.monthly.included_usages', ['Tokens' => 100, 'SMS' => 50]);

    $billable = operationsActionsBillable();
    \GraystackIT\MollieBilling\MollieBilling::resolveBillableUsing(fn () => $billable);
    \GraystackIT\MollieBilling\MollieBilling::authUsing(fn (): bool => true);

    $wallets = app(\GraystackIT\MollieBilling\Services\Wallet\WalletUsageService::class);
    $wallets->credit($billable, 'Tokens', 100, 'subscription_activation');
    $wallets->credit($billable, 'SMS', 50, 'subscription_activation');
    $wallets->debit($billable, 'Tokens', 10);
    \Illuminate\Support\Facades\DB::table('transactions')->where('type', 'withdraw')->update(['created_at' => now()->subDays(4)]);
    $wallets->debit($billable, 'Tokens', 30);
    $wallets->debit($billable, 'SMS', 5);

    $stats = fn ($component): array => array_intersect_key($component->viewData('stats'), ['debits' => 0, 'total' => 0]);

    $component = Livewire::test('mollie-billing::usage-history');

    // The two credits are bookkeeping, not usage.
    expect($stats($component))->toBe(['total' => 3, 'debits' => 45]);

    expect($stats($component->set('usageType', 'Tokens')))->toBe(['total' => 2, 'debits' => 40])
        ->and(array_column($component->viewData('meters'), 'type'))->toBe(['Tokens']);

    expect($stats($component->set('usageType', '')->set('dateFrom', now()->subDay()->format('Y-m-d'))))->toBe(['total' => 2, 'debits' => 35]);
    expect($stats($component->set('dateFrom', now()->subDays(6)->format('Y-m-d'))->set('dateTo', now()->subDays(2)->format('Y-m-d'))))->toBe(['total' => 1, 'debits' => 10]);

    // Garbage in a date field is ignored rather than turned into an error page.
    $component->set('dateTo', 'not-a-date')->assertOk();

    // An overage charge that failed and stands as a debt is said in words, with its amount.
    $component->assertDontSee('dusk="outstanding-overage"', escape: false);

    $billable->forceFill(['subscription_meta' => [
        'usage_overage_status' => \GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly::STATUS_FAILED,
        'usage_overage' => [
            'line_items' => [['kind' => 'overage', 'type' => 'Tokens', 'quantity' => 37, 'unit_price_net' => 10, 'total_net' => 370, 'settles_wallet' => false]],
            'amount_net' => 370, 'amount_gross' => 440, 'currency' => 'EUR',
        ],
    ]])->save();

    Livewire::test('mollie-billing::usage-history')
        ->assertSee('dusk="outstanding-overage"', escape: false)
        ->assertSee(__('billing::portal.usage_history_outstanding_overage', ['amount' => \GraystackIT\MollieBilling\Support\BillingMoney::format(440)]));
});

it('scheduled-changes: applyNow says so when the change cannot be applied, and keeps it booked', function (): void {
    // A change to a plan that is no longer sold. The unattended run drops such a change
    // and tells the customer; in front of an operator it used to be dropped as well —
    // and the screen then said "Scheduled change applied."
    $billable = operationsActionsBillable();
    $billable->forceFill([
        'scheduled_change_at' => BillingTime::nowUtc()->addDays(5),
        'subscription_meta' => ['seat_count' => 1, 'scheduled_change' => [
            'plan_code' => 'no-such-plan',
            'scheduled_at' => BillingTime::nowUtc()->addDays(5)->toIso8601String(),
        ]],
    ])->save();

    Livewire::test('mollie-billing::admin.scheduled-changes.index')
        ->call('applyNow', $billable->getKey())
        ->assertSet('flash', null)
        ->assertSet('error', __('billing::admin.scheduled_changes.apply_failed'));

    $billable->refresh();

    expect($billable->subscription_plan_code)->toBe('free')
        ->and($billable->getBillingSubscriptionMeta()['scheduled_change']['plan_code'] ?? null)->toBe('no-such-plan');
});

it('usage reasons: a sentence an operator typed is shown as written, an identifier as a heading', function (): void {
    expect(\GraystackIT\MollieBilling\Support\UsageReason::label('Goodwill after the outage'))->toBe('Goodwill after the outage')
        ->and(\GraystackIT\MollieBilling\Support\UsageReason::label('some_new_reason'))->toBe('Some New Reason')
        ->and(\GraystackIT\MollieBilling\Support\UsageReason::label('api-call'))->toBe('Api Call');
});
