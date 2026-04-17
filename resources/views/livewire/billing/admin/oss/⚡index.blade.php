<?php

use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Vat\OssProtocolService;
use Livewire\Component;

new class extends Component {
    public ?string $flash = null;
    public ?string $error = null;

    public function export(int $year, OssProtocolService $service): void
    {
        $this->flash = $this->error = null;
        try {
            $path = $service->export($year);
            $this->flash = "CSV written to {$path}";
        } catch (\Throwable $e) {
            report($e);
            $this->error = 'An unexpected error occurred.';
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

<div class="space-y-6">
    <x-mollie-billing::admin.page-header
        title="OSS protocol"
        subtitle="Export the one-stop-shop VAT protocol as a CSV file, by year."
    />

    <x-mollie-billing::admin.flash :success="$flash" :error="$error" />

    @if (empty($years))
        <flux:card>
            <x-mollie-billing::admin.empty
                icon="document-arrow-down"
                title="No invoices yet"
                description="Once invoices exist, the applicable years will appear here for export."
            />
        </flux:card>
    @else
        <flux:card class="p-0! max-w-lg">
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
                                <flux:button size="xs" icon="arrow-down-tray" wire:click="export({{ $year }})">Export CSV</flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @endif
</div>
