<?php

use GraystackIT\MollieBilling\Concerns\ValidatesVatNumber;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Exceptions\ViesUnavailableException;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\StartMandateCheckout;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\CountryResolver;
use Mollie\Laravel\Facades\Mollie;
use Livewire\Component;

new class extends Component {
    use ValidatesVatNumber;

    private const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
        'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
        'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
    ];

    /** Mandate id at the time the user landed on the page — used to detect the webhook update. */
    public ?string $initialMandateId = null;

    /** Whether we are still waiting for the Mollie webhook to swap the mandate. */
    public bool $awaitingMandateUpdate = false;

    // Billing details (form-bound, persisted on save())
    public string $company_name = '';
    public string $billing_street = '';
    public string $billing_postal_code = '';
    public string $billing_city = '';
    public string $billing_country = '';
    public ?string $vat_number = null;
    public ?bool $vatNumberValid = null;
    public ?string $vatStatusMessage = null;

    /** Snapshot of the persisted country at mount time, used to detect dirty state. */
    public string $persistedCountry = '';

    public function mount(VatCalculationService $vat): void
    {
        $billable = $this->resolveBillable();

        if (request()->boolean('payment_method_updated')) {
            $this->initialMandateId = $billable?->getMollieMandateId();
            $this->awaitingMandateUpdate = true;
        }

        $default = MollieBilling::ipGeolocation()->defaultCountryFor(request()->ip());

        if ($billable !== null) {
            $this->company_name = (string) $billable->getBillingName();
            $this->billing_street = (string) ($billable->getBillingStreet() ?? '');
            $this->billing_postal_code = (string) ($billable->getBillingPostalCode() ?? '');
            $this->billing_city = (string) ($billable->getBillingCity() ?? '');
            $this->billing_country = $billable->getBillingCountry() ?: $default;
            $this->persistedCountry = $this->billing_country;
            $this->vat_number = $billable->vat_number;

            if (filled($this->vat_number)) {
                $this->validateVatNumberLive($vat);
            }
        } else {
            $this->billing_country = $default;
            $this->persistedCountry = $default;
        }
    }

    /** @return array<string, string> */
    public function countries(): array
    {
        return CountryResolver::resolve();
    }

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

    public function save(VatCalculationService $vat): void
    {
        $billable = $this->resolveBillable();
        if ($billable === null) {
            \Flux::toast(__('billing::portal.no_billable'), variant: 'danger');

            return;
        }

        $validCountries = array_keys($this->countries());

        $this->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'billing_street' => ['required', 'string', 'max:255'],
            'billing_postal_code' => ['required', 'string', 'max:20'],
            'billing_city' => ['required', 'string', 'max:255'],
            'billing_country' => ['required', 'string', 'in:'.implode(',', $validCountries)],
            'vat_number' => ['nullable', 'string', 'max:50'],
        ]);

        // Re-validate against VIES whenever the user submits with a VAT number.
        // This is a hard gate — if VIES says invalid or is unreachable, the save
        // is aborted and the user is shown an actionable error. We never persist
        // billing data with an unverified VAT number.
        if (filled($this->vat_number)) {
            try {
                $this->validateVatNumberLive($vat);
            } catch (\Throwable $e) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'vat_number' => __('billing::checkout.vies_unavailable'),
                ]);
            }

            if ($this->vatNumberValid !== true) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'vat_number' => __('billing::checkout.vat_correct_or_clear'),
                ]);
            }
        }

        $countryChanged = strtoupper((string) $billable->getBillingCountry()) !== strtoupper($this->billing_country);

        // Atomic: persisting the billable with a vat_number and recording the
        // matching BillingVatValidation must succeed or fail together. Otherwise
        // a VIES outage between save and validateAndPersist would leave the
        // billable with a vat_number but no audit row — currentVatValidation()
        // returns null and reverse-charge silently stops applying.
        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($billable, $vat): void {
                /** @var \Illuminate\Database\Eloquent\Model&Billable $billable */
                $billable->forceFill([
                    'name' => $this->company_name,
                    'billing_street' => $this->billing_street,
                    'billing_postal_code' => $this->billing_postal_code,
                    'billing_city' => $this->billing_city,
                    'billing_country' => $this->billing_country,
                    'vat_number' => $this->vat_number,
                    'tax_country_user' => $this->billing_country,
                ])->save();

                // `currentVatValidation()` filters by the billable's current
                // `vat_number`, so a number change (or initial set) automatically
                // triggers a fresh persist.
                if (filled($this->vat_number) && $billable->currentVatValidation() === null) {
                    $vat->validateAndPersist($billable, (string) $this->vat_number);
                }
            });
        } catch (ViesUnavailableException) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'vat_number' => __('billing::checkout.vies_unavailable'),
            ]);
        }

        $this->persistedCountry = $this->billing_country;

        if ($countryChanged) {
            \Flux::toast(__('billing::portal.billing_data.country_changed_warning'), variant: 'warning');
        } else {
            \Flux::toast(__('billing::portal.billing_data.saved_flash'), variant: 'success');
        }
    }

    public function checkForMandateUpdate(): void
    {
        if (! $this->awaitingMandateUpdate) {
            return;
        }

        $billable = $this->resolveBillable();
        if ($billable === null) {
            return;
        }

        /** @var \Illuminate\Database\Eloquent\Model&Billable $billable */
        $billable->refresh();

        $current = $billable->getMollieMandateId();
        if ($current !== null && $current !== $this->initialMandateId) {
            $this->awaitingMandateUpdate = false;
            \Flux::toast(__('billing::portal.payment_method.updated_flash'), variant: 'success');
        }
    }

    private function resolveBillable(): ?Billable
    {
        return MollieBilling::resolveBillable(request());
    }

    /**
     * Reverse-charge applies when a valid EU VAT number is set
     * and the destination country is also EU.
     */
    public function isReverseCharge(): bool
    {
        return $this->vatNumberValid === true
            && in_array(strtoupper($this->billing_country), self::EU_COUNTRIES, true);
    }

    /**
     * The country derived from the customer's payment method, if it differs from
     * the country currently selected in the form. Returns null when there is no
     * recorded payment country (e.g. before the first payment) or when both match.
     */
    public function paymentCountryMismatch(?Billable $billable): ?string
    {
        if ($billable === null) {
            return null;
        }

        $paymentCountry = strtoupper((string) ($billable->tax_country_payment ?? ''));
        if ($paymentCountry === '') {
            return null;
        }

        if ($paymentCountry === strtoupper($this->billing_country)) {
            return null;
        }

        return $paymentCountry;
    }

    /**
     * Fetch the current mandate from the Mollie API.
     *
     * @return array{
     *     status: string,
     *     statusColor: string,
     *     statusLabel: string,
     *     method: string,
     *     methodLabel: string,
     *     details: array<string, mixed>,
     *     mandateReference: ?string,
     *     signatureDate: ?string,
     *     summary: ?string,
     * }|null
     */
    public function mandateInfo(?Billable $billable): ?array
    {
        if ($billable === null) {
            return null;
        }

        $customerId = $billable->getMollieCustomerId();
        $mandateId = $billable->getMollieMandateId();

        if ($customerId === null || $mandateId === null || $customerId === '' || $mandateId === '') {
            return null;
        }

        try {
            $mandate = Mollie::api()->mandates->getForId($customerId, $mandateId);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        $status = (string) ($mandate->status ?? 'unknown');
        $method = (string) ($mandate->method ?? 'unknown');
        $details = is_object($mandate->details ?? null)
            ? json_decode(json_encode($mandate->details), true) ?: []
            : (array) ($mandate->details ?? []);

        $signatureDate = null;
        if (! empty($mandate->signatureDate)) {
            try {
                $signatureDate = BillingTime::display(\Carbon\Carbon::parse((string) $mandate->signatureDate)->setTimezone('UTC'), $billable)->translatedFormat('d. M Y');
            } catch (\Throwable) {
                $signatureDate = null;
            }
        }

        return [
            'status' => $status,
            'statusColor' => match ($status) {
                'valid' => 'lime',
                'pending' => 'amber',
                'invalid' => 'red',
                default => 'zinc',
            },
            'statusLabel' => __('billing::portal.payment_method.status.'.$status),
            'method' => $method,
            'methodLabel' => __('billing::portal.payment_method.method.'.$method),
            'details' => $details,
            'mandateReference' => isset($mandate->mandateReference) ? (string) $mandate->mandateReference : null,
            'signatureDate' => $signatureDate,
            'summary' => $this->buildSummary($method, $details),
        ];
    }

    /**
     * Build a human-readable one-liner for the mandate (e.g. "Visa •••• 1234, expires 12/2027").
     *
     * @param  array<string, mixed>  $details
     */
    private function buildSummary(string $method, array $details): ?string
    {
        if ($method === 'creditcard') {
            $label = isset($details['cardLabel']) ? (string) $details['cardLabel'] : __('billing::portal.payment_method.method.creditcard');
            $last4 = isset($details['cardNumber']) ? (string) $details['cardNumber'] : null;
            $expiry = isset($details['cardExpiryDate']) ? (string) $details['cardExpiryDate'] : null;

            $parts = [$label];
            if ($last4 !== null && $last4 !== '') {
                $parts[] = '•••• '.$last4;
            }
            $summary = implode(' ', $parts);

            if ($expiry !== null && $expiry !== '') {
                try {
                    $formatted = \Carbon\Carbon::parse($expiry)->format('m/Y');
                    $summary .= ' · '.__('billing::portal.payment_method.expires', ['date' => $formatted]);
                } catch (\Throwable) {
                    // skip expiry formatting on failure
                }
            }

            return $summary;
        }

        if ($method === 'directdebit') {
            $holder = isset($details['consumerName']) ? (string) $details['consumerName'] : null;
            $iban = isset($details['consumerAccount']) ? (string) $details['consumerAccount'] : null;

            $parts = [];
            if ($holder !== null && $holder !== '') {
                $parts[] = $holder;
            }
            if ($iban !== null && $iban !== '') {
                $parts[] = $iban;
            }

            return $parts === [] ? null : implode(' · ', $parts);
        }

        if ($method === 'paypal') {
            $email = isset($details['consumerAccount']) ? (string) $details['consumerAccount'] : null;

            return $email;
        }

        return null;
    }

    public function changePaymentMethod(StartMandateCheckout $checkout): mixed
    {
        $billable = $this->resolveBillable();
        if ($billable === null) {
            \Flux::toast(__('billing::portal.no_billable'), variant: 'danger');

            return null;
        }

        $returnUrl = route(
            BillingRoute::name('billing-data'),
            array_merge(MollieBilling::resolveUrlParameters($billable), ['payment_method_updated' => 1]),
        );

        try {
            $result = $checkout->handle($billable, $returnUrl);
        } catch (\Throwable $e) {
            report($e);
            \Flux::toast(__('billing::portal.flash.error'), variant: 'danger');

            return null;
        }

        if (! empty($result['checkout_url'])) {
            return $this->redirect($result['checkout_url'], navigate: false);
        }

        \Flux::toast(__('billing::portal.flash.error'), variant: 'danger');

        return null;
    }
};

?>

@php
    $billable = $this->resolveBillable();
    $mandate = $this->mandateInfo($billable);
    $isLocal = $billable && $billable->isLocalBillingSubscription();
    $paymentCountryMismatch = $this->paymentCountryMismatch($billable);
    $countryDirty = strtoupper($persistedCountry) !== strtoupper($billing_country);
    $reverseCharge = $this->isReverseCharge();
@endphp

<div class="space-y-6"
    @if ($awaitingMandateUpdate)
        wire:poll.2s="checkForMandateUpdate"
    @endif
>
    {{-- Page header with VAT-mode badge --}}
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <flux:heading size="xl">{{ __('billing::portal.billing_data.title') }}</flux:heading>
            <flux:subheading>{{ __('billing::portal.billing_data.subtitle') }}</flux:subheading>
        </div>

        @if ($billable && ! $isLocal)
            <div>
                @if ($reverseCharge)
                    <flux:badge color="lime" icon="receipt-percent">
                        {{ __('billing::portal.billing_data.badge_reverse_charge') }}
                    </flux:badge>
                @elseif (filled($vat_number) && $vatNumberValid !== true)
                    <flux:badge color="amber" icon="receipt-percent">
                        {{ __('billing::portal.billing_data.badge_vat_pending') }}
                    </flux:badge>
                @else
                    <flux:badge color="zinc" icon="receipt-percent">
                        {{ __('billing::portal.billing_data.badge_vat_standard') }}
                    </flux:badge>
                @endif
            </div>
        @endif
    </div>

    @if (! $billable)
        <flux:callout variant="warning" icon="exclamation-triangle">
            {{ __('billing::portal.no_billable') }}
        </flux:callout>
    @else
        {{-- Invoice address + VAT number --}}
        <flux:card>
            <div class="space-y-6">
                {{-- Card header --}}
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <flux:heading size="lg">{{ __('billing::portal.billing_data.section_address') }}</flux:heading>
                        <flux:subheading>{{ __('billing::portal.billing_data.section_address_hint') }}</flux:subheading>
                    </div>
                    <flux:icon.building-office-2 class="size-6 text-zinc-400" />
                </div>

                {{-- Form grid --}}
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 sm:items-start">
                    <div class="sm:col-span-2">
                        <flux:input wire:model="company_name" :label="__('billing::checkout.company_name')" type="text" required />
                    </div>
                    <div class="sm:col-span-2">
                        <flux:input wire:model="billing_street" :label="__('billing::checkout.street')" type="text" required />
                    </div>
                    <flux:input wire:model="billing_postal_code" :label="__('billing::checkout.postal_code')" type="text" required />
                    <flux:input wire:model="billing_city" :label="__('billing::checkout.city')" type="text" required />
                    <flux:field>
                        <flux:label>{{ __('billing::checkout.country') }}</flux:label>
                        <flux:select wire:model.live="billing_country" required>
                            @foreach ($this->countries() as $iso => $name)
                                <flux:select.option value="{{ $iso }}">{{ $name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="billing_country" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('billing::checkout.vat_number') }}</flux:label>
                        <flux:input.group>
                            <flux:input wire:model.live.debounce.500ms="vat_number" type="text" placeholder="ATU12345678" />

                            <flux:input.group.suffix class="text-zinc-500 dark:text-zinc-400" wire:loading.flex wire:target="vat_number,billing_country">
                                <flux:icon.loading class="size-4" />
                            </flux:input.group.suffix>

                            <div wire:loading.remove wire:target="vat_number,billing_country" class="contents">
                                @if ($vatNumberValid === true)
                                    <flux:input.group.suffix class="text-emerald-700 dark:text-emerald-400">
                                        <flux:icon.check-circle class="size-4" />
                                    </flux:input.group.suffix>
                                @elseif ($vatNumberValid === false)
                                    <flux:input.group.suffix class="text-red-600 dark:text-red-400">
                                        <flux:icon.x-circle class="size-4" />
                                    </flux:input.group.suffix>
                                @endif
                            </div>
                        </flux:input.group>
                        <div wire:loading.remove wire:target="vat_number,billing_country">
                            <flux:error name="vat_number" />
                        </div>
                        <flux:description>{{ __('billing::portal.billing_data.vat_number_help') }}</flux:description>
                    </flux:field>

                    @if ($countryDirty)
                        <div class="sm:col-span-2">
                            <flux:callout color="amber" icon="information-circle" inline>
                                {{ __('billing::portal.billing_data.country_dirty_inline') }}
                            </flux:callout>
                        </div>
                    @endif

                    @if ($paymentCountryMismatch)
                        <div class="sm:col-span-2">
                            <flux:callout color="amber" icon="credit-card" inline>
                                {{ __('billing::portal.billing_data.payment_country_mismatch_inline', [
                                    'declared' => strtoupper($billing_country),
                                    'payment' => $paymentCountryMismatch,
                                ]) }}
                            </flux:callout>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Action footer --}}
            <flux:separator class="my-6" />
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('billing::portal.billing_data.save_hint') }}
                </flux:text>
                <flux:button
                    variant="primary"
                    icon="check"
                    wire:click="save"
                    wire:target="save,vat_number,billing_country"
                    wire:loading.attr="disabled"
                >
                    {{ __('billing::portal.billing_data.save') }}
                </flux:button>
            </div>
        </flux:card>

        {{-- Payment method (mandate) — single card with stateful body --}}
        <flux:card>
            <div class="flex items-start justify-between gap-4">
                <div>
                    <flux:heading size="lg">{{ __('billing::portal.billing_data.section_mandate') }}</flux:heading>
                    <flux:subheading>{{ __('billing::portal.billing_data.section_mandate_hint') }}</flux:subheading>
                </div>
                @if ($mandate && ! $isLocal)
                    <flux:badge size="sm" :color="$mandate['statusColor']">{{ $mandate['statusLabel'] }}</flux:badge>
                @else
                    <flux:icon.credit-card class="size-6 text-zinc-400" />
                @endif
            </div>

            @if ($awaitingMandateUpdate)
                <div class="mt-5">
                    <flux:callout icon="arrow-path" color="blue" inline>
                        {{ __('billing::portal.payment_method.awaiting_update') }}
                    </flux:callout>
                </div>
            @endif

            <flux:separator class="my-6" />

            @if ($isLocal)
                {{-- Local subscription: no Mollie mandate is collected for free plans. --}}
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                    <flux:icon.gift class="size-10 text-zinc-400" />
                    <div class="flex-1">
                        <flux:text>{{ __('billing::portal.free_plan_no_payment') }}</flux:text>
                    </div>
                    <flux:button
                        :href="route(\GraystackIT\MollieBilling\Support\BillingRoute::name('plan'), \GraystackIT\MollieBilling\MollieBilling::resolveUrlParameters($billable))"
                        variant="ghost"
                        size="sm"
                        icon:trailing="arrow-right"
                    >
                        {{ __('billing::portal.plan_change') }}
                    </flux:button>
                </div>
            @elseif ($mandate === null)
                {{-- No mandate yet --}}
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                    <flux:icon.exclamation-circle class="size-10 text-amber-500" />
                    <div class="flex-1 space-y-1">
                        <flux:heading size="sm">{{ __('billing::portal.payment_method.none_title') }}</flux:heading>
                        <flux:text class="text-sm">{{ __('billing::portal.payment_method.none_body') }}</flux:text>
                    </div>
                    <flux:button variant="primary" icon="plus" wire:click="changePaymentMethod">
                        {{ __('billing::portal.payment_method.add_button') }}
                    </flux:button>
                </div>
            @else
                {{-- Current mandate --}}
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="flex items-start gap-4">
                        <flux:icon.credit-card class="size-10 text-zinc-500 dark:text-zinc-400" />
                        <div class="space-y-1">
                            <flux:heading size="lg">{{ $mandate['methodLabel'] }}</flux:heading>
                            @if ($mandate['summary'])
                                <flux:text class="font-mono text-sm">{{ $mandate['summary'] }}</flux:text>
                            @endif
                        </div>
                    </div>
                    <flux:modal.trigger name="change-payment-method">
                        <flux:button variant="primary" icon="arrow-path">{{ __('billing::portal.payment_method.change_button') }}</flux:button>
                    </flux:modal.trigger>
                </div>

                <flux:separator class="my-6" />

                <div class="grid grid-cols-1 gap-x-8 gap-y-5 sm:grid-cols-2">
                    @if ($mandate['signatureDate'])
                        <div>
                            <flux:subheading size="sm" class="text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.payment_method.signature_date') }}</flux:subheading>
                            <flux:text class="mt-1 font-semibold">{{ $mandate['signatureDate'] }}</flux:text>
                        </div>
                    @endif
                    @if ($mandate['mandateReference'])
                        <div>
                            <flux:subheading size="sm" class="text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.payment_method.reference') }}</flux:subheading>
                            <flux:text class="mt-1 font-mono text-sm">{{ $mandate['mandateReference'] }}</flux:text>
                        </div>
                    @endif

                    @if ($mandate['method'] === 'creditcard')
                        @if (! empty($mandate['details']['cardHolder']))
                            <div>
                                <flux:subheading size="sm" class="text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.payment_method.card_holder') }}</flux:subheading>
                                <flux:text class="mt-1 font-semibold">{{ $mandate['details']['cardHolder'] }}</flux:text>
                            </div>
                        @endif
                        @if (! empty($mandate['details']['cardLabel']))
                            <div>
                                <flux:subheading size="sm" class="text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.payment_method.card_brand') }}</flux:subheading>
                                <flux:text class="mt-1 font-semibold">{{ $mandate['details']['cardLabel'] }}</flux:text>
                            </div>
                        @endif
                    @elseif ($mandate['method'] === 'directdebit')
                        @if (! empty($mandate['details']['consumerName']))
                            <div>
                                <flux:subheading size="sm" class="text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.payment_method.account_holder') }}</flux:subheading>
                                <flux:text class="mt-1 font-semibold">{{ $mandate['details']['consumerName'] }}</flux:text>
                            </div>
                        @endif
                        @if (! empty($mandate['details']['consumerAccount']))
                            <div>
                                <flux:subheading size="sm" class="text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.payment_method.iban') }}</flux:subheading>
                                <flux:text class="mt-1 font-mono">{{ $mandate['details']['consumerAccount'] }}</flux:text>
                            </div>
                        @endif
                        @if (! empty($mandate['details']['consumerBic']))
                            <div>
                                <flux:subheading size="sm" class="text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.payment_method.bic') }}</flux:subheading>
                                <flux:text class="mt-1 font-mono">{{ $mandate['details']['consumerBic'] }}</flux:text>
                            </div>
                        @endif
                    @elseif ($mandate['method'] === 'paypal')
                        @if (! empty($mandate['details']['consumerName']))
                            <div>
                                <flux:subheading size="sm" class="text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.payment_method.account_holder') }}</flux:subheading>
                                <flux:text class="mt-1 font-semibold">{{ $mandate['details']['consumerName'] }}</flux:text>
                            </div>
                        @endif
                        @if (! empty($mandate['details']['consumerAccount']))
                            <div>
                                <flux:subheading size="sm" class="text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.payment_method.paypal_email') }}</flux:subheading>
                                <flux:text class="mt-1">{{ $mandate['details']['consumerAccount'] }}</flux:text>
                            </div>
                        @endif
                    @endif
                </div>

                {{-- Confirm modal --}}
                <flux:modal name="change-payment-method" class="max-w-md">
                    <div class="space-y-6">
                        <div class="space-y-2">
                            <flux:heading size="lg">{{ __('billing::portal.payment_method.change_confirm.title') }}</flux:heading>
                            <flux:text>{{ __('billing::portal.payment_method.change_confirm.body') }}</flux:text>
                        </div>
                        <div class="flex justify-end gap-2">
                            <flux:modal.close>
                                <flux:button variant="ghost">{{ __('billing::portal.payment_method.change_confirm.cancel') }}</flux:button>
                            </flux:modal.close>
                            <flux:button variant="primary" wire:click="changePaymentMethod" x-on:click="$flux.modal('change-payment-method').close()">
                                {{ __('billing::portal.payment_method.change_confirm.confirm') }}
                            </flux:button>
                        </div>
                    </div>
                </flux:modal>
            @endif
        </flux:card>
    @endif
</div>
