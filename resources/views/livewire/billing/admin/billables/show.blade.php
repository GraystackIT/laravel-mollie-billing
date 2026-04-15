<?php

use Livewire\Component;

new class extends Component {
    public mixed $billable = null;

    public function mount(mixed $billable = null): void
    {
        $class = config('mollie-billing.billable_model');
        if ($class && $billable !== null) {
            $this->billable = $class::find($billable);
        }
    }
};

?>

<div class="p-6 space-y-6">
    @if (! $billable)
        <flux:text class="text-zinc-500">Billable not found.</flux:text>
    @else
        <flux:heading size="xl">{{ $billable->name }}</flux:heading>
        <flux:text class="text-zinc-600 dark:text-zinc-400">{{ $billable->email }}</flux:text>

        <div class="grid gap-4 md:grid-cols-3">
            <flux:card>
                <flux:text size="xs" class="uppercase text-zinc-500">Plan</flux:text>
                <flux:heading size="md" class="mt-1">{{ $billable->subscription_plan_code ?? '—' }}</flux:heading>
            </flux:card>
            <flux:card>
                <flux:text size="xs" class="uppercase text-zinc-500">Status</flux:text>
                <flux:heading size="md" class="mt-1">{{ is_object($billable->subscription_status) ? $billable->subscription_status->value : $billable->subscription_status }}</flux:heading>
            </flux:card>
            <flux:card>
                <flux:text size="xs" class="uppercase text-zinc-500">Mandate</flux:text>
                <flux:heading size="md" class="mt-1">{{ $billable->mollie_mandate_id ? 'yes' : 'no' }}</flux:heading>
            </flux:card>
        </div>

        <flux:card>
            <flux:heading size="lg">Subscription</flux:heading>
            <div class="mt-3">
                <livewire:mollie-billing::admin.billables.subscription-tab :billable-id="$billable->getKey()" />
            </div>
        </flux:card>

        <flux:card>
            <flux:heading size="lg">Invoices</flux:heading>
            <div class="mt-3">
                <livewire:mollie-billing::admin.billables.invoices-tab :billable-id="$billable->getKey()" />
            </div>
        </flux:card>

        <flux:card>
            <flux:heading size="lg">Wallet</flux:heading>
            <div class="mt-3">
                <livewire:mollie-billing::admin.billables.wallet-tab :billable-id="$billable->getKey()" />
            </div>
        </flux:card>
    @endif
</div>
