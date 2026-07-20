<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

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
     * True when the current request is handled by one of the package's admin
     * routes. Resolved through BillingRoute so a mounted prefix (e.g.
     * `tenant.billing.admin.`) is honoured.
     */
    private static function inAdminContext(): bool
    {
        if (! app()->bound('request')) {
            return false;
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
