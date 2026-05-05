<?php

use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Services\Billing\MollieMandateInspector;
use GraystackIT\MollieBilling\Support\AdminLocale;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Support\MandateSummary;
use Livewire\Component;

new class extends Component {
    public mixed $billable = null;

    public function mount(array $routeParameters = []): void
    {
        $id = $routeParameters['billable'] ?? null;
        $class = config('mollie-billing.billable_model');
        if ($class && $id !== null && is_string($class) && is_subclass_of($class, \Illuminate\Database\Eloquent\Model::class)) {
            $this->billable = (new $class)->resolveRouteBinding($id);
        }
    }

    public function mandate(): ?MandateSummary
    {
        return $this->billable
            ? app(MollieMandateInspector::class)->inspect($this->billable)
            : null;
    }

    public function planName(): ?string
    {
        $code = $this->billable?->subscription_plan_code;
        if ($code === null || $code === '') {
            return null;
        }

        return app(SubscriptionCatalogInterface::class)->planName($code) ?? $code;
    }
};

?>

<div class="space-y-6">
    @if (! $billable)
        <x-mollie-billing::admin.page-header
            title="Billable not found"
            :back="route(BillingRoute::admin('billables.index'))"
            backLabel="Billables"
        />
        <flux:card>
            <x-mollie-billing::admin.empty
                icon="user"
                title="Billable not found"
                description="The requested billable does not exist or has been removed."
            />
        </flux:card>
    @else
        @php
            $initials = collect(explode(' ', trim((string) $billable->name)))
                ->filter()
                ->take(2)
                ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
                ->implode('');
        @endphp

        <div class="space-y-2">
            <flux:button :href="route(BillingRoute::admin('billables.index'))" size="xs" variant="ghost" icon="arrow-left" class="-ml-2">Billables</flux:button>

            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex min-w-0 items-center gap-4">
                    <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-lg font-semibold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                        {{ $initials ?: '?' }}
                    </div>
                    <div class="min-w-0 space-y-1">
                        <div class="flex flex-wrap items-center gap-3">
                            <flux:heading size="xl" class="truncate">{{ $billable->name }}</flux:heading>
                            <x-mollie-billing::admin.enum-badge :value="$billable->subscription_status" />
                        </div>
                        <flux:text class="text-zinc-600 dark:text-zinc-400">{{ $billable->email }}</flux:text>
                    </div>
                </div>

            </div>
        </div>

        @php
            $planName = $this->planName();
            $planCode = $billable->subscription_plan_code;
            $intervalLabel = $billable->subscription_interval
                ? AdminLocale::enumLabel($billable->subscription_interval)
                : null;

            $planHintParts = [];
            if ($intervalLabel) { $planHintParts[] = $intervalLabel; }
            if ($planName && $planCode && $planName !== $planCode) {
                $planHintParts[] = $planCode;
            }
            $planHint = $planHintParts !== [] ? implode(' · ', $planHintParts) : null;

            $mandate = $this->mandate();
            // Resolve all translatable mandate strings under the admin locale so
            // they match the rest of the panel even when the surrounding app is
            // running in another language.
            $mandateMethodLabel = $mandate
                ? AdminLocale::with(fn (): string => $mandate->methodLabel())
                : null;
            $mandateStatusLabel = $mandate
                ? AdminLocale::with(fn (): string => $mandate->statusLabel())
                : null;
            $mandateAccount = $mandate?->accountDisplay();
            $mandateHolder = $mandate?->holder();
            $mandateExpiry = $mandate?->expiry();
            $mandateExpired = (bool) $mandate?->isExpired();
            $mandateExpiringSoon = (bool) $mandate?->isExpiringSoon();
        @endphp

        <div class="grid gap-4 md:grid-cols-3">
            <x-mollie-billing::admin.stat
                label="Plan"
                :value="$planName ?? '—'"
                icon="squares-2x2"
                :hint="$planHint"
            />

            {{-- Customer card: contact email + the full billing address + VAT number,
                 i.e. everything that lives on the Billable but isn't already shown in
                 the page header. Subscription status is already on the header pill so
                 it doesn't need its own tile. --}}
            <flux:card class="flex h-full flex-col">
                <div class="flex items-start justify-between gap-3">
                    <flux:text size="xs" class="uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Customer</flux:text>
                    <flux:icon name="identification" variant="mini" class="text-zinc-400" />
                </div>

                <div class="mt-3 space-y-3 text-sm">
                    @php
                        $email = $billable->getBillingEmail();
                        $street = $billable->getBillingStreet();
                        $postal = $billable->getBillingPostalCode();
                        $city = $billable->getBillingCity();
                        $country = $billable->getBillingCountry();
                        $vatNumber = $billable->vat_number ?? null;
                        $cityLine = trim(($postal ?? '').' '.($city ?? ''));
                        $hasAnyAddressPart = $street || $cityLine !== '' || $country;
                    @endphp

                    @if ($email)
                        <a href="mailto:{{ $email }}" class="flex min-w-0 items-center gap-2 text-zinc-700 underline-offset-4 hover:underline dark:text-zinc-200">
                            <flux:icon name="envelope" variant="micro" class="size-3.5 shrink-0 text-zinc-400" />
                            <span class="truncate">{{ $email }}</span>
                        </a>
                    @endif

                    @if ($hasAnyAddressPart)
                        <div class="flex min-w-0 items-start gap-2 text-zinc-700 dark:text-zinc-200">
                            <flux:icon name="map-pin" variant="micro" class="mt-0.5 size-3.5 shrink-0 text-zinc-400" />
                            <address class="not-italic leading-snug">
                                @if ($street)
                                    <div class="truncate">{{ $street }}</div>
                                @endif
                                @if ($cityLine !== '')
                                    <div class="truncate">{{ $cityLine }}</div>
                                @endif
                                @if ($country)
                                    <div class="font-mono text-xs uppercase text-zinc-500 dark:text-zinc-400">{{ $country }}</div>
                                @endif
                            </address>
                        </div>
                    @else
                        <div class="flex items-center gap-2 text-zinc-400 dark:text-zinc-500">
                            <flux:icon name="map-pin" variant="micro" class="size-3.5" />
                            <span class="italic">No billing address on file</span>
                        </div>
                    @endif

                    @if ($vatNumber)
                        <div class="flex min-w-0 items-center gap-2 pt-1 text-zinc-700 dark:text-zinc-200">
                            <flux:icon name="hashtag" variant="micro" class="size-3.5 shrink-0 text-zinc-400" />
                            <span class="font-mono text-xs uppercase tracking-wide">{{ $vatNumber }}</span>
                        </div>
                    @endif
                </div>
            </flux:card>

            {{-- Mandate card: payment-card-shaped tile. Method + brand pill on top,
                 account number large and centered, holder/expiry as the lower-band
                 details (cf. real card layout). --}}
            <flux:card class="flex h-full flex-col">
                <div class="flex items-start justify-between gap-3">
                    <flux:text size="xs" class="uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Mandate</flux:text>
                    @if ($mandate)
                        <span @class([
                            'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium',
                            'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $mandate->status === 'valid',
                            'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' => $mandate->status === 'pending',
                            'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300' => $mandate->status === 'invalid',
                            'bg-zinc-100 text-zinc-600 dark:bg-zinc-700/40 dark:text-zinc-300' => ! in_array($mandate->status, ['valid', 'pending', 'invalid'], true),
                        ])>
                            <span @class([
                                'inline-block size-1.5 rounded-full',
                                'bg-emerald-500' => $mandate->status === 'valid',
                                'bg-amber-500' => $mandate->status === 'pending',
                                'bg-red-500' => $mandate->status === 'invalid',
                                'bg-zinc-400' => ! in_array($mandate->status, ['valid', 'pending', 'invalid'], true),
                            ])></span>
                            {{ $mandateStatusLabel }}
                        </span>
                    @else
                        <flux:icon
                            :name="$mandate?->method === 'directdebit' ? 'building-library' : ($mandate?->method === 'paypal' ? 'globe-alt' : 'credit-card')"
                            variant="mini"
                            class="text-zinc-400"
                        />
                    @endif
                </div>

                @if ($mandate)
                    {{-- Method label as small subtitle so the prominent line is the account number --}}
                    <flux:text size="xs" class="mt-3 font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        {{ $mandateMethodLabel }}
                    </flux:text>

                    {{-- Account number — render as four-digit groups like a real card. --}}
                    <div class="mt-1.5">
                        @if ($mandate->method === 'creditcard' && $mandate->cardLast4())
                            <div class="font-mono text-2xl font-semibold tracking-[0.08em] text-zinc-900 dark:text-white">
                                <span class="text-zinc-400 dark:text-zinc-600">••••</span>
                                <span class="text-zinc-400 dark:text-zinc-600">••••</span>
                                <span class="text-zinc-400 dark:text-zinc-600">••••</span>
                                <span>{{ $mandate->cardLast4() }}</span>
                            </div>
                        @elseif ($mandate->method === 'directdebit' && $mandate->ibanSuffix())
                            <div class="font-mono text-2xl font-semibold tracking-[0.06em] text-zinc-900 dark:text-white">
                                <span class="text-zinc-400 dark:text-zinc-600">•••• ••••</span>
                                <span>{{ $mandate->ibanSuffix() }}</span>
                            </div>
                        @elseif ($mandateAccount)
                            <div class="break-all font-mono text-base font-semibold text-zinc-900 dark:text-white">
                                {{ $mandateAccount }}
                            </div>
                        @else
                            <div class="text-base font-semibold text-zinc-700 dark:text-zinc-300">
                                {{ AdminLocale::with(fn () => __('billing::portal.payment_method.status.valid')) }}
                            </div>
                        @endif
                    </div>

                    {{-- Lower band: holder on the left, expiry on the right. Mirrors a physical card. --}}
                    @if ($mandateHolder || $mandateExpiry)
                        <div class="mt-3 flex items-end justify-between gap-3 text-xs">
                            @if ($mandateHolder)
                                <div class="min-w-0">
                                    <div class="text-[10px] font-medium uppercase tracking-[0.14em] text-zinc-400 dark:text-zinc-500">Holder</div>
                                    <div class="mt-0.5 truncate text-zinc-700 dark:text-zinc-200">{{ $mandateHolder }}</div>
                                </div>
                            @else
                                <div></div>
                            @endif
                            @if ($mandateExpiry)
                                <div class="text-right">
                                    <div class="text-[10px] font-medium uppercase tracking-[0.14em] text-zinc-400 dark:text-zinc-500">
                                        @if ($mandateExpired) Expired @else Expires @endif
                                    </div>
                                    <div @class([
                                        'mt-0.5 tabular-nums',
                                        'text-red-600 dark:text-red-400 font-medium' => $mandateExpired,
                                        'text-amber-600 dark:text-amber-400 font-medium' => $mandateExpiringSoon,
                                        'text-zinc-700 dark:text-zinc-200' => ! $mandateExpired && ! $mandateExpiringSoon,
                                    ])>{{ $mandateExpiry->format('m/Y') }}</div>
                                </div>
                            @endif
                        </div>
                    @endif

                    <div class="mt-auto pt-3 font-mono text-[11px] text-zinc-400 dark:text-zinc-500">
                        {{ $mandate->id }}
                    </div>
                @elseif ($billable->mollie_mandate_id)
                    <div class="mt-3 text-2xl font-semibold text-zinc-700 dark:text-zinc-200">Active</div>
                    <flux:text size="xs" class="mt-1 text-zinc-500 dark:text-zinc-400">Could not load payment method details from Mollie.</flux:text>
                    <div class="mt-auto pt-3 font-mono text-[11px] text-zinc-400 dark:text-zinc-500">{{ $billable->mollie_mandate_id }}</div>
                @else
                    <div class="mt-3 text-2xl font-semibold text-zinc-400 dark:text-zinc-500">None</div>
                    <flux:text size="xs" class="mt-1 text-zinc-500 dark:text-zinc-400">No payment method on file.</flux:text>
                @endif
            </flux:card>
        </div>

        <flux:tab.group>
            <flux:tabs>
                <flux:tab name="subscription" icon="arrow-path">Subscription</flux:tab>
                <flux:tab name="invoices" icon="document-text">Invoices</flux:tab>
                <flux:tab name="wallet" icon="wallet">Wallet</flux:tab>
            </flux:tabs>

            <flux:tab.panel name="subscription" class="pt-4">
                <livewire:mollie-billing::admin.billables.subscription-tab :billable-id="$billable->getKey()" :key="'sub-'.$billable->getKey()" />
            </flux:tab.panel>

            <flux:tab.panel name="invoices" class="pt-4">
                <livewire:mollie-billing::admin.billables.invoices-tab :billable-id="$billable->getKey()" :key="'inv-'.$billable->getKey()" />
            </flux:tab.panel>

            <flux:tab.panel name="wallet" class="pt-4">
                <livewire:mollie-billing::admin.billables.wallet-tab :billable-id="$billable->getKey()" :key="'wal-'.$billable->getKey()" />
            </flux:tab.panel>
        </flux:tab.group>
    @endif
</div>
