@if(\GraystackIT\MollieBilling\Support\FluxPro::isInstalled())
    <flux:table {{ $attributes }}>{{ $slot }}</flux:table>
@else
    <table class="w-full border-collapse" {{ $attributes }}>{{ $slot }}</table>
@endif
