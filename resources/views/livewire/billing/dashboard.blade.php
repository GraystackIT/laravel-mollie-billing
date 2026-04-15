<?php

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use Livewire\Component;

new class extends Component {
    public ?Billable $billable = null;

    public function mount(): void
    {
        $this->billable = MollieBilling::resolveBillable(request());
    }
};

?>

<div class="p-6 space-y-6">
    <h1 class="text-2xl font-semibold">Billing</h1>

    @if (! $billable)
        <p class="text-zinc-500">No billable context. Register a resolver via <code>MollieBilling::resolveBillableUsing(...)</code>.</p>
    @else
        <section class="p-4 rounded-lg border bg-white dark:bg-zinc-800 space-y-2">
            <div class="text-xs uppercase text-zinc-500">Current plan</div>
            <div class="text-xl font-semibold">{{ $billable->getCurrentBillingPlanName() ?? 'Free' }}</div>
            <div class="text-sm text-zinc-600">Status: {{ $billable->getBillingSubscriptionStatus()->value }}</div>
            @if ($billable->nextBillingDate())
                <div class="text-sm text-zinc-600">Next billing: {{ $billable->nextBillingDate()->format('Y-m-d') }}</div>
            @endif
            @if ($billable->isOnBillingTrial())
                <div class="text-sm text-amber-700">Trial ends: {{ $billable->getBillingTrialEndsAt()?->format('Y-m-d') }}</div>
            @endif
        </section>

        <section class="flex flex-wrap gap-2 text-sm">
            <a href="{{ $billable->billingPlanChangeUrl() }}" class="px-3 py-1.5 rounded border">Change plan</a>
            <a href="{{ $billable->billingCheckoutUrl() }}" class="px-3 py-1.5 rounded border">Checkout</a>
            <a href="{{ route('billing.invoices') }}" class="px-3 py-1.5 rounded border">Invoices</a>
        </section>

        <section class="p-4 rounded-lg border bg-white dark:bg-zinc-800">
            <h2 class="text-sm uppercase text-zinc-500 mb-2">Recent invoices</h2>
            <ul class="divide-y">
                @forelse ($billable->billingInvoices()->limit(3)->get() as $invoice)
                    <li class="py-2 flex items-center justify-between">
                        <span>{{ $invoice->created_at->format('Y-m-d') }} · {{ number_format($invoice->amount_gross / 100, 2) }}</span>
                        <span class="text-xs uppercase">{{ $invoice->status->value }}</span>
                    </li>
                @empty
                    <li class="py-2 text-zinc-500 text-sm">No invoices yet.</li>
                @endforelse
            </ul>
        </section>
    @endif
</div>
