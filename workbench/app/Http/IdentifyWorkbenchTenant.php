<?php

declare(strict_types=1);

namespace Workbench\App\Http;

use Closure;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The tenant identification of a multi-tenant app, for the workbench's prefixed mount
 * (`tenant_mount` = 1, see WorkbenchServiceProvider::registerTenantMount()).
 *
 * What a real app does for `Route::prefix('{organization:slug}')`: read the slug, find the
 * organization, and keep it for the rest of the request. It is listed as Livewire
 * persistent middleware, because a component's action runs on `/livewire/update`, whose
 * own route carries no `{organization}` — only the re-applied middleware sees the page's
 * original route. A slug no organization has is a 404, as in an app.
 */
class IdentifyWorkbenchTenant
{
    public const CONTAINER_KEY = 'wb.tenant';

    public function handle(Request $request, Closure $next)
    {
        $slug = $request->route('organization');

        if (is_string($slug) && $slug !== '') {
            $tenant = self::findBySlug($slug);

            abort_if($tenant === null, 404);

            app()->instance(self::CONTAINER_KEY, $tenant);
        }

        return $next($request);
    }

    /** The workbench's billables carry no slug column: the slug is their name's. */
    public static function slugOf(TestBillable $billable): string
    {
        return Str::slug((string) $billable->name);
    }

    public static function findBySlug(string $slug): ?TestBillable
    {
        return TestBillable::query()->orderBy('id')->get()
            ->first(fn (TestBillable $billable): bool => self::slugOf($billable) === $slug);
    }
}
