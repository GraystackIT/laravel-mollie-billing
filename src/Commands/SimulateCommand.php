<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Commands;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Testing\LifecycleSimulator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\search;
use function Laravel\Prompts\text;

class SimulateCommand extends Command
{
    protected $signature = 'billing:simulate
        {flow? : Single flow to run (skips the interactive picker)}
        {--billable= : Billable id for the chosen flow}
        {--usage-type= : Usage type for the overage-charge flow}
        {--withdraw= : Units to withdraw before the overage-charge flow}
        {--plan= : Plan code to schedule for the apply-scheduled-change flow}
        {--gross= : Override gross amount in major units for the renewal flow}
        {--yes : Skip all confirmation prompts}';

    protected $description = 'Simulate subscription lifecycle flows on a non-production system.';

    private const LABELS = [
        'trial-ending-soon' => 'Trial ending soon (T-1 notification)',
        'trial-expired' => 'Trial expired → PastDue + notification',
        'renewal' => 'Renewal (real Mollie sandbox payment + webhook)',
        'apply-scheduled-change' => 'Apply scheduled plan change at period end',
        'overage-charge' => 'Overage charge (negative wallet balance)',
        'past-due-auto-cancel' => 'Past-due → auto-cancelled after max days',
        'cancelled-to-expired' => 'Cancelled → Expired at period end',
    ];

    private const BLURBS = [
        'trial-ending-soon' => 'Sets trial_ends_at = tomorrow, runs ProcessTrialLifecycleJob → expects TrialEndingSoonNotification.',
        'trial-expired' => 'Sets trial_ends_at = yesterday, runs ProcessTrialLifecycleJob → expects status=PastDue + TrialExpiredNotification.',
        'renewal' => 'Creates a real recurring payment in the Mollie sandbox, polls until paid, then invokes our webhook.',
        'apply-scheduled-change' => 'Sets scheduled_change_at = now-1min, runs PrepareUsageOverageJob → ApplyScheduledChangesJob picks it up.',
        'overage-charge' => 'Withdraws units from a wallet (negative balance), runs PrepareUsageOverageJob → triggers a Mollie overage payment.',
        'past-due-auto-cancel' => 'Sets past_due_since older than past_due_max_days, runs PrepareUsageOverageJob → expects status=Cancelled.',
        'cancelled-to-expired' => 'Sets subscription_ends_at = past, runs PrepareUsageOverageJob → expects status=Expired.',
    ];

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

        $billable = $this->resolveBillableFromOptions($sim) ?? $this->pickBillable($sim);
        if ($billable === null) {
            return self::FAILURE;
        }

        $this->line(" Target: <info>#{$billable->getKey()}</info> {$billable->getBillingEmail()}");
        $this->newLine();

        $options = [];
        foreach (LifecycleSimulator::FLOWS as $key) {
            $options[$key] = self::LABELS[$key];
        }

        $selected = multiselect(
            label: 'Which flows do you want to run?',
            options: $options,
            required: false,
            hint: 'Space to toggle, Enter to confirm. Flows run in the order you see here.',
        );

        if ($selected === []) {
            $this->warn('No flows selected — nothing to do.');
            return self::SUCCESS;
        }

        foreach ($selected as $flow) {
            $this->newLine();
            $this->dispatch($sim, $flow, $billable, interactive: true);
        }

        $this->newLine();
        $this->components->info('Done.');
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

    private function pickBillable(LifecycleSimulator $sim): ?Billable
    {
        try {
            $id = search(
                label: 'Pick a billable',
                options: function (string $value) use ($sim): array {
                    return $sim->searchBillables($value);
                },
                placeholder: 'Type id, name or email…',
                scroll: 10,
                hint: 'Arrow keys to navigate, Enter to pick.',
            );

            return $sim->resolveBillable((string) $id);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return null;
        }
    }

    private function dispatch(LifecycleSimulator $sim, string $flow, Billable $billable, bool $interactive): bool
    {
        $label = self::LABELS[$flow] ?? $flow;
        $this->line("──── <comment>{$label}</comment> ────");
        $this->line('  '.(self::BLURBS[$flow] ?? ''));

        $before = $this->snapshot($billable);

        try {
            match ($flow) {
                'trial-ending-soon' => $sim->trialEndingSoon($billable),
                'trial-expired' => $this->confirmAnd($interactive, 'Force trial to expired (sets status=PastDue)?')
                    ? $sim->trialExpired($billable)
                    : $this->note('skipped'),
                'renewal' => $this->runRenewal($sim, $billable, $interactive),
                'apply-scheduled-change' => $sim->applyScheduledChange($billable, $this->resolvePlanOption($interactive)),
                'overage-charge' => $this->runOverage($sim, $billable, $interactive),
                'past-due-auto-cancel' => $this->confirmAnd($interactive, 'Force auto-cancel (PastDue → Cancelled)?')
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
            $this->warn('  No Mollie mandate on this billable — renewal flow skipped. Complete a checkout first.');
            return;
        }

        if (! $this->confirmAnd($interactive, 'This creates a REAL payment in the Mollie sandbox. Continue?')) {
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

        if ($interactive && $type === '') {
            $type = (string) text(
                label: 'Usage type to withdraw',
                placeholder: 'tokens, sms, …',
                required: true,
            );
        }

        if ($interactive && $units <= 0) {
            $units = (int) text(
                label: 'Units to withdraw',
                default: '100',
                required: true,
                validate: fn (string $v) => ((int) $v) > 0 ? null : 'Must be a positive integer.',
            );
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

        $answer = text(
            label: 'Plan code to schedule',
            placeholder: 'leave empty to reuse existing scheduled_change',
            required: false,
        );
        return $answer !== '' ? (string) $answer : null;
    }

    private function confirmAnd(bool $interactive, string $question): bool
    {
        if (! $interactive || $this->option('yes')) {
            return true;
        }
        return confirm(label: $question, default: false);
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
