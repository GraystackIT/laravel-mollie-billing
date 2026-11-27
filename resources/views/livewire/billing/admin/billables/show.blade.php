<?php

use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Services\Billing\MollieMandateInspector;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Support\MandateSummary;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    /** Locked: this page and the child tabs it keys are scoped to the routed billable. */
    #[Locked]
    public mixed $billable = null;

    public function mount(array $routeParameters = []): void
    {
        $id = $routeParameters['billable'] ?? null;
        $class = config('mollie-billing.billable_model');
        if ($class && $id !== null && is_string($class) && is_subclass_of($class, \Illuminate\Database\Eloquent\Model::class)) {
            $this->billable = (new $class)->resolveRouteBinding($id);
        }
    }

    /**
     * The header (status badge, plan, mandate) is rendered here, the tabs are child
     * components that re-render only themselves. A tab announces every action that
     * reached a service, and the page re-reads the billable so the header follows
     * without a reload. The children are keyed, so this does not re-render them.
     */
    #[On('billing-admin-billable-changed')]
    public function billableChanged(): void
    {
        $this->billable = $this->billable?->fresh();
    }

    /**
     * The mandate id the summary below was fetched for. Null with `$mandateFetched` false
     * until the first render; kept between requests so a header refresh does not ask Mollie
     * again. A full page load starts empty and always fetches.
     */
    #[Locked]
    public ?string $mandateFetchedFor = null;

    #[Locked]
    public bool $mandateFetched = false;

    /** @var array<string, mixed>|null  {@see MandateSummary::snapshot()}, null when Mollie had none to give */
    #[Locked]
    public ?array $mandateSnapshot = null;

    /**
     * One GetMandate per page load, not per tab action. The header refresh re-renders this
     * component after every action a tab announces — a status or plan change, none of
     * which touches the mandate — and asking Mollie each time was one extra API call per
     * admin action. Asked again only when the billable's mandate id is not the one the
     * kept summary belongs to.
     */
    public function mandate(): ?MandateSummary
    {
        if (! $this->billable) {
            return null;
        }

        $mandateId = $this->billable->mollie_mandate_id;
        $mandateId = is_string($mandateId) && $mandateId !== '' ? $mandateId : null;

        if (! $this->mandateFetched || $this->mandateFetchedFor !== $mandateId) {
            $summary = $mandateId !== null
                ? app(MollieMandateInspector::class)->inspect($this->billable)
                : null;

            $this->mandateFetched = true;
            $this->mandateFetchedFor = $mandateId;
            $this->mandateSnapshot = $summary?->snapshot();

            return $summary;
        }

        return $this->mandateSnapshot !== null ? MandateSummary::fromSnapshot($this->mandateSnapshot) : null;
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
            :title="__('billing::admin.billables.show.not_found_title')"
            :back="route(BillingRoute::admin('billables.index'))"
            :backLabel="__('billing::admin.nav.billables')"
        />
        <flux:card>
            <x-mollie-billing::admin.empty
                icon="user"
                :title="__('billing::admin.billables.show.not_found_title')"
                :description="__('billing::admin.billables.show.not_found_description')"
            />
        </flux:card>
    @else
        @php
            $initials = collect(explode(' ', trim($billable->getBillingName())))
                ->filter()
                ->take(2)
                ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
                ->implode('');
        @endphp

        <div class="space-y-2">
            <flux:button :href="route(BillingRoute::admin('billables.index'))" size="xs" variant="ghost" icon="arrow-left" class="-ml-2">{{ __('billing::admin.nav.billables') }}</flux:button>

            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex min-w-0 items-center gap-4">
                    <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-lg font-semibold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                        {{ $initials ?: '?' }}
                    </div>
                    <div class="min-w-0 space-y-1">
                        <div class="flex flex-wrap items-center gap-3">
                            <flux:heading size="xl" class="truncate">{{ $billable->getBillingName() }}</flux:heading>
                            <span data-billable-status="{{ $billable->subscription_status?->value }}">
                                <x-mollie-billing::admin.enum-badge :value="$billable->subscription_status" />
                            </span>
                        </div>
                        <flux:text class="text-zinc-600 dark:text-zinc-400">{{ $billable->getBillingEmail() }}</flux:text>
                    </div>
                </div>

            </div>
        </div>

        @php
            $planName = $this->planName();
            $planCode = $billable->subscription_plan_code;
            $intervalLabel = $billable->subscription_interval?->label();

            $planHintParts = [];
            if ($intervalLabel) { $planHintParts[] = $intervalLabel; }
            if ($planName && $planCode && $planName !== $planCode) {
                $planHintParts[] = $planCode;
            }
            $planHint = $planHintParts !== [] ? implode(' · ', $planHintParts) : null;

            $mandate = $this->mandate();
            $mandateMethodLabel = $mandate?->methodLabel();
            $mandateStatusLabel = $mandate?->statusLabel();
            $mandateAccount = $mandate?->accountDisplay();
            $mandateHolder = $mandate?->holder();
            $mandateExpiry = $mandate?->expiry();
            $mandateExpired = (bool) $mandate?->isExpired();
            $mandateExpiringSoon = (bool) $mandate?->isExpiringSoon();
        @endphp

        <div class="grid gap-4 md:grid-cols-3">
            <x-mollie-billing::admin.stat
                :label="__('billing::admin.common.plan')"
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
                    <flux:text size="xs" class="uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('billing::admin.billables.show.customer') }}</flux:text>
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
                            <span class="italic">{{ __('billing::admin.billables.show.no_address') }}</span>
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
                    <flux:text size="xs" class="uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('billing::admin.billables.show.mandate') }}</flux:text>
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
                        <flux:icon name="credit-card" variant="mini" class="text-zinc-400" />
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
                                {{ __('billing::portal.payment_method.status.valid') }}
                            </div>
                        @endif
                    </div>

                    {{-- Lower band: holder on the left, expiry on the right. Mirrors a physical card. --}}
                    @if ($mandateHolder || $mandateExpiry)
                        <div class="mt-3 flex items-end justify-between gap-3 text-xs">
                            @if ($mandateHolder)
                                <div class="min-w-0">
                                    <div class="text-[10px] font-medium uppercase tracking-[0.14em] text-zinc-400 dark:text-zinc-500">{{ __('billing::admin.billables.show.holder') }}</div>
                                    <div class="mt-0.5 truncate text-zinc-700 dark:text-zinc-200">{{ $mandateHolder }}</div>
                                </div>
                            @else
                                <div></div>
                            @endif
                            @if ($mandateExpiry)
                                <div class="text-right">
                                    <div class="text-[10px] font-medium uppercase tracking-[0.14em] text-zinc-400 dark:text-zinc-500">
                                        {{ $mandateExpired ? __('billing::admin.billables.show.expired') : __('billing::admin.billables.show.expires') }}
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
                    <div class="mt-3 text-2xl font-semibold text-zinc-700 dark:text-zinc-200">{{ __('billing::admin.billables.show.mandate_active') }}</div>
                    <flux:text size="xs" class="mt-1 text-zinc-500 dark:text-zinc-400">{{ __('billing::admin.billables.show.mandate_unavailable') }}</flux:text>
                    <div class="mt-auto pt-3 font-mono text-[11px] text-zinc-400 dark:text-zinc-500">{{ $billable->mollie_mandate_id }}</div>
                @else
                    <div class="mt-3 text-2xl font-semibold text-zinc-400 dark:text-zinc-500">{{ __('billing::admin.billables.show.mandate_none') }}</div>
                    <flux:text size="xs" class="mt-1 text-zinc-500 dark:text-zinc-400">{{ __('billing::admin.billables.show.mandate_none_description') }}</flux:text>
                @endif
            </flux:card>
        </div>

        <flux:tab.group>
            <flux:tabs>
                <flux:tab name="subscription" icon="arrow-path">{{ __('billing::admin.billables.show.tab.subscription') }}</flux:tab>
                <flux:tab name="invoices" icon="document-text">{{ __('billing::admin.billables.show.tab.invoices') }}</flux:tab>
                <flux:tab name="wallet" icon="wallet">{{ __('billing::admin.billables.show.tab.wallet') }}</flux:tab>
                <flux:tab name="audit" icon="clock">{{ __('billing::audit.tab_label') }}</flux:tab>
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

            <flux:tab.panel name="audit" class="pt-4">
                <livewire:mollie-billing::admin.billables.audit-tab :billable-id="$billable->getKey()" :key="'aud-'.$billable->getKey()" />
            </flux:tab.panel>
        </flux:tab.group>
    @endif
</div>
