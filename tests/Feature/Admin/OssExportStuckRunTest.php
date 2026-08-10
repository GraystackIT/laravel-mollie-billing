<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\OssExportStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Jobs\GenerateOssExportJob;
use GraystackIT\MollieBilling\Models\BillingOssExport;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

// A row that says `processing` says so because a process wrote it and then stopped
// existing — a deploy mid-run, the OOM killer, Ctrl-C on `billing:oss-export`. Nothing
// unwinds it: `failed()` never runs when the worker is killed outright, and the CLI
// writes `processing` before it starts. The screen treats any such row as "a run is in
// flight" and refuses to start another, for every year at once — so one dead run in 2024
// meant no OSS report could ever be produced again, in the one part of the package whose
// output goes to a tax authority on a deadline.

beforeEach(function (): void {
    MollieBilling::authUsing(fn (): bool => true);
    Queue::fake();
});

it('lets a new export start once an abandoned run is past the stale cutoff', function (): void {
    BillingOssExport::create([
        'year' => 2024,
        'status' => OssExportStatus::Processing,
        // Six hours: far past any cutoff a ten-minute job could justify.
        'created_at' => BillingTime::nowUtc()->subHours(6),
        'updated_at' => BillingTime::nowUtc()->subHours(6),
    ]);

    Livewire::test('mollie-billing::admin.oss')
        ->call('generate', 2025)
        ->assertSet('error', null);

    Queue::assertPushed(GenerateOssExportJob::class);

    expect(BillingOssExport::query()->where('year', 2025)->where('status', OssExportStatus::Queued)->exists())->toBeTrue()
        // And the dead run is recorded as what it was, so the audit log does not keep a
        // spinner running forever.
        ->and(BillingOssExport::query()->where('year', 2024)->first()->status)->toBe(OssExportStatus::Failed);
});

it('still refuses a second run while the first one is genuinely in flight', function (): void {
    BillingOssExport::create([
        'year' => 2025,
        'status' => OssExportStatus::Processing,
        'created_at' => BillingTime::nowUtc()->subMinutes(2),
        'updated_at' => BillingTime::nowUtc()->subMinutes(2),
    ]);

    Livewire::test('mollie-billing::admin.oss')
        ->call('generate', 2025)
        ->assertSet('error', __('billing::admin.oss.already_running'));

    Queue::assertNothingPushed();
});
