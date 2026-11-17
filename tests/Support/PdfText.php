<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Support;

use Smalot\PdfParser\Parser;

/**
 * The text of a PDF, read in PHP.
 *
 * The browser suite used to shell out to `pdftotext` and skipped the content check
 * wherever it was not installed — so on a machine without poppler the PDFs were only
 * checked to start with `%PDF`. `smalot/pdfparser` (require-dev) reads DOMPDF's output in
 * every environment, so the check always runs.
 */
final class PdfText
{
    /** Whitespace collapsed to single spaces: PDF text comes back in layout fragments. */
    public static function of(string $pdf): string
    {
        $text = (new Parser)->parseContent($pdf)->getText();

        return self::squash($text);
    }

    public static function squash(string $text): string
    {
        // pdfparser returns the non-breaking spaces DOMPDF writes between a currency
        // amount and its symbol as U+00A0; they are spaces for the reader too.
        $text = str_replace(["\u{00A0}", "\u{202F}"], ' ', $text);

        return (string) preg_replace('/\s+/u', ' ', trim($text));
    }
}
