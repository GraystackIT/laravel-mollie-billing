<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Http\Controllers;

use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class InvoiceDownloadController extends Controller
{
    public function __invoke(Request $request): Response
    {
        // Resolve the invoice manually. Implicit model binding relies on the
        // controller's type-hinted parameter, but Laravel's positional
        // dependency resolution injects a wrapping route parameter (e.g.
        // {organization}) ahead of {invoice} when both are present, which
        // breaks typed binding. Pulling the raw key and loading the model
        // by hand side-steps both issues.
        $invoiceKey = $request->route('invoice');
        $invoice = $invoiceKey instanceof BillingInvoice
            ? $invoiceKey
            : BillingInvoice::find($invoiceKey);

        abort_unless($invoice instanceof BillingInvoice, 404);

        $billable = $invoice->billable;

        abort_unless($billable && MollieBilling::authorizes($request, $billable), 403);
        abort_unless($invoice->hasPdf(), 404);

        $disk = Storage::disk($invoice->pdf_disk);
        $filename = Str::slug($invoice->serial_number ?? 'invoice-'.$invoice->id).'.pdf';

        // S3-compatible disks: generate a temporary URL and redirect.
        // Local disks also have temporaryUrl() but it generates a signed route
        // back to the same app, which is pointless here — stream directly instead.
        $driver = config("filesystems.disks.{$invoice->pdf_disk}.driver");

        if ($driver !== 'local' && method_exists($disk, 'temporaryUrl')) {
            try {
                $expiry = (int) config('mollie-billing.invoices.temporary_url_expiry', 30);
                $url = $disk->temporaryUrl($invoice->pdf_path, now()->addMinutes($expiry));

                return redirect($url);
            } catch (\RuntimeException) {
                // Driver does not support temporary URLs — fall through to streaming.
            }
        }

        // Local disk or fallback: stream the file directly.
        return $disk->download($invoice->pdf_path, $filename);
    }
}
