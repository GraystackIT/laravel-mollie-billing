<?php

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\StartMandateCheckout;
use GraystackIT\MollieBilling\Support\BillingRoute;
use Mollie\Laravel\Facades\Mollie;
use Livewire\Component;

new class extends Component {
    /** Mandate id at the time the user landed on the page — used to detect the webhook update. */
    public ?string $initialMandateId = null;

    /** Whether we are still waiting for the Mollie webhook to swap the mandate. */
    public bool $awaitingMandateUpdate = false;

    public function mount(): void
    {
        $billable = $this->resolveBillable();

        if (request()->boolean('payment_method_updated')) {
            $this->initialMandateId = $billable?->getMollieMandateId();
            $this->awaitingMandateUpdate = true;
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
                $signatureDate = \Carbon\Carbon::parse((string) $mandate->signatureDate)->translatedFormat('d. M Y');
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
            BillingRoute::name('payment-method'),
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
@endphp

<div class="space-y-6"
    @if ($awaitingMandateUpdate)
        wire:poll.2s="checkForMandateUpdate"
    @endif
>
    {{-- Page header --}}
    <div>
        <flux:heading size="xl">{{ __('billing::portal.payment_method.title') }}</flux:heading>
        <flux:subheading>{{ __('billing::portal.payment_method.subtitle') }}</flux:subheading>
    </div>

    @if ($awaitingMandateUpdate)
        <flux:callout icon="arrow-path" color="blue" inline>
            {{ __('billing::portal.payment_method.awaiting_update') }}
        </flux:callout>
    @endif

    @if (! $billable)
        <flux:callout variant="warning" icon="exclamation-triangle">
            {{ __('billing::portal.no_billable') }}
        </flux:callout>
    @elseif ($billable->isLocalBillingSubscription())
        {{-- Local subscription: no Mollie mandate is collected for free plans. --}}
        <flux:callout icon="information-circle" color="blue">
            <span>{{ __('billing::portal.free_plan_no_payment') }}</span>
            <flux:button :href="route(\GraystackIT\MollieBilling\Support\BillingRoute::name('plan'), \GraystackIT\MollieBilling\MollieBilling::resolveUrlParameters($billable))" variant="primary" size="sm" class="mt-3">
                {{ __('billing::portal.plan_change') }}
            </flux:button>
        </flux:callout>
    @elseif ($mandate === null)
        {{-- No mandate yet --}}
        <flux:card class="space-y-4">
            <div class="flex items-start gap-4">
                <flux:icon.credit-card class="size-10 text-zinc-400" />
                <div class="flex-1 space-y-1">
                    <flux:heading size="lg">{{ __('billing::portal.payment_method.none_title') }}</flux:heading>
                    <flux:text>{{ __('billing::portal.payment_method.none_body') }}</flux:text>
                </div>
            </div>
            <div>
                <flux:button variant="primary" icon="plus" wire:click="changePaymentMethod">
                    {{ __('billing::portal.payment_method.add_button') }}
                </flux:button>
            </div>
        </flux:card>
    @else
        {{-- Current mandate --}}
        <flux:card class="space-y-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="flex items-start gap-4">
                    <flux:icon.credit-card class="size-10 text-zinc-500 dark:text-zinc-400" />
                    <div class="space-y-1">
                        <div class="flex items-center gap-3">
                            <flux:heading size="lg">{{ $mandate['methodLabel'] }}</flux:heading>
                            <flux:badge size="sm" :color="$mandate['statusColor']">{{ $mandate['statusLabel'] }}</flux:badge>
                        </div>
                        @if ($mandate['summary'])
                            <flux:text class="font-mono text-sm">{{ $mandate['summary'] }}</flux:text>
                        @endif
                    </div>
                </div>
                <div>
                    <flux:modal.trigger name="change-payment-method">
                        <flux:button variant="primary" icon="arrow-path">{{ __('billing::portal.payment_method.change_button') }}</flux:button>
                    </flux:modal.trigger>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-x-8 gap-y-5 border-t border-zinc-200/75 pt-5 sm:grid-cols-2 dark:border-zinc-700/50">
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
        </flux:card>

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
</div>
