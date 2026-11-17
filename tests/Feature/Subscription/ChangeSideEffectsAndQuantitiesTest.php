<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\SeatsChanged;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;
use Mollie\Laravel\Facades\Mollie;

/**
 * A change's announcements must not be able to undo it, and its prices must not be able to
 * disagree with themselves.
 *
 * `update()` wraps the whole change — including the Mollie payments and refunds
 * `applyProrata()` posts — in one transaction, and events are the documented extension seam.
 * A throwing listener could therefore roll back the local half of settled money.
 */
beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => ['print-gateway'],
        'intervals' => [
            'monthly' => ['base_price_net' => 2900, 'seat_price_net' => 500, 'included_usages' => []],
        ],
    ]);
    config()->set('mollie-billing-plans.addons', [
        'print-gateway' => ['name' => 'Print Gateway', 'feature_keys' => [], 'intervals' => ['monthly' => ['price_net' => 900]]],
    ]);
});

/** A billable whose app bills `print-gateway` three times over. */
function quantityBillable(): TestBillable
{
    $billable = new class extends TestBillable
    {
        protected $table = 'test_billables';

        public function getBillingAddonQuantity(string $addonCode): int
        {
            return $addonCode === 'print-gateway' ? 3 : 1;
        }
    };

    $billable->forceFill([
        'name' => 'Quantity Ltd',
        'email' => uniqid('qty-').'@x.test',
        'billing_country' => 'AT',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(6),
        'active_addon_codes' => ['print-gateway'],
        'mollie_customer_id' => 'cst_qty',
        'mollie_mandate_id' => 'mdt_qty',
        'subscription_meta' => ['mollie_subscription_id' => 'sub_qty', 'seat_count' => 1],
    ])->save();

    return $billable->refresh();
}

it('keeps the change when a listener on its own event throws', function (): void {
    $billable = quantityBillable();

    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_qty', 'status' => 'active']);

    // Dispatched inside the transaction, this rolled back the seat count — and, on a change
    // that had refunded something, the credit note and the `refunded_net` increment too,
    // while Mollie had already paid the customer. The retry then refunded a second time.
    Event::listen(SeatsChanged::class, function (): void {
        throw new RuntimeException('CRM push exploded');
    });

    // The listener's failure still surfaces — it is an app bug and hiding it would be worse
    // — but it surfaces *after* the commit, so nothing it touched is undone.
    expect(fn () => app(UpdateSubscription::class)->update($billable, ['seats' => 2]))
        ->toThrow(RuntimeException::class);

    expect($billable->fresh()->getBillingSeatCount())->toBe(2);
});

it('prices a quantitative addon by its real quantity on both sides of a change', function (): void {
    $billable = quantityBillable();

    // `SubscriptionAmount::net()` reads getBillingAddonQuantity() — 2900 + 3 × 900 = 5600 —
    // and its docblock promises the override is honoured "in every place where pricing is
    // calculated". The intent hardcoded 1, so the prorata window priced one unit while the
    // recurring window billed three.
    $preview = \GraystackIT\MollieBilling\Facades\MollieBilling::preview()
        ->previewUpdate($billable, ['seats' => 2]);

    expect($preview['newPriceNet'])->toBe(5600 + 500);
});

/*
 * The addons screen quotes what toggling one addon moves now. It handed the preview the
 * addons to keep as a list of codes, which the preview reads as one unit each — so the
 * addon the app bills three times over was quoted as dropping to one: switching another
 * addon on showed a credit, while `UpdateSubscription` (which asks
 * getBillingAddonQuantity()) charged for the new addon alone.
 */
it('quotes an addon toggle on the addons screen with the real quantity of the addons that stay', function (array $active, string $toggled): void {
    config()->set('mollie-billing-plans.plans.pro.allowed_addons', ['print-gateway', 'archive']);
    config()->set('mollie-billing-plans.addons.archive', ['name' => 'Archive', 'feature_keys' => [], 'intervals' => ['monthly' => ['price_net' => 400]]]);

    $billable = quantityBillable();
    $billable->forceFill(['active_addon_codes' => $active])->save();

    // The paid invoice of the running period — print-gateway three times over — is what a
    // credit is taken from; without one nothing is refundable and every quote is a charge.
    $line = fn (string $kind, string $code, int $qty, int $unit): array => [
        'kind' => $kind, 'code' => $code, 'label' => $code, 'quantity' => $qty,
        'unit_price_net' => $unit, 'amount_net' => $qty * $unit, 'total_net' => $qty * $unit,
        'vat_rate' => 20.0, 'vat_amount' => (int) round($qty * $unit * 0.2), 'amount_gross' => (int) round($qty * $unit * 1.2),
    ];
    $lines = [$line('plan', 'pro', 1, 2900), $line('addon', 'print-gateway', 3, 900)];
    if (in_array('archive', $active, true)) {
        $lines[] = $line('addon', 'archive', 1, 400);
    }
    $net = array_sum(array_column($lines, 'amount_net'));
    \GraystackIT\MollieBilling\Models\BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => uniqid('tr_period_'),
        'invoice_kind' => \GraystackIT\MollieBilling\Enums\InvoiceKind::Subscription,
        'status' => \GraystackIT\MollieBilling\Enums\InvoiceStatus::Paid,
        'country' => 'AT', 'currency' => 'EUR',
        'amount_net' => $net, 'amount_vat' => (int) round($net * 0.2), 'amount_gross' => (int) round($net * 1.2),
        'period_start' => $billable->subscription_period_starts_at,
        'period_end' => $billable->nextBillingDate(),
        'line_items' => $lines,
    ]);

    \GraystackIT\MollieBilling\Facades\MollieBilling::resolveBillableUsing(fn () => $billable->fresh());
    \GraystackIT\MollieBilling\Facades\MollieBilling::authUsing(fn (): bool => true);

    $card = collect(\Livewire\Livewire::test('mollie-billing::addons')->viewData('addons'))->firstWhere('code', $toggled);

    // What the change itself is priced with: every addon that stays at its billed quantity.
    $next = in_array($toggled, $active, true)
        ? ['print-gateway' => 3]
        : ['print-gateway' => 3, $toggled => 1];
    $expected = (int) \GraystackIT\MollieBilling\Facades\MollieBilling::preview()
        ->previewUpdate($billable->fresh(), ['addons' => $next])['prorataTotalGross'];

    expect($expected)->not->toBe(0)
        ->and($card['dueNowGross'])->toBe($expected);

    // And its sign is the toggle's: a charge for switching on, a credit for switching off.
    in_array($toggled, $active, true)
        ? expect($card['dueNowGross'])->toBeLessThan(0)
        : expect($card['dueNowGross'])->toBeGreaterThan(0);
})->with([
    'switching another addon on' => [['print-gateway'], 'archive'],
    'switching another addon off' => [['print-gateway', 'archive'], 'archive'],
]);
