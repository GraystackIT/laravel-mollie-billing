<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\AuthorizesBillingAdmin;
use GraystackIT\MollieBilling\Http\Middleware\AuthorizeBillingAdmin;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LocaleTestAdminUser extends Authenticatable implements AuthorizesBillingAdmin
{
    public function canAccessBillingAdmin(): bool
    {
        return true;
    }
}

function passThroughAdminMiddleware(): void
{
    $request = Request::create('/billing/admin');
    $request->setUserResolver(fn () => new LocaleTestAdminUser);

    (new AuthorizeBillingAdmin)->handle($request, fn () => new Response);
}

it('leaves the application locale untouched by default', function (): void {
    config()->set('mollie-billing.admin_locale', null);
    app()->setLocale('de');

    passThroughAdminMiddleware();

    expect(app()->getLocale())->toBe('de');
});

it('pins the locale when admin_locale is configured', function (): void {
    config()->set('mollie-billing.admin_locale', 'en');
    app()->setLocale('de');

    passThroughAdminMiddleware();

    expect(app()->getLocale())->toBe('en');
});

it('ignores an empty admin_locale', function (): void {
    config()->set('mollie-billing.admin_locale', '');
    app()->setLocale('de');

    passThroughAdminMiddleware();

    expect(app()->getLocale())->toBe('de');
});

it('aborts for users that cannot access the admin panel', function (): void {
    $request = Request::create('/billing/admin');
    $request->setUserResolver(fn () => null);

    expect(fn () => (new AuthorizeBillingAdmin)->handle($request, fn () => new Response))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});
