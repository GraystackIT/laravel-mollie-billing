{{-- Checkout matrix: the workbench's own checkout step (see WorkbenchServiceProvider). --}}
<div class="mt-4 flex flex-col gap-3">
    <label class="flex items-center gap-3 text-sm">
        <input type="checkbox" wire:model.live="customData.terms" dusk="wb-terms">
        <span>I accept the workbench terms.</span>
    </label>
    @error('customData.terms')
        <p class="text-sm text-red-600" dusk="wb-terms-error">{{ $message }}</p>
    @enderror
</div>
