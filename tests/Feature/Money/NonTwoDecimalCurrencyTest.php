<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * `mollie-billing.currency` accepts JPY (no decimals) and KWD (three). Everywhere an
 * amount crosses between minor units and a decimal string, the scale is a property of
 * the currency — a literal 100 is off by 100× for JPY and 10× for KWD, in opposite
 * directions.
 *
 * The OSS export's side of this lives in tests/Feature/Vat/OssExportCurrencyTest.php,
 * next to the rest of its fixtures.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
});

function scaleBillable(): TestBillable
{
    /** @var TestBillable $b */
    return TestBillable::create([
        'name' => 'Yen Co',
        'email' => uniqid('yen-').'@x.test',
        'billing_country' => 'JP',
    ])->refresh();
}

function scaleInvoice(TestBillable $billable, string $currency, int $net): BillingInvoice
{
    return BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => uniqid('tr_cur_'),
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'JP',
        'currency' => $currency,
        'amount_net' => $net,
        'amount_vat' => 0,
        'amount_gross' => $net,
        'period_start' => BillingTime::nowUtc()->subDays(5),
        'period_end' => BillingTime::nowUtc()->addDays(25),
        'line_items' => [[
            'kind' => 'plan',
            'code' => 'pro',
            'label' => 'Pro',
            'quantity' => 1,
            'unit_price_net' => $net,
            'amount_net' => $net,
            'total_net' => $net,
            'vat_rate' => 0.0,
            'vat_amount' => 0,
            'amount_gross' => $net,
        ]],
    ]);
}

/** The parser is private on purpose — it is reached the way the component reaches it. */
function parseRefundAmount(object $component, string $input, string $currency): int|false
{
    $method = new ReflectionMethod($component, 'parseAmountToMinor');
    $method->setAccessible(true);

    return $method->invoke($component, $input, $currency);
}

// ─────────────────────────────────────────────────────────────────────────────
// The admin refund parser — real money leaving the account
// ─────────────────────────────────────────────────────────────────────────────

it('reads a JPY refund amount at the currency scale', function (): void {
    config()->set('mollie-billing.currency', 'JPY');

    $billable = scaleBillable();
    $invoice = scaleInvoice($billable, 'JPY', 3000);

    $component = Livewire::test('mollie-billing::admin.billables.invoices-tab', [
        'billableId' => (string) $billable->getKey(),
    ])->instance();

    // Typing 500 to refund ¥500 posted a ¥50 000 refund — a 100× over-refund of real
    // money — while every honest entry below ¥50 000 was rejected as "exceeds
    // remaining", because the parsed value was compared against the invoice's ¥3000.
    expect(parseRefundAmount($component, '500', 'JPY'))->toBe(500)
        ->and(parseRefundAmount($component, '3000', 'JPY'))->toBe(3000)
        ->and(parseRefundAmount($component, '3000', 'JPY'))
        ->toBeLessThanOrEqual($invoice->remainingRefundableNet());

    // A decimal is not a valid JPY amount at all — silently rounding it would either
    // refuse the operator's intent or invent a value they did not type.
    expect(parseRefundAmount($component, '500,50', 'JPY'))->toBeFalse();
});

it('reads a three-decimal currency at the currency scale', function (): void {
    config()->set('mollie-billing.currency', 'KWD');

    $billable = scaleBillable();

    $component = Livewire::test('mollie-billing::admin.billables.invoices-tab', [
        'billableId' => (string) $billable->getKey(),
    ])->instance();

    // 1.500 KWD is 1500 fils. A hardcoded ×100 made it 150 — a 10× under-refund — and
    // the third decimal was rejected outright.
    expect(parseRefundAmount($component, '1,500', 'KWD'))->toBe(1500)
        ->and(parseRefundAmount($component, '1.5', 'KWD'))->toBe(1500)
        ->and(parseRefundAmount($component, '0,007', 'KWD'))->toBe(7)
        ->and(parseRefundAmount($component, '1,5005', 'KWD'))->toBeFalse();
});

it('still reads two-decimal amounts exactly', function (): void {
    $billable = scaleBillable();

    $component = Livewire::test('mollie-billing::admin.billables.invoices-tab', [
        'billableId' => (string) $billable->getKey(),
    ])->instance();

    // Both separators, both grouping conventions, and the float-precision case the
    // digit composition exists to avoid (`(float) '0.07' * 100` is 7.000000000000001).
    expect(parseRefundAmount($component, '10,50', 'EUR'))->toBe(1050)
        ->and(parseRefundAmount($component, '10.50', 'EUR'))->toBe(1050)
        ->and(parseRefundAmount($component, '1.234,56', 'EUR'))->toBe(123456)
        ->and(parseRefundAmount($component, '1,234.56', 'EUR'))->toBe(123456)
        ->and(parseRefundAmount($component, '0,07', 'EUR'))->toBe(7)
        ->and(parseRefundAmount($component, '10', 'EUR'))->toBe(1000)
        ->and(parseRefundAmount($component, '1e3', 'EUR'))->toBeFalse()
        ->and(parseRefundAmount($component, '10,505', 'EUR'))->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// The invariant itself
// ─────────────────────────────────────────────────────────────────────────────

it('routes every currency conversion through BillingMoney', function (): void {
    // BillingMoney is the single place that answers "how many minor units does this
    // currency have". Anything else scaling money by a literal is a JPY bug waiting to
    // be filed, so this catalogues the sites that were fixed and would regress silently.
    $suspects = [
        'src/Services/Vat/OssProtocolService.php',
        'src/Services/Webhook/WebhookSupport.php',
        'src/Testing/LifecycleSimulator.php',
        'resources/views/livewire/billing/admin/billables/invoices-tab.blade.php',
    ];

    foreach ($suspects as $relative) {
        $path = dirname(__DIR__, 3).'/'.$relative;

        // The comments explain the old bug on purpose; strip them before looking for it.
        $code = (string) preg_replace(
            '#(//[^\n]*|/\*.*?\*/)#s',
            '',
            (string) file_get_contents($path)
        );

        // The rule: in these files a literal 100 may only appear as a percentage. Every
        // other × 100 or ÷ 100 is a currency scale in disguise and belongs in
        // BillingMoney, which is the one place that knows a currency's minor units.
        foreach (preg_split('/\n/', $code) ?: [] as $line) {
            if (preg_match('#[*/]\s*100\b#', $line) !== 1) {
                continue;
            }

            expect($line)->toMatch('/rate|percent/i', $relative.': '.trim($line));
        }

        // And no amount may be formatted to a fixed two decimals.
        expect($code)->not->toMatch('/number_format\([^)]*(?:cents|gross|net|amount)[^)]*,\s*2\s*,/i');
    }

    // And the helper it all goes through has to actually know these currencies.
    expect(BillingMoney::fractionDigits('JPY'))->toBe(0)
        ->and(BillingMoney::fractionDigits('KWD'))->toBe(3)
        ->and(BillingMoney::fractionDigits('EUR'))->toBe(2)
        ->and(BillingMoney::minorUnitFactor('JPY'))->toBe(1)
        ->and(BillingMoney::minorUnitFactor('KWD'))->toBe(1000)
        ->and(BillingMoney::mollieAmount(3000, 'JPY'))->toBe('3000')
        ->and(BillingMoney::minorFromMollieAmount('3000', 'JPY'))->toBe(3000);
});
