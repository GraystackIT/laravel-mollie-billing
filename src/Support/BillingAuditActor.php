<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

use GraystackIT\MollieBilling\Http\Middleware\AuthorizeBillingAdmin;
use Illuminate\Database\Eloquent\Model;

/**
 * Works out who triggered a billing event.
 *
 * Much of this package runs outside an HTTP request — webhooks, queued jobs,
 * scheduled commands — where there simply is no authenticated user. Those rows
 * are attributed to `system` rather than being silently dropped, so a timeline
 * gap always means "nothing happened", never "we couldn't tell who did it".
 */
final class BillingAuditActor
{
    public const SYSTEM = 'system';

    public const ADMIN = 'admin';

    public const CUSTOMER = 'customer';

    public static function causer(): ?Model
    {
        if (! app()->bound('auth')) {
            return null;
        }

        $user = auth()->user();

        return $user instanceof Model ? $user : null;
    }

    public static function kind(): string
    {
        if (self::causer() === null) {
            return self::SYSTEM;
        }

        return self::inAdminContext() ? self::ADMIN : self::CUSTOMER;
    }

    /**
     * True when the current request is operator work.
     *
     * Two signals, because neither covers both request shapes. The route name covers the
     * initial page load and is resolved through BillingRoute so a mounted prefix (e.g.
     * `tenant.billing.admin.`) is honoured. It does *not* cover anything after that: an
     * admin panel built on Livewire never navigates again, and every later button is a
     * POST to `/livewire/update` — so every mutation an operator made was filed against
     * them as a customer action. AuthorizeBillingAdmin is in Livewire's persistent
     * middleware list and therefore runs on those requests too; the attribute it sets is
     * what carries the context through.
     */
    private static function inAdminContext(): bool
    {
        if (! app()->bound('request')) {
            return false;
        }

        if (request()->attributes->get(AuthorizeBillingAdmin::CONTEXT_ATTRIBUTE) === true) {
            return true;
        }

        $name = request()->route()?->getName();

        if ($name === null || $name === '') {
            return false;
        }

        try {
            $adminPrefix = BillingRoute::admin('');
        } catch (\Throwable) {
            return false;
        }

        return $adminPrefix !== '' && str_starts_with($name, $adminPrefix);
    }
}
