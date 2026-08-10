<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Http\Middleware\AuthorizeBillingAdmin;
use GraystackIT\MollieBilling\Http\Middleware\RequireActiveSubscription;
use GraystackIT\MollieBilling\Jobs\CleanupStalePendingProrataChangeJob;
use GraystackIT\MollieBilling\Jobs\SweepPendingRetriesJob;
use GraystackIT\MollieBilling\MollieBillingServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;

it('re-applies the admin authorization middleware on livewire update requests', function (): void {
    // This is the exact list Livewire consults when rebuilding the middleware stack
    // for a /livewire/update request.
    $middleware = app(PersistentMiddleware::class)->getPersistentMiddleware();

    expect($middleware)->toContain(RequireActiveSubscription::class);

    // Without this, an operator whose canAccessBillingAdmin() flips to false keeps
    // calling forceCancel/refund/credit from an already-loaded tab, and the
    // admin_locale pinning is lost after the first component interaction.
    expect($middleware)->toContain(AuthorizeBillingAdmin::class);
});

it('publishes translations into lang_path, not resources/lang', function (): void {
    $paths = MollieBillingServiceProvider::pathsToPublish(MollieBillingServiceProvider::class, 'billing-lang');

    expect($paths)->not->toBeEmpty();

    // Publishing into resources/lang flips Laravel's lang-path resolution on the next
    // boot and stops every root-level lang/ file of the host app from loading.
    foreach ($paths as $target) {
        expect($target)->toBe(lang_path('vendor/billing'));
        expect($target)->not->toContain('resources'.DIRECTORY_SEPARATOR.'lang');
    }
});

it('does not publish the billable-table stub verbatim', function (): void {
    $paths = MollieBillingServiceProvider::pathsToPublish(MollieBillingServiceProvider::class, 'mollie-billing-migrations');

    foreach ($paths as $target) {
        expect($target)->not->toEndWith('.stub');
    }

    // The stub is still published — under a timestamped migration name.
    $stubTargets = array_filter(
        $paths,
        fn (string $target) => str_contains($target, 'add_billing_columns_to_billable_table'),
    );

    expect($stubTargets)->toHaveCount(1);
});

it('loads its migrations outside the console, so HTTP-triggered tenant provisioning gets the tables', function (): void {
    // loadMigrationsFrom() used to be the last statement of a console-only method, so
    // Artisan::call('migrate') from an HTTP request built a tenant schema with no
    // billing table at all. It is now registered from boot() unconditionally.
    $expected = realpath(__DIR__.'/../../database/migrations');

    $registered = array_filter(array_map(
        fn (string $path): string|false => realpath($path),
        app('migrator')->paths(),
    ));

    expect($registered)->toContain($expected);
});

it('schedules the stale-prorata cleanup and the pending-retry sweep', function (): void {
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    $scheduled = [];
    foreach ($schedule->events() as $event) {
        $scheduled[] = $event->description ?? '';
    }

    expect($scheduled)->toContain(CleanupStalePendingProrataChangeJob::class);
    expect($scheduled)->toContain(SweepPendingRetriesJob::class);
});

it('registers no blade component namespace for a directory that does not exist', function (): void {
    expect(is_dir(__DIR__.'/../../src/View/Components'))->toBeFalse();

    // The dead `billing` component namespace was removed; all ten anonymous
    // components are addressed through the `mollie-billing::` view namespace.
    expect(view()->exists('mollie-billing::components.admin.empty'))->toBeTrue();
});
