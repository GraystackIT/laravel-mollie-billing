<div class="flex flex-col gap-5 mt-4">
    <flux:input wire:model.live.debounce.500ms="company_name" :label="__('billing::checkout.company_name')" type="text" required autofocus />
    <flux:input wire:model.live.debounce.500ms="billing_street" :label="__('billing::checkout.street')" type="text" required />
    <div class="error-reserve grid gap-5 sm:grid-cols-[1fr_2fr]">
        <flux:input wire:model.live.debounce.500ms="billing_postal_code" :label="__('billing::checkout.postal_code')" type="text" required />
        <flux:input wire:model.live.debounce.500ms="billing_city" :label="__('billing::checkout.city')" type="text" required />
    </div>
    <div class="error-reserve grid gap-5 sm:grid-cols-2">
        <flux:select wire:model.live="billing_country" :label="__('billing::checkout.country')" required>
            @foreach ($this->countries() as $iso => $name)
                <flux:select.option value="{{ $iso }}">{{ $name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:field>
            <flux:label>{{ __('billing::checkout.vat_number') }}</flux:label>
            <flux:input.group>
                <flux:input wire:model.live.debounce.500ms="vat_number" type="text" placeholder="ATU12345678" />
                @if ($vatNumberValid === true)
                    <flux:input.group.suffix class="text-emerald-700 dark:text-emerald-400">
                        <flux:icon.check-circle class="size-4" />
                    </flux:input.group.suffix>
                @elseif ($vatNumberValid === false)
                    <flux:input.group.suffix class="text-red-600 dark:text-red-400">
                        <flux:icon.x-circle class="size-4" />
                    </flux:input.group.suffix>
                @endif
            </flux:input.group>
            <flux:error name="vat_number" />
        </flux:field>
    </div>
</div>

<div class="flex items-center justify-between pt-2">
    @if ($customStepCount > 0)
        <flux:button wire:click="back" variant="ghost" icon="arrow-left">{{ __('billing::checkout.back') }}</flux:button>
    @else
        <div></div>
    @endif
    <flux:button wire:click="next" variant="primary" icon:trailing="arrow-right">
        {{ __('billing::checkout.continue') }}
    </flux:button>
</div>
