<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Http\Controllers;

use GraystackIT\MollieBilling\Models\BillingOssExport;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class OssExportDownloadController extends Controller
{
    public function __invoke(Request $request, BillingOssExport $export): Response
    {
        abort_unless($export->isReady(), 404);

        $disk = Storage::disk((string) $export->disk);
        $filename = "oss-export-{$export->year}.csv";

        if (! $disk->exists((string) $export->path)) {
            abort(404);
        }

        $driver = config("filesystems.disks.{$export->disk}.driver");

        // S3-compatible disks: redirect to a short-lived signed URL so the
        // payload streams directly from the bucket. Local disks technically
        // also support temporaryUrl() but generate a signed route back to the
        // app, so we stream them inline instead.
        if ($driver !== 'local' && method_exists($disk, 'temporaryUrl')) {
            try {
                $expiry = (int) config('mollie-billing.oss.temporary_url_expiry', 30);
                $url = $disk->temporaryUrl(
                    (string) $export->path,
                    now()->addMinutes($expiry),
                    ['ResponseContentDisposition' => 'attachment; filename="'.$filename.'"'],
                );

                return redirect($url);
            } catch (\RuntimeException) {
                // Driver does not support temporary URLs — fall through to streaming.
            }
        }

        return $disk->download((string) $export->path, $filename);
    }
}
