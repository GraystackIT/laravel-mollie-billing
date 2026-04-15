@php

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Services\Vat\CountryMatchService;
use Livewire\Component;

new class extends Component {
    public ?string $flash = null;

    public function resolve(int $id, CountryMatchService $service): void
    {
        $m = BillingCountryMismatch::find($id);
        if (! $m) return;
        $b = $m->billable()->first();
        if ($b) { $service->resolve($b, $m, auth()->user()); $this->flash = 'Mismatch resolved.'; }
    }

    public function with(): array
    {
        return ['rows' => BillingCountryMismatch::query()
            ->where('status', CountryMismatchStatus::Pending)
            ->latest()
            ->paginate(20)];
    }
};

@endphp

<div class="p-6 space-y-4">
    <flux:heading size="xl">Country mismatches</flux:heading>
    @if ($flash)<div class="p-3 rounded bg-green-50 border border-green-200 text-sm">{{ $flash }}</div>@endif
    <table class="w-full border text-sm">
        <thead class="bg-zinc-50 text-left"><tr><th class="p-2">Billable</th><th class="p-2">User</th><th class="p-2">IP</th><th class="p-2">Payment</th><th></th></tr></thead>
        <tbody>
            @foreach ($rows as $r)
                <tr class="border-t">
                    <td class="p-2">{{ class_basename($r->billable_type) }}#{{ $r->billable_id }}</td>
                    <td class="p-2">{{ $r->tax_country_user }}</td>
                    <td class="p-2">{{ $r->tax_country_ip ?? '—' }}</td>
                    <td class="p-2">{{ $r->tax_country_payment ?? '—' }}</td>
                    <td class="p-2"><button wire:click="resolve({{ $r->id }})" class="px-2 py-1 border rounded text-xs">Resolve</button></td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <div>{{ $rows->links() }}</div>
</div>
