@php

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

@endphp

<div class="space-y-3 text-sm">
    @php $b = $this->billable(); @endphp
    @if ($flash)<div class="p-2 rounded bg-green-50 border border-green-200">{{ $flash }}</div>@endif
    @if ($b)
        <ul class="space-y-1">
            <li><strong>Interval:</strong> {{ is_object($b->subscription_interval) ? $b->subscription_interval->value : $b->subscription_interval }}</li>
            <li><strong>Seats:</strong> {{ $b->getBillingSeatCount() }}</li>
            <li><strong>Addons:</strong> {{ implode(', ', $b->getActiveBillingAddonCodes() ?: ['—']) }}</li>
            <li><strong>Trial ends:</strong> {{ $b->trial_ends_at?->format('Y-m-d') ?? '—' }}</li>
            <li><strong>Subscription ends:</strong> {{ $b->subscription_ends_at?->format('Y-m-d') ?? '—' }}</li>
        </ul>
        <div class="flex flex-wrap gap-2 mt-3">
            <form wire:submit="extendTrial" class="flex gap-1 items-center">
                <input type="date" wire:model="trialUntil" class="border rounded px-2 py-1">
                <button class="px-3 py-1 border rounded">Extend trial</button>
            </form>
            <button wire:click="forceCancel" class="px-3 py-1 border rounded text-red-600">Force cancel</button>
            <button wire:click="resubscribe" class="px-3 py-1 border rounded">Resubscribe</button>
        </div>
    @endif
</div>
