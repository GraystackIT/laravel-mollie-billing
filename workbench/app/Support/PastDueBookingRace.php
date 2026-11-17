<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Exceptions\PastDueExpansionNotAllowedException;
use GraystackIT\MollieBilling\Services\Billing\StartGrantExtrasPurchase;
use GraystackIT\MollieBilling\Services\Billing\StartOneTimeOrderCheckout;
use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use Illuminate\Contracts\Foundation\Application;

/**
 * Switch `past_due_booking_race` = 1: the three booking services refuse with
 * `PastDueExpansionNotAllowedException::booking()` — the answer they give when the
 * subscription turned past due between the screen's own check and the booking (a renewal
 * that failed while the customer had the page open).
 *
 * The refusal itself is the services' business and tested there. What this makes reachable
 * is the screen's `catch` for it: in a real past-due state the pages redirect or do not
 * offer the action, so a click could never get there.
 */
final class PastDueBookingRace
{
    public const SETTING = 'past_due_booking_race';

    public static function bindIfSwitchedOn(Application $app): void
    {
        try {
            if (WorkbenchSetting::get(self::SETTING) !== '1') {
                return;
            }
        } catch (\Throwable) {
            return; // no settings table yet (migrate:fresh is running)
        }

        $app->bind(StartGrantExtrasPurchase::class, fn (Application $app) => new class($app->make(\GraystackIT\MollieBilling\Services\Billing\UpgradeLocalToMollie::class), $app->make(SubscriptionCatalogInterface::class)) extends StartGrantExtrasPurchase
        {
            public function handle(Billable $billable, array $addonCodes, int $seats): ?string
            {
                throw PastDueExpansionNotAllowedException::booking($billable);
            }
        });

        $app->bind(StartOneTimeOrderCheckout::class, fn (Application $app) => new class(
            $app->make(SubscriptionCatalogInterface::class),
            $app->make(\GraystackIT\MollieBilling\Services\Vat\VatCalculationService::class),
            $app->make(\GraystackIT\MollieBilling\Services\Billing\CouponService::class),
            $app->make(\GraystackIT\MollieBilling\Services\Billing\InvoiceService::class),
            $app->make(\GraystackIT\MollieBilling\Services\Wallet\WalletUsageService::class),
        ) extends StartOneTimeOrderCheckout
        {
            public function handle(Billable $billable, array $request): array
            {
                throw PastDueExpansionNotAllowedException::booking($billable);
            }
        });

        $app->bind(StartSubscriptionCheckout::class, fn (Application $app) => new class(
            $app->make(SubscriptionCatalogInterface::class),
            $app->make(\GraystackIT\MollieBilling\Support\MollieCustomerResolver::class),
            $app->make(\GraystackIT\MollieBilling\Services\Billing\StartMandateCheckout::class),
            $app->make(\GraystackIT\MollieBilling\Services\Billing\ActivateLocalSubscription::class),
            $app->make(\GraystackIT\MollieBilling\Services\Billing\CouponService::class),
            $app->make(\GraystackIT\MollieBilling\Services\Vat\VatCalculationService::class),
        ) extends StartSubscriptionCheckout
        {
            public function handle(Billable $billable, array $request): array
            {
                throw PastDueExpansionNotAllowedException::booking($billable);
            }
        });
    }
}
