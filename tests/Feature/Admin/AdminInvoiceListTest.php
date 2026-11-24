<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GraystackIT\MollieBilling\Contracts\AuthorizesBillingAdmin;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceArchiveService;
use GraystackIT\MollieBilling\Support\AdminInvoiceFilter;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * The admin's list of every invoice: the date range and its quick filters, the other
 * filters, the detail, the single PDF (viewed and downloaded) and the ZIP export of
 * exactly what the list shows.
 */
class InvoiceListAdminUser extends Authenticatable implements AuthorizesBillingAdmin
{
    public function canAccessBillingAdmin(): bool
    {
        return true;
    }
}

class InvoiceListPlainUser extends Authenticatable implements AuthorizesBillingAdmin
{
    public function canAccessBillingAdmin(): bool
    {
        return false;
    }
}

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
});

function invoiceListBillable(string $name, string $email): TestBillable
{
    $b = new TestBillable;
    $b->forceFill(['name' => $name, 'email' => $email, 'billing_country' => 'DE'])->save();

    return $b;
}

function invoiceListInvoice(
    ?TestBillable $billable,
    string $createdAt,
    string $serial,
    int $net = 1000,
    InvoiceKind $kind = InvoiceKind::Subscription,
    InvoiceStatus $status = InvoiceStatus::Paid,
    bool $withPdf = true,
): BillingInvoice {
    $invoice = new BillingInvoice;
    $invoice->billable_type = $billable?->getMorphClass() ?? TestBillable::class;
    $invoice->billable_id = $billable?->getKey() ?? '00000000-0000-0000-0000-000000000000';
    $invoice->mollie_payment_id = $kind === InvoiceKind::Refund ? null : 'tr_'.uniqid();
    $invoice->invoice_kind = $kind;
    $invoice->status = $status;
    $invoice->country = 'DE';
    $invoice->amount_net = $net;
    $invoice->amount_vat = (int) round($net * 0.19);
    $invoice->amount_gross = $net + (int) round($net * 0.19);
    $invoice->line_items = [['kind' => 'plan', 'label' => 'Pro plan', 'quantity' => 1, 'amount_net' => $net]];
    $invoice->serial_number = $serial;
    $invoice->created_at = CarbonImmutable::parse($createdAt, 'UTC');
    $invoice->save();

    if ($withPdf) {
        $path = 'billing/invoices/'.$invoice->id.'.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 '.$serial);
        $invoice->forceFill(['pdf_disk' => 'local', 'pdf_path' => $path])->save();
    }

    return $invoice;
}

/** @return list<string> serial numbers in the order the component lists them */
function invoiceListSerials($component): array
{
    return $component->viewData('invoices')->getCollection()->pluck('serial_number')->all();
}

/** @return array<string, string> entry name => content */
function invoiceListZip(string $content): array
{
    $path = tempnam(sys_get_temp_dir(), 'invoice-list-test-');
    file_put_contents($path, $content);

    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();

    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $entries[$name] = (string) $zip->getFromName($name);
    }

    $zip->close();
    @unlink($path);
    ksort($entries);

    return $entries;
}

// ── The list ─────────────────────────────────────────────────────────────────

it('lists every invoice across billables, newest first', function (): void {
    $a = invoiceListBillable('Acme', 'acme@example.com');
    $b = invoiceListBillable('Globex', 'globex@example.com');

    invoiceListInvoice($a, '2026-10-02 10:00:00', 'RE-1');
    invoiceListInvoice($b, '2026-10-05 10:00:00', 'RE-2');
    invoiceListInvoice($a, '2026-10-04 10:00:00', 'GS-1', -500, InvoiceKind::Refund);

    $component = Livewire::test('mollie-billing::admin.invoices.index');

    expect(invoiceListSerials($component))->toBe(['RE-2', 'GS-1', 'RE-1']);
    $component->assertSee('Acme')->assertSee('Globex')
        ->assertSee(route('billing.admin.billables.show', $a))
        ->assertSee(route('billing.admin.billables.show', $b));
});

it('filters by an inclusive date range, in UTC while no admin timezone is set', function (): void {
    $b = invoiceListBillable('Acme', 'acme@example.com');

    invoiceListInvoice($b, '2026-09-30 23:59:59', 'before');
    invoiceListInvoice($b, '2026-10-01 00:00:00', 'first-second');
    invoiceListInvoice($b, '2026-10-31 23:59:59', 'last-second');
    invoiceListInvoice($b, '2026-11-01 00:00:00', 'after');

    $component = Livewire::test('mollie-billing::admin.invoices.index')
        ->set('from', '2026-10-01')
        ->set('to', '2026-10-31');

    expect(invoiceListSerials($component))->toBe(['last-second', 'first-second']);

    // Entered backwards, the same days — not none of them.
    $component->set('from', '2026-10-31')->set('to', '2026-10-01');
    expect(invoiceListSerials($component))->toBe(['last-second', 'first-second']);

    // An open end is open.
    $component->set('from', '2026-10-31')->set('to', '');
    expect(invoiceListSerials($component))->toBe(['after', 'last-second']);
});

it('sets the range from the quick filters, and clears it when the active one is clicked again', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-31 12:00:00', 'UTC'));

    expect(AdminInvoiceFilter::presetRange('this_month'))->toBe(['2026-03-01', '2026-03-31'])
        // From the 31st: February, not a "March 3rd" overflow.
        ->and(AdminInvoiceFilter::presetRange('last_month'))->toBe(['2026-02-01', '2026-02-28'])
        ->and(AdminInvoiceFilter::presetRange('this_year'))->toBe(['2026-01-01', '2026-12-31'])
        ->and(AdminInvoiceFilter::presetRange('last_year'))->toBe(['2025-01-01', '2025-12-31'])
        ->and(AdminInvoiceFilter::presetRange('nonsense'))->toBeNull();

    $b = invoiceListBillable('Acme', 'acme@example.com');
    invoiceListInvoice($b, '2026-03-10 10:00:00', 'march');
    invoiceListInvoice($b, '2026-02-10 10:00:00', 'february');
    invoiceListInvoice($b, '2025-07-10 10:00:00', 'last-year');

    $component = Livewire::test('mollie-billing::admin.invoices.index');

    $component->call('applyPreset', 'last_month')
        ->assertSet('from', '2026-02-01')->assertSet('to', '2026-02-28')
        ->assertViewHas('activePreset', 'last_month');
    expect(invoiceListSerials($component))->toBe(['february']);

    $component->call('applyPreset', 'this_month');
    expect(invoiceListSerials($component))->toBe(['march']);

    $component->call('applyPreset', 'this_year');
    expect(invoiceListSerials($component))->toBe(['march', 'february']);

    $component->call('applyPreset', 'last_year');
    expect(invoiceListSerials($component))->toBe(['last-year']);

    $component->call('applyPreset', 'last_year')->assertSet('from', '')->assertSet('to', '');
    expect(invoiceListSerials($component))->toHaveCount(3);

    $component->call('applyPreset', 'nonsense')->assertSet('from', '')->assertSet('to', '');
});

it('filters by type, status and search, and clears every filter at once', function (): void {
    $acme = invoiceListBillable('Acme', 'billing@acme.test');
    $globex = invoiceListBillable('Globex', 'billing@globex.test');

    invoiceListInvoice($acme, '2026-10-02 10:00:00', 'RE-1');
    invoiceListInvoice($acme, '2026-10-03 10:00:00', 'GS-1', -500, InvoiceKind::Refund);
    invoiceListInvoice($globex, '2026-10-04 10:00:00', 'OV-1', 300, InvoiceKind::Overage, InvoiceStatus::Failed);

    $component = Livewire::test('mollie-billing::admin.invoices.index');

    $component->set('kind', 'refund');
    expect(invoiceListSerials($component))->toBe(['GS-1']);

    $component->set('kind', '')->set('status', 'failed');
    expect(invoiceListSerials($component))->toBe(['OV-1']);

    $component->set('status', '')->set('search', 'acme');
    expect(invoiceListSerials($component))->toBe(['GS-1', 'RE-1']);

    $component->set('search', 'RE-1');
    expect(invoiceListSerials($component))->toBe(['RE-1']);

    // An unknown type is no filter, not an empty list.
    $component->set('search', '')->set('kind', 'nonsense');
    expect(invoiceListSerials($component))->toHaveCount(3);

    $component->set('kind', 'refund')->set('from', '2026-10-01')->call('clearFilters')
        ->assertSet('kind', '')->assertSet('from', '')->assertSet('search', '');
    expect(invoiceListSerials($component))->toHaveCount(3);
});

it('sums the shown invoices per currency, credit notes counted against them', function (): void {
    $b = invoiceListBillable('Acme', 'acme@example.com');
    invoiceListInvoice($b, '2026-10-02 10:00:00', 'RE-1', 10000);
    invoiceListInvoice($b, '2026-10-03 10:00:00', 'GS-1', -2500, InvoiceKind::Refund);

    $component = Livewire::test('mollie-billing::admin.invoices.index');

    $component->assertViewHas('count', 2);
    $totals = $component->viewData('totals');
    expect($totals)->toHaveCount(1)
        ->and($totals[0]['net'])->toBe(7500)
        ->and($totals[0]['gross'])->toBe(7500 + (int) round(10000 * 0.19) + (int) round(-2500 * 0.19));
});

it('sorts the invoice list by an allowed column and ignores any other', function (): void {
    $b = invoiceListBillable('Acme', 'acme@example.com');
    invoiceListInvoice($b, '2026-10-01 10:00:00', 'B', 3000);
    invoiceListInvoice($b, '2026-10-02 10:00:00', 'C', 1000);
    invoiceListInvoice($b, '2026-10-03 10:00:00', 'A', 2000);

    $component = Livewire::test('mollie-billing::admin.invoices.index');

    $component->call('sort', 'serial_number');
    expect(invoiceListSerials($component))->toBe(['A', 'B', 'C']);

    $component->call('sort', 'serial_number');
    expect(invoiceListSerials($component))->toBe(['C', 'B', 'A']);

    $component->call('sort', 'amount_net');
    expect(invoiceListSerials($component))->toBe(['C', 'A', 'B']);

    $component->call('sort', 'amount_gross');
    expect(invoiceListSerials($component))->toBe(['C', 'A', 'B']);

    $component->call('sort', 'created_at');
    expect(invoiceListSerials($component))->toBe(['B', 'C', 'A']);

    $component->call('sort', 'billable_id; drop table billing_invoices')->assertSet('sortBy', 'created_at');
});

it('opens the detail of an invoice with its PDF links and the way to its billable', function (): void {
    $b = invoiceListBillable('Acme', 'acme@example.com');
    $invoice = invoiceListInvoice($b, '2026-10-02 10:00:00', 'RE-77');

    $component = Livewire::test('mollie-billing::admin.invoices.index')
        ->call('openDetail', $invoice->id)
        ->assertSet('showDetail', true)
        ->assertSet('detailInvoiceId', $invoice->id)
        ->assertViewHas('detail', fn ($detail) => $detail?->is($invoice))
        ->assertSee('Pro plan')
        ->assertSee(route('billing.admin.billables.show', $b));

    expect(fn () => $component->set('detailInvoiceId', 999))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    Livewire::test('mollie-billing::admin.invoices.index')
        ->call('openDetail', 999999)
        ->assertSet('showDetail', false);
});

it('offers the ZIP export as a link carrying the filter of the list', function (): void {
    $b = invoiceListBillable('Acme', 'acme@example.com');
    invoiceListInvoice($b, '2026-10-02 10:00:00', 'RE-1');

    $component = Livewire::test('mollie-billing::admin.invoices.index')
        ->set('from', '2026-10-01')->set('to', '2026-10-31')->set('kind', 'subscription');

    expect($component->viewData('exportUrl'))
        ->toBe(route('billing.admin.invoices.export', ['from' => '2026-10-01', 'to' => '2026-10-31', 'kind' => 'subscription']));
});

it('lists the invoices screen in the admin navigation', function (): void {
    $this->actingAs(new InvoiceListAdminUser)
        ->get(route('billing.admin.invoices.index'))
        ->assertOk()
        ->assertSee(route('billing.admin.invoices.index'))
        ->assertSee(__('billing::admin.invoices.title'));
});

// ── The export ───────────────────────────────────────────────────────────────

it('exports exactly the filtered invoices as a ZIP of their PDFs', function (): void {
    $b = invoiceListBillable('Acme', 'acme@example.com');
    invoiceListInvoice($b, '2026-09-30 23:59:59', 'RE/26/0001');
    invoiceListInvoice($b, '2026-10-01 00:00:00', 'RE/26/0002');
    invoiceListInvoice($b, '2026-10-15 00:00:00', 'GS/26/0001', -500, InvoiceKind::Refund);
    invoiceListInvoice($b, '2026-11-01 00:00:00', 'RE/26/0003');

    $response = $this->actingAs(new InvoiceListAdminUser)
        ->get(route('billing.admin.invoices.export', ['from' => '2026-10-01', 'to' => '2026-10-31']));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/zip')
        ->assertDownload('invoices-2026-10-01_2026-10-31.zip');

    $entries = invoiceListZip(file_get_contents($response->baseResponse->getFile()->getPathname()));

    // Named as the single download names them.
    expect($entries)->toBe([
        'gs-26-0001.pdf' => '%PDF-1.4 GS/26/0001',
        're-26-0002.pdf' => '%PDF-1.4 RE/26/0002',
    ]);
});

it('regenerates a missing PDF for the export and names the one it cannot', function (): void {
    $b = invoiceListBillable('Acme', 'acme@example.com');
    invoiceListInvoice($b, '2026-10-01 10:00:00', 'RE-1');
    // No billable to render for: regeneration fails, the invoice must not vanish silently.
    invoiceListInvoice(null, '2026-10-02 10:00:00', 'RE-ORPHAN', withPdf: false);

    $response = $this->actingAs(new InvoiceListAdminUser)
        ->get(route('billing.admin.invoices.export', ['from' => '2026-10-01']));

    $response->assertOk();
    $entries = invoiceListZip(file_get_contents($response->baseResponse->getFile()->getPathname()));

    expect(array_keys($entries))->toBe([InvoiceArchiveService::MISSING_FILE, 're-1.pdf'])
        ->and($entries[InvoiceArchiveService::MISSING_FILE])->toContain('RE-ORPHAN');
});

it('refuses an empty export and one above invoices.export_max', function (): void {
    $b = invoiceListBillable('Acme', 'acme@example.com');
    invoiceListInvoice($b, '2026-10-01 10:00:00', 'RE-1');
    invoiceListInvoice($b, '2026-10-02 10:00:00', 'RE-2');

    $admin = new InvoiceListAdminUser;

    $this->actingAs($admin)
        ->get(route('billing.admin.invoices.export', ['from' => '2027-01-01']))
        ->assertNotFound();

    config()->set('mollie-billing.invoices.export_max', 1);

    $this->actingAs($admin)
        ->get(route('billing.admin.invoices.export'))
        ->assertStatus(422);

    $this->actingAs($admin)
        ->get(route('billing.admin.invoices.export', ['search' => 'RE-2']))
        ->assertOk();

    // The screen knows before anyone clicks: the button is disabled with this reason.
    Livewire::test('mollie-billing::admin.invoices.index')
        ->assertViewHas('exportMax', 1)
        ->assertViewHas('count', 2);
});

it('keeps the invoice list and its export behind the admin gate', function (): void {
    $b = invoiceListBillable('Acme', 'acme@example.com');
    invoiceListInvoice($b, '2026-10-01 10:00:00', 'RE-1');

    $this->actingAs(new InvoiceListPlainUser)
        ->get(route('billing.admin.invoices.export'))
        ->assertForbidden();

    $this->actingAs(new InvoiceListPlainUser)
        ->get(route('billing.admin.invoices.index'))
        ->assertForbidden();
});

// ── The single document ──────────────────────────────────────────────────────

it('names a PDF by its serial, a slash or a space in it becoming a dash', function (): void {
    $invoice = new BillingInvoice;
    $invoice->id = 42;

    foreach ([
        'RE/26/0001' => 're-26-0001.pdf',
        'RE /26/0001' => 're-26-0001.pdf',
        'RE\\26\\0001' => 're-26-0001.pdf',
        'IN-26000001' => 'in-26000001.pdf',
    ] as $serial => $expected) {
        $invoice->serial_number = $serial;
        expect($invoice->pdfFilename())->toBe($expected);
    }

    $invoice->serial_number = null;
    expect($invoice->pdfFilename())->toBe('invoice-42.pdf');
});

it('shows a PDF in the browser on the view route and sends it as an attachment on download', function (): void {
    $b = invoiceListBillable('Acme', 'acme@example.com');
    $invoice = invoiceListInvoice($b, '2026-10-01 10:00:00', 'RE/26/0009');

    $admin = new InvoiceListAdminUser;

    $view = $this->actingAs($admin)->get(route('billing.admin.invoice.view', ['invoice' => $invoice]));
    $view->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect((string) $view->headers->get('Content-Disposition'))->toStartWith('inline')->toContain('re-26-0009.pdf');

    $download = $this->actingAs($admin)->get(route('billing.admin.invoice.download', ['invoice' => $invoice]));
    $download->assertOk()->assertDownload('re-26-0009.pdf');
});

// ── UTC ──────────────────────────────────────────────────────────────────────

it('works in UTC whatever the server or the operator, and says so on every time', function (): void {
    // A Vienna server: the list must not follow it.
    config()->set('app.timezone', 'Europe/Vienna');
    date_default_timezone_set('Europe/Vienna');
    config()->set('mollie-billing.billing_timezone', 'Europe/Vienna');

    try {
        $b = invoiceListBillable('Acme', 'acme@example.com');
        // 31 October 23:30 UTC — already 1 November in Vienna, but October here.
        $invoice = invoiceListInvoice($b, '2026-10-31 23:30:00', 'late-october');

        $component = Livewire::test('mollie-billing::admin.invoices.index')
            ->set('from', '2026-10-01')->set('to', '2026-10-31');
        expect(invoiceListSerials($component))->toBe(['late-october']);

        $component->assertSeeHtml('<time datetime="2026-10-31T23:30:00+00:00"')
            ->assertSeeHtml('2026-10-31 23:30 <span')
            ->assertSee('UTC');

        $component->set('from', '2026-11-01')->set('to', '2026-11-30');
        expect(invoiceListSerials($component))->toBe([]);

        // The detail states it too, and so does the export's cut.
        $component->set('from', '')->set('to', '')->call('openDetail', $invoice->id)
            ->assertSee('2026-10-31 23:30 UTC');

        $response = $this->actingAs(new InvoiceListAdminUser)
            ->get(route('billing.admin.invoices.export', ['from' => '2026-10-01', 'to' => '2026-10-31']));
        expect(array_keys(invoiceListZip(file_get_contents($response->baseResponse->getFile()->getPathname()))))
            ->toBe(['late-october.pdf']);

        // Quick filters from the UTC calendar: New Year's Eve 23:30 UTC is still December.
        $this->travelTo(CarbonImmutable::parse('2026-12-31 23:30:00', 'UTC'));
        expect(AdminInvoiceFilter::presetRange('this_month'))->toBe(['2026-12-01', '2026-12-31'])
            ->and(AdminInvoiceFilter::presetRange('this_year'))->toBe(['2026-01-01', '2026-12-31']);
    } finally {
        date_default_timezone_set('UTC');
    }
});

// ── Cost of a render ─────────────────────────────────────────────────────────

it('reads the first page of the list off the (created_at, id) index', function (): void {
    $b = invoiceListBillable('Acme', 'acme@example.com');
    foreach (range(1, 30) as $i) {
        invoiceListInvoice($b, '2026-10-01 10:00:00', 'RE-'.$i, withPdf: false);
    }

    foreach ([new AdminInvoiceFilter, new AdminInvoiceFilter('2026-10-01', '2026-10-31')] as $filter) {
        $query = $filter->query()->orderBy('created_at', 'desc')->orderBy('id', 'desc')->limit(25);
        $plan = collect(DB::select('EXPLAIN QUERY PLAN '.$query->toSql(), $query->getBindings()))
            ->pluck('detail')->implode("\n");

        expect($plan)->toContain('billing_invoices_created_at_id_index')
            ->not->toContain('SCAN billing_invoices'."\n")
            ->not->toContain('TEMP B-TREE');
    }
});

it('counts once per render and keeps the sums while only sort, page or detail change', function (): void {
    $b = invoiceListBillable('Acme', 'acme@example.com');
    foreach (range(1, 30) as $i) {
        invoiceListInvoice($b, '2026-10-01 10:00:00', 'RE-'.$i, withPdf: false);
    }

    $sums = 0;
    $counts = 0;
    DB::listen(function ($query) use (&$sums, &$counts): void {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'sum(amount_net)')) {
            $sums++;
        } elseif (str_contains($sql, 'count(')) {
            $counts++;
        }
    });

    $component = Livewire::test('mollie-billing::admin.invoices.index');
    // The summary's count is the paginator's total: one aggregate, not two.
    expect([$sums, $counts])->toBe([1, 0]);
    $component->assertViewHas('invoices', fn ($p) => $p->total() === 30);

    // Sort, page, detail: the sums are kept; the paginator counts for itself (it must not
    // take a kept count as its total), still one aggregate per render.
    $component->call('sort', 'serial_number')->call('nextPage')->call('openDetail', BillingInvoice::query()->value('id'));
    expect([$sums, $counts])->toBe([1, 3]);

    // A new filter is a new summary, whose count the paginator takes again.
    $component->set('search', 'RE-1');
    expect([$sums, $counts])->toBe([2, 3]);

    // And a list left open catches up after a minute.
    invoiceListInvoice($b, '2026-10-02 10:00:00', 'RE-100', withPdf: false);
    $this->travel(61)->seconds();
    $component->call('sort', 'created_at');
    expect($sums)->toBe(3);
    $component->assertViewHas('count', 12);
});

// ── Review findings ──────────────────────────────────────────────────────────

it('shows an invoice booked after the sums were kept, instead of an empty list', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00:00', 'UTC'));
    $b = invoiceListBillable('Acme', 'acme@example.com');

    $component = Livewire::test('mollie-billing::admin.invoices.index')->call('applyPreset', 'this_month');
    $component->assertViewHas('count', 0);

    // Booked ten seconds later; the kept summary still says 0.
    $this->travel(10)->seconds();
    invoiceListInvoice($b, '2026-11-10 12:00:05', 'RE-NEW');

    $component->call('sort', 'serial_number');
    expect(invoiceListSerials($component))->toBe(['RE-NEW']);
    $component->assertViewHas('count', 1);
});

it('treats % and _ in the search as characters and matches the billable id exactly', function (): void {
    $acme = invoiceListBillable('Acme', 'acme@example.com');
    $globex = invoiceListBillable('Globex', 'globex@example.com');

    // Fixed payment ids: a random one may contain the digits of an id searched below.
    invoiceListInvoice($acme, '2026-10-01 10:00:00', 'RE-0001')->forceFill(['mollie_payment_id' => 'tr_acme'])->save();
    invoiceListInvoice($globex, '2026-10-02 10:00:00', 'GS_0001')->forceFill(['mollie_payment_id' => 'tr_globex'])->save();

    $serials = fn (string $term): array => (new AdminInvoiceFilter(search: $term))->query()
        ->orderBy('serial_number')->pluck('serial_number')->all();

    expect($serials('%'))->toBe([])
        ->and($serials('S_0'))->toBe(['GS_0001'])
        // "E_" would match "E-" if `_` were a wildcard.
        ->and($serials('E_'))->toBe([])
        ->and($serials('re-0'))->toBe(['RE-0001'])
        ->and($serials((string) $globex->getKey()))->toBe(['GS_0001'])
        // A fragment of an id is not an id.
        ->and($serials('%'.$globex->getKey()))->toBe([]);
});

it('ignores a date with a trailing newline instead of failing', function (): void {
    $b = invoiceListBillable('Acme', 'acme@example.com');
    invoiceListInvoice($b, '2026-10-01 10:00:00', 'RE-1');

    $filter = new AdminInvoiceFilter("2026-11-24\n", "2026-11-30\n");
    expect($filter->from)->toBeNull()->and($filter->to)->toBeNull();

    // Over HTTP, TrimStrings usually takes the newline off first; trimmed or ignored, the
    // answer is the archive, not a 500.
    $this->actingAs(new InvoiceListAdminUser)
        ->get('/billing/admin/invoices/export?from=2026-09-01%0A')
        ->assertOk();

    Livewire::withQueryParams(['from' => "2026-11-24\n"])
        ->test('mollie-billing::admin.invoices.index')
        ->assertViewHas('count', 1);
});

it('leaves no archive behind when an export fails halfway', function (): void {
    $b = invoiceListBillable('Acme', 'acme@example.com');
    $first = invoiceListInvoice($b, '2026-10-01 10:00:00', 'RE-1');

    $pattern = sys_get_temp_dir().'/billing-invoices-*';
    $before = glob($pattern) ?: [];

    $failing = (function () use ($first) {
        yield $first;

        throw new RuntimeException('disk went away');
    })();

    expect(fn () => app(InvoiceArchiveService::class)->build($failing))
        ->toThrow(RuntimeException::class, 'disk went away');

    gc_collect_cycles();

    expect(array_values(array_diff(glob($pattern) ?: [], $before)))->toBe([]);
});

it('names a PDF that arrived truncated from a remote disk in MISSING.txt', function (): void {
    config()->set('filesystems.disks.remote', ['driver' => 's3']);

    $whole = '%PDF-1.4 whole document';
    $remote = Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
    $remote->shouldReceive('exists')->andReturnTrue();
    $remote->shouldReceive('readStream')->andReturnUsing(function (string $path) use ($whole) {
        $stream = fopen('php://memory', 'w+b');
        // The broken transfer: a few bytes of the truncated one.
        fwrite($stream, $path === 'broken.pdf' ? '%PDF' : $whole);
        rewind($stream);

        return $stream;
    });
    $remote->shouldReceive('size')->andReturn(strlen($whole));
    Storage::set('remote', $remote);

    $b = invoiceListBillable('Acme', 'acme@example.com');
    invoiceListInvoice($b, '2026-10-01 10:00:00', 'RE-WHOLE', withPdf: false)
        ->forceFill(['pdf_disk' => 'remote', 'pdf_path' => 'whole.pdf'])->save();
    invoiceListInvoice($b, '2026-10-02 10:00:00', 'RE-BROKEN', withPdf: false)
        ->forceFill(['pdf_disk' => 'remote', 'pdf_path' => 'broken.pdf'])->save();

    $response = $this->actingAs(new InvoiceListAdminUser)->get(route('billing.admin.invoices.export'));
    $entries = invoiceListZip(file_get_contents($response->baseResponse->getFile()->getPathname()));

    expect(array_keys($entries))->toBe([InvoiceArchiveService::MISSING_FILE, 're-whole.pdf'])
        ->and($entries['re-whole.pdf'])->toBe($whole)
        ->and($entries[InvoiceArchiveService::MISSING_FILE])->toContain('RE-BROKEN');
});

it('regenerates at most MAX_REGENERATIONS missing PDFs per export and names the rest', function (): void {
    $stub = new class (app(\GraystackIT\MollieBilling\Services\Vat\VatCalculationService::class), app(\GraystackIT\MollieBilling\Services\Billing\InvoiceNumberGenerator::class)) extends \GraystackIT\MollieBilling\Services\Billing\InvoiceService
    {
        public int $renders = 0;

        public function regeneratePdf(BillingInvoice $invoice): bool
        {
            $this->renders++;
            $path = 'billing/invoices/regen-'.$invoice->id.'.pdf';
            Storage::disk('local')->put($path, '%PDF-1.4 regenerated');
            $invoice->forceFill(['pdf_disk' => 'local', 'pdf_path' => $path])->save();

            return true;
        }
    };
    $this->app->instance(\GraystackIT\MollieBilling\Services\Billing\InvoiceService::class, $stub);

    $max = InvoiceArchiveService::MAX_REGENERATIONS;
    $b = invoiceListBillable('Acme', 'acme@example.com');
    invoiceListInvoice($b, '2026-10-01 09:00:00', 'RE-STORED');
    foreach (range(1, $max + 2) as $i) {
        invoiceListInvoice($b, '2026-10-01 10:00:00', sprintf('RE-%03d', $i), withPdf: false);
    }

    $export = function () {
        $response = $this->actingAs(new InvoiceListAdminUser)->get(route('billing.admin.invoices.export'));

        return invoiceListZip(file_get_contents($response->baseResponse->getFile()->getPathname()));
    };

    $entries = $export();
    expect($stub->renders)->toBe($max)
        // The stored one, plus as many as were regenerated.
        ->and(count($entries) - 1)->toBe($max + 1)
        ->and($entries[InvoiceArchiveService::MISSING_FILE])
        ->toContain(__('billing::admin.invoices.export.deferred_header', ['max' => $max]))
        ->toContain(sprintf('RE-%03d', $max + 1))
        ->toContain(sprintf('RE-%03d', $max + 2));

    // What was regenerated is stored: the next export of the same range finishes.
    $entries = $export();
    expect($stub->renders)->toBe($max + 2)
        ->and($entries)->not->toHaveKey(InvoiceArchiveService::MISSING_FILE)
        ->and($entries)->toHaveCount($max + 3);
});

// ── CodeRabbit review ────────────────────────────────────────────────────────

/** An InvoiceService whose regeneration writes a PDF to the local disk, or fails for the given serials. */
function invoiceListRegeneratingService(array $failing = []): \GraystackIT\MollieBilling\Services\Billing\InvoiceService
{
    $stub = new class (app(\GraystackIT\MollieBilling\Services\Vat\VatCalculationService::class), app(\GraystackIT\MollieBilling\Services\Billing\InvoiceNumberGenerator::class)) extends \GraystackIT\MollieBilling\Services\Billing\InvoiceService
    {
        /** @var list<string> */
        public array $failing = [];

        /** @var list<string> */
        public array $attempts = [];

        public function regeneratePdf(BillingInvoice $invoice): bool
        {
            $this->attempts[] = (string) $invoice->serial_number;

            if (in_array($invoice->serial_number, $this->failing, true)) {
                throw new RuntimeException('cannot render '.$invoice->serial_number);
            }

            $path = 'billing/invoices/regen-'.$invoice->id.'.pdf';
            Storage::disk('local')->put($path, '%PDF-1.4 regenerated '.$invoice->serial_number);
            $invoice->forceFill(['pdf_disk' => 'local', 'pdf_path' => $path])->save();

            return true;
        }
    };
    $stub->failing = $failing;
    app()->instance(\GraystackIT\MollieBilling\Services\Billing\InvoiceService::class, $stub);

    return $stub;
}

it('reaches a regenerable PDF behind unrenderable ones on the next export', function (): void {
    $max = InvoiceArchiveService::MAX_REGENERATIONS;
    $failing = array_map(fn (int $i): string => sprintf('RE-BAD-%03d', $i), range(1, $max + 1));
    $stub = invoiceListRegeneratingService($failing);

    $b = invoiceListBillable('Acme', 'acme@example.com');
    foreach ($failing as $serial) {
        invoiceListInvoice($b, '2026-10-01 10:00:00', $serial, withPdf: false);
    }
    invoiceListInvoice($b, '2026-10-01 10:00:00', 'RE-GOOD', withPdf: false);

    $export = function () {
        $response = $this->actingAs(new InvoiceListAdminUser)->get(route('billing.admin.invoices.export'));

        return invoiceListZip(file_get_contents($response->baseResponse->getFile()->getPathname()));
    };

    $export();
    expect($stub->attempts)->toHaveCount($max)->not->toContain('RE-GOOD');

    // The failed ones are not retried at once: the budget goes to the rest.
    $stub->attempts = [];
    $entries = $export();

    expect($stub->attempts)->toBe([$failing[$max], 'RE-GOOD'])
        ->and($entries)->toHaveKey('re-good.pdf')
        ->and($entries[InvoiceArchiveService::MISSING_FILE])->toContain($failing[0])->toContain($failing[$max]);
});

it('compares the billable id only when the number fits a bigint', function (): void {
    config()->set('mollie-billing.billable_key_type', 'int');

    $sql = fn (string $term): string => (new AdminInvoiceFilter(search: $term))->query()->toSql();

    expect($sql((string) PHP_INT_MAX))->toContain('"billable_id" = ?')
        ->and($sql('9223372036854775808'))->not->toContain('"billable_id" = ?')
        ->and($sql('9999999999999999999'))->not->toContain('"billable_id" = ?');
});

it('names a PDF a remote disk refuses to read in MISSING.txt and still exports the rest', function (): void {
    config()->set('filesystems.disks.remote', ['driver' => 's3', 'throw' => true]);

    $whole = '%PDF-1.4 whole document';
    $remote = Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
    $remote->shouldReceive('exists')->andReturnTrue();
    $remote->shouldReceive('readStream')->andReturnUsing(function (string $path) use ($whole) {
        if ($path === 'unreadable.pdf') {
            throw \League\Flysystem\UnableToReadFile::fromLocation($path, 'access denied');
        }

        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, $whole);
        rewind($stream);

        return $stream;
    });
    $remote->shouldReceive('size')->andReturn(strlen($whole));
    Storage::set('remote', $remote);

    $b = invoiceListBillable('Acme', 'acme@example.com');
    invoiceListInvoice($b, '2026-10-01 10:00:00', 'RE-WHOLE', withPdf: false)
        ->forceFill(['pdf_disk' => 'remote', 'pdf_path' => 'whole.pdf'])->save();
    invoiceListInvoice($b, '2026-10-02 10:00:00', 'RE-UNREADABLE', withPdf: false)
        ->forceFill(['pdf_disk' => 'remote', 'pdf_path' => 'unreadable.pdf'])->save();

    $response = $this->actingAs(new InvoiceListAdminUser)->get(route('billing.admin.invoices.export'));
    $response->assertOk();
    $entries = invoiceListZip(file_get_contents($response->baseResponse->getFile()->getPathname()));

    expect(array_keys($entries))->toBe([InvoiceArchiveService::MISSING_FILE, 're-whole.pdf'])
        ->and($entries[InvoiceArchiveService::MISSING_FILE])->toContain('RE-UNREADABLE');
});

it('finds a name with umlauts by its own spelling on SQLite', function (): void {
    $b = invoiceListBillable('MÜLLER GmbH', 'office@mueller.test');
    invoiceListInvoice($b, '2026-10-01 10:00:00', 'RÉ-0001');

    $serials = fn (string $term): array => (new AdminInvoiceFilter(search: $term))->query()->pluck('serial_number')->all();

    expect($serials('MÜLLER'))->toBe(['RÉ-0001'])
        ->and($serials('RÉ-0001'))->toBe(['RÉ-0001'])
        ->and(TestBillable::query()->billableSearch('MÜLLER')->count())->toBe(1);
});

it('never lets one PDF overwrite another under a suffixed name', function (): void {
    $b = invoiceListBillable('Acme', 'acme@example.com');
    // Created first (lowest id), renamed below to the name the third one will be suffixed to.
    $taken = invoiceListInvoice($b, '2026-10-01 10:00:00', 'PLACEHOLDER');
    invoiceListInvoice($b, '2026-10-01 11:00:00', 'RE/26');
    $third = invoiceListInvoice($b, '2026-10-01 12:00:00', 'RE-26');
    $taken->forceFill(['serial_number' => 'RE-26-'.$third->id])->save();

    $response = $this->actingAs(new InvoiceListAdminUser)->get(route('billing.admin.invoices.export'));
    $entries = invoiceListZip(file_get_contents($response->baseResponse->getFile()->getPathname()));

    expect($entries)->toHaveCount(3)
        ->and(array_values($entries))->toContain('%PDF-1.4 PLACEHOLDER', '%PDF-1.4 RE/26', '%PDF-1.4 RE-26');
});

it('builds the archive from the invoices selected when the export was asked for', function (): void {
    config()->set('mollie-billing.invoices.export_max', 2);

    $b = invoiceListBillable('Acme', 'acme@example.com');
    invoiceListInvoice($b, '2026-10-01 10:00:00', 'RE-1');
    invoiceListInvoice($b, '2026-10-02 10:00:00', 'RE-2');

    // A renewal booked while the archive is being built.
    $this->app->bind(InvoiceArchiveService::class, fn ($app) => new class ($app->make(\GraystackIT\MollieBilling\Services\Billing\InvoiceService::class), $b) extends InvoiceArchiveService
    {
        public function __construct(\GraystackIT\MollieBilling\Services\Billing\InvoiceService $invoices, private readonly TestBillable $billable)
        {
            parent::__construct($invoices);
        }

        public function build(iterable $invoices): string
        {
            invoiceListInvoice($this->billable, '2026-10-03 10:00:00', 'RE-LATE');

            return parent::build($invoices);
        }
    });

    $response = $this->actingAs(new InvoiceListAdminUser)->get(route('billing.admin.invoices.export'));
    $entries = invoiceListZip(file_get_contents($response->baseResponse->getFile()->getPathname()));

    expect(array_keys($entries))->toBe(['re-1.pdf', 're-2.pdf']);
});

it('regenerates a PDF whose disk is no longer configured instead of failing on it', function (): void {
    invoiceListRegeneratingService();

    $b = invoiceListBillable('Acme', 'acme@example.com');
    $invoice = invoiceListInvoice($b, '2026-10-01 10:00:00', 'RE-MOVED', withPdf: false);
    $invoice->forceFill(['pdf_disk' => 'retired-disk', 'pdf_path' => 'billing/invoices/old.pdf'])->save();

    $service = app(\GraystackIT\MollieBilling\Services\Billing\InvoiceService::class);

    expect($service->hasStoredPdf($invoice))->toBeFalse()
        ->and($service->ensureStoredPdf($invoice)?->pdf_disk)->toBe('local');

    $this->actingAs(new InvoiceListAdminUser)
        ->get(route('billing.admin.invoice.download', $invoice))
        ->assertOk();
});

it('names an empty PDF on a local disk in MISSING.txt instead of packing it', function (): void {
    $b = invoiceListBillable('Acme', 'acme@example.com');
    invoiceListInvoice($b, '2026-10-01 10:00:00', 'RE-WHOLE');
    $empty = invoiceListInvoice($b, '2026-10-02 10:00:00', 'RE-EMPTY');
    // What an interrupted write leaves behind.
    Storage::disk('local')->put($empty->pdf_path, '');

    $response = $this->actingAs(new InvoiceListAdminUser)->get(route('billing.admin.invoices.export'));
    $entries = invoiceListZip(file_get_contents($response->baseResponse->getFile()->getPathname()));

    expect(array_keys($entries))->toBe([InvoiceArchiveService::MISSING_FILE, 're-whole.pdf'])
        ->and($entries[InvoiceArchiveService::MISSING_FILE])->toContain('RE-EMPTY');
});

it('names a PDF whose existence the disk cannot check in MISSING.txt and still exports the rest', function (): void {
    config()->set('filesystems.disks.remote', ['driver' => 's3', 'throw' => true]);

    $remote = Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
    $remote->shouldReceive('exists')->andThrow(\League\Flysystem\UnableToCheckFileExistence::forLocation('x.pdf'));
    Storage::set('remote', $remote);

    $b = invoiceListBillable('Acme', 'acme@example.com');
    invoiceListInvoice($b, '2026-10-01 10:00:00', 'RE-LOCAL');
    invoiceListInvoice($b, '2026-10-02 10:00:00', 'RE-REMOTE', withPdf: false)
        ->forceFill(['pdf_disk' => 'remote', 'pdf_path' => 'x.pdf'])->save();

    $response = $this->actingAs(new InvoiceListAdminUser)->get(route('billing.admin.invoices.export'));
    $response->assertOk();
    $entries = invoiceListZip(file_get_contents($response->baseResponse->getFile()->getPathname()));

    expect(array_keys($entries))->toBe([InvoiceArchiveService::MISSING_FILE, 're-local.pdf'])
        ->and($entries[InvoiceArchiveService::MISSING_FILE])->toContain('RE-REMOTE');
});

it('shows the last page instead of the empty state for a page past the end', function (): void {
    $b = invoiceListBillable('Acme', 'acme@example.com');
    invoiceListInvoice($b, '2026-10-01 10:00:00', 'RE-1');
    invoiceListInvoice($b, '2026-10-02 10:00:00', 'RE-2');

    $component = Livewire::withQueryParams(['page' => 3])->test('mollie-billing::admin.invoices.index');

    expect(invoiceListSerials($component))->toBe(['RE-2', 'RE-1'])
        ->and($component->viewData('invoices')->currentPage())->toBe(1)
        ->and($component->viewData('count'))->toBe(2);
});
