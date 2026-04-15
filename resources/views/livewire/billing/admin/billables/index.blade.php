@php

use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $statusFilter = '';

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingStatusFilter(): void { $this->resetPage(); }

    public function with(): array
    {
        $class = config('mollie-billing.billable_model');
        $query = $class ? $class::query() : null;

        if ($query && $this->search !== '') {
            $query->where(function ($q): void {
                $q->where('email', 'like', '%'.$this->search.'%')
                  ->orWhere('name', 'like', '%'.$this->search.'%');
            });
        }

        if ($query && $this->statusFilter !== '') {
            $query->where('subscription_status', $this->statusFilter);
        }

        return [
            'billables' => $query ? $query->latest()->paginate(20) : null,
        ];
    }
};

@endphp

<div class="p-6 space-y-4">
    <flux:heading size="xl">Billables</flux:heading>
    <div class="flex gap-2">
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Name or email" class="px-3 py-1.5 border rounded w-full max-w-md">
        <select wire:model.live="statusFilter" class="px-3 py-1.5 border rounded">
            <option value="">All</option>
            <option value="active">Active</option>
            <option value="trial">Trial</option>
            <option value="past_due">Past due</option>
            <option value="cancelled">Cancelled</option>
            <option value="expired">Expired</option>
        </select>
    </div>
    @if (! $billables)
        <p class="text-zinc-500">No billable model configured.</p>
    @else
        <table class="w-full text-sm border">
            <thead class="bg-zinc-50 text-left"><tr><th class="p-2">Name</th><th class="p-2">Email</th><th class="p-2">Plan</th><th class="p-2">Status</th><th></th></tr></thead>
            <tbody>
                @forelse ($billables as $b)
                    <tr class="border-t">
                        <td class="p-2">{{ $b->name }}</td>
                        <td class="p-2">{{ $b->email }}</td>
                        <td class="p-2">{{ $b->subscription_plan_code ?? '—' }}</td>
                        <td class="p-2">{{ is_object($b->subscription_status) ? $b->subscription_status->value : $b->subscription_status }}</td>
                        <td class="p-2"><a href="{{ route('billing.admin.billables.show', $b) }}" class="text-indigo-600">View</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-4 text-center text-zinc-500">No billables yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div>{{ $billables->links() }}</div>
    @endif
</div>
