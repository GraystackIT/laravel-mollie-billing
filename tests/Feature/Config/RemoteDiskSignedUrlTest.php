<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\OssExportStatus;
use GraystackIT\MollieBilling\Http\Controllers\OssExportDownloadController;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingOssExport;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/*
 * `invoices.temporary_url_expiry` and `oss.temporary_url_expiry` against a REAL S3 disk:
 * Laravel's `s3` driver, Flysystem's AwsS3V3Adapter and the AWS SDK's SigV4 presigner —
 * the code that writes the signed link a customer is redirected to. The only thing not
 * real is the network: the SDK's transport handler answers the one HeadObject the download
 * asks (does the file exist?), and the endpoint is a host that does not resolve. Signing a
 * URL needs no connection, so what is asserted is exactly what S3 would be handed.
 *
 * The other OperationsConfigKeysTest cases fake the disk and read the expiry off a URL
 * builder callback; this checks the signature itself: `X-Amz-Expires` is the configured
 * minutes, and the link expires at "now + minutes".
 */

/** @return ArrayObject<int, string> the S3 operations the SDK was asked to send */
function realS3Disk(string $name): ArrayObject
{
    $sent = new ArrayObject;

    config()->set("filesystems.disks.{$name}", [
        'driver' => 's3',
        'key' => 'AKIABILLINGTEST00001',
        'secret' => 'billing-test-secret-key-not-a-real-one-0000',
        'region' => 'eu-central-1',
        'bucket' => 'billing-docs',
        // Unroutable on purpose (RFC 2606 `.invalid`): nothing may leave the process.
        'endpoint' => 'https://s3.billing-test.invalid',
        'use_path_style_endpoint' => true,
        'throw' => true,
        'handler' => function (\Aws\CommandInterface $command, \Psr\Http\Message\RequestInterface $request) use ($sent) {
            $sent[] = $command->getName();

            return \GuzzleHttp\Promise\Create::promiseFor(new \Aws\Result(['@metadata' => ['statusCode' => 200]]));
        },
    ]);

    app('filesystem')->forgetDisk($name);

    return $sent;
}

/**
 * @return array{host: string, path: string, query: array<string, string>}
 */
function signedUrlParts(string $url): array
{
    $parts = parse_url($url);
    parse_str((string) ($parts['query'] ?? ''), $query);

    return ['host' => (string) ($parts['host'] ?? ''), 'path' => (string) ($parts['path'] ?? ''), 'query' => $query];
}

/**
 * The SDK signs with PHP's own clock; the controller asks for "now + minutes" on Laravel's.
 * Frozen at the current whole second, the two agree up to the second the request may tick
 * over — which the presigner then takes off X-Amz-Expires, so date + expires is exact.
 */
function freezeAtWholeSecond(): Carbon
{
    $now = Carbon::createFromTimestampUTC(time());
    Carbon::setTestNow($now);

    return $now;
}

afterEach(function (): void {
    Carbon::setTestNow();
});

it('signs the invoice download link for exactly invoices.temporary_url_expiry minutes', function (int $minutes): void {
    $sent = realS3Disk('s3-invoices');

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'S3 Co', 'email' => uniqid('s3-').'@x.test', 'billing_country' => 'DE']);
    $invoice = new BillingInvoice;
    $invoice->forceFill([
        'billable_type' => $billable->getMorphClass(), 'billable_id' => $billable->getKey(),
        'mollie_payment_id' => uniqid('tr_s3_'), 'serial_number' => 'S3-0001',
        'invoice_kind' => InvoiceKind::Subscription, 'status' => InvoiceStatus::Paid,
        'country' => 'DE', 'currency' => 'EUR',
        'amount_net' => 1000, 'amount_vat' => 190, 'amount_gross' => 1190, 'refunded_net' => 0,
        'line_items' => [['kind' => 'plan', 'label' => 'Pro', 'amount_net' => 1000, 'vat_rate' => 19.0, 'vat_amount' => 190, 'amount_gross' => 1190]],
        'pdf_disk' => 's3-invoices', 'pdf_path' => 'books/2027/S3-0001.pdf',
    ])->save();

    \GraystackIT\MollieBilling\MollieBilling::resolveBillableUsing(fn () => $billable);
    \GraystackIT\MollieBilling\MollieBilling::authUsing(fn (): bool => true);
    config()->set('mollie-billing.invoices.temporary_url_expiry', $minutes);

    $now = freezeAtWholeSecond();
    $location = (string) $this->get(route('billing.invoice.download', ['invoice' => $invoice->id]))
        ->assertRedirect()
        ->headers->get('Location');

    $url = signedUrlParts($location);
    $signedAt = Carbon::createFromFormat('Ymd\THis\Z', $url['query']['X-Amz-Date'], 'UTC');

    expect($url['host'])->toBe('s3.billing-test.invalid')
        ->and($url['path'])->toBe('/billing-docs/books/2027/S3-0001.pdf')
        ->and($url['query']['X-Amz-Algorithm'])->toBe('AWS4-HMAC-SHA256')
        ->and($url['query']['X-Amz-Credential'])->toStartWith('AKIABILLINGTEST00001/')
        ->and($url['query']['X-Amz-Credential'])->toContain('/eu-central-1/s3/aws4_request')
        ->and($url['query']['X-Amz-Signature'])->toMatch('/^[0-9a-f]{64}$/')
        // The configured minutes, give or take the second the request may have ticked over …
        ->and((int) $url['query']['X-Amz-Expires'])->toBeGreaterThanOrEqual($minutes * 60 - 1)
        ->and((int) $url['query']['X-Amz-Expires'])->toBeLessThanOrEqual($minutes * 60)
        // … and the link dies exactly at "now + minutes".
        ->and($signedAt->getTimestamp() + (int) $url['query']['X-Amz-Expires'])->toBe($now->getTimestamp() + $minutes * 60)
        // The file was looked up, never fetched through the app.
        ->and($sent->getArrayCopy())->toBe(['HeadObject']);
})->with([5, 45]);

it('signs the OSS export link for exactly oss.temporary_url_expiry minutes, as an attachment', function (int $minutes): void {
    $sent = realS3Disk('s3-oss');

    $export = BillingOssExport::create([
        'year' => 2027, 'status' => OssExportStatus::Ready, 'disk' => 's3-oss', 'path' => 'oss/2027.csv',
    ]);

    config()->set('mollie-billing.oss.temporary_url_expiry', $minutes);

    $now = freezeAtWholeSecond();
    $response = app(OssExportDownloadController::class)(Request::create('/x'), $export);

    expect($response)->toBeInstanceOf(RedirectResponse::class);

    $url = signedUrlParts($response->getTargetUrl());
    $signedAt = Carbon::createFromFormat('Ymd\THis\Z', $url['query']['X-Amz-Date'], 'UTC');

    expect($url['path'])->toBe('/billing-docs/oss/2027.csv')
        ->and($url['query']['response-content-disposition'])->toBe('attachment; filename="oss-export-2027.csv"')
        ->and($url['query']['X-Amz-Signature'])->toMatch('/^[0-9a-f]{64}$/')
        ->and((int) $url['query']['X-Amz-Expires'])->toBeGreaterThanOrEqual($minutes * 60 - 1)
        ->and((int) $url['query']['X-Amz-Expires'])->toBeLessThanOrEqual($minutes * 60)
        ->and($signedAt->getTimestamp() + (int) $url['query']['X-Amz-Expires'])->toBe($now->getTimestamp() + $minutes * 60)
        ->and($sent->getArrayCopy())->toBe(['HeadObject']);
})->with([10, 90]);
