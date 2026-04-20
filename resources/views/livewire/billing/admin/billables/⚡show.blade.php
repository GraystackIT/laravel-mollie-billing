<?php

use GraystackIT\MollieBilling\Support\BillingRoute;
use Livewire\Component;

new class extends Component {
    public mixed $billable = null;

    public function mount(array $routeParameters = []): void
    {
        $id = $routeParameters['billable'] ?? null;
        $class = config('mollie-billing.billable_model');
        if ($class && $id !== null && is_string($class) && is_subclass_of($class, \Illuminate\Database\Eloquent\Model::class)) {
            $this->billable = (new $class)->resolveRouteBinding($id);
        }
    }
};

?>

<div class="space-y-6">
    @if (! $billable)
        <x-mollie-billing::admin.page-header
            title="Billable not found"
            :back="route(BillingRoute::admin('billables.index'))"
            backLabel="Billables"
        />
        <flux:card>
            <x-mollie-billing::admin.empty
                icon="user"
                title="Billable not found"
                description="The requested billable does not exist or has been removed."
            />
        </flux:card>
    @else
        @php
            $initials = collect(explode(' ', trim((string) $billable->name)))
                ->filter()
                ->take(2)
                ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
                ->implode('');
        @endphp

        <div class="space-y-2">
            <flux:button :href="route(BillingRoute::admin('billables.index'))" size="xs" variant="ghost" icon="arrow-left" class="-ml-2">Billables</flux:button>

            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex min-w-0 items-center gap-4">
                    <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-lg font-semibold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                        {{ $initials ?: '?' }}
                    </div>
                    <div class="min-w-0 space-y-1">
                        <div class="flex flex-wrap items-center gap-3">
                            <flux:heading size="xl" class="truncate">{{ $billable->name }}</flux:heading>
                            <x-mollie-billing::admin.enum-badge :value="$billable->subscription_status" />
                        </div>
                        <flux:text class="text-zinc-600 dark:text-zinc-400">{{ $billable->email }}</flux:text>
                    </div>
                </div>

            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-3">
            <x-mollie-billing::admin.stat
                label="Plan"
                :value="$billable->subscription_plan_code ?? '—'"
                icon="squares-2x2"
                :hint="$billable->subscription_interval?->label()"
            />
            <x-mollie-billing::admin.stat
                label="Status"
                :value="$billable->subscription_status->label()"
                icon="signal"
            />
            <x-mollie-billing::admin.stat
                label="Mandate"
                :value="$billable->mollie_mandate_id ? 'Active' : 'None'"
                icon="credit-card"
                :tone="$billable->mollie_mandate_id ? 'success' : null"
                :hint="$billable->mollie_mandate_id ?? 'No payment method on file'"
            />
        </div>

        <flux:tab.group>
            <flux:tabs>
                <flux:tab name="subscription" icon="arrow-path">Subscription</flux:tab>
                <flux:tab name="invoices" icon="document-text">Invoices</flux:tab>
                <flux:tab name="wallet" icon="wallet">Wallet</flux:tab>
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
        </flux:tab.group>
    @endif
</div>
