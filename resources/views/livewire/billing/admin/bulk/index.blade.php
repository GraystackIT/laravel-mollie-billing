<?php

use Carbon\Carbon;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use Livewire\Component;

new class extends Component {
    public string $planFilter = '';
    public int $creditUnits = 0;
    public string $creditType = '';
    public int $trialDays = 7;
    public ?string $flash = null;

    public function mount(SubscriptionCatalogInterface $catalog): void
    {
        $this->planFilter = $catalog->allPlans()[0] ?? '';
        $this->creditType = $catalog->allUsageTypes()[0] ?? '';
    }

    public function with(SubscriptionCatalogInterface $catalog): array
    {
        return [
            'planOptions' => collect($catalog->allPlans())
                ->mapWithKeys(fn (string $code) => [$code => $catalog->planName($code) ?: $code])
                ->all(),
            'usageTypes' => $catalog->allUsageTypes(),
        ];
    }

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

<div class="p-6 space-y-6">
    <flux:heading size="xl">Bulk actions</flux:heading>

    @if ($flash)
        <flux:callout variant="success" icon="check-circle" inline>{{ $flash }}</flux:callout>
    @endif

    <flux:card class="space-y-4">
        <div>
            <flux:heading size="md">Target</flux:heading>
            <flux:text class="text-zinc-500">Actions below apply to all billables on this plan.</flux:text>
        </div>

        <flux:separator />

        <flux:select wire:model="planFilter" label="Plan">
            @foreach ($planOptions as $code => $name)
                <flux:select.option value="{{ $code }}">{{ $name }}</flux:select.option>
            @endforeach
        </flux:select>
    </flux:card>

    <flux:card class="space-y-4">
        <div>
            <flux:heading size="md">Mass wallet credit</flux:heading>
            <flux:text class="text-zinc-500">Credits every targeted billable's wallet.</flux:text>
        </div>

        <flux:separator />

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <flux:select wire:model="creditType" label="Usage type">
                @foreach ($usageTypes as $type)
                    <flux:select.option value="{{ $type }}">{{ $type }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input type="number" wire:model="creditUnits" label="Units" />
        </div>

        <div class="flex justify-end">
            <flux:button wire:click="creditWallets" size="sm" variant="primary">Credit all</flux:button>
        </div>
    </flux:card>

    <flux:card class="space-y-4">
        <div>
            <flux:heading size="md">Mass trial extension</flux:heading>
            <flux:text class="text-zinc-500">Extends every targeted billable's trial from today.</flux:text>
        </div>

        <flux:separator />

        <flux:input type="number" wire:model="trialDays" label="Days" />

        <div class="flex justify-end">
            <flux:button wire:click="extendTrials" size="sm" variant="primary">Extend all</flux:button>
        </div>
    </flux:card>
</div>
