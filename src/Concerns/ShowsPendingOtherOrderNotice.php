<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Concerns;

use GraystackIT\MollieBilling\Exceptions\PendingPaymentForAnotherOrderException;
use GraystackIT\MollieBilling\Support\BillingTime;
use Livewire\Attributes\Locked;

/**
 * The state behind `<x-mollie-billing::pending-other-order>`: a changed order waits for an
 * earlier first payment Mollie would not withdraw (`PendingPaymentForAnotherOrderException`).
 *
 * Every screen that starts a `sequenceType: first` payment can meet it — the checkout, the
 * upgrade from a free plan, the purchase of extras on a gift — and each says the same thing:
 * until when that payment stays payable, and the link that still finishes it. A screen
 * calls {@see showPendingOtherOrder()} in its catch and {@see clearPendingOtherOrder()} when
 * the action is tried again.
 *
 * Locked: the link is rendered as an href, so only the server may put a URL there — and only
 * one it accepts ({@see pendingOtherOrderLink()}).
 */
trait ShowsPendingOtherOrderNotice
{
    /** Whether the notice is shown. */
    #[Locked]
    public bool $pendingOtherOrder = false;

    /** Until when the earlier payment stays payable (ISO-8601, UTC); null when Mollie did not say. */
    #[Locked]
    public ?string $pendingOtherOrderUntil = null;

    /** The timezone the billable reads times in, for {@see $pendingOtherOrderUntil}. */
    #[Locked]
    public ?string $pendingOtherOrderTimezone = null;

    /** The earlier payment's checkout link; null when Mollie no longer offers one. */
    #[Locked]
    public ?string $pendingOtherOrderUrl = null;

    protected function showPendingOtherOrder(PendingPaymentForAnotherOrderException $e): void
    {
        $this->pendingOtherOrder = true;
        $this->pendingOtherOrderUntil = $e->expiresAt?->utc()->toIso8601String();
        $this->pendingOtherOrderTimezone = $e->expiresAt !== null
            ? BillingTime::display($e->expiresAt, $e->billable)?->getTimezone()->getName()
            : null;
        $this->pendingOtherOrderUrl = self::pendingOtherOrderLink($e->checkoutUrl);
    }

    protected function clearPendingOtherOrder(): void
    {
        $this->pendingOtherOrder = false;
        $this->pendingOtherOrderUntil = null;
        $this->pendingOtherOrderTimezone = null;
        $this->pendingOtherOrderUrl = null;
    }

    /**
     * The link as the notice may render it: an https URL, or one on this application's own
     * origin (the browser suite's fake Mollie checkout lives there, on plain http). Anything
     * else — another scheme, a relative path, a foreign plain-http host — is no link at all,
     * and the notice says "wait" instead of "finish it".
     */
    private static function pendingOtherOrderLink(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || empty($parts['host']) || ! isset($parts['scheme'])) {
            return null;
        }

        if (strtolower($parts['scheme']) === 'https') {
            return $url;
        }

        $app = parse_url(url('/'));

        $sameOrigin = is_array($app)
            && strtolower((string) ($app['scheme'] ?? '')) === strtolower($parts['scheme'])
            && strtolower((string) ($app['host'] ?? '')) === strtolower($parts['host'])
            && ($app['port'] ?? null) === ($parts['port'] ?? null);

        return $sameOrigin ? $url : null;
    }
}
