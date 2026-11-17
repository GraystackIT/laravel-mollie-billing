<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

use Mollie\Api\Http\Requests\GetPaginatedPaymentChargebacksRequest;
use Mollie\Api\Http\Requests\GetPaginatedPaymentRefundsRequest;

/**
 * Reads a payment's refunds and chargebacks from Mollie — every page, through
 * {@see MollieCall::send()}.
 *
 * The webhook handlers used to ask the SDK resource (`$payment->refunds()`,
 * `$payment->chargebacks()`). Those methods send their request through the client they
 * were hydrated with, past `MollieCall`, so neither the transaction guard nor anything
 * else the one door enforces applied to them. They also returned the first page only:
 * a payment with more refunds than one page holds (Mollie's default is 50) had the rest
 * never booked. Both are typed list requests here, and the `next` link is followed until
 * there is none.
 *
 * Resolved from the container, so an app (or a test double) can replace it.
 */
class MolliePaymentCredits
{
    /** Mollie's largest page. */
    public const PAGE_SIZE = 250;

    /** A bound, not an expectation: 250 × 100 entries on one payment is not a payment. */
    private const MAX_PAGES = 100;

    /**
     * @return list<object> every refund of the payment, as the SDK hydrates it
     */
    public function refunds(object $payment): array
    {
        return $this->allPages(
            fn (?string $from): GetPaginatedPaymentRefundsRequest => new GetPaginatedPaymentRefundsRequest(self::paymentId($payment), $from, self::PAGE_SIZE),
        );
    }

    /**
     * @return list<object> every chargeback of the payment, as the SDK hydrates it
     */
    public function chargebacks(object $payment): array
    {
        return $this->allPages(
            fn (?string $from): GetPaginatedPaymentChargebacksRequest => new GetPaginatedPaymentChargebacksRequest(self::paymentId($payment), $from, self::PAGE_SIZE),
        );
    }

    /**
     * @param  callable(?string): object  $requestFrom  the typed request for the page starting at `from`
     * @return list<object>
     */
    protected function allPages(callable $requestFrom): array
    {
        $items = [];
        $from = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $collection = MollieCall::send($requestFrom($from));

            foreach ($collection as $item) {
                $items[] = $item;
            }

            $next = self::cursorOf($collection->_links->next->href ?? null);

            // No next page — or one that would start where this one did, which would loop.
            if ($next === null || $next === $from) {
                return $items;
            }

            $from = $next;
        }

        throw new \RuntimeException(sprintf('Mollie kept reporting a next page after %d pages.', self::MAX_PAGES));
    }

    /** The `from` cursor of a `next` link, e.g. `…/refunds?from=re_4qqhO89gsT&limit=250`. */
    private static function cursorOf(mixed $href): ?string
    {
        if (! is_string($href) || $href === '') {
            return null;
        }

        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);

        $from = $query['from'] ?? null;

        return is_string($from) && $from !== '' ? $from : null;
    }

    private static function paymentId(object $payment): string
    {
        $id = (string) ($payment->id ?? '');

        if ($id === '') {
            throw new \InvalidArgumentException('A payment without an id has no refunds or chargebacks to read.');
        }

        return $id;
    }
}
