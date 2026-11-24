<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Models\BillingInvoice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;
use RuntimeException;
use ZipArchive;

/**
 * Packs invoice PDFs into one ZIP file — the admin's "export what I am looking at".
 *
 * Read-only: no booking, no Mollie call. A document that is missing on the disk is
 * regenerated first, exactly as a single download would ({@see InvoiceService::ensureStoredPdf()});
 * one that cannot be is named in `MISSING.txt` inside the archive instead of being dropped
 * without a word, so an archive handed to the accountant never looks complete while it is not.
 */
class InvoiceArchiveService
{
    public const MISSING_FILE = 'MISSING.txt';

    /**
     * The most missing PDFs one export regenerates. A render (DOMPDF plus the e-invoice)
     * takes about a second and may wait on a lock, so after a disk migration an export of
     * a year would otherwise run into the web server's timeout every time and never
     * complete. Above this the invoices are named in MISSING.txt; what was regenerated is
     * stored, so the next export of the same range gets further.
     */
    public const MAX_REGENERATIONS = 25;

    /**
     * How long an export skips an invoice whose regeneration just failed. Without it, the
     * same unrenderable invoices at the head of the range used up the budget on every
     * export, and the regenerable ones behind them were never reached. Named in
     * MISSING.txt meanwhile; a single download still tries at once.
     */
    public const FAILED_REGENERATION_TTL_SECONDS = 600;

    private const FAILED_REGENERATION_KEY = 'billing:invoice-export-regeneration-failed:';

    public function __construct(private readonly InvoiceService $invoices) {}

    public static function isAvailable(): bool
    {
        return class_exists(ZipArchive::class);
    }

    /**
     * The most invoices one export may hold. The archive is built inside the request, so
     * the cap keeps a careless "all invoices" from running into the web server's timeout;
     * narrow the date range to export more.
     */
    public static function maxInvoices(): int
    {
        return max(1, (int) config('mollie-billing.invoices.export_max', 1000));
    }

    /**
     * Writes the archive to a temporary file and returns its path; the caller deletes it.
     *
     * @param  iterable<BillingInvoice>  $invoices
     */
    public function build(iterable $invoices): string
    {
        if (! self::isAvailable()) {
            throw new RuntimeException('Exporting invoices as a ZIP archive needs the PHP zip extension (ext-zip).');
        }

        $path = tempnam(sys_get_temp_dir(), 'billing-invoices-');
        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file for the invoice archive.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($path);

            throw new RuntimeException('Could not open the invoice archive for writing.');
        }

        /** @var list<string> $copies remote documents staged locally until the archive is closed */
        $copies = [];
        $names = [];
        $missing = [];
        $deferred = [];
        $regenerations = 0;
        $closed = false;

        try {
            foreach ($invoices as $invoice) {
                if ($this->invoices->hasStoredPdf($invoice)) {
                    $stored = $invoice;
                } elseif (Cache::has(self::FAILED_REGENERATION_KEY.$invoice->id)) {
                    $stored = null;
                } elseif ($regenerations < self::MAX_REGENERATIONS) {
                    $regenerations++;
                    $stored = $this->invoices->ensureStoredPdf($invoice);

                    if ($stored === null) {
                        Cache::put(self::FAILED_REGENERATION_KEY.$invoice->id, true, self::FAILED_REGENERATION_TTL_SECONDS);
                    }
                } else {
                    $deferred[] = $this->describe($invoice);

                    continue;
                }

                if ($stored === null) {
                    $missing[] = $this->describe($invoice);

                    continue;
                }

                $name = $this->uniqueName($stored, $names);
                $source = $this->localPathOf($stored, $copies);

                if ($source === null || ! $zip->addFile($source, $name)) {
                    $missing[] = $this->describe($stored);

                    continue;
                }

                $names[$name] = true;
            }

            if ($missing !== [] || $deferred !== []) {
                $zip->addFromString(self::MISSING_FILE, $this->missingNote($missing, $deferred));
            }

            // libzip reads the added files only now, so the staged copies must outlive this.
            $closed = true;
            if ($zip->close() !== true) {
                throw new RuntimeException('Could not write the invoice archive.');
            }
        } catch (\Throwable $e) {
            // Discard before deleting: ZipArchive's destructor closes an open archive, and
            // that close would write the half-built ZIP back to the path just unlinked —
            // left in the temp directory for good.
            if (! $closed) {
                try {
                    $zip->unchangeAll();
                    @$zip->close();
                } catch (\Throwable) {
                    // Already unusable; the unlink below is all that is left to do.
                }
            }

            @unlink($path);

            throw $e;
        } finally {
            foreach ($copies as $copy) {
                @unlink($copy);
            }
        }

        return $path;
    }

    /**
     * A path on this machine holding the PDF: the file itself on a local disk, a temporary
     * copy of it on any other.
     *
     * @param  list<string>  $copies
     */
    private function localPathOf(BillingInvoice $invoice, array &$copies): ?string
    {
        $disk = Storage::disk($invoice->pdf_disk);

        if (config("filesystems.disks.{$invoice->pdf_disk}.driver") === 'local') {
            return $disk->path($invoice->pdf_path);
        }

        // A disk configured with `throw => true` throws where others return null; either way
        // it is this one document that is missing, not the archive.
        try {
            $stream = $disk->readStream($invoice->pdf_path);
        } catch (FilesystemException) {
            return null;
        }

        if (! is_resource($stream)) {
            return null;
        }

        $copy = tempnam(sys_get_temp_dir(), 'billing-invoice-pdf-');
        if ($copy === false) {
            fclose($stream);

            return null;
        }

        // Staged for cleanup before anything can fail, so a broken copy is deleted too.
        $copies[] = $copy;

        $target = fopen($copy, 'wb');
        if ($target === false) {
            fclose($stream);

            return null;
        }

        $copied = stream_copy_to_stream($stream, $target);
        fclose($target);
        fclose($stream);

        // A stream that broke off mid-transfer leaves a truncated PDF. Packed under its
        // normal name, it is an unreadable invoice in an archive that looks complete; named
        // in MISSING.txt instead, it is a gap the operator can see.
        try {
            $expected = $disk->size($invoice->pdf_path);
        } catch (\Throwable) {
            return null;
        }

        return $copied !== false && $copied > 0 && $copied === $expected ? $copy : null;
    }

    /**
     * @param  list<string>  $missing  could not be produced
     * @param  list<string>  $deferred  not attempted: over MAX_REGENERATIONS
     */
    private function missingNote(array $missing, array $deferred): string
    {
        $sections = [];

        if ($missing !== []) {
            $sections[] = __('billing::admin.invoices.export.missing_header')."\n\n".implode("\n", $missing);
        }

        if ($deferred !== []) {
            $sections[] = __('billing::admin.invoices.export.deferred_header', ['max' => self::MAX_REGENERATIONS])
                ."\n\n".implode("\n", $deferred);
        }

        return implode("\n\n", $sections)."\n";
    }

    /** @param  array<string, true>  $names */
    private function uniqueName(BillingInvoice $invoice, array $names): string
    {
        $name = $invoice->pdfFilename();

        // Two documents without a serial cannot collide (the id is in the name), but two
        // serials that slug to the same string can — and the suffixed name can be another
        // serial's own ("RE/26" with id 3 is "re-26-3.pdf", as is serial "RE-26-3"). addFile()
        // overwrites by default, so a taken name would silently replace that document.
        $base = substr($name, 0, -4);
        for ($n = 0; isset($names[$name]); $n++) {
            $name = $base.'-'.$invoice->id.($n === 0 ? '' : '-'.$n).'.pdf';
        }

        return $name;
    }

    private function describe(BillingInvoice $invoice): string
    {
        return trim(($invoice->serial_number ?? '').' (#'.$invoice->id.', '.$invoice->created_at?->toDateString().')');
    }
}
