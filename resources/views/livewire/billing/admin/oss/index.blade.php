<?php

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

?>

<div class="p-6 space-y-4 max-w-md">
    <flux:heading size="xl">OSS protocol</flux:heading>

    @if ($flash)
        <flux:callout variant="success" icon="check-circle">{{ $flash }}</flux:callout>
    @endif

    @if (empty($years))
        <flux:text class="text-zinc-500">No invoices yet.</flux:text>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Year</flux:table.column>
                <flux:table.column align="end"></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($years as $year)
                    <flux:table.row :key="$year">
                        <flux:table.cell variant="strong" class="font-mono">{{ $year }}</flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button size="xs" wire:click="export({{ $year }})">Export CSV</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</div>
