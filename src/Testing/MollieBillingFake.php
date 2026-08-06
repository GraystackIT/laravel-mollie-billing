<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Testing;

use GraystackIT\MollieBilling\IpGeolocation\Contracts\IpGeolocationDriver;
use GraystackIT\MollieBilling\IpGeolocation\IpGeolocationManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Mollie\Api\Fake\MockMollieClient;
use Mollie\Laravel\Facades\Mollie;
use Mpociot\VatCalculator\VatCalculator;

/**
 * Central entry-point for test fakes. `MollieBilling::fake()` fakes events and
 * notifications, swaps Mollie's API client for the SDK's MockMollieClient (so no
 * checkout can reach the network), routes IP geolocation through an in-memory
 * override, and answers VIES lookups from an allow-list.
 *
 * Every setter takes effect for lookups made after it — the bound driver and VAT
 * calculator read the fake's state live rather than copying it at bind time.
 */
class MollieBillingFake
{
    private ?string $ipOverride = null;

    /** @var array<int, string> */
    private array $validVatNumbers = [];

    public function __construct()
    {
        Event::fake();
        Notification::fake();

        // Swap the Mollie client first: a test that starts a checkout without
        // registering responses must fail on a missing mock response, never by
        // issuing a real HTTP request against Mollie.
        Mollie::fake();

        $this->bindIpGeolocation();
        $this->bindVatCalculator();
    }

    /**
     * Register canned Mollie responses (see the SDK's MockMollieClient) and get the
     * mock client back for `assertSent()` / `assertSentCount()`.
     *
     * @param  array<string, mixed>  $expectedResponses
     */
    public function mollie(array $expectedResponses = []): MockMollieClient
    {
        return Mollie::fake($expectedResponses);
    }

    /**
     * Pin the country every IP lookup resolves to. Also clears the geolocation
     * cache, which is keyed per IP and would otherwise pin the first answer.
     */
    public function ipReturns(string $country): self
    {
        $this->ipOverride = strtoupper($country);

        Cache::flush();

        return $this;
    }

    /** Read by the bound geolocation driver — must stay public. */
    public function ipOverride(): ?string
    {
        return $this->ipOverride;
    }

    /**
     * Treat the given VAT numbers as VIES-valid; every other number comes back
     * invalid. Formatting (spaces, dots, dashes) is normalised away.
     */
    public function vatNumberValidates(string ...$vatNumbers): self
    {
        foreach ($vatNumbers as $vatNumber) {
            $normalized = strtoupper(preg_replace('/[\s\-.,]/', '', $vatNumber) ?? '');

            if ($normalized !== '' && ! in_array($normalized, $this->validVatNumbers, true)) {
                $this->validVatNumbers[] = $normalized;
            }
        }

        return $this;
    }

    /**
     * Fluent alternative to vatNumberValidates(): `$fake->vies()->validates('ATU…')`.
     * Writes into the same allow-list the bound calculator reads.
     */
    public function vies(): FakeVatValidator
    {
        return new FakeVatValidator($this->validVatNumbers);
    }

    /** @return array<int, string> */
    public function validVatNumbers(): array
    {
        return $this->validVatNumbers;
    }

    public function assertSubscriptionCreated(mixed $billable, ?\Closure $callback = null): void
    {
        Event::assertDispatched(\GraystackIT\MollieBilling\Events\SubscriptionCreated::class,
            fn ($e) => $e->billable === $billable && ($callback === null || $callback($e)));
    }

    public function assertEventDispatched(string $event): void
    {
        Event::assertDispatched($event);
    }

    public function assertNotificationSent(string $notification, mixed $notifiable, ?\Closure $callback = null): void
    {
        Notification::assertSentTo($notifiable, $notification, $callback);
    }

    private function bindIpGeolocation(): void
    {
        $fake = $this;

        app()->singleton(IpGeolocationManager::class, function ($app) use ($fake): IpGeolocationManager {
            $manager = new IpGeolocationManager($app);

            $manager->extend('billing-fake', fn (): IpGeolocationDriver => new class($fake) implements IpGeolocationDriver
            {
                public function __construct(private readonly MollieBillingFake $fake) {}

                public function getCountry(string $ip): ?string
                {
                    return $this->fake->ipOverride();
                }
            });

            return $manager;
        });

        config(['mollie-billing.ip_geolocation.driver' => 'billing-fake']);
    }

    private function bindVatCalculator(): void
    {
        // Bound by reference so vatNumberValidates() / vies()->validates() calls
        // made after this point are still honoured by an already-resolved service.
        app()->singleton(
            VatCalculator::class,
            fn ($app) => new FakeVatCalculator($this->validVatNumbers, $app['config']),
        );
    }
}
