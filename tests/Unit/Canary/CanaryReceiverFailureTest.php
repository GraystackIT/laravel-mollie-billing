<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Tests\SandboxBrowser\Support\CanaryReceiver;

/**
 * What a failed read of the receiver means (tests/SandboxBrowser/Support/CanaryReceiver::failure):
 * a rejected token is a misconfiguration and red, anything else the receiver's weather and a warning.
 */
it('takes 401 and 403 as a rejected read token', function (int $status): void {
    $failure = CanaryReceiver::failure($status, 'HTTP request returned status code '.$status);

    expect($failure['kind'])->toBe('token_rejected')
        ->and($failure['text'])->toContain('CANARY_RECEIVER_READ_TOKEN')->toContain("HTTP {$status}");
})->with([401, 403]);

it('takes a 5xx, a 404 or no answer at all as the receiver being unreachable', function (?int $status, string $message, string $cause): void {
    $failure = CanaryReceiver::failure($status, $message);

    expect($failure['kind'])->toBe('unreachable')
        ->and($failure['text'])->toContain($cause)->toContain('did not check that Mollie delivered');
})->with([
    'bad gateway' => [502, 'HTTP request returned status code 502', 'HTTP 502'],
    'not found' => [404, 'HTTP request returned status code 404', 'HTTP 404'],
    'timeout' => [null, 'cURL error 28: Operation timed out after 30001 milliseconds', 'no answer: cURL error 28'],
]);

it('keeps a long connection message short', function (): void {
    expect(mb_strlen(CanaryReceiver::failure(null, str_repeat('x', 1000))['text']))->toBeLessThan(400);
});
