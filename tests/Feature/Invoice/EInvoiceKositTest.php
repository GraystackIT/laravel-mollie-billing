<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\EInvoiceService;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Tests\Support\EInvoiceFixtures;
use Illuminate\Support\Facades\Storage;

/*
 * Every e-invoice shape the package issues, run through the official KoSIT validator
 * (EN 16931 + XRechnung Schematron) — the check the schema test in EInvoiceTest cannot
 * make. Needs Java and a local copy of the validator, so it is skipped unless
 * BILLING_KOSIT_DIR points at it:
 *
 *   BILLING_KOSIT_DIR=/path/to/kosit composer test:e-invoice
 *
 * The directory holds the unpacked validator distribution (validationtool-*-standalone.jar)
 * and the unpacked XRechnung configuration (scenarios.xml); docs/e-invoicing.md has the
 * download links.
 */

beforeEach(function (): void {
    $dir = getenv('BILLING_KOSIT_DIR') ?: null;

    if ($dir === null || ! is_dir($dir)) {
        $this->markTestSkipped('BILLING_KOSIT_DIR is not set — see docs/e-invoicing.md.');
    }

    Storage::fake('local');
    EInvoiceFixtures::configureSeller();
});

/**
 * @return array<string, Closure(): BillingInvoice>
 */
function kositScenarios(): array
{
    return [
        'domestic' => fn () => EInvoiceFixtures::invoice(),
        'oss-b2c-other-eu-country' => fn () => EInvoiceFixtures::invoice('DE', ['billing_city' => 'Köln', 'billing_postal_code' => '50667'], [[
            'kind' => 'plan', 'label' => 'Pro plan', 'quantity' => 1,
            'unit_price_net' => 3000, 'amount_net' => 3000,
            'vat_rate' => 19.0, 'vat_amount' => 570, 'amount_gross' => 3570,
        ]]),
        'reverse-charge' => fn () => EInvoiceFixtures::withValidVatNumber(
            EInvoiceFixtures::invoice('DE', lines: EInvoiceFixtures::zeroRatedLines()),
            'DE123456789',
            'DE',
        ),
        'outside-eu' => fn () => EInvoiceFixtures::invoice('US', ['billing_city' => 'Austin', 'billing_postal_code' => '78701'], EInvoiceFixtures::zeroRatedLines()),
        'domestic-exempt' => fn () => EInvoiceFixtures::invoice(lines: EInvoiceFixtures::zeroRatedLines()),
        'discount-line' => fn () => EInvoiceFixtures::invoice(lines: [
            ['kind' => 'plan', 'label' => 'Pro plan', 'quantity' => 1, 'unit_price_net' => 3000, 'amount_net' => 3000, 'vat_rate' => 20.0, 'vat_amount' => 600],
            ['kind' => 'coupon', 'label' => 'Coupon', 'quantity' => 1, 'unit_price_net' => -500, 'amount_net' => -500, 'vat_rate' => 20.0, 'vat_amount' => -100],
        ]),
        'mixed-rates' => fn () => EInvoiceFixtures::invoice(lines: [
            ['kind' => 'plan', 'label' => 'Pro plan', 'quantity' => 1, 'unit_price_net' => 3000, 'amount_net' => 3000, 'vat_rate' => 20.0, 'vat_amount' => 600],
            ['kind' => 'addon', 'label' => 'E-books', 'quantity' => 2, 'unit_price_net' => 450, 'amount_net' => 900, 'vat_rate' => 10.0, 'vat_amount' => 90],
        ]),
        'saldo-zero-plan-switch' => fn () => EInvoiceFixtures::invoice(lines: [
            ['kind' => 'plan', 'label' => 'Business plan', 'quantity' => 1, 'unit_price_net' => 2000, 'amount_net' => 2000, 'vat_rate' => 20.0, 'vat_amount' => 400],
            ['kind' => 'refund', 'label' => 'Credit Pro plan', 'quantity' => 1, 'unit_price_net' => -2000, 'amount_net' => -2000, 'vat_rate' => 20.0, 'vat_amount' => -400],
        ]),
        'open' => fn () => EInvoiceFixtures::invoice(invoiceAttributes: ['status' => InvoiceStatus::Open]),
        'zero-decimal-currency' => fn () => EInvoiceFixtures::invoice(lines: [
            ['kind' => 'plan', 'label' => 'Pro plan', 'quantity' => 1, 'unit_price_net' => 3000, 'amount_net' => 3000, 'vat_rate' => 20.0, 'vat_amount' => 600],
        ], invoiceAttributes: ['currency' => 'JPY']),
        'one-time-order' => fn () => EInvoiceFixtures::invoice(lines: [
            ['kind' => 'product', 'label' => '1.000 Tokens', 'quantity' => 1, 'unit_price_net' => 1900, 'amount_net' => 1900, 'vat_rate' => 20.0, 'vat_amount' => 380],
        ], invoiceAttributes: ['invoice_kind' => \GraystackIT\MollieBilling\Enums\InvoiceKind::OneTimeOrder]),
        'non-eu-taxed' => fn () => EInvoiceFixtures::invoice('CH', ['billing_city' => 'Zürich', 'billing_postal_code' => '8001'], [
            ['kind' => 'plan', 'label' => 'Pro plan', 'quantity' => 1, 'unit_price_net' => 3000, 'amount_net' => 3000, 'vat_rate' => 8.1, 'vat_amount' => 243],
        ]),
        'non-eu-taxed-with-zero-rated-line' => fn () => EInvoiceFixtures::invoice('CH', ['billing_city' => 'Zürich', 'billing_postal_code' => '8001'], [
            ['kind' => 'plan', 'label' => 'Pro plan', 'quantity' => 1, 'unit_price_net' => 3000, 'amount_net' => 3000, 'vat_rate' => 8.1, 'vat_amount' => 243],
            ['kind' => 'addon', 'label' => 'Add-on', 'quantity' => 1, 'unit_price_net' => 500, 'amount_net' => 500, 'vat_rate' => 0.0, 'vat_amount' => 0],
        ]),
        'period-at-incidental-hour' => fn () => EInvoiceFixtures::invoice(lines: [
            ['kind' => 'plan', 'label' => 'Pro plan', 'quantity' => 1, 'unit_price_net' => 3000, 'amount_net' => 3000, 'vat_rate' => 20.0, 'vat_amount' => 600,
                'period_start' => '2026-11-14T10:00:00+00:00', 'period_end' => '2026-12-01T00:28:00+00:00'],
        ]),
        'credit-note' => function (): BillingInvoice {
            $invoice = EInvoiceFixtures::invoice();

            return app(InvoiceService::class)->createCreditNote($invoice, 1000, deferDocument: true)->refresh();
        },
    ];
}

it('issues e-invoices the KoSIT validator accepts, in both profiles', function (): void {
    $dir = (string) getenv('BILLING_KOSIT_DIR');
    $jar = collect(glob($dir.'/{,*/}validationtool-*-standalone.jar', GLOB_BRACE) ?: [])
        ->reject(fn (string $path): bool => str_contains($path, 'java8'))
        ->first();
    $scenarios = (glob($dir.'/{,*/}scenarios.xml', GLOB_BRACE) ?: [])[0] ?? null;

    expect($jar)->not->toBeNull('validationtool-*-standalone.jar not found under BILLING_KOSIT_DIR')
        ->and($scenarios)->not->toBeNull('scenarios.xml not found under BILLING_KOSIT_DIR');

    $work = sys_get_temp_dir().'/billing-kosit-'.uniqid();
    mkdir($work.'/out', 0777, true);

    $service = app(EInvoiceService::class);
    $files = [];

    foreach (kositScenarios() as $name => $make) {
        $invoice = $make();

        foreach ([EInvoiceService::PROFILE_EN16931, EInvoiceService::PROFILE_XRECHNUNG] as $profile) {
            $file = $work.'/'.$name.'-'.$profile.'.xml';
            file_put_contents($file, $service->xml($invoice, $invoice->billable, $profile));
            $files[] = $file;
        }
    }

    $command = array_merge(
        ['java', '-jar', $jar, '-s', $scenarios, '-r', dirname($scenarios), '-o', $work.'/out'],
        $files,
    );
    exec(implode(' ', array_map('escapeshellarg', $command)).' 2>&1', $output);

    $rejected = [];
    foreach ($files as $file) {
        $report = $work.'/out/'.basename($file, '.xml').'-report.xml';
        $content = is_file($report) ? (string) file_get_contents($report) : '';

        if (! str_contains($content, '<rep:accept')) {
            preg_match_all('/<rep:message[^>]*level="error"[^>]*>(.*?)<\/rep:message>/s', $content, $m);
            $rejected[basename($file)] = $content === ''
                ? 'no report: '.implode("\n", array_slice($output, -5))
                : implode(' | ', array_map(fn (string $text): string => trim(strip_tags($text)), $m[1]));
        }
    }

    \Illuminate\Support\Facades\File::deleteDirectory($work);

    expect($rejected)->toBe([]);
});
