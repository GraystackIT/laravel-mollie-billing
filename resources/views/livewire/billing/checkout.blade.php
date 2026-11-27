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
use GraystackIT\MollieBilling\Support\CheckoutDraft;
use GraystackIT\MollieBilling\Support\Sanitize;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
     * A changed order waits for an earlier first payment Mollie would not withdraw
     * (`PendingPaymentForAnotherOrderException`): whether to say so, until when that payment
     * stays payable (ISO-8601, UTC; null when Mollie did not say) and its checkout link to
     * finish it (null when Mollie no longer offers one). Locked: the link is rendered as an
     * href and only the server may put a Mollie URL there.
     */
    #[Locked]
    public bool $pendingOtherOrder = false;
    #[Locked]
    public ?string $pendingOtherOrderUntil = null;
    #[Locked]
    public ?string $pendingOtherOrderUrl = null;

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

    /**
     * Where this checkout's draft is filed in the session ({@see CheckoutDraft}), or null when
     * this instance keeps none: drafts switched off, or the checkout was opened for a billable
     * the visitor may not act for. Derived on mount from the signed-in user, the authorized
     * billable and the checkout URL — never from anything the browser sends.
     */
    #[Locked]
    public ?string $draftKey = null;

    /** Hash of the signed-in user the draft belongs to; a different one stops it being written. */
    #[Locked]
    public ?string $draftViewer = null;

    /** The billable the draft is filed for ("class:key", or "new" for a signup). */
    #[Locked]
    public string $draftSubject = 'new';

    /**
     * The customer took the promotion link's code off. It is then not put back by
     * `adoptAutoApplyCoupon()` the next time the selection changes.
     */
    public bool $promotionRemoved = false;

    /** Something the draft keeps changed during this request. Per request, never serialised. */
    private bool $draftDirty = false;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    /** Field names in validation messages: the labels, not the property names. */
    protected function validationAttributes(): array
    {
        return \GraystackIT\MollieBilling\Support\BillingFieldNames::attributes();
    }

    public function mount(): void
    {
        $this->backUrl = Sanitize::backUrl(request()->query('back'));

        $this->billing_country = MollieBilling::ipGeolocation()->defaultCountryFor(request()->ip());

        // If a billable is already resolvable from the request (e.g. checkout
        // entered after a force-cancelled local subscription), pre-fill the
        // billing-address fields and lock the billing step. The controller
        // already redirects away when an active subscription exists, so this
        // branch only runs for billables without an accessible subscription.
        //
        $existing = MollieBilling::resolveBillable(request());

        // Reads need the same authorization the writes have always had. On a package
        // route `resolveBillable()` falls back to matching any unreserved query
        // parameter against a billable's route key, so `?org=<victim-key>` resolved a
        // stranger — and this branch then rendered their company name, street, city
        // and VAT number read-only on the confirm step. That was the one half of this
        // component which never asked `authorizes()`.
        //
        // Identity and data are separated deliberately. The ($billableId, $billableClass)
        // pair says who this checkout is *for*, and `submit()` authorizes against it —
        // so a team member who is not the billing manager still gets a 403 there rather
        // than silently having a second billable created. What must not happen is
        // copying the fields, because that is the disclosure. A query-parameter guess
        // is dropped entirely: it was never a statement about this visitor.
        $refused = $existing !== null && ! MollieBilling::authorizes(request(), $existing);

        if ($refused && MollieBilling::resolveBillableFromCallback(request()) === null) {
            $existing = null;
            $refused = false;
        }

        if ($refused) {
            /** @var Model&Billable $existing */
            $this->billableId = (string) $existing->getKey();
            $this->billableClass = $existing->getMorphClass();
            $existing = null;
        }

        // Identity first, and unconditionally — see the note above. Adopting it only
        // together with the prefill made one predicate answer two questions, and an
        // authorized billable without a complete address (a fresh signup, an org whose
        // access grant just lapsed) fell through to `createBillable()` in submit(): the
        // customer paid, a *second* organisation was created to hold the subscription, and
        // their own one stayed unsubscribed — bounced back to checkout by
        // RequireActiveSubscription and eligible for orphan cleanup.
        if ($existing !== null) {
            /** @var Model&Billable $existing */
            $this->billableId = (string) $existing->getKey();
            $this->billableClass = $existing->getMorphClass();
        }

        // Paid, and the subscription not there yet — the webhook still on its way, or the
        // activation running or waiting for its retry (~7 h). `RequireActiveSubscription`
        // sends exactly this customer here, and the submit can only lead to the return page
        // (`PendingFirstPayment::redirectFor()`): go there now instead of walking them through
        // a form for an order that cannot be placed. Only for a billable this visitor may act
        // for — a refused one learns nothing about its state. A refused €0 authorisation and
        // an activation that failed for good leave nothing in flight and show the checkout.
        if ($existing !== null && ! $existing->hasAccessibleBillingSubscription()) {
            $underway = app(\GraystackIT\MollieBilling\Services\Billing\PendingFirstPayment::class)->activationUnderway($existing);

            if ($underway !== null) {
                $this->redirect($underway, navigate: false);

                return;
            }
        }

        // The prefill, the lock and the step skip are the address question, and they stay
        // gated on actually having an address to show.
        if ($existing !== null && $this->hasPersistedBillingData($existing)) {
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

        // An unfinished checkout of this visitor, for this billable and this checkout URL. Never
        // for a billable the visitor was refused: there is nothing of theirs to resume there.
        $draft = $refused ? null : $this->openDraft($existing);

        if ($draft !== null) {
            $this->restoreDraftInputs($draft);
        }

        // Pre-select plan and/or interval from query parameters. A link that names a different
        // plan or interval than the draft is a new choice: it wins, and the resume stops at the
        // plan step so the customer sees what they are now buying.
        $queryOverridesDraft = false;

        $plan = request()->query('plan');
        if (is_string($plan) && array_key_exists($plan, $this->plans())) {
            $queryOverridesDraft = $draft !== null && $plan !== $this->plan_code;
            $this->plan_code = $plan;
        }

        $interval = request()->query('interval');
        if (is_string($interval) && in_array($interval, ['monthly', 'yearly'], true)) {
            $queryOverridesDraft = $queryOverridesDraft || ($draft !== null && $interval !== $this->interval);
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

        if ($draft !== null) {
            $this->resumeDraft($draft, $queryOverridesDraft);
        }

        $this->adoptAutoApplyCoupon();
    }

    // -------------------------------------------------------------------------
    // Checkout draft
    // -------------------------------------------------------------------------

    /**
     * Mark the draft for writing whenever the browser changed a property. Which properties end
     * up in it is decided by {@see draftPayload()}, not here.
     */
    public function updated(string $property): void
    {
        $this->draftDirty = true;
    }

    /** Write the draft at the end of every request that changed something it keeps. */
    public function dehydrate(): void
    {
        if ($this->draftDirty) {
            $this->persistDraft();
        }
    }

    /**
     * Derive this checkout's draft key and read the draft filed under it.
     *
     * @return array<string, mixed>|null
     */
    private function openDraft(?Billable $subject): ?array
    {
        if (! CheckoutDraft::enabled()) {
            return null;
        }

        $viewer = CheckoutDraft::viewer();
        $this->draftSubject = CheckoutDraft::subject($subject);
        $this->draftViewer = CheckoutDraft::viewerFingerprint($viewer);
        $this->draftKey = CheckoutDraft::key($viewer, $this->draftSubject, CheckoutDraft::context(request()->route()));

        $draft = CheckoutDraft::read($this->draftKey);

        // A signup that created this very billable and was then left at Mollie: its draft was
        // filed as "new". Now that the visitor comes back as that billable — authorized by the
        // caller, under the same signed-in user and session — it continues here.
        if ($draft === null && $subject !== null) {
            $draft = CheckoutDraft::adoptCreated($this->draftViewer, $this->draftSubject, $this->draftKey);
        }

        if ($draft === null) {
            return null;
        }

        // The billable this draft's checkout created is gone since (orphan cleanup, an admin).
        // Nothing of it is restored — and nothing re-created from it: the checkout starts over.
        $created = CheckoutDraft::createdBillable($this->draftKey);

        if ($created !== null && ! $this->billableExists($created['class'], $created['id'])) {
            CheckoutDraft::forget($this->draftKey);

            return null;
        }

        return $draft;
    }

    /**
     * Put the draft's inputs back into the form. Only plain input: the address while it is not
     * locked to a billable's persisted data, the selection when the catalog still sells it, and
     * the `customData` keys the app allowed. Prices, VAT and the coupon are worked out afterwards.
     *
     * @param  array<string, mixed>  $draft
     */
    private function restoreDraftInputs(array $draft): void
    {
        if (! $this->billing_locked) {
            foreach (['company_name', 'billing_street', 'billing_postal_code', 'billing_city'] as $field) {
                if (isset($draft[$field])) {
                    $this->{$field} = (string) $draft[$field];
                }
            }

            if (isset($draft['billing_country']) && array_key_exists($draft['billing_country'], $this->countries())) {
                $this->billing_country = (string) $draft['billing_country'];
            }

            if (array_key_exists('vat_number', $draft)) {
                $this->vat_number = filled($draft['vat_number']) ? (string) $draft['vat_number'] : null;
            }
        }

        if (isset($draft['plan_code']) && array_key_exists($draft['plan_code'], $this->plans())) {
            $this->plan_code = (string) $draft['plan_code'];
        }

        if (isset($draft['interval'])) {
            $this->interval = (string) $draft['interval'];
        }

        if (isset($draft['addon_codes'])) {
            $this->addon_codes = array_values((array) $draft['addon_codes']);
        }

        if (isset($draft['extra_seats'])) {
            $this->extra_seats = (int) $draft['extra_seats'];
        }

        if (is_array($draft['custom_data'] ?? null)) {
            $this->customData = array_merge($this->customData, $draft['custom_data']);
        }
    }

    /**
     * Bring the restored form to a state the server agrees with, then resume at the furthest
     * step that still validates.
     *
     * @param  array<string, mixed>  $draft
     */
    private function resumeDraft(array $draft, bool $queryOverridesDraft): void
    {
        $initialStep = $this->step;

        $this->dropAddonsNotSoldInSelection();

        // The coupon is re-validated by code, exactly as if typed: an expired, exhausted or no
        // longer fitting code is refused with its reason instead of carrying a stale discount.
        if (filled($draft['coupon_code'] ?? null)) {
            $this->coupon_input = (string) $draft['coupon_code'];
            $this->applyCoupon();
        }

        $target = (int) ($draft['step'] ?? $initialStep);

        // Step numbers include the app's own steps. If their number changed since the draft was
        // written, a package step keeps meaning the same package step.
        $savedOffset = (int) ($draft['custom_steps'] ?? $this->customStepCount());
        $offset = $this->customStepCount();

        if ($savedOffset !== $offset) {
            $target = $target > $savedOffset ? $target - $savedOffset + $offset : min($target, max(1, $offset));
        }

        if ($queryOverridesDraft) {
            $target = min($target, $this->customStepCount() + 2);
        }

        $this->step = $this->resumableStep($target);

        $this->resetErrorBag();
        $this->resetValidation();

        if ($this->step !== $initialStep) {
            $this->enterCustomStep($this->step);
        }
    }

    /**
     * The furthest step up to `$target` whose predecessors all validate now — run through the
     * same validators `next()` and `submit()` use, so a resume can never skip a check. The
     * VAT number is re-verified on the way (VIES), never taken from the draft.
     */
    private function resumableStep(int $target): int
    {
        $offset = $this->customStepCount();

        // The steps a customer walks through, in order. A locked billing step starts at the plan
        // step, exactly as mount() does without a draft.
        $sequence = $this->billing_locked ? [] : range(1, $offset + 1);
        $sequence[] = $offset + 2;

        if ($this->hasAddonsOrSeatsStep()) {
            $sequence[] = $offset + 3;
        }

        $sequence[] = $offset + 4;

        $resume = $sequence[0];

        foreach ($sequence as $candidate) {
            $resume = $candidate;

            if ($candidate >= $target || ! $this->stepHoldsSilently($candidate)) {
                break;
            }
        }

        return $resume;
    }

    private function stepHoldsSilently(int $step): bool
    {
        $offset = $this->customStepCount();

        try {
            if ($step <= $offset) {
                $this->validateCustomStep($step);
            } else {
                match ($step - $offset) {
                    1 => $this->validateStep1(),
                    2 => $this->validateStep2(),
                    3 => $this->validateStep3(),
                    default => null,
                };
            }

            $holds = $this->getErrorBag()->isEmpty();
        } catch (\Illuminate\Validation\ValidationException) {
            $holds = false;
        } catch (\Throwable $e) {
            report($e);
            $holds = false;
        }

        $this->resetErrorBag();

        return $holds;
    }

    /**
     * Write the draft — unless the person or the billable it was opened for no longer fits.
     *
     * A different signed-in user (logout and login in another tab, an account switch) stops it
     * and drops what was kept: the draft belongs to who opened the page. So does a billable that
     * was deleted since or that the visitor may no longer act for.
     */
    private function persistDraft(): void
    {
        if ($this->draftKey === null || ! CheckoutDraft::enabled()) {
            return;
        }

        if (! hash_equals((string) $this->draftViewer, CheckoutDraft::viewerFingerprint(CheckoutDraft::viewer()))) {
            $this->discardDraft();

            return;
        }

        $billable = null;

        if ($this->billableId !== null) {
            $billable = $this->resolveBillable();

            if ($billable === null || (! $this->billableCreatedHere && ! MollieBilling::authorizes(request(), $billable))) {
                $this->discardDraft();

                return;
            }
        }

        CheckoutDraft::write(
            $this->draftKey,
            $this->draftPayload(),
            $this->draftSubject,
            (string) $this->draftViewer,
            $this->billableCreatedHere ? $billable : null,
        );
    }

    /**
     * What the draft keeps: input only. Never the VIES verdict, a price, a discount amount, an
     * error or the billable id — those are the server's to work out again.
     *
     * @return array<string, mixed>
     */
    private function draftPayload(): array
    {
        $payload = [
            'step' => $this->step,
            'custom_steps' => $this->customStepCount(),
            'plan_code' => $this->plan_code,
            'interval' => $this->interval,
            'addon_codes' => $this->addon_codes,
            'extra_seats' => $this->extra_seats,
            'coupon_code' => $this->applied_coupon['code'] ?? null,
            'custom_data' => $this->customData,
        ];

        // A locked address is the billable's persisted one, not input — nothing to keep.
        if (! $this->billing_locked) {
            $payload += [
                'company_name' => $this->company_name,
                'billing_street' => $this->billing_street,
                'billing_postal_code' => $this->billing_postal_code,
                'billing_city' => $this->billing_city,
                'billing_country' => $this->billing_country,
                'vat_number' => $this->vat_number,
            ];
        }

        return $payload;
    }

    /** Drop this checkout's draft (and any other of the billable's) and stop writing one. */
    private function discardDraft(?Billable $billable = null): void
    {
        if ($this->draftKey !== null) {
            CheckoutDraft::forget($this->draftKey);
        }

        if ($billable !== null) {
            CheckoutDraft::forgetFor($billable);
        }

        $this->draftKey = null;
    }

    private function billableExists(string $morphClass, string $id): bool
    {
        $class = Relation::getMorphedModel($morphClass) ?? $morphClass;

        if (! is_a($class, Model::class, true) || ! is_a($class, Billable::class, true)) {
            return false;
        }

        return $class::query()->withoutGlobalScope(\GraystackIT\MollieBilling\Scopes\BillingScope::class)->whereKey($id)->exists();
    }

    /**
     * Pre-fill the coupon a promotion link put in the session.
     *
     * Without this the code reached `StartSubscriptionCheckout` through the session
     * while the form submitted a gross that knew nothing about it — the customer paid
     * full price and the coupon was redeemed anyway. (The docs claimed this pre-fill
     * existed; it did not.) The service now refuses a session coupon the amount does
     * not reflect, so leaving this out would mean promotion links silently stopped
     * working; doing it here is what makes them work *and* be priced.
     *
     * Validated like a typed code, so an expired or exhausted promotion is simply not
     * applied rather than surfacing an error on a page the customer never asked about.
     *
     * Tried again whenever the selection changes and on the confirm step, not only at
     * mount: the promotion link arrives without a plan, so a code restricted to a plan,
     * to an addon or to a minimum order fails against the empty selection — and was then
     * never tried for the plan the campaign was for. The customer paid full price, and
     * the service dropped the code from the session because the amount did not reflect it.
     */
    private function adoptAutoApplyCoupon(): void
    {
        $code = session('billing.auto_apply_coupon');

        if (! is_string($code) || $code === '' || $this->applied_coupon !== null || $this->promotionRemoved) {
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
        } catch (\Throwable) {
            return;
        }

        if ($this->couponTrialDays($coupon) > 0 && ! $this->trialApplies($this->plan_code, $this->interval)) {
            return;
        }

        if ($this->couponNeedsExistingSubscription($coupon)) {
            return;
        }

        $this->applied_coupon = [
            'code' => (string) $coupon->code,
            'name' => (string) $coupon->name,
            'discount_net' => $this->calculateCouponDiscount($coupon),
            'trial_days' => $this->couponTrialDays($coupon),
        ];
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
                // Filtered by the *selected* interval as well as by the plan: an addon that
                // does not sell this interval prices at 0, so it rendered as "€0.00 / per
                // year" and looked like a free perk of the annual plan — and then
                // StartSubscriptionCheckout refused the order, which submit() could only
                // report as the generic "payment could not be created". The plan step's own
                // addon list is fed from here too.
                'allowed_addons' => array_values(array_filter(
                    $allAddons,
                    fn (string $addonCode): bool => $catalog->planAllowsAddon($code, $addonCode)
                        && $catalog->addonOffersInterval($addonCode, $this->interval),
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
                    'monthly' => ['price_net' => $catalog->addonPriceNet($code, 'monthly'), 'seat_price_net' => $catalog->addonSeatPriceNet($code, 'monthly')],
                    'yearly' => ['price_net' => $catalog->addonPriceNet($code, 'yearly'), 'seat_price_net' => $catalog->addonSeatPriceNet($code, 'yearly')],
                ],
                'included_seats' => $catalog->addonIncludedSeats($code),
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
            // Only what a customer should read; a hidden feature code still unlocks.
            $catalog->visiblePlanFeatures($planCode),
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

        // The component's own persisted pair first; the request resolver only as a
        // fallback for a not-yet-created billable, and only when authorized — the
        // badge is derived from hasMollieMandate(), which is a fact about that
        // account, not about this visitor.
        $billable = $this->resolveBillable();

        if ($billable === null) {
            $resolved = MollieBilling::resolveBillable(request());
            $billable = $resolved !== null && MollieBilling::authorizes(request(), $resolved)
                ? $resolved
                : null;
        }

        return $billable === null || ! $billable->hasMollieMandate();
    }

    /**
     * Which branch of `StartSubscriptionCheckout::handle()` a submit would take right now — the
     * booking path, not what the catalog offers in principle.
     *
     * Asked in the service's own order and on its own terms, so the confirm step can name what
     * the button really does:
     *  - `access_grant` — the coupon is an access grant: activated locally, nothing collected;
     *  - `free`         — a free plan whose order costs nothing: activated locally;
     *  - `trial`        — a trial starts (trial days, no mandate yet, the order costs something):
     *                     a payment method is authorised, nothing is charged until the trial ends;
     *  - `mandate`      — a code covers the whole first charge: a payment method is authorised,
     *                     nothing is charged today;
     *  - `payment`      — the first period is paid now.
     *
     * The plan's `trial_days` alone are not the answer: a returning customer with a mandate, an
     * access grant and a free plan all skip the trial branch.
     */
    public function bookingPath(): string
    {
        // The service takes the form's code, else the one a promotion link left in the session.
        $code = (string) ($this->applied_coupon['code'] ?? '');

        if ($code === '') {
            $session = session(StartSubscriptionCheckout::AUTO_APPLY_COUPON_SESSION_KEY);
            $code = is_string($session) ? $session : '';
        }

        if ($code !== '') {
            $type = Coupon::query()->whereRaw('UPPER(code) = ?', [strtoupper($code)])->value('type');
            $type = $type instanceof \GraystackIT\MollieBilling\Enums\CouponType ? $type : \GraystackIT\MollieBilling\Enums\CouponType::tryFrom((string) $type);

            if ($type === \GraystackIT\MollieBilling\Enums\CouponType::AccessGrant) {
                return 'access_grant';
            }
        }

        $gross = $this->totals(app(VatCalculationService::class))['gross'];
        $orderNet = $this->subtotalNet();
        $planIsFree = app(SubscriptionCatalogInterface::class)->basePriceNet($this->plan_code, $this->interval) === 0;

        if ($gross === 0 && $planIsFree && ($code === '' || $orderNet === 0)) {
            return 'free';
        }

        if ($this->trialApplies($this->plan_code, $this->interval) && ($gross > 0 || $orderNet > 0)) {
            return 'trial';
        }

        return $gross === 0 ? 'mandate' : 'payment';
    }

    /**
     * The trial length this checkout announces: the plan's own days plus what an
     * applied TrialExtension coupon adds. 0 when no trial will start — decided by the
     * booking path, so an access grant, a free plan or a returning customer is never
     * promised one.
     */
    public function announcedTrialDays(): int
    {
        if ($this->plan_code === '' || $this->bookingPath() !== 'trial') {
            return 0;
        }

        return $this->planTrialDays($this->plan_code, $this->interval)
            + (int) ($this->applied_coupon['trial_days'] ?? 0);
    }

    /**
     * The date money is first collected for a checkout that collects nothing now, or
     * null when the first payment is due at once (or nothing is ever due).
     *
     * A trial defers it to the trial's end. A recurring coupon that covers the whole
     * order defers it past the coupon — Mollie cannot hold an amount of 0, so the
     * subscription keeps its full price and starts charging with the first regular
     * charge the discount no longer covers; its periods start at the first charge, the
     * trial's end where there is one. This is `CreateSubscription`'s own rule, asked of
     * the same `CouponService::recurringChargeResumesAt()` its marker is written from:
     * the confirm step used to print the trial end regardless, which for a three-month
     * coupon on a 14-day trial named a date months before the real one.
     */
    public function firstChargeDate(): ?\Carbon\CarbonInterface
    {
        $now = \GraystackIT\MollieBilling\Support\BillingTime::nowUtc();
        $trialDays = $this->announcedTrialDays();
        $date = $trialDays > 0 ? $now->copy()->addDays($trialDays) : null;

        $subtotal = $this->subtotalNet();
        $code = (string) ($this->applied_coupon['code'] ?? '');

        if ($code !== '' && $subtotal > 0 && (int) ($this->applied_coupon['discount_net'] ?? 0) >= $subtotal) {
            $coupon = Coupon::query()->whereRaw('UPPER(code) = ?', [strtoupper($code)])->first();

            // A first-payment code covers one charge and is shown on it: on a trial, the
            // charge at its end, which the confirm step names as due on that day and as
            // costing nothing — exactly like a partial code, only at 0.00. That Mollie,
            // unable to hold 0, starts one period later is how the free charge is carried
            // out, not a later first charge (CouponService::applyFirstChargeMarkerFrom()).
            if ($coupon !== null && $coupon->type !== \GraystackIT\MollieBilling\Enums\CouponType::Recurring) {
                $coupon = null;
            }

            if ($coupon !== null) {
                $afterCoupon = MollieBilling::coupons()->recurringChargeResumesAt($coupon, $this->interval, $date ?? $now);

                if ($afterCoupon !== null) {
                    $date = $date === null ? $afterCoupon : \GraystackIT\MollieBilling\Support\BillingTime::latest($date, $afterCoupon);
                }
            }
        }

        return $date;
    }

    /** @return array<string, array{label: string, included: int, overage_price: int|null}> */
    public function planUsages(string $planCode, string $interval): array
    {
        $catalog = app(SubscriptionCatalogInterface::class);
        // The plan card lists only what a customer should read; a hidden usage type
        // is still credited and charged like any other.
        $usages = $catalog->visibleIncludedUsages($planCode, $interval);
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
                'seat_price_net' => $catalog->addonSeatPriceNet($addonCode, $interval),
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

    /** @return array<int, array{key:string, label:string, headline:string, description:string, view:string, validate?:\Closure, enter?:\Closure}> */
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
            $net += $this->addonSeatsNet((string) $code);
        }

        return $net;
    }

    /**
     * The per-seat part of one selected addon for the seats being ordered — through
     * SubscriptionAmount, so this screen cannot price it differently from the payment
     * that follows. 0 for an addon without a per-seat price.
     */
    public function addonSeatsNet(string $addonCode): int
    {
        $catalog = app(SubscriptionCatalogInterface::class);

        return \GraystackIT\MollieBilling\Support\SubscriptionAmount::addonSeatsNet(
            $catalog,
            $addonCode,
            (string) $this->plan_code,
            (string) $this->interval,
            $catalog->includedSeats((string) $this->plan_code) + max(0, (int) $this->extra_seats),
        );
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

            // Its own line, as on the invoice (`addon_seats`).
            $addonSeatsNet = $this->addonSeatsNet((string) $code);
            if ($addonSeatsNet > 0) {
                $lines[] = $addonSeatsNet;
            }
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

        // While locked, the number that decides is the persisted one (taxVatNumber()), but
        // the VIES flag describes whatever `vat_number` held when it was last checked — and
        // that field stays live-bound. Confirming somebody else's valid number in the form
        // then lent reverse charge to a persisted number nobody had verified. The flag only
        // counts when it was earned by the number the invoice will carry.
        if ($this->billing_locked
            && CountryResolver::normalizeVatNumber((string) $this->vat_number)
                !== CountryResolver::normalizeVatNumber((string) $this->taxVatNumber())) {
            return false;
        }

        // Cannot delegate wholesale to reverseChargeApplies(): it reads the *persisted*
        // validation, and during checkout the number being entered is not saved until
        // submit() — so a legitimate B2B customer would be shown and charged gross. The
        // country half is asked of the service, which owns the seller-country policy and the
        // domestic-supply rule; a local copy of it read a malformed `vat.seller_country`
        // (`AUT`, `Austria`) as "no seller country" and granted reverse charge on an invoice
        // that owes VAT.
        return app(VatCalculationService::class)->reverseChargeCountriesQualify($this->taxCountry());
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
        //
        // Except where nothing is owed. Per-line rounding does not cancel across lines: a
        // 100 % coupon on Pro 29.50 + an addon 15.50 at 19 % is 5.61 + 2.95 of VAT against
        // −8.55 on the one coupon line, i.e. a total of 0.01. That cent was shown, and it
        // was sent to Mollie as a first payment — which also took the order off the
        // zero-amount path it belongs on. A net of 0 is a gross of 0.
        $gross = $net === 0 ? 0 : BillingMoney::grossWithLineVat($this->lineNets(), $rate);

        return [
            'net' => $net,
            'vat' => $gross - $net,
            'gross' => $gross,
            'rate' => $rate,
            'reverseCharge' => false,
        ];
    }

    /** @return array{vatRate:float,showsNet:bool,showNetAndGross:bool,priceFormatter:\Closure,money:\Closure,currencySymbol:string,totals:array,customStepCount:int,customStepViews:array} */
    public function with(VatCalculationService $vat): array
    {
        $rate = $this->vatRate($vat);
        $showsNet = $this->showsNetPrices();
        $customSteps = $this->customSteps();

        return [
            'vatRate' => $rate,
            'showsNet' => $showsNet,
            'showNetAndGross' => \GraystackIT\MollieBilling\Support\BillingPriceDisplay::showsNetAndGross($this->taxCountry(), $this->taxVatNumber()),
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
        $this->dropAddonsNotSoldInSelection();
        $this->refreshCouponAfterSelectionChange();
    }

    public function updatedPlanCode(): void
    {
        $this->interval = $this->firstOfferedInterval($this->plan_code, $this->interval);
        $this->dropAddonsNotSoldInSelection();
        $this->refreshCouponAfterSelectionChange();
    }

    /**
     * Drop already-ticked addons the new selection does not sell.
     *
     * Otherwise switching to yearly kept a monthly-only addon ticked and priced at 0 — a
     * free extra on the confirm step, refused only when the order reached
     * StartSubscriptionCheckout, where submit() could report nothing more useful than
     * "payment could not be created".
     */
    private function dropAddonsNotSoldInSelection(): void
    {
        $plan = $this->selectedPlan();

        // Seats the new selection does not sell, for the same reason as the addons below:
        // picking a seat count on one plan and then switching to a plan without a
        // `seat_price_net` left the count in place, priced at 0 — free capacity on the
        // confirm step, and `validateStep3()` would refuse the order the customer had
        // already been shown.
        if ($this->extra_seats > 0
            && ($plan['intervals'][$this->interval]['seat_price_net'] ?? null) === null
        ) {
            $this->extra_seats = 0;
        }

        if ($this->addon_codes === []) {
            return;
        }

        $allowed = (array) ($plan['allowed_addons'] ?? []);

        $this->addon_codes = array_values(array_filter(
            $this->addon_codes,
            fn ($code): bool => in_array((string) $code, $allowed, true),
        ));
    }

    /**
     * A coupon validated against the previous selection may no longer fit it.
     * `refreshAppliedCoupon()` clears it and names the reason; surface that as a
     * banner rather than silently dropping the discount the customer can see.
     */
    private function refreshCouponAfterSelectionChange(): void
    {
        if ($this->applied_coupon === null) {
            $this->adoptAutoApplyCoupon();

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
        $this->draftDirty = true;
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
            $this->assertCouponFitsBillable($coupon);
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

        if ($this->couponEndsBeforeFirstCharge($coupon)) {
            $this->couponError = __('billing::checkout.coupon_ends_before_first_charge');

            return;
        }

        if ($this->couponNeedsExistingSubscription($coupon)) {
            $this->couponError = __('billing::checkout.coupon_requires_active_subscription');

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
        $this->draftDirty = true;

        $promotion = session(StartSubscriptionCheckout::AUTO_APPLY_COUPON_SESSION_KEY);

        if (is_string($promotion) && $this->applied_coupon !== null
            && strtoupper($promotion) === strtoupper((string) $this->applied_coupon['code'])
        ) {
            $this->promotionRemoved = true;
        }

        $this->applied_coupon = null;
        $this->couponError = null;
    }

    /**
     * Where to send the customer instead of creating a new first payment, or null.
     *
     * The order handed over is the one `StartSubscriptionCheckout::handle()` is about to
     * be given — the promotion link's code included, which that service falls back to —
     * so the payment on record is reused only while it is for this very order. See
     * `PendingFirstPayment`.
     *
     * @throws \GraystackIT\MollieBilling\Exceptions\PendingPaymentForAnotherOrderException
     */
    private function reusablePendingCheckoutUrl(mixed $billable, int $amountGross): ?string
    {
        $coupon = $this->applied_coupon['code'] ?? null;

        if ($coupon === null || $coupon === '') {
            $coupon = session(StartSubscriptionCheckout::AUTO_APPLY_COUPON_SESSION_KEY);
        }

        return app(\GraystackIT\MollieBilling\Services\Billing\PendingFirstPayment::class)->redirectFor($billable, [
            'plan_code' => (string) $this->plan_code,
            'interval' => (string) $this->interval,
            'addon_codes' => $this->addon_codes,
            'extra_seats' => (int) $this->extra_seats,
            'coupon_code' => $coupon !== null ? (string) $coupon : null,
            'amount_gross' => $amountGross,
        ]);
    }

    /**
     * The half of coupon validation that needs the billable: its per-billable limit.
     *
     * `validateWithoutBillable()` is right for a signup and blind for a returning
     * customer, whose already-spent coupon then priced the payment — the webhook, which
     * does check per billable, rejected it afterwards and activated the subscription at
     * the discounted price anyway. Only the types that move money here are judged, with
     * the same context FirstPaymentCouponPricing::price() uses when the payment settles.
     *
     * @throws InvalidCouponException
     */
    private function assertCouponFitsBillable(\GraystackIT\MollieBilling\Models\Coupon $coupon): void
    {
        $billable = $this->resolveBillable();

        if ($billable === null || ! in_array($coupon->type, [
            \GraystackIT\MollieBilling\Enums\CouponType::SinglePayment,
            \GraystackIT\MollieBilling\Enums\CouponType::Recurring,
        ], true)) {
            return;
        }

        MollieBilling::coupons()->validate((string) $coupon->code, $billable, [
            'planCode' => $this->plan_code,
            'interval' => $this->interval,
            'addonCodes' => $this->addon_codes,
            'orderAmountNet' => $this->subtotalNet(),
            'trial_starting' => $this->trialApplies($this->plan_code, $this->interval),
            'allowed_types' => [
                \GraystackIT\MollieBilling\Enums\CouponType::SinglePayment,
                \GraystackIT\MollieBilling\Enums\CouponType::Recurring,
            ],
        ]);
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
            $this->assertCouponFitsBillable($coupon);
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

        // Same as applyCoupon(): a plan with a trial picked after the code was applied, or
        // a page left open until the trial outlasts the code, must not carry a discount the
        // first charge will not have.
        if ($this->couponEndsBeforeFirstCharge($coupon)) {
            $this->applied_coupon = null;

            return 'ends_before_first_charge';
        }

        if ($this->couponNeedsExistingSubscription($coupon)) {
            $this->applied_coupon = null;

            return 'requires_active_subscription';
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

    /**
     * A dated recurring code that is over before the trial ends discounts nothing: the
     * first charge is at the trial end, and by then the code no longer applies. The summary
     * showed its discount all the same — 35.70 for a first charge that is 42.00.
     * `FirstPaymentCouponPricing::price()` puts no discount on the subscription in that
     * case; this keeps the form from promising one.
     */
    private function couponEndsBeforeFirstCharge(Coupon $coupon): bool
    {
        return $coupon->type === \GraystackIT\MollieBilling\Enums\CouponType::Recurring
            && $coupon->valid_until !== null
            && $this->trialApplies($this->plan_code, $this->interval)
            && \GraystackIT\MollieBilling\Support\BillingTime::toUtc($coupon->valid_until)->lessThanOrEqualTo(
                \GraystackIT\MollieBilling\Support\BillingTime::nowUtc()->addDays($this->planTrialDays($this->plan_code, $this->interval)),
            );
    }

    /**
     * An addon-only access grant: it adds addons to a subscription that already runs.
     *
     * The checkout only ever serves a billable WITHOUT one, so such a code can never be
     * honoured here — `CouponService::validate()` refuses it for exactly that reason. The
     * form validates without a billable and therefore accepted it, showed the addon as
     * free next to a payable total, and the submit then failed inside the service with
     * nothing better to tell the customer than "the payment could not be created".
     */
    private function couponNeedsExistingSubscription(Coupon $coupon): bool
    {
        return $coupon->type === \GraystackIT\MollieBilling\Enums\CouponType::AccessGrant
            && empty($coupon->grant_plan_code);
    }

    private function calculateCouponDiscount(Coupon $coupon): int
    {
        if ($coupon->type === \GraystackIT\MollieBilling\Enums\CouponType::AccessGrant) {
            return $this->calculateAccessGrantDiscount($coupon);
        }

        // Only the two types that actually move money. A TrialExtension or PeriodExtension
        // coupon can still carry a `discount_value` — the admin form keeps the property
        // when the operator switches the type selector after typing one — and
        // `computeRecurringDiscount()` reads it regardless. The checkout then showed a
        // discount no webhook path honours: the customer was quoted a reduced total and
        // charged the full one.
        if (! in_array($coupon->type, [
            \GraystackIT\MollieBilling\Enums\CouponType::SinglePayment,
            \GraystackIT\MollieBilling\Enums\CouponType::Recurring,
        ], true)) {
            return 0;
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
            $discount += $this->addonSeatsNet((string) $addonCode);
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
            'usage_not_applicable' => __('billing::checkout.coupon_usage_mismatch'),
            'min_order_not_met' => __('billing::checkout.coupon_min_order'),
            'requires_billable' => __('billing::checkout.coupon_requires_billable'),
            'requires_trial' => __('billing::checkout.coupon_requires_trial'),
            'recurring_conflict' => __('billing::checkout.coupon_recurring_conflict'),
            'requires_active_subscription' => __('billing::checkout.coupon_requires_active_subscription'),
            'ends_before_first_charge' => __('billing::checkout.coupon_ends_before_first_charge'),
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
        $this->draftDirty = true;
        $offset = $this->customStepCount();
        $pkg = $this->packageStep();

        // Nothing from the previous attempt survives the move.
        //
        // `validate()` only clears the keys it is about to check, and a custom step that
        // reports its own problem with `addError()` uses a key no later `validate()` touches —
        // so the message stayed on screen after the user had fixed the thing it complained
        // about. An email-confirmation step is the plain case: "please confirm your address"
        // sat under a field that already said "confirmed".
        $this->resetErrorBag();
        $this->resetValidation();

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
        $this->enterCustomStep($this->step);

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
        // Addons, seats and a minimum order are settled only now; a promotion code that
        // needed one of them is applied here, before the total is shown.
        if ($this->applied_coupon === null) {
            $this->adoptAutoApplyCoupon();

            return;
        }

        $reason = $this->refreshAppliedCoupon();
        if ($reason !== null) {
            $this->couponError = $this->couponErrorMessage($reason);
        }
    }

    public function back(): void
    {
        $this->draftDirty = true;
        $offset = $this->customStepCount();
        $pkg = $this->packageStep();

        // Same reset as next(): a message about the step being left must not greet the user on
        // the step they are returning to.
        $this->resetErrorBag();
        $this->resetValidation();

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
            $this->enterCustomStep($this->step);

            return;
        }

        $this->step = max(1, $this->step - 1);
        $this->enterCustomStep($this->step);
    }

    private function validateCustomStep(int $step): void
    {
        $customStep = $this->customSteps()[$step - 1] ?? null;

        if ($customStep !== null && isset($customStep['validate'])) {
            ($customStep['validate'])($this);
        }
    }

    /**
     * Tell a custom step it is being entered — forwards or backwards.
     *
     * The step contract had `validate` and nothing else, so a step whose state can go stale
     * while the user is elsewhere had no way to notice. The case that surfaced it: an
     * email-confirmation step whose confirmation no longer holds once the user navigates back,
     * with no way to request a new code because nothing told the step it was live again. A
     * page reload was the only way forward.
     *
     * Called on every arrival, including a re-entry into the step just left, so an `enter`
     * callback has to be safe to run repeatedly. It runs after `$this->step` has moved, so the
     * callback sees the step it is being asked about as the current one.
     */
    private function enterCustomStep(int $step): void
    {
        if ($step > $this->customStepCount()) {
            return;
        }

        $customStep = $this->customSteps()[$step - 1] ?? null;

        if ($customStep !== null && isset($customStep['enter'])) {
            ($customStep['enter'])($this);
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
        // Already interval-filtered by plans(), so the `in:` rule refuses an addon the
        // selected interval does not sell — with a field-level message instead of the
        // generic payment error the service's exception used to surface as.
        $allowed = (array) ($plan['allowed_addons'] ?? []);

        $this->validate([
            'addon_codes' => ['array'],
            'addon_codes.*' => ['string', 'in:' . ($allowed === [] ? 'none' : implode(',', $allowed))],
            'extra_seats' => ['integer', 'min:0', 'max:100'],
        ]);

        // A plan whose interval sells no extra seats must not be given any. The catalog
        // prices them at 0 there, so an unchecked value was capacity handed out for free —
        // and the seat step is not even rendered for such a plan, so the only way to hold a
        // non-zero value is a crafted payload or a selection changed after the fact.
        if ($this->extra_seats > 0
            && ($plan['intervals'][$this->interval]['seat_price_net'] ?? null) === null
        ) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'extra_seats' => __('billing::checkout.seats_not_available'),
            ]);
        }
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
        // The draft records that this checkout created it (see CheckoutDraft::write()).
        $this->draftDirty = true;

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

    /**
     * Until when the earlier payment stays open, as the customer reads it: in the billable's
     * display timezone, formatted in the app locale. Null when Mollie did not say.
     */
    public function pendingOtherOrderUntilLabel(): ?string
    {
        if ($this->pendingOtherOrderUntil === null) {
            return null;
        }

        $billable = $this->resolveBillable();

        return \GraystackIT\MollieBilling\Support\BillingTime::display(
            \Carbon\CarbonImmutable::parse($this->pendingOtherOrderUntil),
            $billable instanceof Billable ? $billable : null,
        )?->locale(app()->getLocale())->translatedFormat(__('billing::checkout.pending_other_order_time_format'));
    }

    // -------------------------------------------------------------------------
    // Final submit
    // -------------------------------------------------------------------------

    public function submit(StartSubscriptionCheckout $checkout, VatCalculationService $vat): mixed
    {
        $this->errorMessage = null;
        $this->pendingOtherOrder = false;
        $this->pendingOtherOrderUntil = null;
        $this->pendingOtherOrderUrl = null;

        // Authorize FIRST — before validation, before anything mutates, and outside
        // the try below so the 403 is not swallowed by its catchall. Livewire actions
        // bypass the checkout route's middleware, and from here on we overwrite the
        // billable's billing address, write a VIES audit row for it and open a Mollie
        // payment on its customer. Anonymous signup is the one exemption: a billable
        // this very component instance created has no owner to authorize against yet.
        //
        // Ahead of validation on purpose. With the field pre-fill now gated on
        // authorization, an unauthorized requester's form is empty, so validation
        // would refuse first and answer with field errors — telling someone who may
        // not be here which fields a stranger's checkout wants. The answer must be
        // 403 regardless of form state.
        if (! $this->billableCreatedHere) {
            $existing = $this->resolveBillable();

            if ($existing !== null) {
                abort_unless(MollieBilling::authorizes(request(), $existing), 403);
            }
        }

        // Validation runs BEFORE setting processing=true. ValidationException is
        // re-thrown so Livewire can render field errors; processing stays false
        // so the submit button doesn't lock up on a recoverable input error.
        // Every custom step, not just the ones the customer walked through.
        //
        // `$step` is a plain public property: a crafted Livewire payload can set it past
        // the custom steps and submit, and `next()` — which is where their validators
        // normally run — is never called. An app's step is exactly where the README tells
        // it to enforce things `createBillableUsing()` then trusts (a confirmed email, an
        // accepted agreement), so skipping them is not a UI nicety.
        foreach (array_keys($this->customSteps()) as $index) {
            $this->validateCustomStep($index + 1);
        }

        $this->validateStep1();
        $this->validateStep2();
        // Unconditional. Gating this on `hasAddonsOrSeatsStep()` meant that for a plan
        // with no allowed addons and no seat price — where the step is not rendered at
        // all — `addon_codes` and `extra_seats` reached the order completely unchecked.
        // A crafted Livewire payload could therefore buy 500 free seats and switch on an
        // addon reserved for another tier, whose features `FeatureAccess` then granted.
        // With an empty allow-list the `in:none` rule refuses every code, so this is also
        // the right check for exactly that plan.
        $this->validateStep3();

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
                $this->discardDraft($billable);

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

            // Serialised per billable. `$processing` is component state, so two
            // `/livewire/update` POSTs each replaying their own snapshot never see the
            // other's flag: both reached this call and both created a
            // `sequenceType: first` payment. `recordPendingFirstPayment()` keeps only
            // the last id, so the return page then polled the payment the customer did
            // *not* pay, and the second one settling later was only caught by the
            // activation gate — as a duplicate, after the money moved.
            //
            // Non-blocking on purpose: the loser must not sit on a worker until the
            // winner has finished talking to Mollie. It re-enters through the
            // still-open-payment branch below on the next attempt.
            $submitLock = Cache::lock('billing:checkout-submit:'.$billable->getKey(), 60);

            if (! $submitLock->get()) {
                $this->errorMessage = __('billing::checkout.error_already_in_progress');

                return null;
            }

            try {
                // A first payment still open at Mollie for this order is the same
                // checkout, not a new one. Sending the customer back to it keeps one
                // payment per intent even when the two submits are seconds apart and the
                // lock has already been released — a second `sequenceType: first`
                // payment on the same mandate-to-be is exactly what the activation gate
                // has to clean up afterwards, and it charges the customer twice if both
                // are paid. One that is paid, or whose activation is still running, is
                // followed on the return page; one for an order the customer has since
                // changed is withdrawn first.
                $amountGross = $this->totals($vat)['gross'];
                $reusable = $this->reusablePendingCheckoutUrl($billable, $amountGross);

                if ($reusable !== null) {
                    return $this->redirect($reusable, navigate: false);
                }

                $result = $checkout->handle($billable, [
                    'plan_code' => $this->plan_code,
                    'interval' => $this->interval,
                    'addon_codes' => $this->addon_codes,
                    'extra_seats' => $this->extra_seats,
                    'coupon_code' => $this->applied_coupon['code'] ?? null,
                    'amount_gross' => $amountGross,
                ]);

                // Inside the lock: the marker is what the reuse branch above reads, so
                // it has to be visible before another submit can win.
                if (! empty($result['payment_id'])) {
                    $billable->recordPendingFirstPayment((string) $result['payment_id']);
                }

                // Stamp the subject into this browser's session so the return page can
                // recognise it. An anonymous signup has no owner yet, so `authorizes()`
                // — which fails closed — answers false for the very customer who just
                // paid, and the return page polled itself into a 90-second timeout with a
                // Logout button. The session is bound to this browser, which is exactly
                // the claim the return page needs and the one thing a `?org=` query
                // parameter cannot forge.
                session([StartSubscriptionCheckout::CHECKOUT_SUBJECT_SESSION_KEY => [
                    'class' => $billable->getMorphClass(),
                    'id' => (string) $billable->getKey(),
                ]]);
            } catch (\GraystackIT\MollieBilling\Exceptions\PendingPaymentForAnotherOrderException $e) {
                // An earlier payment for another order is still open and Mollie would not
                // withdraw it (it never does for an open first payment — measured). Nothing
                // new is created: the customer is told until when it stays open, and gets
                // its link to finish it. See docs/decisions.md.
                $this->pendingOtherOrder = true;
                $this->pendingOtherOrderUntil = $e->expiresAt?->utc()->toIso8601String();
                $this->pendingOtherOrderUrl = $e->checkoutUrl;

                return null;
            } catch (\GraystackIT\MollieBilling\Exceptions\PastDueExpansionNotAllowedException $e) {
                // Not a failure of the payment: this customer owes money and takes out
                // nothing new until it is settled. Say that, not "payment failed".
                $this->errorMessage = \GraystackIT\MollieBilling\Support\ExceptionMessage::for($e);

                return null;
            } catch (\GraystackIT\MollieBilling\Exceptions\ActivationInFlightException) {
                // Paid already; the subscription is on its way. The return page follows it.
                return $this->redirect(BillingRoute::url('return', $billable), navigate: false);
            } catch (\Throwable $e) {
                report($e);
                try {
                    MollieBilling::runAfterCheckout($billable, false);
                } catch (\Throwable $hookError) {
                    report($hookError);
                }
                $this->errorMessage = __('billing::checkout.error_payment_creation');

                return null;
            } finally {
                $submitLock->release();
            }

            if (! empty($result['checkout_url'])) {
                // The draft stays: a customer who cancels on Mollie's page lands back on this
                // checkout and finds it as they left it. It is dropped once the subscription is
                // there (the return page, or the checkout's own redirect for an active one).
                $this->draftDirty = true;

                return $this->redirect($result['checkout_url'], navigate: false);
            }

            // Booked without leaving the site: the checkout is done.
            $this->discardDraft($billable);

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

    {{-- A changed order waits for an earlier payment Mollie would not withdraw --}}
    @if ($pendingOtherOrder)
        @php($until = $this->pendingOtherOrderUntilLabel())
        <flux:callout icon="clock" color="amber" class="mt-4 mb-4" dusk="pending-other-order">
            <flux:callout.heading>{{ __('billing::checkout.pending_other_order_heading') }}</flux:callout.heading>
            <flux:callout.text>
                <span dusk="pending-other-order-text">
                    @if ($until !== null)
                        {!! __('billing::checkout.pending_other_order_until', ['time' => '<strong dusk="pending-other-order-until">'.e($until).'</strong>']) !!}
                    @else
                        {{ __('billing::checkout.pending_other_order_open') }}
                    @endif
                    {{ $pendingOtherOrderUrl
                        ? __('billing::checkout.pending_other_order_finish_or_wait')
                        : __('billing::checkout.pending_other_order_wait') }}
                </span>
            </flux:callout.text>
            @if ($pendingOtherOrderUrl)
                <div class="mt-3">
                    <flux:button size="sm" variant="primary" href="{{ $pendingOtherOrderUrl }}" dusk="pending-other-order-resume">
                        {{ __('billing::checkout.pending_other_order_resume') }}
                    </flux:button>
                </div>
            @endif
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
