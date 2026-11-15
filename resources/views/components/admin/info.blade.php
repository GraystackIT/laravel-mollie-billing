{{--
    The (i) next to an option: what the option actually does, on hover or click.

    `toggleable` because these explanations are sentences, not labels — an operator on a
    touch device or one who wants to read it properly can pin it open. The text is passed
    in already translated; it is escaped here.
--}}
@props(['text' => null])

<flux:tooltip toggleable position="top">
    <flux:button
        type="button"
        icon="information-circle"
        size="xs"
        variant="subtle"
        inset="top bottom"
        :aria-label="__('billing::admin.common.more_info')"
    />
    {{-- Inline, not utility classes: these explanations run to several sentences, and a
         tooltip that does not wrap is one line running off the screen. Whether the
         host app's Tailwind build happens to include `max-w-xs` must not decide that. --}}
    <flux:tooltip.content style="max-width: 22rem; white-space: normal; text-align: left; line-height: 1.5;">
        {{ $text ?? $slot }}
    </flux:tooltip.content>
</flux:tooltip>
