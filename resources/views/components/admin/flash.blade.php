@props([
    'success' => null,
    'error' => null,
    'errorHeading' => 'Something went wrong',
    'info' => null,
])

@if ($success)
    <flux:callout variant="success" icon="check-circle" inline>{{ $success }}</flux:callout>
@endif

@if ($error)
    <flux:callout variant="danger" icon="exclamation-triangle">
        <flux:callout.heading>{{ $errorHeading }}</flux:callout.heading>
        <flux:callout.text>{{ $error }}</flux:callout.text>
    </flux:callout>
@endif

@if ($info)
    <flux:callout variant="secondary" icon="information-circle" inline>{{ $info }}</flux:callout>
@endif

@if (session('status'))
    <flux:callout variant="success" icon="check-circle" inline>{{ session('status') }}</flux:callout>
@endif
