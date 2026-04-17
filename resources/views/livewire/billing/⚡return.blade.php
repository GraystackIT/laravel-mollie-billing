<?php

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Support\BillingRoute;
use Livewire\Component;

new class extends Component {
    public ?Billable $billable = null;

    public function mount(): void
    {
        $this->billable = MollieBilling::resolveBillable(request());

        if ($this->billable !== null) {
            $hasSubscription = $this->billable->hasAccessibleBillingSubscription();
            MollieBilling::runAfterCheckout($this->billable, $hasSubscription);
        }
    }
};

?>

<div class="space-y-6">
    <flux:card class="space-y-4 text-center">
        <flux:icon.check-circle class="mx-auto size-12 text-lime-500" />
        <flux:heading size="xl">{{ __('billing::portal.return.title') }}</flux:heading>
        <flux:text>{{ __('billing::portal.return.body') }}</flux:text>
        <div class="flex justify-center gap-2">
            <flux:button variant="primary" href="{{ route(BillingRoute::name('index')) }}">
                {{ __('billing::portal.return.to_dashboard') }}
            </flux:button>
            <flux:button variant="ghost" href="{{ route(BillingRoute::name('invoices')) }}">
                {{ __('billing::portal.return.to_invoices') }}
            </flux:button>
        </div>
    </flux:card>
</div>
