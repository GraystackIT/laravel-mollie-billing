<?php

use GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange;
use Livewire\Component;

new class extends Component {
    public ?string $flash = null;

    public function cancel(mixed $id, ScheduleSubscriptionChange $service): void
    {
        $class = config('mollie-billing.billable_model');
        $b = $class?->find($id);
        if ($b) { $service->cancel($b); $this->flash = 'Scheduled change cancelled.'; }
    }

    public function applyNow(mixed $id, ScheduleSubscriptionChange $service): void
    {
        $class = config('mollie-billing.billable_model');
        $b = $class?->find($id);
        if ($b) {
            try { $service->apply($b); $this->flash = 'Scheduled change applied.'; }
            catch (\Throwable $e) { $this->flash = 'Error: '.$e->getMessage(); }
        }
    }

    public function with(): array
    {
        $class = config('mollie-billing.billable_model');
        $query = $class ? $class::query()->whereNotNull('scheduled_change_at')->orderBy('scheduled_change_at') : null;
        return ['billables' => $query ? $query->paginate(20) : null];
    }
};

?>

<div class="p-6 space-y-4">
    <flux:heading size="xl">Scheduled changes</flux:heading>
    @if ($flash)<div class="p-3 rounded bg-green-50 border border-green-200 text-sm">{{ $flash }}</div>@endif
    @if (! $billables)
        <p class="text-zinc-500">No billable model configured.</p>
    @else
        <table class="w-full text-sm border">
            <thead class="bg-zinc-50 text-left"><tr><th class="p-2">Billable</th><th class="p-2">At</th><th class="p-2">Change</th><th></th></tr></thead>
            <tbody>
                @foreach ($billables as $b)
                    <tr class="border-t">
                        <td class="p-2">{{ $b->name }}</td>
                        <td class="p-2">{{ $b->scheduled_change_at?->format('Y-m-d H:i') }}</td>
                        <td class="p-2 font-mono text-xs">{{ json_encode($b->getBillingSubscriptionMeta()['scheduled_change'] ?? []) }}</td>
                        <td class="p-2 space-x-2">
                            <button wire:click="applyNow({{ $b->getKey() }})" class="px-2 py-1 border rounded text-xs">Apply now</button>
                            <button wire:click="cancel({{ $b->getKey() }})" class="px-2 py-1 border rounded text-xs text-red-600">Cancel</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div>{{ $billables->links() }}</div>
    @endif
</div>
