<?php

use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use Illuminate\Support\Str;
use Livewire\Component;

new class extends Component {
    public mixed $billableId = null;
    public string $mode = 'full';
    public string $planCode = '';
    public string $interval = 'monthly';
    /** @var array<int, string> */
    public array $addonCodes = [];
    public int $durationDays = 30;
    public ?string $flash = null;

    public function mount(mixed $billable = null, SubscriptionCatalogInterface $catalog): void
    {
        $this->billableId = $billable;
        $this->planCode = $catalog->allPlans()[0] ?? '';
    }

    public function with(SubscriptionCatalogInterface $catalog): array
    {
        return [
            'planOptions' => collect($catalog->allPlans())
                ->mapWithKeys(fn (string $code) => [$code => $catalog->planName($code) ?: $code])
                ->all(),
            'addonOptions' => collect($catalog->allAddons())
                ->mapWithKeys(fn (string $code) => [$code => $catalog->addonName($code) ?: $code])
                ->all(),
        ];
    }

    public function issue(CouponService $service): void
    {
        $class = config('mollie-billing.billable_model');
        $b = $class?->find($this->billableId);
        if (! $b) { $this->flash = 'Billable not found.'; return; }

        $code = 'GRANT-'.Str::upper(Str::random(8));
        try {
            $coupon = $this->mode === 'full'
                ? $service->accessGrantCoupon($code, $this->planCode, $this->interval, $this->addonCodes, $this->durationDays)
                : $service->addonGrantCoupon($code, $this->addonCodes);
            $service->redeem($coupon, $b, []);
            $this->flash = "Grant {$code} issued and redeemed.";
        } catch (\Throwable $e) {
            $this->flash = 'Error: '.$e->getMessage();
        }
    }
};

?>

<div class="p-6 space-y-6">
    <flux:heading size="xl">Issue access grant</flux:heading>

    @if ($flash)
        <flux:callout variant="success" icon="check-circle" inline>{{ $flash }}</flux:callout>
    @endif

    <form wire:submit="issue" class="space-y-6">
        <flux:card class="space-y-4">
            <div>
                <flux:heading size="md">Grant configuration</flux:heading>
                <flux:text class="text-zinc-500">Choose the mode and (for full grants) plan and duration.</flux:text>
            </div>

            <flux:separator />

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <flux:select wire:model.live="mode" label="Mode">
                    <flux:select.option value="full">Full — plan + addons + duration</flux:select.option>
                    <flux:select.option value="addon">Addon-only</flux:select.option>
                </flux:select>

                @if ($mode === 'full')
                    <flux:input type="number" wire:model="durationDays" label="Duration (days)" />

                    <flux:select wire:model="planCode" label="Plan" required>
                        @foreach ($planOptions as $code => $name)
                            <flux:select.option value="{{ $code }}">{{ $name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="interval" label="Interval">
                        <flux:select.option value="monthly">Monthly</flux:select.option>
                        <flux:select.option value="yearly">Yearly</flux:select.option>
                    </flux:select>
                @endif
            </div>
        </flux:card>

        <flux:card class="space-y-4">
            <div>
                <flux:heading size="md">Addons</flux:heading>
                <flux:text class="text-zinc-500">Optional — granted alongside the plan.</flux:text>
            </div>

            <flux:separator />

            <flux:select
                wire:model="addonCodes"
                label="Addons"
                multiple
                variant="listbox"
                :placeholder="count($addonOptions) ? 'Select addons…' : 'No addons configured'"
            >
                @foreach ($addonOptions as $code => $name)
                    <flux:select.option value="{{ $code }}">{{ $name }}</flux:select.option>
                @endforeach
            </flux:select>
        </flux:card>

        <div class="flex gap-2 justify-end">
            <flux:button type="submit" variant="primary">Issue grant</flux:button>
        </div>
    </form>
</div>
