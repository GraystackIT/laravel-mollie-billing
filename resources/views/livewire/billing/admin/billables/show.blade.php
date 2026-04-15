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
        <p class="text-zinc-500">Billable not found.</p>
    @else
        <flux:heading size="xl">{{ $billable->name }}</flux:heading>
        <div class="text-sm text-zinc-600">{{ $billable->email }}</div>

        <div class="grid gap-4 md:grid-cols-3">
            <div class="p-3 border rounded"><div class="text-xs uppercase text-zinc-500">Plan</div><div class="font-medium">{{ $billable->subscription_plan_code ?? '—' }}</div></div>
            <div class="p-3 border rounded"><div class="text-xs uppercase text-zinc-500">Status</div><div class="font-medium">{{ is_object($billable->subscription_status) ? $billable->subscription_status->value : $billable->subscription_status }}</div></div>
            <div class="p-3 border rounded"><div class="text-xs uppercase text-zinc-500">Mandate</div><div class="font-medium">{{ $billable->mollie_mandate_id ? 'yes' : 'no' }}</div></div>
        </div>

        <section class="p-4 border rounded">
            <flux:heading size="lg">Subscription</flux:heading>
            <livewire:billing::admin.billables.subscription-tab :billable-id="$billable->getKey()" />
        </section>

        <section class="p-4 border rounded">
            <flux:heading size="lg">Invoices</flux:heading>
            <livewire:billing::admin.billables.invoices-tab :billable-id="$billable->getKey()" />
        </section>

        <section class="p-4 border rounded">
            <flux:heading size="lg">Wallet</flux:heading>
            <livewire:billing::admin.billables.wallet-tab :billable-id="$billable->getKey()" />
        </section>
    @endif
</div>
