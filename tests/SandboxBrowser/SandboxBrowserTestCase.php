<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\SandboxBrowser;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Sandbox\SandboxTestCase;
use Illuminate\Support\Facades\Storage;
use Mollie\Api\Http\Requests\GetMandateRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Api\Http\Requests\GetSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;
use Laravel\Dusk\Browser;
use Laravel\Dusk\Chrome\ChromeProcess;
use Symfony\Component\Process\Process;

/**
 * Base for the tests that pay at the REAL Mollie test system, in a real browser.
 *
 * `tests/Sandbox` stops where a human would have to act: a hosted checkout has to be
 * completed by somebody, so no payment there ever settles and everything downstream of
 * "money arrived" — refunds above all — stayed an assumption. This suite is that somebody.
 * It starts a checkout through the package's own services, opens Mollie's hosted page in
 * the same Chrome the browser suite uses, picks the outcome on Mollie's test-mode screen,
 * and then calls the package's webhook route itself with the payment id (Mollie cannot
 * reach a Testbench app — see SandboxTestCase).
 *
 * Unlike `tests/Browser` there is no second process and no workbench: nothing of the
 * package's own UI is on screen here, only Mollie's. The app under test lives in this
 * process, on the in-memory database, with the real Mollie client.
 *
 * Needs MOLLIE_KEY=test_…, a network and Chrome. `composer test:sandbox:browser`.
 */
abstract class SandboxBrowserTestCase extends SandboxTestCase
{
    private const DRIVER_PORT = 9517;

    private static ?Process $chromeDriver = null;

    private ?Browser $browser = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::sandboxKey() === null) {
            return;
        }

        Storage::fake('local');
        config()->set('mollie-billing.invoices.disk', 'local');

        config()->set('mollie-billing-plans.plans.pro', [
            'name' => 'Pro',
            'tier' => 2,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => ['sso'],
            'intervals' => [
                'monthly' => ['base_price_net' => 1000, 'seat_price_net' => 500, 'included_usages' => ['Tokens' => 100]],
            ],
        ]);
        config()->set('mollie-billing-plans.plans.trialplan', [
            'name' => 'Trial Plan',
            'tier' => 1,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'trial_days' => 14, 'included_usages' => ['Tokens' => 100]],
            ],
        ]);
        config()->set('mollie-billing-plans.usage_types', ['Tokens' => ['rollover' => false]]);
        config()->set('mollie-billing-plans.addons.sso', [
            'name' => 'SSO',
            'feature_keys' => [],
            'intervals' => ['monthly' => ['price_net' => 500]],
        ]);
    }

    protected function newCustomer(): TestBillable
    {
        /** @var TestBillable $billable */
        $billable = TestBillable::create([
            'name' => 'Sandbox Browser Co',
            'email' => 'sandbox-browser-'.bin2hex(random_bytes(3)).'@example.test',
        ]);

        $billable->forceFill([
            'subscription_status' => SubscriptionStatus::New,
            'billing_country' => 'AT',
            'billing_street' => 'Teststrasse 1',
            'billing_postal_code' => '1010',
            'billing_city' => 'Wien',
        ])->save();

        return $billable->refresh();
    }

    protected function track(TestBillable $billable): void
    {
        $customerId = $billable->refresh()->getMollieCustomerId();

        if (is_string($customerId) && $customerId !== '') {
            $this->trackMollieResource('customer', $customerId);
        }
    }

    /**
     * Start → pay → webhook. Returns the settled Mollie payment.
     *
     * @param  array<string, mixed>  $request
     */
    protected function checkout(TestBillable $billable, array $request, string $outcome, string $label): object
    {
        $result = app(StartSubscriptionCheckout::class)->handle($billable, $request + [
            'interval' => 'monthly',
            'addon_codes' => [],
            'extra_seats' => 0,
        ]);
        $this->track($billable);

        $this->assertNotEmpty($result['checkout_url'] ?? null, 'No hosted checkout URL.');

        $this->completeMollieCheckout((string) $result['checkout_url'], $outcome, $label);
        $this->deliverMollieWebhook((string) $result['payment_id'])->assertStatus(200);

        return Mollie::send(new GetPaymentRequest((string) $result['payment_id']));
    }

    protected function mollieSubscriptionOf(TestBillable $billable): object
    {
        $billable->refresh();
        $subscriptionId = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');

        $this->assertNotSame('', $subscriptionId, 'No Mollie subscription was recorded.');
        $this->trackMollieResource('subscription', $subscriptionId, (string) $billable->getMollieCustomerId());

        return Mollie::send(new GetSubscriptionRequest((string) $billable->getMollieCustomerId(), $subscriptionId));
    }

    protected function assertMandateIsValid(TestBillable $billable): void
    {
        $billable->refresh();
        $mandate = Mollie::send(new GetMandateRequest((string) $billable->getMollieCustomerId(), (string) $billable->getMollieMandateId()));

        $this->assertSame('valid', (string) $mandate->status, 'The first payment must leave a valid mandate.');
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$chromeDriver === null) {
            self::$chromeDriver = (new ChromeProcess)->toProcess(['--port='.self::DRIVER_PORT]);
            self::$chromeDriver->start();

            // The driver needs a moment before it accepts sessions.
            usleep(1_500_000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$chromeDriver?->stop();
        self::$chromeDriver = null;

        parent::tearDownAfterClass();
    }

    protected function tearDown(): void
    {
        try {
            $this->browser?->quit();
        } catch (\Throwable) {
            // a browser that is already gone must not mask the test's result
        }

        $this->browser = null;

        parent::tearDown();
    }

    protected function browser(): Browser
    {
        if ($this->browser !== null) {
            return $this->browser;
        }

        $directory = self::artifactDirectory();

        Browser::$storeScreenshotsAt = $directory;
        Browser::$storeConsoleLogAt = $directory;
        Browser::$storeSourceAt = $directory;

        $options = (new ChromeOptions)->addArguments(array_filter([
            '--window-size=1280,1400',
            '--disable-gpu',
            '--disable-search-engine-choice-screen',
            '--lang=en-GB',
            env('DUSK_HEADED') ? null : '--headless=new',
        ]));

        $driver = RemoteWebDriver::create(
            'http://localhost:'.self::DRIVER_PORT,
            DesiredCapabilities::chrome()->setCapability(ChromeOptions::CAPABILITY, $options),
            30_000,
            60_000,
        );

        return $this->browser = new Browser($driver);
    }

    /**
     * Complete Mollie's hosted checkout with the outcome a test wants.
     *
     * What the test system looks like — measured, November 2026, profile with card and eps
     * enabled, customer in Austria:
     *
     *  1. `…/checkout/select-method/{token}`: one `<button name="method" value="…">` per
     *     payment method inside `form#body`. A payment with an amount offers `creditcard`
     *     and `eps`; a **€0.00 first payment offers `creditcard` only**. The page also
     *     carries `form#cancel-button` ("‹ previous page").
     *  2. eps opens `…/checkout/test-mode?method=eps&token=…`: a plain form with
     *     `<input type="radio" name="final_state" value="open|paid|failed|canceled|expired">`
     *     and `<button name="submit">`. That page IS the bank in test mode.
     *  3. The card method opens `…/checkout/credit-card/session/{token}` with the card
     *     fields in one iframe (`iframe[src*="components/card"]`: `#cardNumber`,
     *     `#cardExpiryDate`, `#cardCvv`, `#cardHolder`, a submit button). With the test
     *     card it continues to the same test-mode page (`method=creditcard`; there the
     *     radios are `open|paid|failed|expired`).
     *  4. `paid` redirects to the payment's `redirectUrl`; the status is final at that
     *     moment and a `paid` first payment has created a mandate.
     *  5. `failed` and `expired` do NOT end the payment: Mollie returns to the method
     *     list and the payment stays `open` — the customer may try again until the
     *     payment expires (15 minutes after creation for a first payment).
     *  6. The cancel button of the method list redirects to the `redirectUrl` at once;
     *     the payment is `canceled` within about five seconds. The API refuses to cancel
     *     an open first payment (`isCancelable` false, 422).
     *
     * @param  'paid'|'failed'|'canceled'|'expired'  $outcome
     */
    protected function completeMollieCheckout(string $checkoutUrl, string $outcome, string $label): void
    {
        $browser = $this->browser();
        $browser->visit($checkoutUrl);

        try {
            $browser->waitUsing(20, 250, function (): bool {
                return $this->domCountOnMollie('button[name="method"]') > 0
                    || $this->domCountOnMollie('input[name="final_state"]') > 0;
            }, 'Mollie showed neither a method list nor the test-mode page.');

            $browser->screenshot($label.'-1-methods');

            if ($outcome === 'canceled') {
                $this->cancelOnMollieMethodList();

                return;
            }

            if ($this->domCountOnMollie('input[name="final_state"]') === 0) {
                if ($this->domCountOnMollie('button[name="method"][value="eps"]') > 0) {
                    $browser->script('document.querySelector(\'button[name="method"][value="eps"]\').click()');
                } else {
                    $this->assertGreaterThan(
                        0,
                        $this->domCountOnMollie('button[name="method"][value="creditcard"]'),
                        'Mollie offers neither eps nor card for this payment.',
                    );
                    $this->payWithMollieTestCard();
                }

                $browser->waitUsing(30, 250, fn (): bool => $this->domCountOnMollie('input[name="final_state"]') > 0,
                    'Mollie did not open its test-mode page.');
            }

            $browser->screenshot($label.'-2-test-mode');
            $browser->script(sprintf('document.querySelector(\'input[name="final_state"][value="%s"]\').click()', $outcome));
            $browser->script('document.querySelector(\'form#body button[name="submit"]\').click()');

            if ($outcome === 'paid') {
                // Away from Mollie means the status is final. The redirect target is not
                // ours to load (example.com) — only that the browser left.
                $browser->waitUsing(30, 250, fn (): bool => ! str_contains($browser->driver->getCurrentURL(), 'mollie.com/checkout'),
                    'Mollie did not redirect after the test-mode page was submitted.');

                return;
            }

            // Not paid: Mollie offers the methods again instead of ending the payment.
            $browser->waitUsing(30, 250, fn (): bool => str_contains($browser->driver->getCurrentURL(), '/checkout/select-method/'),
                'Mollie did not return to the method list after an unpaid attempt.');
            $browser->screenshot($label.'-3-back-on-methods');
        } catch (\Throwable $e) {
            // What Mollie showed instead is the finding; keep it.
            $browser->screenshot($label.'-FAILED');
            file_put_contents(self::artifactDirectory().'/'.$label.'-FAILED.html', $browser->driver->getPageSource());
            fwrite(STDERR, "\nMollie page at failure: ".$browser->driver->getCurrentURL()."\n");

            throw $e;
        }
    }

    /** The customer walks away: the only way to a terminal `canceled` from the hosted page. */
    protected function cancelOnMollieMethodList(): void
    {
        $browser = $this->browser();

        $browser->script('document.querySelector(\'form#cancel-button button\').click()');
        $browser->waitUsing(30, 250, fn (): bool => ! str_contains($browser->driver->getCurrentURL(), 'mollie.com/checkout'),
            'Mollie did not leave its checkout after the cancel button.');
    }

    /** Mollie's documented test card, typed into the Components iframe. */
    protected function payWithMollieTestCard(): void
    {
        $browser = $this->browser();
        $driver = $browser->driver;

        $browser->script('document.querySelector(\'button[name="method"][value="creditcard"]\').click()');
        $browser->waitUsing(30, 250, fn (): bool => $this->domCountOnMollie('iframe[src*="components/card"]') > 0,
            'Mollie did not open its card form.');

        $driver->switchTo()->frame($driver->findElement(WebDriverBy::cssSelector('iframe[src*="components/card"]')));

        try {
            $browser->waitUsing(20, 250, fn (): bool => $this->domCountOnMollie('#cardNumber') > 0, 'The card fields did not render.');

            foreach ([
                'cardNumber' => '4543474002249996',
                'cardExpiryDate' => '1230',
                'cardCvv' => '123',
                'cardHolder' => 'Sandbox Tester',
            ] as $id => $value) {
                $driver->findElement(WebDriverBy::id($id))->sendKeys($value);
            }

            $browser->pause(400);
            $driver->findElement(WebDriverBy::cssSelector('button[type="submit"]'))->click();
        } finally {
            $driver->switchTo()->defaultContent();
        }
    }

    /** Poll the real API until the payment has the status, or give up. */
    protected function waitForMollieStatus(string $paymentId, string $status, int $seconds = 40): object
    {
        $deadline = microtime(true) + $seconds;

        do {
            $payment = Mollie::send(new GetPaymentRequest($paymentId));

            if ((string) $payment->status === $status) {
                return $payment;
            }

            usleep(1_500_000);
        } while (microtime(true) < $deadline);

        $this->fail("Payment {$paymentId} is [{$payment->status}], not [{$status}], after {$seconds}s.");
    }

    protected function domCountOnMollie(string $css): int
    {
        return (int) $this->browser()->script(sprintf('return document.querySelectorAll(%s).length', json_encode($css)))[0];
    }

    /**
     * Deliver the payment's webhook to the package ourselves — Mollie cannot reach a
     * Testbench app. The controller fetches the payment from the real API.
     */
    protected function deliverMollieWebhook(string $paymentId): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route(\GraystackIT\MollieBilling\Support\BillingRoute::webhook()), ['id' => $paymentId]);
    }

    /** Screenshots and page sources of Mollie's pages — what was measured, kept. */
    protected static function artifactDirectory(): string
    {
        $directory = __DIR__.'/screenshots';

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        return $directory;
    }
}
