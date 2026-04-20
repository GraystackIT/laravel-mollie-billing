<?php

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\ResubscribeSubscription;
use Livewire\Component;

new class extends Component {
    public mixed $billableId = null;
    public ?string $trialUntil = null;
    public ?string $flash = null;
    public ?string $error = null;

    public function mount(mixed $billableId = null): void { $this->billableId = $billableId; }

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
            ->whereHas('coupon', fn ($q) => $q->where('type', CouponType::AccessGrant->value))
            ->latest('applied_at')
            ->get();
    }

    public function extendTrial(): void
    {
        $this->error = $this->flash = null;
        if (! $this->trialUntil) {
            $this->error = 'Please pick a trial end date.';
            return;
        }
        $this->billable()?->extendBillingTrialUntil(Carbon::parse($this->trialUntil));
        $this->flash = 'Trial extended to '.Carbon::parse($this->trialUntil)->format('Y-m-d').'.';
    }

    public function cancelAtPeriodEnd(CancelSubscription $service): void
    {
        $this->error = $this->flash = null;
        $b = $this->billable();
        if ($b) { $service->handle($b, false); $this->flash = 'Subscription cancelled. Grace period applies.'; }
    }

    public function forceCancel(CancelSubscription $service): void
    {
        $this->error = $this->flash = null;
        $b = $this->billable();
        if ($b) { $service->handle($b, true); $this->flash = 'Subscription cancelled immediately.'; }
    }

    public function resubscribe(ResubscribeSubscription $service): void
    {
        $this->error = $this->flash = null;
        $b = $this->billable();
        if ($b) {
            try { $service->handle($b); $this->flash = 'Resubscribed.'; }
            catch (\Throwable $e) { $this->error = $e->getMessage(); }
        }
    }

    public function revokeGrant(int $redemptionId, CouponService $service): void
    {
        $this->error = $this->flash = null;
        $redemption = CouponRedemption::find($redemptionId);
        if (! $redemption) { $this->error = 'Grant not found.'; return; }

        $b = $this->billable();
        if (! $b || $redemption->billable_type !== $b->getMorphClass() || (string) $redemption->billable_id !== (string) $b->getKey()) {
            $this->error = 'Grant does not belong to this billable.';
            return;
        }

        try {
            $service->revokeGrant($redemption, 'Revoked by admin');
            $this->flash = 'Grant revoked.';
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }
};

?>

<div class="space-y-4">
    <x-mollie-billing::admin.flash :success="$flash" :error="$error" />

    @php $b = $this->billable(); @endphp
    @if ($b)
        <div class="grid gap-6 lg:grid-cols-2">
            <x-mollie-billing::admin.section title="Current subscription" description="Snapshot of the billable's subscription state.">
                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-mollie-billing::admin.detail label="Plan" mono>
                        {{ $b->subscription_plan_code ?? '—' }}
                    </x-mollie-billing::admin.detail>
                    <x-mollie-billing::admin.detail label="Interval">
                        {{ $b->subscription_interval->label() }}
                    </x-mollie-billing::admin.detail>
                    <x-mollie-billing::admin.detail label="Status">
                        <x-mollie-billing::admin.enum-badge :value="$b->subscription_status" />
                    </x-mollie-billing::admin.detail>
                    <x-mollie-billing::admin.detail label="Source">
                        <x-mollie-billing::admin.enum-badge :value="$b->subscription_source ?? null" />
                    </x-mollie-billing::admin.detail>
                    <x-mollie-billing::admin.detail label="Seats" mono>
                        {{ $b->getBillingSeatCount() }}
                    </x-mollie-billing::admin.detail>
                    <x-mollie-billing::admin.detail label="Addons">
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
                    <x-mollie-billing::admin.detail label="Trial ends" mono>
                        {{ $b->trial_ends_at?->format('Y-m-d') ?? '—' }}
                        @if ($b->trial_ends_at)
                            <flux:text size="xs" class="text-zinc-500">{{ $b->trial_ends_at->diffForHumans() }}</flux:text>
                        @endif
                    </x-mollie-billing::admin.detail>
                    <x-mollie-billing::admin.detail label="Subscription ends" mono>
                        {{ $b->subscription_ends_at?->format('Y-m-d') ?? '—' }}
                        @if ($b->subscription_ends_at)
                            <flux:text size="xs" class="text-zinc-500">{{ $b->subscription_ends_at->diffForHumans() }}</flux:text>
                        @endif
                    </x-mollie-billing::admin.detail>
                </dl>
            </x-mollie-billing::admin.section>

            @php
                $status = $b->getBillingSubscriptionStatus();
                $endsAt = $b->getBillingSubscriptionEndsAt();
                $canExtendTrial = $b->isOnBillingTrial() || ($b->trial_ends_at !== null);
                $canResubscribe = $status === \GraystackIT\MollieBilling\Enums\SubscriptionStatus::Cancelled
                    && $endsAt !== null && $endsAt->isFuture();
                $canCancel = ! in_array($status, [
                    \GraystackIT\MollieBilling\Enums\SubscriptionStatus::Cancelled,
                    \GraystackIT\MollieBilling\Enums\SubscriptionStatus::Expired,
                ], true) && $b->getBillingSubscriptionPlanCode() !== null;
                $canForceCancel = $canCancel;
                $hasAnyAction = $canExtendTrial || $canResubscribe || $canCancel;
            @endphp

            <x-mollie-billing::admin.section title="Actions" description="Administrative overrides.">
                @if (! $hasAnyAction)
                    <x-mollie-billing::admin.empty
                        icon="check-circle"
                        title="No actions available"
                        description="There are no administrative overrides applicable to this billable's current state."
                    />
                @else
                    @if ($canExtendTrial)
                        <form wire:submit="extendTrial" class="flex items-end gap-2">
                            <flux:input
                                type="date"
                                wire:model="trialUntil"
                                label="Extend trial until"
                                description="Sets the trial end date."
                                class="flex-1"
                            />
                            <flux:button type="submit" size="sm" variant="primary" icon="clock" class="shrink-0">Extend</flux:button>
                        </form>
                    @endif

                    @if ($canExtendTrial && ($canResubscribe || $canForceCancel))
                        <flux:separator />
                    @endif

                    @if ($canResubscribe || $canCancel)
                        <div class="flex flex-wrap gap-2">
                            @if ($canResubscribe)
                                <flux:button size="sm" icon="arrow-path" wire:click="resubscribe" wire:confirm="Resubscribe this billable?">Resubscribe</flux:button>
                            @endif
                            @if ($canCancel)
                                <flux:button size="sm" icon="pause" wire:click="cancelAtPeriodEnd" wire:confirm="Cancel at the end of the current billing period? The billable keeps access until then.">Cancel at period end</flux:button>
                                <flux:button size="sm" variant="danger" icon="x-mark" wire:click="forceCancel" wire:confirm="Cancel this subscription immediately? The billable loses access right now.">Force cancel</flux:button>
                            @endif
                        </div>
                    @endif
                @endif
            </x-mollie-billing::admin.section>
        </div>

        @php $grants = $this->grants(); @endphp
        <x-mollie-billing::admin.section
            title="Active grants"
            :description="'Free plan and addon grants currently applied. '.($grants->isEmpty() ? 'This billable has no active grants.' : $grants->count().' active.')"
        >
            <x-slot:actions>
                <flux:button size="xs" icon="gift" :href="route(BillingRoute::admin('grants.create'), $b)">Issue grant</flux:button>
            </x-slot:actions>

            @if ($grants->isEmpty())
                <x-mollie-billing::admin.empty
                    icon="gift"
                    title="No active grants"
                    description="Issue a grant to provide plan or addon access without charge."
                />
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Code</flux:table.column>
                        <flux:table.column>Mode</flux:table.column>
                        <flux:table.column>Plan / Addons</flux:table.column>
                        <flux:table.column>Applied</flux:table.column>
                        <flux:table.column>Days</flux:table.column>
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
                                    <flux:badge size="sm" :color="$mode === 'full' ? 'cyan' : 'blue'">
                                        {{ $mode === 'full' ? 'Full' : ($mode === 'addon_only' ? 'Addon-only' : $mode) }}
                                    </flux:badge>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <div class="space-y-1">
                                        @if ($plan)
                                            <div class="font-mono text-sm">{{ $plan }}@if ($interval) <span class="text-zinc-400">·</span> <span class="text-zinc-500">{{ ucfirst($interval) }}</span>@endif</div>
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
                                    {{ $g->applied_at?->format('Y-m-d') }}
                                    <flux:text size="xs" class="text-zinc-500">{{ $g->applied_at?->diffForHumans() }}</flux:text>
                                </flux:table.cell>
                                <flux:table.cell class="tabular-nums">{{ $g->grant_days_added ?? '—' }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:button
                                        size="xs"
                                        variant="danger"
                                        icon="x-mark"
                                        wire:click="revokeGrant({{ $g->id }})"
                                        wire:confirm="Revoke this grant? The billable will lose the granted access immediately."
                                    >Revoke</flux:button>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </x-mollie-billing::admin.section>
    @endif
</div>
