@php

use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Vat\OssProtocolService;
use Livewire\Component;

new class extends Component {
    public ?string $flash = null;

    public function export(int $year, OssProtocolService $service): void
    {
        try {
            $path = $service->export($year);
            $this->flash = "CSV written to {$path}";
        } catch (\Throwable $e) {
            $this->flash = 'Error: '.$e->getMessage();
        }
    }

    public function with(): array
    {
        // Driver-agnostic: pull min/max created_at and enumerate years in PHP.
        $oldest = BillingInvoice::query()->min('created_at');
        $newest = BillingInvoice::query()->max('created_at');

        $years = [];
        if ($oldest && $newest) {
            $from = (int) date('Y', strtotime((string) $oldest));
            $to = (int) date('Y', strtotime((string) $newest));
            for ($y = $to; $y >= $from; $y--) {
                $years[] = $y;
            }
        }

        return ['years' => $years];
    }
};

@endphp

<div class="p-6 space-y-4 max-w-md">
    <flux:heading size="xl">OSS protocol</flux:heading>
    @if ($flash)<div class="p-3 rounded bg-green-50 border border-green-200 text-sm">{{ $flash }}</div>@endif
    <ul class="space-y-2">
        @forelse ($years as $year)
            <li class="flex items-center justify-between p-3 border rounded">
                <span class="font-mono">{{ $year }}</span>
                <button wire:click="export({{ $year }})" class="px-3 py-1 border rounded text-sm">Export CSV</button>
            </li>
        @empty
            <li class="text-zinc-500">No invoices yet.</li>
        @endforelse
    </ul>
</div>
