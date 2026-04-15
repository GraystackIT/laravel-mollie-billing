<?php

use Carbon\Carbon;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use Livewire\Component;

new class extends Component {
    public string $planFilter = '';
    public int $creditUnits = 0;
    public string $creditType = '';
    public int $trialDays = 7;
    public ?string $flash = null;

    public function creditWallets(RefundInvoiceService $service): void
    {
        $class = config('mollie-billing.billable_model');
        if (! $class || $this->creditUnits <= 0 || $this->creditType === '') return;

        $count = 0;
        $class::query()->where('subscription_plan_code', $this->planFilter)->each(function ($b) use ($service, &$count): void {
            $service->creditWalletOnly($b, $this->creditType, $this->creditUnits, 'bulk credit');
            $count++;
        });
        $this->flash = "Credited {$count} billables.";
    }

    public function extendTrials(): void
    {
        $class = config('mollie-billing.billable_model');
        if (! $class) return;

        $count = 0;
        $class::query()->where('subscription_plan_code', $this->planFilter)->each(function ($b) use (&$count): void {
            $b->extendBillingTrialUntil(Carbon::now()->addDays($this->trialDays));
            $count++;
        });
        $this->flash = "Extended trials for {$count} billables.";
    }
};

?>

<div class="p-6 space-y-6 max-w-2xl">
    <flux:heading size="xl">Bulk actions</flux:heading>
    @if ($flash)<div class="p-3 rounded bg-green-50 border border-green-200 text-sm">{{ $flash }}</div>@endif
    <div><label>Target plan code</label><input wire:model="planFilter" class="border rounded px-2 py-1 w-full"></div>

    <fieldset class="border rounded p-4 space-y-2">
        <legend class="px-1 font-medium">Mass wallet credit</legend>
        <input wire:model="creditType" placeholder="Usage type" class="border rounded px-2 py-1 w-full">
        <input type="number" wire:model="creditUnits" placeholder="Units" class="border rounded px-2 py-1 w-full">
        <flux:button wire:click="creditWallets" size="sm" variant="primary">Credit all</flux:button>
    </fieldset>

    <fieldset class="border rounded p-4 space-y-2">
        <legend class="px-1 font-medium">Mass trial extension</legend>
        <input type="number" wire:model="trialDays" placeholder="Days" class="border rounded px-2 py-1 w-full">
        <flux:button wire:click="extendTrials" size="sm" variant="primary">Extend all</flux:button>
    </fieldset>
</div>
