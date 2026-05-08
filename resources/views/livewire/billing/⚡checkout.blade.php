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
use Illuminate\Support\Facades\DB;
use GraystackIT\MollieBilling\Support\Sanitize;
use Livewire\Attributes\Layout;
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

    /** The billable created in the billing-address step (stored as ID + class for Livewire serialisation). */
    public ?string $billableId = null;
    public ?string $billableClass = null;

    /**
     * True when the checkout was entered with a pre-existing billable that already
     * carries persisted billing data (e.g. after a previously cancelled subscription
     * or an access-grant-activated local subscription). The billing-address step is
     * skipped and the data is shown read-only on the confirm step.
     */
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
            $this->company_name = (string) ($existing->name ?? '');
            $this->billing_street = (string) $existing->getBillingStreet();
            $this->billing_postal_code = (string) $existing->getBillingPostalCode();
            $this->billing_city = (string) $existing->getBillingCity();
            $this->billing_country = (string) $existing->getBillingCountry();
            $this->vat_number = $existing->vat_number;
            // VAT number is treated as confirmed when it was previously persisted
            // — the audit trail captured the VIES result at the time it was set.
            if (filled($this->vat_number)) {
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

    /** @return array<string, array<string, mixed>> */
    public function plans(): array
    {
        return config('mollie-billing-plans.plans', []);
    }

    /** @return array<string, array<string, mixed>> */
    public function addons(): array
    {
        return config('mollie-billing-plans.addons', []);
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

    /** @return array<string, array{included: int, overage_price: int|null}> */
    public function planUsages(string $planCode, string $interval): array
    {
        $catalog = app(SubscriptionCatalogInterface::class);
        $usages = $catalog->includedUsages($planCode, $interval);
        $result = [];

        foreach ($usages as $type => $included) {
            $result[$type] = [
                'included' => $included,
                'overage_price' => $catalog->usageOveragePrice($planCode, $interval, $type),
            ];
        }

        return $result;
    }

    /** @return array<string, array{name: string, price_net: int}> */
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

    public function showsNetPrices(): bool
    {
        return $this->vatNumberValid === true;
    }

    public function vatRate(VatCalculationService $vat): float
    {
        try {
            return $vat->vatRateFor($this->billing_country);
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

        // Trust the live VIES result captured in vatNumberValid — never re-hit VIES on render.
        // Otherwise a flaky VIES call could disagree with what the UI just rendered, producing
        // a "Reverse-Charge" label next to a gross-with-VAT total.
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
            $calc = $vat->calculate($this->billing_country, $net, null);
        } catch (\Throwable) {
            $calc = ['net' => $net, 'vat' => 0, 'gross' => $net, 'rate' => 0.0];
        }

        return [
            ...$calc,
            'reverseCharge' => false,
        ];
    }

    /** @return array{vatRate:float,showsNet:bool,priceFormatter:\Closure,totals:array,customStepCount:int,customStepViews:array} */
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

        $this->applied_coupon = [
            'code' => (string) $coupon->code,
            'name' => (string) $coupon->name,
            'discount_net' => $this->calculateCouponDiscount($coupon),
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

        $this->applied_coupon['discount_net'] = $this->calculateCouponDiscount($coupon);

        return null;
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

        $model = app($this->billableClass);

        return $model->find($this->billableId);
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
                $billable->forceFill([
                    'name' => $this->company_name,
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

<div class="flex flex-col gap-10">
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
