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
        if (! $b) { $this->error = __('billing::admin.common.billable_not_found'); return; }

        $code = 'GRANT-'.Str::upper(Str::random(8));
        try {
            $coupon = $this->mode === 'full'
                ? $service->accessGrantCoupon($code, $this->planCode, $this->interval, $this->addonCodes, $this->durationDays)
                : $service->addonGrantCoupon($code, $this->addonCodes);
            $service->redeem($coupon, $b, []);
            session()->flash('status', __('billing::admin.grants.issued', [
                'code' => $code,
                'name' => $b->getBillingName(),
            ]));
            return $this->redirectRoute(BillingRoute::admin('billables.show'), ['billable' => $b], navigate: true);
        } catch (\Throwable $e) {
            report($e);
            $this->error = __('billing::admin.common.error_unexpected');
        }
    }
};

?>

<div class="space-y-6">
    <x-mollie-billing::admin.page-header
        :title="__('billing::admin.grants.title')"
        :subtitle="$billable
            ? __('billing::admin.grants.subtitle_for', ['name' => $billable->getBillingName(), 'email' => $billable->getBillingEmail()])
            : __('billing::admin.grants.subtitle_default')"
        :back="$billable ? route(BillingRoute::admin('billables.show'), $billable) : route(BillingRoute::admin('billables.index'))"
        :backLabel="$billable ? $billable->getBillingName() : __('billing::admin.nav.billables')"
    />

    <x-mollie-billing::admin.flash :error="$error" />

    <form wire:submit="issue" class="space-y-6">
        <x-mollie-billing::admin.section :title="__('billing::admin.grants.config_title')" :description="__('billing::admin.grants.config_description')">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <flux:select wire:model.live="mode" :label="__('billing::admin.grants.mode')" :description="__('billing::admin.grants.mode_description')">
                    <flux:select.option value="full">{{ __('billing::admin.grants.mode_full') }}</flux:select.option>
                    <flux:select.option value="addon">{{ __('billing::admin.grants.mode_addon') }}</flux:select.option>
                </flux:select>

                @if ($mode === 'full')
                    <flux:input
                        type="number"
                        wire:model="durationDays"
                        :label="__('billing::admin.grants.duration')"
                        :description="__('billing::admin.grants.duration_description')"
                        placeholder="30"
                        :suffix="__('billing::admin.common.days')"
                        min="1"
                    />

                    <flux:select wire:model="planCode" :label="__('billing::admin.common.plan')" required>
                        @foreach ($planOptions as $code => $name)
                            <flux:select.option value="{{ $code }}">{{ $name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="interval" :label="__('billing::admin.common.interval')">
                        <flux:select.option value="monthly">{{ __('billing::admin.common.monthly') }}</flux:select.option>
                        <flux:select.option value="yearly">{{ __('billing::admin.common.yearly') }}</flux:select.option>
                    </flux:select>
                @endif
            </div>
        </x-mollie-billing::admin.section>

        <x-mollie-billing::admin.section :title="__('billing::admin.grants.addons_title')" :description="__('billing::admin.grants.addons_description')">
            <flux:select
                wire:model="addonCodes"
                :label="__('billing::admin.grants.addons')"
                multiple
                variant="listbox"
                :placeholder="count($addonOptions) ? __('billing::admin.grants.addons_placeholder') : __('billing::admin.grants.addons_none')"
            >
                @foreach ($addonOptions as $code => $name)
                    <flux:select.option value="{{ $code }}">{{ $name }}</flux:select.option>
                @endforeach
            </flux:select>
        </x-mollie-billing::admin.section>

        <div class="flex justify-end gap-2">
            <flux:button type="button" variant="ghost" :href="$billable ? route(BillingRoute::admin('billables.show'), $billable) : route(BillingRoute::admin('billables.index'))">{{ __('billing::admin.common.cancel') }}</flux:button>
            <flux:button type="submit" variant="primary" icon="gift">{{ __('billing::admin.grants.submit') }}</flux:button>
        </div>
    </form>
</div>
