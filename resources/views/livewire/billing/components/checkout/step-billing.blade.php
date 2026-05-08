<div class="flex flex-col gap-5 mt-4">
    <flux:input wire:model.live.debounce.500ms="company_name" :label="__('billing::checkout.company_name')" type="text" required autofocus />
    <flux:input wire:model.live.debounce.500ms="billing_street" :label="__('billing::checkout.street')" type="text" required />
    <div class="error-reserve grid gap-5 sm:grid-cols-[1fr_2fr]">
        <flux:input wire:model.live.debounce.500ms="billing_postal_code" :label="__('billing::checkout.postal_code')" type="text" required />
        <flux:input wire:model.live.debounce.500ms="billing_city" :label="__('billing::checkout.city')" type="text" required />
    </div>
    <div class="error-reserve grid gap-5 sm:grid-cols-2">
        <flux:select wire:model.live="billing_country" :label="__('billing::checkout.country')" required class="min-w-0">
            @foreach ($this->countries() as $iso => $name)
                <flux:select.option value="{{ $iso }}">{{ $name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:field class="block w-full min-w-0">
            <flux:label>{{ __('billing::checkout.vat_number') }}</flux:label>
            <flux:input.group class="w-full">
                <flux:input wire:model.live.debounce.500ms="vat_number" type="text" placeholder="ATU12345678" class="min-w-0 grow" />

                {{-- Suffix is ALWAYS rendered so the input.group's border-welding stays
                     consistent (otherwise the input would lose its right border + radius
                     when the suffix appears/disappears, making the empty state look broken).
                     The icon inside swaps based on validation state. --}}
                <flux:input.group.suffix>
                    <span wire:loading.flex wire:target="vat_number,billing_country" class="text-zinc-500 dark:text-zinc-400">
                        <flux:icon.loading class="size-4" />
                    </span>
                    <span wire:loading.remove wire:target="vat_number,billing_country" class="flex items-center">
                        @if ($vatNumberValid === true)
                            <flux:icon.check-circle class="size-4 text-emerald-700 dark:text-emerald-400" />
                        @elseif ($vatNumberValid === false)
                            <flux:icon.x-circle class="size-4 text-red-600 dark:text-red-400" />
                        @else
                            {{-- Neutral idle state: keep the suffix slot occupied with a hairline-grey info dot --}}
                            <flux:icon.information-circle class="size-4 text-zinc-300 dark:text-zinc-600" />
                        @endif
                    </span>
                </flux:input.group.suffix>
            </flux:input.group>
            <div wire:loading.remove wire:target="vat_number,billing_country">
                <flux:error name="vat_number" />
            </div>
        </flux:field>
    </div>
</div>

<div class="flex items-center justify-between pt-2">
    @if ($customStepCount > 0)
        <flux:button wire:click="back" variant="ghost" icon="arrow-left">{{ __('billing::checkout.back') }}</flux:button>
    @else
        <div></div>
    @endif
    <flux:button
        wire:click="next"
        wire:loading.attr="disabled"
        wire:target="next,vat_number,billing_country"
        variant="primary"
        icon:trailing="arrow-right"
    >
        {{ __('billing::checkout.continue') }}
    </flux:button>
</div>
