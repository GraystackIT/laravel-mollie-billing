@props([
    'success' => null,
    'error' => null,
    'info' => null,
])

@if ($success)
    <flux:callout variant="success" icon="check-circle" inline>{{ $success }}</flux:callout>
@endif

@if ($error)
    <flux:callout variant="danger" icon="exclamation-triangle" inline>{{ $error }}</flux:callout>
@endif

@if ($info)
    <flux:callout variant="secondary" icon="information-circle" inline>{{ $info }}</flux:callout>
@endif

@if (session('status'))
    <flux:callout variant="success" icon="check-circle" inline>{{ session('status') }}</flux:callout>
@endif
