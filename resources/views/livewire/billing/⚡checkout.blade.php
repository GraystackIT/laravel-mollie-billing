<?php

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Events\CheckoutStarted;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Exceptions\ViesUnavailableException;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\CountryResolver;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('mollie-billing::layouts.checkout')] class extends Component {
    public int $step = 1;

    // Step 1: billing address
    public string $company_name = '';
    public string $billing_street = '';
    public string $billing_postal_code = '';
    public string $billing_city = '';
    public string $billing_country = 'AT';
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

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    public function mount(): void
    {
        $this->backUrl = request()->query('back');

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

        return $this->customStepCount() + $base;
    }

    public function displayStep(): int
    {
        $offset = $this->customStepCount();

        // When addons step is skipped, the confirm step is still stored as offset+4 internally
        if (! $this->hasAddonsOrSeatsStep() && $this->step === $offset + 4) {
            return $offset + 3;
        }

        return $this->step;
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
        $offset = $this->customStepCount();

        // Custom steps first
        foreach ($this->customSteps() as $i => $customStep) {
            $steps[] = ['key' => $i + 1, 'label' => $customStep['label']];
        }

        // Package steps
        $steps[] = ['key' => $offset + 1, 'label' => __('billing::checkout.step_billing_details')];
        $steps[] = ['key' => $offset + 2, 'label' => __('billing::checkout.step_plan')];

        if ($this->hasAddonsOrSeatsStep()) {
            $steps[] = ['key' => $offset + 3, 'label' => __('billing::checkout.step_addons')];
            $steps[] = ['key' => $offset + 4, 'label' => __('billing::checkout.step_confirm')];
        } else {
            $steps[] = ['key' => $offset + 3, 'label' => __('billing::checkout.step_confirm')];
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

        foreach ($this->addon_codes as $code) {
            $net += (int) ($this->addons()[$code]['intervals'][$this->interval]['price_net'] ?? 0);
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

        try {
            $calc = $vat->calculate($this->billing_country, $net, $this->showsNetPrices() ? $this->vat_number : null);
        } catch (\Throwable) {
            $calc = ['net' => $net, 'vat' => 0, 'gross' => $net, 'rate' => 0.0];
        }

        return [
            ...$calc,
            'reverseCharge' => $this->showsNetPrices(),
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
        $this->resetErrorBag('vat_number');
        $this->vatNumberValid = null;
        $this->vatStatusMessage = null;

        $value = trim((string) $this->vat_number);
        if ($value === '') {
            return;
        }

        $normalized = strtoupper(preg_replace('/\s+/', '', $value) ?? '');
        $this->vat_number = $normalized;

        if (! preg_match('/^[A-Z]{2}[A-Z0-9]{2,12}$/', $normalized)) {
            $this->addError('vat_number', __('billing::checkout.vat_invalid_format'));
            $this->vatNumberValid = false;

            return;
        }

        if (substr($normalized, 0, 2) !== strtoupper($this->billing_country)) {
            $this->addError('vat_number', __('billing::checkout.vat_country_mismatch'));
            $this->vatNumberValid = false;

            return;
        }

        try {
            $isValid = $vat->validateVatNumber($normalized);
        } catch (ViesUnavailableException) {
            $this->vatNumberValid = null;
            $this->vatStatusMessage = __('billing::checkout.vies_unavailable');

            return;
        }

        if (! $isValid) {
            $this->addError('vat_number', __('billing::checkout.vies_validation_failed'));
            $this->vatNumberValid = false;

            return;
        }

        $this->vatNumberValid = true;
        $this->vatStatusMessage = __('billing::checkout.vat_verified');
    }

    public function updatedBillingCountry(VatCalculationService $vat): void
    {
        if (filled($this->vat_number)) {
            $this->updatedVatNumber($vat);
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
                'orderAmountNet' => $this->subtotalNet(),
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

    private function refreshAppliedCoupon(): void
    {
        if ($this->applied_coupon === null) {
            return;
        }

        try {
            $coupon = MollieBilling::coupons()->validateWithoutBillable($this->applied_coupon['code'], [
                'planCode' => $this->plan_code,
                'interval' => $this->interval,
                'addonCodes' => $this->addon_codes,
                'orderAmountNet' => $this->subtotalNet(),
            ]);
        } catch (\Throwable) {
            $this->applied_coupon = null;

            return;
        }

        $this->applied_coupon['discount_net'] = $this->calculateCouponDiscount($coupon);
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
            'min_order_not_met' => __('billing::checkout.coupon_min_order'),
            'requires_billable' => __('billing::checkout.coupon_requires_billable'),
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

        // Create billable after billing-address step validation
        if ($pkg === 1 && $this->billableId === null) {
            $this->createBillable();
        }

        // Skip addons step if plan doesn't need it
        if ($pkg === 2 && ! $this->hasAddonsOrSeatsStep()) {
            $this->step = $offset + 4;

            return;
        }

        $this->step++;
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

        if ($this->vatNumberValid === false) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'vat_number' => __('billing::checkout.vat_correct_or_clear'),
            ]);
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
            'vat_number' => $this->vat_number,
            'custom' => $this->customData,
        ]);

        /** @var \Illuminate\Database\Eloquent\Model&Billable $billable */
        $this->billableId = (string) $billable->getKey();
        $this->billableClass = $billable->getMorphClass();

        event(new CheckoutStarted($billable));
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
        $this->processing = true;
        $this->errorMessage = null;

        $this->validateStep1();
        $this->validateStep2();
        if ($this->hasAddonsOrSeatsStep()) {
            $this->validateStep3();
        }

        $billable = $this->resolveBillable();
        if ($billable === null) {
            $this->errorMessage = __('billing::checkout.error_no_billable');
            $this->processing = false;

            return null;
        }

        // Update billing address on the billable (may have changed since step 1)
        /** @var \Illuminate\Database\Eloquent\Model&Billable $billable */
        $billable->forceFill([
            'name' => $this->company_name,
            'billing_street' => $this->billing_street,
            'billing_postal_code' => $this->billing_postal_code,
            'billing_city' => $this->billing_city,
            'billing_country' => $this->billing_country,
            'vat_number' => $this->vat_number,
        ])->save();

        // Run before-checkout hook
        $blockReason = MollieBilling::runBeforeCheckout($billable);
        if ($blockReason !== null) {
            $this->errorMessage = $blockReason;
            $this->processing = false;

            return null;
        }

        $this->refreshAppliedCoupon();

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
            MollieBilling::runAfterCheckout($billable, false);
            $this->errorMessage = __('billing::checkout.error_payment_creation');
            $this->processing = false;

            return null;
        }

        if (! empty($result['checkout_url'])) {
            return $this->redirect($result['checkout_url'], navigate: false);
        }

        // Zero-amount / local subscription — no Mollie redirect needed
        MollieBilling::runAfterCheckout($billable, true);

        return $this->redirectRoute(BillingRoute::name('index'), navigate: false);
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
        <flux:callout icon="exclamation-triangle" color="red" inline>
            {{ $errorMessage }}
        </flux:callout>
    @endif

    {{-- Horizontal timeline --}}
    <flux:timeline :horizontal="true" align="center" class="rounded-xl border border-zinc-200/80 bg-white/60 p-6 backdrop-blur-sm dark:border-white/10 dark:bg-white/2">
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
