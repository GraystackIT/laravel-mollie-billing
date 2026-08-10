<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Sandbox;

use GraystackIT\MollieBilling\Tests\TestCase;
use Mollie\Api\Http\Requests\CreateCustomerRequest;
use Mollie\Api\Http\Requests\CreateMandateRequest;
use Mollie\Api\Http\Requests\RevokeMandateRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * Base for the tests that talk to Mollie for real.
 *
 * Everything else in the suite fakes Mollie — the feature tests at the facade, the browser
 * tests at the SDK's own HTTP adapter. Both are worth having and neither can answer the one
 * question that matters here: does Mollie actually accept the requests this package builds,
 * and do its responses carry the fields this package reads? A typed request object the SDK
 * is happy to construct is not a request the API is happy to receive.
 *
 * Opt-in, and excluded from `phpunit.xml.dist`: it needs a key and a network. Run it with
 * `composer test:sandbox`.
 *
 * **Test keys only.** Every case here creates real records in the Mollie dashboard —
 * customers, mandates, subscriptions, payments. A test key moves no money; a live key
 * would, so {@see sandboxKey()} refuses anything that is not `test_`.
 *
 * What this suite deliberately does not cover, because the sandbox cannot:
 *
 * - **Webhook delivery.** Mollie only calls a publicly reachable URL, and Testbench serves
 *   none. The handlers are driven with real fetched payment objects instead; the delivery
 *   leg itself is untested here.
 * - **A settled payment.** A direct-debit recurring charge stays `pending` in test mode —
 *   measured at over an hour — so anything downstream of "money arrived", refunds above
 *   all, cannot be reached without a browser completing a hosted payment.
 * - **A real renewal.** Mollie's scheduler runs daily and the earliest `startDate` is
 *   tomorrow, so no run can observe a period actually rolling over.
 *
 * Everything created is torn down: subscriptions cancelled, mandates revoked, customers
 * deleted. A subscription left behind in test mode keeps trying to charge, daily, forever.
 */
abstract class SandboxTestCase extends TestCase
{
    /** Mollie's documented test IBAN — a direct-debit mandate created with it is instantly valid. */
    protected const TEST_IBAN = 'NL55INGB0000000000';

    /** @var list<array{type: string, id: string, customer?: string}> */
    private array $created = [];

    /**
     * The real Mollie provider, which the rest of the suite does not need.
     *
     * Every other test mocks the `Mollie` facade outright, so the actual client is never
     * resolved and the provider's absence is invisible. Here it is the whole point: without
     * it nothing binds `MollieApiClient` and no request carries an authenticator.
     */
    protected function getPackageProviders($app): array
    {
        return array_merge(
            [\Mollie\Laravel\MollieServiceProvider::class],
            parent::getPackageProviders($app),
        );
    }

    /**
     * The key has to be in place before the container boots.
     *
     * `mollie/laravel-mollie` resolves its client once and reads `mollie.key` while doing
     * so, so setting it in `setUp()` is too late — the client is already built without an
     * authenticator and every call dies with MissingAuthenticationException.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $key = self::sandboxKey();

        if ($key !== null) {
            $app['config']->set('mollie.key', $key);
            $app['config']->set('mollie-billing.mollie_api_key', $key);
        }

        // Mollie validates that `webhookUrl` is reachable from the outside and rejects the
        // request with a 422 otherwise — so `http://localhost/...`, which is what `route()`
        // produces under Testbench, fails before any of our own logic is exercised. The
        // host is swapped for a public one; the path, the parameters and the route name
        // still come from the package's own URL generation, which is the part under test.
        // Delivery itself is not tested here — see the class docblock.
        $app['config']->set('app.url', 'https://example.com');
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (self::sandboxKey() === null) {
            $this->markTestSkipped('No Mollie test key. Set MOLLIE_KEY=test_… in .env to run the sandbox suite.');
        }

        // `app.url` alone is not enough: the URL generator is already resolved by the time
        // a test runs, so `route()` keeps producing `http://localhost/…` and Mollie rejects
        // the payment with "webhook URL unreachable" before any of our logic is exercised.
        \Illuminate\Support\Facades\URL::forceRootUrl('https://example.com');
    }

    protected function tearDown(): void
    {
        $this->cleanUpMollieResources();

        parent::tearDown();
    }

    /**
     * The key, only if it is a test key.
     *
     * Read from the process environment rather than through `env()`, because Testbench
     * does not load the package's own `.env` — and refused outright unless it says
     * `test_`, which is the one check that must not be skippable.
     */
    protected static function sandboxKey(): ?string
    {
        $key = $_SERVER['MOLLIE_KEY'] ?? $_ENV['MOLLIE_KEY'] ?? (getenv('MOLLIE_KEY') ?: null);

        if (! is_string($key)) {
            $path = dirname(__DIR__, 2).'/.env';

            if (is_readable($path)) {
                foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                    if (preg_match('/^\s*MOLLIE_KEY\s*=\s*(.+)$/', $line, $m) === 1) {
                        $key = trim($m[1], " \t\"'");
                        break;
                    }
                }
            }
        }

        if (! is_string($key) || $key === '') {
            return null;
        }

        if (! str_starts_with($key, 'test_')) {
            throw new \RuntimeException(
                'The sandbox suite refuses a key that is not a Mollie test key. It creates '
                .'customers, mandates and subscriptions, and a live key would create them for real.'
            );
        }

        return $key;
    }

    /**
     * A customer with a valid direct-debit mandate, created entirely through the API.
     *
     * This is what makes the recurring half of the package testable without a human: a
     * card mandate needs someone to complete a first payment on the hosted page, while a
     * direct-debit mandate can be created outright and comes back `valid` in test mode.
     *
     * @return array{customerId: string, mandateId: string}
     */
    protected function sandboxCustomerWithMandate(string $name = 'Sandbox Customer'): array
    {
        $customer = Mollie::send(new CreateCustomerRequest(
            name: $name,
            email: 'sandbox-'.bin2hex(random_bytes(4)).'@example.test',
        ));

        $customerId = (string) $customer->id;
        $this->created[] = ['type' => 'customer', 'id' => $customerId];

        $mandate = Mollie::send(new CreateMandateRequest(
            customerId: $customerId,
            method: 'directdebit',
            consumerName: $name,
            consumerAccount: self::TEST_IBAN,
        ));

        $mandateId = (string) $mandate->id;
        $this->created[] = ['type' => 'mandate', 'id' => $mandateId, 'customer' => $customerId];

        return ['customerId' => $customerId, 'mandateId' => $mandateId];
    }

    /** Register a resource for teardown — for anything a test creates itself. */
    protected function trackMollieResource(string $type, string $id, ?string $customerId = null): void
    {
        $this->created[] = array_filter([
            'type' => $type,
            'id' => $id,
            'customer' => $customerId,
        ], static fn ($v): bool => $v !== null);
    }

    /**
     * Undo everything, in the order Mollie allows it, and never fail the test over it.
     *
     * A subscription that survives keeps charging daily in test mode, so this runs even
     * when the case that created it blew up.
     */
    private function cleanUpMollieResources(): void
    {
        // Sweep by customer first, and only then work the explicit list.
        //
        // Registering a subscription id requires the test to get far enough to read it, and
        // a test that fails before that leaves a live one behind — which in test mode keeps
        // trying to charge, daily, forever. An early run of this suite left 27 of them.
        // Asking Mollie what belongs to the customers we created does not depend on how far
        // the test got.
        foreach ($this->created as $resource) {
            if ($resource['type'] !== 'customer') {
                continue;
            }

            try {
                $subscriptions = Mollie::send(
                    new \Mollie\Api\Http\Requests\GetPaginatedSubscriptionsRequest($resource['id'])
                );

                foreach ($subscriptions as $subscription) {
                    if (in_array((string) ($subscription->status ?? ''), ['active', 'pending', 'suspended'], true)) {
                        Mollie::send(new \Mollie\Api\Http\Requests\CancelSubscriptionRequest(
                            $resource['id'],
                            (string) $subscription->id,
                        ));
                    }
                }
            } catch (\Throwable) {
                // The customer may already be gone, or have none.
            }
        }

        foreach (array_reverse($this->created) as $resource) {
            try {
                match ($resource['type']) {
                    'subscription' => Mollie::send(new \Mollie\Api\Http\Requests\CancelSubscriptionRequest(
                        $resource['customer'] ?? '',
                        $resource['id'],
                    )),
                    'mandate' => Mollie::send(new RevokeMandateRequest(
                        $resource['customer'] ?? '',
                        $resource['id'],
                    )),
                    'customer' => Mollie::send(new \Mollie\Api\Http\Requests\DeleteCustomerRequest($resource['id'])),
                    default => null,
                };
            } catch (\Throwable) {
                // Already gone, or Mollie refused — either way this must not mask the
                // result of the test that ran.
            }
        }

        $this->created = [];
    }
}
