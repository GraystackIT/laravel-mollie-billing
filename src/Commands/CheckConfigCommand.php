<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Commands;

use Cron\CronExpression;
use GraystackIT\MollieBilling\Concerns\HasBilling;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\AuditCategory;
use GraystackIT\MollieBilling\Enums\PlanChangeMode;
use GraystackIT\MollieBilling\Support\BillingAuditMap;
use GraystackIT\MollieBilling\Support\CountryResolver;
use Illuminate\Console\Command;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use GraystackIT\MollieBilling\Support\BillingRoute;

class CheckConfigCommand extends Command
{
    protected $signature = 'billing:check-config';

    protected $description = 'Validate the syntax and semantic integrity of mollie-billing.php and mollie-billing-plans.php.';

    /** @var list<array{level: string, scope: string, message: string}> */
    private array $issues = [];

    public function handle(): int
    {
        $this->checkBillingConfig();
        $this->checkPlansConfig();

        return $this->report();
    }

    private function checkBillingConfig(): void
    {
        $scope = 'mollie-billing';

        // Both accept `route:some.name`, and both are read from a layout — where a route that
        // does not exist would be a 500 on every page rather than a missing link.
        // BillingRoute::configuredUrl() degrades to null for exactly that reason, which makes
        // this the only place the typo is visible.
        foreach (['dashboard_url', 'admin_back_url'] as $urlKey) {
            $value = config('mollie-billing.'.$urlKey);

            if (! is_string($value) || ! str_starts_with(trim($value), 'route:')) {
                continue;
            }

            $routeName = substr(trim($value), 6);

            if ($routeName === '' || ! \Illuminate\Support\Facades\Route::has($routeName)) {
                $this->addError($scope, "{$urlKey} names route [{$routeName}], which is not registered — the link will be hidden.");
            }
        }

        $this->checkAdminBackUrlIsReachable($scope);
        $this->checkWebhookRouteIsMounted($scope);

        $billableModel = config('mollie-billing.billable_model');
        if (! is_string($billableModel) || $billableModel === '') {
            $this->addError($scope, 'billable_model is not set. Set BILLING_BILLABLE_MODEL in your .env or config/mollie-billing.php.');
        } elseif (! class_exists($billableModel)) {
            $this->addError($scope, "billable_model class [{$billableModel}] does not exist.");
        } else {
            $implements = class_implements($billableModel) ?: [];
            $traits = $this->classUsesRecursive($billableModel);

            if (! in_array(Billable::class, $implements, true)) {
                $this->addError($scope, "billable_model [{$billableModel}] does not implement ".Billable::class.'.');
            }
            if (! in_array(HasBilling::class, $traits, true)) {
                $this->addError($scope, "billable_model [{$billableModel}] does not use the ".HasBilling::class.' trait.');
            }
        }

        $keyType = (string) config('mollie-billing.billable_key_type', 'uuid');
        if (! in_array($keyType, ['uuid', 'ulid', 'int'], true)) {
            $this->addError($scope, "billable_key_type [{$keyType}] must be one of: uuid, ulid, int.");
        }

        $userKeyType = (string) config('mollie-billing.user_key_type', 'int');
        if (! in_array($userKeyType, ['uuid', 'ulid', 'int'], true)) {
            $this->addError($scope, "user_key_type [{$userKeyType}] must be one of: uuid, ulid, int.");
        }

        $planChangeMode = config('mollie-billing.plan_change_mode');
        $validModes = implode(', ', array_map(fn (PlanChangeMode $m) => $m->value, PlanChangeMode::cases()));

        if (! $planChangeMode instanceof PlanChangeMode) {
            $this->addError($scope, "plan_change_mode is invalid. Valid values: {$validModes}.");
        }

        // The config file degrades an unrecognised env value to the default rather than
        // throwing during `register()` — which used to take the whole app down, this
        // command included. That makes a typo silent, so it is named here instead. The
        // case names (`Immediate`, `EndOfPeriod`) are the likely mistake: the docs used
        // to print them, and they are not the backing values.
        // Read from the process environment rather than through `env()`: this has to work
        // on an install whose config is cached, where `env()` outside a config file
        // returns null and the typo would go unreported exactly when it is hardest to find.
        $rawMode = $_SERVER['BILLING_PLAN_CHANGE_MODE']
            ?? $_ENV['BILLING_PLAN_CHANGE_MODE']
            ?? (getenv('BILLING_PLAN_CHANGE_MODE') ?: null);

        if (is_string($rawMode) && $rawMode !== '' && PlanChangeMode::tryFrom(strtolower(trim($rawMode))) === null) {
            // The enum's case names are the likely mistake — the docs printed them for a
            // while — so name the backing value they meant instead of only listing all three.
            $suggestion = null;
            foreach (PlanChangeMode::cases() as $case) {
                if (strcasecmp($case->name, trim($rawMode)) === 0) {
                    $suggestion = $case->value;
                    break;
                }
            }

            $this->addError(
                $scope,
                "BILLING_PLAN_CHANGE_MODE [{$rawMode}] is not a valid plan change mode — falling back to "
                .PlanChangeMode::UserChoice->value.'. '
                .($suggestion !== null ? "Did you mean [{$suggestion}]? " : '')
                ."Valid values: {$validModes}."
            );
        }

        $currency = (string) config('mollie-billing.currency', 'EUR');
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            $this->addWarning($scope, "currency [{$currency}] should be an uppercase ISO-4217 3-letter code (e.g. EUR, USD).");
        }

        $disk = (string) config('mollie-billing.invoices.disk', 'local');
        $disks = (array) config('filesystems.disks', []);
        if (! array_key_exists($disk, $disks)) {
            $this->addError($scope, "invoices.disk [{$disk}] is not defined in config/filesystems.php disks.");
        } else {
            try {
                Storage::disk($disk);
            } catch (\Throwable $e) {
                $this->addError($scope, "invoices.disk [{$disk}] could not be resolved: {$e->getMessage()}");
            }
        }

        $ossDisk = config('mollie-billing.oss.disk');
        if (is_string($ossDisk) && $ossDisk !== '') {
            if (! array_key_exists($ossDisk, $disks)) {
                $this->addError($scope, "oss.disk [{$ossDisk}] is not defined in config/filesystems.php disks.");
            } else {
                try {
                    Storage::disk($ossDisk);
                } catch (\Throwable $e) {
                    $this->addError($scope, "oss.disk [{$ossDisk}] could not be resolved: {$e->getMessage()}");
                }
            }
        }

        $format = (string) config('mollie-billing.invoices.serial_number.format', 'PP-YYCCCCCC');
        if ($format === '') {
            $this->addError($scope, 'invoices.serial_number.format must not be empty.');
        } else {
            if (substr_count($format, 'P') === 0) {
                $this->addWarning($scope, "invoices.serial_number.format [{$format}] contains no 'P' (prefix) slots.");
            }
            if (substr_count($format, 'Y') === 0) {
                $this->addWarning($scope, "invoices.serial_number.format [{$format}] contains no 'Y' (year) slots.");
            }
            if (substr_count($format, 'C') === 0) {
                $this->addError($scope, "invoices.serial_number.format [{$format}] must contain at least one 'C' (counter) slot.");
            }
            $this->validateSerialNumberPrefixes($scope);
        }

        $this->validateSeller($scope);

        $driver = (string) config('mollie-billing.ip_geolocation.driver', 'ipinfo_lite');
        $drivers = (array) config('mollie-billing.ip_geolocation.drivers', []);
        if (! array_key_exists($driver, $drivers)) {
            $this->addError($scope, "ip_geolocation.driver [{$driver}] is not defined in ip_geolocation.drivers.");
        } elseif ($driver === 'ipinfo_lite' && empty($drivers['ipinfo_lite']['token'])) {
            $this->addWarning($scope, 'ip_geolocation.drivers.ipinfo_lite.token is empty — IPinfo lookups will fail. Set IPINFO_TOKEN.');
        } elseif ($driver === 'db_ip' && empty($drivers['db_ip']['api_key'])) {
            $this->addWarning($scope, 'ip_geolocation.drivers.db_ip.api_key is empty — DB-IP lookups will fall back to the public free tier (rate-limited). Set DB_IP_API_KEY for production use.');
        }

        $this->validateCheckoutCountries($scope);
        $this->validateAdditionalCountries($scope);
        $this->validateVatRateOverrides($scope);
        $this->validateBillingTimezone($scope);
        $this->validateQueue($scope);
        $this->validateWalletLock($scope);
        $this->validateCacheLocks($scope);
        $this->checkAdminRoutesAreMounted($scope);
        $this->validateScheduleTimes($scope);
        $this->validateCurrencySymbol($scope, $currency);
        $this->validateUsageThreshold($scope);
        $this->validateIpBlock($scope, $driver);
        $this->validateLegacyRolloverKeys($scope);
        $this->validateAudit($scope);
    }

    /**
     * Warn when the admin panel's back link names a route it cannot build a URL for.
     *
     * `admin_back_url` falls back to `dashboard_url`, and on a tenant-prefixed mount that is
     * typically `route:tenant.dashboard` — correct for the portal, where the current request
     * supplies `{organization}`, but unbuildable from the admin panel, which is mounted
     * outside that prefix. Console runs have no route parameters either, so generating the
     * URL here reproduces exactly what the panel's layout faces.
     *
     * @param  string  $scope  Config file the issue is reported against
     */
    /**
     * The webhook route is the one mounting step nothing else can survive without.
     *
     * Every payment the package creates carries `webhookUrl: route(BillingRoute::webhook())`,
     * so an app that mounted the portal and the checkout but not the webhook throws
     * `RouteNotFoundException` on the very first payment attempt — a 500 in checkout, with
     * nothing in the logs pointing at a missing route registration. `BillingRoute::webhook()`
     * falls back to the bare name when it finds no match, so it cannot report this itself.
     */
    private function checkWebhookRouteIsMounted(string $scope): void
    {
        if (\Illuminate\Support\Facades\Route::has(BillingRoute::webhook())) {
            return;
        }

        $this->addError(
            $scope,
            'The Mollie webhook route is not mounted. Add MollieBilling::webhookRoutes() to routes/api.php '
            .'(or any group WITHOUT the `web` middleware — Mollie calls it server-to-server, with no session '
            .'and no CSRF token). Without it every checkout fails with RouteNotFoundException [billing.webhook].'
        );
    }

    private function checkAdminBackUrlIsReachable(string $scope): void
    {
        $value = config('mollie-billing.admin_back_url') ?: config('mollie-billing.dashboard_url');

        if (! is_string($value) || ! str_starts_with(trim($value), 'route:')) {
            return;
        }

        $routeName = substr(trim($value), 6);

        if ($routeName === '' || ! \Illuminate\Support\Facades\Route::has($routeName)) {
            return;
        }

        try {
            route($routeName);
        } catch (UrlGenerationException) {
            $this->addWarning($scope, "admin_back_url resolves to route [{$routeName}], which needs parameters the admin panel cannot supply — the back link will be hidden there. Point BILLING_ADMIN_BACK_URL at a parameterless route or a plain URL.");
        }
    }

    private function validateAudit(string $scope): void
    {
        if (! config('mollie-billing.audit.enabled', true)) {
            return;
        }

        $categories = config('mollie-billing.audit.categories');
        $known = array_map(fn (AuditCategory $c): string => $c->value, AuditCategory::cases());

        if ($categories !== null && ! is_array($categories)) {
            $this->addError($scope, 'audit.categories must be an array of category values (or null to record every category); got ['.var_export($categories, true).'].');
        } elseif (is_array($categories)) {
            foreach ($categories as $category) {
                if (! is_string($category) || ! in_array($category, $known, true)) {
                    $this->addError($scope, 'audit.categories contains an unknown category ['.var_export($category, true).']; valid: '.implode(', ', $known).'.');
                }
            }
        }

        $retention = config('mollie-billing.audit.retention_days');
        // Values coming straight from .env arrive as numeric strings, which
        // PruneBillingAuditJob accepts — so accept them here too.
        if ($retention !== null && (! is_numeric($retention) || (int) $retention != $retention || (int) $retention < 1)) {
            $this->addError($scope, 'audit.retention_days must be null (keep forever) or a positive integer; got ['.var_export($retention, true).'].');
        }

        $table = (string) config('activitylog.table_name', 'activity_log');

        try {
            $connection = Schema::connection(config('activitylog.database_connection'));

            if (! $connection->hasTable($table)) {
                $this->addError($scope, "audit is enabled but the [{$table}] table does not exist — run `php artisan migrate`. Do not publish spatie's own activitylog migrations; this package ships one sized for your billable_key_type.");

                return;
            }

            // The reason we ship our own migration: spatie's stub uses
            // unsignedBigInteger morphs, which cannot hold uuid/ulid keys.
            $this->validateAuditMorphColumn(
                $scope,
                $connection,
                $table,
                'subject_id',
                'billable_key_type',
                (string) config('mollie-billing.billable_key_type', 'uuid'),
            );

            $this->validateAuditMorphColumn(
                $scope,
                $connection,
                $table,
                'causer_id',
                'user_key_type',
                (string) config('mollie-billing.user_key_type', 'int'),
            );
        } catch (\Throwable $e) {
            $this->addWarning($scope, 'Could not inspect the audit table: '.$e->getMessage());
        }

        // A missing translation renders as "Unrecognised billing event" in the
        // timeline rather than failing loudly, so surface it here instead.
        $missing = [];
        foreach (BillingAuditMap::all() as $descriptor) {
            $key = 'billing::'.$descriptor->descriptionKey();
            if (trans($key) === $key) {
                $missing[] = $descriptor->key;
            }
        }

        if ($missing !== []) {
            $this->addWarning($scope, 'Missing audit translations for the current locale ['.app()->getLocale().']: '.implode(', ', $missing).'. Re-publish with `php artisan vendor:publish --tag=billing-lang`.');
        }
    }

    /**
     * A numeric morph id cannot hold a uuid/ulid key. The reverse is harmless —
     * our own create migration ships string morphs, which store integer keys
     * fine — so only the numeric-column-with-textual-key direction is an error.
     */
    private function validateAuditMorphColumn(
        string $scope,
        \Illuminate\Database\Schema\Builder $connection,
        string $table,
        string $column,
        string $configKey,
        string $keyType,
    ): void {
        if ($keyType === 'int') {
            return;
        }

        $type = $connection->getColumnType($table, $column);

        if (in_array(strtolower($type), ['integer', 'bigint', 'int', 'int8'], true)) {
            $this->addError($scope, "[{$table}].{$column} is [{$type}] but {$configKey} is [{$keyType}] — audit writes will fail. This happens when spatie's own migration created the table; run `php artisan migrate` so the package's alter migration widens it.");
        }
    }

    private function validateLegacyRolloverKeys(string $scope): void
    {
        $migrationHint = 'Use `usage_rollover_fallback` (env: BILLING_USAGE_ROLLOVER_FALLBACK) and per-type overrides in `mollie-billing-plans.usage_types`.';

        if (array_key_exists('usage_rollover', (array) config('mollie-billing'))) {
            $this->addError($scope, "Legacy key `mollie-billing.usage_rollover` is set. {$migrationHint}");
        }

        if (getenv('BILLING_USAGE_ROLLOVER') !== false
            || isset($_ENV['BILLING_USAGE_ROLLOVER'])
            || isset($_SERVER['BILLING_USAGE_ROLLOVER'])) {
            $this->addError($scope, "Legacy env var `BILLING_USAGE_ROLLOVER` is set. {$migrationHint}");
        }
    }

    private function validateIpBlock(string $scope, string $geoDriver): void
    {
        $config = (array) config('mollie-billing.ip_block', []);

        if (! ($config['enabled'] ?? false)) {
            return;
        }

        $mode = (string) ($config['mode'] ?? 'blocklist');
        if (! in_array($mode, ['blocklist', 'allowlist'], true)) {
            $this->addError($scope, "ip_block.mode [{$mode}] must be 'blocklist' or 'allowlist'.");
        }

        $countries = (array) ($config['countries'] ?? []);
        foreach ($countries as $iso) {
            if (! is_string($iso) || ! preg_match('/^[A-Z]{2}$/', $iso)) {
                $this->addError($scope, 'ip_block.countries entries must be uppercase ISO-3166-1 alpha-2 codes; got ['.var_export($iso, true).'].');
            }
        }

        if ($mode === 'allowlist' && $countries === []) {
            $this->addError($scope, 'ip_block.mode is allowlist but ip_block.countries is empty — every visitor will be blocked.');
        }

        if ($geoDriver === 'null') {
            $this->addWarning($scope, 'ip_block.enabled is true but ip_geolocation.driver is "null" — no country will ever be resolved, so the gate is effectively disabled (or blocks everyone if ip_block.block_unknown is true).');
        }
    }

    private function validateSerialNumberPrefixes(string $scope): void
    {
        $prefixes = (array) config('mollie-billing.invoices.serial_number.prefix', []);
        $slots = substr_count((string) config('mollie-billing.invoices.serial_number.format', 'PP-YYCCCCCC'), 'P');

        foreach (['invoice', 'credit_note', 'one_time_order'] as $kind) {
            if (! isset($prefixes[$kind])) {
                $this->addWarning($scope, "invoices.serial_number.prefix.{$kind} is not defined — defaults will be used.");

                continue;
            }

            // A prefix longer than its `P` run is now truncated by the generator, which keeps
            // the sequence intact but silently shortens what the operator configured — so say
            // so here, where it can still be fixed.
            if (strlen((string) $prefixes[$kind]) > $slots) {
                $this->addError($scope, sprintf(
                    'invoices.serial_number.prefix.%s [%s] is longer than the %d "P" slot(s) in the format — it will be truncated.',
                    $kind,
                    (string) $prefixes[$kind],
                    $slots,
                ));
            }
        }
    }

    private function validateSeller(string $scope): void
    {
        $seller = (array) config('mollie-billing.invoices.seller', []);
        $required = ['company', 'email', 'tax_number'];

        foreach ($required as $key) {
            if (empty($seller[$key])) {
                $this->addWarning($scope, "invoices.seller.{$key} is empty — generated invoices will be incomplete.");
            }
        }

        $address = (array) ($seller['address'] ?? []);
        foreach (['street', 'city', 'postal_code', 'country'] as $key) {
            if (empty($address[$key])) {
                $this->addWarning($scope, "invoices.seller.address.{$key} is empty — generated invoices will be incomplete.");
            }
        }

        $this->validateSellerCountry($scope);
    }

    /**
     * The seller country decides domestic VAT versus cross-border reverse charge, so
     * a value that is set but not a valid ISO-3166-1 alpha-2 code is an error rather
     * than a warning: at runtime VatCalculationService refuses it and falls back to
     * charging VAT, which is recoverable — but every B2B invoice until someone
     * notices carries the wrong treatment. This is the place a typo should be loud.
     */
    private function validateSellerCountry(string $scope): void
    {
        $keys = [
            'mollie-billing.vat.seller_country',
            'mollie-billing.invoices.seller.address.country',
        ];

        foreach ($keys as $key) {
            $value = config($key);

            if ($value === null || (is_string($value) && trim($value) === '')) {
                continue;
            }

            if (! is_string($value) || preg_match('/^[A-Za-z]{2}$/', trim($value)) !== 1) {
                $shown = is_scalar($value) ? (string) $value : gettype($value);

                $this->addError($scope, sprintf(
                    '%s is "%s", which is not an ISO-3166-1 alpha-2 country code. Reverse charge is refused while it stays malformed, so every cross-border B2B supply is invoiced with domestic VAT.',
                    str_replace('mollie-billing.', '', $key),
                    $shown,
                ));
            }
        }
    }

    private function validateCheckoutCountries(string $scope): void
    {
        $countries = (array) config('mollie-billing.checkout_countries', []);

        $regions = (array) ($countries['regions'] ?? []);
        foreach ($regions as $region) {
            if ($region !== 'EU') {
                $this->addWarning($scope, "checkout_countries.regions contains [{$region}] — only 'EU' is currently supported.");
            }
        }

        $additional = (array) config('mollie-billing.additional_countries', []);

        foreach ((array) ($countries['include'] ?? []) as $iso) {
            if (! is_string($iso) || ! preg_match('/^[A-Z]{2}$/', $iso)) {
                $this->addError($scope, 'checkout_countries.include entries must be uppercase ISO-3166-1 alpha-2 codes; got ['.var_export($iso, true).'].');

                continue;
            }

            // Offering a country and being able to invoice it are two lists. A code that
            // is on the first and not the second used to be priced at 0 % by the checkout
            // and then could not be booked by the webhook: money collected, no invoice, no
            // subscription. CountryResolver now leaves such a code out of the selector, so
            // without this message the entry would just silently do nothing.
            if (! CountryResolver::isEuMember($iso) && ! array_key_exists($iso, $additional)) {
                $this->addError($scope, "checkout_countries.include lists [{$iso}], which is not an EU member state and has no additional_countries.{$iso} entry — there is no VAT rate to invoice it with, so it is not offered. Add 'additional_countries' => ['{$iso}' => ['vat_rate' => …, 'name' => …]].");
            }
        }

        foreach ((array) ($countries['exclude'] ?? []) as $iso) {
            if (! is_string($iso) || ! preg_match('/^[A-Z]{2}$/', $iso)) {
                $this->addError($scope, 'checkout_countries.exclude entries must be uppercase ISO-3166-1 alpha-2 codes; got ['.var_export($iso, true).'].');
            }
        }

        $resolved = CountryResolver::resolve();
        if ($resolved === []) {
            $this->addError($scope, 'checkout_countries resolves to an empty list — no country can be selected during checkout.');
        }
    }

    private function validateAdditionalCountries(string $scope): void
    {
        $additional = (array) config('mollie-billing.additional_countries', []);
        foreach ($additional as $iso => $data) {
            if (! is_string($iso) || ! preg_match('/^[A-Z]{2}$/', $iso)) {
                $this->addError($scope, 'additional_countries keys must be uppercase ISO-3166-1 alpha-2 codes; got ['.var_export($iso, true).'].');

                continue;
            }
            if (! is_array($data)) {
                $this->addError($scope, "additional_countries.{$iso} must be an array with 'vat_rate' and 'name'.");

                continue;
            }
            if (! array_key_exists('vat_rate', $data)) {
                $this->addError($scope, "additional_countries.{$iso}.vat_rate must be numeric (e.g. 8.1).");
            } else {
                $this->validateVatRate($scope, "additional_countries.{$iso}.vat_rate", $data['vat_rate']);
            }
            if (empty($data['name'])) {
                $this->addWarning($scope, "additional_countries.{$iso}.name is empty — the ISO code will be shown as label.");
            }
        }
    }

    /**
     * `vat_rate_overrides` outranks every other rate source and is cast with `(float)`,
     * so nothing at runtime ever objects to what is written there.
     */
    private function validateVatRateOverrides(string $scope): void
    {
        $overrides = config('mollie-billing.vat_rate_overrides', []);

        if ($overrides === null) {
            return;
        }

        if (! is_array($overrides)) {
            $this->addError($scope, 'vat_rate_overrides must be an array of ISO country code => percentage.');

            return;
        }

        foreach ($overrides as $iso => $rate) {
            if (! is_string($iso) || ! preg_match('/^[A-Z]{2}$/', $iso)) {
                // The lookup upper-cases the country, so a lower-case key is never read:
                // the override the operator wrote silently does not apply.
                $this->addError($scope, 'vat_rate_overrides keys must be uppercase ISO-3166-1 alpha-2 codes; got ['.var_export($iso, true).'] — the entry is never read.');

                continue;
            }

            $this->validateVatRate($scope, "vat_rate_overrides.{$iso}", $rate);
        }
    }

    /**
     * A VAT rate is a percentage: 19.0 means 19 %.
     *
     * mpociot — the library the package falls back to — speaks decimals (0.19), so the
     * fraction is the mistake waiting to happen. It is numeric, it used to pass, and every
     * invoice for that country then carried 0.19 % VAT. No VAT rate lies strictly between
     * 0 and 1 percent, so that range is refused outright; 0 itself stays valid (a country
     * sold to without VAT).
     */
    private function validateVatRate(string $scope, string $where, mixed $rate): void
    {
        if (is_bool($rate) || ! is_numeric($rate)) {
            $this->addError($scope, "{$where} must be a numeric percentage (e.g. 19.0); got [".var_export($rate, true).'] — a non-numeric value is read as 0 % VAT.');

            return;
        }

        $value = (float) $rate;

        if ($value < 0.0 || $value > 100.0) {
            $this->addError($scope, "{$where} [{$rate}] must be between 0 and 100 (a percentage).");

            return;
        }

        if ($value > 0.0 && $value < 1.0) {
            $this->addError($scope, "{$where} [{$rate}] looks like a decimal fraction — rates are percentages, so write ".rtrim(rtrim(number_format($value * 100, 4, '.', ''), '0'), '.').' for '.rtrim(rtrim(number_format($value * 100, 4, '.', ''), '0'), '.').' %.');
        }
    }

    /**
     * Every queued job and notification in the package is routed onto this connection.
     * A typo in BILLING_QUEUE_CONNECTION is invisible until the first dispatch, which
     * then throws `InvalidArgumentException: The [xyz] queue connection has not been
     * configured` — so nothing gets retried, nothing gets charged, nothing gets swept.
     */
    private function validateQueue(string $scope): void
    {
        $connection = config('mollie-billing.queue.connection');

        // null / '' is the documented "fall back to the framework default".
        if ($connection !== null && $connection !== '') {
            if (! is_string($connection)) {
                $this->addError($scope, 'queue.connection must be a string connection name, or null to use the framework default; got ['.var_export($connection, true).'].');
            } elseif (! array_key_exists($connection, (array) config('queue.connections', []))) {
                $this->addError($scope, "queue.connection [{$connection}] is not defined in config/queue.php connections — every queued billing job would throw on dispatch. Set BILLING_QUEUE_CONNECTION to a configured connection or leave it unset.");
            }
        }

        $queue = config('mollie-billing.queue.name');

        if ($queue !== null && $queue !== '' && ! is_string($queue)) {
            $this->addError($scope, 'queue.name must be a string queue name, or null to use the framework default; got ['.var_export($queue, true).'].');
        }

        // A warning, not an error: a sync install works, and the retry jobs are written to
        // survive it. But every job then runs inside the request that dispatched it, and a
        // job that throws has failed for good — so a retry is never retried by the queue,
        // and the package's coarser scheduled passes are all there is.
        if (\GraystackIT\MollieBilling\Support\BillingQueue::runsInline()) {
            $this->addWarning($scope, sprintf(
                'The billing jobs run on the [%s] connection, which uses the sync driver: each job runs '
                .'inside the request that dispatches it, delays are ignored, and a job that throws has '
                .'failed for good at its first error — the queue never retries it. The retry jobs '
                .'(refund lines, overage charges, Mollie subscription PATCH and cancel, activation) then '
                .'fall back to the scheduled passes: the hourly billing sweep and the nightly overage pass '
                .'instead of a backoff of minutes. Run a real queue (database, redis, sqs) with a worker, '
                .'via BILLING_QUEUE_CONNECTION or QUEUE_CONNECTION.',
                \GraystackIT\MollieBilling\Support\BillingQueue::connection(),
            ));
        }
    }

    /**
     * The wallet lock is what keeps two processes from billing the same units twice.
     *
     * bavix defaults `wallet.lock.driver` to `array`, which is a per-process store: the
     * lock it takes around a balance update is invisible to every other process. That is
     * fine for a single-process install and wrong for every other one — this package has a
     * web request recording usage while a queue worker charges the overage against the
     * same wallet, and the balance both of them read is what the customer is billed from.
     *
     * A warning rather than an error, because it depends on the deployment: a sync queue
     * and one web worker genuinely have nothing to serialise. Reported whenever the queue
     * is not `sync`, which is the closest thing to "more than one process" that can be
     * read from config.
     */
    private function validateWalletLock(string $scope): void
    {
        $driver = config('wallet.lock.driver');

        // No bavix config published at all: it ships its own default, which is `array`.
        $driver = is_string($driver) && $driver !== '' ? $driver : 'array';

        if ($driver !== 'array') {
            return;
        }

        $queue = (string) (config('mollie-billing.queue.connection') ?: config('queue.default', 'sync'));

        if ($queue === 'sync') {
            return;
        }

        $this->addWarning($scope, sprintf(
            'wallet.lock.driver is [array] while the billing queue runs on [%s]. '
            .'The array store is per-process, so bavix takes a lock no other process can see — '
            .'a request recording usage and the worker charging the overage can interleave on the '
            .'same wallet, and the balance this package bills from is the result. '
            .'Set WALLET_LOCK_DRIVER to a shared store (redis, memcached, database).',
            $queue,
        ));
    }

    /**
     * The package's own money-path locks are cache locks, so the cache store decides.
     *
     * `UpdateSubscription`, `RefundInvoiceService` and `SyncSeatsJob` each serialise on a
     * `Cache::lock()` — that is what replaced the long transaction they used to hold, and
     * it is the only thing standing between two concurrent plan changes, two refund clicks
     * or two seat deltas. On the `array` store that lock lives in one process's memory, and
     * on `null` it does not exist at all: every caller gets it, and both changes proceed.
     *
     * Reported for the same deployment shape as the wallet lock — anything but a sync queue
     * means more than one process is running.
     */
    private function validateCacheLocks(string $scope): void
    {
        $store = (string) config('cache.default', 'file');
        $driver = (string) (config("cache.stores.{$store}.driver") ?: $store);

        if (! in_array($driver, ['array', 'null'], true)) {
            return;
        }

        $queue = (string) (config('mollie-billing.queue.connection') ?: config('queue.default', 'sync'));

        if ($queue === 'sync' && $driver === 'array') {
            return;
        }

        $this->addWarning($scope, sprintf(
            'The default cache store [%s] uses the [%s] driver, and this package serialises '
            .'its money paths with Cache::lock(): plan changes (billing:subscription-change), '
            .'refunds (billing:invoice-refund) and seat deltas (billing:seat-sync). '
            .'%s Point CACHE_STORE at a shared, lock-capable store (redis, memcached, database, file).',
            $store,
            $driver,
            $driver === 'null'
                ? 'The null driver grants every lock, so those guards do nothing at all.'
                : 'The array driver keeps the lock in one process\'s memory, so a second process takes it too.',
        ));
    }

    /**
     * Auto-load off and nothing mounted means there is no admin panel at all.
     *
     * Switching `routes.auto_load_admin` off is how an app puts the panel under its own
     * middleware, with `MollieBilling::adminRoutes()` inside its own group. Forgetting the
     * second half leaves every admin URL 404ing, and nothing at boot says so.
     */
    private function checkAdminRoutesAreMounted(string $scope): void
    {
        if (config('mollie-billing.routes.auto_load_admin', true)) {
            return;
        }

        if (\Illuminate\Support\Facades\Route::has(BillingRoute::admin('dashboard'))) {
            return;
        }

        $this->addWarning($scope, 'routes.auto_load_admin is false and no admin route is registered — the admin panel is unreachable. Call MollieBilling::adminRoutes() inside your own route group, or turn the auto-load back on.');
    }

    private function validateBillingTimezone(string $scope): void
    {
        $tz = (string) config('mollie-billing.billing_timezone', 'UTC');
        if (! in_array($tz, timezone_identifiers_list(), true)) {
            $this->addError($scope, "billing_timezone [{$tz}] is not a valid IANA timezone identifier.");
        }
    }

    /**
     * Every value that feeds the scheduler, not just overage_job_time. An invalid
     * `trial_lifecycle_job_time` or `cleanup.cron_expression` makes `schedule:run`
     * throw for the *entire application's* schedule, so reporting the config valid is
     * the worst possible outcome.
     */
    private function validateScheduleTimes(string $scope): void
    {
        foreach (['overage_job_time' => '02:00', 'trial_lifecycle_job_time' => '02:05'] as $key => $default) {
            $time = (string) config("mollie-billing.{$key}", $default);

            if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                $this->addError($scope, "{$key} [{$time}] must be in HH:MM (24-hour) format.");
            }
        }

        $cron = (string) config('mollie-billing.cleanup.cron_expression', '*/15 * * * *');

        if (! CronExpression::isValidExpression($cron)) {
            $this->addError($scope, "cleanup.cron_expression [{$cron}] is not a valid cron expression.");
        }
    }

    /**
     * `currency_symbol` overrides the symbol for *every* currency, so a leftover
     * value from an earlier currency renders the wrong symbol on every price and
     * every invoice.
     */
    private function validateCurrencySymbol(string $scope, string $currency): void
    {
        $symbol = config('mollie-billing.currency_symbol');

        if (! is_string($symbol) || $symbol === '') {
            return;
        }

        $expected = ['EUR' => '€', 'USD' => '$', 'GBP' => '£'];
        $known = $expected[strtoupper($currency)] ?? null;

        if ($known !== null && trim($symbol) !== $known) {
            $this->addWarning($scope, "currency_symbol [{$symbol}] does not match currency [{$currency}] (expected [{$known}]). Leave BILLING_CURRENCY_SYMBOL unset to derive it from `currency`.");
        }
    }

    private function validateUsageThreshold(string $scope): void
    {
        $threshold = config('mollie-billing.usage_threshold_percent', 80);
        if (! is_numeric($threshold) || $threshold < 0 || $threshold > 100) {
            $this->addError($scope, "usage_threshold_percent [{$threshold}] must be a number between 0 and 100.");
        }
    }

    private function checkPlansConfig(): void
    {
        $scope = 'mollie-billing-plans';

        $plans = (array) config('mollie-billing-plans.plans', []);
        $features = (array) config('mollie-billing-plans.features', []);
        $addons = (array) config('mollie-billing-plans.addons', []);
        $products = (array) config('mollie-billing-plans.products', []);
        $productGroups = (array) config('mollie-billing-plans.product_groups', []);

        if ($plans === []) {
            $this->addError($scope, 'plans is empty — at least one plan must be defined.');
        } elseif ($this->visiblePlanCodes($plans) === []) {
            $this->addError($scope, 'every plan is marked `hidden` — the checkout plan step would have nothing to offer. At least one plan must stay visible.');
        }

        // `visible` decides whether a customer sees the feature on a plan card. A value
        // that is not a boolean is almost always a string from an env() call, and
        // `'false'` is truthy — the feature the operator meant to hide stays on display.
        foreach ($features as $key => $feature) {
            if (is_array($feature) && array_key_exists('visible', $feature) && ! is_bool($feature['visible'])) {
                $this->addError($scope, "features.{$key}.visible must be a boolean (true or false), got ".get_debug_type($feature['visible']).'.');
            }
        }

        $featureKeys = array_keys($features);
        $addonKeys = array_keys($addons);
        $groupKeys = array_keys($productGroups);

        $this->validatePlans($scope, $plans, $featureKeys, $addonKeys);
        $this->validateAddons($scope, $addons, $featureKeys);
        $this->warnAboutUnreachableAddonSeatPrices($scope, $plans, $addons);
        $this->validateProducts($scope, $products, $groupKeys, $plans);
        $this->validateUsageTypes($scope, $plans);

        $this->checkUnusedFeatures($scope, $featureKeys, $plans, $addons);
    }

    /**
     * @param  array<string, mixed>  $plans
     */
    private function validateUsageTypes(string $scope, array $plans): void
    {
        $rawConfig = config('mollie-billing-plans');
        $hasUsageTypes = is_array($rawConfig) && array_key_exists('usage_types', $rawConfig);

        if (! $hasUsageTypes) {
            return;
        }

        $usageTypes = $rawConfig['usage_types'];

        if (! is_array($usageTypes)) {
            $this->addError($scope, 'usage_types must be an array.');

            return;
        }

        foreach ($usageTypes as $type => $config) {
            $where = "usage_types.{$type}";

            if (! is_string($type) || $type === '') {
                $this->addError($scope, 'usage_types contains an invalid usage type key.');

                continue;
            }

            if (! is_array($config)) {
                $this->addError($scope, "{$where} must be an array (e.g. ['rollover' => true]).");

                continue;
            }

            if (! array_key_exists('rollover', $config)) {
                $this->addError($scope, "{$where}.rollover is required (true or false).");
            } elseif (! is_bool($config['rollover'])) {
                $this->addError($scope, "{$where}.rollover must be a boolean.");
            }

            // Same rule as features.*.visible: a non-boolean is almost always a string
            // from an env() call, and `'false'` does not hide.
            if (array_key_exists('visible', $config) && ! is_bool($config['visible'])) {
                $this->addError($scope, "{$where}.visible must be a boolean (true or false), got ".get_debug_type($config['visible']).'.');
            } elseif (($config['visible'] ?? true) === false) {
                $this->warnAboutPricedHiddenUsageType($scope, $type, $plans);
            }
        }
    }

    /**
     * A hidden usage type is left off the plan cards, but its overage is still charged.
     * Priced, the customer pays per unit for something the card they chose from never
     * mentioned. Valid — the operator may state it elsewhere — so a warning.
     *
     * @param  array<string, mixed>  $plans
     */
    private function warnAboutPricedHiddenUsageType(string $scope, string $type, array $plans): void
    {
        foreach ($plans as $planCode => $plan) {
            $intervals = is_array($plan) ? (array) ($plan['intervals'] ?? []) : [];

            foreach ($intervals as $intervalKey => $interval) {
                $prices = is_array($interval) ? (array) ($interval['usage_overage_prices'] ?? []) : [];

                foreach ($prices as $priceType => $price) {
                    // `false` / `null` declare no overage, and a price of 0 charges nothing:
                    // in neither case does the customer pay for what the card leaves out.
                    if (strcasecmp((string) $priceType, $type) !== 0 || $price === false || $price === null || (is_numeric($price) && (int) $price <= 0)) {
                        continue;
                    }

                    $this->addWarning($scope, "usage_types.{$type}.visible is false, but plans.{$planCode}.intervals.{$intervalKey}.usage_overage_prices.{$priceType} charges for it — the customer pays overage for a usage type the plan card does not show. Show the type, or set its overage price to `false`.");
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $plans
     * @param  list<string>  $featureKeys
     * @param  list<string>  $addonKeys
     */
    private function validatePlans(string $scope, array $plans, array $featureKeys, array $addonKeys): void
    {
        $tiers = [];

        foreach ($plans as $code => $plan) {
            $where = "plans.{$code}";

            if (! is_array($plan)) {
                $this->addError($scope, "{$where} must be an array.");

                continue;
            }

            if (empty($plan['name'])) {
                $this->addWarning($scope, "{$where}.name is empty — translations may still be used.");
            }

            if (array_key_exists('hidden', $plan) && ! is_bool($plan['hidden'])) {
                $this->addError($scope, "{$where}.hidden must be a boolean.");
            }

            // Absent is fine, malformed is not. `tier` is the consuming app's own plan
            // rank — nothing in the package reads it, and up/downgrade is derived from the
            // prorata amounts — so a config without it is complete, and failing the command
            // over it blocked deploys on a field that changes nothing. A value that *is*
            // there but is not an integer is still a config error, because whatever reads
            // it will compare it.
            if (! isset($plan['tier'])) {
                $this->addWarning($scope, "{$where}.tier is not set — the package does not read it, but your own plan-rank comparisons will have nothing to compare.");
            } elseif (! is_int($plan['tier'])) {
                $this->addError($scope, "{$where}.tier must be an integer (your app's plan rank).");
            } elseif (empty($plan['hidden'])) {
                // Only visible plans form the offered ladder, so only they can make
                // its ranking ambiguous. A hidden internal plan is free to mirror a
                // public plan's tier without being told off for it.
                $tiers[(int) $plan['tier']][] = (string) $code;
            }

            if (array_key_exists('trial_days', $plan)) {
                $this->addError($scope, "{$where}.trial_days is no longer supported at plan level. Move the value into each interval (e.g. {$where}.intervals.monthly.trial_days).");
            }

            if (array_key_exists('usage_rollover', $plan)) {
                $this->addError($scope, "{$where}.usage_rollover is no longer supported. Use `usage_rollover_fallback` (env: BILLING_USAGE_ROLLOVER_FALLBACK) and per-type overrides in `mollie-billing-plans.usage_types`.");
            }

            // ConfigSubscriptionCatalog reads both of these only from
            // intervals.{monthly|yearly} and has no plan-level fallback, so a map put
            // here resolves every quota to 0 and makes no overage chargeable — while
            // the config still looked valid.
            foreach (['included_usages', 'usage_overage_prices'] as $intervalOnlyKey) {
                if (array_key_exists($intervalOnlyKey, $plan)) {
                    $this->addError($scope, "{$where}.{$intervalOnlyKey} is not read at plan level. Move it into each interval (e.g. {$where}.intervals.monthly.{$intervalOnlyKey}) — the catalog resolves it per (plan, interval) with no plan-level fallback.");
                }
            }

            $this->validateUsageTypesAcrossIntervals($scope, $where, (array) ($plan['intervals'] ?? []));

            if (isset($plan['included_seats']) && (! is_int($plan['included_seats']) || $plan['included_seats'] < 1)) {
                $this->addError($scope, "{$where}.included_seats must be a positive integer.");
            }

            foreach ((array) ($plan['feature_keys'] ?? []) as $key) {
                if (! is_string($key) || ! in_array($key, $featureKeys, true)) {
                    $this->addError($scope, "{$where}.feature_keys references unknown feature [".(string) $key.'].');
                }
            }

            foreach ((array) ($plan['allowed_addons'] ?? []) as $addonCode) {
                if (! is_string($addonCode) || ! in_array($addonCode, $addonKeys, true)) {
                    $this->addError($scope, "{$where}.allowed_addons references unknown addon [".(string) $addonCode.'].');
                }
            }

            $intervals = (array) ($plan['intervals'] ?? []);
            if ($intervals === []) {
                $this->addError($scope, "{$where}.intervals must define at least one of: monthly, yearly.");
                continue;
            }

            foreach ($intervals as $intervalKey => $interval) {
                if (! in_array($intervalKey, ['monthly', 'yearly'], true)) {
                    $this->addError($scope, "{$where}.intervals.{$intervalKey} is not a recognised interval (expected: monthly, yearly).");
                }

                $this->validatePlanInterval($scope, $where, (string) $intervalKey, (array) $interval);
            }
        }

        foreach ($tiers as $tier => $codes) {
            if (count($codes) > 1) {
                $this->addWarning($scope, "tier [{$tier}] is shared by multiple plans: ".implode(', ', $codes).' — plan ranking by tier becomes ambiguous.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $interval
     */
    /**
     * A usage type sold on one interval of a plan and forgotten on the other.
     *
     * Quotas and overage prices are keyed by (plan, interval) with no fallback, which is
     * deliberate — a yearly quota is not twelve monthlies. The cost of that is a config
     * shape with no symptom until somebody switches interval: the quota resolves to 0, and
     * with no overage price on that side the hard cap turns the customer's next metered
     * call into an exception. Same plan, same subscription, feature gone.
     *
     * Deliberately asymmetric quotas are normal, so this only reports a type that is
     * *absent from both maps* on one side while being present on the other.
     *
     * @param  array<string, mixed>  $intervals
     */
    private function validateUsageTypesAcrossIntervals(string $scope, string $where, array $intervals): void
    {
        $declared = [];

        foreach ($intervals as $intervalKey => $interval) {
            if (! is_array($interval)) {
                continue;
            }

            foreach (['included_usages', 'usage_overage_prices'] as $mapKey) {
                foreach (array_keys((array) ($interval[$mapKey] ?? [])) as $type) {
                    if (is_string($type) && $type !== '') {
                        $declared[strtolower($type)][(string) $intervalKey] = $type;
                    }
                }
            }
        }

        $intervalKeys = array_values(array_filter(
            array_keys($intervals),
            static fn ($k): bool => is_string($k) && is_array($intervals[$k] ?? null),
        ));

        if (count($intervalKeys) < 2) {
            return;
        }

        foreach ($declared as $seen) {
            $missing = array_values(array_diff($intervalKeys, array_keys($seen)));

            if ($missing === []) {
                continue;
            }

            $type = reset($seen);

            $this->addWarning($scope, sprintf(
                '%s sells usage type [%s] on [%s] but declares it nowhere under [%s]. '
                .'Quotas and overage prices are resolved per (plan, interval) with no fallback, so a '
                .'customer switching to %s loses the type entirely — quota 0, and with no overage price '
                .'there the next metered call is refused.',
                $where,
                $type,
                implode(', ', array_keys($seen)),
                implode(', ', $missing),
                implode('/', $missing),
            ));
        }
    }

    private function validatePlanInterval(string $scope, string $where, string $intervalKey, array $interval): void
    {
        $base = "{$where}.intervals.{$intervalKey}";

        if (! array_key_exists('base_price_net', $interval)) {
            $this->addError($scope, "{$base}.base_price_net is required.");
        } elseif (! is_int($interval['base_price_net']) || $interval['base_price_net'] < 0) {
            $this->addError($scope, "{$base}.base_price_net must be a non-negative integer (in minor currency units).");
        }

        if (array_key_exists('seat_price_net', $interval) && $interval['seat_price_net'] !== null) {
            if (! is_int($interval['seat_price_net']) || $interval['seat_price_net'] < 0) {
                $this->addError($scope, "{$base}.seat_price_net must be null or a non-negative integer.");
            }
        }

        if (array_key_exists('trial_days', $interval) && $interval['trial_days'] !== null) {
            if (! is_int($interval['trial_days']) || $interval['trial_days'] < 0) {
                $this->addError($scope, "{$base}.trial_days must be a non-negative integer.");
            } elseif ($interval['trial_days'] > 0) {
                $basePrice = is_int($interval['base_price_net'] ?? null) ? (int) $interval['base_price_net'] : 0;
                $seatPrice = $interval['seat_price_net'] ?? null;
                if ($basePrice === 0 && $seatPrice === null) {
                    $this->addWarning($scope, "{$base}.trial_days is set but the interval is free (no base or seat price) — the trial flow only applies to paid intervals and will be ignored.");
                }
            }
        }

        $included = (array) ($interval['included_usages'] ?? []);
        $overage = (array) ($interval['usage_overage_prices'] ?? []);

        foreach ($included as $type => $value) {
            if (! is_string($type) || $type === '') {
                $this->addError($scope, "{$base}.included_usages contains an invalid usage type key.");
            }
            if (! is_int($value) || $value < 0) {
                $this->addError($scope, "{$base}.included_usages.{$type} must be a non-negative integer.");
            }
        }

        foreach ($overage as $type => $price) {
            if (! is_string($type) || $type === '') {
                $this->addError($scope, "{$base}.usage_overage_prices contains an invalid usage type key.");
            }
            // `false` is the explicit "this quota deliberately has no overage"
            // declaration (see the missing-price warning below). `true` is not
            // accepted — it would read as a price without being one.
            if ($price === false) {
                continue;
            }
            if (! is_int($price) || $price < 0) {
                $this->addError($scope, "{$base}.usage_overage_prices.{$type} must be a non-negative integer, or `false` to declare that this quota has no overage.");
            }
        }

        $includedKeys = array_map('strval', array_keys($included));
        $overageKeys = array_map('strval', array_keys($overage));

        $missingPrices = array_diff($includedKeys, $overageKeys);
        foreach ($missingPrices as $type) {
            if (($included[$type] ?? 0) > 0) {
                $this->addWarning($scope, "{$base}.included_usages defines [{$type}] but {$base}.usage_overage_prices has no entry — overages cannot be charged. Set `'{$type}' => false` there if the quota is meant to have no overage.");
            }
        }

        // An explicit `false` declares intent about a quota, so it does not need a
        // matching quota entry to be meaningful — warning here would punish exactly
        // the declaration we just asked for.
        $missingIncluded = array_diff($overageKeys, $includedKeys);
        foreach ($missingIncluded as $type) {
            if (($overage[$type] ?? null) === false) {
                continue;
            }
            // Two readings, and only the operator knows which was meant. Priced with no
            // quota is pay-per-use: every unit is billed, the wallet only ever goes
            // negative, and the package handles it — the debit is allowed, the period-end
            // pass charges it, the plan-change rebalance bills what is owed. It is also
            // exactly what a forgotten `included_usages` entry looks like, and then the
            // customer is billed per unit for something they were promised an allowance of.
            $this->addWarning($scope, "{$base}.usage_overage_prices defines [{$type}] but {$base}.included_usages has no matching entry, so the quota is 0 and every unit is billed at the overage price. That is a valid pay-per-use type — add `'{$type}' => 0` to included_usages to say so deliberately, or the allowance you meant to grant.");
        }
    }

    /**
     * @param  array<string, mixed>  $addons
     * @param  list<string>  $featureKeys
     */
    private function validateAddons(string $scope, array $addons, array $featureKeys): void
    {
        foreach ($addons as $code => $addon) {
            $where = "addons.{$code}";

            if (! is_array($addon)) {
                $this->addError($scope, "{$where} must be an array.");

                continue;
            }

            if (empty($addon['name'])) {
                $this->addWarning($scope, "{$where}.name is empty — translations may still be used.");
            }

            $features = (array) ($addon['feature_keys'] ?? []);
            if ($features === []) {
                $this->addWarning($scope, "{$where}.feature_keys is empty — this addon unlocks no features.");
            }
            foreach ($features as $key) {
                if (! is_string($key) || ! in_array($key, $featureKeys, true)) {
                    $this->addError($scope, "{$where}.feature_keys references unknown feature [".(string) $key.'].');
                }
            }

            $intervals = (array) ($addon['intervals'] ?? []);
            if ($intervals === []) {
                $this->addError($scope, "{$where}.intervals must define at least one of: monthly, yearly.");
                continue;
            }

            foreach ($intervals as $intervalKey => $interval) {
                if (! in_array($intervalKey, ['monthly', 'yearly'], true)) {
                    $this->addError($scope, "{$where}.intervals.{$intervalKey} is not a recognised interval (expected: monthly, yearly).");
                }
                $price = (array) $interval;
                if (! array_key_exists('price_net', $price)) {
                    $this->addError($scope, "{$where}.intervals.{$intervalKey}.price_net is required.");
                } elseif (! is_int($price['price_net']) || $price['price_net'] < 0) {
                    $this->addError($scope, "{$where}.intervals.{$intervalKey}.price_net must be a non-negative integer.");
                }

                // Per-seat pricing sits ON TOP of the base price, so `price_net` stays
                // required next to it (0 is a valid base) — the error above covers that. A
                // negative seat price would be a per-seat rebate that every amount, invoice
                // line and Mollie PATCH would faithfully subtract.
                if (array_key_exists('seat_price_net', $price) && $price['seat_price_net'] !== null
                    && (! is_int($price['seat_price_net']) || $price['seat_price_net'] < 0)
                ) {
                    $this->addError($scope, "{$where}.intervals.{$intervalKey}.seat_price_net must be null or a non-negative integer.");
                }
            }

            if (array_key_exists('included_seats', $addon)
                && (! is_int($addon['included_seats']) || $addon['included_seats'] < 0)
            ) {
                $this->addError($scope, "{$where}.included_seats must be a non-negative integer.");
            }

            $this->warnAboutLopsidedAddonSeatPricing($scope, $where, $addon, $intervals);
        }
    }

    /**
     * Per-seat pricing that is valid field by field and still does not bill what its
     * author meant. Warnings, because each shape can be deliberate.
     *
     * @param  array<string, mixed>  $addon
     * @param  array<string|int, mixed>  $intervals
     */
    private function warnAboutLopsidedAddonSeatPricing(string $scope, string $where, array $addon, array $intervals): void
    {
        $with = [];
        $without = [];

        foreach ($intervals as $intervalKey => $interval) {
            $seatPrice = is_array($interval) ? ($interval['seat_price_net'] ?? null) : null;

            if (is_int($seatPrice) && $seatPrice > 0) {
                $with[] = (string) $intervalKey;
            } else {
                $without[] = (string) $intervalKey;
            }
        }

        // The seat price is read per (addon, interval) with no fallback — like every
        // other price. Sold per seat on one interval and flat on the other, a customer
        // who switches interval stops (or starts) paying for seats.
        if ($with !== [] && $without !== []) {
            $this->addWarning($scope, sprintf(
                '%s declares seat_price_net on [%s] but not on [%s] — there the addon is billed at its base price only, however many seats the subscription has.',
                $where,
                implode(', ', $with),
                implode(', ', $without),
            ));
        }

        if ($with === [] && is_int($addon['included_seats'] ?? null) && $addon['included_seats'] > 0) {
            $this->addWarning($scope, "{$where}.included_seats is set but no interval has a seat_price_net — there is no per-seat price for those seats to be free of.");
        }
    }

    /**
     * A plan that allows a per-seat addon but can never have a seat the addon charges.
     *
     * The addon bills every seat of the subscription beyond its own `included_seats`. A
     * plan that sells no extra seats stays at its included ones forever, so when those do
     * not exceed the addon's free seats the per-seat price is dead on that plan: the
     * addon is sold there at its base price alone.
     *
     * @param  array<string, mixed>  $plans
     * @param  array<string, mixed>  $addons
     */
    private function warnAboutUnreachableAddonSeatPrices(string $scope, array $plans, array $addons): void
    {
        foreach ($plans as $planCode => $plan) {
            if (! is_array($plan)) {
                continue;
            }

            $planIncluded = is_int($plan['included_seats'] ?? null) ? (int) $plan['included_seats'] : 1;

            foreach ((array) ($plan['allowed_addons'] ?? []) as $addonCode) {
                $addon = is_string($addonCode) ? ($addons[$addonCode] ?? null) : null;

                if (! is_array($addon)) {
                    continue;
                }

                $addonIncluded = is_int($addon['included_seats'] ?? null) ? max(0, (int) $addon['included_seats']) : 0;

                if ($planIncluded > $addonIncluded) {
                    continue;
                }

                foreach ((array) ($plan['intervals'] ?? []) as $intervalKey => $interval) {
                    $addonSeatPrice = $addon['intervals'][$intervalKey]['seat_price_net'] ?? null;
                    $planSellsSeats = is_array($interval) && ($interval['seat_price_net'] ?? null) !== null;

                    if (is_int($addonSeatPrice) && $addonSeatPrice > 0 && ! $planSellsSeats) {
                        $this->addWarning($scope, sprintf(
                            'plans.%s allows the per-seat addon [%s], but on [%s] it sells no extra seats and includes %d — no more than the addon\'s %d free seat(s). The addon\'s seat_price_net is never charged on this plan.',
                            (string) $planCode,
                            $addonCode,
                            (string) $intervalKey,
                            $planIncluded,
                            $addonIncluded,
                        ));

                        continue 2;
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $products
     * @param  list<string>  $groupKeys
     * @param  array<string, mixed>  $plans
     */
    private function validateProducts(string $scope, array $products, array $groupKeys, array $plans): void
    {
        $usageTypes = $this->collectUsageTypes($plans);

        foreach ($products as $code => $product) {
            $where = "products.{$code}";

            if (! is_array($product)) {
                $this->addError($scope, "{$where} must be an array.");

                continue;
            }

            if (empty($product['name'])) {
                $this->addWarning($scope, "{$where}.name is empty — translations may still be used.");
            }

            if (! array_key_exists('price_net', $product)) {
                $this->addError($scope, "{$where}.price_net is required.");
            } elseif (! is_int($product['price_net']) || $product['price_net'] < 1) {
                // Not "non-negative": StartOneTimeOrderCheckout refuses a product without
                // a price, so a 0 here is a card on the products page whose button fails.
                $this->addError($scope, "{$where}.price_net must be a positive integer (in minor currency units) — a product cannot be sold for 0.");
            }

            if (isset($product['group']) && $product['group'] !== null) {
                if (! is_string($product['group']) || ! in_array($product['group'], $groupKeys, true)) {
                    $this->addError($scope, "{$where}.group references unknown product group [".(string) $product['group'].'].');
                }
            }

            $hasUsageType = isset($product['usage_type']) && $product['usage_type'] !== null && $product['usage_type'] !== '';
            $hasQuantity = isset($product['quantity']) && $product['quantity'] !== null;

            if ($hasUsageType) {
                if (! is_string($product['usage_type'])) {
                    $this->addError($scope, "{$where}.usage_type must be a string.");
                } elseif (! $this->matchesUsageTypeCaseInsensitive($product['usage_type'], $usageTypes)) {
                    $this->addWarning($scope, "{$where}.usage_type [{$product['usage_type']}] is not declared in any plan's included_usages or usage_overage_prices — wallet credits may not be metered.");
                }
                if (! $hasQuantity) {
                    $this->addWarning($scope, "{$where} declares usage_type but no quantity — purchase will not credit any wallet units.");
                }
            }

            if ($hasQuantity) {
                if (! is_int($product['quantity']) || $product['quantity'] < 1) {
                    $this->addError($scope, "{$where}.quantity must be a positive integer.");
                }
                if (! $hasUsageType) {
                    $this->addWarning($scope, "{$where} declares quantity but no usage_type — credits cannot be routed to a wallet.");
                }
            }

            if (isset($product['onetimeonly']) && ! is_bool($product['onetimeonly'])) {
                $this->addError($scope, "{$where}.onetimeonly must be a boolean.");
            }

            // Only a literal `true` shows the unit price; a string from env() would not.
            if (array_key_exists('show_unit_price', $product) && ! is_bool($product['show_unit_price'])) {
                $this->addError($scope, "{$where}.show_unit_price must be a boolean (true or false), got ".get_debug_type($product['show_unit_price']).'.');
            } elseif (($product['show_unit_price'] ?? false) === true
                && (empty($product['usage_type']) || (int) ($product['quantity'] ?? 0) <= 1)) {
                $this->addWarning($scope, "{$where}.show_unit_price is true, but the product has no usage_type or a quantity of 1 or less — no unit price is shown.");
            }
        }
    }

    /**
     * Plan codes that are offered to customers.
     *
     * Read straight from the raw config rather than through the catalog: this
     * command validates the config file itself, and a rebound catalog may not be
     * backed by it at all.
     *
     * @param  array<string, mixed>  $plans
     * @return list<string>
     */
    private function visiblePlanCodes(array $plans): array
    {
        $visible = [];
        foreach ($plans as $code => $plan) {
            if (is_array($plan) && ! empty($plan['hidden'])) {
                continue;
            }
            $visible[] = (string) $code;
        }

        return $visible;
    }

    /**
     * @param  array<string, mixed>  $plans
     * @return list<string>
     */
    private function collectUsageTypes(array $plans): array
    {
        $types = [];
        foreach ($plans as $plan) {
            foreach ((array) ($plan['intervals'] ?? []) as $interval) {
                foreach (array_keys((array) ($interval['included_usages'] ?? [])) as $type) {
                    $types[(string) $type] = true;
                }
                foreach (array_keys((array) ($interval['usage_overage_prices'] ?? [])) as $type) {
                    $types[(string) $type] = true;
                }
            }
        }

        return array_keys($types);
    }

    /**
     * @param  list<string>  $haystack
     */
    private function matchesUsageTypeCaseInsensitive(string $needle, array $haystack): bool
    {
        foreach ($haystack as $candidate) {
            if (strcasecmp($candidate, $needle) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $featureKeys
     * @param  array<string, mixed>  $plans
     * @param  array<string, mixed>  $addons
     */
    private function checkUnusedFeatures(string $scope, array $featureKeys, array $plans, array $addons): void
    {
        $used = [];
        foreach ($plans as $plan) {
            foreach ((array) ($plan['feature_keys'] ?? []) as $key) {
                $used[(string) $key] = true;
            }
        }
        foreach ($addons as $addon) {
            foreach ((array) ($addon['feature_keys'] ?? []) as $key) {
                $used[(string) $key] = true;
            }
        }

        foreach ($featureKeys as $key) {
            if (! isset($used[$key])) {
                $this->addWarning($scope, "features.{$key} is defined but not referenced by any plan or addon.");
            }
        }
    }

    /**
     * @return list<class-string>
     */
    private function classUsesRecursive(string $class): array
    {
        $traits = [];
        do {
            $traits = array_merge(class_uses($class) ?: [], $traits);
        } while ($class = get_parent_class($class));

        foreach ($traits as $trait) {
            $traits = array_merge(class_uses($trait) ?: [], $traits);
        }

        return array_values(array_unique($traits));
    }

    private function addError(string $scope, string $message): void
    {
        $this->issues[] = ['level' => 'error', 'scope' => $scope, 'message' => $message];
    }

    private function addWarning(string $scope, string $message): void
    {
        $this->issues[] = ['level' => 'warning', 'scope' => $scope, 'message' => $message];
    }

    private function report(): int
    {
        $errors = array_filter($this->issues, fn (array $i) => $i['level'] === 'error');
        $warnings = array_filter($this->issues, fn (array $i) => $i['level'] === 'warning');

        foreach ($errors as $issue) {
            $this->components->error("[{$issue['scope']}] {$issue['message']}");
        }

        foreach ($warnings as $issue) {
            $this->components->warn("[{$issue['scope']}] {$issue['message']}");
        }

        $this->newLine();

        if ($errors === [] && $warnings === []) {
            $this->components->info('Billing configuration is valid — no issues detected.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Validation finished: %d error(s), %d warning(s).',
            count($errors),
            count($warnings),
        ));

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }
}
