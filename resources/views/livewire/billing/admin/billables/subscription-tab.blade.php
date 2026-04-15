<?php

use Carbon\Carbon;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Services\Billing\ResubscribeSubscription;
use Livewire\Component;

new class extends Component {
    public mixed $billableId = null;
    public ?string $trialUntil = null;
    public ?string $flash = null;

    public function mount(mixed $billableId = null): void { $this->billableId = $billableId; }

    public function billable(): mixed
    {
        $class = config('mollie-billing.billable_model');
        return $class ? $class::find($this->billableId) : null;
    }

    public function extendTrial(): void
    {
        if (! $this->trialUntil) return;
        $this->billable()?->extendBillingTrialUntil(Carbon::parse($this->trialUntil));
        $this->flash = 'Trial extended.';
    }

    public function forceCancel(CancelSubscription $service): void
    {
        $b = $this->billable();
        if ($b) { $service->handle($b, true); $this->flash = 'Subscription cancelled immediately.'; }
    }

    public function resubscribe(ResubscribeSubscription $service): void
    {
        $b = $this->billable();
        if ($b) {
            try { $service->handle($b); $this->flash = 'Resubscribed.'; }
            catch (\Throwable $e) { $this->flash = 'Error: '.$e->getMessage(); }
        }
    }
};

?>

<div class="space-y-3">
    @php $b = $this->billable(); @endphp
    @if ($flash)
        <flux:callout variant="success" icon="check-circle" inline>{{ $flash }}</flux:callout>
    @endif
    @if ($b)
        <dl class="grid grid-cols-2 gap-2 text-sm">
            <dt class="text-zinc-500 dark:text-zinc-400">Interval</dt>
            <dd>{{ is_object($b->subscription_interval) ? $b->subscription_interval->value : $b->subscription_interval }}</dd>
            <dt class="text-zinc-500 dark:text-zinc-400">Seats</dt>
            <dd>{{ $b->getBillingSeatCount() }}</dd>
            <dt class="text-zinc-500 dark:text-zinc-400">Addons</dt>
            <dd>{{ implode(', ', $b->getActiveBillingAddonCodes() ?: ['—']) }}</dd>
            <dt class="text-zinc-500 dark:text-zinc-400">Trial ends</dt>
            <dd>{{ $b->trial_ends_at?->format('Y-m-d') ?? '—' }}</dd>
            <dt class="text-zinc-500 dark:text-zinc-400">Subscription ends</dt>
            <dd>{{ $b->subscription_ends_at?->format('Y-m-d') ?? '—' }}</dd>
        </dl>

        <div class="flex flex-wrap gap-2 mt-3 items-end">
            <form wire:submit="extendTrial" class="flex gap-2 items-end">
                <flux:input type="date" wire:model="trialUntil" label="Trial until" />
                <flux:button type="submit" size="sm">Extend trial</flux:button>
            </form>
            <flux:button size="sm" variant="danger" wire:click="forceCancel">Force cancel</flux:button>
            <flux:button size="sm" wire:click="resubscribe">Resubscribe</flux:button>
        </div>
    @endif
</div>
