<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use Illuminate\Support\Str;

/**
 * Human-readable labels for the `reason` stored on every wallet transaction.
 *
 * The reasons are internal identifiers — `subscription_trial_start`,
 * `one_time_order:starter-pack` — and the usage history rendered them raw wherever a
 * translation happened to be missing, which is what a customer saw for every reason
 * added after the language files were written. Resolution happens here so the portal,
 * the admin screens and any app-side listing arrive at the same wording:
 *
 * 1. an exact `billing::portal.usage_reasons.{reason}` key,
 * 2. a dynamic reason (`one_time_order:{productCode}`) rendered with its product name,
 * 3. otherwise the reason title-cased — free-text reasons (an admin's note, an app's
 *    own `recordBillingUsage($type, $n, 'Brave Image Search')`) pass through readable
 *    instead of being replaced by a placeholder that hides what happened.
 */
final class UsageReason
{
    public static function label(?string $reason): string
    {
        $reason = trim((string) $reason);

        // A debit written without a reason is plain consumption — that is what
        // `WalletUsageService::debit()` stores as `usage`.
        if ($reason === '') {
            return self::translate('usage') ?? Str::headline('usage');
        }

        if (($exact = self::translate($reason)) !== null) {
            return $exact;
        }

        if (str_starts_with($reason, 'one_time_order:')) {
            $code = substr($reason, strlen('one_time_order:'));
            $name = app(SubscriptionCatalogInterface::class)->productName($code) ?? $code;

            return self::translate('one_time_order', ['product' => $name]) ?? $name;
        }

        // A sentence somebody typed — an operator's reason for a credit, an app's own
        // wording — is shown as it was written. Only an identifier is turned into a
        // heading; run through `headline()` as well, "Goodwill after the outage" came out
        // as "Goodwill After The Outage".
        if (preg_match('/\s/u', $reason) === 1) {
            return $reason;
        }

        return Str::headline($reason);
    }

    /**
     * The translated reason, or null when no key exists for it.
     *
     * @param  array<string, string>  $replace
     */
    private static function translate(string $key, array $replace = []): ?string
    {
        $langKey = "billing::portal.usage_reasons.{$key}";
        $translated = __($langKey, $replace);

        return is_string($translated) && $translated !== $langKey ? $translated : null;
    }
}
