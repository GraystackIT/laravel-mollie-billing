<?php

use GraystackIT\MollieBilling\Concerns\ValidatesVatNumber;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Events\CheckoutStarted;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\CountryResolver;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\Sanitize;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Layout('mollie-billing::layouts.checkout')] class extends Component {
    use ValidatesVatNumber;

    public int $step = 1;

    // Step 1: billing address
    public string $company_name = '';
    public string $billing_street = '';
    public string $billing_postal_code = '';
    public string $billing_city = '';
    public string $billing_country = '';
    public ?string $vat_number = null;

    /**
     * Outcome of the last server-side VIES check. Locked: this flag alone decides
     * whether the customer is charged net (reverse charge) instead of gross, so a
     * client-supplied `true` would strip the VAT off the Mollie payment.
     */
    #[Locked]
    public ?bool $vatNumberValid = null;
    public ?string $vatStatusMessage = null;

    // Step 2: plan
    public string $plan_code = '';
    public string $interval = 'monthly';

    // Step 3: addons + seats
    /** @var array<int,string> */
    public array $addon_codes = [];
    public int $extra_seats = 0;

    // Step 4: coupon
    public string $coupon_input = '';
    /** @var array{code:string,name:string,discount_net:int}|null */
    public ?array $applied_coupon = null;
    public ?string $couponError = null;

    // Custom step data (used by app-provided checkout step views via wire:model="customData.xxx")
    public array $customData = [];

    // Internal state
    public ?string $backUrl = null;
    public ?string $errorMessage = null;
    public bool $processing = false;

    /**
     * The billable created in the billing-address step (stored as ID + class for
     * Livewire serialisation).
     *
     * Locked: `submit()` writes the billing address, the VIES audit row and the
     * pending first payment onto whatever this pair resolves to. Unlocked, a
     * crafted `updates` payload would repoint both at another tenant and rewrite
     * its billing data — and `billableClass` additionally feeds a container
     * lookup, so it must never be client-supplied.
     */
    #[Locked]
    public ?string $billableId = null;
    #[Locked]
    public ?string $billableClass = null;

    /**
     * True when this component instance created the billable itself (anonymous
     * signup). Those billables have no owner to authorize against yet, so the
     * authorization gate in `submit()` is skipped for them — and only for them.
     */
    #[Locked]
    public bool $billableCreatedHere = false;

    /**
     * True when the checkout was entered with a pre-existing billable that already
     * carries persisted billing data (e.g. after a previously cancelled subscription
     * or an access-grant-activated local subscription). The billing-address step is
     * skipped and the data is shown read-only on the confirm step.
     *
     * Locked: flipping it to false is what would re-enable the billing-address
     * write in `submit()` for a billable the user only entered checkout with.
     */
    #[Locked]
    public bool $billing_locked = false;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    public function mount(): void
    {
        $this->backUrl = Sanitize::backUrl(request()->query('back'));

        $this->billing_country = MollieBilling::ipGeolocation()->defaultCountryFor(request()->ip());

        // If a billable is already resolvable from the request (e.g. checkout
        // entered after a force-cancelled local subscription), pre-fill the
        // billing-address fields and lock the billing step. The controller
        // already redirects away when an active subscription exists, so this
        // branch only runs for billables without an accessible subscription.
        $existing = MollieBilling::resolveBillable(request());
        if ($existing !== null && $this->hasPersistedBillingData($existing)) {
            $this->billableId = (string) $existing->getKey();
            $this->billableClass = $existing->getMorphClass();
            $this->company_name = $existing->getBillingName();
            $this->billing_street = (string) $existing->getBillingStreet();
            $this->billing_postal_code = (string) $existing->getBillingPostalCode();
            $this->billing_city = (string) $existing->getBillingCity();
            $this->billing_country = (string) $existing->getBillingCountry();
            $this->vat_number = $existing->vat_number;
            // A persisted VAT number only counts as confirmed when the audit trail
            // actually says so. Treating "vat_number is set" as valid would grant
            // reverse charge to a number VIES rejected.
            if (filled($this->vat_number) && $this->billableUsesReverseCharge($existing)) {
                $this->vatNumberValid = true;
            }
            $this->billing_locked = true;
            // Skip the billing step — start at the plan step (offset by any custom steps).
            $this->step = $this->customStepCount() + 2;
        }

        // Pre-select plan and/or interval from query parameters
        $plan = request()->query('plan');
        if ($plan !== null && array_key_exists($plan, $this->plans())) {
            $this->plan_code = $plan;
        }

        $interval = request()->query('interval');
        if ($interval !== null && in_array($interval, ['monthly', 'yearly'], true)) {
            $this->interval = $interval;
        }

        // If only a single plan is configured, auto-select it — there's no
        // meaningful choice to make and the validator would otherwise reject
        // the form with "plan code field is required".
        if ($this->plan_code === '') {
            $plans = $this->plans();
            if (count($plans) === 1) {
                $this->plan_code = (string) array_key_first($plans);
            }
        }

        // Last, because it depends on the plan being settled: a plan may be sold in
        // only one interval, and an interval it does not sell carries no price —
        // which the pricing paths read as free. That applies to `?interval=` as much
        // as to the property default, so normalise instead of trusting either.
        $this->interval = $this->firstOfferedInterval($this->plan_code, $this->interval);
    }

    /**
     * The intervals the customer may actually pick, in display order.
     *
     * With a plan chosen, that plan's own intervals. Before then, every interval
     * at least one selectable plan is sold in — so a catalog of monthly-only plans
     * shows no toggle at all instead of a yearly option that prices to 0.
     *
     * @return array<int, string>
     */
    public function offeredIntervals(string $planCode = ''): array
    {
        $catalog = app(SubscriptionCatalogInterface::class);
        $codes = $planCode !== '' ? [$planCode] : array_keys($this->plans());

        $offered = [];
        foreach (['monthly', 'yearly'] as $candidate) {
            foreach ($codes as $code) {
                if ($catalog->planOffersInterval((string) $code, $candidate)) {
                    $offered[] = $candidate;
                    continue 2;
                }
            }
        }

        return $offered;
    }

    /**
     * The preferred interval if the plan sells it, otherwise the plan's own
     * remaining interval. Falls back to the preference when nothing is known
     * (no plan chosen yet, or a catalog that offers neither).
     */
    private function firstOfferedInterval(string $planCode, string $preferred): string
    {
        if ($planCode === '') {
            return $preferred;
        }

        $catalog = app(SubscriptionCatalogInterface::class);

        if ($catalog->planOffersInterval($planCode, $preferred)) {
            return $preferred;
        }

        foreach (['monthly', 'yearly'] as $candidate) {
            if ($catalog->planOffersInterval($planCode, $candidate)) {
                return $candidate;
            }
        }

        return $preferred;
    }

    private function hasPersistedBillingData(Billable $billable): bool
    {
        return filled($billable->getBillingStreet())
            && filled($billable->getBillingPostalCode())
            && filled($billable->getBillingCity())
            && filled($billable->getBillingCountry());
    }

    // -------------------------------------------------------------------------
    // Computed lookups
    // -------------------------------------------------------------------------

    /** @return array<string, string> */
    public function countries(): array
    {
        return CountryResolver::resolve();
    }

    /**
     * Plans offered in the checkout plan step.
     *
     * Built from the active `SubscriptionCatalogInterface` — never from
     * `config('mollie-billing-plans.plans')` — so an app with a database-backed
     * catalog offers the plan codes it actually sells instead of whatever the
     * config file happens to still contain. `visiblePlans()` is the
     * customer-listing contract, so hidden plans (internal / supplier /
     * grandfathered) are excluded and every downstream check inherits the rule
     * for free: the `?plan=` pre-selection in mount(), the `in:` rule in
     * validateStep2() and the re-validation in submit() all read this list.
     *
     * The array shape mirrors the config layout the step views consume
     * (`name`, `included_seats`, `allowed_addons`, `intervals.*`), so the cards
     * stay unchanged while the data source moves behind the catalog.
     *
     * @return array<string, array<string, mixed>>
     */
    public function plans(): array
    {
        $catalog = app(SubscriptionCatalogInterface::class);
        $allAddons = $catalog->allAddons();
        $plans = [];

        foreach ($catalog->visiblePlans() as $code) {
            $code = (string) $code;

            $plans[$code] = [
                'name' => $catalog->planName($code) ?? $code,
                'description' => $catalog->planDescription($code),
                'included_seats' => $catalog->includedSeats($code),
                'allowed_addons' => array_values(array_filter(
                    $allAddons,
                    fn (string $addonCode): bool => $catalog->planAllowsAddon($code, $addonCode),
                )),
                'intervals' => [
                    'monthly' => [
                        'base_price_net' => $catalog->basePriceNet($code, 'monthly'),
                        'seat_price_net' => $catalog->seatPriceNet($code, 'monthly'),
                    ],
                    'yearly' => [
                        'base_price_net' => $catalog->basePriceNet($code, 'yearly'),
                        'seat_price_net' => $catalog->seatPriceNet($code, 'yearly'),
                    ],
                ],
            ];
        }

        return $plans;
    }

    /**
     * Addons available in the checkout, keyed by code.
     *
     * Catalog-driven for the same reason as plans(): `plans()` derives
     * `allowed_addons` from `$catalog->allAddons()`, so the price lookups in
     * subtotalNet() and the addon step must resolve through the catalog too —
     * otherwise a catalog addon missing from the config file would be offered
     * without a price and undercharge the displayed total.
     *
     * @return array<string, array<string, mixed>>
     */
    public function addons(): array
    {
        $catalog = app(SubscriptionCatalogInterface::class);
        $addons = [];

        foreach ($catalog->allAddons() as $code) {
            $code = (string) $code;

            $addons[$code] = [
                'name' => $catalog->addonName($code) ?? $code,
                'description' => $catalog->addonDescription($code),
                'intervals' => [
                    'monthly' => ['price_net' => $catalog->addonPriceNet($code, 'monthly')],
                    'yearly' => ['price_net' => $catalog->addonPriceNet($code, 'yearly')],
                ],
            ];
        }

        return $addons;
    }

    /** @return array<int, array{key: string, name: string, description: ?string}> */
    public function planFeatures(string $planCode): array
    {
        $catalog = app(SubscriptionCatalogInterface::class);

        return array_map(
            fn (string $key): array => [
                'key' => $key,
                'name' => $catalog->featureName($key),
                'description' => $catalog->featureDescription($key),
            ],
            $catalog->planFeatures($planCode),
        );
    }

    public function planTrialDays(string $planCode, string $interval): int
    {
        return app(SubscriptionCatalogInterface::class)->trialDays($planCode, $interval);
    }

    /**
     * Whether the trial would actually fire on submit. Mirrors the gate in
     * `StartSubscriptionCheckout::handle()`: a positive interval-level
     * `trial_days` plus a billable that has not yet captured a Mollie mandate.
     * Returning customers (mandate already on file) re-enter checkout at full
     * price — the badge must not promise a trial that won't be granted.
     *
     * Uses the persisted ($billableId, $billableClass) pair on the component
     * with a fallback to the request-scoped resolver, so the check works both
     * before the billable is created (resolver only) and after (component
     * properties carry the id across Livewire updates).
     */
    public function trialApplies(string $planCode, string $interval): bool
    {
        if ($this->planTrialDays($planCode, $interval) <= 0) {
            return false;
        }

        $billable = $this->resolveBillable() ?? MollieBilling::resolveBillable(request());

        return $billable === null || ! $billable->hasMollieMandate();
    }

    /** @return array<string, array{label: string, included: int, overage_price: int|null}> */
    public function planUsages(string $planCode, string $interval): array
    {
        $catalog = app(SubscriptionCatalogInterface::class);
        $usages = $catalog->includedUsages($planCode, $interval);
        $result = [];

        foreach ($usages as $type => $included) {
            $result[$type] = [
                'label' => $catalog->usageTypeName($type),
                'included' => $included,
                'overage_price' => $catalog->usageOveragePrice($planCode, $interval, $type),
            ];
        }

        return $result;
    }

    /** @return array<string, array{name: string, description: string|null, price_net: int}> */
    public function planAddons(string $planCode, string $interval): array
    {
        $plan = $this->plans()[$planCode] ?? [];
        $allowedAddons = $plan['allowed_addons'] ?? [];
        $allAddons = $this->addons();
        $catalog = app(SubscriptionCatalogInterface::class);
        $result = [];

        foreach ($allowedAddons as $addonCode) {
            $addon = $allAddons[$addonCode] ?? null;
            if ($addon === null) {
                continue;
            }
            $result[$addonCode] = [
                'name' => $catalog->addonName($addonCode) ?? $addonCode,
                'description' => $addon['description'] ?? null,
                'price_net' => $catalog->addonPriceNet($addonCode, $interval),
            ];
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    public function selectedPlan(): ?array
    {
        return $this->plans()[$this->plan_code] ?? null;
    }

    public function hasAddonsOrSeatsStep(): bool
    {
        $plan = $this->selectedPlan();
        if ($plan === null) {
            return false;
        }

        $hasAllowedAddons = ! empty($plan['allowed_addons'] ?? []);
        $hasSeatPrice = ($plan['intervals'][$this->interval]['seat_price_net'] ?? null) !== null;

        return $hasAllowedAddons || $hasSeatPrice;
    }

    // -------------------------------------------------------------------------
    // Custom checkout steps (resolved fresh each call — closures can't be serialised by Livewire)
    // -------------------------------------------------------------------------

    /** @return array<int, array{key:string, label:string, headline:string, description:string, view:string, validate?:\Closure}> */
    private function customSteps(): array
    {
        return MollieBilling::resolveCheckoutSteps();
    }

    public function customStepCount(): int
    {
        return count($this->customSteps());
    }

    /**
     * The internal package step number (1 = billing, 2 = plan, 3 = addons, 4 = confirm)
     * relative to the current $this->step which includes the custom step offset.
     */
    private function packageStep(): int
    {
        return $this->step - $this->customStepCount();
    }

    // -------------------------------------------------------------------------
    // Step numbering
    // -------------------------------------------------------------------------

    public function totalSteps(): int
    {
        $base = $this->hasAddonsOrSeatsStep() ? 4 : 3;

        if ($this->billing_locked) {
            $base--;
        }

        return $this->customStepCount() + $base;
    }

    public function displayStep(): int
    {
        $offset = $this->customStepCount();
        $step = $this->step;

        // When addons step is skipped, the confirm step is still stored as offset+4 internally
        if (! $this->hasAddonsOrSeatsStep() && $step === $offset + 4) {
            $step = $offset + 3;
        }

        // When billing step is locked it does not appear in the timeline, so
        // every package step shifts down by one.
        if ($this->billing_locked && $step > $offset) {
            $step--;
        }

        return $step;
    }

    public function stepHeadline(): string
    {
        $offset = $this->customStepCount();

        if ($this->step <= $offset) {
            return $this->customSteps()[$this->step - 1]['headline'] ?? '';
        }

        return match ($this->step - $offset) {
            1 => __('billing::checkout.headline_billing'),
            2 => __('billing::checkout.headline_plan'),
            3 => __('billing::checkout.headline_addons'),
            4 => __('billing::checkout.headline_confirm'),
            default => '',
        };
    }

    public function stepDescription(): string
    {
        $offset = $this->customStepCount();

        if ($this->step <= $offset) {
            return $this->customSteps()[$this->step - 1]['description'] ?? '';
        }

        return match ($this->step - $offset) {
            1 => __('billing::checkout.description_billing'),
            2 => __('billing::checkout.description_plan'),
            3 => __('billing::checkout.description_addons'),
            4 => __('billing::checkout.description_confirm'),
            default => '',
        };
    }

    /** @return list<array{key:int,label:string}> */
    public function timelineSteps(): array
    {
        $steps = [];
        $key = 0;

        // Custom steps first
        foreach ($this->customSteps() as $customStep) {
            $steps[] = ['key' => ++$key, 'label' => $customStep['label']];
        }

        // Package steps — the billing step is hidden when the data is locked
        // (a pre-existing billable already carries persisted billing data).
        if (! $this->billing_locked) {
            $steps[] = ['key' => ++$key, 'label' => __('billing::checkout.step_billing_details')];
        }
        $steps[] = ['key' => ++$key, 'label' => __('billing::checkout.step_plan')];

        if ($this->hasAddonsOrSeatsStep()) {
            $steps[] = ['key' => ++$key, 'label' => __('billing::checkout.step_addons')];
            $steps[] = ['key' => ++$key, 'label' => __('billing::checkout.step_confirm')];
        } else {
            $steps[] = ['key' => ++$key, 'label' => __('billing::checkout.step_confirm')];
        }

        return $steps;
    }

    public function timelineStatus(int $stepKey): string
    {
        $currentDisplay = $this->displayStep();

        return match (true) {
            $stepKey < $currentDisplay => 'complete',
            $stepKey === $currentDisplay => 'current',
            default => 'incomplete',
        };
    }

    // -------------------------------------------------------------------------
    // Pricing
    // -------------------------------------------------------------------------

    public function subtotalNet(): int
    {
        $plan = $this->selectedPlan();
        if ($plan === null) {
            return 0;
        }

        $net = (int) ($plan['intervals'][$this->interval]['base_price_net'] ?? 0);
        $seatPrice = (int) ($plan['intervals'][$this->interval]['seat_price_net'] ?? 0);
        $net += $seatPrice * $this->extra_seats;

        $addons = $this->addons();
        foreach ($this->addon_codes as $code) {
            if (! isset($addons[$code])) {
                continue;
            }
            $net += (int) ($addons[$code]['intervals'][$this->interval]['price_net'] ?? 0);
        }

        return $net;
    }

    public function totalNet(): int
    {
        $net = $this->subtotalNet();
        $discount = (int) ($this->applied_coupon['discount_net'] ?? 0);

        return max(0, $net - $discount);
    }

    /**
     * Net amount per invoice line, in the order the first payment will be booked.
     *
     * Mirrors `SubscriptionAmount::lineItems()` plus the negative coupon line
     * `FirstPaymentArtifacts::couponLineItems()` appends, because that is what the
     * webhook writes onto the invoice for this very payment. VAT is rounded per line
     * there, so the total has to be assembled the same way — see
     * {@see BillingMoney::grossWithLineVat()}.
     *
     * @return list<int>
     */
    public function lineNets(): array
    {
        $plan = $this->selectedPlan();
        if ($plan === null) {
            return [];
        }

        $lines = [(int) ($plan['intervals'][$this->interval]['base_price_net'] ?? 0)];

        $seatPrice = (int) ($plan['intervals'][$this->interval]['seat_price_net'] ?? 0);
        if ($this->extra_seats > 0 && $seatPrice > 0) {
            $lines[] = $seatPrice * $this->extra_seats;
        }

        $addons = $this->addons();
        foreach ($this->addon_codes as $code) {
            if (! isset($addons[$code])) {
                continue;
            }
            $lines[] = (int) ($addons[$code]['intervals'][$this->interval]['price_net'] ?? 0);
        }

        // Capped at the subtotal: a discount larger than the order would otherwise
        // push the assembled gross below zero, while totalNet() clamps at 0.
        $discount = min($this->subtotalNet(), (int) ($this->applied_coupon['discount_net'] ?? 0));
        if ($discount > 0) {
            $lines[] = -$discount;
        }

        return $lines;
    }

    /**
     * Tax country the payment is priced from.
     *
     * With `billing_locked` the address belongs to a pre-existing billable: it is
     * rendered read-only and `submit()` deliberately never writes it back. The
     * property stays `wire:model`-bound for the unlocked case though, and
     * `totals()` feeds `amount_gross` straight into the Mollie payment — so a
     * locked checkout that priced from the form value could be re-pointed at a
     * 0%-VAT country and pay net while the invoice books the persisted country's
     * gross.
     */
    private function taxCountry(): string
    {
        if (! $this->billing_locked) {
            return $this->billing_country;
        }

        $persisted = (string) ($this->resolveBillable()?->getBillingCountry() ?? '');

        return $persisted !== '' ? $persisted : $this->billing_country;
    }

    /** The VAT number reverse charge is decided by — the persisted one while locked. See {@see taxCountry()}. */
    private function taxVatNumber(): ?string
    {
        if (! $this->billing_locked) {
            return $this->vat_number;
        }

        $billable = $this->resolveBillable();

        return $billable instanceof Model ? ($billable->vat_number ?? null) : $this->vat_number;
    }

    /**
     * Reverse charge — net prices, no VAT — requires BOTH a VAT number in the form
     * and a server-side VIES confirmation for it. The `filled()` half is not
     * cosmetic: without it a blank VAT number combined with a stale `true` flag
     * would zero out the VAT while the VIES block in `validateStep1()` is skipped.
     */
    public function showsNetPrices(): bool
    {
        if ($this->vatNumberValid !== true || ! filled($this->taxVatNumber())) {
            return false;
        }

        // Cannot delegate wholesale to reverseChargeApplies(): it reads the *persisted*
        // validation, and during checkout the number being entered is not saved until
        // submit() — so a legitimate B2B customer would be shown and charged gross.
        // The seller-country policy is asked of the service rather than reimplemented:
        // a local copy of it read a malformed `vat.seller_country` (`AUT`, `Austria`) as
        // "no seller country" and granted reverse charge, while the service refuses it —
        // net displayed and collected against an invoice that owes VAT.
        $vatService = app(VatCalculationService::class);
        $seller = $vatService->sellerCountry();

        if ($seller === null) {
            return $vatService->reverseChargePolicyWithoutSellerCountry();
        }

        // A valid VAT number is not enough — a domestic B2B supply carries domestic VAT.
        return ! CountryResolver::sameCountry($this->taxCountry(), $seller);
    }

    public function vatRate(VatCalculationService $vat): float
    {
        try {
            return $vat->vatRateFor($this->taxCountry());
        } catch (\Throwable) {
            return 0.0;
        }
    }

    public function displayAmount(int $netCents, VatCalculationService $vat): int
    {
        if ($this->showsNetPrices() || $netCents === 0) {
            return $netCents;
        }

        $rate = $this->vatRate($vat);

        return $netCents + (int) round($netCents * $rate / 100);
    }

    /** @return array{net:int,vat:int,gross:int,rate:float,reverseCharge:bool} */
    public function totals(VatCalculationService $vat): array
    {
        $net = $this->totalNet();
        $reverseCharge = $this->showsNetPrices();

        // Reuse the VIES result captured in vatNumberValid — never re-hit VIES on render.
        // Otherwise a flaky VIES call could disagree with what the UI just rendered, producing
        // a "Reverse-Charge" label next to a gross-with-VAT total. The flag is #[Locked] and
        // only ever written by validateVatNumberLive(), so this is server state, not input;
        // `submit()` re-runs validateStep1() before pricing, which refreshes it.
        if ($reverseCharge) {
            return [
                'net' => $net,
                'vat' => 0,
                'gross' => $net,
                'rate' => 0.0,
                'reverseCharge' => true,
            ];
        }

        try {
            $rate = (float) $vat->calculate($this->taxCountry(), $net, null)['rate'];
        } catch (\Throwable) {
            $rate = 0.0;
        }

        // VAT per invoice line, not over the summed net: the webhook books this
        // payment line by line and the invoice header sums the per-line VAT
        // (InvoiceService::createForPayment), so `round(Σnet × rate)` shows — and
        // collects, via `amount_gross` in submit() — a different cent than the
        // document the customer receives. See BillingMoney::grossWithLineVat().
        $gross = BillingMoney::grossWithLineVat($this->lineNets(), $rate);

        return [
            'net' => $net,
            'vat' => $gross - $net,
            'gross' => $gross,
            'rate' => $rate,
            'reverseCharge' => false,
        ];
    }

    /** @return array{vatRate:float,showsNet:bool,priceFormatter:\Closure,money:\Closure,currencySymbol:string,totals:array,customStepCount:int,customStepViews:array} */
    public function with(VatCalculationService $vat): array
    {
        $rate = $this->vatRate($vat);
        $showsNet = $this->showsNetPrices();
        $customSteps = $this->customSteps();

        return [
            'vatRate' => $rate,
            'showsNet' => $showsNet,
            'priceFormatter' => function (int $netCents) use ($rate, $showsNet): int {
                if ($showsNet || $netCents === 0) {
                    return $netCents;
                }

                return $netCents + (int) round($netCents * $rate / 100);
            },
            // Handed down to the @include'd step partials, which are compiled as
            // separate views and therefore cannot see this file's imports. Single
            // money-rendering strategy for the whole checkout; `currencySymbol` is
            // only for the lang strings that place the symbol themselves.
            'money' => fn (?int $cents): string => BillingMoney::format($cents),
            'moneyNumber' => fn (?int $cents): string => BillingMoney::numberFromMinor($cents),
            'currencySymbol' => BillingMoney::symbol(),
            'totals' => $this->totals($vat),
            'customStepCount' => count($customSteps),
            'customStepViews' => array_map(fn (array $s): string => $s['view'], $customSteps),
        ];
    }

    // -------------------------------------------------------------------------
    // VAT number validation
    // -------------------------------------------------------------------------

    public function updatedVatNumber(VatCalculationService $vat): void
    {
        $this->validateVatNumberLive($vat);
    }

    public function updatedBillingCountry(VatCalculationService $vat): void
    {
        if (filled($this->vat_number)) {
            $this->validateVatNumberLive($vat);
        }
    }

    /**
     * Both directions of the (plan, interval) pair have to stay on a pair the
     * catalog actually prices. `interval` is a live-bound radio group and
     * `plan_code` is set through `$set()`, so either can move independently — and
     * an unpriced pair costs 0, which the zero-amount branch of
     * StartSubscriptionCheckout used to activate as a free plan.
     */
    public function updatedInterval(): void
    {
        $this->interval = $this->firstOfferedInterval($this->plan_code, $this->interval);
        $this->refreshCouponAfterSelectionChange();
    }

    public function updatedPlanCode(): void
    {
        $this->interval = $this->firstOfferedInterval($this->plan_code, $this->interval);
        $this->refreshCouponAfterSelectionChange();
    }

    /**
     * A coupon validated against the previous selection may no longer fit it.
     * `refreshAppliedCoupon()` clears it and names the reason; surface that as a
     * banner rather than silently dropping the discount the customer can see.
     */
    private function refreshCouponAfterSelectionChange(): void
    {
        if ($this->applied_coupon === null) {
            return;
        }

        $reason = $this->refreshAppliedCoupon();

        if ($reason !== null) {
            $this->couponError = $this->couponErrorMessage($reason);
        }
    }

    // -------------------------------------------------------------------------
    // Coupon handling
    // -------------------------------------------------------------------------

    public function applyCoupon(): void
    {
        $this->couponError = null;

        $code = strtoupper(trim($this->coupon_input));
        if ($code === '') {
            $this->couponError = __('billing::checkout.coupon_empty');

            return;
        }

        if ($this->applied_coupon !== null) {
            $this->couponError = __('billing::checkout.coupon_already_applied');

            return;
        }

        try {
            $coupon = MollieBilling::coupons()->validateWithoutBillable($code, [
                'planCode' => $this->plan_code,
                'interval' => $this->interval,
                'addonCodes' => $this->addon_codes,
                'extraSeats' => $this->extra_seats,
                'orderAmountNet' => $this->subtotalNet(),
                'allowed_types' => [
                    \GraystackIT\MollieBilling\Enums\CouponType::SinglePayment,
                    \GraystackIT\MollieBilling\Enums\CouponType::Recurring,
                    \GraystackIT\MollieBilling\Enums\CouponType::TrialExtension,
                    \GraystackIT\MollieBilling\Enums\CouponType::AccessGrant,
                ],
            ]);
        } catch (InvalidCouponException $e) {
            $this->couponError = $this->couponErrorMessage($e->reason());

            return;
        } catch (\Throwable) {
            $this->couponError = __('billing::checkout.coupon_failed');

            return;
        }

        // The service can only ask whether the PLAN offers a trial; whether one will
        // actually start also depends on this customer — a returning subscriber with
        // a mandate on file checks out at full price, gets no trial, and a trial
        // extension applied to that has nothing to extend.
        if ($this->couponTrialDays($coupon) > 0 && ! $this->trialApplies($this->plan_code, $this->interval)) {
            $this->couponError = __('billing::checkout.coupon_requires_trial');

            return;
        }

        $this->applied_coupon = [
            'code' => (string) $coupon->code,
            'name' => (string) $coupon->name,
            'discount_net' => $this->calculateCouponDiscount($coupon),
            'trial_days' => $this->couponTrialDays($coupon),
        ];
        $this->coupon_input = '';
    }

    public function removeCoupon(): void
    {
        $this->applied_coupon = null;
        $this->couponError = null;
    }

    /**
     * Re-validate the currently applied coupon against the live form state
     * (plan, interval, addons, extra seats). Returns null on success, or a
     * reason string when the coupon no longer fits — in which case the
     * applied_coupon is cleared. The caller decides whether to abort the
     * current action (e.g. submit) or just surface a banner warning
     * (e.g. on step navigation).
     */
    private function refreshAppliedCoupon(): ?string
    {
        if ($this->applied_coupon === null) {
            return null;
        }

        try {
            $coupon = MollieBilling::coupons()->validateWithoutBillable($this->applied_coupon['code'], [
                'planCode' => $this->plan_code,
                'interval' => $this->interval,
                'addonCodes' => $this->addon_codes,
                'extraSeats' => $this->extra_seats,
                'orderAmountNet' => $this->subtotalNet(),
                'allowed_types' => [
                    \GraystackIT\MollieBilling\Enums\CouponType::SinglePayment,
                    \GraystackIT\MollieBilling\Enums\CouponType::Recurring,
                    \GraystackIT\MollieBilling\Enums\CouponType::TrialExtension,
                    \GraystackIT\MollieBilling\Enums\CouponType::AccessGrant,
                ],
            ]);
        } catch (InvalidCouponException $e) {
            $this->applied_coupon = null;

            return $e->reason();
        } catch (\Throwable) {
            $this->applied_coupon = null;

            return 'failed';
        }

        // Same customer-level check as applyCoupon(): switching to a plan whose trial
        // this billable can no longer get must drop the extension, not carry it to a
        // submit where it would be applied to a paid period.
        if ($this->couponTrialDays($coupon) > 0 && ! $this->trialApplies($this->plan_code, $this->interval)) {
            $this->applied_coupon = null;

            return 'requires_trial';
        }

        $this->applied_coupon['discount_net'] = $this->calculateCouponDiscount($coupon);
        $this->applied_coupon['trial_days'] = $this->couponTrialDays($coupon);

        return null;
    }

    /**
     * What a TrialExtension coupon buys. It carries no money discount, so without
     * this the summary would advertise it as "−0.00" — a coupon that reads as worth
     * nothing while it is in fact worth days.
     */
    private function couponTrialDays(Coupon $coupon): int
    {
        return $coupon->type === \GraystackIT\MollieBilling\Enums\CouponType::TrialExtension
            ? max(0, (int) ($coupon->trial_extension_days ?? 0))
            : 0;
    }

    private function calculateCouponDiscount(Coupon $coupon): int
    {
        if ($coupon->type === \GraystackIT\MollieBilling\Enums\CouponType::AccessGrant) {
            return $this->calculateAccessGrantDiscount($coupon);
        }

        return MollieBilling::coupons()->computeRecurringDiscount($coupon, $this->subtotalNet());
    }

    private function calculateAccessGrantDiscount(Coupon $coupon): int
    {
        $plan = $this->selectedPlan();
        if ($plan === null) {
            return 0;
        }

        $discount = 0;
        $hasFullPlan = ! empty($coupon->grant_plan_code);

        if ($hasFullPlan) {
            $matchesPlan = $coupon->grant_plan_code === $this->plan_code;
            $matchesInterval = $coupon->grant_interval === null
                || $coupon->grant_interval === $this->interval;

            if ($matchesPlan && $matchesInterval) {
                $discount += (int) ($plan['intervals'][$this->interval]['base_price_net'] ?? 0);
            }
        }

        $grantedAddons = (array) ($coupon->grant_addon_codes ?? []);
        foreach ($this->addon_codes as $addonCode) {
            if (! in_array($addonCode, $grantedAddons, true)) {
                continue;
            }
            $discount += (int) ($this->addons()[$addonCode]['intervals'][$this->interval]['price_net'] ?? 0);
        }

        return min($discount, $this->subtotalNet());
    }

    private function couponErrorMessage(string $reason): string
    {
        return match ($reason) {
            'not_found' => __('billing::checkout.coupon_not_found'),
            'inactive' => __('billing::checkout.coupon_inactive'),
            'not_yet_valid' => __('billing::checkout.coupon_not_yet_valid'),
            'expired' => __('billing::checkout.coupon_expired'),
            'globally_exhausted' => __('billing::checkout.coupon_exhausted'),
            'plan_not_applicable' => __('billing::checkout.coupon_plan_mismatch'),
            'interval_not_applicable' => __('billing::checkout.coupon_interval_mismatch'),
            'addon_not_applicable' => __('billing::checkout.coupon_addon_mismatch'),
            'product_not_applicable' => __('billing::checkout.coupon_product_mismatch'),
            'min_order_not_met' => __('billing::checkout.coupon_min_order'),
            'requires_billable' => __('billing::checkout.coupon_requires_billable'),
            'requires_trial' => __('billing::checkout.coupon_requires_trial'),
            'recurring_conflict' => __('billing::checkout.coupon_recurring_conflict'),
            'requires_active_subscription' => __('billing::checkout.coupon_requires_active_subscription'),
            'too_close_to_charge' => __('billing::checkout.coupon_too_close_to_charge'),
            'per_billable_limit_reached' => __('billing::checkout.coupon_per_billable_limit_reached'),
            'full_coverage_use_access_grant' => __('billing::checkout.coupon_full_coverage_use_access_grant'),
            'recurring_already_active' => __('billing::checkout.coupon_recurring_already_active'),
            'type_not_allowed_in_context' => __('billing::checkout.coupon_type_not_allowed_in_context'),
            'grant_plan_mismatch' => __('billing::checkout.coupon_grant_plan_mismatch'),
            'grant_interval_mismatch' => __('billing::checkout.coupon_grant_interval_mismatch'),
            'grant_addons_exceeded' => __('billing::checkout.coupon_grant_addons_exceeded'),
            'grant_seats_not_supported' => __('billing::checkout.coupon_grant_seats_not_supported'),
            default => __('billing::checkout.coupon_failed'),
        };
    }

    // -------------------------------------------------------------------------
    // Step navigation
    // -------------------------------------------------------------------------

    public function next(): void
    {
        $offset = $this->customStepCount();
        $pkg = $this->packageStep();

        // Validate the current step
        if ($this->step <= $offset) {
            $this->validateCustomStep($this->step);
        } else {
            match ($pkg) {
                1 => $this->validateStep1(),
                2 => $this->validateStep2(),
                3 => $this->validateStep3(),
                default => null,
            };
        }

        // Skip addons step if plan doesn't need it
        if ($pkg === 2 && ! $this->hasAddonsOrSeatsStep()) {
            $this->step = $offset + 4;
            $this->revalidateCouponOnEnterConfirm();

            return;
        }

        $this->step++;

        // After advancing into the confirm step, re-validate any applied coupon
        // against the form state the user is about to submit. If it no longer
        // fits (plan/addons/seats changed earlier), drop it and surface the
        // reason — the submit() check is the hard guard, this is the soft
        // heads-up so the user sees the price change before clicking submit.
        if ($this->packageStep() === 4) {
            $this->revalidateCouponOnEnterConfirm();
        }
    }

    private function revalidateCouponOnEnterConfirm(): void
    {
        $reason = $this->refreshAppliedCoupon();
        if ($reason !== null) {
            $this->couponError = $this->couponErrorMessage($reason);
        }
    }

    public function back(): void
    {
        $offset = $this->customStepCount();
        $pkg = $this->packageStep();

        // Skip addons step backwards
        if ($pkg === 4 && ! $this->hasAddonsOrSeatsStep()) {
            $this->step = $offset + 2;

            return;
        }

        // Skip the locked billing step backwards: from the plan step jump
        // straight to the last custom step when one exists, otherwise stay
        // on the plan step (there is nothing earlier to navigate to).
        if ($this->billing_locked && $pkg === 2) {
            $this->step = $offset > 0 ? $offset : $this->step;

            return;
        }

        $this->step = max(1, $this->step - 1);
    }

    private function validateCustomStep(int $step): void
    {
        $customStep = $this->customSteps()[$step - 1] ?? null;

        if ($customStep !== null && isset($customStep['validate'])) {
            ($customStep['validate'])($this);
        }
    }

    private function validateStep1(): void
    {
        $validCountries = array_keys($this->countries());

        $this->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'billing_street' => ['required', 'string', 'max:255'],
            'billing_postal_code' => ['required', 'string', 'max:20'],
            'billing_city' => ['required', 'string', 'max:255'],
            'billing_country' => ['required', 'string', 'in:' . implode(',', $validCountries)],
            'vat_number' => ['nullable', 'string', 'max:50'],
        ]);

        if (filled($this->vat_number)) {
            try {
                $this->validateVatNumberLive(app(VatCalculationService::class));
            } catch (\Throwable) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'vat_number' => __('billing::checkout.vies_unavailable'),
                ]);
            }

            // Hard gate: every persisted VAT number must be VIES-confirmed valid.
            // Pending state (null) or invalid state both block progression so the
            // billing data we ultimately store never relies on an unverified
            // VAT number.
            if ($this->vatNumberValid !== true) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'vat_number' => __('billing::checkout.vat_correct_or_clear'),
                ]);
            }
        }
    }

    private function validateStep2(): void
    {
        $plans = $this->plans();
        $this->validate([
            'plan_code' => ['required', 'string', 'in:' . implode(',', array_keys($plans))],
            'interval' => ['required', 'in:monthly,yearly'],
        ]);

        // `in:monthly,yearly` says the value is a known interval, not that this
        // plan is sold in it. A plan may offer only one — and an interval it does
        // not offer has no price, which every pricing path below reads as free.
        // Unchecked, picking "Yearly" on a monthly-only paid plan activated that
        // paid plan for 0 €.
        if (! app(SubscriptionCatalogInterface::class)->planOffersInterval($this->plan_code, $this->interval)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'interval' => __('billing::checkout.interval_not_offered'),
            ]);
        }
    }

    private function validateStep3(): void
    {
        $plan = $this->selectedPlan();
        $allowed = (array) ($plan['allowed_addons'] ?? []);

        $this->validate([
            'addon_codes' => ['array'],
            'addon_codes.*' => ['string', 'in:' . ($allowed === [] ? 'none' : implode(',', $allowed))],
            'extra_seats' => ['integer', 'min:0', 'max:100'],
        ]);
    }

    // -------------------------------------------------------------------------
    // Billable creation
    // -------------------------------------------------------------------------

    private function createBillable(): void
    {
        $billable = MollieBilling::createBillable([
            'name' => $this->company_name,
            'email' => '', // App fills this via the callback
            'billing_street' => $this->billing_street,
            'billing_postal_code' => $this->billing_postal_code,
            'billing_city' => $this->billing_city,
            'billing_country' => $this->billing_country,
            'tax_country_user' => $this->billing_country,
            'tax_country_ip' => $this->resolveCurrentIpCountry(),
            'vat_number' => $this->vat_number,
            'custom' => $this->customData,
        ]);

        /** @var \Illuminate\Database\Eloquent\Model&Billable $billable */
        $this->billableId = (string) $billable->getKey();
        $this->billableClass = $billable->getMorphClass();
        $this->billableCreatedHere = true;

        event(new CheckoutStarted($billable));
    }

    private function resolveCurrentIpCountry(): ?string
    {
        $ip = (string) (request()->ip() ?? '');
        if ($ip === '') {
            return null;
        }
        try {
            $country = MollieBilling::ipGeolocation()->getCountry($ip);
        } catch (\Throwable) {
            return null;
        }

        return is_string($country) && $country !== '' ? strtoupper($country) : null;
    }

    private function resolveBillable(): ?Billable
    {
        if ($this->billableId === null || $this->billableClass === null) {
            return null;
        }

        // `billableClass` holds a morph class, which is the morph-map alias when the
        // app registered one — resolve that back to the model class first. The
        // is_a() gate keeps the lookup inside Billable models even if the property
        // is ever reached by something other than createBillable()/mount().
        $class = Relation::getMorphedModel($this->billableClass) ?? $this->billableClass;

        if (! is_string($class) || ! is_a($class, Model::class, true) || ! is_a($class, Billable::class, true)) {
            return null;
        }

        /** @var \Illuminate\Database\Eloquent\Model $model */
        $model = app($class);

        $found = $model->newQuery()->find($this->billableId);

        return $found instanceof Billable ? $found : null;
    }

    /** Whether a persisted, VIES-confirmed validation exists for the billable's current VAT number. */
    private function billableUsesReverseCharge(Billable $billable): bool
    {
        return method_exists($billable, 'usesReverseCharge') && $billable->usesReverseCharge();
    }

    // -------------------------------------------------------------------------
    // Final submit
    // -------------------------------------------------------------------------

    public function submit(StartSubscriptionCheckout $checkout, VatCalculationService $vat): mixed
    {
        $this->errorMessage = null;

        // Validation runs BEFORE setting processing=true. ValidationException is
        // re-thrown so Livewire can render field errors; processing stays false
        // so the submit button doesn't lock up on a recoverable input error.
        $this->validateStep1();
        $this->validateStep2();
        if ($this->hasAddonsOrSeatsStep()) {
            $this->validateStep3();
        }

        // Authorize before anything mutates, and outside the try below so the 403
        // is not swallowed by its catchall. Livewire actions bypass the checkout
        // route's middleware, and from here on we overwrite the billable's billing
        // address, write a VIES audit row for it and open a Mollie payment on its
        // customer. Anonymous signup is the one exemption: a billable this very
        // component instance created has no owner to authorize against yet.
        if (! $this->billableCreatedHere) {
            $existing = $this->resolveBillable();

            if ($existing !== null) {
                abort_unless(MollieBilling::authorizes(request(), $existing), 403);
            }
        }

        $this->processing = true;

        try {
            if ($this->billableId === null) {
                try {
                    $this->createBillable();
                } catch (\Throwable $e) {
                    report($e);
                    $this->errorMessage = __('billing::checkout.error_billable_creation');

                    return null;
                }
            }

            $billable = $this->resolveBillable();
            if ($billable === null) {
                $this->errorMessage = __('billing::checkout.error_no_billable');

                return null;
            }
            /** @var \Illuminate\Database\Eloquent\Model&Billable $billable */
            if ($billable->hasAccessibleBillingSubscription()) {
                return $this->redirect(BillingRoute::url('index', $billable), navigate: false);
            }

            // Update billing address on the billable (may have changed since step 1).
            // When the billing data is locked it was loaded from the existing
            // billable in mount() and is shown read-only — skip the write.
            if (! $this->billing_locked) {
                /** @var \Illuminate\Database\Eloquent\Model&Billable $billable */
                // The company/billing name is routed through setBillingName() so
                // apps that use a User as the billable can persist it into a
                // dedicated attribute (e.g. `practice_name`) without colliding
                // with the user's personal `name` column.
                $billable->setBillingName($this->company_name);
                $billable->forceFill([
                    'billing_street' => $this->billing_street,
                    'billing_postal_code' => $this->billing_postal_code,
                    'billing_city' => $this->billing_city,
                    'billing_country' => $this->billing_country,
                    'tax_country_user' => $this->billing_country,
                    'tax_country_ip' => $this->resolveCurrentIpCountry(),
                    'vat_number' => $this->vat_number,
                ])->save();
            }

            // Persist the VIES audit-trail entry that any subsequent invoice will
            // reference. We check `currentVatValidation()` (not just "vat_number
            // changed") because the billable may have been created above with the
            // VAT number already set — in that case there is no prior persisted
            // value to diff against, but there is also no audit entry yet.
            if (filled($this->vat_number) && $billable->currentVatValidation() === null) {
                try {
                    app(VatCalculationService::class)->validateAndPersist($billable, (string) $this->vat_number);
                } catch (\GraystackIT\MollieBilling\Exceptions\ViesUnavailableException) {
                    $this->errorMessage = __('billing::checkout.vies_unavailable');

                    return null;
                }
            }

            // Run before-checkout hook
            $blockReason = MollieBilling::runBeforeCheckout($billable);
            if ($blockReason !== null) {
                $this->errorMessage = $blockReason;

                return null;
            }

            // Final safety check: re-validate the applied coupon against the
            // live form state. If the user navigated back, changed plan / addons
            // / seats and returned to confirm, the coupon may no longer fit —
            // refuse to submit instead of silently dropping it and charging
            // (or activating) something different than what the user sees.
            $couponDropReason = $this->refreshAppliedCoupon();
            if ($couponDropReason !== null) {
                $this->couponError = $this->couponErrorMessage($couponDropReason);
                $this->errorMessage = __('billing::checkout.coupon_dropped_revisit');

                return null;
            }

            try {
                $result = $checkout->handle($billable, [
                    'plan_code' => $this->plan_code,
                    'interval' => $this->interval,
                    'addon_codes' => $this->addon_codes,
                    'extra_seats' => $this->extra_seats,
                    'coupon_code' => $this->applied_coupon['code'] ?? null,
                    'amount_gross' => $this->totals($vat)['gross'],
                ]);
            } catch (\Throwable $e) {
                report($e);
                try {
                    MollieBilling::runAfterCheckout($billable, false);
                } catch (\Throwable $hookError) {
                    report($hookError);
                }
                $this->errorMessage = __('billing::checkout.error_payment_creation');

                return null;
            }

            if (! empty($result['payment_id'])) {
                $billable->recordPendingFirstPayment((string) $result['payment_id']);
            }

            if (! empty($result['checkout_url'])) {
                return $this->redirect($result['checkout_url'], navigate: false);
            }

            // Zero-amount / local subscription — no Mollie redirect needed
            try {
                MollieBilling::runAfterCheckout($billable, true);
            } catch (\Throwable $hookError) {
                report($hookError);
            }

            // Mirror the post-Mollie return flow: respect the host app's
            // configured post-checkout target (typically the app dashboard),
            // and only fall back to the billing portal when nothing is set.
            $configured = config('mollie-billing.redirect_after_return');
            $params = MollieBilling::resolveUrlParameters($billable);
            $target = $configured
                ? route($configured, $params)
                : BillingRoute::url('index', $billable);

            return $this->redirect($target, navigate: false);
        } catch (\Throwable $e) {
            // Catchall: anything not handled above (unexpected DB/Mollie/network failure)
            // must never leave the UI in a permanently disabled state.
            report($e);
            $this->errorMessage = __('billing::checkout.error_unexpected');

            return null;
        } finally {
            $this->processing = false;
        }
    }
}; ?>

@php
    // The plan step needs the full shell width for the side-by-side cards; the
    // form-shaped steps stay narrow so inputs don't stretch across the viewport.
    $wideStep = $step === $customStepCount + 2;
@endphp

<div class="mx-auto flex w-full flex-col gap-10 {{ $wideStep ? 'max-w-5xl' : 'max-w-3xl' }}">
    {{-- Eyebrow + headline --}}
    <div class="flex flex-col gap-3">
        <div class="flex items-center gap-2 text-xs font-medium uppercase tracking-[0.18em] text-zinc-500 dark:text-zinc-400">
            <span class="inline-flex h-1 w-6 bg-zinc-900 dark:bg-white"></span>
            <span>{{ __('billing::checkout.step_counter', ['current' => $this->displayStep(), 'total' => $this->totalSteps()]) }}</span>
        </div>
        <h1 class="text-balance text-3xl font-semibold tracking-tight text-zinc-900 dark:text-white lg:text-4xl">
            {{ $this->stepHeadline() }}
        </h1>
        <p class="max-w-xl text-sm text-zinc-600 dark:text-zinc-400">
            {{ $this->stepDescription() }}
        </p>
    </div>

    {{-- Error message from hooks --}}
    @if ($errorMessage)
        <flux:callout icon="exclamation-triangle" color="red" inline class="mt-4 mb-4">
            {{ $errorMessage }}
        </flux:callout>
    @endif

    {{-- Horizontal timeline --}}
    <flux:timeline :horizontal="true" align="center" class="rounded-xl border border-zinc-200/80 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/2">
        @foreach ($this->timelineSteps() as $item)
            <flux:timeline.item :status="$this->timelineStatus($item['key'])">
                <flux:timeline.indicator />
                <flux:timeline.content>
                    <div class="text-xs font-medium uppercase tracking-wider">
                        {{ str_pad((string) $item['key'], 2, '0', STR_PAD_LEFT) }}
                    </div>
                    <div class="mt-0.5 text-sm">{{ $item['label'] }}</div>
                </flux:timeline.content>
            </flux:timeline.item>
        @endforeach
    </flux:timeline>

    {{-- Custom steps (injected by the consuming app before the package steps) --}}
    @foreach ($customStepViews as $index => $customStepView)
        @if ($step === $index + 1)
            @include($customStepView)

            <div class="flex items-center justify-between pt-2">
                @if ($index > 0)
                    <flux:button wire:click="back" variant="ghost" icon="arrow-left">{{ __('billing::checkout.back') }}</flux:button>
                @else
                    <div></div>
                @endif
                <flux:button wire:click="next" variant="primary" icon:trailing="arrow-right">
                    {{ __('billing::checkout.continue') }}
                </flux:button>
            </div>
        @endif
    @endforeach

    {{-- Package steps --}}
    @if ($step === $customStepCount + 1)
        @include('mollie-billing::livewire.billing.components.checkout.step-billing')
    @endif

    @if ($step === $customStepCount + 2)
        @include('mollie-billing::livewire.billing.components.checkout.step-plan')
    @endif

    @if ($step === $customStepCount + 3)
        @include('mollie-billing::livewire.billing.components.checkout.step-addons')
    @endif

    @if ($step === $customStepCount + 4)
        @include('mollie-billing::livewire.billing.components.checkout.step-confirm')
    @endif
</div>
