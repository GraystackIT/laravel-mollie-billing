<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Support;

use Mockery as M;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Http\Response as MollieResponse;
use Psr\Http\Message\RequestInterface as PsrRequest;
use Psr\Http\Message\StreamInterface as PsrStream;

/**
 * Builds a Mollie `ApiException` with a given HTTP status.
 *
 * `ApiException` insists on a real `Response`, which every test that wants to simulate
 * "Mollie says 404" has to fabricate. Shared here rather than copied, because the
 * status code is load-bearing: several call sites treat 404/410 as "already gone, we
 * are done" and everything else as "retry", so a test that cannot produce a specific
 * code cannot tell those branches apart.
 */
final class MollieApiExceptionFactory
{
    public static function withStatus(int $status, string $message = 'Mollie error'): ApiException
    {
        $stream = M::mock(PsrStream::class);
        $stream->shouldReceive('__toString')->andReturn('');

        $psrRequest = M::mock(PsrRequest::class);
        $psrRequest->shouldReceive('getBody')->andReturn($stream);

        $response = M::mock(MollieResponse::class);
        $response->shouldReceive('json')->andReturn((object) []);
        $response->shouldReceive('getPsrRequest')->andReturn($psrRequest);

        return new ApiException($response, $message, $status);
    }
}
