<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Jobs\RevokeMollieMandateJob;
use Mockery as M;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Http\Requests\RevokeMandateRequest;
use Mollie\Api\Http\Response as MollieResponse;
use Mollie\Laravel\Facades\Mollie;
use Psr\Http\Message\RequestInterface as PsrRequest;
use Psr\Http\Message\StreamInterface as PsrStream;

/** Minimal Mollie Response double — ApiException requires a real one. */
function mandateApiException(string $message, int $code): ApiException
{
    $stream = M::mock(PsrStream::class);
    $stream->shouldReceive('__toString')->andReturn('');
    $psrRequest = M::mock(PsrRequest::class);
    $psrRequest->shouldReceive('getBody')->andReturn($stream);
    $response = M::mock(MollieResponse::class);
    $response->shouldReceive('json')->andReturn((object) []);
    $response->shouldReceive('getPsrRequest')->andReturn($psrRequest);

    return new ApiException($response, $message, $code);
}

it('revokes the mandate through a typed request object', function (): void {
    $sent = null;

    Mollie::shouldReceive('send')
        ->once()
        ->andReturnUsing(function ($request) use (&$sent) {
            $sent = $request;

            return (object) [];
        });

    (new RevokeMollieMandateJob('cst_x', 'mdt_x'))->handle();

    expect($sent)->toBeInstanceOf(RevokeMandateRequest::class);
    expect($sent->resolveResourcePath())->toBe('customers/cst_x/mandates/mdt_x');
});

it('treats an already revoked mandate as success', function (int $status): void {
    // CleanupOrphanedBillablesJob and the RevokePreviousMandate listener can both
    // dispatch for the same pair, so the loser finding nothing left is normal — it must
    // not burn all three tries and file a failed_jobs row for work already done.
    Mollie::shouldReceive('send')
        ->once()
        ->andThrow(mandateApiException('Mandate not found', $status));

    $job = new RevokeMollieMandateJob('cst_x', 'mdt_x');

    expect(fn () => $job->handle())->not->toThrow(Throwable::class);
})->with([404, 410]);

it('still retries a genuine Mollie failure', function (): void {
    Mollie::shouldReceive('send')
        ->once()
        ->andThrow(mandateApiException('Service unavailable', 503));

    expect(fn () => (new RevokeMollieMandateJob('cst_x', 'mdt_x'))->handle())
        ->toThrow(ApiException::class);
});

it('still retries a transport-level failure', function (): void {
    Mollie::shouldReceive('send')
        ->once()
        ->andThrow(new RuntimeException('connection reset'));

    expect(fn () => (new RevokeMollieMandateJob('cst_x', 'mdt_x'))->handle())
        ->toThrow(RuntimeException::class);
});
