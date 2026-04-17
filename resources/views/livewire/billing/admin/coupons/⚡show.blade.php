<?php

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Support\BillingRoute;
use Livewire\Component;

new class extends Component {
    public ?Coupon $coupon = null;
    public ?string $flash = null;
    public ?string $error = null;

    public function mount(array $routeParameters = []): void
    {
        $id = $routeParameters['coupon'] ?? null;
        if ($id !== null) {
            $this->coupon = Coupon::find($id);
        }
    }

    public function deactivate(CouponService $service): void
    {
        if ($this->coupon) {
            $service->deactivate($this->coupon);
            $this->coupon->refresh();
            $this->flash = 'Coupon deactivated.';
        }
    }

    public function delete(CouponService $service)
    {
        if (! $this->coupon) return;
        try {
            $service->delete($this->coupon);
            session()->flash('status', 'Coupon deleted.');
            return $this->redirectRoute(BillingRoute::admin('coupons.index'), navigate: true);
        } catch (\Throwable $e) {
            report($e);
            $this->error = 'Unable to delete coupon. Please try again.';
        }
    }
};

?>

<div class="space-y-6">
    @if (! $coupon)
        <x-mollie-billing::admin.page-header
            title="Coupon not found"
            :back="route(BillingRoute::admin('coupons.index'))"
            backLabel="Coupons"
        />
        <flux:card>
            <x-mollie-billing::admin.empty
                icon="ticket"
                title="Coupon not found"
                description="The coupon you tried to open does not exist or has been deleted."
            />
        </flux:card>
    @else
        @php
            $type = $coupon->type;
            $isExpired = $coupon->valid_until && $coupon->valid_until->isPast();
            $isUsedUp = $coupon->max_redemptions !== null && $coupon->redemptions_count >= $coupon->max_redemptions;
            $redemptionPct = $coupon->max_redemptions
                ? min(100, round(($coupon->redemptions_count / $coupon->max_redemptions) * 100))
                : null;
        @endphp

        <div class="space-y-2">
            <flux:button :href="route(BillingRoute::admin('coupons.index'))" size="xs" variant="ghost" icon="arrow-left" class="-ml-2">Coupons</flux:button>

            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0 space-y-2">
                    <div class="flex flex-wrap items-center gap-3">
                        <flux:heading size="xl" class="font-mono">{{ $coupon->code }}</flux:heading>
                        <x-mollie-billing::admin.enum-badge :value="$coupon->type" />
                        @if ($coupon->active && ! $isExpired && ! $isUsedUp)
                            <flux:badge color="green" size="sm">Active</flux:badge>
                        @elseif ($isExpired)
                            <flux:badge color="zinc" size="sm">Expired</flux:badge>
                        @elseif ($isUsedUp)
                            <flux:badge color="zinc" size="sm">Used up</flux:badge>
                        @else
                            <flux:badge color="zinc" size="sm">Inactive</flux:badge>
                        @endif
                        @if ($coupon->stackable)
                            <flux:badge color="blue" size="sm" icon="squares-plus">Stackable</flux:badge>
                        @endif
                    </div>
                    @if ($coupon->name && $coupon->name !== $coupon->code)
                        <flux:text class="text-zinc-600 dark:text-zinc-400">{{ $coupon->name }}</flux:text>
                    @endif
                    @if ($coupon->description)
                        <flux:text class="max-w-2xl text-zinc-500 dark:text-zinc-400">{{ $coupon->description }}</flux:text>
                    @endif
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    @if ($coupon->active)
                        <flux:button size="sm" icon="no-symbol" wire:click="deactivate" wire:confirm="Deactivate this coupon?">Deactivate</flux:button>
                    @endif
                    <flux:button size="sm" variant="danger" icon="trash" wire:click="delete" wire:confirm="Delete this coupon? This cannot be undone.">Delete</flux:button>
                </div>
            </div>
        </div>

        <x-mollie-billing::admin.flash :success="$flash" :error="$error" />

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <x-mollie-billing::admin.stat
                label="Redemptions"
                :value="$coupon->redemptions_count.($coupon->max_redemptions ? ' / '.$coupon->max_redemptions : '')"
                icon="ticket"
                :hint="$redemptionPct !== null ? $redemptionPct.'% used' : 'No cap'"
            />
            <x-mollie-billing::admin.stat
                label="Max per billable"
                :value="$coupon->max_redemptions_per_billable ?? '∞'"
                icon="user"
            />
            <x-mollie-billing::admin.stat
                label="Valid until"
                :value="$coupon->valid_until?->format('Y-m-d') ?? 'No expiry'"
                icon="calendar"
                :tone="$isExpired ? 'danger' : null"
                :hint="$coupon->valid_until ? $coupon->valid_until->diffForHumans() : null"
            />
        </div>

        <x-mollie-billing::admin.section title="Benefit" description="What this coupon grants when redeemed.">
            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3">
                <x-mollie-billing::admin.detail label="Type">
                    <x-mollie-billing::admin.enum-badge :value="$coupon->type" />
                </x-mollie-billing::admin.detail>

                @if (in_array($type, [CouponType::FirstPayment, CouponType::Recurring]))
                    <x-mollie-billing::admin.detail label="Discount type">
                        {{ \GraystackIT\MollieBilling\Support\EnumLabels::label($coupon->discount_type) }}
                    </x-mollie-billing::admin.detail>
                    <x-mollie-billing::admin.detail label="Discount value" mono>
                        @if ($coupon->discount_type === DiscountType::Percentage)
                            {{ $coupon->discount_value }}%
                        @else
                            <x-mollie-billing::admin.money :cents="$coupon->discount_value ?? 0" />
                        @endif
                    </x-mollie-billing::admin.detail>
                    @if ($coupon->minimum_order_amount_net)
                        <x-mollie-billing::admin.detail label="Minimum order (net)" mono>
                            <x-mollie-billing::admin.money :cents="$coupon->minimum_order_amount_net" />
                        </x-mollie-billing::admin.detail>
                    @endif
                @endif

                @if ($type === CouponType::TrialExtension)
                    <x-mollie-billing::admin.detail label="Extends trial by" mono>
                        {{ $coupon->trial_extension_days }} days
                    </x-mollie-billing::admin.detail>
                @endif

                @if ($type === CouponType::AccessGrant)
                    <x-mollie-billing::admin.detail label="Plan" mono>
                        {{ $coupon->grant_plan_code ?? '— (addon-only) —' }}
                    </x-mollie-billing::admin.detail>
                    @if ($coupon->grant_interval)
                        <x-mollie-billing::admin.detail label="Interval">
                            {{ ucfirst($coupon->grant_interval) }}
                        </x-mollie-billing::admin.detail>
                    @endif
                    @if ($coupon->grant_duration_days)
                        <x-mollie-billing::admin.detail label="Duration" mono>
                            {{ $coupon->grant_duration_days }} days
                        </x-mollie-billing::admin.detail>
                    @endif
                    @if (! empty($coupon->grant_addon_codes))
                        <x-mollie-billing::admin.detail label="Addons" mono>
                            {{ implode(', ', $coupon->grant_addon_codes) }}
                        </x-mollie-billing::admin.detail>
                    @endif
                @endif

                @if ($type === CouponType::Credits && ! empty($coupon->credits_payload))
                    <x-mollie-billing::admin.detail label="Credits" mono>
                        @foreach ($coupon->credits_payload as $usage => $units)
                            <div>{{ $units }} × {{ $usage }}</div>
                        @endforeach
                    </x-mollie-billing::admin.detail>
                @endif
            </dl>
        </x-mollie-billing::admin.section>

        <x-mollie-billing::admin.section title="Validity & limits">
            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3">
                <x-mollie-billing::admin.detail label="Valid from" mono>
                    {{ $coupon->valid_from?->format('Y-m-d H:i') ?? 'Immediately' }}
                </x-mollie-billing::admin.detail>
                <x-mollie-billing::admin.detail label="Valid until" mono>
                    {{ $coupon->valid_until?->format('Y-m-d H:i') ?? 'No expiry' }}
                </x-mollie-billing::admin.detail>
                <x-mollie-billing::admin.detail label="Redemptions" mono>
                    {{ $coupon->redemptions_count }}{{ $coupon->max_redemptions ? ' / '.$coupon->max_redemptions : ' / ∞' }}
                </x-mollie-billing::admin.detail>
                <x-mollie-billing::admin.detail label="Max per billable" mono>
                    {{ $coupon->max_redemptions_per_billable ?? '∞' }}
                </x-mollie-billing::admin.detail>
                <x-mollie-billing::admin.detail label="Stackable">
                    <flux:badge :color="$coupon->stackable ? 'blue' : 'zinc'" size="sm">{{ $coupon->stackable ? 'Yes' : 'No' }}</flux:badge>
                </x-mollie-billing::admin.detail>
                <x-mollie-billing::admin.detail label="Auto-apply token" mono>
                    {{ $coupon->auto_apply_token ?? '—' }}
                </x-mollie-billing::admin.detail>
            </dl>
        </x-mollie-billing::admin.section>

        <x-mollie-billing::admin.section title="Redemption history" :description="$coupon->redemptions_count.' redemption'.($coupon->redemptions_count === 1 ? '' : 's').' recorded.'">
            @php $redemptions = $coupon->redemptions()->latest('applied_at')->limit(30)->get(); @endphp
            @if ($redemptions->isEmpty())
                <x-mollie-billing::admin.empty
                    icon="receipt-percent"
                    title="No redemptions yet"
                    description="This coupon has not been redeemed by any billable so far."
                />
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Applied</flux:table.column>
                        <flux:table.column>Billable</flux:table.column>
                        <flux:table.column align="end">Discount (net)</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($redemptions as $r)
                            <flux:table.row :key="$r->id">
                                <flux:table.cell class="tabular-nums">{{ $r->applied_at?->format('Y-m-d H:i') }}</flux:table.cell>
                                <flux:table.cell variant="strong" class="font-mono">{{ class_basename($r->billable_type) }}#{{ $r->billable_id }}</flux:table.cell>
                                <flux:table.cell align="end">
                                    <x-mollie-billing::admin.money :cents="$r->discount_amount_net ?? 0" />
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </x-mollie-billing::admin.section>
    @endif
</div>
