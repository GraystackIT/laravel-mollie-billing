<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Scopes;

use GraystackIT\MollieBilling\Contracts\Billable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Delegates filtering to the billable model's applyBillingScope() hook.
 * Bypass with ->withoutGlobalScope(BillingScope::class) on lookups that
 * must work for every row regardless of the app-defined restriction —
 * webhook resolution, retry jobs, admin impersonation flows.
 */
class BillingScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if ($model instanceof Billable) {
            $model->applyBillingScope($builder);
        }
    }
}
