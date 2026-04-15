<?php

use GraystackIT\MollieBilling\Jobs\RetryUsageOverageChargeJob;
use Livewire\Component;

new class extends Component {
    public ?string $flash = null;

    public function retry(mixed $id): void
    {
        $class = config('mollie-billing.billable_model');
        $b = $class?->find($id);
        if ($b) {
            RetryUsageOverageChargeJob::dispatch($class, $b->getKey());
            $this->flash = "Retry dispatched for {$b->name}.";
        }
    }

    public function with(): array
    {
        $class = config('mollie-billing.billable_model');
        $q = $class ? $class::query()->where('subscription_status', 'past_due')->latest() : null;
        return ['billables' => $q ? $q->paginate(20) : null];
    }
};

?>

<div class="p-6 space-y-4">
    <flux:heading size="xl">Past due</flux:heading>
    @if ($flash)<div class="p-3 rounded bg-green-50 border border-green-200 text-sm">{{ $flash }}</div>@endif
    @if (! $billables)
        <p class="text-zinc-500">No billable model configured.</p>
    @else
        <table class="w-full text-sm border">
            <thead class="bg-zinc-50 text-left"><tr><th class="p-2">Billable</th><th class="p-2">Since</th><th class="p-2">Last failure</th><th></th></tr></thead>
            <tbody>
                @foreach ($billables as $b)
                    <tr class="border-t">
                        <td class="p-2">{{ $b->name }} <span class="text-xs text-zinc-500">{{ $b->email }}</span></td>
                        <td class="p-2">{{ $b->updated_at?->format('Y-m-d') }}</td>
                        <td class="p-2 text-xs">{{ data_get($b->getBillingSubscriptionMeta(), 'payment_failure.reason', '—') }}</td>
                        <td class="p-2"><button wire:click="retry({{ $b->getKey() }})" class="px-2 py-1 border rounded text-xs">Retry overage</button></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div>{{ $billables->links() }}</div>
    @endif
</div>
