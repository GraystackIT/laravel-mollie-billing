<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Http\Controllers;

use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceArchiveService;
use GraystackIT\MollieBilling\Support\AdminInvoiceFilter;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\LazyCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin's ZIP export of the invoice list: the same filter the screen shows, as a
 * query string, packed by {@see InvoiceArchiveService}. Admin-only through the route group.
 */
class InvoiceExportController extends Controller
{
    public function __invoke(Request $request, InvoiceArchiveService $archive): Response
    {
        abort_unless(InvoiceArchiveService::isAvailable(), 501, __('billing::admin.invoices.export.unavailable'));

        $filter = AdminInvoiceFilter::fromArray($request->query());
        $max = InvoiceArchiveService::maxInvoices();

        // The selection is fixed once, one past the cap: a renewal booked while the archive
        // is being built must neither push it past export_max nor slip into it unannounced.
        $ids = $filter->query()->orderBy('id')->limit($max + 1)->pluck('id');

        abort_if($ids->isEmpty(), 404, __('billing::admin.invoices.export.empty'));
        abort_if($ids->count() > $max, 422, __('billing::admin.invoices.export.too_many', ['max' => $max]));

        // Loaded a page at a time so a year of invoices is never held in memory at once.
        $invoices = LazyCollection::make(function () use ($ids) {
            foreach ($ids->chunk(100) as $page) {
                foreach (BillingInvoice::query()->whereKey($page->values()->all())->orderBy('id')->get() as $invoice) {
                    yield $invoice;
                }
            }
        });

        $path = $archive->build($invoices);

        return response()
            ->download($path, $filter->archiveName(), ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }
}
