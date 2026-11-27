<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Support\BillingTime;

/**
 * A paid addon or an extra seat was to be booked into the grace period of a subscription
 * that has been cancelled at the period end.
 *
 * The subscription keeps its access until `subscription_ends_at`, and nothing is renewed
 * after it. A booking there was a pro-rata charge for a period that ends anyway — the
 * customer paid for a few days of an addon on a subscription they had asked to stop. It is
 * refused before anything reaches Mollie, and the sentence says until when the
 * subscription runs and what to do instead: withdraw the cancellation (the dashboard's
 * "Resubscribe"), then book. Switching an addon off, fewer seats and an addon that bills
 * nothing stay possible.
 *
 * A subclass of {@see SubscriptionEndedChangeNotAllowedException}, the general refusal for
 * a cancelled or expired subscription, so every screen and the preview that already catch
 * that one handle this one too; only its sentence is more precise. A plan or interval
 * change on a cancelled subscription, and anything on an expired one, keep the general
 * refusal.
 *
 * Like its parent, the message is the sentence in English, for the log; a screen shows it in
 * the reader's language through the translation key it carries (with the date), resolved by
 * {@see \GraystackIT\MollieBilling\Support\ExceptionMessage}.
 */
class SubscriptionEndingBookingNotAllowedException extends SubscriptionEndedChangeNotAllowedException
{
    public const ADDONS = 'billing::portal.error_subscription_ending_addons';

    public const SEATS = 'billing::portal.error_subscription_ending_seats';

    public static function addons(Billable $billable): self
    {
        return new self($billable, self::ADDONS);
    }

    public static function seats(Billable $billable): self
    {
        return new self($billable, self::SEATS);
    }

    /**
     * Whether the billable is in that grace period: cancelled, with an end still ahead.
     * An expired subscription, or a cancelled one whose end has passed, is not — there is
     * no cancellation left to withdraw.
     */
    public static function appliesTo(Billable $billable): bool
    {
        return $billable->getBillingSubscriptionStatus() === \GraystackIT\MollieBilling\Enums\SubscriptionStatus::Cancelled
            && ($billable->getBillingSubscriptionEndsAt()?->isFuture() ?? false);
    }

    /**
     * The end date the sentence names, formatted the way the dashboard shows it — in the
     * current locale, or in the one given.
     */
    public static function endDate(Billable $billable, ?string $locale = null): string
    {
        $day = BillingTime::billingDay($billable->getBillingSubscriptionEndsAt());

        if ($day !== null && $locale !== null) {
            $day = $day->copy()->locale($locale);
        }

        return (string) $day?->translatedFormat('d. M Y');
    }

    public function __construct(Billable $billable, public readonly string $messageKey = self::ADDONS)
    {
        parent::__construct($billable);

        $this->message = (string) __($messageKey, ['date' => self::endDate($billable, 'en')], 'en');
        $this->withTranslation($messageKey, ['date' => self::endDate($billable)]);
    }
}
