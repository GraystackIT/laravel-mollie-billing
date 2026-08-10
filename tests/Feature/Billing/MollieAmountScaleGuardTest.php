<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\MollieCall;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Laravel\Facades\Mollie;

// The second invariant MollieCall enforces. Same reasoning as the transaction guard: the
// rule is easy to state, impossible to keep by review, and the failure is a wrong amount
// the API accepts rather than an error anybody sees.

it('refuses an amount written with the wrong number of decimals', function (): void {
    Mollie::shouldReceive('send')->never();

    $request = new CreatePaymentRequest(
        description: 'Wrong scale',
        amount: new Money('EUR', '10.5'),
        redirectUrl: 'https://example.test/return',
    );

    expect(fn () => MollieCall::send($request))
        ->toThrow(LogicException::class, 'decimal place');
});

it('refuses two decimals on a currency that has none', function (): void {
    Mollie::shouldReceive('send')->never();

    $request = new CreatePaymentRequest(
        description: 'Yen',
        amount: new Money('JPY', '500.00'),
        redirectUrl: 'https://example.test/return',
    );

    expect(fn () => MollieCall::send($request))->toThrow(LogicException::class);
});

it('lets a correctly scaled amount through, for both scales', function (): void {
    Mollie::shouldReceive('send')->twice()->andReturn((object) ['id' => 'tr_ok']);

    MollieCall::send(new CreatePaymentRequest(
        description: 'Euros',
        amount: new Money('EUR', BillingMoney::mollieAmount(1050, 'EUR')),
        redirectUrl: 'https://example.test/return',
    ));

    MollieCall::send(new CreatePaymentRequest(
        description: 'Yen',
        amount: new Money('JPY', BillingMoney::mollieAmount(500, 'JPY')),
        redirectUrl: 'https://example.test/return',
    ));

    expect(true)->toBeTrue();
});
