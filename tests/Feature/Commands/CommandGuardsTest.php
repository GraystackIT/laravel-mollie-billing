<?php

declare(strict_types=1);

use Bavix\Wallet\Models\Transaction;
use GraystackIT\MollieBilling\Enums\OssExportStatus;
use GraystackIT\MollieBilling\Models\BillingOssExport;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Testing\TestBillable;

beforeEach(function (): void {
    config()->set('mollie-billing.billable_model', TestBillable::class);
    config()->set('mollie-billing.cleanup.enabled', true);
    config()->set('mollie-billing.cleanup.threshold_minutes', 60);
});

// ── billing:simulate ─────────────────────────────────────────────────────────

it('explains that --billable is required instead of exiting silently', function (): void {
    $this->artisan('billing:simulate', ['flow' => 'trial-expired'])
        ->expectsOutputToContain('--billable')
        ->assertFailed();
});

// ── billing:oss-export ──────────────────────────────────────────────────────

it('rejects a non-numeric year without creating an export row', function (): void {
    $this->artisan('billing:oss-export', ['year' => 'foo'])
        ->expectsOutputToContain('Invalid year')
        ->assertFailed();

    expect(BillingOssExport::query()->count())->toBe(0);
});

it('rejects a negative year before it reaches the unsigned column', function (): void {
    $this->artisan('billing:oss-export', ['year' => '-1'])
        ->expectsOutputToContain('Invalid year')
        ->assertFailed();

    expect(BillingOssExport::query()->count())->toBe(0);
});

it('rejects a year outside the supported range', function (): void {
    $this->artisan('billing:oss-export', ['year' => '1999'])->assertFailed();
    $this->artisan('billing:oss-export', ['year' => '3000'])->assertFailed();

    expect(BillingOssExport::query()->count())->toBe(0);
});

it('accepts a valid year', function (): void {
    $this->artisan('billing:oss-export', ['year' => '2026'])->assertSuccessful();

    $export = BillingOssExport::query()->firstOrFail();
    expect((int) $export->year)->toBe(2026);
    expect($export->status)->toBe(OssExportStatus::Ready);
});

// ── billing:prepare-overage ─────────────────────────────────────────────────

it('fails loudly when no billable model is configured', function (): void {
    config()->set('mollie-billing.billable_model', null);

    $this->artisan('billing:prepare-overage')
        ->expectsOutputToContain('billable_model')
        ->assertFailed();
});

it('reports which billable model it prepared overage for', function (): void {
    // --force skips the confirmation the command grew: the five passes create real
    // recurring payments, cancel Mollie subscriptions and apply scheduled changes, which
    // is not something a bare invocation should start.
    $this->artisan('billing:prepare-overage --force')
        ->expectsOutputToContain(TestBillable::class)
        ->assertSuccessful();
});

it('asks before charging overage for every billable', function (): void {
    $this->artisan('billing:prepare-overage')
        ->expectsConfirmation('This charges overage, cancels past-due subscriptions and applies scheduled changes for every ['.TestBillable::class.']. Continue?', 'no')
        ->expectsOutputToContain('Aborted')
        ->assertSuccessful();
});

// ── billing:cleanup-orphans ─────────────────────────────────────────────────

it('does not report success when cleanup is disabled', function (): void {
    config()->set('mollie-billing.cleanup.enabled', false);

    $this->artisan('billing:cleanup-orphans')
        ->expectsOutputToContain('disabled')
        ->assertFailed();
});

it('fails when the threshold makes the job a no-op', function (): void {
    config()->set('mollie-billing.cleanup.threshold_minutes', 0);

    $this->artisan('billing:cleanup-orphans')
        ->expectsOutputToContain('threshold_minutes')
        ->assertFailed();
});

it('deletes nothing on a dry run', function (): void {
    $orphan = TestBillable::create(['name' => 'Dry', 'email' => 'dry@example.test']);
    $orphan->forceFill(['created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2)])->save();

    $this->artisan('billing:cleanup-orphans', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect(TestBillable::find($orphan->getKey()))->not->toBeNull();
});

it('asks before deleting and keeps everything when declined', function (): void {
    $orphan = TestBillable::create(['name' => 'Ask', 'email' => 'ask@example.test']);
    $orphan->forceFill(['created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2)])->save();

    $this->artisan('billing:cleanup-orphans')
        ->expectsConfirmation('Delete up to 1 orphaned billable(s) and revoke their Mollie mandates?', 'no')
        ->assertSuccessful();

    expect(TestBillable::find($orphan->getKey()))->not->toBeNull();
});

it('deletes with --force and reports the counts', function (): void {
    $orphan = TestBillable::create(['name' => 'Force', 'email' => 'force@example.test']);
    $orphan->forceFill(['created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2)])->save();

    $this->artisan('billing:cleanup-orphans', ['--force' => true])
        ->expectsOutputToContain('1 of 1 candidate(s) removed')
        ->assertSuccessful();

    expect(TestBillable::find($orphan->getKey()))->toBeNull();
});

// ── billing:sync-purchased-balance ──────────────────────────────────────────

it('ignores purchases that predate the last period reset', function (): void {
    /** @var TestBillable $b */
    $b = TestBillable::create(['name' => 'Wallet', 'email' => 'wallet@example.test']);

    $wallet = $b->createWallet(['name' => 'tokens', 'slug' => 'tokens']);

    // Six months ago: bought 5 000 extra units, and used them.
    $wallet->deposit(5000, ['type' => 'tokens', 'reason' => 'one_time_order:pack']);
    $wallet->forceWithdraw(5000, ['type' => 'tokens', 'reason' => 'usage']);

    // Renewal reset, then the plan quota was credited.
    $wallet->forceWithdraw(0, ['type' => 'tokens', 'reason' => 'period_reset']);
    $wallet->deposit(100_000, ['type' => 'tokens', 'reason' => 'credit']);

    $this->artisan('billing:sync-purchased-balance', ['--force' => true])->assertSuccessful();

    // Lifetime summing wrote 5 000 here and resurrected it on every later reset.
    expect(WalletUsageService::getPurchasedBalance($wallet->refresh()))->toBe(0);
});

it('counts purchases made in the current period', function (): void {
    /** @var TestBillable $b */
    $b = TestBillable::create(['name' => 'Wallet2', 'email' => 'wallet2@example.test']);

    $wallet = $b->createWallet(['name' => 'tokens', 'slug' => 'tokens']);

    $wallet->forceWithdraw(0, ['type' => 'tokens', 'reason' => 'period_reset']);
    $wallet->deposit(100_000, ['type' => 'tokens', 'reason' => 'credit']);
    $wallet->deposit(5000, ['type' => 'tokens', 'reason' => 'one_time_order:pack']);

    $this->artisan('billing:sync-purchased-balance', ['--force' => true])->assertSuccessful();

    expect(WalletUsageService::getPurchasedBalance($wallet->refresh()))->toBe(5000);
});

it('corrects a stale purchased_balance down to zero', function (): void {
    /** @var TestBillable $b */
    $b = TestBillable::create(['name' => 'Wallet3', 'email' => 'wallet3@example.test']);

    $wallet = $b->createWallet(['name' => 'tokens', 'slug' => 'tokens']);
    $wallet->deposit(1000, ['type' => 'tokens', 'reason' => 'credit']);

    // A leftover value with no purchase transactions behind it — previously skipped.
    WalletUsageService::setPurchasedBalance($wallet, 4200);

    $this->artisan('billing:sync-purchased-balance', ['--force' => true])->assertSuccessful();

    expect(WalletUsageService::getPurchasedBalance($wallet->refresh()))->toBe(0);
});

it('writes nothing on a dry run', function (): void {
    /** @var TestBillable $b */
    $b = TestBillable::create(['name' => 'Wallet4', 'email' => 'wallet4@example.test']);

    $wallet = $b->createWallet(['name' => 'tokens', 'slug' => 'tokens']);
    $wallet->deposit(1000, ['type' => 'tokens', 'reason' => 'credit']);
    WalletUsageService::setPurchasedBalance($wallet, 777);

    $this->artisan('billing:sync-purchased-balance', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect(WalletUsageService::getPurchasedBalance($wallet->refresh()))->toBe(777);
});

it('asks before overwriting purchased balances', function (): void {
    /** @var TestBillable $b */
    $b = TestBillable::create(['name' => 'Wallet5', 'email' => 'wallet5@example.test']);

    $wallet = $b->createWallet(['name' => 'tokens', 'slug' => 'tokens']);
    $wallet->deposit(1000, ['type' => 'tokens', 'reason' => 'credit']);
    WalletUsageService::setPurchasedBalance($wallet, 555);

    $this->artisan('billing:sync-purchased-balance')
        ->expectsConfirmation('Overwrite purchased_balance on 1 wallet(s)?', 'no')
        ->assertSuccessful();

    expect(WalletUsageService::getPurchasedBalance($wallet->refresh()))->toBe(555);
    expect(Transaction::query()->count())->toBeGreaterThan(0);
});
