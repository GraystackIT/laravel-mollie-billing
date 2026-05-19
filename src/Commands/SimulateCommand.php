<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Commands;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Testing\LifecycleSimulator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

class SimulateCommand extends Command
{
    protected $signature = 'billing:simulate
        {flow? : Single flow to run (skips the interactive runner)}
        {--billable= : Billable id for the chosen flow}
        {--usage-type= : Usage type for the overage-charge flow}
        {--withdraw= : Units to withdraw before the overage-charge flow}
        {--plan= : Plan code to schedule for the apply-scheduled-change flow}
        {--gross= : Override gross amount in major units for the renewal flow}
        {--yes : Skip all confirmation prompts}';

    protected $description = 'Simulate subscription lifecycle flows on a non-production system.';

    public function handle(Application $app, LifecycleSimulator $sim): int
    {
        if ($app->environment('production')) {
            $this->error('billing:simulate is disabled in production.');
            return self::FAILURE;
        }

        $flow = (string) ($this->argument('flow') ?? '');
        if ($flow !== '') {
            return $this->runSingleFlow($sim, $flow);
        }

        return $this->runInteractive($sim);
    }

    private function runSingleFlow(LifecycleSimulator $sim, string $flow): int
    {
        if (! in_array($flow, LifecycleSimulator::FLOWS, true)) {
            $this->error("Unknown flow [{$flow}]. Available: ".implode(', ', LifecycleSimulator::FLOWS));
            return self::FAILURE;
        }

        $billable = $this->resolveBillableFromOptions($sim);
        if ($billable === null) {
            return self::FAILURE;
        }

        return $this->dispatch($sim, $flow, $billable, interactive: false) ? self::SUCCESS : self::FAILURE;
    }

    private function runInteractive(LifecycleSimulator $sim): int
    {
        $this->components->info('Subscription lifecycle simulator (non-production)');
        $this->line('Walks through each flow in order. Pick "skip" to jump one, "abort" to stop.');
        $this->newLine();

        $billable = $this->resolveBillableFromOptions($sim) ?? $this->promptForBillable($sim);
        if ($billable === null) {
            return self::FAILURE;
        }

        $this->line("Target billable: <info>#{$billable->getKey()}</info> ({$billable->getBillingEmail()})");
        $this->newLine();

        foreach (LifecycleSimulator::FLOWS as $flow) {
            $choice = $this->choice(
                "Run flow [{$flow}]?",
                ['run', 'skip', 'abort'],
                default: 'run',
            );

            if ($choice === 'abort') {
                $this->warn('Aborted by user.');
                return self::SUCCESS;
            }
            if ($choice === 'skip') {
                continue;
            }

            $this->dispatch($sim, $flow, $billable, interactive: true);
            $this->newLine();
        }

        $this->components->info('All flows processed.');
        return self::SUCCESS;
    }

    private function resolveBillableFromOptions(LifecycleSimulator $sim): ?Billable
    {
        $id = $this->option('billable');
        if ($id === null || $id === '') {
            return null;
        }

        try {
            return $sim->resolveBillable($id);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return null;
        }
    }

    private function promptForBillable(LifecycleSimulator $sim): ?Billable
    {
        $id = $this->ask('Billable id?');
        if ($id === null || $id === '') {
            $this->error('Billable id required.');
            return null;
        }

        try {
            return $sim->resolveBillable($id);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return null;
        }
    }

    private function dispatch(LifecycleSimulator $sim, string $flow, Billable $billable, bool $interactive): bool
    {
        $this->line("──── <comment>{$flow}</comment> ────");
        $this->describe($flow);

        $before = $this->snapshot($billable);

        try {
            match ($flow) {
                'trial-ending-soon' => $sim->trialEndingSoon($billable),
                'trial-expired' => $this->confirmAnd($interactive, 'Set trial to expired and run trial lifecycle job?')
                    ? $sim->trialExpired($billable)
                    : $this->note('skipped'),
                'renewal' => $this->runRenewal($sim, $billable, $interactive),
                'apply-scheduled-change' => $sim->applyScheduledChange($billable, $this->resolvePlanOption($interactive)),
                'overage-charge' => $this->runOverage($sim, $billable, $interactive),
                'past-due-auto-cancel' => $this->confirmAnd($interactive, 'Force billable to auto-cancel (PastDue → Cancelled)?')
                    ? $sim->pastDueAutoCancel($billable)
                    : $this->note('skipped'),
                'cancelled-to-expired' => $this->confirmAnd($interactive, 'Force billable to Expired?')
                    ? $sim->cancelledToExpired($billable)
                    : $this->note('skipped'),
            };
        } catch (Throwable $e) {
            $this->error("Flow [{$flow}] failed: {$e->getMessage()}");
            return false;
        }

        $billable->refresh();
        $after = $this->snapshot($billable);
        $this->showDiff($before, $after);

        return true;
    }

    private function runRenewal(LifecycleSimulator $sim, Billable $billable, bool $interactive): void
    {
        if ($billable->getMollieMandateId() === null) {
            $this->warn('No Mollie mandate on this billable — renewal flow skipped. Complete a checkout first.');
            return;
        }

        if (! $this->confirmAnd($interactive, 'This will create a REAL payment in the Mollie sandbox. Continue?')) {
            $this->note('skipped');
            return;
        }

        $grossOption = $this->option('gross');
        $override = $grossOption !== null && $grossOption !== '' ? (float) $grossOption : null;

        $result = $sim->renewal($billable, $override);

        $this->line("  → Mollie payment: <info>{$result['payment_id']}</info> ({$result['status']})");
        if ($result['invoice_id'] !== null) {
            $this->line("  → Invoice created: <info>#{$result['invoice_id']}</info>");
        } else {
            $this->warn('  → No invoice created (payment was not paid, or webhook short-circuited).');
        }
    }

    private function runOverage(LifecycleSimulator $sim, Billable $billable, bool $interactive): void
    {
        $type = (string) ($this->option('usage-type') ?? '');
        $units = (int) ($this->option('withdraw') ?? 0);

        if ($interactive) {
            if ($type === '') {
                $type = (string) $this->ask('Usage type to withdraw (e.g. tokens, sms)?');
            }
            if ($units <= 0) {
                $units = (int) $this->ask('Units to withdraw?', '100');
            }
        }

        if ($type === '' || $units <= 0) {
            $this->warn('  Skipping — usage type and units required.');
            return;
        }

        $sim->overageCharge($billable, $type, $units);
        $this->line("  → Withdrew {$units} × {$type}, dispatched PrepareUsageOverageJob.");
    }

    private function resolvePlanOption(bool $interactive): ?string
    {
        $plan = (string) ($this->option('plan') ?? '');
        if ($plan !== '') {
            return $plan;
        }

        if (! $interactive) {
            return null;
        }

        $answer = $this->ask('Plan code to schedule (leave empty to reuse existing scheduled_change)?');
        return $answer !== null && $answer !== '' ? (string) $answer : null;
    }

    private function describe(string $flow): void
    {
        $blurbs = [
            'trial-ending-soon' => 'Sets trial_ends_at = tomorrow, runs ProcessTrialLifecycleJob → expects TrialEndingSoonNotification.',
            'trial-expired' => 'Sets trial_ends_at = yesterday, runs ProcessTrialLifecycleJob → expects status=PastDue + TrialExpiredNotification.',
            'renewal' => 'Creates a real recurring payment in the Mollie sandbox, polls until paid, then triggers our webhook.',
            'apply-scheduled-change' => 'Sets scheduled_change_at = now-1min, runs PrepareUsageOverageJob → ApplyScheduledChangesJob picks it up.',
            'overage-charge' => 'Withdraws units from a wallet (negative balance), runs PrepareUsageOverageJob → triggers a Mollie overage payment.',
            'past-due-auto-cancel' => 'Sets past_due_since older than past_due_max_days, runs PrepareUsageOverageJob → expects status=Cancelled.',
            'cancelled-to-expired' => 'Sets subscription_ends_at = past, runs PrepareUsageOverageJob → expects status=Expired.',
        ];
        $this->line('  '.($blurbs[$flow] ?? ''));
    }

    private function confirmAnd(bool $interactive, string $question): bool
    {
        if (! $interactive || $this->option('yes')) {
            return true;
        }
        return $this->confirm($question, false);
    }

    private function note(string $msg): void
    {
        $this->line("  <fg=gray>{$msg}</>");
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Billable $billable): array
    {
        return [
            'status' => $billable->getBillingSubscriptionStatus()->value,
            'trial_ends_at' => optional($billable->getBillingTrialEndsAt())->toIso8601String(),
            'ends_at' => optional($billable->getBillingSubscriptionEndsAt())->toIso8601String(),
            'period_starts_at' => optional($billable->getBillingPeriodStartsAt())->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function showDiff(array $before, array $after): void
    {
        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $from = $before[$key] ?? '∅';
                $to = $value ?? '∅';
                $this->line("  <fg=yellow>{$key}</>: {$from} → {$to}");
            }
        }
    }
}
