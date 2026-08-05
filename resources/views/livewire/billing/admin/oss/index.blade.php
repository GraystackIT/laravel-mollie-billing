<?php

use GraystackIT\MollieBilling\Enums\OssExportStatus;
use GraystackIT\MollieBilling\Jobs\GenerateOssExportJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingOssExport;
use GraystackIT\MollieBilling\Support\BillingRoute;
use Illuminate\Support\Carbon;
use Livewire\Component;

new class extends Component {
    public ?string $flash = null;
    public ?string $error = null;

    public function generate(int $year): void
    {
        $this->flash = $this->error = null;

        if ($year < 2000 || $year > 2999) {
            $this->error = __('billing::admin.oss.invalid_year');
            return;
        }

        // Reject if there's already a pending export for this year — the table
        // is auto-refreshing via wire:poll, so the user sees the spinner spin.
        $hasPending = BillingOssExport::query()
            ->where('year', $year)
            ->whereIn('status', [OssExportStatus::Queued, OssExportStatus::Processing])
            ->exists();

        if ($hasPending) {
            $this->error = __('billing::admin.oss.already_running');
            return;
        }

        $userId = auth()->user()?->getAuthIdentifier();

        $export = BillingOssExport::create([
            'year' => $year,
            'status' => OssExportStatus::Queued,
            'requested_by_user_id' => $userId,
        ]);

        GenerateOssExportJob::dispatch($export->id);

        $this->flash = __('billing::admin.oss.queued', ['year' => $year]);
    }

    public function with(): array
    {
        $oldest = BillingInvoice::query()->min('created_at');
        $newest = BillingInvoice::query()->max('created_at');

        $years = [];
        if ($oldest && $newest) {
            $from = (int) Carbon::parse((string) $oldest)->setTimezone('UTC')->format('Y');
            $to = (int) Carbon::parse((string) $newest)->setTimezone('UTC')->format('Y');
            for ($y = $to; $y >= $from; $y--) {
                $years[] = $y;
            }
        }

        // Latest export per year (any status). Used to render status/download in
        // the year row. Also fetch all historical exports for the audit log.
        $latestByYear = [];
        if ($years !== []) {
            $latestByYear = BillingOssExport::query()
                ->whereIn('year', $years)
                ->orderByDesc('id')
                ->get()
                ->groupBy('year')
                ->map(fn ($group) => $group->first())
                ->all();
        }

        $history = BillingOssExport::query()
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $hasPending = BillingOssExport::query()
            ->whereIn('status', [OssExportStatus::Queued, OssExportStatus::Processing])
            ->exists();

        return [
            'years' => $years,
            'latestByYear' => $latestByYear,
            'history' => $history,
            'hasPending' => $hasPending,
        ];
    }
};

?>

@php
    $formatBytes = function (?int $bytes): string {
        if ($bytes === null) {
            return '—';
        }
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024;
        $unit = 'KB';
        foreach ($units as $candidate) {
            $unit = $candidate;
            if ($value < 1024) {
                break;
            }
            $value /= 1024;
        }
        return number_format($value, $value < 10 ? 1 : 0).' '.$unit;
    };
@endphp

<div class="space-y-6" @if ($hasPending) wire:poll.5s @endif>
    <x-mollie-billing::admin.page-header
        :title="__('billing::admin.oss.title')"
        :subtitle="__('billing::admin.oss.subtitle')"
    />

    <x-mollie-billing::admin.flash :success="$flash" :error="$error" />

    @if (empty($years))
        <flux:card>
            <x-mollie-billing::admin.empty
                icon="document-arrow-down"
                :title="__('billing::admin.oss.empty_title')"
                :description="__('billing::admin.oss.empty_description')"
            />
        </flux:card>
    @else
        <flux:card class="p-0! sm:px-6! sm:py-2!">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('billing::admin.oss.column.year') }}</flux:table.column>
                    <flux:table.column>{{ __('billing::admin.oss.column.status') }}</flux:table.column>
                    <flux:table.column>{{ __('billing::admin.oss.column.generated') }}</flux:table.column>
                    <flux:table.column>{{ __('billing::admin.oss.column.size') }}</flux:table.column>
                    <flux:table.column align="end"></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($years as $year)
                        @php($latest = $latestByYear[$year] ?? null)
                        <flux:table.row :key="$year">
                            <flux:table.cell variant="strong" class="font-mono">{{ $year }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($latest)
                                    <flux:badge color="{{ $latest->status->color() }}" size="sm">
                                        {{ $latest->status->label() }}
                                    </flux:badge>
                                    @if ($latest->status === OssExportStatus::Failed && $latest->failure_reason)
                                        <div class="mt-1 text-xs text-red-600 dark:text-red-400">
                                            {{ \Illuminate\Support\Str::limit($latest->failure_reason, 120) }}
                                        </div>
                                    @endif
                                @else
                                    <span class="text-zinc-400">—</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="text-sm text-zinc-500">
                                @if ($latest?->completed_at)
                                    {{ $latest->completed_at->format('Y-m-d H:i') }} UTC
                                @elseif ($latest)
                                    <span class="text-zinc-400">{{ $latest->created_at->format('Y-m-d H:i') }} UTC</span>
                                @else
                                    <span class="text-zinc-400">{{ __('billing::admin.oss.never') }}</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="text-sm text-zinc-500 font-mono">
                                {{ $latest?->isReady() ? $formatBytes($latest->bytes) : '—' }}
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                <div class="flex items-center justify-end gap-2">
                                    @if ($latest?->isReady())
                                        <flux:button
                                            size="xs"
                                            icon="arrow-down-tray"
                                            href="{{ route(BillingRoute::admin('oss.download'), $latest) }}"
                                        >
                                            {{ __('billing::admin.common.download') }}
                                        </flux:button>
                                    @endif

                                    @if ($latest?->status?->isPending())
                                        <flux:button size="xs" icon="arrow-path" disabled>
                                            {{ __('billing::admin.oss.generating') }}
                                        </flux:button>
                                    @else
                                        <flux:button
                                            size="xs"
                                            variant="ghost"
                                            icon="arrow-path"
                                            wire:click="generate({{ $year }})"
                                        >
                                            {{ $latest ? __('billing::admin.oss.regenerate') : __('billing::admin.oss.generate') }}
                                        </flux:button>
                                    @endif
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @endif

    @if ($history->isNotEmpty())
        <div>
            <flux:heading size="lg" class="mb-3">{{ __('billing::admin.oss.history') }}</flux:heading>
            <flux:card class="p-0! sm:px-6! sm:py-2!">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('billing::admin.oss.column.year') }}</flux:table.column>
                        <flux:table.column>{{ __('billing::admin.oss.column.status') }}</flux:table.column>
                        <flux:table.column>{{ __('billing::admin.oss.column.requested') }}</flux:table.column>
                        <flux:table.column>{{ __('billing::admin.oss.column.completed') }}</flux:table.column>
                        <flux:table.column>{{ __('billing::admin.oss.column.rows') }}</flux:table.column>
                        <flux:table.column>{{ __('billing::admin.oss.column.size') }}</flux:table.column>
                        <flux:table.column align="end"></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($history as $entry)
                            <flux:table.row :key="$entry->id">
                                <flux:table.cell class="font-mono">{{ $entry->year }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge color="{{ $entry->status->color() }}" size="sm">
                                        {{ $entry->status->label() }}
                                    </flux:badge>
                                </flux:table.cell>
                                <flux:table.cell class="text-sm text-zinc-500">
                                    {{ $entry->created_at->format('Y-m-d H:i') }} UTC
                                </flux:table.cell>
                                <flux:table.cell class="text-sm text-zinc-500">
                                    {{ $entry->completed_at?->format('Y-m-d H:i') ?? '—' }}
                                    @if ($entry->completed_at)
                                        <span class="text-xs text-zinc-400">UTC</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="text-sm font-mono text-zinc-500">
                                    {{ $entry->rows_count ?? '—' }}
                                </flux:table.cell>
                                <flux:table.cell class="text-sm font-mono text-zinc-500">
                                    {{ $entry->isReady() ? $formatBytes($entry->bytes) : '—' }}
                                </flux:table.cell>
                                <flux:table.cell align="end">
                                    @if ($entry->isReady())
                                        <flux:button
                                            size="xs"
                                            variant="ghost"
                                            icon="arrow-down-tray"
                                            href="{{ route(BillingRoute::admin('oss.download'), $entry) }}"
                                        >
                                            {{ __('billing::admin.common.download') }}
                                        </flux:button>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </flux:card>
        </div>
    @endif
</div>
