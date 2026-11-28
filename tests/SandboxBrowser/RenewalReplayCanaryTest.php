<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\SandboxBrowser;

use Carbon\CarbonImmutable;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\CleanupOrphanedBillablesJob;
use GraystackIT\MollieBilling\Jobs\CleanupStalePendingCountryCorrectionJob;
use GraystackIT\MollieBilling\Jobs\CleanupStalePendingOverageChargeJob;
use GraystackIT\MollieBilling\Jobs\CleanupStalePendingProrataChangeJob;
use GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob;
use GraystackIT\MollieBilling\Jobs\ProcessTrialLifecycleJob;
use GraystackIT\MollieBilling\Jobs\RefundUnassignedPaymentsJob;
use GraystackIT\MollieBilling\Jobs\SweepPendingRetriesJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\CreateSubscription;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionReplacer;
use GraystackIT\MollieBilling\Services\Billing\RedeemCouponFromPortal;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Services\Webhook\WebhookSupport;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Support\MollieCall;
use GraystackIT\MollieBilling\Support\MollieCustomerResolver;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\SandboxBrowser\Support\CanaryDeliveries;
use GraystackIT\MollieBilling\Tests\SandboxBrowser\Support\CanaryReceiver;
use GraystackIT\MollieBilling\Tests\SandboxBrowser\Support\CanaryReset;
use GraystackIT\MollieBilling\Tests\SandboxBrowser\Support\SubscriptionCanaryWorld;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;
use Mollie\Api\Http\Requests\CreateMandateRequest;
use Mollie\Api\Http\Requests\GetMandateRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\DeleteCustomerRequest;
use Mollie\Api\Http\Requests\GetPaginatedCustomerPaymentsRequest;
use Mollie\Api\Http\Requests\GetPaginatedSubscriptionPaymentsRequest;
use Mollie\Api\Http\Requests\GetPaginatedSubscriptionsRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Api\Http\Requests\GetSubscriptionRequest;
use Mollie\Api\Http\Requests\RevokeMandateRequest;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * The renewal replay canary: the package's own webhook handling, fed with renewals Mollie's
 * scheduler really created — looked at once a day.
 *
 * `SubscriptionCanaryTest` watches what Mollie does; this one watches what the PACKAGE does
 * with it. The first visit builds a small world through the package's own services — one
 * billable per scenario, each with its own Mollie customer, a direct-debit mandate and a
 * real Mollie subscription whose first charge is tomorrow — in a file-based SQLite database
 * next to the state file, so invoices, wallets and coupons survive from one visit to the
 * next. Every visit then:
 *
 *  1. lists each customer's payments at Mollie (renewals, and the overage charges the package
 *     created itself) and replays every one not yet seen into `MollieWebhookController` —
 *     the same form POST of `id=tr_…` the route gets from Mollie. With a webhook receiver
 *     (`CANARY_RECEIVER_URL`, below) these are Mollie's REAL deliveries, in arrival order;
 *     without one they are synthetic;
 *  2. settles a payment still `pending` through its `changePaymentState` page (Mollie's
 *     test-mode switch for a recurring payment — a test-mode direct debit never settles on
 *     its own, measured 26 November 2026): first replayed while pending (nothing may be
 *     booked), then set to `paid` — or `failed` for the dunning scenario — and replayed
 *     again, then delivered a second time (a re-delivery must change nothing);
 *  3. runs the jobs the scheduler would run — once per UTC day, like the scheduler;
 *  4. holds the world against what the package promises, per scenario.
 *
 * The scenarios (one billable each):
 *
 *  - `renewal`: a plain renewal — one invoice per charge at the charged amount, the period
 *    from the charge's `createdAt`, the non-rollover wallet reset to the quota, the rollover
 *    wallet topped up additively, Mollie's next date = the package's next billing date;
 *  - `overage`: usage beyond the quota before the night before the renewal (the sweep
 *    charges it) and after it (the renewal collects it itself, and its reset keeps the debt
 *    while that charge settles);
 *  - `scheduled_change`: a downgrade scheduled for the period end — Mollie told the new
 *    amount ahead, the renewal booked for the new plan;
 *  - `recurring_coupon`: a 50 % code for two periods redeemed from the portal — two
 *    discounted charges, then Mollie back at full price, never a PaymentAmountMismatch;
 *  - `cancel_at_period_end`: cancelled at the period end — Mollie's subscription cancelled,
 *    no charge ever, `expired` once the end has passed and the nightly pass ran;
 *  - `failed_renewal`: its first renewal set to `failed` — past due, no invoice, dunning,
 *    auto-cancelled after `past_due_max_days` (2 in this world), then expired.
 *
 * The subscriptions are monthly, as the package only knows monthly and yearly: a Mollie
 * interval of "1 day" would charge daily while the package books a month per charge, which
 * tests nothing the package promises. The first renewal is therefore the day after the
 * first visit, the later ones a month apart; the coupon's full-price charge is the third.
 *
 * **The webhook receiver.** With `CANARY_RECEIVER_URL` (and `CANARY_RECEIVER_READ_TOKEN`) set,
 * the app's root URL is the receiver's — an always-on service of its own (repository
 * `mollie-canary-receiver`, see docs/sandbox-testing.md) that records whatever Mollie posts
 * to it. Every webhook URL the package builds then points there, subscriptions created before
 * are PATCHed there once, and each payment the visit books must have a real delivery after its
 * final status change: "Mollie did not deliver" is a problem (the replay falls back to a
 * synthetic POST so the other checks still run). Deliveries of payments already booked are
 * replayed too and must change nothing. Once a week it also pays a few standalone test
 * payments whose webhook URLs fail on purpose (`/probe/503/3/…`, `/probe/500/always/…`, and
 * `/probe/timeout/2/…` where the receiver enables it) and, past Mollie's 26-hour window, reads
 * how Mollie retried: the first reading is the baseline, a later one that differs is a problem.
 *
 * Opt-in: it runs only with `MOLLIE_BILLING_CANARY_STATE` set (the state lands next to that
 * file as `renewal-replay.json`, the database as `renewal-replay.sqlite`, the invoice PDFs
 * in `renewal-replay-invoices/`); without it the test is skipped and creates nothing.
 * `MOLLIE_BILLING_RENEWAL_REPLAY_RESET=1` (or `MOLLIE_BILLING_CANARY_RESET=1`, which resets both canaries)
 * cancels everything at Mollie and deletes the three.
 * `incomplete` while there is nothing new to observe; it fails only on a discrepancy.
 *
 * Deliberately NOT cleaned up by the base class: surviving the run is the point.
 */
class RenewalReplayCanaryTest extends SandboxBrowserTestCase
{
    private const SCENARIOS = [
        'renewal',
        'overage',
        'scheduled_change',
        'recurring_coupon',
        'cancel_at_period_end',
        'failed_renewal',
    ];

    /** Overage units recorded before the night-before sweep (scenario `overage`). */
    private const OVERAGE_BEFORE_SWEEP = 30;

    /** Overage units recorded after the sweep, left for the renewal to collect. */
    private const OVERAGE_AFTER_SWEEP = 20;

    /** @var array<string, mixed> */
    private array $state = [];

    /** @var list<string> */
    private array $problems = [];

    /** @var list<string> */
    private array $observations = [];

    /** @var list<string> what is worth knowing but not red by itself — the receiver unreachable, above all */
    private array $warnings = [];

    /** @var array{kind: string, text: string}|null the first failed read of the receiver this visit */
    private ?array $receiverFailure = null;

    /** @var list<array<string, mixed>> */
    private array $events = [];

    /** @var list<string> */
    private array $logged = [];

    private ?CanaryReceiver $receiver = null;

    /** @var list<array<string, mixed>> what the receiver recorded since the cursor, fetched so far this visit */
    private array $pool = [];

    /** Seconds to wait for Mollie's delivery after a payment reached its final status. */
    private const DELIVERY_WAIT_SECONDS = 300;

    /** Set once Mollie missed a delivery this visit: the next ones are not waited for as long. */
    private bool $deliveryWaitExhausted = false;

    // ── where the world lives ────────────────────────────────────────────────

    private static function canaryStatePath(): ?string
    {
        $configured = $_SERVER['MOLLIE_BILLING_CANARY_STATE'] ?? getenv('MOLLIE_BILLING_CANARY_STATE') ?: null;

        return is_string($configured) && $configured !== '' ? $configured : null;
    }

    private static function worldPath(string $suffix): ?string
    {
        $canary = self::canaryStatePath();

        return $canary === null ? null : dirname($canary).'/renewal-replay'.$suffix;
    }

    /** The world persists between visits: no RefreshDatabase, ever. */
    public function refreshDatabase(): void
    {
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $database = self::worldPath('.sqlite');

        if ($database === null) {
            return;
        }

        if (! is_dir(dirname($database))) {
            mkdir(dirname($database), 0775, true);
        }

        if (! is_file($database)) {
            touch($database);
        }

        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('mail.default', 'array');
        $app['config']->set('filesystems.disks.renewal_replay', [
            'driver' => 'local',
            'root' => (string) self::worldPath('-invoices'),
        ]);
        // Short dunning, so the failed renewal reaches auto-cancel and expiry within days.
        $app['config']->set('mollie-billing.past_due_max_days', 2);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (self::canaryStatePath() === null) {
            $this->markTestSkipped(
                'MOLLIE_BILLING_CANARY_STATE is not set. The renewal replay canary keeps a world at '
                .'Mollie and on disk between visits and runs only where that state is meant to live.'
            );
        }

        // After the parent, which points the invoices at a faked disk for its own tests.
        config()->set('mollie-billing.invoices.disk', 'renewal_replay');

        // After the parent's https://example.com: every URL the package builds — the webhook
        // above all — goes to the receiver, where Mollie's deliveries are recorded.
        $this->receiver = CanaryReceiver::fromEnvironment();

        if ($this->receiver !== null) {
            config()->set('app.url', $this->receiver->url);
            URL::forceRootUrl($this->receiver->url);
        }

        config()->set('mollie-billing-plans.usage_types', [
            'tokens' => ['rollover' => false],
            'credits' => ['rollover' => true],
        ]);

        foreach (['canary_basic' => [1, 1000, 100], 'canary_pro' => [2, 2000, 200]] as $code => [$tier, $net, $tokens]) {
            config()->set('mollie-billing-plans.plans.'.$code, [
                'name' => 'Canary '.($tier === 1 ? 'Basic' : 'Pro'),
                'tier' => $tier,
                'included_seats' => 1,
                'feature_keys' => [],
                'allowed_addons' => [],
                'intervals' => [
                    'monthly' => [
                        'base_price_net' => $net,
                        'seat_price_net' => null,
                        'included_usages' => ['tokens' => $tokens, 'credits' => 50],
                        'usage_overage_prices' => ['tokens' => 10, 'credits' => 10],
                    ],
                ],
            ]);
        }
    }

    // ── the visit ────────────────────────────────────────────────────────────

    public function test_renewals_mollie_created_are_booked_the_way_the_package_promises(): void
    {
        if (CanaryReset::requested('renewal_replay')) {
            $this->reset();
            $this->markTestIncomplete('Renewal replay canary reset: everything cancelled at Mollie, state, database and invoices removed.');
        }

        Artisan::call('migrate', ['--database' => 'testing', '--force' => true]);
        $this->listen();

        try {
            $this->visit();
        } catch (\Throwable $e) {
            if ($e instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $e; // red, incomplete or skipped: the visit finished and wrote itself down
            }

            // Written down before the exception leaves: what this attempt found is not lost
            // when the workflow retries the visit, and the retry is skipped when it found
            // something (.github/scripts/canary-summary.php should-retry).
            $this->state['visits'] = array_slice(array_merge($this->state['visits'] ?? [], [[
                'at' => CarbonImmutable::now('UTC')->toIso8601String(),
                'aborted' => mb_substr(get_class($e).': '.$e->getMessage(), 0, 500),
                'observations' => $this->observations,
                'problems' => $this->problems,
                'warnings' => $this->warnings,
            ]]), -120);
            $this->state['last_problems'] = $this->problems;
            $this->state['last_warnings'] = $this->warnings;

            if (self::worldPath('.json') !== null && $this->state !== []) {
                $this->writeState();
            }

            throw $e;
        }
    }

    private function visit(): void
    {
        $state = $this->readState();
        $carry = [];

        // A world killed by somebody else was torn down by the visit that found it; this one
        // builds the new world, keeping the history and the retry probes.
        if ($state !== null && ! isset($state['scenarios'])) {
            $carry = $state;
            $state = null;
        }

        $created = $state === null;
        $this->state = $state ?? $this->createWorld($carry);
        $this->writeState();

        if (! $created && ($this->state['complete'] ?? true) === false) {
            $created = $this->finishHalfBuiltWorld();

            if ($created === null) {
                return;
            }
        }

        if (! $created && $this->buryDeadWorld()) {
            return;
        }

        $before = $this->fingerprint();
        $observedBefore = $created ? 0 : count($this->observations);

        if ($this->receiver !== null) {
            $this->fetchDeliveries();
            $this->pointWebhooksAtReceiver();
        }

        $this->replayRound();

        $jobsRan = false;
        $today = CarbonImmutable::now('UTC')->toDateString();

        if (($this->state['jobs_ran_on'] ?? null) !== $today) {
            $this->runScheduledJobs();
            $this->state['jobs_ran_on'] = $today;
            $this->state['jobs_runs'][] = CarbonImmutable::now('UTC')->toIso8601String();
            $jobsRan = true;
            $this->writeState();

            // Whatever the jobs created at Mollie (an overage charge) is settled and booked now.
            $this->replayRound();
        }

        if ($this->receiver !== null) {
            $this->replayLateDeliveries();
            $this->observeRetries();
            $this->state['receiver']['cursor'] = max([(int) ($this->state['receiver']['cursor'] ?? 0), ...array_map(static fn (array $d): int => (int) $d['id'], $this->pool)]);
            $this->writeState();
        }

        if ($created) {
            // After the night-before sweep: units the sweep did not see, for the renewal.
            $this->billable('overage')->recordBillingUsage('tokens', self::OVERAGE_AFTER_SWEEP, 'renewal replay canary: after the sweep');
            $this->state['scenarios']['overage']['after_sweep_recorded_at'] = CarbonImmutable::now('UTC')->toIso8601String();
        }

        $this->checkScenarios();

        $newThisVisit = count($this->observations) - $observedBefore;

        if (! $created && $newThisVisit === 0 && ! $jobsRan && $this->fingerprint() !== $before) {
            $this->problems[] = 'A visit with nothing new from Mollie and no scheduled jobs changed the world.';
        }

        foreach ($this->events as $event) {
            if ($event['event'] === 'PaymentAmountMismatch') {
                $this->problems[] = 'PaymentAmountMismatch for '.($event['scenario'] ?? '?').': '.json_encode($event);
            }
        }

        $this->state['events'] = array_slice(array_merge($this->state['events'] ?? [], $this->events), -300);
        $this->state['visits'] = array_slice(array_merge($this->state['visits'] ?? [], [[
            'at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'created_world' => $created,
            'jobs_ran' => $jobsRan,
            'observations' => array_slice($this->observations, $observedBefore),
            'problems' => $this->problems,
            'warnings' => $this->warnings,
            'log' => array_slice($this->logged, 0, 40),
        ]]), -120);
        $this->state['last_checked_at'] = CarbonImmutable::now('UTC')->toIso8601String();
        $this->state['last_problems'] = $this->problems;
        $this->state['last_warnings'] = $this->warnings;
        $this->writeState();

        $this->finishCheckedVisit($created, $observedBefore, $newThisVisit);
    }

    /** @param int $newThisVisit observations this visit made */
    private function finishCheckedVisit(bool $created, int $observedBefore, int $newThisVisit): void
    {
        $this->assertSame([], $this->problems, "The package booked something it does not promise:\n".implode("\n", $this->problems));

        if ($created) {
            $this->markTestIncomplete(
                'Renewal replay world created — the first renewals are due '.$this->state['first_charge_on']
                .'. Seen on this visit: '.implode('; ', array_slice($this->observations, $observedBefore))
            );
        }

        if ($newThisVisit === 0) {
            $this->markTestIncomplete('Renewal replay canary alive, nothing new since '.($this->previousVisitAt() ?? 'the last visit').'.');
        }

        $this->addToAssertionCount(1);
    }

    // ── building the world ───────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $carry  what a torn-down world handed on: its history, the retry probes, the receiver's cursor
     * @return array<string, mixed>
     */
    private function createWorld(array $carry = []): array
    {
        $now = CarbonImmutable::now('UTC');
        $firstCharge = $now->addDay()->startOfDay();

        // A dress rehearsal in a throwaway directory: Mollie takes today as a start date and
        // creates the charge within about two hours (measured 17 November 2026), so the
        // renewal path can be watched the same day. The canary itself never sets this —
        // with today the night-before sweep and the cancel's grace are already behind it.
        if (($_SERVER['MOLLIE_BILLING_RENEWAL_REPLAY_FIRST_CHARGE'] ?? getenv('MOLLIE_BILLING_RENEWAL_REPLAY_FIRST_CHARGE')) === 'today') {
            $firstCharge = $now->startOfDay();
        }

        $this->state = array_merge($carry, [
            'created_at' => $now->toIso8601String(),
            'first_charge_on' => $firstCharge->toDateString(),
            'database' => self::worldPath('.sqlite'),
            // false until every scenario stands: a build that dies half-way is finished
            // (or, past its first charge date, torn down) by the next visit.
            'complete' => false,
            'scenarios' => [],
            'payments' => [],
        ]);

        $this->buildMissingScenarios($firstCharge);

        return $this->state;
    }

    /** Every scenario that does not stand yet, one after another, the state written after each. */
    private function buildMissingScenarios(CarbonImmutable $firstCharge): void
    {
        foreach (self::SCENARIOS as $scenario) {
            if (isset($this->state['scenarios'][$scenario]['setup'])) {
                continue;
            }

            // Begun and not finished by an attempt that died: whatever it made goes first.
            if (isset($this->state['scenarios'][$scenario])) {
                $this->discardPartialScenario($scenario);
            }

            $this->state['scenarios'][$scenario] = $this->createBillable($scenario, $firstCharge);

            // After every one: a failure half-way must not orphan what exists at Mollie.
            $this->writeState();
        }

        $this->state['complete'] = true;
        $this->writeState();
    }

    private function discardPartialScenario(string $scenario): void
    {
        $row = $this->state['scenarios'][$scenario];
        $billable = isset($row['billable_id']) ? TestBillable::query()->find($row['billable_id']) : null;
        $customerId = (string) ($row['customer_id'] ?? $billable?->getMollieCustomerId() ?? '');

        if ($customerId !== '') {
            $this->tearDownAtMollie(['scenarios' => [$scenario => ['customer_id' => $customerId, 'mandate_id' => $row['mandate_id'] ?? $billable?->mollie_mandate_id]]]);
        }

        $billable?->delete();
        unset($this->state['scenarios'][$scenario]);
        $this->warnings[] = "{$scenario}: a half-built scenario from an attempt that died was torn down and built again";
    }

    /**
     * A world whose build died half-way. Before its first charge date the missing scenarios
     * are built now; after it, the world is torn down and the next visit builds a new one
     * (a billable built today would not renew with the others).
     *
     * @return bool|null true: finished (a world created this visit); null: torn down, the visit is over
     */
    private function finishHalfBuiltWorld(): ?bool
    {
        $firstCharge = CarbonImmutable::parse((string) $this->state['first_charge_on'], 'UTC')->startOfDay();

        if ($firstCharge->isAfter(CarbonImmutable::now('UTC'))) {
            $this->buildMissingScenarios($firstCharge);

            return true;
        }

        $this->warnings[] = 'A world whose build died half-way, past its first charge date ('.$this->state['first_charge_on'].'): torn down, the next visit builds a new one.';
        $this->retireWorld(['reasons' => ['half-built'], 'detected_at' => CarbonImmutable::now('UTC')->toIso8601String()]);
        $this->markTestIncomplete(implode(' ', $this->warnings));
    }

    /**
     * A world somebody else killed — a mandate revoked or gone, a customer deleted, a Mollie
     * subscription cancelled while the package still bills on it — teaches nothing more and
     * would fail every visit from then on. It is reported once (the canary never revokes or
     * cancels outside a reset; the package only where its own billable says so, and those
     * scenarios are not looked at here), torn down at Mollie, and the next visit builds a new
     * world. Kept: the visits, the retry probes and their baseline (standalone payments, not
     * part of the world) and the receiver's cursor. Lost: the payments, invoices, wallets and
     * the scenarios' progress — the old database and PDFs remain in `canary-state`'s history.
     */
    private function buryDeadWorld(): bool
    {
        $reasons = [];

        foreach (self::SCENARIOS as $scenario) {
            $row = $this->state['scenarios'][$scenario];
            $billable = TestBillable::query()->find($row['billable_id'] ?? 0);

            if ($billable === null) {
                $reasons[] = "{$scenario}: billable {$row['billable_id']} is missing from the database";

                continue;
            }

            // Only where the package itself still bills: a cancelled or expired scenario's
            // Mollie subscription is ended by the package, on purpose.
            if (! in_array($billable->subscription_status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true)) {
                continue;
            }

            $customerId = (string) $row['customer_id'];
            $mandateId = (string) ($billable->mollie_mandate_id ?? $row['mandate_id'] ?? '');
            $mandate = ['id' => $mandateId, 'status' => null, 'http' => null];

            try {
                $mandate['status'] = (string) Mollie::send(new GetMandateRequest($customerId, $mandateId))->status;
            } catch (ApiException $e) {
                if (! in_array($e->getCode(), SubscriptionCanaryWorld::GONE, true)) {
                    throw $e;
                }

                $mandate['http'] = $e->getCode();
            }

            $meta = $billable->getBillingSubscriptionMeta();
            $subscriptions = [];

            // A replacement in flight cancels the old subscription before the new id is
            // written: not a death, the replacement's own retry finishes it.
            if (! is_array($meta[MollieSubscriptionReplacer::META_KEY] ?? null)) {
                $subscriptionId = (string) ($meta['mollie_subscription_id'] ?? '');

                try {
                    $subscriptions[$scenario] = ['id' => $subscriptionId, 'times' => null, 'status' => (string) Mollie::send(new GetSubscriptionRequest($customerId, $subscriptionId))->status];
                } catch (ApiException $e) {
                    if (! in_array($e->getCode(), SubscriptionCanaryWorld::GONE, true)) {
                        throw $e;
                    }

                    $subscriptions[$scenario] = ['id' => $subscriptionId, 'times' => null, 'http' => $e->getCode()];
                }
            }

            foreach (SubscriptionCanaryWorld::deathReasons($mandate, $subscriptions) as $reason) {
                $reasons[] = "{$scenario} ({$billable->subscription_status->value}): {$reason}";
            }
        }

        if ($reasons === []) {
            return false;
        }

        $this->problems[] = 'The renewal replay world (created '.($this->state['created_at'] ?? '?').') died: '.implode('; ', $reasons)
            .'. Neither the canary nor the package did this. The world was torn down at Mollie; the next visit builds a new one.';
        $this->retireWorld(['reasons' => $reasons, 'detected_at' => CarbonImmutable::now('UTC')->toIso8601String()]);

        $this->assertSame([], $this->problems, implode("\n", $this->problems));

        return true;
    }

    /**
     * Tear the world down at Mollie and on disk and leave a state that only carries what
     * outlives a world; the next visit builds on it (`visit()`: a state without scenarios).
     *
     * @param  array<string, mixed>  $died
     */
    private function retireWorld(array $died): void
    {
        $old = $this->state;
        $this->tearDownAtMollie($old);

        $archived = [
            'created_at' => $old['created_at'] ?? null,
            'first_charge_on' => $old['first_charge_on'] ?? null,
            'died' => $died,
            'scenarios' => array_map(static fn (array $row): array => array_intersect_key($row, array_flip(['billable_id', 'customer_id', 'mandate_id', 'subscription_ids', 'milestones'])), $old['scenarios'] ?? []),
            'payments_seen' => count($old['payments'] ?? []),
        ];

        $receiver = $old['receiver'] ?? [];
        unset($receiver['webhooks']);

        $this->state = array_filter([
            'previous_worlds' => [...($old['previous_worlds'] ?? []), $archived],
            'retry_probes' => $old['retry_probes'] ?? null,
            'receiver' => $receiver ?: null,
            'visits' => array_slice([...($old['visits'] ?? []), [
                'at' => CarbonImmutable::now('UTC')->toIso8601String(),
                'retired_world' => $died,
                'observations' => $this->observations,
                'problems' => $this->problems,
                'warnings' => $this->warnings,
            ]], -120),
            'last_checked_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'last_problems' => $this->problems,
            'last_warnings' => $this->warnings,
        ], static fn ($v): bool => $v !== null);

        $this->removeWorldFiles(keepJson: true);
        $this->writeState();
    }

    /** @return array<string, mixed> */
    private function createBillable(string $scenario, CarbonImmutable $firstCharge): array
    {
        /** @var TestBillable $billable */
        $billable = TestBillable::create([
            'name' => 'Renewal replay '.$scenario,
            'email' => 'renewal-replay-'.$scenario.'-'.bin2hex(random_bytes(3)).'@example.test',
        ]);
        $billable->forceFill([
            'subscription_status' => SubscriptionStatus::New,
            'billing_country' => 'AT',
            'billing_street' => 'Teststrasse 1',
            'billing_postal_code' => '1010',
            'billing_city' => 'Wien',
        ])->save();

        // Known by its id before anything happens to it, so its events are attributed.
        $this->state['scenarios'][$scenario] = ['billable_id' => $billable->getKey()];

        $customerId = app(MollieCustomerResolver::class)->resolve($billable);

        // The one thing no package service creates: a mandate without a first payment.
        // In production it comes from a hosted first payment (FirstPaymentArtifacts).
        $mandate = Mollie::send(new CreateMandateRequest(
            customerId: $customerId,
            method: 'directdebit',
            consumerName: 'Renewal replay '.$scenario,
            consumerAccount: self::TEST_IBAN,
        ));
        $billable->forceFill(['mollie_mandate_id' => (string) $mandate->id])->save();

        $row = [
            'billable_id' => $billable->getKey(),
            'customer_id' => $customerId,
            'mandate_id' => (string) $mandate->id,
        ];

        // A paid period that ends tomorrow: Mollie's first charge is tomorrow.
        app(CreateSubscription::class)->handle($billable->refresh(), [
            'plan_code' => 'canary_pro',
            'interval' => 'monthly',
            'addon_codes' => [],
            'extra_seats' => 0,
            'mandate_id' => (string) $mandate->id,
            'paid_period_ends_at' => $firstCharge,
        ]);
        app(WebhookSupport::class)->creditPeriodQuota($billable->refresh(), 'canary_pro', 'monthly', 'renewal_replay_setup');

        $billable->refresh();
        $row['subscription_ids'] = [(string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id']];
        $row['next_billing_date'] = $billable->nextBillingDate()?->toIso8601String();

        match ($scenario) {
            'renewal' => (function () use ($billable): void {
                $billable->recordBillingUsage('tokens', 30, 'renewal replay canary');
                $billable->recordBillingUsage('credits', 20, 'renewal replay canary');
            })(),
            'overage' => $billable->recordBillingUsage('tokens', 200 + self::OVERAGE_BEFORE_SWEEP, 'renewal replay canary: before the sweep'),
            'scheduled_change' => app(UpdateSubscription::class)->update($billable, [
                'plan_code' => 'canary_basic',
                'interval' => 'monthly',
                'apply_at' => 'end_of_period',
            ]),
            'recurring_coupon' => $row += $this->redeemRecurringCoupon($billable),
            'cancel_at_period_end' => app(CancelSubscription::class)->handle($billable, false),
            default => null,
        };

        $billable->refresh();
        $mollie = $this->mollieSubscription($billable);

        $row['setup'] = [
            'status' => $billable->subscription_status?->value,
            'plan' => $billable->subscription_plan_code,
            'ends_at' => $billable->subscription_ends_at?->toIso8601String(),
            'scheduled_change_at' => $billable->scheduled_change_at?->toIso8601String(),
            'mollie_status' => (string) ($mollie->status ?? ''),
            'mollie_amount' => (string) ($mollie->amount->value ?? ''),
            'mollie_start_date' => (string) ($mollie->startDate ?? ''),
            'mollie_next_payment_date' => (string) ($mollie->nextPaymentDate ?? ''),
            'mollie_webhook_url' => (string) ($mollie->webhookUrl ?? ''),
            'wallets' => $this->wallets($billable),
        ];

        $this->observations[] = "{$scenario}: billable {$billable->getKey()}, Mollie {$row['setup']['mollie_status']} {$row['setup']['mollie_amount']} from {$row['setup']['mollie_start_date']}";

        return $row;
    }

    /** @return array<string, mixed> */
    private function redeemRecurringCoupon(TestBillable $billable): array
    {
        $code = 'RRC'.strtoupper(bin2hex(random_bytes(3)));

        app(CouponService::class)->create([
            'code' => $code,
            'name' => 'Renewal replay: 50 % for two periods',
            'type' => CouponType::Recurring,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 50,
            'discount_periods' => 2,
        ]);

        $outcome = app(RedeemCouponFromPortal::class)->handle($billable->refresh(), $code);

        if (! $outcome->redeemed) {
            $this->problems[] = "recurring_coupon: the portal refused {$code}: {$outcome->message}";
        }

        return ['coupon_code' => $code];
    }

    // ── replaying what Mollie did ────────────────────────────────────────────

    /** Until a round finds nothing to do — booking a payment can create another one. */
    private function replayRound(): void
    {
        for ($round = 0; $round < 6; $round++) {
            $progress = false;

            foreach (self::SCENARIOS as $scenario) {
                $customerId = (string) $this->state['scenarios'][$scenario]['customer_id'];
                $payments = [];

                foreach (Mollie::send(new GetPaginatedCustomerPaymentsRequest($customerId, limit: 250)) as $payment) {
                    $payments[] = $payment;
                }

                usort($payments, static fn ($a, $b): int => strcmp((string) $a->createdAt, (string) $b->createdAt));

                foreach ($payments as $payment) {
                    if ($this->state['payments'][(string) $payment->id]['done'] ?? false) {
                        continue;
                    }

                    $progress = $this->handlePayment($scenario, $payment) || $progress;
                    $this->writeState();
                }
            }

            if (! $progress) {
                return;
            }
        }

        $this->problems[] = 'Six replay rounds in one visit and still new payments — something keeps creating them.';
    }

    private function handlePayment(string $scenario, object $payment): bool
    {
        $id = (string) $payment->id;
        $kind = $this->kindOf($payment);
        $row = $this->state['payments'][$id] ?? [
            'scenario' => $scenario,
            'kind' => $kind,
            'subscription_id' => isset($payment->subscriptionId) ? (string) $payment->subscriptionId : null,
            'created_at' => (string) $payment->createdAt,
            'amount' => (string) $payment->amount->value,
            'method' => (string) ($payment->method ?? ''),
            'first_seen_at' => CarbonImmutable::now('UTC')->toIso8601String(),
        ];

        $viaReceiver = $this->deliversToReceiver($payment);

        if (in_array((string) $payment->status, ['open', 'pending', 'authorized'], true)) {
            if (! isset($row['pending_replay'])) {
                // What Mollie delivered while it was pending, if anything; else one of our own.
                $real = $viaReceiver ? $this->unreplayedDeliveries($id) : [];
                $row['pending_replay'] = $real === []
                    ? ['status' => (string) $payment->status, 'http' => $this->deliver($id), 'source' => 'synthetic']
                    : ['status' => (string) $payment->status, 'http' => $this->worst($this->replayReal($real)), 'source' => 'mollie', 'deliveries' => array_column($real, 'id')];

                if ($row['pending_replay']['http'] !== 200) {
                    $this->problems[] = "{$scenario} {$id}: a {$payment->status} delivery answered {$row['pending_replay']['http']}, not 200";
                }

                if ($this->invoicesFor($id) !== []) {
                    $this->problems[] = "{$scenario} {$id}: an invoice was written for a payment that is still {$payment->status}";
                }
            }

            $target = $this->desiredOutcome($scenario, $kind);
            $link = $payment->_links->changePaymentState->href ?? null;

            if (! is_string($link) || $link === '') {
                // Nothing to settle it with; looked at again next visit.
                $row['waiting_since'] ??= CarbonImmutable::now('UTC')->toIso8601String();
                $this->state['payments'][$id] = $row;

                return false;
            }

            $row['settle_started_at'] = CarbonImmutable::now('UTC')->toIso8601String();
            $this->settle($link, $target, "renewal-replay-{$scenario}-{$id}");
            $payment = $this->waitUntilSettled($id);
            $row['settled_to'] = $target;
            $row['settled_at'] = CarbonImmutable::now('UTC')->toIso8601String();

            if ((string) $payment->status !== $target) {
                $this->problems[] = "{$scenario} {$id}: set to {$target} on Mollie's test page, Mollie reads {$payment->status}";
            }
        }

        $billable = $this->billable($scenario);
        $row['status'] = (string) $payment->status;
        $row['paid_at'] = isset($payment->paidAt) ? (string) $payment->paidAt : null;
        $row['failed_at'] = isset($payment->failedAt) ? (string) $payment->failedAt : null;
        $row['wallets_before'] = $this->wallets($billable);
        $row['subscription_status_before'] = $billable->subscription_status?->value;

        if ($viaReceiver) {
            $row['http'] = $this->replayMollieDelivery($scenario, $id, $row, $payment);
        } else {
            $row['http'] = $this->deliver($id);
        }

        $billable->refresh();
        $row['wallets_after'] = $this->wallets($billable);
        $row['subscription_status_after'] = $billable->subscription_status?->value;
        $row['plan_after'] = $billable->subscription_plan_code;
        $row['invoices'] = $this->invoicesFor($id);
        $row['replayed_at'] = CarbonImmutable::now('UTC')->toIso8601String();

        // A re-delivery of the same outcome must change nothing.
        $fingerprint = $this->fingerprint();
        $row['redelivery_http'] = $this->deliver($id);

        if ($this->fingerprint() !== $fingerprint) {
            $this->problems[] = "{$scenario} {$id}: a second delivery of the same {$payment->status} changed the world";
        }

        $row['done'] = true;
        $this->state['payments'][$id] = $row;
        $this->checkPayment($scenario, $id, $row, $payment);

        $invoice = $row['invoices'][0] ?? null;
        $this->observations[] = sprintf(
            '%s: %s %s %s %s created %s → %s, invoice %s',
            $scenario,
            $kind,
            $id,
            $row['amount'],
            $row['status'],
            substr($row['created_at'], 0, 16),
            $row['http'],
            $invoice === null ? 'none' : $invoice['serial'].' '.$invoice['gross'],
        );

        return true;
    }

    /** The general promises, for every payment. */
    private function checkPayment(string $scenario, string $id, array $row, object $payment): void
    {
        if ($row['http'] !== 200 || $row['redelivery_http'] !== 200) {
            $this->problems[] = "{$scenario} {$id}: webhook answered {$row['http']} / re-delivery {$row['redelivery_http']}, not 200";
        }

        $invoices = $row['invoices'];

        if ($row['status'] !== 'paid') {
            if ($invoices !== []) {
                $this->problems[] = "{$scenario} {$id}: a {$row['status']} payment has an invoice";
            }

            return;
        }

        if (count($invoices) !== 1) {
            $this->problems[] = "{$scenario} {$id}: paid, ".count($invoices).' invoices instead of one';

            return;
        }

        $invoice = $invoices[0];
        $cents = (int) round(((float) $row['amount']) * 100);

        if ($invoice['gross'] !== $cents) {
            $this->problems[] = "{$scenario} {$id}: invoiced {$invoice['gross']} cents, Mollie collected {$cents}";
        }

        $expectedKind = match ($row['kind']) {
            'renewal' => InvoiceKind::Subscription->value,
            'overage' => InvoiceKind::Overage->value,
            default => null,
        };

        if ($expectedKind !== null && $invoice['kind'] !== $expectedKind) {
            $this->problems[] = "{$scenario} {$id}: invoice kind {$invoice['kind']}, expected {$expectedKind}";
        }

        if ($row['kind'] === 'renewal') {
            $created = CarbonImmutable::parse($row['created_at'])->utc()->toDateString();

            if (substr((string) $invoice['period_start'], 0, 10) !== $created) {
                $this->problems[] = "{$scenario} {$id}: invoice period starts {$invoice['period_start']}, the charge was created on {$created}";
            }

            if (! in_array($row['subscription_status_after'], [SubscriptionStatus::Active->value], true)) {
                $this->problems[] = "{$scenario} {$id}: after a paid renewal the subscription is {$row['subscription_status_after']}";
            }
        }
    }

    /** `failed` for the dunning scenario's first renewal; `paid` for everything else. */
    private function desiredOutcome(string $scenario, string $kind): string
    {
        if ($scenario !== 'failed_renewal' || $kind !== 'renewal') {
            return 'paid';
        }

        foreach ($this->state['payments'] as $row) {
            if ($row['scenario'] === 'failed_renewal' && $row['kind'] === 'renewal' && ($row['settled_to'] ?? null) === 'failed') {
                return 'paid';
            }
        }

        return 'failed';
    }

    private function kindOf(object $payment): string
    {
        if (isset($payment->subscriptionId) && (string) $payment->subscriptionId !== '') {
            return 'renewal';
        }

        $type = (string) ($payment->metadata->type ?? '');

        return $type !== '' ? $type : (string) ($payment->sequenceType ?? 'unknown');
    }

    /** The same request Mollie sends: a form POST with the payment id. */
    private function deliver(string $paymentId): int
    {
        return $this->post(route(BillingRoute::webhook()), ['id' => $paymentId])->getStatusCode();
    }

    // ── the webhook receiver: Mollie's real deliveries ───────────────────────

    /** Adds what the receiver recorded since the cursor (on a new world: since its creation). */
    private function fetchDeliveries(): void
    {
        $known = [];

        foreach ($this->pool as $delivery) {
            $known[(int) $delivery['id']] = true;
        }

        $last = $known === [] ? (int) ($this->state['receiver']['cursor'] ?? 0) : max(array_keys($known));
        $filter = $last > 0
            ? ['after_id' => $last]
            : ['since' => CarbonImmutable::parse((string) $this->state['created_at'])->utc()->subDay()->format('Y-m-d\TH:i:s.v\Z')];

        foreach ($this->readReceiver($filter) ?? [] as $delivery) {
            if (! isset($known[(int) $delivery['id']])) {
                $this->pool[] = $delivery;
            }
        }
    }

    /**
     * `GET /deliveries`, or null when the receiver cannot be read. The first failure of a visit
     * is written down once ({@see CanaryReceiver::failure()}): a rejected token is a problem
     * (a misconfiguration nobody else will notice), an unreachable receiver a warning — the
     * visit goes on with synthetic deliveries and does not blame Mollie for what it could not see.
     *
     * @param  array<string, mixed>  $filter
     * @return list<array<string, mixed>>|null
     */
    private function readReceiver(array $filter): ?array
    {
        if ($this->receiverFailure !== null) {
            return null;
        }

        try {
            return $this->receiver->deliveries($filter);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $this->receiverFailure = CanaryReceiver::failure($e->response->status(), $e->getMessage());
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->receiverFailure = CanaryReceiver::failure(null, $e->getMessage());
        }

        if ($this->receiverFailure['kind'] === 'token_rejected') {
            $this->problems[] = $this->receiverFailure['text'];
        } else {
            $this->warnings[] = $this->receiverFailure['text'];
        }

        return null;
    }

    /** A payment Mollie reports to the receiver — its own webhookUrl says so, not ours. */
    private function deliversToReceiver(object $payment): bool
    {
        return $this->receiver !== null
            && str_starts_with((string) ($payment->webhookUrl ?? ''), $this->receiver->url.'/')
            && ! str_starts_with((string) $payment->webhookUrl, $this->receiver->url.CanaryDeliveries::PROBE_PREFIX);
    }

    /** @return list<array<string, mixed>> */
    private function unreplayedDeliveries(string $paymentId): array
    {
        $replayed = array_flip($this->state['receiver']['replayed'] ?? []);

        return array_values(array_filter(
            CanaryDeliveries::forPayment($this->pool, $paymentId),
            static fn (array $d): bool => ! isset($replayed[(int) $d['id']]),
        ));
    }

    /**
     * Each delivery, in arrival order, into the webhook route — the same form POST of its id.
     *
     * @param  list<array<string, mixed>>  $deliveries
     * @return list<int>
     */
    private function replayReal(array $deliveries): array
    {
        $codes = [];

        foreach ($deliveries as $delivery) {
            $codes[] = $this->deliver((string) $delivery['payment_id']);
            $this->state['receiver']['replayed'][] = (int) $delivery['id'];
        }

        $this->state['receiver']['replayed'] = array_slice($this->state['receiver']['replayed'] ?? [], -3000);

        return $codes;
    }

    /** @param list<int> $codes */
    private function worst(array $codes): int
    {
        foreach ($codes as $code) {
            if ($code !== 200) {
                return $code;
            }
        }

        return $codes === [] ? 0 : 200;
    }

    private function finalStatusAt(object $payment): string
    {
        foreach (['paidAt', 'failedAt', 'canceledAt', 'expiredAt', 'createdAt'] as $field) {
            if (isset($payment->{$field}) && (string) $payment->{$field} !== '') {
                return (string) $payment->{$field};
            }
        }

        return CarbonImmutable::now('UTC')->toIso8601String();
    }

    /**
     * Book a payment from Mollie's own delivery: at least one must have arrived after its
     * final status change (polled for a while); all not yet replayed go in, in arrival order.
     * None → "Mollie did not deliver", and a synthetic POST so the remaining checks still run.
     *
     * @param  array<string, mixed>  $row
     */
    private function replayMollieDelivery(string $scenario, string $id, array &$row, object $payment): int
    {
        $since = (string) ($row['settle_started_at'] ?? $this->finalStatusAt($payment));
        $configured = (int) ($_SERVER['CANARY_RECEIVER_WAIT_SECONDS'] ?? getenv('CANARY_RECEIVER_WAIT_SECONDS') ?: self::DELIVERY_WAIT_SECONDS);
        $wait = $this->deliveryWaitExhausted ? min(60, $configured) : $configured;
        $started = microtime(true);

        while (($arrived = CanaryDeliveries::forPayment($this->pool, $id, $since)) === [] && $this->receiverFailure === null && microtime(true) - $started < $wait) {
            usleep(10_000_000);
            $this->fetchDeliveries();
        }

        // The receiver could not be read: whether Mollie delivered is unknown, not "no".
        if ($arrived === [] && $this->receiverFailure !== null) {
            $this->warnings[] = "{$scenario} {$id}: delivery not checked (receiver unreachable); replayed synthetically";
            $row['delivery'] = ['source' => 'synthetic', 'reason' => 'receiver unreachable', 'waited_s' => (int) round(microtime(true) - $started)];

            return $this->deliver($id);
        }

        if ($arrived === []) {
            $this->deliveryWaitExhausted = true;
            $this->problems[] = "{$scenario} {$id}: Mollie did not deliver — {$payment->status} since {$since}, nothing at the receiver after {$wait} s; replayed synthetically instead";
            $row['delivery'] = ['source' => 'synthetic', 'waited_s' => (int) round(microtime(true) - $started)];

            return $this->deliver($id);
        }

        $replay = $this->unreplayedDeliveries($id) ?: [end($arrived)];
        $codes = $this->replayReal($replay);
        $row['delivery'] = [
            'source' => 'mollie',
            'replayed' => array_column($replay, 'id'),
            'first_received_at' => (string) $arrived[0]['received_at'],
            'after_s' => (int) CarbonImmutable::parse($since)->diffInSeconds(CarbonImmutable::parse((string) $arrived[0]['received_at']), false),
            'deliveries_after_final_status' => count($arrived),
            'replay_http' => $codes,
        ];

        return $this->worst($codes);
    }

    /**
     * Subscriptions created before the receiver existed still post to example.com: PATCH their
     * webhookUrl once — nothing but the webhookUrl, so no start date ever goes along.
     */
    private function pointWebhooksAtReceiver(): void
    {
        $expected = route(BillingRoute::webhook());

        foreach (self::SCENARIOS as $scenario) {
            $ids = $this->state['scenarios'][$scenario]['subscription_ids'] ?? [];
            $subscriptionId = (string) ($this->billable($scenario)->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');
            $subscriptionId = $subscriptionId !== '' ? $subscriptionId : (string) (end($ids) ?: '');

            if ($subscriptionId === '' || isset($this->state['receiver']['webhooks'][$subscriptionId])) {
                continue;
            }

            $customerId = (string) $this->state['scenarios'][$scenario]['customer_id'];
            $subscription = Mollie::send(new GetSubscriptionRequest($customerId, $subscriptionId));
            $current = (string) ($subscription->webhookUrl ?? '');
            $entry = ['scenario' => $scenario, 'from' => $current, 'at' => CarbonImmutable::now('UTC')->toIso8601String()];

            if ($current === $expected) {
                $entry['outcome'] = 'already';
            } elseif (! in_array((string) $subscription->status, ['active', 'pending'], true)) {
                $entry['outcome'] = 'left alone: '.$subscription->status;
            } else {
                $updated = MollieCall::send(new UpdateSubscriptionRequest($customerId, $subscriptionId, webhookUrl: $expected));
                $entry['outcome'] = 'patched';
                $entry['to'] = (string) ($updated->webhookUrl ?? '');

                if ($entry['to'] !== $expected) {
                    $this->problems[] = "{$scenario}: PATCHed the webhookUrl of {$subscriptionId} to the receiver, Mollie holds {$entry['to']}";
                }

                $this->observations[] = "{$scenario}: Mollie subscription {$subscriptionId} now posts to the receiver (was {$current})";
            }

            $this->state['receiver']['webhooks'][$subscriptionId] = $entry;
            $this->writeState();
        }
    }

    /**
     * Deliveries of payments already booked — Mollie posting again — go in too, and must change
     * nothing while the payment's status is what was booked.
     */
    private function replayLateDeliveries(): void
    {
        $this->fetchDeliveries();
        $replayed = array_flip($this->state['receiver']['replayed'] ?? []);
        $foreign = 0;

        foreach ($this->pool as $delivery) {
            if (CanaryDeliveries::isProbe($delivery) || isset($replayed[(int) $delivery['id']])) {
                continue;
            }

            $paymentId = (string) ($delivery['payment_id'] ?? '');
            $row = $this->state['payments'][$paymentId] ?? null;

            if ($row === null) {
                $foreign++;

                continue;
            }

            if (! ($row['done'] ?? false)) {
                continue;
            }

            $status = (string) Mollie::send(new GetPaymentRequest($paymentId))->status;
            $before = $this->fingerprint();
            $http = $this->replayReal([$delivery])[0];
            $changed = $this->fingerprint() !== $before;

            if ($http !== 200) {
                $this->problems[] = "{$row['scenario']} {$paymentId}: Mollie's later delivery ({$delivery['received_at']}) answered {$http}, not 200";
            }

            if ($changed && $status === $row['status']) {
                $this->problems[] = "{$row['scenario']} {$paymentId}: Mollie's later delivery ({$delivery['received_at']}) changed the world although the payment is still {$status}";
            }

            $this->state['payments'][$paymentId]['late_deliveries'][] = [
                'delivery' => (int) $delivery['id'],
                'received_at' => (string) $delivery['received_at'],
                'status' => $status,
                'http' => $http,
                'changed' => $changed,
            ];
            $this->observations[] = "{$row['scenario']}: Mollie delivered {$paymentId} again ({$delivery['received_at']}, {$status}) → {$http}".($changed ? ', booked a change' : ', nothing changed');
        }

        // Not this world's: a rehearsal's, a probe's, or somebody posting to a public URL.
        $this->state['receiver']['foreign_deliveries'] = (int) ($this->state['receiver']['foreign_deliveries'] ?? 0) + $foreign;
    }

    // ── how Mollie retries: standalone probe payments ────────────────────────

    private function observeRetries(): void
    {
        // Nothing can be read or started without the receiver; the sets wait for the next visit.
        if ($this->receiverFailure !== null) {
            return;
        }

        $now = CarbonImmutable::now('UTC');
        $sets = $this->state['retry_probes']['sets'] ?? [];

        foreach ($sets as $i => $set) {
            if (($set['completed_at'] ?? null) !== null || ! CanaryDeliveries::probeSetReadable($set, $now)) {
                continue;
            }

            $summaries = [];

            foreach ($set['probes'] as $name => $probe) {
                if (($probe['paid_at'] ?? null) === null) {
                    continue;
                }

                $attempts = $this->readReceiver([
                    'payment_id' => (string) $probe['payment_id'],
                    'path_prefix' => (string) $probe['path'],
                ]);

                if ($attempts === null) {
                    return; // read again on the next visit, whole
                }

                $summaries[$name] = CanaryDeliveries::retrySummary($attempts);

                if ($summaries[$name]['attempts'] === 0) {
                    $this->problems[] = "Mollie did not deliver to the retry probe {$name} ({$probe['payment_id']}) at all";
                }
            }

            $sets[$i]['summaries'] = $summaries;
            $sets[$i]['completed_at'] = $now->toIso8601String();
            $baseline = $this->state['retry_probes']['baseline'] ?? null;

            if ($baseline === null) {
                $this->state['retry_probes']['baseline'] = [
                    'measured_on' => $now->toDateString(),
                    'set_created_at' => $set['created_at'],
                    'summaries' => $summaries,
                    'differs_from_documentation' => isset($summaries['500always']) ? CanaryDeliveries::documentedDeviations($summaries['500always']) : [],
                ];
                $this->observations[] = 'Retry baseline measured — write it into docs/decisions.md: '.json_encode($this->state['retry_probes']['baseline'], JSON_UNESCAPED_SLASHES);

                continue;
            }

            $deviations = [];

            foreach ($summaries as $name => $summary) {
                if (isset($baseline['summaries'][$name])) {
                    $deviations = array_merge($deviations, CanaryDeliveries::retryDeviations($name, $baseline['summaries'][$name], $summary));
                } else {
                    $this->observations[] = "Retry probe {$name} measured for the first time (no baseline for it yet): ".json_encode($summary);
                }
            }

            array_push($this->problems, ...$deviations);
            $this->observations[] = "Retry probes of {$set['created_at']}: ".($deviations === [] ? 'as the baseline' : count($deviations).' deviations');
        }

        $this->state['retry_probes']['sets'] = $sets;

        if (CanaryDeliveries::probeSetDue($sets, $now)) {
            $this->startProbeSet($now);
        }

        $this->state['retry_probes']['sets'] = array_slice($this->state['retry_probes']['sets'] ?? [], -60);
    }

    /** Standalone test payments — no billable, no package code — paid on Mollie's test page. */
    private function startProbeSet(CarbonImmutable $now): void
    {
        $tag = $now->format('Ymd\THis');
        $index = count($this->state['retry_probes']['sets'] ?? []);
        $this->state['retry_probes']['sets'][$index] = ['created_at' => $now->toIso8601String(), 'tag' => $tag, 'probes' => [], 'completed_at' => null];

        foreach (CanaryDeliveries::probesFor($this->receiver->settings) as $name => $prefix) {
            $path = "{$prefix}/{$tag}-{$name}";
            $payment = MollieCall::send(new CreatePaymentRequest(
                description: "Renewal replay canary: retry probe {$name} {$tag}",
                amount: new Money('EUR', '1.00'),
                redirectUrl: $this->receiver->url.'/return',
                webhookUrl: $this->receiver->url.$path,
                metadata: ['canary' => 'retry-probe', 'probe' => $name],
            ));
            $probe = ['payment_id' => (string) $payment->id, 'path' => $path, 'created_at' => (string) $payment->createdAt, 'paid_at' => null];
            $this->state['retry_probes']['sets'][$index]['probes'][$name] = $probe;
            $this->writeState();

            try {
                $this->completeMollieCheckout((string) $payment->getCheckoutUrl(), 'paid', "retry-probe-{$name}");
                $paid = $this->waitUntilSettled((string) $payment->id);

                if ((string) $paid->status === 'paid') {
                    $this->state['retry_probes']['sets'][$index]['probes'][$name]['paid_at'] = (string) $paid->paidAt;
                } else {
                    $this->problems[] = "retry probe {$name} {$payment->id}: paid on Mollie's test page, Mollie reads {$paid->status}";
                }
            } catch (\Throwable $e) {
                $this->problems[] = "retry probe {$name} {$payment->id}: could not be paid: ".$e->getMessage();
            }

            $this->writeState();
        }

        $this->observations[] = 'Retry probes started ('.implode(', ', array_keys($this->state['retry_probes']['sets'][$index]['probes'])).'); read after '.CanaryDeliveries::PROBE_READ_AFTER_HOURS.' h';
    }

    /**
     * Mollie's test-mode switch for a recurring payment: the `changePaymentState` page,
     * a form with `final_state` radios — the only way a test-mode direct debit settles.
     */
    private function settle(string $url, string $outcome, string $label): void
    {
        $browser = $this->browser();
        $browser->visit($url);

        try {
            $browser->waitUsing(30, 250, fn (): bool => $this->domCountOnMollie('input[name="final_state"]') > 0,
                'Mollie did not show its test-mode page for the payment.');
            $browser->script(sprintf('document.querySelector(\'input[name="final_state"][value="%s"]\').click()', $outcome));
            $browser->script('document.querySelector(\'button[name="submit"]\').click()');
            $browser->pause(2000);
        } catch (\Throwable $e) {
            $browser->screenshot($label.'-FAILED');
            file_put_contents(self::artifactDirectory().'/'.$label.'-FAILED.html', $browser->driver->getPageSource());

            throw $e;
        }
    }

    private function waitUntilSettled(string $paymentId): object
    {
        $deadline = microtime(true) + 60;

        do {
            $payment = Mollie::send(new GetPaymentRequest($paymentId));

            if (! in_array((string) $payment->status, ['open', 'pending', 'authorized'], true)) {
                return $payment;
            }

            usleep(2_000_000);
        } while (microtime(true) < $deadline);

        return $payment;
    }

    /** What the scheduler runs in a day (MollieBillingServiceProvider::registerScheduledJobs). */
    private function runScheduledJobs(): void
    {
        foreach ([
            PrepareUsageOverageJob::class,
            ProcessTrialLifecycleJob::class,
            RefundUnassignedPaymentsJob::class,
            CleanupStalePendingProrataChangeJob::class,
            CleanupStalePendingOverageChargeJob::class,
            CleanupStalePendingCountryCorrectionJob::class,
            SweepPendingRetriesJob::class,
            CleanupOrphanedBillablesJob::class,
        ] as $job) {
            try {
                Bus::dispatchSync(new $job);
            } catch (\Throwable $e) {
                $this->problems[] = class_basename($job).' threw: '.$e->getMessage();
            }
        }
    }

    // ── what the package promises, per scenario ──────────────────────────────

    private function checkScenarios(): void
    {
        foreach (self::SCENARIOS as $scenario) {
            $billable = $this->billable($scenario);
            $rows = array_filter($this->state['payments'], static fn (array $r): bool => $r['scenario'] === $scenario && ($r['done'] ?? false));
            $renewals = array_values(array_filter($rows, static fn (array $r): bool => $r['kind'] === 'renewal'));
            $paidRenewals = array_values(array_filter($renewals, static fn (array $r): bool => $r['status'] === 'paid'));

            $this->recordSubscriptionIds($scenario, $billable);

            match ($scenario) {
                'renewal' => $this->checkPlainRenewal($billable, $paidRenewals),
                'overage' => $this->checkOverage($billable, $rows),
                'scheduled_change' => $this->checkScheduledChange($billable, $paidRenewals),
                'recurring_coupon' => $this->checkRecurringCoupon($billable, $paidRenewals),
                'cancel_at_period_end' => $this->checkCancelAtPeriodEnd($billable, $rows),
                'failed_renewal' => $this->checkFailedRenewal($billable, $renewals),
            };

            $this->state['scenarios'][$scenario]['now'] = [
                'status' => $billable->subscription_status?->value,
                'plan' => $billable->subscription_plan_code,
                'period_starts_at' => $billable->subscription_period_starts_at?->toIso8601String(),
                'next_billing_date' => $billable->nextBillingDate()?->toIso8601String(),
                'ends_at' => $billable->subscription_ends_at?->toIso8601String(),
                'wallets' => $this->wallets($billable),
                'invoices' => BillingInvoice::query()->where('billable_id', $billable->getKey())->count(),
            ];
        }
    }

    /** @param list<array<string, mixed>> $paidRenewals */
    private function checkPlainRenewal(TestBillable $billable, array $paidRenewals): void
    {
        foreach ($paidRenewals as $row) {
            if (($row['wallets_after']['tokens'] ?? null) !== 200) {
                $this->problems[] = 'renewal: tokens (no rollover) after the renewal '.json_encode($row['wallets_after']['tokens'] ?? null).', expected the quota 200';
            }

            $expectedCredits = ($row['wallets_before']['credits'] ?? 0) + 50;

            if (($row['wallets_after']['credits'] ?? null) !== $expectedCredits) {
                $this->problems[] = 'renewal: credits (rollover) after the renewal '.json_encode($row['wallets_after']['credits'] ?? null).", expected {$expectedCredits} (added on top)";
            }

            if ($row['amount'] !== '24.00') {
                $this->problems[] = "renewal: charged {$row['amount']}, the plan costs 24.00";
            }
        }

        $this->checkMollieAgreesOnNextCharge('renewal', $billable);
    }

    /** @param array<string, array<string, mixed>> $rows */
    private function checkOverage(TestBillable $billable, array $rows): void
    {
        $overages = array_values(array_filter($rows, static fn (array $r): bool => $r['kind'] === 'overage'));
        $renewal = array_values(array_filter($rows, static fn (array $r): bool => $r['kind'] === 'renewal' && $r['status'] === 'paid'))[0] ?? null;

        // The sweep's charge, created by the setup visit's jobs before any renewal: 30 units
        // at 0.10 + 20 %.
        $sweep = array_values(array_filter(
            $overages,
            static fn (array $r): bool => $renewal === null || $r['created_at'] < $renewal['created_at'],
        ))[0] ?? null;

        if (($this->state['jobs_runs'] ?? []) !== [] && $sweep === null) {
            $this->problems[] = 'overage: the night-before sweep created no overage charge for '.self::OVERAGE_BEFORE_SWEEP.' units over the quota';
        } elseif ($sweep !== null && $sweep['amount'] !== '3.60') {
            $this->problems[] = "overage: the sweep charged {$sweep['amount']}, expected 3.60 for ".self::OVERAGE_BEFORE_SWEEP.' units';
        }

        if ($renewal === null) {
            return;
        }

        // The renewal collects the units the sweep never saw, and its reset keeps the debt
        // while that charge settles: −20 + 200.
        if (($renewal['wallets_after']['tokens'] ?? null) !== 200 - self::OVERAGE_AFTER_SWEEP) {
            $this->problems[] = 'overage: tokens right after the renewal '.json_encode($renewal['wallets_after']['tokens'] ?? null).', expected '.(200 - self::OVERAGE_AFTER_SWEEP).' (the debt kept while its charge settles)';
        }

        $collected = array_values(array_filter($overages, static fn (array $r): bool => $r['created_at'] >= $renewal['created_at']));

        if ($collected === []) {
            $this->problems[] = 'overage: the renewal did not collect the '.self::OVERAGE_AFTER_SWEEP.' units recorded after the sweep';

            return;
        }

        if ($collected[0]['amount'] !== '2.40') {
            $this->problems[] = "overage: the renewal collected {$collected[0]['amount']}, expected 2.40";
        }

        if ($collected[0]['status'] === 'paid' && ($collected[0]['wallets_after']['tokens'] ?? null) !== 200) {
            $this->problems[] = 'overage: tokens after the collected overage settled '.json_encode($collected[0]['wallets_after']['tokens'] ?? null).', expected 200';
        }
    }

    /** @param list<array<string, mixed>> $paidRenewals */
    private function checkScheduledChange(TestBillable $billable, array $paidRenewals): void
    {
        $setup = $this->state['scenarios']['scheduled_change']['setup'];

        if ($setup['scheduled_change_at'] === null) {
            $this->problems[] = 'scheduled_change: nothing was scheduled at setup';
        }

        if ($setup['mollie_amount'] !== '12.00') {
            $this->problems[] = "scheduled_change: Mollie held {$setup['mollie_amount']} after scheduling the downgrade, expected the new 12.00 ahead of the boundary";
        }

        if ($paidRenewals === []) {
            return;
        }

        $first = $paidRenewals[0];

        if ($first['amount'] !== '12.00' || $first['plan_after'] !== 'canary_basic') {
            $this->problems[] = "scheduled_change: the renewal charged {$first['amount']} and left plan {$first['plan_after']}, expected 12.00 on canary_basic";
        }

        if ($billable->scheduled_change_at !== null) {
            $this->problems[] = 'scheduled_change: still scheduled after the boundary was booked';
        }

        if (($first['wallets_after']['tokens'] ?? null) !== 100) {
            $this->problems[] = 'scheduled_change: tokens after the renewal '.json_encode($first['wallets_after']['tokens'] ?? null).', expected the new plan\'s 100';
        }

        $this->checkMollieAgreesOnNextCharge('scheduled_change', $billable);
    }

    /** @param list<array<string, mixed>> $paidRenewals */
    private function checkRecurringCoupon(TestBillable $billable, array $paidRenewals): void
    {
        $setup = $this->state['scenarios']['recurring_coupon']['setup'];

        if ($setup['mollie_amount'] !== '12.00') {
            $this->problems[] = "recurring_coupon: Mollie held {$setup['mollie_amount']} after the code was redeemed, expected 12.00 (50 % of 24.00)";
        }

        foreach ($paidRenewals as $n => $row) {
            $discounted = $n < 2;
            $expected = $discounted ? '12.00' : '24.00';
            $couponLines = count(array_filter(
                (array) ($row['invoices'][0]['lines'] ?? []),
                static fn ($line): bool => ($line['kind'] ?? null) === 'coupon',
            ));

            if ($row['amount'] !== $expected) {
                $this->problems[] = 'recurring_coupon: renewal '.($n + 1)." charged {$row['amount']}, expected {$expected}";
            }

            if ($couponLines !== ($discounted ? 1 : 0)) {
                $this->problems[] = 'recurring_coupon: renewal '.($n + 1)." has {$couponLines} coupon lines, expected ".($discounted ? 1 : 0);
            }
        }

        $code = (string) ($this->state['scenarios']['recurring_coupon']['coupon_code'] ?? '');
        $redemptions = CouponRedemption::query()
            ->whereHas('coupon', static fn ($q) => $q->where('code', $code))
            ->count();
        $expectedRedemptions = 1 + min(2, count($paidRenewals));

        if ($redemptions !== $expectedRedemptions) {
            $this->problems[] = "recurring_coupon: {$redemptions} redemption rows, expected {$expectedRedemptions} (the grant, one per discounted renewal)";
        }

        if (count($paidRenewals) >= 2) {
            $mollie = $this->mollieSubscription($billable);

            if ((string) $mollie->amount->value !== '24.00') {
                $this->problems[] = "recurring_coupon: after the second discounted charge Mollie holds {$mollie->amount->value}, expected the full 24.00";
            }

            if (isset($billable->getBillingSubscriptionMeta()['active_recurring_coupon'])) {
                $this->problems[] = 'recurring_coupon: the marker survived its last discounted charge';
            }
        }

        $this->checkMollieAgreesOnNextCharge('recurring_coupon', $billable);
    }

    /** @param array<string, array<string, mixed>> $rows */
    private function checkCancelAtPeriodEnd(TestBillable $billable, array $rows): void
    {
        $scenario = $this->state['scenarios']['cancel_at_period_end'];

        if ($scenario['setup']['status'] !== SubscriptionStatus::Cancelled->value || $scenario['setup']['mollie_status'] !== 'canceled') {
            $this->problems[] = "cancel_at_period_end: after the cancel the billable is {$scenario['setup']['status']} and Mollie {$scenario['setup']['mollie_status']}";
        }

        if ($rows !== []) {
            $this->problems[] = 'cancel_at_period_end: Mollie charged a subscription cancelled at the period end: '.implode(', ', array_keys($rows));
        }

        $endsAt = $scenario['setup']['ends_at'] ?? null;

        if ($endsAt === null || substr($endsAt, 0, 10) !== $this->state['first_charge_on']) {
            $this->problems[] = 'cancel_at_period_end: access ends '.json_encode($endsAt).', expected the period end '.$this->state['first_charge_on'];

            return;
        }

        // Expired by the first nightly pass after the end.
        $jobsAfterEnd = array_filter($this->state['jobs_runs'] ?? [], static fn (string $at): bool => $at > $endsAt);

        if ($jobsAfterEnd !== []) {
            if ($billable->subscription_status !== SubscriptionStatus::Expired) {
                $this->problems[] = "cancel_at_period_end: the nightly pass ran after the end ({$endsAt}) and the subscription is {$billable->subscription_status?->value}, not expired";
            } else {
                $this->milestone('cancel_at_period_end', 'expired', 'cancel_at_period_end: expired after its end, never charged');
            }
        } elseif ($billable->subscription_status !== SubscriptionStatus::Cancelled) {
            $this->problems[] = "cancel_at_period_end: before its end the subscription is {$billable->subscription_status?->value}, not cancelled";
        }
    }

    /** @param list<array<string, mixed>> $renewals */
    private function checkFailedRenewal(TestBillable $billable, array $renewals): void
    {
        $failed = array_values(array_filter($renewals, static fn (array $r): bool => $r['status'] === 'failed'))[0] ?? null;

        if ($failed === null) {
            return;
        }

        if ($failed['subscription_status_after'] !== SubscriptionStatus::PastDue->value) {
            $this->problems[] = "failed_renewal: after the failed renewal the subscription is {$failed['subscription_status_after']}, not past_due";
        }

        if (! collect($this->state['events'] ?? [])->merge($this->events)->contains(fn (array $e): bool => $e['event'] === 'PaymentFailed' && $e['scenario'] === 'failed_renewal')) {
            $this->problems[] = 'failed_renewal: no PaymentFailed event for the failed renewal';
        }

        $since = $billable->getBillingSubscriptionMeta()['past_due_since'] ?? ($this->state['scenarios']['failed_renewal']['past_due_since'] ?? null);

        if (is_string($since) && $since !== '') {
            $this->state['scenarios']['failed_renewal']['past_due_since'] ??= $since;
        }

        $since = $this->state['scenarios']['failed_renewal']['past_due_since'] ?? null;

        if (! is_string($since)) {
            $this->problems[] = 'failed_renewal: past_due_since was never set';

            return;
        }

        // past_due_max_days = 2: the first nightly pass after that cancels, the next expires.
        $cutoff = CarbonImmutable::parse($since)->addDays(2)->toIso8601String();
        $runsAfter = array_values(array_filter($this->state['jobs_runs'] ?? [], static fn (string $at): bool => $at > $cutoff));
        $status = $billable->subscription_status;

        if ($runsAfter === []) {
            if ($status !== SubscriptionStatus::PastDue) {
                $this->problems[] = "failed_renewal: within the dunning window the subscription is {$status?->value}, not past_due";
            }

            return;
        }

        if (count($runsAfter) === 1 && $status !== SubscriptionStatus::Cancelled) {
            $this->problems[] = "failed_renewal: the nightly pass after past_due_max_days left it {$status?->value}, not cancelled";
        }

        if (count($runsAfter) >= 2 && $status !== SubscriptionStatus::Expired) {
            $this->problems[] = "failed_renewal: two nightly passes after the auto-cancel and it is {$status?->value}, not expired";
        }

        if ($status === SubscriptionStatus::Cancelled || $status === SubscriptionStatus::Expired) {
            $this->milestone('failed_renewal', $status->value, "failed_renewal: {$status->value} after dunning");

            foreach ($this->state['scenarios']['failed_renewal']['subscription_ids'] as $subscriptionId) {
                $mollie = Mollie::send(new GetSubscriptionRequest((string) $this->state['scenarios']['failed_renewal']['customer_id'], $subscriptionId));

                if (in_array((string) $mollie->status, ['active', 'pending'], true)) {
                    $this->problems[] = "failed_renewal: auto-cancelled, but Mollie's subscription {$subscriptionId} is still {$mollie->status}";
                }
            }
        }
    }

    /** For a running subscription, Mollie's next charge is the package's next billing date. */
    private function checkMollieAgreesOnNextCharge(string $scenario, TestBillable $billable): void
    {
        if (! in_array($billable->subscription_status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true)) {
            return;
        }

        $mollie = $this->mollieSubscription($billable);
        $ours = $billable->nextBillingDate()?->toDateString();
        $theirs = (string) ($mollie->nextPaymentDate ?? '');

        if ($ours !== $theirs) {
            $this->problems[] = "{$scenario}: Mollie's next payment date is {$theirs}, the package's next billing date ".json_encode($ours);
        }
    }

    private function milestone(string $scenario, string $name, string $observation): void
    {
        if (isset($this->state['scenarios'][$scenario]['milestones'][$name])) {
            return;
        }

        $this->state['scenarios'][$scenario]['milestones'][$name] = CarbonImmutable::now('UTC')->toIso8601String();
        $this->observations[] = $observation;
    }

    private function recordSubscriptionIds(string $scenario, TestBillable $billable): void
    {
        $id = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');
        $ids = $this->state['scenarios'][$scenario]['subscription_ids'] ?? [];

        if ($id !== '' && ! in_array($id, $ids, true)) {
            $ids[] = $id;
            $this->observations[] = "{$scenario}: Mollie subscription replaced, now {$id}";
        }

        $this->state['scenarios'][$scenario]['subscription_ids'] = $ids;
    }

    // ── reading the world ────────────────────────────────────────────────────

    private function billable(string $scenario): TestBillable
    {
        /** @var TestBillable */
        return TestBillable::query()->findOrFail($this->state['scenarios'][$scenario]['billable_id']);
    }

    private function mollieSubscription(TestBillable $billable): object
    {
        $id = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');

        if ($id === '') {
            $ids = [];

            foreach ($this->state['scenarios'] ?? [] as $row) {
                if (($row['billable_id'] ?? null) === $billable->getKey()) {
                    $ids = $row['subscription_ids'] ?? [];
                }
            }

            $id = (string) (end($ids) ?: '');
        }

        return Mollie::send(new GetSubscriptionRequest((string) $billable->getMollieCustomerId(), $id));
    }

    /** @return array<string, int> */
    private function wallets(TestBillable $billable): array
    {
        $balances = [];

        foreach (['tokens', 'credits'] as $type) {
            $wallet = $billable->fresh()?->getWallet($type);
            $balances[$type] = $wallet === null ? 0 : (int) $wallet->balanceInt;
        }

        return $balances;
    }

    /** @return list<array<string, mixed>> */
    private function invoicesFor(string $paymentId): array
    {
        return BillingInvoice::query()
            ->where('mollie_payment_id', $paymentId)
            ->orderBy('id')
            ->get()
            ->map(static fn (BillingInvoice $i): array => [
                'id' => $i->id,
                'serial' => $i->serial_number,
                'kind' => $i->invoice_kind instanceof \BackedEnum ? $i->invoice_kind->value : (string) $i->invoice_kind,
                'gross' => (int) $i->amount_gross,
                'net' => (int) $i->amount_net,
                'period_start' => $i->period_start?->toIso8601String(),
                'period_end' => $i->period_end?->toIso8601String(),
                'lines' => array_map(static fn ($l): array => [
                    'kind' => $l['kind'] ?? null,
                    'amount_net' => $l['amount_net'] ?? null,
                    'quantity' => $l['quantity'] ?? null,
                ], (array) $i->line_items),
            ])
            ->all();
    }

    /** What a visit may not change when nothing happened. */
    private function fingerprint(): string
    {
        return md5(json_encode([
            DB::table('test_billables')->orderBy('id')->get()->map(static function ($row): array {
                $row = (array) $row;
                unset($row['updated_at']);

                return $row;
            })->all(),
            DB::table('billing_invoices')->orderBy('id')->get(['id', 'mollie_payment_id', 'amount_gross', 'invoice_kind'])->all(),
            DB::table('wallets')->orderBy('id')->get(['id', 'holder_id', 'slug', 'balance'])->all(),
            DB::table('coupon_redemptions')->count(),
            DB::table('billing_processed_webhooks')->orderBy('id')->get(['mollie_payment_id', 'event_signature'])->all(),
        ]) ?: '');
    }

    private function listen(): void
    {
        Event::listen([
            \GraystackIT\MollieBilling\Events\PaymentAmountMismatch::class,
            \GraystackIT\MollieBilling\Events\PaymentFailed::class,
            \GraystackIT\MollieBilling\Events\PaymentSucceeded::class,
            \GraystackIT\MollieBilling\Events\OverageCharged::class,
            \GraystackIT\MollieBilling\Events\OverageChargeFailed::class,
            \GraystackIT\MollieBilling\Events\PlanChanged::class,
            \GraystackIT\MollieBilling\Events\SubscriptionCancelled::class,
            \GraystackIT\MollieBilling\Events\SubscriptionExpired::class,
            \GraystackIT\MollieBilling\Events\SubscriptionChangeScheduled::class,
            \GraystackIT\MollieBilling\Events\SubscriptionChangeApplyFailed::class,
            \GraystackIT\MollieBilling\Events\MollieSubscriptionReplaced::class,
            \GraystackIT\MollieBilling\Events\RenewalUndercollected::class,
            \GraystackIT\MollieBilling\Events\RenewalCollectedForEndedSubscription::class,
            \GraystackIT\MollieBilling\Events\UnwantedPaymentRefunded::class,
            \GraystackIT\MollieBilling\Events\CouponRedeemed::class,
        ], function (object $event): void {
            $billable = $event->billable ?? null;
            $scenario = null;

            foreach ($this->state['scenarios'] ?? [] as $name => $row) {
                if (is_object($billable) && method_exists($billable, 'getKey') && ($row['billable_id'] ?? null) === $billable->getKey()) {
                    $scenario = $name;
                }
            }

            $this->events[] = [
                'event' => class_basename($event),
                'scenario' => $scenario,
                'at' => CarbonImmutable::now('UTC')->toIso8601String(),
            ];
        });

        Event::listen(MessageLogged::class, function (MessageLogged $log): void {
            if (in_array($log->level, ['error', 'critical', 'alert', 'emergency', 'warning'], true)
                && ! str_contains($log->message, 'deprecated')) {
                $this->logged[] = $log->level.': '.mb_substr($log->message.' '.json_encode(array_diff_key($log->context, ['trace' => true])), 0, 400);
            }
        });
    }

    private function previousVisitAt(): ?string
    {
        $visits = $this->state['visits'] ?? [];

        return $visits === [] ? null : (string) end($visits)['at'];
    }

    // ── state ────────────────────────────────────────────────────────────────

    /** @return array<string, mixed>|null */
    private function readState(): ?array
    {
        $path = (string) self::worldPath('.json');

        if (! is_file($path)) {
            return null;
        }

        $state = json_decode((string) file_get_contents($path), true);

        return is_array($state) ? $state : null;
    }

    private function writeState(): void
    {
        $path = (string) self::worldPath('.json');
        file_put_contents($path, json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    private function reset(): void
    {
        $state = $this->readState();

        foreach ([$state ?? [], ...($state['previous_worlds'] ?? [])] as $world) {
            $this->tearDownAtMollie($world);
        }

        $this->removeWorldFiles(keepJson: false);
    }

    /**
     * Cancel, revoke, delete — whatever of a world still exists at Mollie. Never fails: what
     * is already gone is the point.
     *
     * @param  array<string, mixed>  $state
     */
    private function tearDownAtMollie(array $state): void
    {
        foreach ($state['scenarios'] ?? [] as $row) {
            $customerId = (string) ($row['customer_id'] ?? '');

            if ($customerId === '') {
                continue;
            }

            try {
                foreach (Mollie::send(new GetPaginatedSubscriptionsRequest($customerId)) as $subscription) {
                    if (in_array((string) ($subscription->status ?? ''), ['active', 'pending', 'suspended'], true)) {
                        Mollie::send(new CancelSubscriptionRequest($customerId, (string) $subscription->id));
                    }
                }
            } catch (\Throwable) {
                // the customer may be gone already
            }

            foreach ([
                fn () => Mollie::send(new RevokeMandateRequest($customerId, (string) ($row['mandate_id'] ?? ''))),
                fn () => Mollie::send(new DeleteCustomerRequest($customerId)),
            ] as $step) {
                try {
                    $step();
                } catch (\Throwable) {
                    // already gone
                }
            }
        }
    }

    private function removeWorldFiles(bool $keepJson): void
    {
        DB::disconnect('testing');

        foreach ($keepJson ? ['.sqlite'] : ['.json', '.sqlite'] as $suffix) {
            @unlink((string) self::worldPath($suffix));
        }

        $invoices = (string) self::worldPath('-invoices');

        if (is_dir($invoices)) {
            (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($invoices);
        }
    }
}
