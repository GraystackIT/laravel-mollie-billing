{{--
    A form control with a label, an (i) that explains what the option really does, and
    an optional one-line hint underneath.

    Flux renders a label itself when a control is given `label="…"`, but offers no place
    to put anything next to it. Controls inside this component are therefore passed
    WITHOUT a label; the label lives here.
--}}
@props(['label', 'info' => null, 'description' => null, 'required' => false])

<flux:field {{ $attributes }}>
    <div class="flex items-center gap-1">
        <flux:label :badge="$required ? __('billing::admin.common.required') : null">{{ $label }}</flux:label>
        @if ($info)
            <x-mollie-billing::admin.info :text="$info" />
        @endif
    </div>

    {{ $slot }}

    @if ($description)
        <flux:description>{{ $description }}</flux:description>
    @endif
</flux:field>
