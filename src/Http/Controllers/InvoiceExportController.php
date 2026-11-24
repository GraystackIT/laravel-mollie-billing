<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Http\Controllers;

use GraystackIT\MollieBilling\Services\Billing\InvoiceArchiveService;
use GraystackIT\MollieBilling\Support\AdminInvoiceFilter;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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
        $count = $filter->query()->count();

        abort_if($count === 0, 404, __('billing::admin.invoices.export.empty'));
        abort_if($count > InvoiceArchiveService::maxInvoices(), 422, __('billing::admin.invoices.export.too_many', [
            'max' => InvoiceArchiveService::maxInvoices(),
        ]));

        // Paged by id so a year of invoices is never held in memory at once.
        $path = $archive->build($filter->query()->lazyById(100));

        return response()
            ->download($path, $filter->archiveName(), ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }
}
