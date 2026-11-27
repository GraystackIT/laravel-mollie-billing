<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use GraystackIT\MollieBilling\Contracts\AuthorizesBillingAdmin;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * The operator persona for the admin-panel scenarios. The package's
 * AuthorizeBillingAdmin middleware demands an authenticated user implementing
 * AuthorizesBillingAdmin — the customer portal, by contrast, needs no auth at
 * all (it resolves the billable via MollieBilling::resolveBillableUsing()).
 */
class AdminUser extends Authenticatable implements AuthorizesBillingAdmin
{
    protected $table = 'admin_users';

    protected $guarded = [];

    /**
     * Always, unless a test demoted the operator mid-session (`deny_billing_admin` = 1): the
     * server reads it on every request, so a tab that is already open loses the panel on its
     * next click.
     */
    public function canAccessBillingAdmin(): bool
    {
        return ! \Workbench\App\Providers\WorkbenchServiceProvider::settingIs('deny_billing_admin', '1');
    }
}
