<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Testing;

use GraystackIT\MollieBilling\IpGeolocation\Drivers\NullDriver;
use GraystackIT\MollieBilling\IpGeolocation\IpGeolocationManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/**
 * Central entry-point for test fakes. `MollieBilling::fake()` returns a new instance,
 * wires up the fake Mollie client, VAT validator and IP driver, and exposes assertions.
 *
 * NOTE: this is a scaffold — expand assertions in Phase 18.
 */
class MollieBillingFake
{
    private ?string $ipOverride = null;
    private array $validVatNumbers = [];

    public function __construct()
    {
        Event::fake();
        Notification::fake();

        app()->singleton(IpGeolocationManager::class, function ($app): IpGeolocationManager {
            $manager = new IpGeolocationManager($app);
            $manager->extend('null', fn () => new NullDriver());

            return $manager;
        });
    }

    public function ipReturns(string $country): self
    {
        $this->ipOverride = $country;
        return $this;
    }

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
}
