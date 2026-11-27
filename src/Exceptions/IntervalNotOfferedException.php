<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

use GraystackIT\MollieBilling\Exceptions\Concerns\CarriesTranslation;
use RuntimeException;

/**
 * Thrown when a (plan, interval) or (addon, interval) pair carries no price
 * because that interval simply is not sold.
 *
 * A plan may legitimately offer only one interval — `billing:check-config`
 * accepts `intervals` with just `monthly` or just `yearly`. The danger is that
 * the price getters cannot express "not sold": `basePriceNet()` and
 * `addonPriceNet()` both answer `0` for a missing interval, which every pricing
 * path downstream reads as *free*. A checkout on a monthly-only paid plan with
 * `interval=yearly` therefore priced the order at zero and activated a Local
 * subscription on that paid plan, with all its features, indefinitely, for
 * nothing — no payment, no Mollie call, no tampering required beyond clicking
 * the interval toggle.
 *
 * So every surface that takes an interval from the outside asks
 * {@see \GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface::planOffersInterval()}
 * (or `addonOffersInterval()`) first, and the services throw this rather than
 * trusting a zero.
 */
class IntervalNotOfferedException extends RuntimeException implements TranslatableException
{
    use CarriesTranslation;

    protected function defaultTranslationKey(): ?string
    {
        return 'billing::exceptions.interval_not_offered';
    }

    /** @return array<string, string|int|float> */
    protected function defaultTranslationParameters(): array
    {
        return [
            'code' => $this->targetCode,
            'interval' => \Illuminate\Support\Facades\Lang::has('billing::enums.subscription_interval.'.$this->interval)
                ? (string) __('billing::enums.subscription_interval.'.$this->interval)
                : $this->interval,
        ];
    }

    public function __construct(
        public readonly string $targetCode,
        public readonly string $interval,
        public readonly string $kind = 'plan',
    ) {
        parent::__construct(
            "The {$kind} '{$targetCode}' is not offered on a {$interval} interval."
        );
    }
}
