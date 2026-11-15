<?php

use GraystackIT\MollieBilling\Concerns\InteractsWithFluxUi;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionGate;
use GraystackIT\MollieBilling\Services\Billing\ResubscribeSubscription;
use GraystackIT\MollieBilling\Services\Vat\CountryMatchService;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use Livewire\Component;

new class extends Component {
    use InteractsWithFluxUi;
    public ?string $flash = null;
    public bool $flashError = false;
    public string $couponCode = '';
    public ?string $couponMessage = null;
    public bool $couponMessageError = false;

    // Country-mismatch self-service modal
    public string $fixCountry = '';
    public string $fixStreet = '';
    public string $fixPostalCode = '';
    public string $fixCity = '';
    public bool $fixConfirmed = false;
    public ?string $fixError = null;

    private function resolveBillable(): ?Billable
    {
        return MollieBilling::resolveBillable(request());
    }

    /**
     * Livewire actions bypass the portal route middleware, so every action below
     * that touches the billable authorizes here. Without it a member the host app
     * denies billing management to could still cancel the tenant's subscription,
     * end its trial or rewrite its billing address straight from the dashboard.
     */
    private function authorizeBillingAction(Billable $billable): void
    {
        abort_unless(MollieBilling::authorizes(request(), $billable), 403);
    }

    /**
     * The same gate for the read path, which the hardening above skipped.
     *
     * The portal routes do carry `billing.portal`, and AuthorizeBillingPortal is
     * registered as Livewire persistent middleware so it re-runs on
     * `/livewire/update` too. This gate is defence-in-depth behind both, because a
     * component that answers questions about a tenant must not depend on its
     * mounting being right — it decides for itself who gets to read the tenant's plan, address,
     * invoices and wallet balances. Withholding the billable renders the same
     * "no billable" state as an unresolvable tenant, while the actions keep resolving it
     * unguarded so they can still answer 403.
     */
    private function readableBillable(): ?Billable
    {
        $billable = $this->resolveBillable();

        return $billable !== null && MollieBilling::authorizes(request(), $billable)
            ? $billable
            : null;
    }

    public function openCountryFix(): void
    {
        $billable = $this->resolveBillable();
        if (! $billable) return;

        $this->authorizeBillingAction($billable);

        $this->fixCountry = (string) ($billable->getBillingCountry() ?? '');
        $this->fixStreet = (string) ($billable->getBillingStreet() ?? '');
        $this->fixPostalCode = (string) ($billable->getBillingPostalCode() ?? '');
        $this->fixCity = (string) ($billable->getBillingCity() ?? '');
        $this->fixConfirmed = false;
        $this->fixError = null;
    }

    public function saveCountryFix(CountryMatchService $service): void
    {
        $this->fixError = null;

        $billable = $this->resolveBillable();
        if (! $billable) {
            $this->fixError = __('billing::portal.no_billable');
            return;
        }

        $this->authorizeBillingAction($billable);

        $mismatch = $billable->latestOpenCountryMismatch();
        if ($mismatch === null) {
            $this->closeModal('fix-country');
            return;
        }

        if (! $this->fixConfirmed) {
            $this->fixError = __('billing::portal.country_mismatch.confirm_required');
            return;
        }

        $this->validate([
            'fixStreet' => ['required', 'string', 'max:255'],
            'fixPostalCode' => ['required', 'string', 'max:20'],
            'fixCity' => ['required', 'string', 'max:255'],
            'fixCountry' => ['required', 'string', 'size:2'],
        ]);

        $newCountry = strtoupper($this->fixCountry);
        $allowed = array_values(array_filter([
            $mismatch->tax_country_payment ? strtoupper((string) $mismatch->tax_country_payment) : null,
            $mismatch->tax_country_ip ? strtoupper((string) $mismatch->tax_country_ip) : null,
        ]));

        if (! in_array($newCountry, $allowed, true)) {
            $this->fixError = __('billing::portal.country_mismatch.invalid_country');
            return;
        }

        /** @var \Illuminate\Database\Eloquent\Model&Billable $billable */
        $billable->forceFill([
            'billing_street' => $this->fixStreet,
            'billing_postal_code' => $this->fixPostalCode,
            'billing_city' => $this->fixCity,
        ])->save();

        try {
            $service->resolve(
                $billable,
                $mismatch,
                $newCountry,
                request()->user()?->getKey(),
            );
        } catch (\Throwable $e) {
            report($e);
            $this->fixError = __('billing::portal.country_mismatch.resolve_failed');
            return;
        }

        $this->closeModal('fix-country');
        $this->flash = __('billing::portal.country_mismatch.resolved_flash');
        $this->flashError = false;
    }

    public function reactivateAfterMismatch(ResubscribeSubscription $service): void
    {
        $billable = $this->resolveBillable();
        if (! $billable) return;

        $this->authorizeBillingAction($billable);
        try {
            $service->handle($billable);
            $this->flash = __('billing::portal.flash.resubscribed');
            $this->flashError = false;
        } catch (\Throwable $e) {
            report($e);
            $this->flash = __('billing::portal.country_mismatch.reactivate_failed');
            $this->flashError = true;
        }
    }

    public function cancelSubscription(): void
    {
        $billable = $this->resolveBillable();
        if (! $billable) return;

        $this->authorizeBillingAction($billable);
        try {
            $billable->cancelBillingSubscription();
            $this->flash = __('billing::portal.flash.cancelled');
            $this->flashError = false;
        } catch (\Throwable $e) {
            report($e);
            $this->flash = __('billing::portal.flash.error');
            $this->flashError = true;
        }
    }

    public function endTrial(): void
    {
        $billable = $this->resolveBillable();
        if (! $billable) {
            return;
        }

        // Authorize FIRST, like every other action here: a 403 for "on trial" and a silent
        // 200 for "not on trial" told an unauthorized team member the tenant's trial state,
        // which the read gate otherwise withholds entirely.
        $this->authorizeBillingAction($billable);

        if (! $billable->isOnBillingTrial()) {
            return;
        }
        try {
            // Immediate cancel: trial ends now, Mollie subscription cancelled,
            // status flips to Cancelled with subscription_ends_at = now. The
            // user did not pay, so there is no grace period to grant access
            // against — `hasAccessibleBillingSubscription()` returns false from
            // the next request on, but the billing portal itself stays
            // reachable so the user can pick a new plan.
            $billable->cancelBillingSubscription(immediately: true);
            $this->flash = __('billing::portal.flash.trial_ended');
            $this->flashError = false;
        } catch (\Throwable $e) {
            report($e);
            $this->flash = __('billing::portal.flash.error');
            $this->flashError = true;
        }
    }

    public function resubscribe(): void
    {
        $billable = $this->resolveBillable();
        if (! $billable) return;

        $this->authorizeBillingAction($billable);
        try {
            $billable->resubscribeBillingPlan();
            $this->flash = __('billing::portal.flash.resubscribed');
            $this->flashError = false;
        } catch (\Throwable $e) {
            report($e);
            $this->flash = __('billing::portal.flash.error');
            $this->flashError = true;
        }
    }

    public function forceImmediateMollieCharge(MollieSubscriptionGate $gate): void
    {
        $billable = $this->resolveBillable();
        if (! $billable) return;

        $this->authorizeBillingAction($billable);

        // Re-checked here, not just in the render that drew the button: the dashboard has
        // no wire:poll, so the banner can be arbitrarily stale. Between the render and the
        // click Mollie can charge and the webhook activate the subscription — and
        // forceImmediateCharge() looks at neither status nor start date, so the click
        // PATCHed startDate to today on an Active subscription and collected a second full
        // period for one already paid. Same shape as the admin past-due list's retry().
        if (! $billable->isBillingPastDue()) {
            $this->flash = __('billing::portal.flash.force_charge_no_longer_needed');
            $this->flashError = true;

            return;
        }

        if ($gate->forceImmediateCharge($billable)) {
            $this->flash = __('billing::portal.flash.force_charge_scheduled');
            $this->flashError = false;
        } else {
            $this->flash = __('billing::portal.flash.error');
            $this->flashError = true;
        }
    }

    public function cancelScheduledChange(): void
    {
        $billable = $this->resolveBillable();
        if (! $billable) return;

        $this->authorizeBillingAction($billable);
        try {
            app(\GraystackIT\MollieBilling\Services\Billing\UpdateSubscription::class)
                ->cancelScheduledChange($billable);
            $this->flash = __('billing::portal.flash.scheduled_cancelled');
            $this->flashError = false;
        } catch (\Throwable $e) {
            report($e);
            $this->flash = __('billing::portal.flash.error');
            $this->flashError = true;
        }
    }

    /**
     * One field for every kind of code. What a code does — and why it may not work here —
     * is decided by RedeemCouponFromPortal; this only authorises, calls and shows.
     */
    public function redeemCoupon(\GraystackIT\MollieBilling\Services\Billing\RedeemCouponFromPortal $redeem): void
    {
        $this->couponMessage = null;
        $this->couponMessageError = false;

        if (trim($this->couponCode) === '') {
            return;
        }

        $billable = $this->resolveBillable();
        if (! $billable) {
            $this->couponMessage = __('billing::portal.coupon_redeem_failed');
            $this->couponMessageError = true;
            return;
        }

        $this->authorizeBillingAction($billable);

        try {
            $outcome = $redeem->handle($billable, $this->couponCode);
        } catch (\Throwable $e) {
            report($e);
            $this->couponMessage = __('billing::portal.coupon_redeem_failed');
            $this->couponMessageError = true;
            return;
        }

        $this->couponMessage = $outcome->message;
        $this->couponMessageError = ! $outcome->redeemed;

        if ($outcome->redeemed) {
            $this->couponCode = '';
        }
    }

    /**
     * @return array{until: string, on_expiry: string}|null
     */
    private function resolvePlanWaiver(Billable $b): ?array
    {
        $marker = app(\GraystackIT\MollieBilling\Services\Billing\PlanWaiver::class)->marker($b);

        if ($marker === null || empty($marker['valid_until'])) {
            return null;
        }

        try {
            $until = \Carbon\Carbon::parse((string) $marker['valid_until']);
        } catch (\Throwable) {
            return null;
        }

        if (! $until->isFuture()) {
            return null;
        }

        return [
            'until' => (string) BillingTime::display($until, $b)?->translatedFormat('d. M Y'),
            'on_expiry' => \GraystackIT\MollieBilling\Services\Billing\PlanWaiver::normalizeOnExpiry($marker['on_expiry'] ?? null),
        ];
    }

    public function dashboardData(?Billable $billable): array
    {
        $b = $billable;
        if (! $b) return [];

        $status = $b->getBillingSubscriptionStatus();
        $planCode = $b->getBillingSubscriptionPlanCode();
        $interval = $b->getBillingSubscriptionInterval();
        $addonCodes = $b->getActiveBillingAddonCodes();

        $catalog = app(SubscriptionCatalogInterface::class);

        $usageTypes = [];
        if ($planCode) {
            foreach ($catalog->includedUsages($planCode, $interval) as $type => $included) {
                $wallet = $b->getWallet((string) $type);
                $usageTypes[] = [
                    'type' => (string) $type,
                    'label' => $catalog->usageTypeName((string) $type),
                    // What was credited, not what the plan lists: a trial gets the
                    // prorated share, and measuring against the full quota rendered
                    // the difference as consumption on a subscription minutes old.
                    'included' => $b->grantedBillingQuota((string) $type),
                    'trial' => $status === SubscriptionStatus::Trial,
                    'balance' => (int) ($wallet?->balanceInt ?? 0),
                    'purchased_balance' => $wallet !== null
                        ? WalletUsageService::getPurchasedBalance($wallet)
                        : 0,
                ];
            }
        }

        // Each row renders in its own invoice currency — the billable may have
        // been charged in more than one over time.
        $invoices = $b->billingInvoices()->latest()->limit(5)->get()->map(fn ($inv) => [
            'date' => BillingTime::display($inv->created_at, $b)->translatedFormat('d. M Y'),
            'kind' => $inv->invoice_kind?->label() ?? '—',
            'kindColor' => $inv->invoice_kind?->color() ?? 'zinc',
            'net' => BillingMoney::format($inv->amount_net, $inv->currency),
            'vat' => BillingMoney::format($inv->amount_vat, $inv->currency),
            'gross' => BillingMoney::format($inv->amount_gross, $inv->currency),
            'status' => $inv->status->label(),
            'statusColor' => $inv->status->color(),
            'pdfUrl' => $inv->hasPdf() ? $inv->getDownloadUrl() : null,
        ])->all();

        // Through the catalog, never the config file: apps may rebind
        // SubscriptionCatalogInterface to a DB-backed catalog (where this config is empty),
        // and addonName() also prefers the `billing::addons.{code}.name` translation. This
        // was the one screen that rendered raw codes and untranslated names.
        $addonLabels = array_map(
            fn (string $code) => $catalog->addonName($code) ?? $code,
            $addonCodes,
        );

        // PastDue but Mollie still has a live subscription (e.g. trial expired
        // before the first charge fell due). Surface the scheduled charge date
        // and offer a "charge now" button instead of the generic past-due
        // banner — the user has nothing to fix; the money is on its way.
        $pendingMollieCharge = null;
        if ($status === SubscriptionStatus::PastDue) {
            $snapshot = app(MollieSubscriptionGate::class)->snapshot($b);
            if ($snapshot !== null
                && $snapshot->startDate !== null
                && $snapshot->startDate->isFuture()
            ) {
                $pendingMollieCharge = [
                    'date' => BillingTime::display($snapshot->startDate, $b)?->translatedFormat('d. M Y') ?? '—',
                ];
            }
        }

        $openMismatch = $b->latestOpenCountryMismatch();
        // Only signals the package can actually invoice. A UK-issued card or a Swiss IP
        // is an ordinary signal, and offering it let the customer resolve to a country
        // `VatCalculationService` refuses — which refunded every invoice and then failed
        // to re-charge, silently. `resolve()` rejects such a country too; filtering here
        // is what keeps it from being presented as a choice in the first place.
        $allowedFixCountries = $openMismatch !== null
            ? array_values(array_filter(array_unique(array_filter([
                $openMismatch->tax_country_payment ? strtoupper((string) $openMismatch->tax_country_payment) : null,
                $openMismatch->tax_country_ip ? strtoupper((string) $openMismatch->tax_country_ip) : null,
            ])), fn (string $country): bool => app(VatCalculationService::class)->isInvoiceable($country)))
            : [];
        $resolvedMismatch = $b->latestResolvedCountryMismatch();
        $endsAt = $b->getBillingSubscriptionEndsAt();
        $needsReactivate = $status === SubscriptionStatus::Cancelled
            && $openMismatch === null
            && $resolvedMismatch !== null
            && $endsAt !== null
            && $resolvedMismatch->resolved_at !== null
            && $resolvedMismatch->resolved_at->lessThanOrEqualTo($endsAt);
        $reactivateInGrace = $endsAt?->isFuture() ?? false;

        $invoicesUnderMismatch = $openMismatch !== null
            ? $b->billingInvoices()
                ->where('mismatch_id', $openMismatch->id)
                ->where('amount_net', '>', 0)
                ->get()
            : collect();
        $mismatchInvoiceCount = $invoicesUnderMismatch->count();

        // Every one of these invoices is about to be refunded and reissued, so the
        // figure has to name the same money the refunds will move. Grouping by the
        // invoice's own currency is what keeps that true: a single sum rendered with one
        // symbol turned 20.00 GBP plus 10.00 EUR into "€30.00", an amount nobody is
        // owed. The `:count` above stays a count over all of them.
        $mismatchInvoiceTotal = $invoicesUnderMismatch
            ->groupBy(fn ($invoice): string => BillingMoney::normalizeCurrency($invoice->currency))
            // The query is unordered, so without this the two amounts swap places between
            // renders of the same data.
            ->sortKeys()
            ->map(fn ($group, string $currency): string => BillingMoney::format(
                (int) $group->sum('amount_gross'),
                $currency,
            ))
            ->join(' + ');

        return [
            'status' => $status,
            'statusLabel' => $status->label(),
            'statusColor' => $status->color(),
            'mismatch' => $openMismatch !== null ? [
                'id' => $openMismatch->id,
                'user' => (string) ($openMismatch->tax_country_user ?? '-'),
                'payment' => (string) ($openMismatch->tax_country_payment ?? '-'),
                'ip' => (string) ($openMismatch->tax_country_ip ?? '-'),
                'allowed_countries' => $allowedFixCountries,
                'invoice_count' => $mismatchInvoiceCount,
                'invoice_total_gross' => $mismatchInvoiceTotal !== ''
                    ? $mismatchInvoiceTotal
                    : BillingMoney::format(0),
            ] : null,
            'needsReactivate' => $needsReactivate,
            'reactivateInGrace' => $reactivateInGrace,
            'hasPendingCorrections' => $b->hasPendingCountryCorrections(),
            'accentClass' => match($status) {
                SubscriptionStatus::Active => 'bg-emerald-500',
                SubscriptionStatus::Trial => 'bg-amber-400',
                SubscriptionStatus::PastDue => 'bg-red-500',
                default => 'bg-zinc-300 dark:bg-zinc-600',
            },
            'hasSubscription' => $planCode !== null,
            'planName' => $b->getCurrentBillingPlanName() ?? __('billing::portal.no_subscription'),
            'interval' => $interval,
            'nextBilling' => BillingTime::display(
                $status === SubscriptionStatus::Trial ? $b->getBillingTrialEndsAt() : $b->nextBillingDate(),
                $b,
            )?->translatedFormat('d. M Y') ?? '—',
            'periodStart' => BillingTime::display($b->getBillingPeriodStartsAt(), $b)?->translatedFormat('d. M Y') ?? '—',
            'seatCount' => $b->getBillingSeatCount(),
            'includedSeats' => $b->getIncludedBillingSeats(),
            'addonCount' => count($addonCodes),
            'addonLabels' => $addonLabels,
            'isTrial' => $status === SubscriptionStatus::Trial,
            'isPastDue' => $status === SubscriptionStatus::PastDue,
            'pendingMollieCharge' => $pendingMollieCharge,
            'isActive' => $status === SubscriptionStatus::Active,
            'isCancelled' => $status === SubscriptionStatus::Cancelled,
            'trialEnds' => BillingTime::display($b->getBillingTrialEndsAt(), $b)?->translatedFormat('d. M Y') ?? '—',
            'subscriptionEnds' => BillingTime::display($b->getBillingSubscriptionEndsAt(), $b)?->translatedFormat('d. M Y'),
            'subscriptionEndsFuture' => $b->getBillingSubscriptionEndsAt()?->isFuture() ?? false,
            // AccessGrant: a Local subscription with a finite end date that is
            // still in the future and not (yet) cancelled. Free Local plans have
            // no end date; cancelled subs are surfaced via the cancelled banner.
            'isAccessGrant' => $b->isLocalBillingSubscription()
                && $status !== SubscriptionStatus::Cancelled
                && ($b->getBillingSubscriptionEndsAt()?->isFuture() ?? false),
            // A plan given for free on a subscription that pays for its extras — see
            // PlanWaiver. The customer has to be told both halves: until when the plan
            // is free, and what happens then.
            'planWaiver' => $this->resolvePlanWaiver($b),
            // Date AND amount of the next charge, from the sources the charge is built from.
            'nextCharge' => app(\GraystackIT\MollieBilling\Services\Billing\NextChargeSummary::class)->for($b),
            'scheduledChange' => $this->resolveScheduledChange($b),
            'usageTypes' => $usageTypes,
            'invoices' => $invoices,
        ];
    }
    private function resolveScheduledChange(Billable $b): ?array
    {
        $meta = $b->getBillingSubscriptionMeta();
        $sc = $meta['scheduled_change'] ?? null;
        if ($sc === null) {
            return null;
        }

        $catalog = app(SubscriptionCatalogInterface::class);
        $planCode = $sc['plan_code'] ?? null;

        return [
            'planName' => $planCode ? ($catalog->planName($planCode) ?? $planCode) : null,
            'interval' => $sc['interval'] ?? null,
            'scheduledAt' => isset($sc['scheduled_at'])
                ? BillingTime::display(\Carbon\Carbon::parse((string) $sc['scheduled_at'])->setTimezone('UTC'), $b)->translatedFormat('d. M Y')
                : null,
        ];
    }
};

?>

@php
    $billable = $this->readableBillable();
    $d = $this->dashboardData($billable);
@endphp

<div class="space-y-6">
    {{-- Page header --}}
    <div>
        <flux:heading size="xl">{{ __('billing::portal.dashboard') }}</flux:heading>
        <flux:subheading>
            {{ __('billing::portal.dashboard_subtitle', ['name' => $billable?->getBillingName() ?? '']) }}
        </flux:subheading>
    </div>

    @if ($flash)
        <flux:callout variant="{{ $flashError ? 'danger' : 'success' }}" icon="{{ $flashError ? 'exclamation-triangle' : 'check-circle' }}">{{ $flash }}</flux:callout>
    @endif

    @if (! $billable)
        <flux:callout variant="warning" icon="exclamation-triangle">
            {{ __('billing::portal.no_billable') }}
        </flux:callout>
    @elseif (! empty($d))

        {{-- Status banners --}}
        @if (! $d['hasSubscription'])
            <flux:callout icon="information-circle" color="zinc" inline>
                {{ __('billing::portal.no_subscription_banner') }}
            </flux:callout>
        @elseif ($d['isTrial'])
            <flux:callout icon="clock" color="amber" inline>
                {{ __('billing::portal.trial_banner', ['date' => $d['trialEnds']]) }}
            </flux:callout>
        @elseif ($d['isPastDue'] && $d['pendingMollieCharge'])
            <flux:callout icon="clock" color="amber" inline>
                <div class="flex items-center justify-between gap-4 w-full">
                    <span>{{ __('billing::portal.pending_mollie_charge_banner', ['date' => $d['pendingMollieCharge']['date']]) }}</span>
                    <flux:button size="sm" variant="primary" wire:click="forceImmediateMollieCharge">
                        {{ __('billing::portal.force_charge_now') }}
                    </flux:button>
                </div>
            </flux:callout>
        @elseif ($d['isPastDue'])
            <flux:callout icon="exclamation-triangle" color="red" inline>
                {{ __('billing::portal.past_due_banner') }}
            </flux:callout>
        @elseif ($d['isCancelled'] && $d['subscriptionEndsFuture'])
            <flux:callout icon="information-circle" color="zinc" inline>
                {{ __('billing::portal.cancelled_banner', ['date' => $d['subscriptionEnds']]) }}
            </flux:callout>
        @endif

        @if ($d['scheduledChange'])
            <flux:callout icon="calendar" color="amber" inline>
                <div class="flex items-center justify-between gap-4 w-full">
                    <span>{{ __('billing::portal.scheduled_change_banner', [
                        'date' => $d['scheduledChange']['scheduledAt'],
                        'plan' => $d['scheduledChange']['planName'],
                        'interval' => $d['scheduledChange']['interval'] === 'monthly' ? __('billing::portal.interval_monthly') : __('billing::portal.interval_yearly'),
                    ]) }}</span>
                    <flux:button size="xs" variant="ghost" wire:click="cancelScheduledChange">
                        {{ __('billing::portal.scheduled_change_cancel') }}
                    </flux:button>
                </div>
            </flux:callout>
        @endif

        {{-- Country mismatch: Pending --}}
        @if ($d['mismatch'])
            <flux:callout variant="danger" icon="exclamation-triangle">
                <flux:callout.heading>{{ __('billing::portal.country_mismatch.banner_heading') }}</flux:callout.heading>
                <flux:callout.text>
                    <p>{{ __('billing::portal.country_mismatch.banner_text') }}</p>

                    <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                        <span class="inline-flex items-center gap-1.5 rounded-md bg-red-50 px-2 py-1 font-medium text-red-800 ring-1 ring-inset ring-red-200/70 dark:bg-red-500/10 dark:text-red-200 dark:ring-red-400/20">
                            <flux:icon.user class="size-3.5 opacity-70" />
                            <span class="opacity-75">{{ __('billing::portal.country_mismatch.label_user') }}</span>
                            <span class="font-mono font-semibold tracking-wide">{{ $d['mismatch']['user'] }}</span>
                        </span>
                        <flux:icon.arrow-long-right class="size-4 text-red-400/70 dark:text-red-300/60" />
                        <span class="inline-flex items-center gap-1.5 rounded-md bg-red-50 px-2 py-1 font-medium text-red-800 ring-1 ring-inset ring-red-200/70 dark:bg-red-500/10 dark:text-red-200 dark:ring-red-400/20">
                            <flux:icon.credit-card class="size-3.5 opacity-70" />
                            <span class="opacity-75">{{ __('billing::portal.country_mismatch.label_payment') }}</span>
                            <span class="font-mono font-semibold tracking-wide">{{ $d['mismatch']['payment'] }}</span>
                        </span>
                        <span class="inline-flex items-center gap-1.5 rounded-md bg-red-50 px-2 py-1 font-medium text-red-800 ring-1 ring-inset ring-red-200/70 dark:bg-red-500/10 dark:text-red-200 dark:ring-red-400/20">
                            <flux:icon.globe-alt class="size-3.5 opacity-70" />
                            <span class="opacity-75">{{ __('billing::portal.country_mismatch.label_ip') }}</span>
                            <span class="font-mono font-semibold tracking-wide">{{ $d['mismatch']['ip'] }}</span>
                        </span>
                    </div>

                    <p class="mt-3 text-sm">{{ __('billing::portal.country_mismatch.banner_consequence') }}</p>
                    <p class="mt-1 text-sm">{{ __('billing::portal.country_mismatch.banner_blocked_actions') }}</p>
                </flux:callout.text>
                <x-slot name="actions">
                    <flux:button size="sm" variant="primary" wire:click="openCountryFix" x-on:click="$flux.modal('fix-country').show()">
                        {{ __('billing::portal.country_mismatch.fix_cta') }}
                    </flux:button>
                </x-slot>
            </flux:callout>
        @endif

        {{-- Country mismatch: Resolved but subscription still cancelled --}}
        @if (! $d['mismatch'] && $d['needsReactivate'])
            <flux:callout variant="warning" icon="information-circle">
                <flux:callout.heading>{{ __('billing::portal.country_mismatch.reactivate_heading') }}</flux:callout.heading>
                <flux:callout.text>
                    @if ($d['reactivateInGrace'])
                        {{ __('billing::portal.country_mismatch.reactivate_text_grace') }}
                    @else
                        {{ __('billing::portal.country_mismatch.reactivate_text_expired') }}
                    @endif
                </flux:callout.text>
                <x-slot name="actions">
                    @if ($d['reactivateInGrace'])
                        <flux:button size="sm" variant="primary" wire:click="reactivateAfterMismatch">
                            {{ __('billing::portal.country_mismatch.reactivate_cta') }}
                        </flux:button>
                    @else
                        <flux:button size="sm" variant="primary" :href="route(BillingRoute::checkout())">
                            {{ __('billing::portal.country_mismatch.reactivate_checkout_cta') }}
                        </flux:button>
                    @endif
                </x-slot>
            </flux:callout>
        @endif

        {{-- Country correction re-charge in progress --}}
        @if ($d['hasPendingCorrections'])
            <flux:callout variant="secondary" icon="arrow-path">
                {{ __('billing::portal.country_mismatch.recharge_pending') }}
            </flux:callout>
        @endif

        {{-- Subscription overview --}}
        <flux:card class="relative overflow-hidden p-0!">
            <div class="absolute inset-x-0 top-0 "></div>

            <div class="flex flex-col gap-4 px-6 pb-6 pt-8 sm:flex-row sm:items-start sm:justify-between">
                <div class="space-y-1 mb-5">
                    <flux:subheading>{{ __('billing::portal.current_plan') }}</flux:subheading>
                    <div class="flex items-center gap-3">
                        <flux:heading size="xl">{{ $d['planName'] }}</flux:heading>
                        @if ($d['hasSubscription'])
                            <flux:badge size="sm" :color="$d['statusColor']">{{ $d['statusLabel'] }}</flux:badge>
                        @endif
                    </div>
                    @if ($d['interval'])
                        <flux:text class="text-sm text-zinc-500">
                            {{ $d['interval'] === 'monthly' ? __('billing::portal.interval_monthly') : __('billing::portal.interval_yearly') }}
                        </flux:text>
                    @endif
                </div>

                <div class="flex flex-wrap gap-2">
                    <flux:button size="sm" variant="primary" href="{{ route(BillingRoute::name('plan'), MollieBilling::resolveUrlParameters($billable)) }}">
                        {{ __('billing::portal.plan_change') }}
                    </flux:button>
                    @if ($d['isActive'])
                        <flux:modal.trigger name="cancel-subscription">
                            <flux:button size="sm" variant="ghost" icon="x-circle">{{ __('billing::portal.cancel_subscription') }}</flux:button>
                        </flux:modal.trigger>
                    @elseif ($d['isTrial'])
                        <flux:modal.trigger name="end-trial">
                            <flux:button size="sm" variant="ghost" icon="x-circle">{{ __('billing::portal.end_trial') }}</flux:button>
                        </flux:modal.trigger>
                    @elseif ($d['isCancelled'] && $d['subscriptionEndsFuture'])
                        <flux:button size="sm" variant="primary" icon="arrow-path" wire:click="resubscribe">{{ __('billing::portal.resubscribe') }}</flux:button>
                    @endif
                </div>
            </div>

            @if (! empty($d['planWaiver']))
                <div class="border-t border-zinc-200/75 px-6 py-4 dark:border-zinc-700/50">
                    <flux:callout icon="gift" color="teal" inline>
                        {{ __('billing::portal.plan_waiver_notice_'.$d['planWaiver']['on_expiry'], ['date' => $d['planWaiver']['until']]) }}
                    </flux:callout>
                </div>
            @endif

            @if ($d['hasSubscription'] && ! empty($d['nextCharge']))
                <div class="border-t border-zinc-200/75 px-6 py-5 dark:border-zinc-700/50">
                    <x-mollie-billing::next-charge :summary="$d['nextCharge']" :billable="$billable" class="max-w-md" />
                </div>
            @endif

            @if ($d['hasSubscription'])
                <div class="border-t border-zinc-200/75 bg-zinc-50/50 px-6 py-5 dark:border-zinc-700/50 dark:bg-white/[0.02]">
                    <div class="grid grid-cols-2 gap-x-8 gap-y-5 sm:grid-cols-4">
                        <div class="mb-3">
                            @if ($d['isAccessGrant'])
                                <flux:subheading size="sm" class="text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.access_grant_valid_until') }}</flux:subheading>
                                <flux:text class="mt-1 font-semibold">{{ $d['subscriptionEnds'] }}</flux:text>
                            @else
                                <flux:subheading size="sm" class="text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.next_billing') }}</flux:subheading>
                                @if ($billable && $billable->isLocalBillingSubscription() && $d['status']?->value !== 'cancelled')
                                    <flux:text class="mt-1 font-semibold text-zinc-500 dark:text-zinc-400">{{ __('billing::portal.free_plan_recurring_charge') }}</flux:text>
                                @else
                                    <flux:text class="mt-1 font-semibold">{{ $d['nextBilling'] }}</flux:text>
                                @endif
                            @endif
                        </div>
                        <div class="mb-3">
                            <flux:subheading size="sm" class="text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.period_start') }}</flux:subheading>
                            <flux:text class="mt-1 font-semibold">{{ $d['periodStart'] }}</flux:text>
                        </div>
                        @if ($d['includedSeats'] > 0 || $d['seatCount'] > 0)
                            <div class="mb-3">
                                <flux:subheading size="sm" class="text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.seats') }}</flux:subheading>
                                <flux:text class="mt-1 font-semibold">{{ $d['seatCount'] }} <span class="font-normal text-zinc-400">({{ $d['includedSeats'] }} {{ __('billing::portal.included') }})</span></flux:text>
                            </div>
                        @endif
                        <div class="mb-3">
                            <flux:subheading size="sm" class="text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.addons') }}</flux:subheading>
                            <flux:text class="mt-1 font-semibold">{{ $d['addonCount'] > 0 ? $d['addonCount'] . ' ' . __('billing::portal.active') : '—' }}</flux:text>
                        </div>
                    </div>

                    @if ($d['addonCount'] > 0)
                        <div class="mt-5 flex flex-wrap gap-1.5">
                            @foreach ($d['addonLabels'] as $label)
                                <flux:badge size="sm" color="zinc">{{ $label }}</flux:badge>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        </flux:card>

        {{-- Redeem coupon --}}
        <flux:card class="p-6">
            <div class="flex items-start gap-3">
                <div class="flex size-10 items-center justify-center rounded-full bg-accent/10">
                    <flux:icon.ticket class="size-5 text-accent" />
                </div>
                <div class="flex-1">
                    <flux:heading size="lg">{{ __('billing::portal.coupon_redeem_title') }}</flux:heading>
                    <flux:text class="text-sm text-zinc-500">{{ __('billing::portal.coupon_redeem_subtitle') }}</flux:text>
                </div>
            </div>

            <div class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-start">
                <flux:input
                    wire:model="couponCode"
                    :placeholder="__('billing::portal.coupon_code_placeholder')"
                    class="flex-1"
                />
                <flux:button variant="primary" wire:click="redeemCoupon">
                    {{ __('billing::portal.coupon_redeem_button') }}
                </flux:button>
            </div>

            @if ($couponMessage)
                <flux:text class="mt-3 text-sm {{ $couponMessageError ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                    {{ $couponMessage }}
                </flux:text>
            @endif
        </flux:card>

        {{-- Usage meters --}}
        @if (count($d['usageTypes']) > 0)
            <div class="space-y-4">
                <flux:heading size="lg">{{ __('billing::portal.usage') }}</flux:heading>
                {{-- An inline grid-template, not an interpolated utility class: Tailwind only
                     emits classes it can see as complete strings, so `lg:grid-cols-{{ n }}`
                     was never compiled and three meters silently fell back to two per row.
                     `auto-fit` also drops the breakpoints — the meters wrap on width rather
                     than on a guessed column count. plan-change.blade.php uses the same
                     inline-template approach. --}}
                <div class="grid gap-4" style="grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr))">
                    @foreach ($d['usageTypes'] as $usage)
                        <livewire:mollie-billing::components.usage-meter
                            :type="$usage['type']"
                            :label="$usage['label']"
                            :included="$usage['included']"
                            :balance="$usage['balance']"
                            :purchased-balance="$usage['purchased_balance']"
                            :trial="$usage['trial']"
                            {{-- The balances belong in the key. A child component keeps its own
                                 state across a parent re-render, so with a fixed key the meter
                                 kept showing the numbers it first mounted with: redeeming a
                                 credits coupon reported success while the meter still displayed
                                 the old balance, and only a full page load revealed the credits
                                 the customer had just been told they received. --}}
                            :key="'usage-'.$usage['type'].'-'.$usage['balance'].'-'.$usage['purchased_balance']"
                        />
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Recent invoices --}}
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">{{ __('billing::portal.recent_invoices') }}</flux:heading>
                <flux:button size="sm" variant="ghost" href="{{ route(BillingRoute::name('invoices'), MollieBilling::resolveUrlParameters($billable)) }}" icon:trailing="arrow-right">
                    {{ __('billing::portal.view_all_invoices') }}
                </flux:button>
            </div>

            <flux:card class="py-0! overflow-hidden">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('billing::portal.invoice.date') }}</flux:table.column>
                        <flux:table.column>{{ __('billing::portal.invoice.kind') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('billing::portal.invoice.net') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('billing::portal.invoice.vat') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('billing::portal.invoice.gross') }}</flux:table.column>
                        <flux:table.column>{{ __('billing::portal.invoice.status') }}</flux:table.column>
                        <flux:table.column align="end"></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($d['invoices'] as $inv)
                            <flux:table.row>
                                <flux:table.cell class="tabular-nums">{{ $inv['date'] }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" :color="$inv['kindColor']">{{ $inv['kind'] }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell class="text-right tabular-nums">{{ $inv['net'] }}</flux:table.cell>
                                <flux:table.cell class="text-right tabular-nums text-zinc-400">{{ $inv['vat'] }}</flux:table.cell>
                                <flux:table.cell class="text-right tabular-nums font-medium">{{ $inv['gross'] }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" :color="$inv['statusColor']">{{ $inv['status'] }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell class="text-right">
                                    @if ($inv['pdfUrl'])
                                        <flux:button size="xs" variant="ghost" icon="arrow-down-tray" href="{{ $inv['pdfUrl'] }}" target="_blank">
                                            {{ __('billing::portal.invoice.pdf') }}
                                        </flux:button>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="7" class="text-center text-zinc-400">{{ __('billing::portal.no_invoices') }}</flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </flux:card>
        </div>

        {{-- Cancel modal --}}
        <flux:modal name="cancel-subscription" class="max-w-md">
            <div class="space-y-6">
                <div class="space-y-2">
                    <flux:heading size="lg">{{ __('billing::portal.cancel_confirm.title') }}</flux:heading>
                    <flux:text>{{ __('billing::portal.cancel_confirm.body') }}</flux:text>
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('billing::portal.cancel_confirm.keep') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="danger" wire:click="cancelSubscription" x-on:click="$flux.modal('cancel-subscription').close()">
                        {{ __('billing::portal.cancel_confirm.confirm') }}
                    </flux:button>
                </div>
            </div>
        </flux:modal>

        {{-- End trial modal --}}
        <flux:modal name="end-trial" class="max-w-md">
            <div class="space-y-6">
                <div class="space-y-2">
                    <flux:heading size="lg">{{ __('billing::portal.end_trial_confirm.title') }}</flux:heading>
                    <flux:text>{{ __('billing::portal.end_trial_confirm.body') }}</flux:text>
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('billing::portal.end_trial_confirm.keep') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="danger" wire:click="endTrial" x-on:click="$flux.modal('end-trial').close()">
                        {{ __('billing::portal.end_trial_confirm.confirm') }}
                    </flux:button>
                </div>
            </div>
        </flux:modal>

        {{-- Country mismatch self-service modal --}}
        @if ($d['mismatch'])
            <flux:modal name="fix-country" class="max-w-xl">
                <form wire:submit.prevent="saveCountryFix" class="space-y-6">
                    <div class="space-y-2">
                        <flux:heading size="lg">{{ __('billing::portal.country_mismatch.modal_title') }}</flux:heading>
                        <flux:text>{{ __('billing::portal.country_mismatch.modal_intro') }}</flux:text>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model="fixStreet" :label="__('billing::portal.billing_data.street')" />
                        <flux:input wire:model="fixPostalCode" :label="__('billing::portal.billing_data.postal_code')" />
                        <flux:input wire:model="fixCity" :label="__('billing::portal.billing_data.city')" />
                        <flux:select wire:model="fixCountry" :label="__('billing::portal.billing_data.country')">
                            <option value="">— {{ __('billing::portal.country_mismatch.choose_country') }} —</option>
                            @foreach ($d['mismatch']['allowed_countries'] as $code)
                                <option value="{{ $code }}">{{ $code }}</option>
                            @endforeach
                        </flux:select>
                    </div>

                    <flux:callout variant="secondary" icon="information-circle">
                        {{ __('billing::portal.country_mismatch.confirm_note', [
                            'count' => $d['mismatch']['invoice_count'],
                            'total' => $d['mismatch']['invoice_total_gross'],
                        ]) }}
                    </flux:callout>

                    <flux:checkbox wire:model="fixConfirmed" :label="__('billing::portal.country_mismatch.confirm_checkbox')" />

                    @if ($fixError)
                        <flux:callout variant="danger" icon="exclamation-triangle">{{ $fixError }}</flux:callout>
                    @endif

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost" type="button">{{ __('billing::portal.country_mismatch.cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="primary" type="submit">
                            {{ __('billing::portal.country_mismatch.save') }}
                        </flux:button>
                    </div>
                </form>
            </flux:modal>
        @endif
    @endif
</div>
