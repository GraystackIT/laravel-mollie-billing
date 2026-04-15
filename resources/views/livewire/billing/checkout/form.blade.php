@php

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use Livewire\Component;

new class extends Component {
    public ?Billable $billable = null;
    public string $street = '';
    public string $city = '';
    public string $postalCode = '';
    public string $country = 'DE';
    public string $vatNumber = '';
    public string $couponCode = '';
    public array $draft = [];
    public array $preview = [];
    public ?string $flash = null;

    public function mount(): void
    {
        $this->billable = MollieBilling::resolveBillable(request());
        $this->draft = session('billing.checkout_draft', []);
        $this->couponCode = session('billing.auto_apply_coupon', '');
    }

    public function refreshPreview(PreviewService $service): void
    {
        if (! $this->billable || empty($this->draft)) return;
        $request = array_merge($this->draft, [
            'coupon_code' => $this->couponCode ?: null,
        ]);
        $this->preview = $service->previewUpdate($this->billable, $request);
    }

    public function applyCoupon(CouponService $service): void
    {
        try {
            $ctx = [
                'planCode' => $this->draft['plan_code'] ?? null,
                'interval' => $this->draft['interval'] ?? null,
                'addonCodes' => $this->draft['addon_codes'] ?? [],
                'orderAmountNet' => 0,
                'existingCouponIds' => [],
            ];
            $service->validate($this->couponCode, $this->billable, $ctx);
            session()->put('billing.auto_apply_coupon', $this->couponCode);
            $this->flash = 'Coupon applied.';
            $this->refreshPreview(app(PreviewService::class));
        } catch (\Throwable $e) {
            $this->flash = 'Coupon error: '.$e->getMessage();
        }
    }

    public function submit(StartSubscriptionCheckout $service): void
    {
        $b = $this->billable;
        if (! $b) return;

        $b->forceFill([
            'billing_street' => $this->street,
            'billing_city' => $this->city,
            'billing_postal_code' => $this->postalCode,
            'billing_country' => $this->country,
            'vat_number' => $this->vatNumber ?: null,
        ])->save();

        try {
            $result = $service->handle($b, array_merge($this->draft, [
                'coupon_code' => $this->couponCode ?: null,
                'amount_gross' => (int) ($this->preview['grossTotal'] ?? 0),
            ]));
            if (! empty($result['checkout_url'])) {
                $this->redirect($result['checkout_url']);
            }
        } catch (\Throwable $e) {
            $this->flash = 'Error: '.$e->getMessage();
        }
    }
};

@endphp

<div class="p-6 space-y-4 max-w-xl">
    <h1 class="text-xl font-semibold">Billing details</h1>
    @if ($flash)<div class="p-3 rounded bg-amber-50 border border-amber-200 text-sm">{{ $flash }}</div>@endif

    <form wire:submit="submit" class="space-y-3">
        <input wire:model.live.debounce.500ms="street" placeholder="Street" class="border rounded px-2 py-1.5 w-full">
        <div class="grid grid-cols-2 gap-3">
            <input wire:model.live.debounce.500ms="postalCode" placeholder="Postal code" class="border rounded px-2 py-1.5 w-full">
            <input wire:model.live.debounce.500ms="city" placeholder="City" class="border rounded px-2 py-1.5 w-full">
        </div>
        <input wire:model.live="country" placeholder="Country (ISO-2)" maxlength="2" class="border rounded px-2 py-1.5 w-full">
        <input wire:model="vatNumber" placeholder="VAT number (optional)" class="border rounded px-2 py-1.5 w-full">

        <div class="flex gap-2">
            <input wire:model="couponCode" placeholder="Coupon code" class="border rounded px-2 py-1.5 flex-1">
            <button type="button" wire:click="applyCoupon" class="px-3 py-1.5 border rounded">Apply</button>
        </div>

        @if (! empty($preview))
            <pre class="text-xs bg-zinc-50 p-2 rounded overflow-x-auto">{{ json_encode($preview, JSON_PRETTY_PRINT) }}</pre>
        @endif

        <button class="px-4 py-2 rounded bg-indigo-600 text-white">Proceed to payment</button>
    </form>
</div>
