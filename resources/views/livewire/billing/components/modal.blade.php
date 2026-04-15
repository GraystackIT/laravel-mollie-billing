@if(\GraystackIT\MollieBilling\Support\FluxPro::isInstalled())
    <flux:modal {{ $attributes }}>{{ $slot }}</flux:modal>
@else
    <div x-data="{ open: false }" x-show="open" class="fixed inset-0 bg-black/40 flex items-center justify-center" {{ $attributes }}>
        <div class="bg-white rounded-2xl p-6 max-w-md w-full">{{ $slot }}</div>
    </div>
@endif
