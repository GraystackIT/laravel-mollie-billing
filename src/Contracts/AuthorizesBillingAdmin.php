<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Contracts;

/**
 * Implement this on the App's User model to grant access to /billing/admin.
 * There is intentionally no default implementation — admin access must be
 * explicitly opted into.
 */
interface AuthorizesBillingAdmin
{
    public function canAccessBillingAdmin(): bool;
}
