<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Http\Controllers;

use GraystackIT\MollieBilling\Contracts\AuthorizesBillingAdmin;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
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
        abort_unless($billable !== null, 404);

        // Tenant access: the requester is a member/owner of this billable.
        // Admin access: the requester can access the billing admin panel and
        // is therefore allowed to download any billable's invoice PDFs.
        $user = $request->user();
        $isAdmin = $user instanceof AuthorizesBillingAdmin && $user->canAccessBillingAdmin();
        $isAuthorizedTenant = MollieBilling::authorizes($request, $billable);

        abort_unless($isAdmin || $isAuthorizedTenant, 403);

        // A missing document — no pointer, or a pointer to a file that is gone — is
        // regenerated rather than refused; a `hasPdf()` guard here 404ed an invoice whose
        // render had failed forever, for an invoice the customer had paid.
        $invoice = app(InvoiceService::class)->ensureStoredPdf($invoice);

        abort_unless($invoice instanceof BillingInvoice, 404);

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk($invoice->pdf_disk);
        $filename = $invoice->pdfFilename();

        // The admin's "view" route asks for the document in the browser tab instead of
        // as a download; every other route keeps the attachment it always sent.
        $inline = ($request->route()?->defaults['disposition'] ?? null) === 'inline';

        // S3-compatible disks: generate a temporary URL and redirect.
        // Local disks also have temporaryUrl() but it generates a signed route
        // back to the same app, which is pointless here — stream directly instead.
        $driver = config("filesystems.disks.{$invoice->pdf_disk}.driver");

        if ($driver !== 'local' && method_exists($disk, 'temporaryUrl')) {
            try {
                $expiry = (int) config('mollie-billing.invoices.temporary_url_expiry', 30);
                $options = $inline
                    ? ['ResponseContentDisposition' => 'inline; filename="'.$filename.'"', 'ResponseContentType' => 'application/pdf']
                    : [];
                $url = $disk->temporaryUrl($invoice->pdf_path, now()->addMinutes($expiry), $options);

                return redirect($url);
            } catch (\RuntimeException) {
                // Driver does not support temporary URLs — fall through to streaming.
            }
        }

        // Local disk or fallback: stream the file directly.
        if ($inline) {
            return $disk->response($invoice->pdf_path, $filename, ['Content-Type' => 'application/pdf']);
        }

        return $disk->download($invoice->pdf_path, $filename);
    }
}
