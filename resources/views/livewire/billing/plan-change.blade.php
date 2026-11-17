<?php

use GraystackIT\MollieBilling\Concerns\InteractsWithFluxUi;
use GraystackIT\MollieBilling\Concerns\ValidatesVatNumber;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Services\Billing\UpgradeLocalToMollie;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Support\Facades\Cache;
use GraystackIT\MollieBilling\Support\CountryResolver;
use GraystackIT\MollieBilling\Support\MollieCustomerResolver;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    use InteractsWithFluxUi;
    use ValidatesVatNumber;

    /**
     * Bound with wire:model.live by the interval toggle, so it cannot be locked — it is
     * validated instead, in every mutating action. An unknown value is not harmless:
     * the catalog resolves an absent (plan, interval) pair to a free one, because
     * `basePriceNet() ?? 0` plus `seatPriceNet() ?? null` makes `isFreePlan()` true.
     * `ValidateSubscriptionChange` only rejects *hidden* plans, not absent ones.
     */
    public string $selectedInterval = 'monthly';

    /**
     * Deliberately NOT #[Locked], and not because a lock would be lost — a probe
     * confirms a locked property assigned server-side survives every later round-trip
     * untouched, and only a *client* write is rejected. The reason is simpler: the plan
     * selector is exactly where the customer is meant to write, so locking this would
     * break the feature outright.
     *
     * A lock would also answer the wrong question. "Did the client change this" is not
     * the risk; "is this a plan they may actually have" is — an unknown code or an
     * interval priced as free turns a paid subscription into a downgrade-to-free.
     * `assertSelectionIsValid()` carries that, and must run in every action that acts
     * on this property.
     */
    public ?string $selectedPlan = null;

    /**
     * Rendered pricing preview, produced by PreviewService. Locked because it is
     * server-computed output, never input — `confirmAndPay()` used to read the
     * Mollie charge amount straight out of it, which made a €99 plan buyable for
     * a cent. It recomputes now, but the lock keeps every other reader honest too.
     */
    #[Locked]
    public array $preview = [];
    public bool $wasPending = false;
    /**
     * A plan change lands on the seats in use or included by the new plan. Set when the
     * customer chooses to keep the seats they have beyond that — off by default.
     */
    public bool $keepExtraSeats = false;
    public bool $showLocalUpgradeConfirmation = false;

    /** @var array<int, string> Codes that the user has already applied (uppercased). */
    public array $appliedCouponCodes = [];
    /** @var array<int, array{code:string, name:string, stackable:bool}> Display metadata for each applied code. */
    public array $appliedCouponInfo = [];
    public string $couponInput = '';
    public ?string $couponError = null;

    // Edit-billing modal state — also used by ValidatesVatNumber trait (vat_number + billing_country)
    public bool $showEditBillingModal = false;
    public string $company_name = '';
    public string $billing_street = '';
    public string $billing_postal_code = '';
    public string $billing_city = '';
    public string $billing_country = 'AT';
    public ?string $vat_number = null;

    /** Outcome of the last server-side VIES check — locked so the client cannot claim reverse charge. */
    #[Locked]
    public ?bool $vatNumberValid = null;
    public ?string $vatStatusMessage = null;

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
     * mounting being right — it decides for itself who gets to read the tenant's current plan, seats,
     * addons, billing address or the pro-rata preview (which names invoice serials in its
     * refund-cap notices). Withholding the billable leaves the preview empty, while the
     * mutating actions keep resolving it unguarded so they can still answer 403.
     */
    private function readableBillable(): ?Billable
    {
        $billable = $this->resolveBillable();

        return $billable !== null && MollieBilling::authorizes(request(), $billable)
            ? $billable
            : null;
    }

    public function updatedSelectedInterval(): void
    {
        $this->preview = [];
        $this->selectedPlan = null;
        $this->keepExtraSeats = false;
    }

    /**
     * Plan cards to render: every visible plan, plus the billable's own plan when
     * that one is hidden.
     *
     * Keeping the own plan in the list is what makes a subscriber on a hidden
     * plan see it at all — with the "current" badge, its features, seats and
     * quota — and it is the only UI affordance for the interval switch that
     * stays allowed within a hidden plan.
     *
     * @return array<int, string>
     */
    public function selectablePlans(?Billable $billable = null): array
    {
        $catalog = app(SubscriptionCatalogInterface::class);
        $ownPlan = ($billable ?? $this->resolveBillable())?->getBillingSubscriptionPlanCode();

        return array_values(array_filter(
            $catalog->allPlans(),
            fn (string $code): bool => ! $catalog->planIsHidden($code) || $code === $ownPlan,
        ));
    }

    /**
     * Guard for every action that reaches UpdateSubscription. The plan must be one this
     * billable may actually select, and the interval must be one the catalog knows —
     * otherwise the (plan, interval) lookup silently prices as free and the change is
     * executed as a downgrade, complete with a Mollie refund.
     */
    protected function assertSelectionIsValid(?Billable $billable = null): bool
    {
        $intervals = array_map(fn (SubscriptionInterval $i): string => $i->value, SubscriptionInterval::cases());

        if (! in_array($this->selectedInterval, $intervals, true)) {
            $this->rejectSelection();

            return false;
        }

        if ($this->selectedPlan !== null && ! in_array($this->selectedPlan, $this->selectablePlans($billable), true)) {
            $this->rejectSelection();

            return false;
        }

        // A known interval is not necessarily one this plan is sold in. An interval
        // the plan does not offer carries no price, so the change would be priced as
        // a downgrade to free — including a real Mollie refund of the current period.
        $targetPlan = $this->selectedPlan ?? $billable?->getBillingSubscriptionPlanCode();
        if ($targetPlan !== null && $targetPlan !== ''
            && ! app(SubscriptionCatalogInterface::class)->planOffersInterval($targetPlan, $this->selectedInterval)
        ) {
            $this->rejectSelection();

            return false;
        }

        return true;
    }

    /**
     * Flux Pro is a hard runtime dependency but ships under `suggest`, so it is absent
     * from this package's own test environment — and a guard that cannot run under test
     * is a guard nobody can prove works.
     */
    private function rejectSelection(): void
    {
        $this->toast(__('billing::portal.flash.error'), 'danger');
    }

    public function previewFor(string $planCode, PreviewService $service): void
    {
        // Livewire methods are callable from the client, so the card list being
        // filtered is not enough — a crafted call would otherwise expose the
        // pricing of a hidden plan through the preview.
        if (! in_array($planCode, $this->selectablePlans(), true)) {
            return;
        }

        $this->selectedPlan = $planCode;
        $this->refreshPreview($service);
    }

    public function toggleKeepExtraSeats(PreviewService $service): void
    {
        $this->keepExtraSeats = ! $this->keepExtraSeats;
        $this->refreshPreview($service);
    }

    /**
     * The seat count to ask for explicitly, or null to let the change derive it.
     *
     * Only when the customer chose to keep their extra seats across a plan change and the
     * target plan sells seats at all: then the count they have now travels with the
     * change (never below what is in use or included). Otherwise nothing is passed and
     * the change lands on `max(used, included)` — BillingPolicy::derivedSeats().
     */
    private function requestedSeats(\GraystackIT\MollieBilling\Contracts\Billable $billable): ?int
    {
        if (! $this->keepExtraSeats || ! $this->selectedPlan) {
            return null;
        }

        $catalog = app(SubscriptionCatalogInterface::class);

        if ($this->selectedPlan === $billable->getBillingSubscriptionPlanCode()
            || $catalog->seatPriceNet($this->selectedPlan, $this->selectedInterval) === null
        ) {
            return null;
        }

        return max(
            $billable->getBillingSeatCount(),
            $billable->getUsedBillingSeats(),
            $catalog->includedSeats($this->selectedPlan),
        );
    }

    public function applyCoupon(CouponService $couponService, PreviewService $previewService): void
    {
        $this->couponError = null;

        $code = strtoupper(trim($this->couponInput));
        if ($code === '') {
            return;
        }

        if (in_array($code, $this->appliedCouponCodes, true)) {
            $this->couponError = __('billing::checkout.coupon_already_applied');
            return;
        }

        if (! $this->canAddMoreCoupons()) {
            $this->couponError = __('billing::checkout.coupon_not_stackable_with_current');
            return;
        }

        $billable = $this->readableBillable();
        if (! $billable || ! $this->selectedPlan) {
            return;
        }

        $catalog = app(SubscriptionCatalogInterface::class);
        $newSeats = $this->requestedSeats($billable) ?? \GraystackIT\MollieBilling\Support\BillingPolicy::derivedSeats(
            $billable->getBillingSeatCount(),
            $billable->getUsedBillingSeats(),
            $catalog->includedSeats($this->selectedPlan),
            $this->selectedPlan !== $billable->getBillingSubscriptionPlanCode(),
        );
        $newAddons = $billable->getActiveBillingAddonCodes();
        $newNet = \GraystackIT\MollieBilling\Support\SubscriptionAmount::net(
            $catalog,
            $billable,
            $this->selectedPlan,
            $this->selectedInterval,
            $newSeats,
            $newAddons,
        );

        $existingCouponIds = $this->resolveAppliedCouponIds();

        try {
            $coupon = $couponService->validate($code, $billable, [
                'planCode' => $this->selectedPlan,
                'interval' => $this->selectedInterval,
                'addonCodes' => $newAddons,
                'orderAmountNet' => $newNet,
                'existingCouponIds' => $existingCouponIds,
                'allowed_types' => [
                    \GraystackIT\MollieBilling\Enums\CouponType::Recurring,
                ],
            ]);
        } catch (InvalidCouponException $e) {
            $this->couponError = $this->translateCouponReason($e->reason());
            return;
        } catch (\Throwable $e) {
            report($e);
            $this->couponError = __('billing::checkout.coupon_failed');
            return;
        }

        $this->appliedCouponCodes[] = (string) $coupon->code;
        $this->appliedCouponInfo[] = [
            'code' => (string) $coupon->code,
            'name' => (string) ($coupon->name ?: $coupon->code),
            'stackable' => (bool) $coupon->stackable,
        ];
        $this->couponInput = '';

        $this->refreshPreview($previewService);
    }

    public function removeCoupon(string $code, PreviewService $service): void
    {
        $code = strtoupper(trim($code));
        $this->appliedCouponCodes = array_values(array_filter(
            $this->appliedCouponCodes,
            fn (string $c) => $c !== $code,
        ));
        $this->appliedCouponInfo = array_values(array_filter(
            $this->appliedCouponInfo,
            fn (array $info) => ($info['code'] ?? null) !== $code,
        ));
        $this->couponError = null;
        $this->refreshPreview($service);
    }

    public function canAddMoreCoupons(): bool
    {
        if ($this->appliedCouponCodes === []) {
            return true;
        }

        // Once any non-stackable coupon is applied, no further codes are allowed.
        foreach ($this->appliedCouponInfo as $info) {
            if (! ($info['stackable'] ?? true)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, int> */
    private function resolveAppliedCouponIds(): array
    {
        if ($this->appliedCouponCodes === []) {
            return [];
        }

        $upper = array_map('strtoupper', $this->appliedCouponCodes);
        $placeholders = implode(',', array_fill(0, count($upper), '?'));

        return Coupon::query()
            ->whereRaw('UPPER(code) IN ('.$placeholders.')', $upper)
            ->pluck('id')
            ->all();
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

    private function refreshPreview(PreviewService $service): void
    {
        $billable = $this->readableBillable();
        if (! $billable || ! $this->selectedPlan) {
            return;
        }

        $seats = $this->requestedSeats($billable);

        $this->preview = $service->previewUpdate($billable, new \GraystackIT\MollieBilling\Services\Billing\SubscriptionUpdateRequest(
            planCode: $this->selectedPlan,
            interval: $this->selectedInterval,
            seats: $seats,
            couponCodes: $this->appliedCouponCodes !== [] ? $this->appliedCouponCodes : null,
        ));
    }

    public function cancelScheduledChange(UpdateSubscription $service): void
    {
        $billable = $this->resolveBillable();
        if (! $billable) return;

        abort_unless(MollieBilling::authorizes(request(), $billable), 403);

        try {
            $service->cancelScheduledChange($billable);
            $this->toast(__('billing::portal.flash.scheduled_cancelled'), 'success');
        } catch (\Throwable $e) {
            report($e);
            $this->toast(__('billing::portal.flash.error'), 'danger');
        }
    }

    public function cancelPendingChange(UpdateSubscription $service): void
    {
        $billable = $this->resolveBillable();
        if (! $billable) return;

        abort_unless(MollieBilling::authorizes(request(), $billable), 403);

        try {
            $service->cancelPendingPlanChange($billable);
            $this->toast(__('billing::portal.flash.pending_change_cancelled'), 'success');
        } catch (\Throwable $e) {
            report($e);
            $this->toast(__('billing::portal.flash.error'), 'danger');
        }
    }

    /**
     * "Apply the scheduled change now" — the period-end path, run early.
     *
     * Delegated to `ScheduleSubscriptionChange::apply()`, which is the same code the daily
     * dispatcher runs, rather than reassembling the payload here. The hand-rolled version
     * got four things wrong, all of them losing the customer's change:
     *
     *  - it cancelled the scheduled change BEFORE attempting it, and never restored it, so
     *    any failure deleted a change the customer had booked;
     *  - it passed `apply_at => immediate` without `internal`, so on an install with
     *    `plan_change_mode = end_of_period` it could never succeed — it threw, and the
     *    catch then deleted the change;
     *  - it replayed a seat count that may have been auto-derived at schedule time as an
     *    explicit one, which is exactly what `apply()` documents it must not do;
     *  - it forwarded only the legacy single `coupon_code`, silently dropping the stacked
     *    `coupon_codes` and with them the discount the customer was promised.
     */
    public function applyScheduledNow(ScheduleSubscriptionChange $service): void
    {
        $billable = $this->resolveBillable();
        if (! $billable) return;

        abort_unless(MollieBilling::authorizes(request(), $billable), 403);

        $meta = $billable->getBillingSubscriptionMeta();
        $sc = $meta['scheduled_change'] ?? null;
        if (! $sc) return;

        try {
            $service->apply($billable, onRequest: true);

            // `apply()` also returns without doing anything — a change that waits for its
            // renewal, one that no longer fits. Only say "changed" when it was.
            $billable->refresh();
            if (! empty($billable->getBillingSubscriptionMeta()['scheduled_change'])) {
                $this->toast(__('billing::portal.flash.scheduled_change_not_yet'), 'warning');

                return;
            }

            $this->toast(__('billing::portal.flash.plan_changed'), 'success');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Scheduled plan change (apply now) failed', [
                'billable' => $billable instanceof \Illuminate\Database\Eloquent\Model ? $billable->getKey() : null,
                'scheduled_change' => $sc,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile().':'.$e->getLine(),
                'previous' => $e->getPrevious() ? $e->getPrevious()->getMessage() : null,
                'trace' => collect(explode("\n", $e->getTraceAsString()))->take(15)->implode("\n"),
            ]);
            report($e);
            // No clearing here: `apply()` owns the stored change and decides itself whether
            // a failure is permanent (drop it) or worth keeping for the next attempt.
            $this->toast(config('app.debug') ? __('billing::portal.flash.error').' (Code: '.$e->getCode().')' : __('billing::portal.flash.error'), 'danger');
        }
    }

    public function applyChange(UpdateSubscription $service, string $applyAt = 'immediate'): void
    {
        $billable = $this->resolveBillable();

        if (! $billable || ! $this->selectedPlan) {
            $this->toast(__('billing::portal.flash.error'), 'danger');
            return;
        }

        abort_unless(MollieBilling::authorizes(request(), $billable), 403);

        if (! $this->assertSelectionIsValid($billable)) {
            return;
        }

        // Local → paid: divert to the dedicated UpgradeLocalToMollie path.
        // The regular UpdateSubscription would (correctly) refuse this transition
        // via LocalSubscriptionUpgradeRequiresMolliePathException — instead we
        // ask the user to confirm and then redirect to a Mollie checkout.
        $catalog = app(SubscriptionCatalogInterface::class);
        if (
            $billable->isLocalBillingSubscription()
            && ! $catalog->isFreePlan($this->selectedPlan, $this->selectedInterval)
        ) {
            $this->showLocalUpgradeConfirmation = true;
            return;
        }

        try {
            $updateData = [
                'plan_code' => $this->selectedPlan,
                'interval' => $this->selectedInterval,
                'apply_at' => $applyAt,
                'coupon_codes' => $this->appliedCouponCodes,
            ];

            $requestedSeats = $this->requestedSeats($billable);
            if ($requestedSeats !== null) {
                $updateData['seats'] = $requestedSeats;
            }

            $result = $service->update($billable, $updateData);

            if (! empty($result['pendingPaymentConfirmation'])) {
                $this->preview = [];
                $this->selectedPlan = null;
                return;
            }

            if (! empty($result['scheduledFor'])) {
                $date = BillingTime::billingDay(\Carbon\Carbon::parse((string) $result['scheduledFor']))->translatedFormat('d. M Y');
                // Scheduled while a payment is open: it does not go away with the change.
                $this->toast(
                    $billable->isBillingPastDue()
                        ? __('billing::portal.flash.plan_scheduled_past_due', ['date' => $date])
                        : __('billing::portal.flash.plan_scheduled', ['date' => $date]),
                    $billable->isBillingPastDue() ? 'warning' : 'success',
                );
            } else {
                $this->toast(__('billing::portal.flash.plan_changed'), 'success');
            }
            $this->preview = [];
            $this->selectedPlan = null;
            $this->appliedCouponCodes = [];
            $this->appliedCouponInfo = [];
            $this->couponInput = '';
            $this->couponError = null;
        } catch (\GraystackIT\MollieBilling\Exceptions\PastDueExpansionNotAllowedException|\GraystackIT\MollieBilling\Exceptions\SubscriptionEndedChangeNotAllowedException $e) {
            // Not an error to report: the customer is told what to do first.
            $this->toast($e->getMessage(), 'danger');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Plan change failed', [
                'billable' => $billable instanceof \Illuminate\Database\Eloquent\Model ? $billable->getKey() : null,
                'selected_plan' => $this->selectedPlan,
                'selected_interval' => $this->selectedInterval,
                'apply_at' => $applyAt,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile().':'.$e->getLine(),
                'previous' => $e->getPrevious() ? $e->getPrevious()->getMessage() : null,
                'trace' => collect(explode("\n", $e->getTraceAsString()))->take(15)->implode("\n"),
            ]);
            report($e);

            // Deliberately NOT `clearPendingPlanChange()`. That wipes
            // `pending_prorata_change` and `prorata_pending_payment_id` too — markers this
            // action may not own. An addon or seat change books a deferred prorata charge
            // under exactly those keys without writing `pending_plan_change`, so a customer
            // whose addon charge was still open and who then hit any error on the plan
            // screen had the marker deleted underneath a live Mollie payment: when it
            // settled, `ProrataChargeHandler` had nothing to match and reported an orphaned
            // charge — money collected, nothing delivered, refunded by hand.
            //
            // `update()` cleans up its own partial writes; anything it did leave behind is
            // reconciled by `CleanupStalePendingProrataChangeJob`.
            $this->toast(config('app.debug') ? __('billing::portal.flash.error').' (Code: '.$e->getCode().')' : __('billing::portal.flash.error'), 'danger');
        }
    }

    public function backToPlanSelection(): void
    {
        $this->showLocalUpgradeConfirmation = false;
    }

    public function confirmAndPay(UpgradeLocalToMollie $service, PreviewService $previewService)
    {
        $billable = $this->resolveBillable();

        if (! $billable || ! $this->selectedPlan) {
            $this->toast(__('billing::portal.flash.error'), 'danger');
            return null;
        }

        abort_unless(MollieBilling::authorizes(request(), $billable), 403);

        if (! $this->assertSelectionIsValid($billable)) {
            return null;
        }

        // Re-price from the catalog right before charging. UpgradeLocalToMollie
        // hands `amount_gross` to Mollie verbatim, and the webhook treats whatever
        // was actually paid as the source of truth — so the number handed over here
        // must come from PreviewService in this request, not from the snapshot.
        $this->refreshPreview($previewService);

        // The view greys the button out on a blocking preview error; a direct call does not
        // see the view. Seats the target plan cannot sell would otherwise ride along in the
        // payment metadata, priced at nothing.
        if (! empty($this->preview['errors'] ?? [])) {
            $this->toast(__('billing::portal.flash.error'), 'danger');
            return null;
        }

        $grossAmount = (int) ($this->preview['grossTotal'] ?? 0);

        // Nothing owed and no code to explain it is not an order. Nothing owed BECAUSE a
        // code covers the whole price is: the service asks Mollie for a €0 authorisation
        // (the paid plan needs a mandate), exactly as the checkout does for that order.
        if ($grossAmount < 0 || ($grossAmount === 0 && $this->appliedCouponCodes === [])) {
            $this->toast(__('billing::portal.flash.error'), 'danger');
            return null;
        }

        // Seats and addons come out of the same preview, so the subscription the
        // webhook builds from this payment's metadata is the one that was priced.
        // Deriving them independently let the seat choice change the amount while
        // the metadata still asked for every seat.
        $extraSeats = max(0, (int) ($this->preview['newSeats'] ?? 0) - (int) ($this->preview['newIncludedSeats'] ?? 0));
        $addonCodes = array_values(array_diff(
            $billable->getActiveBillingAddonCodes(),
            (array) ($this->preview['incompatibleAddons'] ?? []),
        ));

        // The coupon has to travel with the payment, not just with the price.
        //
        // `refreshPreview()` above prices with every applied code, so the customer is
        // charged the discounted amount — but the code was never forwarded, so the webhook
        // had nothing to redeem: no redemption row, no recurring marker (the Mollie
        // subscription is created at full price, losing the ongoing discount), and the
        // reconciliation saw an amount it could not explain and fired PaymentAmountMismatch.
        //
        // The first-payment path carries exactly one code (`metadata.coupon_code`, which is
        // all `FirstPaymentCouponPricing::price()` accepts), so a stacked selection is
        // refused rather than silently charged at one price and recorded at another.
        if (count($this->appliedCouponCodes) > 1) {
            $this->toast(__('billing::portal.flash.upgrade_single_coupon_only'), 'danger');

            return null;
        }

        // One payment per intent, like the checkout.
        //
        // `refreshPreview()` above is several round-trips and the Mollie call another, so
        // the button stays live for a second or more: a double-click — or browser-Back
        // from Mollie and click again — minted a second `sequenceType: first` payment on
        // the same mandate-to-be. Both settle, both activate, and the customer is charged
        // twice. Non-blocking on purpose: the loser must not sit on a worker waiting for
        // the winner to finish talking to Mollie.
        $submitLock = Cache::lock('billing:checkout-submit:'.$billable->getKey(), 60);

        if (! $submitLock->get()) {
            $this->toast(__('billing::checkout.error_already_in_progress'), 'danger');

            return null;
        }

        try {
            $result = $service->handle($billable, [
                'plan_code' => $this->selectedPlan,
                'interval' => $this->selectedInterval,
                'addon_codes' => $addonCodes,
                'extra_seats' => $extraSeats,
                'coupon_code' => $this->appliedCouponCodes[0] ?? null,
                'amount_gross' => $grossAmount,
            ]);

            if (! empty($result['payment_id'])) {
                $billable->recordPendingFirstPayment((string) $result['payment_id']);
            }

            if (! empty($result['checkout_url'])) {
                return $this->redirect($result['checkout_url'], navigate: false);
            }

            $this->toast(__('billing::portal.flash.error'), 'danger');
            return null;
        } catch (\Throwable $e) {
            report($e);
            $this->toast(__('billing::portal.flash.error'), 'danger');
            return null;
        } finally {
            $submitLock->release();
        }
    }

    /**
     * For an upgrade from a free plan that a code covers in full: when money is first
     * collected, and how much. Null whenever something is due today (or nothing ever is).
     *
     * @return array{date: \Carbon\CarbonInterface, gross: int}|null
     */
    public function fullCoverageFirstCharge(): ?array
    {
        $billable = $this->readableBillable();

        if (! $billable || ! $this->selectedPlan || ! $billable->isLocalBillingSubscription()
            || count($this->appliedCouponCodes) !== 1
            || (int) ($this->preview['grossTotal'] ?? -1) !== 0
        ) {
            return null;
        }

        $coupon = \GraystackIT\MollieBilling\Models\Coupon::query()
            ->whereRaw('UPPER(code) = ?', [strtoupper((string) $this->appliedCouponCodes[0])])
            ->first();

        if ($coupon === null) {
            return null;
        }

        $catalog = app(SubscriptionCatalogInterface::class);
        $now = \GraystackIT\MollieBilling\Support\BillingTime::nowUtc();
        $extraSeats = max(0, (int) ($this->preview['newSeats'] ?? 0) - (int) ($this->preview['newIncludedSeats'] ?? 0));
        $addonCodes = array_values(array_diff(
            $billable->getActiveBillingAddonCodes(),
            (array) ($this->preview['incompatibleAddons'] ?? []),
        ));

        $priced = app(\GraystackIT\MollieBilling\Services\Webhook\FirstPaymentCouponPricing::class)
            ->price($billable, (string) $coupon->code, $this->selectedPlan, $this->selectedInterval, $addonCodes, $extraSeats);

        if ($priced === null || $priced['order_amount_net'] <= 0) {
            return null;
        }

        // The same two dates CreateSubscription chooses between: the first regular charge
        // the code no longer covers while it still covers the next one, otherwise one
        // interval from now.
        $resumesAt = (int) $priced['recurring_discount_net'] >= (int) $priced['order_amount_net']
            ? MollieBilling::coupons()->recurringChargeResumesAt($coupon, $this->selectedInterval, $now)
            : null;
        $date = $resumesAt ?? \GraystackIT\MollieBilling\Support\BillingTime::addInterval($now, $this->selectedInterval);

        $country = trim((string) ($billable->getBillingCountry() ?? ''));
        $rate = $country === '' ? 0.0 : (float) app(\GraystackIT\MollieBilling\Services\Vat\VatCalculationService::class)
            ->calculate($country, (int) $priced['order_amount_net'], $billable)['rate'];

        return [
            'date' => $date,
            'gross' => BillingMoney::grossWithLineVat(
                \GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher::recurringLineNets(
                    $catalog, $billable, $this->selectedPlan, $this->selectedInterval, $addonCodes, $extraSeats, (int) $priced['order_amount_net'],
                ),
                $rate,
            ),
        ];
    }

    public function openEditBillingModal(): void
    {
        $billable = $this->readableBillable();
        if (! $billable) {
            return;
        }

        $this->company_name = $billable->getBillingName();
        $this->billing_street = (string) $billable->getBillingStreet();
        $this->billing_postal_code = (string) $billable->getBillingPostalCode();
        $this->billing_city = (string) $billable->getBillingCity();
        $this->billing_country = (string) ($billable->getBillingCountry() ?? 'AT');
        $this->vat_number = $billable instanceof \Illuminate\Database\Eloquent\Model ? ($billable->vat_number ?? null) : null;
        $this->vatNumberValid = null;
        $this->vatStatusMessage = null;
        $this->resetErrorBag();

        $this->showEditBillingModal = true;
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

    public function saveBillingDetails(MollieCustomerResolver $customerResolver, PreviewService $previewService, VatCalculationService $vat): void
    {
        $billable = $this->resolveBillable();
        if (! $billable || ! ($billable instanceof \Illuminate\Database\Eloquent\Model)) {
            return;
        }

        abort_unless(MollieBilling::authorizes(request(), $billable), 403);

        $validCountries = array_keys(CountryResolver::resolve());

        $this->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'billing_street' => ['required', 'string', 'max:255'],
            'billing_postal_code' => ['required', 'string', 'max:20'],
            'billing_city' => ['required', 'string', 'max:255'],
            'billing_country' => ['required', 'string', 'in:'.implode(',', $validCountries)],
            'vat_number' => ['nullable', 'string', 'max:50'],
        ]);

        if (filled($this->vat_number)) {
            try {
                $this->validateVatNumberLive($vat);
            } catch (\Throwable) {
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

        $newVatNumber = $this->vat_number ?: null;

        // Atomic: persisting the billable with a vat_number and recording the
        // matching BillingVatValidation must succeed or fail together. Otherwise
        // a VIES outage between save and validateAndPersist would leave the
        // billable with a vat_number but no audit row — currentVatValidation()
        // returns null and reverse-charge silently stops applying.
        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($billable, $newVatNumber, $vat): void {
                // Through the setter — see billing-data.blade.php and checkout.blade.php.
                $billable->setBillingName($this->company_name);
                $billable->forceFill([
                    'billing_street' => $this->billing_street,
                    'billing_postal_code' => $this->billing_postal_code,
                    'billing_city' => $this->billing_city,
                    'billing_country' => $this->billing_country,
                    'vat_number' => $newVatNumber,
                    // Declared together with the country VAT is priced from, as the
                    // billing-data and checkout forms do — otherwise CountryMatchService
                    // reconciles against the old declaration and misses the move.
                    'tax_country_user' => $this->billing_country,
                ])->save();

                // `currentVatValidation()` filters by the billable's current
                // `vat_number`, so a number change automatically triggers a new
                // persist; reaffirming the same number is a no-op.
                if (filled($newVatNumber) && $billable->currentVatValidation() === null) {
                    $vat->validateAndPersist($billable, (string) $newVatNumber);
                }
            });
        } catch (\GraystackIT\MollieBilling\Exceptions\ViesUnavailableException) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'vat_number' => __('billing::checkout.vies_unavailable'),
            ]);
        }

        try {
            $customerResolver->sync($billable);
        } catch (\Throwable $e) {
            // Non-fatal: local data is saved, Mollie name/email sync can be retried later.
            report($e);
        }

        $this->showEditBillingModal = false;

        if ($this->selectedPlan) {
            $this->refreshPreview($previewService);
        }

        $this->toast(__('billing::portal.flash.billing_details_saved'), 'success');
    }

    public function with(): array
    {
        $billable = $this->readableBillable();
        $scheduledChange = null;
        $pendingPlanChange = null;
        $planChangeFailed = null;

        if ($billable) {
            $meta = $billable->getBillingSubscriptionMeta();
            $sc = $meta['scheduled_change'] ?? null;
            if ($sc !== null) {
                $scheduledChange = [
                    'plan_code' => $sc['plan_code'] ?? null,
                    'interval' => $sc['interval'] ?? null,
                    'scheduled_at' => isset($sc['scheduled_at'])
                        ? BillingTime::billingDay(\Carbon\Carbon::parse((string) $sc['scheduled_at']))->translatedFormat('d. M Y')
                        : null,
                ];
            }

            $pendingPlanChange = $meta['pending_plan_change'] ?? null;

            if (! empty($meta['plan_change_failed_at'])) {
                $failedAt = \Carbon\Carbon::parse((string) $meta['plan_change_failed_at'])->setTimezone('UTC');
                if ($failedAt->isAfter(BillingTime::nowUtc()->subDay())) {
                    $planChangeFailed = [
                        'failed_at' => BillingTime::display($failedAt, $billable)->translatedFormat('d. M Y, H:i'),
                        'reason' => $meta['plan_change_failed_reason'] ?? null,
                    ];
                }
            }

            // Detect pending → resolved transition (webhook applied the change).
            if ($this->wasPending && $pendingPlanChange === null) {
                if ($planChangeFailed) {
                    $this->toast(__('billing::portal.flash.plan_change_failed'), 'danger');
                } else {
                    $this->toast(__('billing::portal.flash.plan_changed'), 'success');
                }
            }
            $this->wasPending = $pendingPlanChange !== null;
        }

        $reverseCharge = $billable !== null
            && method_exists($billable, 'usesReverseCharge')
            && $billable->usesReverseCharge();
        $displayCountry = (string) ($billable?->getBillingCountry() ?? 'AT');

        return [
            'billable' => $billable,
            'plans' => $this->selectablePlans($billable),
            'catalog' => app(SubscriptionCatalogInterface::class),
            'scheduledChange' => $scheduledChange,
            'pendingPlanChange' => $pendingPlanChange,
            'planChangeFailed' => $planChangeFailed,
            'reverseCharge' => $reverseCharge,
            'displayCountry' => $displayCountry,
            // Gates both savings badges in the plan selector: the "save up to X%"
            // on the yearly toggle and the per-card percentage.
            'showYearlySavings' => (bool) config('mollie-billing.show_yearly_savings', true),
        ];
    }
};

?>

<div class="space-y-6" @if($pendingPlanChange) wire:poll.5s @endif>
    {{-- Page header --}}
    <div>
        <flux:heading size="xl">{{ __('billing::portal.plan_change') }}</flux:heading>
        <flux:subheading>
            {{ __('billing::portal.plan_change_subtitle') }}
        </flux:subheading>
    </div>

    {{-- Current subscription summary --}}
    @php
        $headerPlanCode = $billable?->getBillingSubscriptionPlanCode();
        $headerInterval = $billable?->getBillingSubscriptionInterval();
        $headerStatus = $billable?->getBillingSubscriptionStatus();
        $headerIsTrial = $billable && $billable->isOnBillingTrial();
        $headerIsCancelled = $headerStatus?->value === 'cancelled';
        $headerIsLocal = $billable && $billable->isLocalBillingSubscription();
        $headerIsAccessGrant = $headerIsLocal
            && ! $headerIsCancelled
            && ($billable?->getBillingSubscriptionEndsAt()?->isFuture() ?? false);

        if ($headerIsTrial) {
            $headerEndsAt = $billable->getBillingTrialEndsAt();
            $headerEndsLabel = __('billing::portal.trial_ends');
        } elseif ($headerIsCancelled) {
            $headerEndsAt = $billable?->getBillingSubscriptionEndsAt();
            $headerEndsLabel = __('billing::portal.valid_until');
        } elseif ($headerIsAccessGrant) {
            $headerEndsAt = $billable?->getBillingSubscriptionEndsAt();
            $headerEndsLabel = __('billing::portal.access_grant_valid_until');
        } else {
            $headerEndsAt = $billable?->nextBillingDate();
            $headerEndsLabel = __('billing::portal.next_billing');
        }

        $headerEndsDate = $headerEndsAt ? BillingTime::billingDay($headerEndsAt)?->translatedFormat('d. M Y') : null;
    @endphp

    @if ($billable && $headerPlanCode)
        <flux:card class="p-0! overflow-hidden">
            <div class="flex flex-col gap-4 px-6 py-5 sm:flex-row sm:items-center sm:justify-between">
                <div class="space-y-1">
                    <flux:subheading size="sm" class="text-zinc-400 dark:text-zinc-500">
                        {{ __('billing::portal.current_plan') }}
                    </flux:subheading>
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading size="lg">{{ $catalog->planName($headerPlanCode) ?? $headerPlanCode }}</flux:heading>
                        @if ($headerInterval)
                            <flux:badge size="sm" color="zinc">
                                {{ $headerInterval === 'monthly' ? __('billing::portal.interval_monthly') : __('billing::portal.interval_yearly') }}
                            </flux:badge>
                        @endif
                        @if ($headerStatus)
                            <flux:badge size="sm" :color="$headerStatus->color()">{{ $headerStatus->label() }}</flux:badge>
                        @endif
                    </div>
                </div>

                @if ($headerEndsDate)
                    <div class="space-y-1 sm:text-right">
                        <flux:subheading size="sm" class="text-zinc-400 dark:text-zinc-500">
                            {{ $headerEndsLabel }}
                        </flux:subheading>
                        @if ($headerIsLocal && ! $headerIsCancelled && ! $headerIsTrial && ! $headerIsAccessGrant)
                            <flux:text class="font-semibold text-zinc-500 dark:text-zinc-400">
                                {{ __('billing::portal.free_plan_recurring_charge') }}
                            </flux:text>
                        @else
                            <flux:text class="font-semibold">{{ $headerEndsDate }}</flux:text>
                        @endif
                    </div>
                @endif
            </div>
        </flux:card>
    @endif

    <x-mollie-billing::change-notices :billable="$billable" />

    @if ($planChangeFailed)
        <flux:callout variant="danger" icon="exclamation-triangle">
            {{ __('billing::portal.flash.plan_change_failed') }}
        </flux:callout>
    @endif

    @if ($pendingPlanChange)
        <flux:callout icon="arrow-path" color="blue" inline>
            {{ __('billing::portal.pending_plan_change_notice', ['plan' => $catalog->planName($pendingPlanChange['plan_code'] ?? '') ?? ($pendingPlanChange['plan_code'] ?? '')]) }}
            <div class="mt-2">
                <flux:button size="sm" wire:click="cancelPendingChange">
                    {{ __('billing::portal.cancel_pending_change') }}
                </flux:button>
            </div>
        </flux:callout>
    @endif

    @if ($showLocalUpgradeConfirmation && $billable && $selectedPlan)
        @php
            $currencySymbol = BillingMoney::symbol();
            $upgradePlanName = $catalog->planName($selectedPlan) ?? $selectedPlan;
            // The net line has to be the net the VAT was actually computed on, i.e.
            // the recurring price after coupons — PreviewService exposes that as
            // newPriceNet minus couponDiscountNet. ($preview['newNet'] never
            // existed, so this line used to render a flat zero under a real total.)
            $previewVat = (int) ($preview['vatAmount'] ?? 0);
            $previewGross = (int) ($preview['grossTotal'] ?? 0);
            $previewNet = max(0, (int) ($preview['newPriceNet'] ?? 0) - (int) ($preview['couponDiscountNet'] ?? 0));
            $countryNames = \GraystackIT\MollieBilling\Support\CountryResolver::resolve();
            $billingCountryIso = $billable->getBillingCountry();
            $billingCountryName = $billingCountryIso ? ($countryNames[$billingCountryIso] ?? $billingCountryIso) : null;
        @endphp

        <flux:card class="space-y-4">
            <flux:heading size="lg">{{ __('billing::portal.upgrade_confirm_title', ['plan' => $upgradePlanName]) }}</flux:heading>
            <flux:subheading>
                {{ __('billing::portal.upgrade_confirm_subtitle') }}
            </flux:subheading>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <div class="flex items-start justify-between gap-2">
                        <flux:text class="text-sm font-medium">{{ __('billing::portal.billing_address') }}</flux:text>
                        <flux:button size="xs" variant="subtle" icon="pencil-square" wire:click="openEditBillingModal">
                            {{ __('billing::portal.edit_billing_data') }}
                        </flux:button>
                    </div>
                    <div class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">
                        @if ($billable->getBillingName())
                            <div>{{ $billable->getBillingName() }}</div>
                        @endif
                        @if ($billable->getBillingStreet())
                            <div>{{ $billable->getBillingStreet() }}</div>
                        @endif
                        @if ($billable->getBillingPostalCode() || $billable->getBillingCity())
                            <div>{{ trim($billable->getBillingPostalCode().' '.$billable->getBillingCity()) }}</div>
                        @endif
                        @if ($billingCountryName)
                            <div>{{ $billingCountryName }}</div>
                        @endif
                        @if ($billable->vat_number)
                            <div class="mt-1">{{ __('billing::portal.vat_number') }}: {{ $billable->vat_number }}</div>
                        @endif
                    </div>
                </div>

                <div>
                    <flux:text class="text-sm font-medium">{{ __('billing::portal.summary') }}</flux:text>
                    <div class="text-sm mt-1 space-y-1">
                        <div class="flex justify-between">
                            <span class="text-zinc-600 dark:text-zinc-400">{{ $upgradePlanName }} ({{ __('billing::enums.subscription_interval.'.$selectedInterval) }})</span>
                            <span dusk="upgrade-net">{{ BillingMoney::format($previewNet) }}</span>
                        </div>
                        @if ($previewVat > 0)
                            <div class="flex justify-between">
                                <span class="text-zinc-600 dark:text-zinc-400">{{ __('billing::portal.vat') }}</span>
                                <span dusk="upgrade-vat">{{ BillingMoney::format($previewVat) }}</span>
                            </div>
                        @endif
                        <div class="flex justify-between font-semibold border-t border-zinc-200 dark:border-zinc-700 pt-1">
                            <span>{{ __('billing::portal.total') }}</span>
                            <span dusk="upgrade-total">{{ BillingMoney::format($previewGross) }}</span>
                        </div>
                        @php
                            $firstCharge = $this->fullCoverageFirstCharge();
                        @endphp
                        @if ($firstCharge !== null)
                            <div class="pt-2 text-sm text-zinc-600 dark:text-zinc-400" dusk="upgrade-first-charge">
                                {{ __('billing::portal.upgrade_first_charge', [
                                    'date' => \GraystackIT\MollieBilling\Support\BillingTime::billingDay($firstCharge['date'])->translatedFormat('d. M Y'),
                                    'amount' => BillingMoney::format($firstCharge['gross']),
                                ]) }}
                            </div>
                            <div class="pt-1 text-sm text-zinc-600 dark:text-zinc-400" dusk="upgrade-authorisation-notice">
                                {{ __('billing::portal.upgrade_authorisation_notice') }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="flex gap-2 justify-end">
                <flux:button wire:click="backToPlanSelection" variant="ghost">
                    {{ __('billing::portal.back') }}
                </flux:button>
                <flux:button wire:click="confirmAndPay" variant="primary" icon="credit-card">
                    {{ __('billing::portal.confirm_and_pay') }}
                </flux:button>
            </div>
        </flux:card>
    @endif

    {{-- Controls --}}
    @php
        $maxYearlySavings = 0;
        foreach ($plans as $planCodeForSavings) {
            $maxYearlySavings = max($maxYearlySavings, $catalog->yearlySavingsPercent($planCodeForSavings));
        }
        $maxYearlySavings = (int) round($maxYearlySavings);
    @endphp

    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <flux:radio.group wire:model.live="selectedInterval" variant="segmented">
            <flux:radio value="monthly" label="{{ __('billing::portal.interval_monthly') }}" />
            <flux:radio value="yearly">
                <span class="flex items-center gap-2">
                    <span>{{ __('billing::portal.interval_yearly') }}</span>
                    @if ($showYearlySavings && $maxYearlySavings > 0)
                        <flux:badge size="sm" color="lime">
                            {{ __('billing::portal.save_up_to', ['percent' => $maxYearlySavings]) }}
                        </flux:badge>
                    @endif
                </span>
            </flux:radio>
        </flux:radio.group>
    </div>

    @php
        $currentPlanCode = $billable?->getBillingSubscriptionPlanCode();
        $currentInterval = $billable?->getBillingSubscriptionInterval();
        $currencySymbol = BillingMoney::symbol();
        $planCount = count($plans);
    @endphp

    {{-- Plan cards — responsive grid: ≤3 single row, >3 wraps symmetrically --}}
    @php
        $cols = $planCount <= 3 ? $planCount : (int) ceil($planCount / 2);
    @endphp

    @php
        $hasScheduled = $scheduledChange !== null;
        $hasPending = $pendingPlanChange !== null;
        $scheduledPlanCode = $scheduledChange['plan_code'] ?? null;
        $scheduledInterval = $scheduledChange['interval'] ?? null;
    @endphp

    <div class="grid gap-4" style="grid-template-columns: repeat({{ $cols }}, minmax(0, 1fr))">
        @foreach ($plans as $code)
            @php
                $isCurrent = $currentPlanCode === $code && $currentInterval === $selectedInterval;
                $isScheduledTarget = $hasScheduled && $scheduledPlanCode === $code && $scheduledInterval === $selectedInterval;
                $isSelected = $selectedPlan === $code;
                $netPrice = $catalog->basePriceNet($code, $selectedInterval);
                // Only what a customer should read; a hidden feature code still unlocks.
                $features = $catalog->visiblePlanFeatures($code);
                $seats = $catalog->includedSeats($code);
                $savings = $selectedInterval === 'yearly' ? $catalog->yearlySavingsPercent($code) : 0;
                $isFree = $netPrice === 0;

                // Display gross to B2C, net to B2B with valid reverse-charge.
                $vatService = app(GraystackIT\MollieBilling\Services\Vat\VatCalculationService::class);
                $toDisplayCents = function (int $netCents) use ($reverseCharge, $displayCountry, $billable, $vatService): int {
                    if ($reverseCharge || $netCents === 0) {
                        return $netCents;
                    }
                    try {
                        return (int) $vatService->calculate($displayCountry, $netCents, $billable)['gross'];
                    } catch (\Throwable) {
                        return $netCents;
                    }
                };

                $price = $toDisplayCents($netPrice);

                $includedUsages = $catalog->includedUsages($code, $selectedInterval);

                // Resolved up-front: `@php(...)` does not compile reliably inside
                // a Blade component slot, which is where the card body lives.
                $featureRows = array_map(fn (string $key): array => [
                    'name' => $catalog->featureName($key) ?? $key,
                    'description' => $catalog->featureDescription($key),
                ], $features);
            @endphp

            <flux:card
                class="relative flex flex-col overflow-hidden transition {{ $isSelected ? 'ring-2 ring-accent shadow-lg' : 'hover:shadow-md' }}"
            >
                {{-- Top accent strip --}}
                @if ($isCurrent || $isScheduledTarget || $isSelected)
                    <div class="absolute inset-x-0 top-0 h-1.5 {{ $isCurrent ? 'bg-emerald-500' : ($isScheduledTarget ? 'bg-amber-500' : 'bg-accent') }}"></div>
                @endif

                <div class="flex-1 space-y-4 pt-2">
                    {{-- Plan name + badge --}}
                    <div class="flex items-start justify-between gap-2">
                        <flux:heading size="lg">{{ $catalog->planName($code) ?? $code }}</flux:heading>
                        @if ($isCurrent)
                            <flux:badge size="sm" color="lime">{{ __('billing::portal.current') }}</flux:badge>
                        @elseif ($isScheduledTarget)
                            <flux:badge size="sm" color="amber">{{ __('billing::portal.scheduled') }}</flux:badge>
                        @endif
                    </div>

                    {{-- Price block --}}
                    <div class="space-y-1.5">
                        <div class="flex items-baseline gap-1">
                            <span class="text-3xl font-bold tracking-tight">
                                @if ($isFree)
                                    {{ __('billing::portal.free') }}
                                @else
                                    {{ BillingMoney::format($price) }}
                                @endif
                            </span>
                            @unless ($isFree)
                                <span class="text-sm text-zinc-400 dark:text-zinc-500">{{ $selectedInterval === 'monthly' ? __('billing::portal.per_month') : __('billing::portal.per_year') }}</span>
                            @endunless
                        </div>
                        @if ($showYearlySavings && $savings > 0)
                            <flux:badge size="sm" color="lime" icon="arrow-trending-down" class="mt-2 mb-2">{{ __('billing::portal.save_yearly', ['percent' => round($savings)]) }}</flux:badge>
                        @endif
                        @unless ($isFree)
                            <flux:text class="text-xs text-zinc-400">{{ $reverseCharge ? __('billing::portal.prices_excl_vat') : __('billing::portal.prices_incl_vat') }}</flux:text>
                        @endunless
                    </div>

                    <flux:separator />

                    {{-- Included info: one line per entitlement, the per-extra-unit
                         price right-aligned on the same line. Mirrors the checkout
                         plan step; plain flex utilities on purpose (an arbitrary
                         grid template silently collapses to one column when the
                         app's CSS build has not generated it). --}}
                    @php
                        $quotaRows = [];

                        if ($seats > 0) {
                            $seatPriceNet = $catalog->seatPriceNet($code, $selectedInterval);
                            $seatPrice = $seatPriceNet !== null ? $toDisplayCents($seatPriceNet) : null;
                            $quotaRows[] = [
                                'label' => trans_choice('billing::portal.seats_included_count', $seats, ['count' => number_format($seats)]),
                                'unit' => __('billing::portal.seat_unit'),
                                'extra' => $seatPrice !== null && $seatPrice > 0 ? $seatPrice : null,
                                'extra_title' => $seatPrice !== null && $seatPrice > 0
                                    ? __('billing::portal.seat_overage_price', ['currency' => $currencySymbol, 'price' => BillingMoney::numberFromMinor($seatPrice)])
                                    : null,
                            ];
                        }

                        foreach ($includedUsages as $usageType => $included) {
                            $overageNet = $catalog->usageOveragePrice($code, $selectedInterval, $usageType);
                            $overageDisplay = $overageNet !== null ? $toDisplayCents($overageNet) : null;
                            $usageLabel = $catalog->usageTypeName($usageType);
                            $quotaRows[] = [
                                'label' => __('billing::portal.usage_included_count', ['count' => number_format($included), 'type' => $usageLabel]),
                                'unit' => $usageLabel,
                                'extra' => $overageDisplay !== null && $overageDisplay > 0 ? $overageDisplay : null,
                                'extra_title' => $overageDisplay !== null && $overageDisplay > 0
                                    ? __('billing::portal.usage_overage_price', ['currency' => $currencySymbol, 'price' => BillingMoney::numberFromMinor($overageDisplay), 'type' => $usageLabel])
                                    : null,
                            ];
                        }

                    @endphp

                    <div class="text-sm">
                        @if (count($quotaRows) > 0)
                            <div class="space-y-1.5">
                                @foreach ($quotaRows as $row)
                                    <div class="flex items-baseline justify-between gap-3 text-zinc-600 dark:text-zinc-300">
                                        <span>{{ $row['label'] }}</span>
                                        @if ($row['extra'] !== null)
                                            <span class="shrink-0 text-xs tabular-nums text-zinc-500 dark:text-zinc-400" title="{{ $row['extra_title'] }}">{{ __('billing::portal.quota_extra_price', ['currency' => $currencySymbol, 'price' => BillingMoney::numberFromMinor($row['extra']), 'unit' => $row['unit']]) }}</span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if (count($features) > 0)
                            <flux:separator class="mt-4"/>
                            <ul class="space-y-1.5 mt-4">
                                @foreach ($featureRows as $feature)
                                    <li class="flex items-start gap-2">
                                        <flux:icon.check class="mt-0.5 size-4 shrink-0 text-emerald-500" />
                                        <span class="text-zinc-600 dark:text-zinc-300" @if (! empty($feature['description'])) title="{{ $feature['description'] }}" @endif>{{ $feature['name'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>

                {{-- Action --}}
                <div class="mt-6 space-y-2">
                    @if ($isScheduledTarget)
                        {{-- Scheduled date info --}}
                        @if ($scheduledChange['scheduled_at'])
                            <flux:text class="text-center text-xs text-amber-600 dark:text-amber-400">
                                {{ __('billing::portal.scheduled_change_on', ['date' => $scheduledChange['scheduled_at']]) }}
                            </flux:text>
                        @endif
                        <flux:button.group class="w-full">
                            <flux:button class="flex-1" size="sm" wire:click="cancelScheduledChange">
                                <span class="text-amber-600">{{ __('billing::portal.cancel_scheduled_change') }}</span>
                            </flux:button>
                            <flux:dropdown position="bottom end">
                                <flux:button size="sm" icon="chevron-down" />
                                <flux:menu>
                                    <flux:menu.item icon="bolt" wire:click="applyScheduledNow">
                                        {{ __('billing::portal.apply_now') }}
                                    </flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </flux:button.group>
                    @elseif ($isSelected)
                        <flux:button class="w-full" variant="filled" disabled>
                            <flux:icon.check class="size-4" />
                            {{ __('billing::portal.selected') }}
                        </flux:button>
                    @elseif ($isCurrent)
                        <flux:button class="w-full" variant="ghost" disabled>
                            {{ __('billing::portal.current') }}
                        </flux:button>
                    @elseif (! $hasScheduled && ! $hasPending)
                        <flux:button class="w-full" variant="primary" wire:click="previewFor('{{ $code }}')">
                            {{ __('billing::portal.select') }}
                        </flux:button>
                    @endif
                </div>
            </flux:card>
        @endforeach
    </div>

    {{-- Preview panel --}}
    @if ($selectedPlan && !empty($preview))
        @php
            $isUpgrade = $preview['isUpgrade'] ?? false;
            $isDowngrade = $preview['isDowngrade'] ?? false;
            $planChanged = $preview['planChanged'] ?? false;
            $intervalChanged = $preview['intervalChanged'] ?? false;
            $previewErrors = $preview['errors'] ?? [];
            $hasBlockingErrors = !empty($previewErrors);
            $incompatibleAddons = $preview['incompatibleAddons'] ?? [];
            $planChangeMode = $preview['planChangeMode'] ?? 'user_choice';
            $showImmediateOption = in_array($planChangeMode, ['immediate', 'user_choice']);
            $showScheduledOption = in_array($planChangeMode, ['end_of_period', 'user_choice']);
            $usageOverageNet = $preview['usageOverageChargeNet'] ?? 0;
        @endphp

        <flux:card class="relative overflow-hidden p-0!">
            <div class="absolute inset-x-0 top-0 h-1 bg-accent"></div>

            {{-- Header --}}
            <div class="flex flex-col gap-4 px-6 pb-4 pt-8 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3">
                    <div class="flex size-10 items-center justify-center rounded-full bg-accent/10">
                        <flux:icon.receipt-percent class="size-5 text-accent" />
                    </div>
                    <div>
                        <flux:heading size="lg">{{ __('billing::portal.preview') }}</flux:heading>
                        <flux:text class="text-sm text-zinc-500">
                            {{ __('billing::portal.preview_change_summary') }}
                        </flux:text>
                    </div>
                </div>
                @if ($isUpgrade)
                    <flux:badge color="lime" icon="arrow-trending-up">{{ __('billing::portal.preview_upgrade') }}</flux:badge>
                @elseif ($isDowngrade)
                    <flux:badge color="amber" icon="arrow-trending-down">{{ __('billing::portal.preview_downgrade') }}</flux:badge>
                @else
                    <flux:badge color="zinc" icon="minus">{{ __('billing::portal.preview_no_change') }}</flux:badge>
                @endif
            </div>

            {{-- Change details: two-column layout --}}
            <div class="border-t border-zinc-200/75 px-6 py-5 dark:border-zinc-700/50">
                @php
                    $hasUsageChanges = !empty($preview['usageChanges'] ?? []);
                    $hasSeats = ($preview['currentIncludedSeats'] ?? 0) > 0 || ($preview['newIncludedSeats'] ?? 0) > 0;
                @endphp
                <div class="grid gap-6 {{ $hasUsageChanges ? 'sm:grid-cols-2' : '' }}">

                    {{-- Left column: Plan, Interval, Seats --}}
                    <div class="space-y-4">
                        {{-- Plan --}}
                        <div class="flex items-start gap-3">
                            <div class="mt-0.5 flex size-8 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                <flux:icon.squares-2x2 class="size-4 text-zinc-500" />
                            </div>
                            <div>
                                <flux:subheading size="sm" class="text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.plan') }}</flux:subheading>
                                <flux:text class="mt-0.5 font-medium">
                                    @if ($planChanged)
                                        {{ __('billing::portal.preview_plan_from_to', ['from' => $preview['currentPlanName'], 'to' => $preview['newPlanName']]) }}
                                    @else
                                        {{ __('billing::portal.preview_no_plan_change', ['plan' => $preview['newPlanName']]) }}
                                    @endif
                                </flux:text>
                            </div>
                        </div>

                        {{-- Interval --}}
                        <div class="flex items-start gap-3">
                            <div class="mt-0.5 flex size-8 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                <flux:icon.calendar class="size-4 text-zinc-500" />
                            </div>
                            <div>
                                <flux:subheading size="sm" class="text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.interval') }}</flux:subheading>
                                <flux:text class="mt-0.5 font-medium">
                                    @if ($intervalChanged)
                                        {{ __('billing::portal.preview_interval_change', ['from' => __('billing::portal.interval_' . $preview['currentInterval']), 'to' => __('billing::portal.interval_' . $preview['newInterval'])]) }}
                                    @else
                                        {{ __('billing::portal.preview_no_interval_change', ['interval' => __('billing::portal.interval_' . $preview['newInterval'])]) }}
                                    @endif
                                </flux:text>
                            </div>
                        </div>

                        {{-- Seats --}}
                        @if ($hasSeats)
                            @php
                                $previewExtraSeats = $preview['extraSeatsCharged'] ?? 0;
                                $previewNewSeats = $preview['newSeats'] ?? 0;
                                $previewNewIncluded = $preview['newIncludedSeats'] ?? 0;
                                $previewSeatPrice = $preview['seatPriceNet'] ?? 0;
                            @endphp
                            <div class="flex items-start gap-3">
                                <div class="mt-0.5 flex size-8 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                    <flux:icon.users class="size-4 text-zinc-500" />
                                </div>
                                <div>
                                    <flux:subheading size="sm" class="text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.seats') }}</flux:subheading>
                                    <flux:text class="mt-0.5 font-medium">
                                        @if ($preview['currentIncludedSeats'] !== $previewNewIncluded)
                                            {{ __('billing::portal.preview_seats_from_to', ['from' => $preview['currentIncludedSeats'], 'to' => $previewNewIncluded]) }}
                                        @else
                                            {{ trans_choice('billing::portal.seats_included_count', $previewNewIncluded, ['count' => $previewNewIncluded]) }}
                                        @endif
                                    </flux:text>
                                    <flux:text class="text-xs text-zinc-400 dark:text-zinc-500">
                                        {{ __('billing::portal.preview_seats_used', ['count' => $preview['usedSeats'] ?? 0]) }}
                                    </flux:text>
                                    @php
                                        // What the change lands on by default, and what the customer holds
                                        // beyond that today — the seats the choice below is about.
                                        $seatsNow = (int) ($preview['currentSeats'] ?? 0);
                                        $seatsByDefault = max((int) ($preview['usedSeats'] ?? 0), (int) $previewNewIncluded);
                                        $keepableSeats = ($preview['planChanged'] ?? false) && ($preview['seatPriceNet'] ?? null) !== null
                                            ? max(0, $seatsNow - $seatsByDefault)
                                            : 0;
                                    @endphp
                                    <div class="mt-2 text-xs font-medium text-zinc-600 dark:text-zinc-300" dusk="seats-after-change">
                                        {{ trans_choice('billing::portal.preview_seats_after_change', $previewNewSeats, ['count' => $previewNewSeats]) }}
                                    </div>
                                    @if ($previewExtraSeats > 0)
                                        <div class="mt-1 space-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                                            <div>{{ __('billing::portal.preview_seats_total', [
                                                'total' => $previewNewSeats,
                                                'included' => $previewNewIncluded,
                                                'extra' => $previewExtraSeats,
                                            ]) }}</div>
                                            @if ($previewSeatPrice > 0)
                                                <div class="text-zinc-600 dark:text-zinc-300">
                                                    {{ __('billing::portal.preview_seats_extra_price', [
                                                        'price' => BillingMoney::format($previewSeatPrice),
                                                        'interval' => __('billing::portal.interval_' . $selectedInterval),
                                                    ]) }}
                                                </div>
                                            @endif
                                        </div>
                                    @endif
                                    @if ($keepableSeats > 0)
                                        @unless ($keepExtraSeats)
                                            <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                                {{ trans_choice('billing::portal.preview_seats_not_kept', $keepableSeats, ['count' => $keepableSeats]) }}
                                            </div>
                                        @endunless
                                        <div class="mt-2">
                                            <flux:button size="xs" variant="subtle" wire:click="toggleKeepExtraSeats" icon="{{ $keepExtraSeats ? 'x-mark' : 'plus' }}">
                                                {{ $keepExtraSeats ? __('billing::portal.preview_seats_drop_extra') : trans_choice('billing::portal.preview_seats_keep_extra_count', $keepableSeats, ['count' => $keepableSeats]) }}
                                            </flux:button>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>

                    {{-- Right column: Usage changes --}}
                    @if ($hasUsageChanges)
                        <div class="space-y-4 sm:border-l sm:border-zinc-200/75 sm:pl-6 sm:dark:border-zinc-700/50">
                            @foreach (($preview['usageChanges'] ?? []) as $usageType => $usage)
                                <div class="flex items-start gap-3">
                                    <div class="mt-0.5 flex size-8 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                        <flux:icon.chart-bar class="size-4 text-zinc-500" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <flux:subheading size="sm" class="text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.usage') }} · {{ ucfirst($usageType) }}</flux:subheading>
                                        <flux:text class="mt-0.5 font-medium">
                                            @if ($usage['diff'] !== 0)
                                                {{ __('billing::portal.preview_usage_from_to', [
                                                    'from' => $usage['current'] > 0 ? number_format($usage['current']) : '0',
                                                    'to' => $usage['new'] > 0 ? number_format($usage['new']) : '0',
                                                ]) }}
                                                @if ($usage['diff'] > 0)
                                                    <span class="text-emerald-600 dark:text-emerald-400">(+{{ number_format($usage['diff']) }})</span>
                                                @else
                                                    <span class="text-amber-600 dark:text-amber-400">({{ number_format($usage['diff']) }})</span>
                                                @endif
                                            @else
                                                {{ number_format($usage['new']) }} ({{ __('billing::portal.preview_no_change') }})
                                            @endif
                                        </flux:text>

                                        {{-- Current usage stand --}}
                                        <flux:text class="text-xs text-zinc-400 dark:text-zinc-500">
                                            {{ __('billing::portal.preview_usage_current_stand', [
                                                'used' => number_format($usage['actually_used'] ?? 0),
                                                'quota' => number_format($usage['current'] ?? 0),
                                            ]) }}
                                        </flux:text>

                                        {{-- Prorated usage settlement details --}}
                                        @if ($usage['diff'] !== 0 || ($usage['excess'] ?? 0) > 0 || ($usage['actually_used'] ?? 0) > 0)
                                            <div class="mt-2 space-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                                                @if (($usage['prorated_old_quota'] ?? 0) > 0)
                                                    <div>{{ __('billing::portal.preview_usage_used', [
                                                        'used' => number_format($usage['actually_used'] ?? 0),
                                                        'quota' => number_format($usage['prorated_old_quota']),
                                                    ]) }}</div>
                                                @endif
                                                @if (($usage['excess'] ?? 0) > 0)
                                                    <div>
                                                        {{ __('billing::portal.preview_usage_excess', [
                                                            'excess' => number_format($usage['excess']),
                                                        ]) }}
                                                    </div>
                                                    @if (($usage['offset_by_new_plan'] ?? 0) > 0)
                                                        <div>
                                                            {{ __('billing::portal.preview_usage_offset', [
                                                                'count' => number_format($usage['offset_by_new_plan']),
                                                            ]) }}
                                                        </div>
                                                    @endif
                                                    @if (($usage['rollover_credits'] ?? 0) > 0)
                                                        <div>
                                                            {{ __('billing::portal.preview_usage_rollover', [
                                                                'count' => number_format($usage['rollover_credits']),
                                                            ]) }}
                                                        </div>
                                                    @endif
                                                @endif
                                                @if (($usage['excess'] ?? 0) > 0)
                                                    <div>
                                                        {{ __('billing::portal.preview_usage_effective', [
                                                            'quota' => number_format($usage['effective_new_quota'] ?? $usage['new'] ?? 0),
                                                        ]) }}
                                                    </div>
                                                @endif
                                                @if (($usage['unresolved_overage'] ?? 0) > 0)
                                                    <div class="font-medium text-amber-600 dark:text-amber-400">
                                                        {{ __('billing::portal.preview_usage_overage_charge', [
                                                            'count' => number_format($usage['unresolved_overage']),
                                                        ]) }}
                                                    </div>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                </div>
            </div>

            {{-- Warnings & Errors --}}
            @if ($hasBlockingErrors || !empty($incompatibleAddons))
                <div class="border-t border-zinc-200/75 px-6 py-4 space-y-3 dark:border-zinc-700/50">
                    @foreach ($previewErrors as $error)
                        @if (($error['type'] ?? '') === 'seats_exceed_plan')
                            <flux:callout variant="danger" icon="exclamation-triangle">
                                {{ __('billing::portal.error_seats_exceed_plan', [
                                    'used' => $error['used'],
                                    'included' => $error['included'],
                                    'remove' => $error['used'] - $error['included'],
                                ]) }}
                            </flux:callout>
                        @elseif (($error['type'] ?? '') === 'paid_seats_lost')
                            <flux:callout variant="warning" icon="exclamation-triangle">
                                <div class="space-y-2">
                                    <div>
                                        {{ __('billing::portal.error_paid_seats_lost', [
                                            'current' => $error['current'],
                                            'included' => $error['included'],
                                            'lost' => $error['lost'],
                                        ]) }}
                                    </div>
                                </div>
                            </flux:callout>
                        @elseif (($error['type'] ?? '') === 'past_due_expansion')
                            <flux:callout variant="danger" icon="exclamation-triangle" dusk="change-refused-past-due">
                                {{-- The refusal's own sentence: seats and add-ons, a booking, or a plan
                                     change under an open usage amount each say something different. --}}
                                {{ $error['message'] ?? __('billing::portal.error_past_due_expansion') }}
                            </flux:callout>
                        @elseif (($error['type'] ?? '') === 'subscription_ended')
                            <flux:callout variant="danger" icon="exclamation-triangle" dusk="change-refused-subscription-ended">
                                {{ __('billing::portal.error_subscription_ended') }}
                            </flux:callout>
                        @elseif (($error['type'] ?? '') === 'interval_change_under_waiver')
                            <flux:callout variant="warning" icon="exclamation-triangle">
                                {{ __('billing::portal.error_interval_change_under_waiver') }}
                            </flux:callout>
                        @endif
                    @endforeach

                    @if (!empty($incompatibleAddons))
                        <flux:callout variant="warning" icon="exclamation-triangle">
                            {{ __('billing::portal.warning_addons_removed', [
                                'addons' => collect($incompatibleAddons)->map(fn ($code) => $catalog->addonName($code) ?? $code)->join(', '),
                            ]) }}
                        </flux:callout>
                    @endif
                </div>
            @endif

            {{-- Pricing --}}
            @if ($isUpgrade || $isDowngrade || $planChanged || $intervalChanged)
                <div class="border-t border-zinc-200/75 dark:border-zinc-700/50" x-data="{ applyAt: '{{ $showImmediateOption ? 'immediate' : 'end_of_period' }}' }">

                    <div class="grid gap-0 sm:grid-cols-2">

                        {{-- Left panel: Due now --}}
                        <div class="relative bg-accent/5 px-6 py-6 dark:bg-accent/10 sm:rounded-bl-xl">
                            <div class="absolute inset-x-0 top-0 h-0.5 bg-accent sm:inset-y-0 sm:left-auto sm:right-0 sm:h-auto sm:w-0.5"></div>

                            <div class="mb-4">
                                <div class="flex items-center gap-2">
                                    <flux:icon.bolt class="size-4 text-accent" />
                                    <span x-show="applyAt === 'immediate'" class="text-xs font-semibold tracking-wide text-accent uppercase">{{ __('billing::portal.preview_due_now') }}</span>
                                    <span x-show="applyAt === 'end_of_period'" x-cloak class="text-xs font-semibold tracking-wide text-accent uppercase">{{ __('billing::portal.preview_due_now') }}</span>
                                </div>
                            </div>

                            {{-- Immediate view --}}
                            <div x-show="applyAt === 'immediate'" x-cloak>
                                @php
                                    $prorataLines = $preview['prorataLines'] ?? [];
                                    $refundCapNotices = $preview['prorataRefundCapNotices'] ?? [];
                                @endphp

                                {{-- Hinweis, wenn anteilige Erstattung gegen den Restbetrag der Original-Rechnung gekürzt wurde
                                     (z.B. nach vorherigem Kulanz-Refund). --}}
                                @if (! empty($refundCapNotices))
                                    <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200">
                                        <div class="flex items-start gap-2">
                                            <flux:icon.information-circle class="mt-0.5 size-4 shrink-0" />
                                            <div class="space-y-1">
                                                <div class="font-semibold">{{ __('billing::portal.prorata_refund_cap_notice_title') }}</div>
                                                @foreach ($refundCapNotices as $notice)
                                                    @php
                                                        $serial = $notice['invoiceSerial'] ?? null;
                                                        $params = [
                                                            'already' => BillingMoney::format((int) ($notice['alreadyRefundedNet'] ?? 0)),
                                                            'original' => BillingMoney::format((int) ($notice['originalAmountNet'] ?? 0)),
                                                        ];
                                                    @endphp
                                                    <div>
                                                        @if ($serial)
                                                            {{ __('billing::portal.prorata_refund_cap_notice_body', array_merge($params, ['serial' => $serial])) }}
                                                        @else
                                                            {{ __('billing::portal.prorata_refund_cap_notice_body_no_serial', $params) }}
                                                        @endif
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                @endif

                                {{-- Past-Due-Reset-Hinweis: letzte Charge ist fehlgeschlagen, kein Prorata,
                                     stattdessen volle erste Periode. --}}
                                @if (! empty($preview['isPastDueReset']))
                                    <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200">
                                        <div class="flex items-start gap-2">
                                            <flux:icon.information-circle class="mt-0.5 size-4 shrink-0" />
                                            <div>{{ __('billing::portal.past_due_reset_notice') }}</div>
                                        </div>
                                    </div>
                                @endif

                                {{-- Multi-VAT-Aufschlüsselung (neue Preview, wenn ProrataLines vorhanden) --}}
                                @if (! empty($prorataLines))
                                    @php
                                        $linesByCategory = [
                                            'plan' => array_filter($prorataLines, fn ($l) => $l['kind'] === 'plan'),
                                            'seats' => array_filter($prorataLines, fn ($l) => $l['kind'] === 'seats'),
                                            'addon' => array_filter($prorataLines, fn ($l) => in_array($l['kind'], ['addon', 'addon_seats'], true)),
                                            'coupon' => array_filter($prorataLines, fn ($l) => $l['kind'] === 'coupon'),
                                        ];

                                        // Per-Usage-Type Mehrverbrauch — separater Mollie-Charge,
                                        // aber für UI-Transparenz im "Jetzt fällig"-Block sichtbar.
                                        $usageChanges = (array) ($preview['usageChanges'] ?? []);
                                        $overageNet = (int) ($preview['usageOverageChargeNet'] ?? 0);
                                        $overageGross = (int) ($preview['usageOverageChargeGross'] ?? 0);
                                        $overageVatRate = (float) ($preview['vatRate'] ?? 0);
                                        $overageLines = [];
                                        foreach ($usageChanges as $type => $u) {
                                            $unitsCharged = (int) ($u['unresolved_overage'] ?? 0);
                                            $netForType = (int) ($u['overage_total_net'] ?? 0);
                                            if ($unitsCharged <= 0 || $netForType <= 0) {
                                                continue;
                                            }
                                            $grossForType = $overageNet > 0
                                                ? (int) round($overageGross * $netForType / $overageNet)
                                                : $netForType;
                                            $overageLines[] = [
                                                'type' => $type,
                                                'units' => $unitsCharged,
                                                'unit_price_net' => (int) ($u['overage_unit_price_net'] ?? 0),
                                                'gross' => $grossForType,
                                            ];
                                        }

                                        // "Due now" is the money Mollie takes now, not the balance of the
                                        // change. InvoiceService::createCharge() collects the sum of the
                                        // charge-direction lines; the refund-direction lines become
                                        // separate Mollie refunds in phase 2, after that payment clears
                                        // (ProrataExecutor::execute()). Netting them showed "+€5.00" on a
                                        // plan switch that charges €95 and credits €90 — and the €95 is
                                        // what the customer sees on their card. Coupon lines are
                                        // charge-direction with a negative amount, so they belong in
                                        // $chargeGross exactly as they do in the Mollie total.
                                        $chargeGross = 0;
                                        $refundGross = 0;
                                        foreach ($prorataLines as $line) {
                                            if (($line['direction'] ?? 'charge') === 'refund') {
                                                $refundGross += (int) $line['amount_gross'];
                                            } else {
                                                $chargeGross += (int) $line['amount_gross'];
                                            }
                                        }

                                        // Saldo-zero plan switch: ProrataExecutor books it as a local
                                        // plan-switch invoice and never touches Mollie, so nothing is due.
                                        $isSidegrade = ($chargeGross + $refundGross) === 0
                                            && $chargeGross > 0
                                            && ! empty($linesByCategory['plan'])
                                            && count(array_filter($linesByCategory['plan'], fn ($l) => $l['direction'] === 'charge')) > 0
                                            && count(array_filter($linesByCategory['plan'], fn ($l) => $l['direction'] === 'refund')) > 0;

                                        $chargedNowGross = $isSidegrade ? 0 : max(0, $chargeGross);
                                        $refundedGross = $isSidegrade ? 0 : abs($refundGross);
                                        $totalGross = $chargedNowGross + $overageGross;
                                    @endphp

                                    <div class="space-y-4">
                                        {{-- Plan-Sektion --}}
                                        @if (! empty($linesByCategory['plan']))
                                            <div class="space-y-2">
                                                @foreach ($linesByCategory['plan'] as $line)
                                                    <div class="flex items-baseline justify-between gap-3 text-sm">
                                                        <div class="min-w-0 flex-1">
                                                            <div class="text-zinc-600 dark:text-zinc-300 wrap-break-word">{{ $line['label'] }}</div>
                                                            <div class="mt-0.5 text-xs text-zinc-400 wrap-break-word">
                                                                @if ($line['is_coupon_covered'])
                                                                    {{ __('billing::portal.prorata_via_coupon_no_refund') }}
                                                                @elseif ($line['vat_rate'] == 0)
                                                                    {{ __('billing::portal.prorata_reverse_charge') }}
                                                                @else
                                                                    {{ __('billing::portal.prorata_incl_vat_rate', ['rate' => number_format((float) $line['vat_rate'], 0)]) }}
                                                                @endif
                                                                @if (($line['days_remaining'] ?? 0) > 0)
                                                                    <span class="text-zinc-300 dark:text-zinc-600">·</span> {{ __('billing::portal.prorata_plan_remaining_days', ['days' => $line['days_remaining']]) }}
                                                                @endif
                                                            </div>
                                                            @if (! empty($line['refund_cap_note']))
                                                                <div class="mt-0.5 text-[0.7rem] text-zinc-400 wrap-break-word">
                                                                    {{ __('billing::portal.prorata_line_capped_by_prior_refund', ['amount' => BillingMoney::format((int) ($line['refund_cap_note']['alreadyRefundedNet'] ?? 0))]) }}
                                                                </div>
                                                            @endif
                                                        </div>
                                                        <span class="shrink-0 tabular-nums font-medium {{ $line['amount_gross'] < 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-700 dark:text-zinc-200' }}">
                                                            {{ $line['amount_gross'] < 0 ? '−' : '+' }}{{ BillingMoney::format(abs($line['amount_gross'])) }}
                                                        </span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif

                                        {{-- Sitze-Sektion --}}
                                        @if (! empty($linesByCategory['seats']))
                                            <div class="space-y-2">
                                                <div class="text-[0.65rem] font-semibold uppercase tracking-[0.12em] text-zinc-400">{{ __('billing::portal.prorata_seats_group') }}</div>
                                                @foreach ($linesByCategory['seats'] as $line)
                                                    <div class="flex items-baseline justify-between gap-3 text-sm">
                                                        <div class="min-w-0 flex-1">
                                                            <div class="text-zinc-600 dark:text-zinc-300 wrap-break-word">{{ $line['label'] }}</div>
                                                            <div class="mt-0.5 text-xs text-zinc-400 wrap-break-word">
                                                                @if ($line['is_coupon_covered'])
                                                                    {{ __('billing::portal.prorata_via_coupon_no_refund') }}
                                                                @elseif ($line['vat_rate'] == 0)
                                                                    {{ __('billing::portal.prorata_reverse_charge') }}
                                                                @else
                                                                    {{ __('billing::portal.prorata_incl_vat_rate', ['rate' => number_format((float) $line['vat_rate'], 0)]) }}
                                                                @endif
                                                            </div>
                                                            @if (! empty($line['refund_cap_note']))
                                                                <div class="mt-0.5 text-[0.7rem] text-zinc-400 wrap-break-word">
                                                                    {{ __('billing::portal.prorata_line_capped_by_prior_refund', ['amount' => BillingMoney::format((int) ($line['refund_cap_note']['alreadyRefundedNet'] ?? 0))]) }}
                                                                </div>
                                                            @endif
                                                        </div>
                                                        <span class="shrink-0 tabular-nums font-medium {{ $line['amount_gross'] < 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-700 dark:text-zinc-200' }}">
                                                            {{ $line['amount_gross'] < 0 ? '−' : '+' }}{{ BillingMoney::format(abs($line['amount_gross'])) }}
                                                        </span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif

                                        {{-- Addons-Sektion --}}
                                        @if (! empty($linesByCategory['addon']))
                                            <div class="space-y-2">
                                                <div class="text-[0.65rem] font-semibold uppercase tracking-[0.12em] text-zinc-400">{{ __('billing::portal.prorata_addons_group') }}</div>
                                                @foreach ($linesByCategory['addon'] as $line)
                                                    <div class="flex items-baseline justify-between gap-3 text-sm">
                                                        <div class="min-w-0 flex-1">
                                                            <div class="text-zinc-600 dark:text-zinc-300 wrap-break-word">{{ $line['label'] }}</div>
                                                            <div class="mt-0.5 text-xs text-zinc-400 wrap-break-word">
                                                                @if ($line['is_coupon_covered'])
                                                                    {{ __('billing::portal.prorata_via_coupon_no_refund') }}
                                                                @elseif ($line['vat_rate'] == 0)
                                                                    {{ __('billing::portal.prorata_reverse_charge') }}
                                                                @else
                                                                    {{ __('billing::portal.prorata_incl_vat_rate', ['rate' => number_format((float) $line['vat_rate'], 0)]) }}
                                                                @endif
                                                            </div>
                                                            @if (! empty($line['refund_cap_note']))
                                                                <div class="mt-0.5 text-[0.7rem] text-zinc-400 wrap-break-word">
                                                                    {{ __('billing::portal.prorata_line_capped_by_prior_refund', ['amount' => BillingMoney::format((int) ($line['refund_cap_note']['alreadyRefundedNet'] ?? 0))]) }}
                                                                </div>
                                                            @endif
                                                        </div>
                                                        <span class="shrink-0 tabular-nums font-medium {{ $line['amount_gross'] < 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-700 dark:text-zinc-200' }}">
                                                            {{ $line['amount_gross'] < 0 ? '−' : '+' }}{{ BillingMoney::format(abs($line['amount_gross'])) }}
                                                        </span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif

                                        {{-- Coupon-Sektion --}}
                                        @if (! empty($linesByCategory['coupon']))
                                            <div class="space-y-2">
                                                <div class="text-[0.65rem] font-semibold uppercase tracking-[0.12em] text-zinc-400">{{ __('billing::portal.coupon_section') }}</div>
                                                @foreach ($linesByCategory['coupon'] as $line)
                                                    <div class="flex items-baseline justify-between gap-3 text-sm">
                                                        <div class="min-w-0 flex-1">
                                                            <div class="text-zinc-600 dark:text-zinc-300 wrap-break-word">{{ $line['label'] }}</div>
                                                            <div class="mt-0.5 text-xs text-zinc-400 wrap-break-word">
                                                                @if ($line['vat_rate'] == 0)
                                                                    {{ __('billing::portal.prorata_reverse_charge') }}
                                                                @else
                                                                    {{ __('billing::portal.prorata_incl_vat_rate', ['rate' => number_format((float) $line['vat_rate'], 0)]) }}
                                                                @endif
                                                            </div>
                                                        </div>
                                                        <span class="shrink-0 tabular-nums font-medium text-emerald-600 dark:text-emerald-400">
                                                            −{{ BillingMoney::format(abs($line['amount_gross'])) }}
                                                        </span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif

                                        {{-- Mehrverbrauch-Sektion (separater Mollie-Charge, hier nur zur Transparenz) --}}
                                        @if (! empty($overageLines))
                                            <div class="space-y-2">
                                                <div class="text-[0.65rem] font-semibold uppercase tracking-[0.12em] text-zinc-400">{{ __('billing::portal.prorata_overage_group') }}</div>
                                                @foreach ($overageLines as $oline)
                                                    <div class="flex items-baseline justify-between gap-3 text-sm">
                                                        <div class="min-w-0 flex-1">
                                                            <div class="text-zinc-600 dark:text-zinc-300 wrap-break-word">{{ __('billing::portal.invoice_line_overage', ['type' => $oline['type']]) }}</div>
                                                            <div class="mt-0.5 text-xs text-zinc-400 wrap-break-word">
                                                                {{ $oline['units'] }}× {{ BillingMoney::format($oline['unit_price_net']) }}
                                                                <span class="text-zinc-300 dark:text-zinc-600">·</span>
                                                                @if ($overageVatRate == 0)
                                                                    {{ __('billing::portal.prorata_reverse_charge') }}
                                                                @else
                                                                    {{ __('billing::portal.prorata_incl_vat_rate', ['rate' => number_format($overageVatRate, 0)]) }}
                                                                @endif
                                                            </div>
                                                        </div>
                                                        <span class="shrink-0 tabular-nums font-medium text-zinc-700 dark:text-zinc-200">
                                                            +{{ BillingMoney::format($oline['gross']) }}
                                                        </span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif

                                        {{-- Total --}}
                                        <div class="mt-1 border-t border-zinc-200/70 pt-3 dark:border-zinc-700/60">
                                            <div class="flex items-baseline justify-between gap-3">
                                                <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">{{ __('billing::portal.prorata_total') }}</span>
                                                <span dusk="prorata-total" class="shrink-0 text-2xl font-bold tabular-nums tracking-tight {{ $totalGross > 0 ? 'text-zinc-900 dark:text-white' : 'text-emerald-600 dark:text-emerald-400' }}">
                                                    {{ $totalGross > 0 ? '+' : '' }}{{ BillingMoney::format($totalGross) }}
                                                </span>
                                            </div>

                                            @if ($isSidegrade)
                                                <div class="mt-1.5 text-xs italic text-zinc-500 dark:text-zinc-400">
                                                    {{ __('billing::portal.prorata_sidegrade_no_charge') }}
                                                </div>
                                            @elseif ($refundedGross > 0)
                                                <div dusk="prorata-refund-separate" data-refund-minor="{{ $refundedGross }}" class="mt-1.5 text-xs italic text-zinc-500 dark:text-zinc-400">
                                                    {{-- "Once this payment has been captured" only where there is one. --}}
                                                    {{ __($chargedNowGross > 0 ? 'billing::portal.prorata_refund_separate' : 'billing::portal.prorata_refund_only', ['amount' => BillingMoney::format($refundedGross)]) }}
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @else
                                @php
                                    $prorataCharge = $preview['prorataChargeNet'] ?? 0;
                                    $prorataCredit = $preview['prorataCreditNet'] ?? 0;
                                    if ($prorataCharge > 0) {
                                        // Upgrade: new plan = charge + credit, credit shown as deduction.
                                        $dueNowNewPlan = $prorataCharge + $prorataCredit;
                                        $dueNowCredit = $prorataCredit;
                                    } elseif ($prorataCredit > 0) {
                                        // Downgrade: new plan at full price, credit = refund + new plan.
                                        $dueNowNewPlan = $preview['newPriceNet'] ?? 0;
                                        $dueNowCredit = $prorataCredit + $dueNowNewPlan;
                                    } else {
                                        $dueNowNewPlan = 0;
                                        $dueNowCredit = 0;
                                    }
                                    // Collected now = the pro-rata charge plus the usage overage, both
                                    // taken from the preview's own gross figures. The credit is a
                                    // separate Mollie refund, so netting it in named an amount nobody
                                    // moves; and re-deriving VAT from the summed net with the recurring
                                    // rate disagreed with the per-component rates the preview used
                                    // (the pro-rata rate comes off the invoice that paid the period).
                                    $dueNowVatRate = (float) ($preview['vatRate'] ?? 0);
                                    $dueNowNet = $prorataCharge + $usageOverageNet;
                                    $dueNowGross = (int) ($preview['prorataChargeGross'] ?? 0)
                                        + (int) ($preview['usageOverageChargeGross'] ?? 0);
                                    $dueNowVat = $dueNowGross - $dueNowNet;
                                    $refundedNet = $prorataCharge > 0 ? 0 : $prorataCredit;
                                @endphp
                                <div class="space-y-2">
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="text-zinc-600 dark:text-zinc-300">{{ __('billing::portal.preview_prorata_new_plan') }}</span>
                                        <span class="tabular-nums font-medium text-zinc-800 dark:text-zinc-300">{{ BillingMoney::format($dueNowNewPlan) }}</span>
                                    </div>
                                    @if ($dueNowCredit > 0)
                                        @php
                                            $remainingDays = (int) ($preview['prorataRemainingDays'] ?? 0);
                                            $paidSeatsLost = collect($previewErrors)->firstWhere('type', 'paid_seats_lost');
                                        @endphp
                                        <div class="flex items-center justify-between text-sm">
                                            <span class="text-zinc-600 dark:text-zinc-300">
                                                {{ __('billing::portal.preview_prorata_credit') }}
                                                @if ($remainingDays > 0)
                                                    <span class="text-xs text-zinc-400 dark:text-zinc-500">({{ __('billing::portal.preview_prorata_days_'.($remainingDays === 1 ? 'one' : 'many'), ['count' => $remainingDays]) }})</span>
                                                @endif
                                            </span>
                                            <span class="tabular-nums font-medium text-emerald-600 dark:text-emerald-400">−{{ BillingMoney::format($dueNowCredit) }}</span>
                                        </div>
                                        @if ($paidSeatsLost)
                                            <div class="text-xs text-zinc-400 dark:text-zinc-500 italic">
                                                {{ __('billing::portal.preview_prorata_credit_includes_seats_'.($paidSeatsLost['lost'] === 1 ? 'one' : 'many'), ['count' => $paidSeatsLost['lost']]) }}
                                            </div>
                                        @endif
                                    @endif

                                    @if ($usageOverageNet > 0)
                                        @foreach (($preview['usageChanges'] ?? []) as $usageType => $usage)
                                            @if (($usage['unresolved_overage'] ?? 0) > 0 && ($usage['overage_total_net'] ?? 0) > 0)
                                                <div class="flex items-center justify-between text-sm">
                                                    <span class="text-zinc-600 dark:text-zinc-300">{{ __('billing::portal.preview_usage_overage_line', ['type' => ucfirst($usageType)]) }}</span>
                                                    <span class="tabular-nums font-medium text-zinc-700 dark:text-zinc-200">{{ BillingMoney::format($usage['overage_total_net']) }}</span>
                                                </div>
                                            @endif
                                        @endforeach
                                    @endif

                                    <flux:separator class="my-2!" />

                                    <div class="flex items-center justify-between text-sm">
                                        <span class="text-zinc-500 dark:text-zinc-400">{{ __('billing::portal.net') }}</span>
                                        <span class="tabular-nums text-zinc-600 dark:text-zinc-300">{{ BillingMoney::format($dueNowNet) }}</span>
                                    </div>
                                    @if ($dueNowVat > 0)
                                        <div class="flex items-center justify-between text-sm">
                                            <span class="text-zinc-500 dark:text-zinc-400">{{ __('billing::portal.vat') }} ({{ number_format($dueNowVatRate, 0) }}%)</span>
                                            <span class="tabular-nums text-zinc-600 dark:text-zinc-300">{{ BillingMoney::format($dueNowVat) }}</span>
                                        </div>
                                    @endif

                                    <flux:separator class="my-2!" />

                                    <div class="flex items-center justify-between">
                                        <span class="text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ __('billing::portal.preview_total') }}</span>
                                        <span class="text-2xl font-bold tabular-nums {{ $dueNowGross > 0 ? 'text-zinc-900 dark:text-white' : 'text-emerald-600 dark:text-emerald-400' }}">{{ BillingMoney::format($dueNowGross) }}</span>
                                    </div>

                                    @if ($refundedNet > 0)
                                        <flux:text class="text-xs text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.preview_credit_note') }}</flux:text>
                                    @endif
                                </div>
                                @endif {{-- end of @if (! empty($prorataLines)) ... @else --}}
                            </div>

                            {{-- End-of-period view: no additional costs --}}
                            <div x-show="applyAt === 'end_of_period'" x-cloak>
                                <div class="space-y-2">
                                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                        {{ __('billing::portal.preview_no_additional_costs') }}
                                    </flux:text>

                                    <flux:separator class="my-2!" />

                                    <div class="flex items-center justify-between">
                                        <span class="text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ __('billing::portal.preview_total') }}</span>
                                        <span class="text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ BillingMoney::format(0) }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Right panel: Recurring price --}}
                        <div class="bg-zinc-50/80 px-6 py-6 dark:bg-white/[0.02] sm:rounded-br-xl">
                            <div class="mb-4 flex items-center gap-2">
                                <flux:icon.arrow-path class="size-4 text-zinc-400 dark:text-zinc-500" />
                                <span class="text-xs font-semibold tracking-wide text-accent uppercase">{{ __('billing::portal.preview_recurring_price') }}</span>
                            </div>

                            <div class="space-y-2">
                                @foreach (($preview['lineItems'] ?? []) as $item)
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="text-zinc-600 dark:text-zinc-300">
                                            {{ $item['label'] }}
                                            @if (($item['quantity'] ?? 1) > 1)
                                                <span class="text-zinc-400">× {{ $item['quantity'] }}</span>
                                            @endif
                                        </span>
                                        <span class="tabular-nums font-medium {{ ($item['total_net'] ?? 0) < 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-700 dark:text-zinc-200' }}">
                                            {{ ($item['total_net'] ?? 0) < 0 ? '−' : '' }}{{ BillingMoney::format(abs($item['total_net'] ?? 0)) }}
                                        </span>
                                    </div>
                                @endforeach

                                <flux:separator class="my-2!" />

                                <div class="flex items-center justify-between text-sm">
                                    <span class="text-zinc-500">{{ __('billing::portal.net') }}</span>
                                    <span class="tabular-nums text-zinc-600 dark:text-zinc-300">{{ BillingMoney::format($preview['newPriceNet'] ?? 0) }}</span>
                                </div>
                                @if ($preview['reverseCharge'] ?? false)
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="text-zinc-500">{{ __('billing::portal.vat') }}</span>
                                        <span class="tabular-nums text-emerald-700 dark:text-emerald-400">{{ __('billing::checkout.reverse_charge') }}</span>
                                    </div>
                                @elseif (($preview['vatAmount'] ?? 0) > 0)
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="text-zinc-500">{{ __('billing::portal.vat') }} ({{ number_format($preview['vatRate'] ?? 0, 0) }}%)</span>
                                        <span class="tabular-nums text-zinc-600 dark:text-zinc-300">{{ BillingMoney::format($preview['vatAmount']) }}</span>
                                    </div>
                                @endif

                                <flux:separator class="my-2!" />

                                <div class="flex items-center justify-between">
                                    <span class="text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ ($preview['reverseCharge'] ?? false) ? __('billing::portal.net') : __('billing::portal.gross') }}</span>
                                    <span class="text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ BillingMoney::format($preview['grossTotal'] ?? 0) }}</span>
                                </div>
                                <flux:text class="text-xs">{{ __('billing::portal.preview_recurring_from_next_period') }}</flux:text>

                                <flux:separator class="my-3!" />

                                <div class="space-y-2">
                                    @foreach ($appliedCouponInfo as $info)
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
                                                wire:click="removeCoupon({{ \Illuminate\Support\Js::from($info['code']) }})"
                                                :aria-label="__('billing::checkout.remove_coupon')"
                                            />
                                        </div>
                                    @endforeach

                                    @if ($this->canAddMoreCoupons())
                                        <flux:input.group>
                                            <flux:input
                                                size="sm"
                                                wire:model="couponInput"
                                                wire:keydown.enter.prevent="applyCoupon"
                                                :placeholder="__('billing::portal.coupon_code_placeholder')"
                                            />
                                            <flux:button size="sm" type="button" wire:click="applyCoupon" icon="check">
                                                {{ __('billing::portal.coupon_redeem_button') }}
                                            </flux:button>
                                        </flux:input.group>
                                    @endif

                                    @if ($couponError)
                                        <flux:text class="text-xs text-rose-600 dark:text-rose-400">{{ $couponError }}</flux:text>
                                    @endif

                                    @if (! empty($preview['warnings']))
                                        @foreach ($preview['warnings'] as $warning)
                                            <flux:text class="text-xs text-rose-600 dark:text-rose-400">{{ $warning }}</flux:text>
                                        @endforeach
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>

                    @if ($billable?->isBillingPastDue() && $showScheduledOption && ! $hasBlockingErrors)
                        {{-- A cheaper plan can be scheduled while a payment is open; a dearer
                             one cannot. Either way the open payment stays what it is. --}}
                        <div class="border-t border-zinc-200/75 px-6 py-4 dark:border-zinc-700/50">
                            <flux:callout variant="warning" icon="exclamation-triangle" dusk="schedule-past-due-notice">
                                {{ __('billing::portal.schedule_past_due_notice') }}
                            </flux:callout>
                        </div>
                    @endif

                    {{-- Action bar: Switch + Button --}}
                    <div class="border-t border-zinc-200/75 px-6 py-4 dark:border-zinc-700/50">
                        <div class="flex items-center justify-between">
                            {{-- Switcher (left) --}}
                            @if ($showImmediateOption && $showScheduledOption)
                                <div class="inline-flex rounded-lg bg-zinc-100 p-0.5 dark:bg-zinc-800">
                                    <button type="button"
                                        class="rounded-md px-3 py-1.5 text-sm font-medium transition"
                                        :class="applyAt === 'immediate' ? 'bg-white shadow text-zinc-900 dark:bg-zinc-700 dark:text-white' : 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200'"
                                        @click="applyAt = 'immediate'">
                                        {{ __('billing::portal.apply_immediately') }}
                                    </button>
                                    <button type="button"
                                        class="rounded-md px-3 py-1.5 text-sm font-medium transition"
                                        :class="applyAt === 'end_of_period' ? 'bg-white shadow text-zinc-900 dark:bg-zinc-700 dark:text-white' : 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200'"
                                        @click="applyAt = 'end_of_period'">
                                        {{ __('billing::portal.schedule_end_of_period') }}
                                    </button>
                                </div>
                            @else
                                <div></div>
                            @endif

                            {{-- Button (right) --}}
                            @if ($hasBlockingErrors)
                                <flux:button variant="primary" size="sm" disabled>
                                    {{ __('billing::portal.apply_now') }}
                                </flux:button>
                            @else
                                <flux:button variant="primary" size="sm"
                                    x-on:click="$wire.applyChange(applyAt)"
                                    wire:loading.attr="disabled"
                                    wire:target="applyChange">
                                    <flux:icon.arrow-path class="size-4 animate-spin" wire:loading wire:target="applyChange" />
                                    <span wire:loading.remove wire:target="applyChange">
                                        <span x-show="applyAt === 'immediate'">{{ __('billing::portal.apply_immediately') }}</span>
                                        <span x-show="applyAt === 'end_of_period'" x-cloak>{{ __('billing::portal.schedule_end_of_period') }}</span>
                                    </span>
                                    <span wire:loading wire:target="applyChange">
                                        <span x-show="applyAt === 'immediate'">{{ __('billing::portal.apply_immediately') }}</span>
                                        <span x-show="applyAt === 'end_of_period'" x-cloak>{{ __('billing::portal.schedule_end_of_period') }}</span>
                                    </span>
                                </flux:button>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            {{-- Action button (when no pricing section) --}}
            @if (! ($isUpgrade || $isDowngrade || $planChanged || $intervalChanged))
                <div class="border-t border-zinc-200/75 px-6 py-4 dark:border-zinc-700/50">
                    <div class="flex justify-end">
                        @if ($hasBlockingErrors)
                            <flux:button variant="primary" size="sm" disabled>
                                {{ __('billing::portal.apply_now') }}
                            </flux:button>
                        @else
                            <flux:button variant="primary" size="sm" wire:click="applyChange('immediate')">
                                {{ __('billing::portal.apply_immediately') }}
                            </flux:button>
                        @endif
                    </div>
                </div>
            @endif
        </flux:card>
    @endif

    {{-- Edit billing details modal --}}
    @php
        // Resolved outside the modal: `@php(...)` does not compile reliably inside
        // a Blade component slot. Error wins over the non-error VIES verdict.
        $vatMessage = $errors->first('vat_number') ?: $vatStatusMessage;

        // A blocking error can exist while `$vatNumberValid` is still null (VIES
        // unreachable, "correct or clear the number"). Those must read as errors,
        // not as the amber pending state.
        $vatHasError = $vatNumberValid === false || $errors->has('vat_number');
    @endphp
    <flux:modal name="edit-billing-modal" wire:model.self="showEditBillingModal" class="md:w-[36rem]">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('billing::portal.edit_billing_data') }}</flux:heading>
                <flux:subheading>{{ __('billing::portal.edit_billing_data_subtitle') }}</flux:subheading>
            </div>

            <div class="flex flex-col gap-4">
                <flux:input wire:model.live.debounce.500ms="company_name" :label="__('billing::checkout.company_name')" type="text" required autofocus />
                <flux:input wire:model.live.debounce.500ms="billing_street" :label="__('billing::checkout.street')" type="text" required />

                <div class="grid items-start gap-4 sm:grid-cols-[1fr_2fr]">
                    <flux:input wire:model.live.debounce.500ms="billing_postal_code" :label="__('billing::checkout.postal_code')" type="text" required />
                    <flux:input wire:model.live.debounce.500ms="billing_city" :label="__('billing::checkout.city')" type="text" required />
                </div>

                {{-- items-start: a validation message under the VAT field must not
                     stretch or re-center the country select next to it. --}}
                <div class="grid items-start gap-4 sm:grid-cols-2">
                    <flux:select wire:model.live="billing_country" :label="__('billing::checkout.country')" required>
                        @foreach (\GraystackIT\MollieBilling\Support\CountryResolver::resolve() as $iso => $name)
                            <flux:select.option value="{{ $iso }}">{{ $name }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:field class="block w-full min-w-0">
                        <flux:label>{{ __('billing::checkout.vat_number') }}</flux:label>
                        <flux:input.group class="w-full">
                            <flux:input wire:model.live.debounce.500ms="vat_number" type="text" placeholder="ATU12345678" class="min-w-0 grow" />
                            {{-- Always-rendered suffix; see step-billing.blade.php for the rationale. --}}
                            <flux:input.group.suffix>
                                @if ($vatNumberValid === true)
                                    <flux:icon.check-circle class="size-4 text-emerald-700 dark:text-emerald-400" />
                                @elseif ($vatNumberValid === false)
                                    <flux:icon.x-circle class="size-4 text-red-600 dark:text-red-400" />
                                @else
                                    <flux:icon.information-circle class="size-4 text-zinc-300 dark:text-zinc-600" />
                                @endif
                            </flux:input.group.suffix>
                        </flux:input.group>

                        {{-- The wrapper itself reserves one line of feedback space,
                             so errors and the VIES verdict swap in place instead of
                             pushing the modal around on every keystroke — including
                             while a check is still in flight. --}}
                        <div class="mt-2 min-h-4 text-xs">
                            @if ($vatMessage)
                                <span
                                    role="status"
                                    class="flex items-start gap-1.5 {{ $vatHasError ? 'text-red-600 dark:text-red-400' : ($vatNumberValid === true ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400') }}"
                                >
                                    @if ($vatHasError)
                                        <flux:icon.exclamation-triangle class="mt-px size-3.5 shrink-0" />
                                    @elseif ($vatNumberValid === true)
                                        <flux:icon.check class="mt-px size-3.5 shrink-0" />
                                    @endif
                                    <span>{{ $vatMessage }}</span>
                                </span>
                            @endif
                        </div>
                    </flux:field>
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('billing::portal.cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="saveBillingDetails" wire:loading.attr="disabled" wire:target="saveBillingDetails">
                    <flux:icon.arrow-path class="size-4 animate-spin" wire:loading wire:target="saveBillingDetails" />
                    <span>{{ __('billing::portal.save') }}</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
