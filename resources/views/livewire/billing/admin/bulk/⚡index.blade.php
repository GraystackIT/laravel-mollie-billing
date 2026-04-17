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
    public ?string $error = null;

    public function mount(SubscriptionCatalogInterface $catalog): void
    {
        $plans = $catalog->allPlans();
        $usageTypes = $catalog->allUsageTypes();

        $this->planFilter = $plans[0] ?? '';
        $this->creditType = $usageTypes[0] ?? '';
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
        $this->flash = $this->error = null;
        $class = config('mollie-billing.billable_model');
        if (! $class) { $this->error = 'No billable model configured.'; return; }
        if ($this->creditUnits <= 0) { $this->error = 'Units must be greater than zero.'; return; }
        if ($this->creditType === '') { $this->error = 'Select a usage type.'; return; }

        $count = 0;
        $class::query()->where('subscription_plan_code', $this->planFilter)->each(function ($b) use ($service, &$count): void {
            $service->creditWalletOnly($b, $this->creditType, $this->creditUnits, 'bulk credit');
            $count++;
        });
        $this->flash = "Credited {$count} billables.";
    }

    public function extendTrials(): void
    {
        $this->flash = $this->error = null;
        $class = config('mollie-billing.billable_model');
        if (! $class) { $this->error = 'No billable model configured.'; return; }

        $count = 0;
        $class::query()->where('subscription_plan_code', $this->planFilter)->each(function ($b) use (&$count): void {
            $b->extendBillingTrialUntil(Carbon::now()->addDays($this->trialDays));
            $count++;
        });
        $this->flash = "Extended trials for {$count} billables.";
    }
};

?>

<div class="space-y-6">
    <x-mollie-billing::admin.page-header
        title="Bulk actions"
        subtitle="Operate on all billables on a specific plan at once. Use with care."
    />

    <x-mollie-billing::admin.flash :success="$flash" :error="$error" />

    <x-mollie-billing::admin.section title="Target" description="Actions below apply to every billable on the selected plan.">
        <flux:select wire:model.live="planFilter" label="Plan">
            @foreach ($planOptions as $code => $name)
                <flux:select.option value="{{ $code }}">{{ $name }}</flux:select.option>
            @endforeach
        </flux:select>
    </x-mollie-billing::admin.section>

    <x-mollie-billing::admin.section title="Mass wallet credit" description="Credits every targeted billable's wallet by the same amount.">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <flux:select wire:model="creditType" label="Usage type" description="Which wallet to credit.">
                @foreach ($usageTypes as $type)
                    <flux:select.option value="{{ $type }}">{{ $type }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input
                type="number"
                wire:model="creditUnits"
                label="Units per billable"
                description="Whole units to add. Example: 100"
                placeholder="100"
                min="1"
            />
        </div>

        <div class="flex justify-end">
            <flux:button wire:click="creditWallets" size="sm" variant="primary" icon="plus" wire:confirm="Credit all billables on this plan?">Credit all</flux:button>
        </div>
    </x-mollie-billing::admin.section>

    <x-mollie-billing::admin.section title="Mass trial extension" description="Extends every targeted billable's trial by the same number of days from today.">
        <flux:input
            type="number"
            wire:model="trialDays"
            label="Days"
            description="Whole days added from today. Example: 7"
            placeholder="7"
            suffix="days"
            min="1"
        />

        <div class="flex justify-end">
            <flux:button wire:click="extendTrials" size="sm" variant="primary" icon="clock" wire:confirm="Extend trials for all billables on this plan?">Extend all</flux:button>
        </div>
    </x-mollie-billing::admin.section>
</div>
