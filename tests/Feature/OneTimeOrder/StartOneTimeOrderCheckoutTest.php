<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Services\Billing\StartOneTimeOrderCheckout;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Laravel\Facades\Mollie;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.products', [
        'token-pack' => [
            'name' => '500 Token Pack',
            'description' => 'Tokens for your account.',
            'image_url' => null,
            'price_net' => 4900,
            'usage_type' => 'Tokens',
            'quantity' => 500,
        ],
        'consulting' => [
            'name' => 'Consulting',
            'description' => null,
            'image_url' => null,
            'price_net' => 10000,
        ],
    ]);
});

function fakePaymentResponse(string $id, string $checkoutUrl): object
{
    return new class($id, $checkoutUrl) {
        public string $id;

        private string $checkoutUrl;

        public function __construct(string $id, string $checkoutUrl)
        {
            $this->id = $id;
            $this->checkoutUrl = $checkoutUrl;
        }

        public function getCheckoutUrl(): string
        {
            return $this->checkoutUrl;
        }
    };
}

it('creates a one-time payment via Mollie', function (): void {
    $billable = TestBillable::create([
        'name' => 'Order Test',
        'email' => 'order@example.test',
        'billing_country' => 'DE',
    ]);

    // The service only stashes the payment when the request carries a session,
    // which a plain service-level test does not set up on its own.
    request()->setLaravelSession(app('session.store'));

    $capturedRequest = null;

    Mollie::shouldReceive('send')
        ->once()
        ->withArgs(function ($request) use (&$capturedRequest) {
            $capturedRequest = $request;

            return $request instanceof CreatePaymentRequest;
        })
        ->andReturn(fakePaymentResponse('tr_test_123', 'https://checkout.mollie.com/test'));

    $service = app(StartOneTimeOrderCheckout::class);
    $result = $service->handle($billable, ['product_code' => 'token-pack']);

    expect($result['payment_id'])->toBe('tr_test_123');
    expect($result['checkout_url'])->toBe('https://checkout.mollie.com/test');

    // Verify it was a CreatePaymentRequest with correct structure
    expect($capturedRequest)->toBeInstanceOf(CreatePaymentRequest::class);

    // The return page has no other handle on the payment — Mollie's redirect
    // carries no id — so the session stash is what lets it report the real status.
    expect($result['order_token'])->toBeString()->not->toBeEmpty();
    expect(session(StartOneTimeOrderCheckout::PENDING_ORDER_SESSION_KEY))
        ->toBe([$result['order_token'] => 'tr_test_123']);
});

it('keeps parallel checkouts apart with one session entry per token', function (): void {
    $billable = TestBillable::create([
        'name' => 'Test',
        'email' => 'test@example.test',
        'billing_country' => 'DE',
    ]);

    request()->setLaravelSession(app('session.store'));

    Mollie::shouldReceive('send')
        ->twice()
        ->andReturn(
            fakePaymentResponse('tr_first', 'https://checkout.mollie.com/first'),
            fakePaymentResponse('tr_second', 'https://checkout.mollie.com/second'),
        );

    $service = app(StartOneTimeOrderCheckout::class);
    $first = $service->handle($billable, ['product_code' => 'token-pack']);
    $second = $service->handle($billable, ['product_code' => 'token-pack']);

    // A single session-wide id would have let the second checkout overwrite the
    // first, and both return pages would then judge `tr_second`.
    expect($first['order_token'])->not->toBe($second['order_token']);
    expect(session(StartOneTimeOrderCheckout::PENDING_ORDER_SESSION_KEY))->toBe([
        $first['order_token'] => 'tr_first',
        $second['order_token'] => 'tr_second',
    ]);
});

it('throws for unknown product code', function (): void {
    $billable = TestBillable::create([
        'name' => 'Test',
        'email' => 'test@example.test',
        'billing_country' => 'DE',
    ]);

    $service = app(StartOneTimeOrderCheckout::class);
    $service->handle($billable, ['product_code' => 'nonexistent']);
})->throws(\InvalidArgumentException::class, 'Unknown product code');

it('throws for zero-price product', function (): void {
    config()->set('mollie-billing-plans.products.free-thing', [
        'name' => 'Free Thing',
        'price_net' => 0,
    ]);

    $billable = TestBillable::create([
        'name' => 'Test',
        'email' => 'test@example.test',
        'billing_country' => 'DE',
    ]);

    $service = app(StartOneTimeOrderCheckout::class);
    $service->handle($billable, ['product_code' => 'free-thing']);
})->throws(\RuntimeException::class, 'zero or negative price');

it('sends correct amount including VAT for DE', function (): void {
    $billable = TestBillable::create([
        'name' => 'VAT Test',
        'email' => 'vat@example.test',
        'billing_country' => 'DE',
    ]);

    $capturedRequest = null;

    Mollie::shouldReceive('send')
        ->once()
        ->withArgs(function ($request) use (&$capturedRequest) {
            $capturedRequest = $request;

            return true;
        })
        ->andReturn(fakePaymentResponse('tr_vat_123', 'https://checkout.mollie.com/vat'));

    $service = app(StartOneTimeOrderCheckout::class);
    $service->handle($billable, ['product_code' => 'consulting']);

    // consulting is 10000 net, DE VAT is 19% → gross = 11900 → "119.00"
    expect($capturedRequest)->toBeInstanceOf(CreatePaymentRequest::class);
});
