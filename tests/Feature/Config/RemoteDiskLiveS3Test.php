<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\OssExportStatus;
use GraystackIT\MollieBilling\Http\Controllers\OssExportDownloadController;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingOssExport;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
 * The signed download links against a REAL S3-compatible server: the file is uploaded, the
 * link the package redirects to is fetched over HTTP — 200 with the file inside the window,
 * 403 once `temporary_url_expiry` has run out.
 *
 * Optional: skipped unless `BILLING_S3_ENDPOINT` is set (and reachable). `composer test:s3`
 * runs it against Laravel Herd's S3 service (RustFS or MinIO on 127.0.0.1:9000, user `herd`,
 * secret `secretkey`); point it anywhere else with BILLING_S3_ENDPOINT / BILLING_S3_KEY /
 * BILLING_S3_SECRET / BILLING_S3_REGION. A bucket of its own is created and removed again.
 * The expiry case waits just over a minute, because the server checks the real clock.
 *
 * RemoteDiskSignedUrlTest checks the signature itself without a server; this is the half
 * that needs one — that the server honours it.
 */

function liveS3DiskOrSkip(): string
{
    $endpoint = (string) env('BILLING_S3_ENDPOINT', '');

    if ($endpoint === '') {
        test()->markTestSkipped('No S3 server configured (BILLING_S3_ENDPOINT). Run `composer test:s3` with Herd\'s S3 service, see docs/configuration.md.');
    }

    $bucket = 'mollie-billing-test-'.strtolower(bin2hex(random_bytes(4)));

    config()->set('filesystems.disks.live-s3', [
        'driver' => 's3',
        'key' => (string) env('BILLING_S3_KEY', 'herd'),
        'secret' => (string) env('BILLING_S3_SECRET', 'secretkey'),
        'region' => (string) env('BILLING_S3_REGION', 'us-east-1'),
        'bucket' => $bucket,
        'endpoint' => $endpoint,
        'use_path_style_endpoint' => true,
        'throw' => true,
    ]);
    app('filesystem')->forgetDisk('live-s3');

    try {
        Storage::disk('live-s3')->getClient()->createBucket(['Bucket' => $bucket]);
    } catch (\Throwable $e) {
        test()->markTestSkipped("S3 server at {$endpoint} not usable: ".$e->getMessage());
    }

    return $bucket;
}

function dropLiveS3Bucket(string $bucket): void
{
    $disk = Storage::disk('live-s3');

    foreach ($disk->allFiles() as $file) {
        $disk->delete($file);
    }

    $disk->getClient()->deleteBucket(['Bucket' => $bucket]);
}

it('serves the invoice through a signed link inside invoices.temporary_url_expiry, and refuses it after', function (): void {
    $bucket = liveS3DiskOrSkip();

    try {
        $pdf = "%PDF-1.4\n% live S3 check ".bin2hex(random_bytes(8))."\n";
        Storage::disk('live-s3')->put('books/2027/LIVE-0001.pdf', $pdf);

        /** @var TestBillable $billable */
        $billable = TestBillable::create(['name' => 'Live S3', 'email' => uniqid('lives3-').'@x.test', 'billing_country' => 'DE']);
        $invoice = new BillingInvoice;
        $invoice->forceFill([
            'billable_type' => $billable->getMorphClass(), 'billable_id' => $billable->getKey(),
            'mollie_payment_id' => uniqid('tr_live_'), 'serial_number' => 'LIVE-0001',
            'invoice_kind' => InvoiceKind::Subscription, 'status' => InvoiceStatus::Paid,
            'country' => 'DE', 'currency' => 'EUR',
            'amount_net' => 1000, 'amount_vat' => 190, 'amount_gross' => 1190, 'refunded_net' => 0,
            'line_items' => [['kind' => 'plan', 'label' => 'Pro', 'amount_net' => 1000, 'vat_rate' => 19.0, 'vat_amount' => 190, 'amount_gross' => 1190]],
            'pdf_disk' => 'live-s3', 'pdf_path' => 'books/2027/LIVE-0001.pdf',
        ])->save();

        \GraystackIT\MollieBilling\MollieBilling::resolveBillableUsing(fn () => $billable);
        \GraystackIT\MollieBilling\MollieBilling::authUsing(fn (): bool => true);
        config()->set('mollie-billing.invoices.temporary_url_expiry', 1);

        $url = (string) $this->get(route('billing.invoice.download', ['invoice' => $invoice->id]))
            ->assertRedirect()
            ->headers->get('Location');

        expect($url)->toStartWith(rtrim((string) env('BILLING_S3_ENDPOINT'), '/').'/'.$bucket.'/books/2027/LIVE-0001.pdf?');

        // Inside the minute: the server hands out the file.
        $inside = Http::get($url);
        expect($inside->status())->toBe(200)
            ->and($inside->body())->toBe($pdf);

        // A tampered link is refused — the signature is what the server checks.
        $tampered = (string) preg_replace('/X-Amz-Expires=\d+/', 'X-Amz-Expires=86400', $url);
        expect($tampered)->not->toBe($url)
            ->and(Http::get($tampered)->status())->toBe(403);

        // Past the minute: refused.
        sleep(62);
        expect(Http::get($url)->status())->toBe(403);
    } finally {
        dropLiveS3Bucket($bucket);
    }
})->group('s3-live');

it('serves the OSS export through a signed link as an attachment', function (): void {
    $bucket = liveS3DiskOrSkip();

    try {
        Storage::disk('live-s3')->put('oss/2027.csv', "year;country\n2027;DE\n");

        $export = BillingOssExport::create([
            'year' => 2027, 'status' => OssExportStatus::Ready, 'disk' => 'live-s3', 'path' => 'oss/2027.csv',
        ]);
        config()->set('mollie-billing.oss.temporary_url_expiry', 5);

        $response = app(OssExportDownloadController::class)(Request::create('/x'), $export);
        expect($response)->toBeInstanceOf(RedirectResponse::class);

        $download = Http::get($response->getTargetUrl());

        expect($download->status())->toBe(200)
            ->and($download->body())->toBe("year;country\n2027;DE\n")
            ->and($download->header('Content-Disposition'))->toBe('attachment; filename="oss-export-2027.csv"');
    } finally {
        dropLiveS3Bucket($bucket);
    }
})->group('s3-live');
