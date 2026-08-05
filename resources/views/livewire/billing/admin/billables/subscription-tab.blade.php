<?php

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\ResubscribeSubscription;
use GraystackIT\MollieBilling\Support\BillingTime;
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
            $this->error = __('billing::admin.billables.subscription.flash.pick_trial_date');
            return;
        }
        $this->billable()?->extendBillingTrialUntil(Carbon::parse($this->trialUntil));
        $this->flash = __('billing::admin.billables.subscription.flash.trial_extended', [
            'date' => Carbon::parse($this->trialUntil)->format('Y-m-d'),
        ]);
    }

    public function cancelAtPeriodEnd(CancelSubscription $service): void
    {
        $this->error = $this->flash = null;
        $b = $this->billable();
        if ($b) { $service->handle($b, false); $this->flash = __('billing::admin.billables.subscription.flash.cancelled_grace'); }
    }

    public function forceCancel(CancelSubscription $service): void
    {
        $this->error = $this->flash = null;
        $b = $this->billable();
        if ($b) { $service->handle($b, true); $this->flash = __('billing::admin.billables.subscription.flash.cancelled_now'); }
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

    public function revokeGrant(int $redemptionId, CouponService $service): void
    {
        $this->error = $this->flash = null;
        $redemption = CouponRedemption::find($redemptionId);
        if (! $redemption) { $this->error = __('billing::admin.billables.subscription.flash.grant_not_found'); return; }

        $b = $this->billable();
        if (! $b || $redemption->billable_type !== $b->getMorphClass() || (string) $redemption->billable_id !== (string) $b->getKey()) {
            $this->error = __('billing::admin.billables.subscription.flash.grant_foreign');
            return;
        }

        try {
            $service->revokeGrant($redemption, __('billing::admin.billables.subscription.flash.revoked_by_admin'));
            $this->flash = __('billing::admin.billables.subscription.flash.grant_revoked');
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
                                :label="__('billing::admin.billables.subscription.extend_trial_label')"
                                :description="__('billing::admin.billables.subscription.extend_trial_description')"
                                class="flex-1"
                            />
                            <flux:button type="submit" size="sm" variant="primary" icon="clock" class="shrink-0">{{ __('billing::admin.billables.subscription.extend') }}</flux:button>
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
                                <flux:button size="sm" icon="pause" wire:click="cancelAtPeriodEnd" wire:confirm="{{ __('billing::admin.billables.subscription.cancel_at_period_end_confirm') }}">{{ __('billing::admin.billables.subscription.cancel_at_period_end') }}</flux:button>
                                <flux:button size="sm" variant="danger" icon="x-mark" wire:click="forceCancel" wire:confirm="{{ __('billing::admin.billables.subscription.force_cancel_confirm') }}">{{ __('billing::admin.billables.subscription.force_cancel') }}</flux:button>
                            @endif
                        </div>
                    @endif
                @endif
            </x-mollie-billing::admin.section>
        </div>

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
                                    <flux:badge size="sm" :color="$mode === 'full' ? 'cyan' : 'blue'">
                                        {{ $mode === 'full' ? __('billing::admin.billables.subscription.mode_full') : ($mode === 'addon_only' ? __('billing::admin.billables.subscription.mode_addon_only') : $mode) }}
                                    </flux:badge>
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
                                    <flux:button
                                        size="xs"
                                        variant="danger"
                                        icon="x-mark"
                                        wire:click="revokeGrant({{ $g->id }})"
                                        wire:confirm="{{ __('billing::admin.billables.subscription.revoke_confirm') }}"
                                    >{{ __('billing::admin.billables.subscription.revoke') }}</flux:button>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </x-mollie-billing::admin.section>
    @endif
</div>
