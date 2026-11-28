<?php

use GraystackIT\MollieBilling\Concerns\InteractsWithFluxUi;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\BillingMoney;
use Livewire\Component;

new class extends Component {
    use InteractsWithFluxUi;
    use \GraystackIT\MollieBilling\Concerns\ShowsPendingOtherOrderNotice;

    public bool $polling = false;
    public ?string $pendingAddon = null;
    public ?string $pendingAction = null;

    /** @var array<string, array<int, string>> applied coupon codes per addon */
    public array $appliedCouponCodes = [];
    /** @var array<string, array<int, array{code:string, name:string, stackable:bool}>> */
    public array $appliedCouponInfo = [];
    /** @var array<string, string> active input per addon */
    public array $couponInputs = [];
    /** @var array<string, ?string> validation error per addon */
    public array $couponErrors = [];
    /** @var array<string, int> coupon total discount net (cents) per addon */
    public array $couponDiscounts = [];

    private function resolveBillable(): ?Billable
    {
        return MollieBilling::resolveBillable(request());
    }

    /**
     * Read gate for everything this page renders and prices.
     *
     * The portal routes do carry `billing.portal`, and AuthorizeBillingPortal is
     * registered as Livewire persistent middleware so it re-runs on
     * `/livewire/update` too. This gate is defence-in-depth behind both, because a
     * component that answers questions about a tenant must not depend on its
     * mounting being right — it decides for itself who gets to read the tenant's active addons or
     * probe coupon validity against it. Withholding the billable falls through to the
     * same empty state as "none resolved"; `enableAddon()`/`disableAddon()` keep
     * resolving it unguarded so they can still answer 403.
     */
    private function readableBillable(): ?Billable
    {
        $billable = $this->resolveBillable();

        return $billable !== null && MollieBilling::authorizes(request(), $billable)
            ? $billable
            : null;
    }

    public function applyCoupon(string $addonCode, CouponService $couponService, PreviewService $previewService): void
    {
        $this->couponErrors[$addonCode] = null;

        $code = strtoupper(trim((string) ($this->couponInputs[$addonCode] ?? '')));
        if ($code === '') {
            return;
        }

        $current = $this->appliedCouponCodes[$addonCode] ?? [];

        if (in_array($code, $current, true)) {
            $this->couponErrors[$addonCode] = __('billing::checkout.coupon_already_applied');
            return;
        }

        if (! $this->canAddMoreCouponsFor($addonCode)) {
            $this->couponErrors[$addonCode] = __('billing::checkout.coupon_not_stackable_with_current');
            return;
        }

        $billable = $this->readableBillable();
        if (! $billable) {
            return;
        }

        $catalog = app(SubscriptionCatalogInterface::class);
        $next = array_values(array_unique(array_merge($billable->getActiveBillingAddonCodes(), [$addonCode])));
        $planCode = $billable->getBillingSubscriptionPlanCode() ?? '';
        $interval = $billable->getBillingSubscriptionInterval() ?? 'monthly';
        $totalSeats = $planCode ? $catalog->includedSeats($planCode) + max(0, $billable->getExtraBillingSeats()) : 0;
        $newNet = $planCode
            ? \GraystackIT\MollieBilling\Support\SubscriptionAmount::net($catalog, $billable, $planCode, $interval, $totalSeats, $next)
            : 0;

        try {
            $coupon = $couponService->validate($code, $billable, [
                'planCode' => $planCode ?: null,
                'interval' => $interval,
                'addonCodes' => $next,
                'orderAmountNet' => $newNet,
                'existingCouponIds' => $this->resolveAppliedCouponIds($current),
                'allowed_types' => [
                    \GraystackIT\MollieBilling\Enums\CouponType::Recurring,
                ],
            ]);
        } catch (InvalidCouponException $e) {
            $this->couponErrors[$addonCode] = $this->translateCouponReason($e->reason());
            return;
        } catch (\Throwable $e) {
            report($e);
            $this->couponErrors[$addonCode] = __('billing::checkout.coupon_failed');
            return;
        }

        $this->appliedCouponCodes[$addonCode][] = (string) $coupon->code;
        $this->appliedCouponInfo[$addonCode][] = [
            'code' => (string) $coupon->code,
            'name' => (string) ($coupon->name ?: $coupon->code),
            'stackable' => (bool) $coupon->stackable,
        ];
        $this->couponInputs[$addonCode] = '';

        $this->refreshAddonPreview($previewService, $addonCode);
    }

    public function removeCoupon(string $addonCode, string $code, PreviewService $service): void
    {
        $code = strtoupper(trim($code));
        $this->appliedCouponCodes[$addonCode] = array_values(array_filter(
            $this->appliedCouponCodes[$addonCode] ?? [],
            fn (string $c) => $c !== $code,
        ));
        $this->appliedCouponInfo[$addonCode] = array_values(array_filter(
            $this->appliedCouponInfo[$addonCode] ?? [],
            fn (array $info) => ($info['code'] ?? null) !== $code,
        ));
        $this->couponErrors[$addonCode] = null;
        $this->refreshAddonPreview($service, $addonCode);
    }

    public function canAddMoreCouponsFor(string $addonCode): bool
    {
        $infos = $this->appliedCouponInfo[$addonCode] ?? [];
        if ($infos === []) {
            return true;
        }

        foreach ($infos as $info) {
            if (! ($info['stackable'] ?? true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string>  $codes
     * @return array<int, int>
     */
    private function resolveAppliedCouponIds(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $upper = array_map('strtoupper', $codes);
        $placeholders = implode(',', array_fill(0, count($upper), '?'));

        return Coupon::query()
            ->whereRaw('UPPER(code) IN ('.$placeholders.')', $upper)
            ->pluck('id')
            ->all();
    }

    private function refreshAddonPreview(PreviewService $service, string $addonCode): void
    {
        $this->couponDiscounts[$addonCode] = 0;

        $codes = $this->appliedCouponCodes[$addonCode] ?? [];
        if ($codes === []) {
            return;
        }

        $billable = $this->readableBillable();
        if (! $billable) {
            return;
        }

        $next = array_values(array_unique(array_merge($billable->getActiveBillingAddonCodes(), [$addonCode])));

        $preview = $service->previewUpdate($billable, new \GraystackIT\MollieBilling\Services\Billing\SubscriptionUpdateRequest(
            addons: $next,
            couponCodes: $codes,
        ));

        $this->couponDiscounts[$addonCode] = (int) ($preview['couponDiscountNet'] ?? 0);
    }

    private function translateCouponReason(string $reason): string
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
            'usage_not_applicable' => __('billing::checkout.coupon_usage_mismatch'),
            'min_order_not_met' => __('billing::checkout.coupon_min_order'),
            'requires_billable' => __('billing::checkout.coupon_requires_billable'),
            'recurring_conflict' => __('billing::checkout.coupon_recurring_conflict'),
            'requires_active_subscription' => __('billing::checkout.coupon_requires_active_subscription'),
            'too_close_to_charge' => __('billing::checkout.coupon_too_close_to_charge'),
            'per_billable_limit_reached' => __('billing::checkout.coupon_per_billable_limit_reached'),
            'full_coverage_use_access_grant' => __('billing::checkout.coupon_full_coverage_use_access_grant'),
            'recurring_already_active' => __('billing::checkout.coupon_recurring_already_active'),
            'type_not_allowed_in_context' => __('billing::checkout.coupon_type_not_allowed_in_context'),
            default => __('billing::checkout.coupon_failed'),
        };
    }

    public function enableAddon(string $addonCode): void
    {
        $this->clearPendingOtherOrder();
        $billable = $this->resolveBillable();
        if (! $billable) return;

        abort_unless(MollieBilling::authorizes(request(), $billable), 403);

        // The view only offers addons the plan allows, but the code arrives from the
        // client and nothing downstream re-checks it: ValidateSubscriptionChange skips
        // its addon validation unless the *plan* changed, so an addon reserved for a
        // higher tier was written into active_addon_codes — and FeatureAccess then
        // granted its features. An unknown code was accepted too and stayed forever as
        // a junk entry priced at 0.
        $catalog = app(SubscriptionCatalogInterface::class);
        $planCode = $billable->getBillingSubscriptionPlanCode();

        if ($planCode === null || ! $catalog->planAllowsAddon($planCode, $addonCode)) {
            $this->toast(__('billing::portal.flash.error'), 'danger');

            return;
        }

        // An addon may be sold in only one interval, and `addonPriceNet()` answers 0
        // for an interval it does not define — so on the other interval the addon
        // would be enabled, feature-granting, at no charge.
        $interval = $billable->getBillingSubscriptionInterval() ?? 'monthly';

        if (! $catalog->addonOffersInterval($addonCode, $interval)) {
            $this->toast(__('billing::portal.flash.error'), 'danger');

            return;
        }

        // A tenant who was GIVEN their plan buys this addon: the gift stays, only the
        // addon is paid. That needs a payment method first, so it goes to Mollie — and
        // returns as a real subscription whose plan line is waived for as long as the
        // gift runs. Free addons, and everybody else, take the ordinary path below.
        $purchase = app(\GraystackIT\MollieBilling\Services\Billing\StartGrantExtrasPurchase::class);
        $addonsAfter = array_values(array_unique([...$billable->getActiveBillingAddonCodes(), $addonCode]));

        if ($purchase->eligible($billable) && $purchase->needsPayment($billable, $addonsAfter, $billable->getBillingSeatCount())) {
            try {
                $url = $purchase->handle($billable, $addonsAfter, $billable->getBillingSeatCount());
            } catch (\GraystackIT\MollieBilling\Exceptions\PendingPaymentForAnotherOrderException $e) {
                // An earlier purchase of other extras is still open and Mollie would not
                // withdraw it: until when, and its link — the checkout's notice.
                $this->showPendingOtherOrder($e);

                return;
            } catch (\GraystackIT\MollieBilling\Exceptions\PastDueExpansionNotAllowedException|\GraystackIT\MollieBilling\Exceptions\SubscriptionEndedChangeNotAllowedException $e) {
                // Not an error to report: the customer is told what to do first.
                $this->toast(\GraystackIT\MollieBilling\Support\ExceptionMessage::for($e), 'danger');

                return;
            } catch (\GraystackIT\MollieBilling\Exceptions\BillingCountryRequiredException) {
                // A gifted tenant without billing details: say what is missing and where
                // to add it, instead of "something went wrong".
                $this->toast(__('billing::portal.flash.billing_details_required', [
                    'page' => __('billing::portal.nav.billing_data'),
                ]), 'warning');

                return;
            } catch (\Throwable $e) {
                report($e);
                $this->toast(__('billing::portal.flash.error'), 'danger');

                return;
            }

            if ($url === null) {
                $this->toast(__('billing::portal.flash.error'), 'danger');

                return;
            }

            $this->redirect($url, navigate: false);

            return;
        }

        $codes = $this->appliedCouponCodes[$addonCode] ?? [];

        try {
            $billable->enableBillingAddon(
                $addonCode,
                null,
                $codes !== [] ? $codes : null,
            );
            $this->pendingAddon = $addonCode;
            $this->pendingAction = 'enabled';
            $this->polling = true;
            unset(
                $this->appliedCouponCodes[$addonCode],
                $this->appliedCouponInfo[$addonCode],
                $this->couponInputs[$addonCode],
                $this->couponErrors[$addonCode],
                $this->couponDiscounts[$addonCode],
            );
        } catch (\GraystackIT\MollieBilling\Exceptions\PastDueExpansionNotAllowedException|\GraystackIT\MollieBilling\Exceptions\SubscriptionEndedChangeNotAllowedException $e) {
            // Not an error to report: the customer is told what to do first.
            $this->toast(\GraystackIT\MollieBilling\Support\ExceptionMessage::for($e), 'danger');
        } catch (\Throwable $e) {
            report($e);
            $this->toast(__('billing::portal.flash.error'), 'danger');
        }
    }

    public function disableAddon(string $addonCode): void
    {
        $billable = $this->resolveBillable();
        if (! $billable) return;

        abort_unless(MollieBilling::authorizes(request(), $billable), 403);

        try {
            $billable->disableBillingAddon($addonCode);
            $this->pendingAddon = $addonCode;
            $this->pendingAction = 'disabled';
            $this->polling = true;
        } catch (\Throwable $e) {
            report($e);
            $this->toast(__('billing::portal.flash.error'), 'danger');
        }
    }

    public function pollForChange(): void
    {
        $billable = $this->readableBillable();
        if (! $billable) return;

        $meta = $billable->getBillingSubscriptionMeta();

        // Both markers, and the failure one.
        //
        // An addon change books its deferred charge under `pending_prorata_change`;
        // `pending_plan_change` is written only for a real plan or interval switch. Polling
        // on the latter alone meant the very first tick found it empty and announced
        // success while the payment was still open — and it announced success again when
        // the charge was later declined, because `failed()` clears the markers too. The
        // customer read "add-on enabled" for a charge that never went through.
        if (! empty($meta['pending_prorata_change']) || ! empty($meta['pending_plan_change'])) {
            return;
        }

        $this->polling = false;

        $failedAt = $meta['plan_change_failed_at'] ?? null;
        $failedRecently = $failedAt !== null
            && \GraystackIT\MollieBilling\Support\BillingTime::toUtc($failedAt)
                ->greaterThan(\GraystackIT\MollieBilling\Support\BillingTime::nowUtc()->subMinutes(30));

        $name = $this->pendingAddon
            ? (app(SubscriptionCatalogInterface::class)->addonName($this->pendingAddon) ?? $this->pendingAddon)
            : null;

        if ($failedRecently) {
            $this->toast(__('billing::portal.flash.plan_change_failed'), 'danger');
        } else {
            $message = $name
                ? __("billing::portal.addons_flash.{$this->pendingAction}", ['addon' => $name])
                : __('billing::portal.flash.plan_changed');
            $this->toast($message, 'success');
        }

        $this->pendingAddon = null;
        $this->pendingAction = null;
    }

    /**
     * Gross amount that toggling one addon charges (positive) or credits (negative) right
     * now, from the same preview the change is priced with. 0 when nothing moves, when
     * there is no billable, or when the change would be refused.
     */
    private function dueNowFor(?\GraystackIT\MollieBilling\Contracts\Billable $billable, string $addonCode, bool $isActive): int
    {
        if ($billable === null) {
            return 0;
        }

        $active = $billable->getActiveBillingAddonCodes();
        $codes = $isActive
            ? array_values(array_diff($active, [$addonCode]))
            : array_values(array_unique([...$active, $addonCode]));

        // A code => quantity map, at the quantity each addon is billed at — the rule
        // `UpdateSubscription::addonQuantities()` prices the change with. A list of codes
        // is read as one unit each, so an addon the app bills three times over was quoted
        // as dropping to one: switching another addon on showed a credit for a change
        // that then charged.
        $next = [];
        foreach ($codes as $code) {
            $next[(string) $code] = $billable->getBillingAddonQuantity((string) $code) ?: 1;
        }

        try {
            $preview = app(PreviewService::class)->previewUpdate($billable, [
                'addons' => $next,
                'coupon_codes' => $isActive ? [] : ($this->appliedCouponCodes[$addonCode] ?? []),
            ]);

            return ($preview['errors'] ?? []) === [] ? (int) ($preview['prorataTotalGross'] ?? 0) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    public function with(): array
    {
        $billable = $this->readableBillable();
        $catalog = app(SubscriptionCatalogInterface::class);
        $interval = $billable?->getBillingSubscriptionInterval() ?? 'monthly';
        $planCode = $billable?->getBillingSubscriptionPlanCode();
        $activeAddons = $billable?->getActiveBillingAddonCodes() ?? [];
        $currencySymbol = BillingMoney::symbol();

        $reverseCharge = $billable !== null
            && method_exists($billable, 'usesReverseCharge')
            && $billable->usesReverseCharge();
        $vatService = app(VatCalculationService::class);
        $country = (string) ($billable?->getBillingCountry() ?? 'AT');

        $addons = [];
        foreach ($catalog->allAddons() as $code) {
            $allowed = $planCode ? $catalog->planAllowsAddon($planCode, $code) : false;
            $netPrice = $catalog->addonPriceNet($code, $interval);

            // Display gross to B2C, net to B2B with valid reverse-charge.
            $displayPrice = $netPrice;
            if ($netPrice > 0 && ! $reverseCharge && $billable !== null) {
                try {
                    $displayPrice = (int) $vatService->calculate($country, $netPrice, $billable)['gross'];
                } catch (\Throwable) {
                    // fall back to net
                }
            }

            // The per-seat part, shown next to the base price ("10,00 € + 2,00 €/seat").
            $seatPriceNet = $catalog->addonSeatPriceNet($code, $interval);
            $displaySeatPrice = $seatPriceNet;
            if ($seatPriceNet !== null && $seatPriceNet > 0 && ! $reverseCharge && $billable !== null) {
                try {
                    $displaySeatPrice = (int) $vatService->calculate($country, $seatPriceNet, $billable)['gross'];
                } catch (\Throwable) {
                    // fall back to net
                }
            }

            $addons[] = [
                'code' => $code,
                'name' => $catalog->addonName($code) ?? $code,
                'price' => $displayPrice,
                'netPrice' => $netPrice,
                'seatPriceNet' => $seatPriceNet,
                'seatPrice' => $seatPriceNet !== null && $seatPriceNet > 0 ? $displaySeatPrice : null,
                'includedSeats' => $catalog->addonIncludedSeats($code),
                // Base AND per-seat price — an addon with base 0 and a seat price is not free.
                'isFree' => $catalog->addonIsFree($code, $interval),
                // What switching this addon on (positive) or off (negative) moves NOW, gross
                // — the pro-rata charge or credit, as the change will book it. The card
                // shows the recurring price; the confirm dialog shows this.
                'dueNowGross' => $this->dueNowFor($billable, $code, in_array($code, $activeAddons, true)),
                // Only what a customer should read; a hidden feature code still unlocks.
                'features' => $catalog->visibleAddonFeatures($code),
                'isActive' => in_array($code, $activeAddons, true),
                'isAllowed' => $allowed,
            ];
        }

        return [
            'billable' => $billable,
            'addons' => $addons,
            'interval' => $interval,
            'currencySymbol' => $currencySymbol,
            'catalog' => $catalog,
            'hasPendingPlanChange' => $billable?->hasPendingBillingPlanChange() ?? false,
            // Cancelled at the period end and still running: no paid addon is booked into
            // a period that ends anyway (decision 5) — the date the hint names, or null.
            // A free plan says its own sentence already.
            'graceEndsOn' => $billable !== null
                && ! $billable->isLocalBillingSubscription()
                && \GraystackIT\MollieBilling\Exceptions\SubscriptionEndingBookingNotAllowedException::appliesTo($billable)
                    ? \GraystackIT\MollieBilling\Exceptions\SubscriptionEndingBookingNotAllowedException::endDate($billable)
                    : null,
            'reverseCharge' => $reverseCharge,
            'showNetAndGross' => \GraystackIT\MollieBilling\Support\BillingPriceDisplay::forBillable($billable),
        ];
    }
};

?>

<div class="space-y-6" @if ($polling) wire:poll.3s="pollForChange" @endif>
    {{-- Page header --}}
    <div>
        <flux:heading size="xl">{{ __('billing::portal.addons') }}</flux:heading>
        <flux:subheading>
            {{ __('billing::portal.addons_subtitle') }}
        </flux:subheading>
    </div>

    @if ($polling)
        <flux:callout icon="arrow-path" color="blue" inline>{{ __('billing::portal.flash.plan_change_pending_payment') }}</flux:callout>
    @endif

    {{-- A changed order waits for an earlier payment Mollie would not withdraw --}}
    @if ($pendingOtherOrder)
        <x-mollie-billing::pending-other-order :until="$pendingOtherOrderUntil" :timezone="$pendingOtherOrderTimezone" :url="$pendingOtherOrderUrl" x-init="$el.scrollIntoView({ behavior: 'smooth', block: 'center' })" />
    @endif

    @if ($hasPendingPlanChange)
        <flux:callout icon="arrow-path" color="blue" inline>
            {{ __('billing::portal.pending_change_blocks_modifications') }}
        </flux:callout>
    @endif

    {{-- A gifted tenant may never have been through a checkout and so may have no
         billing address. Extras cannot be priced without the billing country — say so
         here, with the way to fix it, rather than only when the purchase is refused. --}}
    @if ($billable
        && app(\GraystackIT\MollieBilling\Services\Billing\StartGrantExtrasPurchase::class)->eligible($billable)
        && trim((string) ($billable->getBillingCountry() ?? '')) === '')
        <flux:callout variant="warning" icon="exclamation-triangle" dusk="billing-details-required">
            <span>{{ __('billing::portal.flash.billing_details_required', ['page' => __('billing::portal.nav.billing_data')]) }}</span>
            <flux:button :href="route(\GraystackIT\MollieBilling\Support\BillingRoute::name('billing-data'), \GraystackIT\MollieBilling\MollieBilling::resolveUrlParameters($billable))" variant="primary" size="sm" class="mt-3">
                {{ __('billing::portal.nav.billing_data') }}
            </flux:button>
        </flux:callout>
    @endif

    @if ($billable && $billable->isLocalBillingSubscription())
        @php
            $isAccessGrant = ($billable->getBillingSubscriptionStatus()->value !== 'cancelled')
                && ($billable->getBillingSubscriptionEndsAt()?->isFuture() ?? false);
        @endphp
        @if ($isAccessGrant)
            {{-- A gift is not a dead end: paid addons can be bought on top of it. --}}
            <flux:callout icon="gift" color="teal">
                <span>{{ __('billing::portal.access_grant_extras_available', ['date' => \GraystackIT\MollieBilling\Support\BillingTime::billingDay($billable->getBillingSubscriptionEndsAt())?->translatedFormat('d. M Y')]) }}</span>
            </flux:callout>
        @else
            <flux:callout icon="information-circle" color="blue">
                <span>{{ __('billing::portal.free_plan_no_paid_extras') }}</span>
                <flux:button :href="route(\GraystackIT\MollieBilling\Support\BillingRoute::name('plan'), \GraystackIT\MollieBilling\MollieBilling::resolveUrlParameters($billable))" variant="primary" size="sm" class="mt-3">
                    {{ __('billing::portal.plan_change') }}
                </flux:button>
            </flux:callout>
        @endif
    @endif

    @if (! $billable)
        <flux:callout variant="warning" icon="exclamation-triangle">
            {{ __('billing::portal.no_billable') }}
        </flux:callout>
    @elseif (empty($addons))
        <flux:callout icon="information-circle" color="zinc" inline>
            {{ __('billing::portal.addons_none_available') }}
        </flux:callout>
    @else
        @if ($billable->isBillingPastDue())
            <flux:callout variant="warning" icon="exclamation-triangle" class="mb-4">
                {{ __('billing::portal.error_past_due_expansion') }}
            </flux:callout>
        @endif
        @if ($graceEndsOn)
            <flux:callout icon="information-circle" color="zinc" class="mb-4" dusk="addons-grace-blocked">
                <span>{{ __('billing::portal.error_subscription_ending_addons', ['date' => $graceEndsOn]) }}</span>
                <flux:button :href="route(\GraystackIT\MollieBilling\Support\BillingRoute::name('index'), \GraystackIT\MollieBilling\MollieBilling::resolveUrlParameters($billable))" variant="primary" size="sm" icon="arrow-path" class="mt-3">
                    {{ __('billing::portal.grace_withdraw_cancellation') }}
                </flux:button>
            </flux:callout>
        @endif
        <div class="space-y-4">
            @foreach ($addons as $addon)
                <flux:card class="relative p-0! hover:shadow-md transition">
                    <div class="flex flex-col gap-4 px-6 py-6 sm:flex-row sm:items-start sm:justify-between">
                        {{-- Left: info --}}
                        <div class="flex items-start gap-4">
                            <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                                <flux:icon.puzzle-piece class="size-5 text-zinc-400" />
                            </div>
                            <div class="space-y-1">
                                <div class="flex items-center gap-3">
                                    <flux:heading size="lg">{{ $addon['name'] }}</flux:heading>
                                </div>
                                @if (count($addon['features']) > 0)
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach ($addon['features'] as $feature)
                                            <span class="inline-flex items-center gap-1 text-sm text-zinc-500 dark:text-zinc-400">
                                                <flux:icon.check class="size-3.5 text-emerald-500" />
                                                {{ $catalog->featureName($feature) }}
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>

                        {{-- Right: price + action --}}
                        <div class="flex items-center gap-6 sm:shrink-0">
                            @if ($addon['isActive'])
                                <flux:badge size="sm" color="lime" dusk="addon-active-{{ $addon['code'] }}">{{ __('billing::portal.active') }}</flux:badge>
                            @endif
                            <div class="text-right">
                                <div class="flex items-baseline gap-1">
                                    <span class="text-2xl font-bold tabular-nums tracking-tight"><x-mollie-billing::price-details :net="$addon['netPrice']" :show-both="$showNetAndGross">{{ BillingMoney::format($addon['price']) }}</x-mollie-billing::price-details></span>
                                    <span class="text-sm text-zinc-400">{{ $interval === 'monthly' ? __('billing::portal.per_month') : __('billing::portal.per_year') }}</span>
                                </div>
                                @if ($addon['seatPrice'] !== null)
                                    <flux:text class="text-xs tabular-nums text-zinc-500"><x-mollie-billing::price-details :net="$addon['seatPriceNet']" :show-both="$showNetAndGross" :net-label="__('billing::portal.addon_price_per_seat', ['price' => BillingMoney::format($addon['seatPriceNet'])])">{{ __('billing::portal.addon_price_per_seat', ['price' => BillingMoney::format($addon['seatPrice'])]) }}</x-mollie-billing::price-details>@if ($addon['includedSeats'] > 0) · {{ trans_choice('billing::portal.addon_included_seats', $addon['includedSeats'], ['count' => $addon['includedSeats']]) }}@endif</flux:text>
                                @endif
                                @unless ($showNetAndGross)
                                    <flux:text class="text-xs text-zinc-400">{{ $reverseCharge ? __('billing::portal.prices_excl_vat') : __('billing::portal.prices_incl_vat') }}</flux:text>
                                @endunless
                            </div>

                            @php
                                // A free plan cannot be charged for an addon. A GIFTED plan can: the
                                // purchase collects a payment method and keeps the gift.
                                $localBlocksThisAddon = $billable
                                    && $billable->isLocalBillingSubscription()
                                    && ! $addon['isFree']
                                    && ! app(\GraystackIT\MollieBilling\Services\Billing\StartGrantExtrasPurchase::class)->eligible($billable);
                            @endphp
                            <div class="w-32 flex justify-end">
                                @if ($hasPendingPlanChange)
                                    <flux:button class="w-full" size="sm" variant="ghost" disabled>
                                        {{ $addon['isActive'] ? __('billing::portal.addons_disable') : __('billing::portal.addons_enable') }}
                                    </flux:button>
                                @elseif ($addon['isActive'])
                                    <flux:modal.trigger name="disable-addon-{{ $addon['code'] }}">
                                        <flux:button class="w-full" size="sm" variant="ghost" icon="x-circle">
                                            {{ __('billing::portal.addons_disable') }}
                                        </flux:button>
                                    </flux:modal.trigger>
                                @elseif ($localBlocksThisAddon || ($graceEndsOn && ! $addon['isFree'] && $addon['isAllowed']))
                                    <flux:button class="w-full" size="sm" variant="ghost" disabled dusk="addon-enable-blocked-{{ $addon['code'] }}">
                                        {{ __('billing::portal.addons_enable') }}
                                    </flux:button>
                                @elseif ($addon['isAllowed'])
                                    <flux:modal.trigger name="enable-addon-{{ $addon['code'] }}">
                                        <flux:button class="w-full" size="sm" variant="primary">
                                            {{ __('billing::portal.addons_enable') }}
                                        </flux:button>
                                    </flux:modal.trigger>
                                @else
                                    <flux:tooltip :content="__('billing::portal.addons_not_available')">
                                        <flux:badge size="sm" color="zinc" icon="lock-closed">
                                            {{ __('billing::portal.addons_not_available_short') }}
                                        </flux:badge>
                                    </flux:tooltip>
                                @endif
                            </div>
                        </div>
                    </div>
                </flux:card>

                {{-- Enable confirm modal --}}
                @if (! $addon['isActive'] && $addon['isAllowed'] && ! ($graceEndsOn && ! $addon['isFree']))
                    <flux:modal name="enable-addon-{{ $addon['code'] }}" class="max-w-md">
                        <div class="space-y-6">
                            <div class="space-y-2">
                                <flux:heading size="lg">{{ __('billing::portal.addons_enable_confirm.title') }}</flux:heading>
                                <flux:text>{{ __('billing::portal.addons_enable_confirm.body', ['addon' => $addon['name'], 'price' => BillingMoney::format($addon['price']), 'interval' => $interval === 'monthly' ? __('billing::portal.per_month') : __('billing::portal.per_year')]) }}</flux:text>
                                @if ($addon['dueNowGross'] > 0)
                                    <div class="flex items-baseline justify-between text-sm">
                                        <span class="text-zinc-600 dark:text-zinc-300">{{ __('billing::portal.preview_due_now') }}</span>
                                        <span dusk="addon-due-now-{{ $addon['code'] }}" class="tabular-nums font-medium text-zinc-800 dark:text-zinc-100">{{ BillingMoney::format($addon['dueNowGross']) }}</span>
                                    </div>
                                @endif
                            </div>

                            <div class="space-y-2">
                                @foreach (($appliedCouponInfo[$addon['code']] ?? []) as $info)
                                    <div class="flex items-center justify-between gap-2 rounded-md border border-emerald-300/60 bg-emerald-50/60 px-2.5 py-1.5 dark:border-emerald-800/50 dark:bg-emerald-900/20">
                                        <div class="flex items-center gap-2 text-sm">
                                            <flux:icon.ticket class="size-3.5 text-emerald-600 dark:text-emerald-400" />
                                            <span class="font-medium tabular-nums text-emerald-700 dark:text-emerald-300">{{ $info['code'] }}</span>
                                            @if (! ($info['stackable'] ?? true))
                                                <span class="text-xs text-zinc-400">{{ __('billing::portal.coupon_not_stackable') }}</span>
                                            @endif
                                        </div>
                                        <flux:button
                                            size="xs"
                                            variant="ghost"
                                            icon="x-mark"
                                            wire:click="removeCoupon({{ \Illuminate\Support\Js::from($addon['code']) }}, {{ \Illuminate\Support\Js::from($info['code']) }})"
                                            :aria-label="__('billing::checkout.remove_coupon')"
                                        />
                                    </div>
                                @endforeach

                                @if ($this->canAddMoreCouponsFor($addon['code']))
                                    <flux:input.group>
                                        <flux:input
                                            wire:model="couponInputs.{{ $addon['code'] }}"
                                            wire:keydown.enter.prevent="applyCoupon('{{ $addon['code'] }}')"
                                            :placeholder="__('billing::portal.coupon_code_placeholder')"
                                        />
                                        <flux:button type="button" wire:click="applyCoupon('{{ $addon['code'] }}')" icon="check">
                                            {{ __('billing::portal.coupon_redeem_button') }}
                                        </flux:button>
                                    </flux:input.group>
                                @endif

                                @if (($couponDiscounts[$addon['code']] ?? 0) > 0)
                                    <flux:text class="text-xs text-emerald-600 dark:text-emerald-400">
                                        −{{ BillingMoney::format($couponDiscounts[$addon['code']] ?? 0) }} {{ __('billing::portal.coupon_discount_applied') }}
                                    </flux:text>
                                @endif

                                @if (! empty($couponErrors[$addon['code']] ?? null))
                                    <flux:text class="text-xs text-rose-600 dark:text-rose-400">{{ $couponErrors[$addon['code']] }}</flux:text>
                                @endif
                            </div>

                            <div class="flex justify-end gap-2">
                                <flux:modal.close>
                                    <flux:button variant="ghost">{{ __('billing::portal.addons_enable_confirm.cancel') }}</flux:button>
                                </flux:modal.close>
                                <flux:button variant="primary" wire:click="enableAddon('{{ $addon['code'] }}')" x-on:click="$flux.modal('enable-addon-{{ $addon['code'] }}').close()">
                                    {{ __('billing::portal.addons_enable_confirm.confirm') }}
                                </flux:button>
                            </div>
                        </div>
                    </flux:modal>
                @endif

                {{-- Disable confirm modal --}}
                @if ($addon['isActive'])
                    <flux:modal name="disable-addon-{{ $addon['code'] }}" class="max-w-md">
                        <div class="space-y-6">
                            <div class="space-y-2">
                                <flux:heading size="lg">{{ __('billing::portal.addons_disable_confirm.title') }}</flux:heading>
                                <flux:text>{{ __('billing::portal.addons_disable_confirm.body', ['addon' => $addon['name']]) }}</flux:text>
                                @if ($addon['dueNowGross'] < 0)
                                    <div class="flex items-baseline justify-between text-sm">
                                        <span class="text-zinc-600 dark:text-zinc-300">{{ __('billing::portal.preview_credit_now') }}</span>
                                        <span dusk="addon-credit-{{ $addon['code'] }}" class="tabular-nums font-medium text-emerald-600 dark:text-emerald-400">{{ BillingMoney::format(abs($addon['dueNowGross'])) }}</span>
                                    </div>
                                @endif
                            </div>
                            <div class="flex justify-end gap-2">
                                <flux:modal.close>
                                    <flux:button variant="ghost">{{ __('billing::portal.addons_disable_confirm.keep') }}</flux:button>
                                </flux:modal.close>
                                <flux:button variant="danger" wire:click="disableAddon('{{ $addon['code'] }}')" x-on:click="$flux.modal('disable-addon-{{ $addon['code'] }}').close()">
                                    {{ __('billing::portal.addons_disable_confirm.confirm') }}
                                </flux:button>
                            </div>
                        </div>
                    </flux:modal>
                @endif
            @endforeach
        </div>
    @endif
</div>
