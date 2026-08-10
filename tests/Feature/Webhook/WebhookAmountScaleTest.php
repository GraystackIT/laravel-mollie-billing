<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Services\Webhook\WebhookSupport;

/**
 * Every amount entering the package from a webhook goes through
 * `amountFromMolliePayment()`: the invoice, the mismatch guard, the wallet
 * settlement and the refund reconciliation all read it. A hardcoded ×100 therefore
 * lands the error directly in a booked document, and for a zero-decimal currency it
 * is a factor of one hundred.
 */
function amountOf(string $value, string $currency): int
{
    return (new WebhookSupport)->amountFromMolliePayment(
        (object) ['amount' => (object) ['value' => $value, 'currency' => $currency]],
    );
}

it('reads a Mollie amount at the scale of its own currency', function (): void {
    expect(amountOf('11.90', 'EUR'))->toBe(1190)
        ->and(amountOf('45.90', 'EUR'))->toBe(4590)
        // JPY has no minor unit: ¥500 is 500 minor units, not 50 000.
        ->and(amountOf('500', 'JPY'))->toBe(500)
        // KWD has three: 1.234 KWD is 1234 fils.
        ->and(amountOf('1.234', 'KWD'))->toBe(1234);
});

it('uses the payment currency rather than the configured one', function (): void {
    // A webhook can arrive for a payment booked before a BILLING_CURRENCY change,
    // so the payment's own currency decides the scale.
    config()->set('mollie-billing.currency', 'EUR');

    expect(amountOf('2900', 'JPY'))->toBe(2900);
});

it('falls back to the configured currency when the payment carries none', function (): void {
    config()->set('mollie-billing.currency', 'EUR');

    $amount = (new WebhookSupport)->amountFromMolliePayment(
        (object) ['amount' => (object) ['value' => '11.90']],
    );

    expect($amount)->toBe(1190);
});

it('reads a missing amount as zero rather than throwing', function (): void {
    // The caller is handling money Mollie has already moved; a malformed payload
    // must not abort the whole delivery into a retry loop.
    expect((new WebhookSupport)->amountFromMolliePayment((object) []))->toBe(0)
        ->and(amountOf('', 'EUR'))->toBe(0);
});

it('sums an expected gross per line, not once over the total', function (): void {
    // The invoice header derives its VAT by summing per-line VAT, and the charge
    // sites now collect the same sum. Six 550-cent lines at 19% are 3930 that way
    // and 3927 the other, which is past the reconciliation guard's 1-cent slack.
    $support = new WebhookSupport;
    $lines = array_fill(0, 6, ['total_net' => 550]);

    expect($support->grossForLineItems($lines, 19.0))->toBe(3930)
        ->and((int) round($support->netFromLineItems($lines) * 1.19))->toBe(3927);
});

it('backs the net out of a gross that was already collected', function (): void {
    $support = new WebhookSupport;

    expect($support->netFromGross(1190, 19.0))->toBe(1000)
        ->and($support->netFromGross(1200, 20.0))->toBe(1000)
        // Reverse charge or a country without VAT: net equals gross.
        ->and($support->netFromGross(1000, 0.0))->toBe(1000);
});
