<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Commands;

use GraystackIT\MollieBilling\Concerns\HasBilling;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\PlanChangeMode;
use GraystackIT\MollieBilling\Support\CountryResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

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
        if (! $planChangeMode instanceof PlanChangeMode) {
            $valid = implode(', ', array_map(fn (PlanChangeMode $m) => $m->value, PlanChangeMode::cases()));
            $this->addError($scope, "plan_change_mode is invalid. Valid values: {$valid}.");
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
        $this->validateBillingTimezone($scope);
        $this->validateOverageJobTime($scope);
        $this->validateUsageThreshold($scope);
        $this->validateIpBlock($scope, $driver);
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
        foreach (['invoice', 'credit_note', 'one_time_order'] as $kind) {
            if (! isset($prefixes[$kind])) {
                $this->addWarning($scope, "invoices.serial_number.prefix.{$kind} is not defined — defaults will be used.");
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

        foreach ((array) ($countries['include'] ?? []) as $iso) {
            if (! is_string($iso) || ! preg_match('/^[A-Z]{2}$/', $iso)) {
                $this->addError($scope, 'checkout_countries.include entries must be uppercase ISO-3166-1 alpha-2 codes; got ['.var_export($iso, true).'].');
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
            if (! isset($data['vat_rate']) || ! is_numeric($data['vat_rate'])) {
                $this->addError($scope, "additional_countries.{$iso}.vat_rate must be numeric (e.g. 8.1).");
            }
            if (empty($data['name'])) {
                $this->addWarning($scope, "additional_countries.{$iso}.name is empty — the ISO code will be shown as label.");
            }
        }
    }

    private function validateBillingTimezone(string $scope): void
    {
        $tz = (string) config('mollie-billing.billing_timezone', 'UTC');
        if (! in_array($tz, timezone_identifiers_list(), true)) {
            $this->addError($scope, "billing_timezone [{$tz}] is not a valid IANA timezone identifier.");
        }
    }

    private function validateOverageJobTime(string $scope): void
    {
        $time = (string) config('mollie-billing.overage_job_time', '02:00');
        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            $this->addError($scope, "overage_job_time [{$time}] must be in HH:MM (24-hour) format.");
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
        }

        $featureKeys = array_keys($features);
        $addonKeys = array_keys($addons);
        $groupKeys = array_keys($productGroups);

        $this->validatePlans($scope, $plans, $featureKeys, $addonKeys);
        $this->validateAddons($scope, $addons, $featureKeys);
        $this->validateProducts($scope, $products, $groupKeys, $plans);

        $this->checkUnusedFeatures($scope, $featureKeys, $plans, $addons);
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

            if (! isset($plan['tier']) || ! is_int($plan['tier'])) {
                $this->addError($scope, "{$where}.tier must be an integer (used to compare plan rank).");
            } else {
                $tiers[(int) $plan['tier']][] = (string) $code;
            }

            if (isset($plan['trial_days']) && (! is_int($plan['trial_days']) || $plan['trial_days'] < 0)) {
                $this->addError($scope, "{$where}.trial_days must be a non-negative integer.");
            }

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
            if (! is_int($price) || $price < 0) {
                $this->addError($scope, "{$base}.usage_overage_prices.{$type} must be a non-negative integer.");
            }
        }

        $includedKeys = array_map('strval', array_keys($included));
        $overageKeys = array_map('strval', array_keys($overage));

        $missingPrices = array_diff($includedKeys, $overageKeys);
        foreach ($missingPrices as $type) {
            if (($included[$type] ?? 0) > 0) {
                $this->addWarning($scope, "{$base}.included_usages defines [{$type}] but {$base}.usage_overage_prices has no entry — overages cannot be charged.");
            }
        }

        $missingIncluded = array_diff($overageKeys, $includedKeys);
        foreach ($missingIncluded as $type) {
            $this->addWarning($scope, "{$base}.usage_overage_prices defines [{$type}] but {$base}.included_usages has no matching entry — quota will default to 0.");
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
            } elseif (! is_int($product['price_net']) || $product['price_net'] < 0) {
                $this->addError($scope, "{$where}.price_net must be a non-negative integer.");
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
        }
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
