<?php

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Support\BillingRoute;
use Livewire\Component;

new class extends Component {
    public bool $activated = false;
    public ?string $origin = null;

    public function mount(): void
    {
        $this->origin = request()->query('origin');

        $billable = MollieBilling::resolveBillable(request());

        // One-time order returns don't need subscription polling — the payment
        // is a oneoff and the webhook handles everything asynchronously.
        if ($this->origin === 'products') {
            $this->activated = true;
            return;
        }

        if ($billable?->hasAccessibleBillingSubscription()) {
            $this->activated = true;
        }
    }

    public function checkStatus(): void
    {
        if ($this->activated) {
            return;
        }

        $billable = MollieBilling::resolveBillable(request());

        if ($billable === null) {
            return;
        }

        /** @var \Illuminate\Database\Eloquent\Model&Billable $billable */
        $billable->refresh();

        if ($billable->hasAccessibleBillingSubscription()) {
            $this->activated = true;
        }
    }

    public function returnUrl(): string
    {
        if ($this->origin === 'products') {
            return route(BillingRoute::name('products'));
        }

        $configured = config('mollie-billing.redirect_after_return');

        return $configured
            ? route($configured)
            : route(BillingRoute::name('index'));
    }
};

?>

<div class="space-y-6"
    @unless($activated)
        wire:poll.2s="checkStatus"
    @endunless
>
    <flux:card class="space-y-4 text-center">
        @if($activated)
            <flux:icon.check-circle class="mx-auto size-12 text-lime-500" />
            <flux:heading size="xl">{{ __('billing::portal.return.title') }}</flux:heading>
            <flux:text>{{ __('billing::portal.return.body') }}</flux:text>
            <div class="flex justify-center gap-2">
                <flux:button variant="primary" href="{{ $this->returnUrl() }}">
                    {{ $origin === 'products' ? __('billing::portal.products.back_to_products') : __('billing::portal.return.to_dashboard') }}
                </flux:button>
            </div>
        @else
            <div class="flex justify-center">
                <flux:icon.arrow-path class="size-12 text-zinc-400 animate-spin" />
            </div>
            <flux:heading size="xl">{{ __('billing::portal.return.processing_title') }}</flux:heading>
            <flux:text>{{ __('billing::portal.return.processing_body') }}</flux:text>
        @endif
    </flux:card>
</div>
