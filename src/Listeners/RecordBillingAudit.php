<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Listeners;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\AuditCategory;
use GraystackIT\MollieBilling\Support\BillingAuditActor;
use GraystackIT\MollieBilling\Support\BillingAuditMap;
use GraystackIT\MollieBilling\Support\BillingAuditDescriptor;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes one audit-trail row per billing event.
 *
 * Deliberately NOT queued: several events carry a \Throwable (OverageChargeFailed,
 * SubscriptionChangeApplyFailed) which cannot be serialised onto a queue.
 *
 * Stores a translation *key* as the description plus raw placeholder values, so
 * the timeline renders in whatever locale the reader uses — see BillingAuditEntry.
 */
class RecordBillingAudit
{
    public function handle(object $event): void
    {
        try {
            $this->record($event);
        } catch (\Throwable $e) {
            // Auditing must never break a billing flow. Surface it to the app's
            // error handler and carry on.
            report($e);
        }
    }

    private function record(object $event): void
    {
        if (! config('mollie-billing.audit.enabled', true)) {
            return;
        }

        $descriptor = BillingAuditMap::for($event);

        if ($descriptor === null || ! $this->categoryEnabled($descriptor)) {
            return;
        }

        $billable = $event->billable ?? null;

        // performedOn() needs an Eloquent model; the Billable check keeps foreign
        // objects that happen to be models out of the billing trail.
        if (! $billable instanceof Billable || ! $billable instanceof Model) {
            return;
        }

        activity((string) config('mollie-billing.audit.log_name', 'billing'))
            ->performedOn($billable)
            ->causedBy(BillingAuditActor::causer())
            ->event($descriptor->key)
            ->withProperties([
                'category' => $descriptor->category->value,
                'actor' => BillingAuditActor::kind(),
                'replace' => $descriptor->properties($event),
            ])
            ->log($descriptor->descriptionKey());
    }

    /**
     * Categories are individually switchable because `usage` events
     * (UsageLimitReached, WalletCredited) can fire per request under metered
     * billing, where one synchronous insert each is a real cost.
     */
    private function categoryEnabled(BillingAuditDescriptor $descriptor): bool
    {
        $enabled = config('mollie-billing.audit.categories');

        if (! is_array($enabled)) {
            return true;
        }

        return in_array($descriptor->category->value, $enabled, true);
    }

    /** @return list<string> */
    public static function defaultCategories(): array
    {
        return array_map(fn (AuditCategory $c): string => $c->value, AuditCategory::cases());
    }
}
