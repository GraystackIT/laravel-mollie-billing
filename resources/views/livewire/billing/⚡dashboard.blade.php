<?php

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Support\BillingRoute;
use Livewire\Component;

new class extends Component {
    public ?string $flash = null;
    public bool $flashError = false;

    private function resolveBillable(): ?Billable
    {
        return MollieBilling::resolveBillable(request());
    }

    public function cancelSubscription(): void
    {
        $billable = $this->resolveBillable();
        if (! $billable) return;
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

    public function resubscribe(): void
    {
        $billable = $this->resolveBillable();
        if (! $billable) return;
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

    public function cancelScheduledChange(): void
    {
        $billable = $this->resolveBillable();
        if (! $billable) return;
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

    public function dashboardData(?Billable $billable): array
    {
        $b = $billable;
        if (! $b) return [];

        $status = $b->getBillingSubscriptionStatus();
        $planCode = $b->getBillingSubscriptionPlanCode();
        $interval = $b->getBillingSubscriptionInterval();
        $currency = config('mollie-billing.currency', 'EUR');
        $addonCodes = $b->getActiveBillingAddonCodes();

        $usageTypes = [];
        if ($planCode) {
            $catalog = app(SubscriptionCatalogInterface::class);
            foreach ($catalog->includedUsages($planCode, $interval) as $type => $included) {
                $used = $b->usedBillingQuota((string) $type);
                $remaining = $b->remainingBillingQuota((string) $type);
                $overage = $b->billingOverageCount((string) $type);
                $percent = $included > 0 ? min(100, (int) round($used / $included * 100)) : 0;
                $threshold = (int) config('mollie-billing.usage_threshold_percent', 80);

                $usageTypes[] = [
                    'label' => ucfirst((string) $type),
                    'used' => $used,
                    'included' => $included,
                    'remaining' => $remaining,
                    'overage' => $overage,
                    'percent' => $percent,
                    'isWarning' => $percent >= $threshold && $percent < 100,
                    'isDanger' => $percent >= 100,
                ];
            }
        }

        $invoices = $b->billingInvoices()->latest()->limit(5)->get()->map(fn ($inv) => [
            'date' => $inv->created_at->translatedFormat('d. M Y'),
            'kind' => $inv->invoice_kind?->value ?? '—',
            'amount' => ($currency === 'EUR' ? '€' : $currency) . number_format($inv->amount_gross / 100, 2),
            'status' => $inv->status->value,
            'statusColor' => $inv->status->value === 'paid' ? 'lime' : ($inv->status->value === 'refunded' ? 'amber' : 'zinc'),
            'pdfUrl' => $inv->hasPdf() ? $inv->getDownloadUrl() : null,
        ])->all();

        $addonLabels = array_map(
            fn (string $code) => config("mollie-billing-plans.addons.{$code}.name", $code),
            $addonCodes,
        );

        return [
            'status' => $status,
            'statusLabel' => $status->value,
            'statusColor' => match($status) {
                SubscriptionStatus::Active => 'lime',
                SubscriptionStatus::Trial => 'amber',
                SubscriptionStatus::PastDue => 'red',
                default => 'zinc',
            },
            'accentClass' => match($status) {
                SubscriptionStatus::Active => 'bg-emerald-500',
                SubscriptionStatus::Trial => 'bg-amber-400',
                SubscriptionStatus::PastDue => 'bg-red-500',
                default => 'bg-zinc-300 dark:bg-zinc-600',
            },
            'hasSubscription' => $planCode !== null,
            'planName' => $b->getCurrentBillingPlanName() ?? __('billing::portal.no_subscription'),
            'interval' => $interval,
            'nextBilling' => $b->nextBillingDate()?->translatedFormat('d. M Y') ?? '—',
            'periodStart' => $b->getBillingPeriodStartsAt()?->translatedFormat('d. M Y') ?? '—',
            'seatCount' => $b->getBillingSeatCount(),
            'includedSeats' => $b->getIncludedBillingSeats(),
            'addonCount' => count($addonCodes),
            'addonLabels' => $addonLabels,
            'isTrial' => $status === SubscriptionStatus::Trial,
            'isPastDue' => $status === SubscriptionStatus::PastDue,
            'isActive' => $status === SubscriptionStatus::Active,
            'isCancelled' => $status === SubscriptionStatus::Cancelled,
            'trialEnds' => $b->getBillingTrialEndsAt()?->translatedFormat('d. M Y') ?? '—',
            'subscriptionEnds' => $b->getBillingSubscriptionEndsAt()?->translatedFormat('d. M Y'),
            'subscriptionEndsFuture' => $b->getBillingSubscriptionEndsAt()?->isFuture() ?? false,
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
                ? \Carbon\Carbon::parse($sc['scheduled_at'])->translatedFormat('d. M Y')
                : null,
        ];
    }
};

?>

@php
    $billable = $this->resolveBillable();
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
                    <flux:button size="sm" variant="primary" href="{{ route(BillingRoute::name('plan')) }}">
                        {{ __('billing::portal.plan_change') }}
                    </flux:button>
                    @if ($d['isActive'])
                        <flux:modal.trigger name="cancel-subscription">
                            <flux:button size="sm" variant="ghost" icon="x-circle">{{ __('billing::portal.cancel_subscription') }}</flux:button>
                        </flux:modal.trigger>
                    @elseif ($d['isCancelled'])
                        <flux:button size="sm" variant="primary" icon="arrow-path" wire:click="resubscribe">{{ __('billing::portal.resubscribe') }}</flux:button>
                    @endif
                </div>
            </div>

            @if ($d['hasSubscription'])
                <div class="border-t border-zinc-200/75 bg-zinc-50/50 px-6 py-5 dark:border-zinc-700/50 dark:bg-white/[0.02]">
                    <div class="grid grid-cols-2 gap-x-8 gap-y-5 sm:grid-cols-4">
                        <div class="mb-3">
                            <flux:subheading size="sm" class="text-zinc-400 dark:text-zinc-500">{{ __('billing::portal.next_billing') }}</flux:subheading>
                            <flux:text class="mt-1 font-semibold">{{ $d['nextBilling'] }}</flux:text>
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

        {{-- Usage meters --}}
        @if (count($d['usageTypes']) > 0)
            <div class="space-y-4">
                <flux:heading size="lg">{{ __('billing::portal.usage') }}</flux:heading>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($d['usageTypes'] as $usage)
                        <flux:card class="p-5!">
                            <div class="flex items-start justify-between gap-2">
                                <flux:subheading>{{ $usage['label'] }}</flux:subheading>
                                @if ($usage['overage'] > 0)
                                    <flux:badge size="sm" color="red">+{{ number_format($usage['overage']) }} {{ __('billing::portal.overage') }}</flux:badge>
                                @elseif ($usage['isWarning'])
                                    <flux:badge size="sm" color="amber">{{ $usage['percent'] }}%</flux:badge>
                                @endif
                            </div>
                            <div class="mt-3 flex items-baseline gap-1.5">
                                <span class="text-2xl font-bold tabular-nums tracking-tight">{{ number_format($usage['used']) }}</span>
                                <span class="text-sm text-zinc-400">/ {{ number_format($usage['included']) }}</span>
                            </div>
                            <div class="mt-4 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                <div
                                    class="h-full rounded-full transition-all duration-500 {{ $usage['isDanger'] ? 'bg-red-500' : ($usage['isWarning'] ? 'bg-amber-400' : 'bg-emerald-500') }}"
                                    style="width: {{ $usage['percent'] }}%"
                                ></div>
                            </div>
                            <flux:text class="mt-2 text-xs text-zinc-400">{{ number_format($usage['remaining']) }} {{ __('billing::portal.remaining') }}</flux:text>
                        </flux:card>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Recent invoices --}}
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">{{ __('billing::portal.recent_invoices') }}</flux:heading>
                <flux:button size="sm" variant="ghost" href="{{ route(BillingRoute::name('invoices')) }}" icon:trailing="arrow-right">
                    {{ __('billing::portal.view_all_invoices') }}
                </flux:button>
            </div>

            <flux:card class="p-0! overflow-hidden">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('billing::portal.invoice.date') }}</flux:table.column>
                        <flux:table.column>{{ __('billing::portal.invoice.kind') }}</flux:table.column>
                        <flux:table.column class="text-right">{{ __('billing::portal.invoice.amount') }}</flux:table.column>
                        <flux:table.column>{{ __('billing::portal.invoice.status') }}</flux:table.column>
                        <flux:table.column class="text-right"></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($d['invoices'] as $inv)
                            <flux:table.row>
                                <flux:table.cell class="tabular-nums">{{ $inv['date'] }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" color="zinc">{{ $inv['kind'] }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell class="text-right tabular-nums font-medium">{{ $inv['amount'] }}</flux:table.cell>
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
                                <flux:table.cell colspan="5" class="text-center text-zinc-400">{{ __('billing::portal.no_invoices') }}</flux:table.cell>
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
    @endif
</div>
