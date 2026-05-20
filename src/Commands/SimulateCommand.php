<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Commands;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\OverageCharged;
use GraystackIT\MollieBilling\Events\PlanChanged;
use GraystackIT\MollieBilling\Events\SubscriptionCancelled;
use GraystackIT\MollieBilling\Events\SubscriptionExpired;
use GraystackIT\MollieBilling\Events\TrialExpired;
use GraystackIT\MollieBilling\Notifications\SubscriptionCancelledNotification;
use GraystackIT\MollieBilling\Notifications\TrialEndingSoonNotification;
use GraystackIT\MollieBilling\Notifications\TrialExpiredNotification;
use GraystackIT\MollieBilling\Testing\LifecycleSimulator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
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
        'trial-ending-soon' => 'Sets trial_ends_at = tomorrow, runs ProcessTrialLifecycleJob.',
        'trial-expired' => 'Sets trial_ends_at = yesterday, runs ProcessTrialLifecycleJob.',
        'renewal' => 'Creates a real recurring payment in the Mollie sandbox, polls until paid, then invokes our webhook.',
        'apply-scheduled-change' => 'Sets scheduled_change_at = now-1min, runs PrepareUsageOverageJob → ApplyScheduledChangesJob picks it up.',
        'overage-charge' => 'Withdraws units from a wallet (negative balance), runs PrepareUsageOverageJob → triggers a Mollie overage payment.',
        'past-due-auto-cancel' => 'Sets past_due_since older than past_due_max_days, runs PrepareUsageOverageJob.',
        'cancelled-to-expired' => 'Sets subscription_ends_at = past, runs PrepareUsageOverageJob.',
    ];

    /**
     * Concrete observable expectations per flow. Verified against the actual
     * state / dispatched events / sent notifications after the flow ran.
     *
     * @var array<string, array{status?: SubscriptionStatus, events?: array<int, class-string>, notifications?: array<int, class-string>}>
     */
    private const EXPECTATIONS = [
        'trial-ending-soon' => [
            'status' => SubscriptionStatus::Trial,
            'notifications' => [TrialEndingSoonNotification::class],
        ],
        'trial-expired' => [
            'status' => SubscriptionStatus::PastDue,
            'events' => [TrialExpired::class],
            'notifications' => [TrialExpiredNotification::class],
        ],
        'renewal' => [
            'status' => SubscriptionStatus::Active,
        ],
        'apply-scheduled-change' => [
            'events' => [PlanChanged::class],
        ],
        'overage-charge' => [
            'events' => [OverageCharged::class],
        ],
        'past-due-auto-cancel' => [
            'status' => SubscriptionStatus::Cancelled,
            'events' => [SubscriptionCancelled::class],
            'notifications' => [SubscriptionCancelledNotification::class],
        ],
        'cancelled-to-expired' => [
            'status' => SubscriptionStatus::Expired,
            'events' => [SubscriptionExpired::class],
        ],
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

        $options = [];
        foreach (LifecycleSimulator::FLOWS as $key) {
            $options[$key] = self::LABELS[$key];
        }
        $options['__exit'] = 'Exit';

        while (true) {
            $this->newLine();

            $choice = (string) select(
                label: 'Which flow do you want to run?',
                options: $options,
                hint: 'Arrow keys to navigate, Enter to pick.',
            );

            if ($choice === '__exit') {
                break;
            }

            $this->newLine();
            $this->dispatch($sim, $choice, $billable, interactive: true);
        }

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

        $expectation = self::EXPECTATIONS[$flow] ?? [];
        $this->showExpectation($expectation);

        $before = $this->snapshot($billable);

        $observedEvents = [];
        $observedNotifications = [];

        // Wildcard listeners receive the event name as the first argument. We
        // filter to class-named events because that's what `event(new …)`
        // produces — string-keyed events (e.g. eloquent.*) are noise here.
        Event::listen('*', function (string $eventName) use (&$observedEvents): void {
            if (class_exists($eventName)) {
                $observedEvents[] = $eventName;
            }
        });
        Event::listen(NotificationSent::class, function (NotificationSent $e) use (&$observedNotifications): void {
            $observedNotifications[] = $e->notification::class;
        });

        $skipped = false;

        try {
            switch ($flow) {
                case 'trial-ending-soon':
                    $sim->trialEndingSoon($billable);
                    break;
                case 'trial-expired':
                    if ($this->confirmAnd($interactive, 'Force trial to expired (sets status=PastDue)?')) {
                        $sim->trialExpired($billable);
                    } else {
                        $skipped = true;
                    }
                    break;
                case 'renewal':
                    $skipped = ! $this->runRenewal($sim, $billable, $interactive);
                    break;
                case 'apply-scheduled-change':
                    $sim->applyScheduledChange($billable, $this->resolvePlanOption($interactive));
                    break;
                case 'overage-charge':
                    $skipped = ! $this->runOverage($sim, $billable, $interactive);
                    break;
                case 'past-due-auto-cancel':
                    if ($this->confirmAnd($interactive, 'Force auto-cancel (PastDue → Cancelled)?')) {
                        $sim->pastDueAutoCancel($billable);
                    } else {
                        $skipped = true;
                    }
                    break;
                case 'cancelled-to-expired':
                    if ($this->confirmAnd($interactive, 'Force billable to Expired?')) {
                        $sim->cancelledToExpired($billable);
                    } else {
                        $skipped = true;
                    }
                    break;
            }
        } catch (Throwable $e) {
            $this->detachListeners();
            $this->error("Flow [{$flow}] failed: {$e->getMessage()}");
            return false;
        }

        $this->detachListeners();

        if ($skipped) {
            $this->note('skipped');
            return true;
        }

        if ($billable instanceof Model) {
            $billable->refresh();
        }
        $after = $this->snapshot($billable);

        $this->showResult($expectation, $before, $after, $observedEvents, $observedNotifications);

        return true;
    }

    private function detachListeners(): void
    {
        Event::forget('*');
        Event::forget(NotificationSent::class);
    }

    /**
     * @param  array{status?: SubscriptionStatus, events?: array<int, class-string>, notifications?: array<int, class-string>}  $expectation
     */
    private function showExpectation(array $expectation): void
    {
        if ($expectation === []) {
            return;
        }

        $this->line('  <fg=cyan>Expected:</>');
        if (isset($expectation['status'])) {
            $this->line('    • status = <info>'.$expectation['status']->value.'</info>');
        }
        foreach ($expectation['events'] ?? [] as $event) {
            $this->line('    • event '.$this->shortClass($event));
        }
        foreach ($expectation['notifications'] ?? [] as $notification) {
            $this->line('    • notification '.$this->shortClass($notification));
        }
    }

    /**
     * @param  array{status?: SubscriptionStatus, events?: array<int, class-string>, notifications?: array<int, class-string>}  $expectation
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<int, string>  $observedEvents
     * @param  array<int, string>  $observedNotifications
     */
    private function showResult(
        array $expectation,
        array $before,
        array $after,
        array $observedEvents,
        array $observedNotifications,
    ): void {
        $this->line('  <fg=cyan>Result:</>');
        $this->showDiff($before, $after);

        if ($expectation === []) {
            return;
        }

        $this->line('  <fg=cyan>Verification:</>');

        if (isset($expectation['status'])) {
            $actual = (string) ($after['status'] ?? '∅');
            $this->renderCheck(
                $actual === $expectation['status']->value,
                "status is {$expectation['status']->value}",
                "actual: {$actual}",
            );
        }

        foreach ($expectation['events'] ?? [] as $event) {
            $this->renderCheck(
                in_array($event, $observedEvents, true),
                'event '.$this->shortClass($event).' dispatched',
            );
        }

        foreach ($expectation['notifications'] ?? [] as $notification) {
            $this->renderCheck(
                in_array($notification, $observedNotifications, true),
                'notification '.$this->shortClass($notification).' sent',
            );
        }
    }

    private function renderCheck(bool $ok, string $label, ?string $detail = null): void
    {
        $mark = $ok ? '<fg=green>✓</>' : '<fg=red>✗</>';
        $line = "    {$mark} {$label}";
        if (! $ok && $detail !== null) {
            $line .= " <fg=gray>({$detail})</>";
        }
        $this->line($line);
    }

    private function shortClass(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return '<info>'.end($parts).'</info>';
    }

    /** @return bool true if the flow ran, false if it was skipped. */
    private function runRenewal(LifecycleSimulator $sim, Billable $billable, bool $interactive): bool
    {
        if ($billable->getMollieMandateId() === null) {
            $this->warn('  No Mollie mandate on this billable — renewal flow skipped. Complete a checkout first.');
            return false;
        }

        if (! $this->confirmAnd($interactive, 'This creates a REAL payment in the Mollie sandbox. Continue?')) {
            return false;
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

        return true;
    }

    /** @return bool true if the flow ran, false if it was skipped. */
    private function runOverage(LifecycleSimulator $sim, Billable $billable, bool $interactive): bool
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
            return false;
        }

        $sim->overageCharge($billable, $type, $units);
        $this->line("  → Withdrew {$units} × {$type}, dispatched PrepareUsageOverageJob.");

        return true;
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
