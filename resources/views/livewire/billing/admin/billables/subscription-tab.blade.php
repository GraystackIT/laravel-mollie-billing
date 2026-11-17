<?php

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\InvalidSubscriptionStateException;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Services\Billing\AdminExtensionService;
use GraystackIT\MollieBilling\Services\Billing\PlanWaiver;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\ResubscribeSubscription;
use GraystackIT\MollieBilling\Support\BillingTime;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    /** Locked: every action below acts on the billable whose tab is open. */
    #[Locked]
    public mixed $billableId = null;
    public ?string $trialUntil = null;
    public ?string $flash = null;
    public ?string $error = null;

    /** Free-time actions — see AdminExtensionService. */
    public string $extendDays = '';
    public string $freePeriods = '';
    public string $grantDays = '';
    public string $grantOnExpiry = '';

    /** Locked: which grant revokeGrant() revokes is decided by openRevoke(), not by the browser. */
    #[Locked]
    public ?int $revokingId = null;
    public bool $showRevokeModal = false;
    public string $revokeNote = '';

    /**
     * Whether the pending cancel ends the period immediately. Locked because
     * openCancel() decides it, and the two variants differ in what the customer keeps:
     * `false` leaves access until `subscription_ends_at`, `true` revokes it now and
     * settles any open overage.
     */
    #[Locked]
    public bool $cancelImmediately = false;
    public bool $showCancelModal = false;
    public string $cancelNote = '';

    /**
     * Stable, locale-independent reason persisted on the redemption and carried in
     * the GrantRevoked event. Translated only for display — a localized string in
     * the column would make the same action unqueryable across locales.
     */
    private const REVOKE_REASON = 'admin_revoke';

    private const MAX_REVOKE_NOTE_LENGTH = 200;

    public function mount(mixed $billableId = null): void { $this->billableId = $billableId; }

    public function with(): array
    {
        return ['maxRevokeNoteLength' => self::MAX_REVOKE_NOTE_LENGTH];
    }

    public function billable(): mixed
    {
        $class = config('mollie-billing.billable_model');
        return $class ? $class::find($this->billableId) : null;
    }

    public function grants()
    {
        $b = $this->billable();
        if (! $b) return collect();

        return CouponRedemption::query()
            ->with('coupon')
            ->where('billable_type', $b->getMorphClass())
            ->where('billable_id', $b->getKey())
            ->whereNull('revoked_at')
            ->whereHas('coupon', fn ($q) => $q->whereIn('type', [
                CouponType::AccessGrant->value,
                CouponType::PeriodExtension->value,
                CouponType::PlanWaiver->value,
            ]))
            ->latest('applied_at')
            ->get()
            // One row per thing that was GIVEN. A plan waiver also writes a row for each
            // renewal it waived; those are its history, not further grants.
            ->reject(fn (CouponRedemption $r): bool => (((array) ($r->grant_applied_snapshot ?? []))['mode'] ?? null) === 'plan_waiver_renewal')
            ->values();
    }

    /**
     * Everything is free for N more days: the next charge (Mollie) or the end date
     * (Local) moves later.
     */
    public function extendSubscription(AdminExtensionService $service): void
    {
        $this->runExtension(
            $this->extendDays,
            fn ($b, int $n) => $service->extendByDays($b, $n),
            'extended_days',
            'extendDays',
        );
    }

    /** Only the plan is free, for N billing periods. Seats and addons stay paid. */
    public function giveFreePlanPeriods(AdminExtensionService $service): void
    {
        $this->runExtension(
            $this->freePeriods,
            fn ($b, int $n) => $service->freePlanPeriods($b, $n),
            'free_periods_given',
            'freePeriods',
        );
    }

    /** A running gift ends N days later; optionally change what happens at its end. */
    public function extendGrant(AdminExtensionService $service): void
    {
        $onExpiry = in_array($this->grantOnExpiry, [PlanWaiver::ON_EXPIRY_END, PlanWaiver::ON_EXPIRY_CONTINUE_PAID], true)
            ? $this->grantOnExpiry
            : null;

        $this->runExtension(
            $this->grantDays,
            fn ($b, int $n) => $service->extendGrant($b, $n, $onExpiry),
            'grant_extended',
            'grantDays',
        );
    }

    /**
     * Shared shell of the three free-time actions: a whole number greater than zero,
     * the billable this tab is locked to, and every refusal shown as a message rather
     * than thrown out of the Livewire action as a 500.
     */
    private function runExtension(string $input, \Closure $action, string $flashKey, string $field): void
    {
        $this->error = $this->flash = null;

        $n = filter_var(trim($input), FILTER_VALIDATE_INT);

        if ($n === false || $n <= 0 || $n > 3650) {
            $this->error = __('billing::admin.billables.subscription.flash.extension_number_invalid');

            return;
        }

        $b = $this->billable();

        if (! $b) {
            $this->error = __('billing::admin.common.billable_not_found');

            return;
        }

        try {
            $action($b, (int) $n);
        } catch (InvalidCouponException $e) {
            // A reason this screen has no sentence for is shown as its code rather than
            // as a raw translation key.
            $reasonKey = 'billing::admin.billables.subscription.extension_reason.'.$e->reason();

            $this->error = __('billing::admin.billables.subscription.flash.extension_refused', [
                'reason' => \Illuminate\Support\Facades\Lang::has($reasonKey) ? __($reasonKey) : $e->reason(),
            ]);

            return;
        } catch (InvalidSubscriptionStateException|\InvalidArgumentException $e) {
            $this->error = $e->getMessage();

            return;
        } catch (\Throwable $e) {
            report($e);
            $this->error = __('billing::admin.common.error_generic', ['code' => $e->getCode()]);

            return;
        }

        $this->flash = __('billing::admin.billables.subscription.flash.'.$flashKey, ['count' => (int) $n]);
        $this->reset($field);
    }

    public function extendTrial(): void
    {
        $this->error = $this->flash = null;
        if (! $this->trialUntil) {
            $this->error = __('billing::admin.billables.subscription.flash.pick_trial_date');
            return;
        }

        // The date input carries a bare date, and `trial_ends_at` is stored and
        // displayed in UTC. Parsing it in app.timezone would store a different
        // instant than the operator picked and than the tab reads back.
        try {
            $until = Carbon::createFromFormat('!Y-m-d', $this->trialUntil, 'UTC');
        } catch (\Throwable) {
            $until = null;
        }

        if ($until === null) {
            $this->error = __('billing::admin.billables.subscription.flash.trial_date_invalid');
            return;
        }

        // extendBillingTrialUntil() keeps max(previous, until) and skips the status
        // flip for non-future targets, so a past date is a silent no-op.
        if (! $until->isFuture()) {
            $this->error = __('billing::admin.billables.subscription.flash.trial_date_past');
            return;
        }

        $b = $this->billable();
        if (! $b) {
            $this->error = __('billing::admin.common.billable_not_found');
            return;
        }

        // Caught, like every other action in this component: extendBillingTrialUntil()
        // refuses Cancelled and Expired ("resubscribe instead of extending"), and both
        // keep `trial_ends_at` set — so the form rendered, the operator picked a date, and
        // the exception went out of the Livewire action as a 500 with the guidance nobody
        // ever saw.
        try {
            $b->extendBillingTrialUntil($until);
        } catch (InvalidSubscriptionStateException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->flash = __('billing::admin.billables.subscription.flash.trial_extended', [
            'date' => $until->format('Y-m-d H:i'),
        ]);
        $this->reset('trialUntil');
    }

    /**
     * Both cancel variants go through a reason prompt rather than a `wire:confirm`.
     * An operator cancelling someone else's subscription is irreversible — a Mollie
     * subscription cannot be un-cancelled, only recreated — and money-affecting, and
     * the audit trail recorded that it happened but never why.
     */
    public function openCancel(bool $immediately): void
    {
        $this->error = $this->flash = null;
        $this->cancelNote = '';

        if ($this->billable() === null) {
            $this->error = __('billing::admin.common.billable_not_found');

            return;
        }

        $this->cancelImmediately = $immediately;
        $this->showCancelModal = true;
    }

    /** The modal's close button and ESC only flip the flag, so reset from here. */
    public function updatedShowCancelModal(bool $value): void
    {
        if (! $value) {
            $this->reset(['cancelImmediately', 'cancelNote']);
        }
    }

    public function confirmCancel(CancelSubscription $service): void
    {
        $this->error = $this->flash = null;
        $note = trim($this->cancelNote);

        if ($note === '') {
            $this->error = __('billing::admin.billables.subscription.flash.cancel_note_required');

            return;
        }

        if (mb_strlen($note) > self::MAX_REVOKE_NOTE_LENGTH) {
            $this->error = __('billing::admin.billables.subscription.flash.cancel_note_too_long', ['max' => self::MAX_REVOKE_NOTE_LENGTH]);

            return;
        }

        $b = $this->billable();

        if ($b === null) {
            $this->error = __('billing::admin.common.billable_not_found');

            return;
        }

        $immediately = $this->cancelImmediately;
        $service->handle($b, $immediately, $note);

        $this->showCancelModal = false;
        $this->reset(['cancelImmediately', 'cancelNote']);

        $this->flash = $immediately
            ? __('billing::admin.billables.subscription.flash.cancelled_now')
            : __('billing::admin.billables.subscription.flash.cancelled_grace');
    }

    public function resubscribe(ResubscribeSubscription $service): void
    {
        $this->error = $this->flash = null;
        $b = $this->billable();
        if ($b) {
            try { $service->handle($b); $this->flash = __('billing::admin.billables.subscription.flash.resubscribed'); }
            catch (\Throwable $e) { report($e); $this->error = __('billing::admin.common.error_generic', ['code' => $e->getCode()]); }
        }
    }

    /**
     * Resolve the redemption the revoke modal addresses, scoped to the locked
     * billable. Sets `$error` and returns null when it is not revocable from here.
     */
    private function revokeTarget(): ?CouponRedemption
    {
        if ($this->revokingId === null) {
            $this->error = __('billing::admin.billables.subscription.flash.grant_not_found');
            return null;
        }

        $redemption = CouponRedemption::find($this->revokingId);
        if (! $redemption) { $this->error = __('billing::admin.billables.subscription.flash.grant_not_found'); return null; }

        $b = $this->billable();
        if (! $b || $redemption->billable_type !== $b->getMorphClass() || (string) $redemption->billable_id !== (string) $b->getKey()) {
            $this->error = __('billing::admin.billables.subscription.flash.grant_foreign');
            return null;
        }

        // A period extension has already moved a charge that may since have happened;
        // there is nothing coherent to take back. The view offers no button for it —
        // this is for the request that does not come from the view.
        $mode = ((array) ($redemption->grant_applied_snapshot ?? []))['mode'] ?? null;
        if (! in_array($mode, ['full', 'addon_only', 'plan_waiver'], true)) {
            $this->error = __('billing::admin.billables.subscription.flash.grant_not_revocable');
            return null;
        }

        return $redemption;
    }

    public function openRevoke(int $redemptionId): void
    {
        $this->error = $this->flash = null;
        $this->revokingId = $redemptionId;
        $this->revokeNote = '';

        // Validate before the modal opens so a foreign or stale id surfaces as an
        // error on the page instead of a form the operator can fill in for nothing.
        if ($this->revokeTarget() === null) {
            $this->revokingId = null;
            return;
        }

        $this->showRevokeModal = true;
    }

    /**
     * The modal's close button and ESC only flip `showRevokeModal`, so the reset
     * has to hang off that property — a `closeRevoke()` action would never run.
     */
    public function updatedShowRevokeModal(bool $value): void
    {
        if (! $value) {
            $this->reset(['revokingId', 'revokeNote']);
        }
    }

    public function revokeGrant(CouponService $service): void
    {
        $this->error = $this->flash = null;

        $redemption = $this->revokeTarget();
        if (! $redemption) { return; }

        $note = trim($this->revokeNote);
        if ($note === '') { $this->error = __('billing::admin.billables.subscription.flash.revoke_note_required'); return; }
        if (mb_strlen($note) > self::MAX_REVOKE_NOTE_LENGTH) {
            $this->error = __('billing::admin.billables.subscription.flash.revoke_note_too_long', ['max' => self::MAX_REVOKE_NOTE_LENGTH]);
            return;
        }

        try {
            // Stable marker first, operator note appended: `revoked_reason` stays
            // greppable as `admin_revoke%` across locales while carrying the why.
            $service->revokeGrant($redemption, self::REVOKE_REASON.': '.$note);
            $this->flash = __('billing::admin.billables.subscription.flash.grant_revoked', [
                'reason' => __('billing::admin.billables.subscription.revoke_reason.'.self::REVOKE_REASON),
            ]);
            $this->showRevokeModal = false;
            $this->reset(['revokingId', 'revokeNote']);
        } catch (\Throwable $e) {
            report($e);
            $this->error = __('billing::admin.common.error_generic', ['code' => $e->getCode()]);
        }
    }
};

?>

<div class="space-y-4">
    <x-mollie-billing::admin.flash :success="$flash" :error="$error" />

    @php $b = $this->billable(); @endphp
    @if ($b)
        <div class="grid gap-6 lg:grid-cols-2">
            <x-mollie-billing::admin.section :title="__('billing::admin.billables.subscription.current_title')" :description="__('billing::admin.billables.subscription.current_description')">
                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-mollie-billing::admin.detail :label="__('billing::admin.common.plan')" mono>
                        {{ $b->subscription_plan_code ?? '—' }}
                    </x-mollie-billing::admin.detail>
                    <x-mollie-billing::admin.detail :label="__('billing::admin.common.interval')">
                        {{ $b->subscription_interval?->label() ?? '—' }}
                    </x-mollie-billing::admin.detail>
                    <x-mollie-billing::admin.detail :label="__('billing::admin.common.status')">
                        <x-mollie-billing::admin.enum-badge :value="$b->subscription_status" />
                    </x-mollie-billing::admin.detail>
                    <x-mollie-billing::admin.detail :label="__('billing::admin.billables.subscription.source')">
                        <x-mollie-billing::admin.enum-badge :value="$b->subscription_source ?? null" />
                    </x-mollie-billing::admin.detail>
                    <x-mollie-billing::admin.detail :label="__('billing::admin.billables.subscription.seats')" mono>
                        {{ $b->getBillingSeatCount() }}
                    </x-mollie-billing::admin.detail>
                    <x-mollie-billing::admin.detail :label="__('billing::admin.billables.subscription.addons')">
                        @php $addons = $b->getActiveBillingAddonCodes(); @endphp
                        @if (empty($addons))
                            <span class="text-zinc-400">—</span>
                        @else
                            <div class="flex flex-wrap gap-1">
                                @foreach ($addons as $addon)
                                    <flux:badge size="sm" color="blue">{{ $addon }}</flux:badge>
                                @endforeach
                            </div>
                        @endif
                    </x-mollie-billing::admin.detail>
                    <x-mollie-billing::admin.detail :label="__('billing::admin.billables.subscription.trial_ends')" mono>
                        {{ BillingTime::displayUtc($b->trial_ends_at)?->format('Y-m-d H:i') ?? '—' }}
                        @if ($b->trial_ends_at)
                            <flux:text size="xs" class="text-zinc-500">{{ $b->trial_ends_at->diffForHumans() }}</flux:text>
                        @endif
                    </x-mollie-billing::admin.detail>
                    <x-mollie-billing::admin.detail :label="__('billing::admin.billables.subscription.subscription_ends')" mono>
                        {{ BillingTime::displayUtc($b->subscription_ends_at)?->format('Y-m-d H:i') ?? '—' }}
                        @if ($b->subscription_ends_at)
                            <flux:text size="xs" class="text-zinc-500">{{ $b->subscription_ends_at->diffForHumans() }}</flux:text>
                        @endif
                    </x-mollie-billing::admin.detail>
                </dl>

                <flux:separator variant="subtle" />

                {{-- What the customer will be charged next, and what it is made of. --}}
                <x-mollie-billing::next-charge :summary="app(\GraystackIT\MollieBilling\Services\Billing\NextChargeSummary::class)->for($b)" />
            </x-mollie-billing::admin.section>

            @php
                $status = $b->getBillingSubscriptionStatus();
                $endsAt = $b->getBillingSubscriptionEndsAt();
                // Only the states extendBillingTrialUntil() accepts. `trial_ends_at !== null`
                // is true for anything that ever trialled, including Cancelled and Expired,
                // where the trait refuses and tells the operator to resubscribe instead.
                $canExtendTrial = in_array($b->getBillingSubscriptionStatus(), [
                    SubscriptionStatus::New,
                    SubscriptionStatus::Trial,
                    SubscriptionStatus::Active,
                    SubscriptionStatus::PastDue,
                ], true) && ($b->isOnBillingTrial() || $b->trial_ends_at !== null);
                $canResubscribe = $status === \GraystackIT\MollieBilling\Enums\SubscriptionStatus::Cancelled
                    && $endsAt !== null && $endsAt->isFuture();
                $canCancel = ! in_array($status, [
                    \GraystackIT\MollieBilling\Enums\SubscriptionStatus::Cancelled,
                    \GraystackIT\MollieBilling\Enums\SubscriptionStatus::Expired,
                ], true) && $b->getBillingSubscriptionPlanCode() !== null;
                $canForceCancel = $canCancel;
                $hasAnyAction = $canExtendTrial || $canResubscribe || $canCancel;
            @endphp

            <x-mollie-billing::admin.section :title="__('billing::admin.billables.subscription.actions_title')" :description="__('billing::admin.billables.subscription.actions_description')">
                @if (! $hasAnyAction)
                    <x-mollie-billing::admin.empty
                        icon="check-circle"
                        :title="__('billing::admin.billables.subscription.no_actions_title')"
                        :description="__('billing::admin.billables.subscription.no_actions_description')"
                    />
                @else
                    @if ($canExtendTrial)
                        <form wire:submit="extendTrial" class="flex items-end gap-2">
                            <flux:input
                                type="date"
                                wire:model="trialUntil"
                                :min="BillingTime::nowUtc()->addDay()->format('Y-m-d')"
                                :label="__('billing::admin.billables.subscription.extend_trial_label')"
                                :description="__('billing::admin.billables.subscription.extend_trial_description')"
                                class="flex-1"
                            />
                            <flux:button type="submit" variant="primary" icon="clock" class="shrink-0">{{ __('billing::admin.billables.subscription.extend') }}</flux:button>
                        </form>
                    @endif

                    @if ($canExtendTrial && ($canResubscribe || $canForceCancel))
                        <flux:separator />
                    @endif

                    @if ($canResubscribe || $canCancel)
                        <div class="flex flex-wrap gap-2">
                            @if ($canResubscribe)
                                <flux:button size="sm" icon="arrow-path" wire:click="resubscribe" wire:confirm="{{ __('billing::admin.billables.subscription.resubscribe_confirm') }}">{{ __('billing::admin.billables.subscription.resubscribe') }}</flux:button>
                            @endif
                            @if ($canCancel)
                                <flux:button size="sm" icon="pause" wire:click="openCancel(false)">{{ __('billing::admin.billables.subscription.cancel_at_period_end') }}</flux:button>
                                <flux:button size="sm" variant="danger" icon="x-mark" wire:click="openCancel(true)">{{ __('billing::admin.billables.subscription.force_cancel') }}</flux:button>
                            @endif
                        </div>
                    @endif
                @endif
            </x-mollie-billing::admin.section>
        </div>

        @php
            $waiver = app(PlanWaiver::class)->marker($b);
            $isLocalGift = $b->isLocalBillingSubscription()
                && $status === SubscriptionStatus::Active
                && $endsAt !== null && $endsAt->isFuture();
            $isMollie = $b->getBillingSubscriptionSource() === 'mollie';
            $running = in_array($status, [SubscriptionStatus::Active, SubscriptionStatus::Trial], true);
            // What each action needs, so a button is only offered where it can work.
            $canExtendDays = $running && ($isMollie || $isLocalGift);
            $canFreePeriods = $running && ($isMollie || $isLocalGift);
            // Only a gift has an end date to move; free periods on a paying
            // subscription are extended by giving more periods.
            $canExtendGrant = $isLocalGift || ($isMollie && $waiver !== null && app(PlanWaiver::class)->isGift($waiver));
            $currentOnExpiry = $waiver['on_expiry'] ?? ($b->getBillingSubscriptionMeta()['grant_on_expiry'] ?? null);
        @endphp

        @php $balanceCharges = \GraystackIT\MollieBilling\Services\Billing\UndercollectionCharge::cases($b); @endphp
        @if ($balanceCharges !== [])
            <x-mollie-billing::admin.section :title="__('billing::admin.balance_charges.heading')" :description="__('billing::admin.balance_charges.description')" dusk="balance-charges">
                <x-slot:actions>
                    <x-mollie-billing::admin.info :text="__('billing::admin.balance_charges.info')" />
                </x-slot:actions>

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('billing::admin.balance_charges.column.date') }}</flux:table.column>
                        <flux:table.column>{{ __('billing::admin.balance_charges.column.reason') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('billing::admin.balance_charges.column.amount') }}</flux:table.column>
                        <flux:table.column>{{ __('billing::admin.balance_charges.column.status') }}</flux:table.column>
                        <flux:table.column>{{ __('billing::admin.balance_charges.column.payment') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($balanceCharges as $charge)
                            @php
                                $reasonKey = 'billing::admin.balance_charges.reason.'.$charge['reason'];
                                $obstacleKey = 'billing::admin.balance_charges.obstacle.'.($charge['obstacle'] ?? '');
                            @endphp
                            <flux:table.row :key="$charge['key']" dusk="balance-charge-row-{{ $charge['status'] }}">
                                <flux:table.cell class="tabular-nums">{{ $charge['at'] ? BillingTime::displayUtc(\Carbon\Carbon::parse($charge['at']))?->format('Y-m-d H:i') : '—' }}</flux:table.cell>
                                <flux:table.cell>{{ \Illuminate\Support\Facades\Lang::has($reasonKey) ? __($reasonKey) : $charge['reason'] }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ \GraystackIT\MollieBilling\Support\BillingMoney::format($charge['amount_gross'], $charge['currency']) }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" :color="['pending' => 'blue', 'paid' => 'green', 'failed' => 'red', 'closed' => 'zinc', 'not_possible' => 'zinc'][$charge['status']] ?? 'zinc'">{{ __('billing::admin.balance_charges.status.'.$charge['status']) }}</flux:badge>
                                    @if ($charge['obstacle'])
                                        <flux:text size="xs" class="mt-1 text-zinc-500">{{ \Illuminate\Support\Facades\Lang::has($obstacleKey) ? __($obstacleKey) : __('billing::admin.balance_charges.obstacle.default') }}</flux:text>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="font-mono text-xs">{{ $charge['payment_id'] ?? '—' }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </x-mollie-billing::admin.section>
        @endif

        @if ($canExtendDays || $canFreePeriods || $canExtendGrant)
            <x-mollie-billing::admin.section
                :title="__('billing::admin.billables.subscription.free_time.title')"
                :description="__('billing::admin.billables.subscription.free_time.description')"
            >
                <div class="grid gap-6 lg:grid-cols-3">
                    @if ($canExtendDays)
                        <form wire:submit="extendSubscription" class="space-y-2">
                            <div class="flex items-center gap-1">
                                <flux:heading size="sm">{{ __('billing::admin.billables.subscription.free_time.extend_days_title') }}</flux:heading>
                                <x-mollie-billing::admin.info :text="__('billing::admin.billables.subscription.free_time.extend_days_info')" />
                            </div>
                            <div class="flex items-end gap-2">
                                <flux:input type="number" min="1" max="3650" wire:model="extendDays" placeholder="14" :suffix="__('billing::admin.common.days')" class="flex-1" />
                                <flux:button type="submit" variant="primary" icon="calendar-days" class="shrink-0" wire:loading.attr="disabled">{{ __('billing::admin.billables.subscription.extend') }}</flux:button>
                            </div>
                        </form>
                    @endif

                    @if ($canFreePeriods)
                        <form wire:submit="giveFreePlanPeriods" class="space-y-2">
                            <div class="flex items-center gap-1">
                                <flux:heading size="sm">{{ __('billing::admin.billables.subscription.free_time.free_periods_title') }}</flux:heading>
                                <x-mollie-billing::admin.info :text="__('billing::admin.billables.subscription.free_time.free_periods_info')" />
                            </div>
                            <div class="flex items-end gap-2">
                                <flux:input type="number" min="1" max="120" wire:model="freePeriods" placeholder="3" :suffix="__('billing::admin.billables.subscription.free_time.periods')" class="flex-1" />
                                <flux:button type="submit" variant="primary" icon="gift" class="shrink-0" wire:loading.attr="disabled">{{ __('billing::admin.billables.subscription.free_time.give') }}</flux:button>
                            </div>
                        </form>
                    @endif

                    @if ($canExtendGrant)
                        <form wire:submit="extendGrant" class="space-y-2">
                            <div class="flex items-center gap-1">
                                <flux:heading size="sm">{{ __('billing::admin.billables.subscription.free_time.extend_grant_title') }}</flux:heading>
                                <x-mollie-billing::admin.info :text="__('billing::admin.billables.subscription.free_time.extend_grant_info')" />
                            </div>
                            <div class="flex items-end gap-2">
                                <flux:input type="number" min="1" max="3650" wire:model="grantDays" placeholder="30" :suffix="__('billing::admin.common.days')" class="flex-1" />
                                <flux:button type="submit" variant="primary" icon="arrow-right-circle" class="shrink-0" wire:loading.attr="disabled">{{ __('billing::admin.billables.subscription.extend') }}</flux:button>
                            </div>
                            <div class="flex items-center gap-1">
                                <flux:select wire:model="grantOnExpiry" class="flex-1">
                                    <flux:select.option value="">{{ __('billing::admin.billables.subscription.free_time.on_expiry_keep', ['current' => __('billing::admin.grants.on_expiry.'.PlanWaiver::normalizeOnExpiry($currentOnExpiry))]) }}</flux:select.option>
                                    <flux:select.option value="end">{{ __('billing::admin.grants.on_expiry.end') }}</flux:select.option>
                                    <flux:select.option value="continue_paid">{{ __('billing::admin.grants.on_expiry.continue_paid') }}</flux:select.option>
                                </flux:select>
                                <x-mollie-billing::admin.info :text="__('billing::admin.grants.on_expiry_info')" />
                            </div>
                        </form>
                    @endif
                </div>

                @if ($waiver !== null)
                    <flux:callout icon="gift" color="teal" inline>
                        {{ __('billing::admin.billables.subscription.free_time.waiver_running', [
                            'until' => BillingTime::displayUtc(\Carbon\Carbon::parse((string) $waiver['valid_until']))?->format('Y-m-d'),
                            'on_expiry' => __('billing::admin.grants.on_expiry.'.PlanWaiver::normalizeOnExpiry($waiver['on_expiry'] ?? null)),
                        ]) }}
                    </flux:callout>
                @endif
            </x-mollie-billing::admin.section>
        @endif

        @php $grants = $this->grants(); @endphp
        <x-mollie-billing::admin.section
            :title="__('billing::admin.billables.subscription.grants_title')"
            :description="__('billing::admin.billables.subscription.grants_description').' '.($grants->isEmpty() ? __('billing::admin.billables.subscription.grants_none') : __('billing::admin.billables.subscription.grants_count', ['count' => $grants->count()]))"
        >
            <x-slot:actions>
                <flux:button size="xs" icon="gift" :href="route(BillingRoute::admin('grants.create'), $b)">{{ __('billing::admin.billables.subscription.issue_grant') }}</flux:button>
            </x-slot:actions>

            @if ($grants->isEmpty())
                <x-mollie-billing::admin.empty
                    icon="gift"
                    :title="__('billing::admin.billables.subscription.grants_empty_title')"
                    :description="__('billing::admin.billables.subscription.grants_empty_description')"
                />
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('billing::admin.billables.subscription.grant_column.code') }}</flux:table.column>
                        <flux:table.column>{{ __('billing::admin.billables.subscription.grant_column.mode') }}</flux:table.column>
                        <flux:table.column>{{ __('billing::admin.billables.subscription.grant_column.plan_addons') }}</flux:table.column>
                        <flux:table.column>{{ __('billing::admin.billables.subscription.grant_column.applied') }}</flux:table.column>
                        <flux:table.column>{{ __('billing::admin.billables.subscription.grant_column.days') }}</flux:table.column>
                        <flux:table.column class="w-24"></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($grants as $g)
                            @php
                                $snap = (array) ($g->grant_applied_snapshot ?? []);
                                $mode = $snap['mode'] ?? '—';
                                $plan = $snap['plan_code'] ?? null;
                                $interval = $snap['interval'] ?? null;
                                $addons = (array) ($snap['addon_codes'] ?? []);
                            @endphp
                            <flux:table.row :key="$g->id">
                                <flux:table.cell variant="strong" class="font-mono">{{ $g->coupon?->code ?? '—' }}</flux:table.cell>
                                <flux:table.cell>
                                    @php
                                        $modeLabel = match ($mode) {
                                            'full' => __('billing::admin.billables.subscription.mode_full'),
                                            'addon_only' => __('billing::admin.billables.subscription.mode_addon_only'),
                                            'period_extension' => __('billing::admin.billables.subscription.mode_period_extension'),
                                            'plan_waiver' => __('billing::admin.billables.subscription.mode_plan_waiver', ['count' => (int) ($snap['periods'] ?? 0)]),
                                            default => $mode,
                                        };
                                        $modeColor = match ($mode) {
                                            'full' => 'cyan',
                                            'period_extension' => 'sky',
                                            'plan_waiver' => 'teal',
                                            default => 'blue',
                                        };
                                    @endphp
                                    <flux:badge size="sm" :color="$modeColor">{{ $modeLabel }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <div class="space-y-1">
                                        @if ($plan)
                                            <div class="font-mono text-sm">{{ $plan }}@if ($interval) <span class="text-zinc-400">·</span> <span class="text-zinc-500">{{ __('billing::enums.subscription_interval.'.$interval) }}</span>@endif</div>
                                        @endif
                                        @if (! empty($addons))
                                            <div class="flex flex-wrap gap-1">
                                                @foreach ($addons as $addon)
                                                    <flux:badge size="sm" color="blue">{{ $addon }}</flux:badge>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell class="tabular-nums">
                                    {{ BillingTime::displayUtc($g->applied_at)?->format('Y-m-d H:i') }}
                                    <flux:text size="xs" class="text-zinc-500">{{ $g->applied_at?->diffForHumans() }}</flux:text>
                                </flux:table.cell>
                                <flux:table.cell class="tabular-nums">{{ $g->grant_days_added ?? '—' }}</flux:table.cell>
                                <flux:table.cell>
                                    @if (in_array($mode, ['full', 'addon_only', 'plan_waiver'], true))
                                        <flux:button
                                            size="xs"
                                            variant="danger"
                                            icon="x-mark"
                                            wire:click="openRevoke({{ $g->id }})"
                                        >{{ __('billing::admin.billables.subscription.revoke') }}</flux:button>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </x-mollie-billing::admin.section>
    @endif

    <flux:modal wire:model.self="showCancelModal" name="cancel-subscription" class="md:w-[480px]">
        <form wire:submit="confirmCancel" class="space-y-5">
            {{-- Said here as well as at the top of the page: the prompt is opened from
                 wherever the operator has scrolled to, and a refusal that only appears
                 above the fold looks like a button that does nothing. --}}
            @if ($error)
                <flux:callout variant="danger" icon="exclamation-triangle" inline data-modal-error>
                    <flux:callout.text>{{ $error }}</flux:callout.text>
                </flux:callout>
            @endif

            <div>
                <flux:heading size="lg">{{ __('billing::admin.billables.subscription.cancel_modal.title') }}</flux:heading>
                <flux:text class="text-zinc-500 dark:text-zinc-400">
                    {{ $cancelImmediately
                        ? __('billing::admin.billables.subscription.force_cancel_confirm')
                        : __('billing::admin.billables.subscription.cancel_at_period_end_confirm') }}
                </flux:text>
            </div>

            <flux:field>
                <flux:label :badge="__('billing::admin.common.required')">{{ __('billing::admin.billables.subscription.cancel_modal.note_label') }}</flux:label>
                <flux:textarea
                    wire:model="cancelNote"
                    :placeholder="__('billing::admin.billables.subscription.cancel_modal.note_placeholder')"
                    rows="3"
                    :maxlength="$maxRevokeNoteLength"
                />
                <flux:description>
                    {{ __('billing::admin.billables.subscription.cancel_modal.note_description') }}
                </flux:description>
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" size="sm" variant="ghost">{{ __('billing::admin.common.cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" size="sm" variant="danger" icon="x-mark" wire:loading.attr="disabled">
                    {{ $cancelImmediately
                        ? __('billing::admin.billables.subscription.force_cancel')
                        : __('billing::admin.billables.subscription.cancel_at_period_end') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model.self="showRevokeModal" name="revoke-grant" class="md:w-[480px]">
        <form wire:submit="revokeGrant" class="space-y-5">
            {{-- Said here as well as at the top of the page: the prompt is opened from
                 wherever the operator has scrolled to, and a refusal that only appears
                 above the fold looks like a button that does nothing. --}}
            @if ($error)
                <flux:callout variant="danger" icon="exclamation-triangle" inline data-modal-error>
                    <flux:callout.text>{{ $error }}</flux:callout.text>
                </flux:callout>
            @endif

            <div>
                <flux:heading size="lg">{{ __('billing::admin.billables.subscription.revoke_modal.title') }}</flux:heading>
                <flux:text class="text-zinc-500 dark:text-zinc-400">
                    {{ __('billing::admin.billables.subscription.revoke_confirm') }}
                </flux:text>
            </div>

            <flux:field>
                <flux:label :badge="__('billing::admin.common.required')">{{ __('billing::admin.billables.subscription.revoke_modal.note_label') }}</flux:label>
                <flux:textarea
                    wire:model="revokeNote"
                    :placeholder="__('billing::admin.billables.subscription.revoke_modal.note_placeholder')"
                    rows="3"
                    :maxlength="$maxRevokeNoteLength"
                />
                <flux:description>
                    {{ __('billing::admin.billables.subscription.revoke_modal.note_description') }}
                </flux:description>
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" size="sm" variant="ghost">{{ __('billing::admin.common.cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" size="sm" variant="danger" icon="x-mark" wire:loading.attr="disabled">
                    {{ __('billing::admin.billables.subscription.revoke') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
