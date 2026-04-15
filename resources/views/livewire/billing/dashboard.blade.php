<?php

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use Livewire\Component;

new class extends Component {
    public ?Billable $billable = null;
    public ?string $flash = null;

    public function mount(): void
    {
        $this->billable = MollieBilling::resolveBillable(request());
    }

    public function cancel(): void
    {
        if (! $this->billable) return;
        try {
            $this->billable->cancelBillingSubscription();
            $this->flash = __('billing::portal.flash.cancelled');
        } catch (\Throwable $e) {
            $this->flash = $e->getMessage();
        }
    }

    public function resubscribe(): void
    {
        if (! $this->billable) return;
        try {
            $this->billable->resubscribeBillingPlan();
            $this->flash = __('billing::portal.flash.resubscribed');
        } catch (\Throwable $e) {
            $this->flash = $e->getMessage();
        }
    }
};

?>

<div class="space-y-6">
    <flux:heading size="xl">{{ __('billing::portal.dashboard') }}</flux:heading>

    @if ($flash)
        <flux:callout variant="secondary" icon="information-circle">{{ $flash }}</flux:callout>
    @endif

    @if (! $billable)
        <flux:callout variant="warning" icon="exclamation-triangle">
            No billable context. Register a resolver via <code>MollieBilling::resolveBillableUsing(...)</code>.
        </flux:callout>
    @else
        @php
            $status = $billable->getBillingSubscriptionStatus();
            $isCancelled = $status === SubscriptionStatus::Cancelled;
            $isActive = $status === SubscriptionStatus::Active;
        @endphp

        <flux:card class="space-y-3">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <flux:text size="sm" class="uppercase tracking-wide text-zinc-500">{{ __('billing::portal.current_plan') }}</flux:text>
                    <flux:heading size="lg">{{ $billable->getCurrentBillingPlanName() ?? 'Free' }}</flux:heading>
                </div>
                <flux:badge :color="$isActive ? 'lime' : ($isCancelled ? 'zinc' : 'amber')">
                    {{ $status->value }}
                </flux:badge>
            </div>

            <flux:separator />

            <dl class="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                @if ($billable->nextBillingDate())
                    <div>
                        <dt class="text-zinc-500">{{ __('billing::portal.next_billing') }}</dt>
                        <dd class="font-medium">{{ $billable->nextBillingDate()->format('Y-m-d') }}</dd>
                    </div>
                @endif
                @if ($billable->isOnBillingTrial())
                    <div>
                        <dt class="text-zinc-500">{{ __('billing::portal.trial_ends') }}</dt>
                        <dd class="font-medium text-amber-700">{{ $billable->getBillingTrialEndsAt()?->format('Y-m-d') }}</dd>
                    </div>
                @endif
            </dl>

            <div class="flex flex-wrap gap-2 pt-2">
                <flux:button size="sm" variant="primary" href="{{ route('billing.plan') }}">
                    {{ __('billing::portal.plan_change') }}
                </flux:button>
                <flux:button size="sm" variant="ghost" href="{{ route('billing.invoices') }}" icon="document-text">
                    {{ __('billing::portal.invoices') }}
                </flux:button>
                @if ($isActive)
                    <flux:modal.trigger name="cancel-subscription">
                        <flux:button size="sm" variant="danger" icon="x-circle">
                            {{ __('billing::portal.cancel_subscription') }}
                        </flux:button>
                    </flux:modal.trigger>
                @elseif ($isCancelled)
                    <flux:button size="sm" variant="primary" icon="arrow-path" wire:click="resubscribe">
                        {{ __('billing::portal.resubscribe') }}
                    </flux:button>
                @endif
            </div>
        </flux:card>

        <flux:card>
            <flux:heading size="sm" class="mb-3">{{ __('billing::portal.recent_invoices') }}</flux:heading>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('billing::portal.invoice.date') }}</flux:table.column>
                    <flux:table.column>{{ __('billing::portal.invoice.amount') }}</flux:table.column>
                    <flux:table.column>{{ __('billing::portal.invoice.status') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($billable->billingInvoices()->latest()->limit(5)->get() as $invoice)
                        <flux:table.row>
                            <flux:table.cell>{{ $invoice->created_at->format('Y-m-d') }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($invoice->amount_gross / 100, 2) }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" color="zinc">{{ $invoice->status->value }}</flux:badge>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="3" class="text-zinc-500">{{ __('billing::portal.no_invoices') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>

        <flux:modal name="cancel-subscription" class="max-w-md">
            <div class="space-y-4">
                <flux:heading size="lg">{{ __('billing::portal.cancel_confirm.title') }}</flux:heading>
                <flux:text>{{ __('billing::portal.cancel_confirm.body') }}</flux:text>
                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('billing::portal.cancel_confirm.keep') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="danger" wire:click="cancel" x-on:click="$flux.modal('cancel-subscription').close()">
                        {{ __('billing::portal.cancel_confirm.confirm') }}
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</div>
