<?php

use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Support\BillingRoute;
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
    public ?string $error = null;

    public function mount(SubscriptionCatalogInterface $catalog, array $routeParameters = []): void
    {
        $this->billableId = $routeParameters['billable'] ?? null;
        $this->planCode = $catalog->allPlans()[0] ?? '';
    }

    public function with(SubscriptionCatalogInterface $catalog): array
    {
        $class = config('mollie-billing.billable_model');
        return [
            'planOptions' => collect($catalog->allPlans())
                ->mapWithKeys(fn (string $code) => [$code => $catalog->planName($code) ?: $code])
                ->all(),
            'addonOptions' => collect($catalog->allAddons())
                ->mapWithKeys(fn (string $code) => [$code => $catalog->addonName($code) ?: $code])
                ->all(),
            'billable' => $class && $this->billableId ? (new $class)->resolveRouteBinding($this->billableId) : null,
        ];
    }

    public function issue(CouponService $service)
    {
        $this->error = null;
        $class = config('mollie-billing.billable_model');
        $b = $class ? (new $class)->resolveRouteBinding($this->billableId) : null;
        if (! $b) { $this->error = 'Billable not found.'; return; }

        $code = 'GRANT-'.Str::upper(Str::random(8));
        try {
            $coupon = $this->mode === 'full'
                ? $service->accessGrantCoupon($code, $this->planCode, $this->interval, $this->addonCodes, $this->durationDays)
                : $service->addonGrantCoupon($code, $this->addonCodes);
            $service->redeem($coupon, $b, []);
            session()->flash('status', "Grant {$code} issued and redeemed for {$b->name}.");
            return $this->redirectRoute(BillingRoute::admin('billables.show'), ['billable' => $b], navigate: true);
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }
};

?>

<div class="space-y-6">
    <x-mollie-billing::admin.page-header
        title="Issue access grant"
        :subtitle="$billable ? 'For '.$billable->name.' ('.$billable->email.')' : 'Grants plan and/or addon access without charge.'"
        :back="$billable ? route(BillingRoute::admin('billables.show'), $billable) : route(BillingRoute::admin('billables.index'))"
        :backLabel="$billable ? $billable->name : 'Billables'"
    />

    <x-mollie-billing::admin.flash :error="$error" />

    <form wire:submit="issue" class="space-y-6">
        <x-mollie-billing::admin.section title="Grant configuration" description="Pick the mode and, for full grants, plan and duration.">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <flux:select wire:model.live="mode" label="Mode" description="Full grants include a plan; addon grants only toggle addons.">
                    <flux:select.option value="full">Full — plan + addons + duration</flux:select.option>
                    <flux:select.option value="addon">Addon-only</flux:select.option>
                </flux:select>

                @if ($mode === 'full')
                    <flux:input
                        type="number"
                        wire:model="durationDays"
                        label="Duration"
                        description="Length of the grant."
                        placeholder="30"
                        suffix="days"
                        min="1"
                    />

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
        </x-mollie-billing::admin.section>

        <x-mollie-billing::admin.section title="Addons" description="Optional — granted alongside the plan.">
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
        </x-mollie-billing::admin.section>

        <div class="flex justify-end gap-2">
            <flux:button type="button" variant="ghost" :href="$billable ? route(BillingRoute::admin('billables.show'), $billable) : route(BillingRoute::admin('billables.index'))">Cancel</flux:button>
            <flux:button type="submit" variant="primary" icon="gift">Issue grant</flux:button>
        </div>
    </form>
</div>
