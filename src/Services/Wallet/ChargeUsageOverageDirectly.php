<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Wallet;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use GraystackIT\MollieBilling\Support\MollieCall;
use GraystackIT\MollieBilling\Support\MollieRefusal;

class ChargeUsageOverageDirectly
{
    use MutatesBillingMeta;

    /**
     * `subscription_meta.usage_overage_status` value meaning "a charge exists that
     * has not been confirmed yet". The daily PrepareUsageOverageJob sweep looks
     * for exactly this value.
     */
    public const STATUS_PENDING = 'pending';

    /**
     * `usage_overage_status` value meaning "the dunning budget for this charge is
     * spent". Set by RetryUsageOverageChargeJob when it gives up; the snapshot is
     * deliberately kept so the amount stays auditable, which is why the next cycle
     * has to recognise it as finished rather than inherit it.
     */
    public const STATUS_FAILED = 'failed';

    /** `subscription_meta` key holding the snapshot of the charge in flight. */
    public const META_KEY = 'usage_overage';

    /**
     * `subscription_meta` key under which the dunning budget records which payments
     * of the CURRENT cycle it has already counted.
     */
    public const COUNTED_PAYMENTS_KEY = 'usage_overage_counted_payments';

    /**
     * Snapshot key meaning "we asked Mollie to create this payment and never learned the
     * answer" — as opposed to a `payment_id: null` that means "we never got as far as asking".
     *
     * The two look identical in the snapshot and must not be treated alike. `charge()` writes
     * the snapshot, calls Mollie, then stores the id; a throw in between (a read timeout on
     * the response is the ordinary case) leaves no id although the payment may well exist.
     * Reading that as "never charged" is what let the debt be restored to the wallet and
     * charged a second time by the next nightly sweep, while the first payment settled into
     * its own invoice. So the marker is set instead, nobody charges while it is present, and
     * `CleanupStalePendingOverageChargeJob` resolves it against Mollie's own payment list —
     * the only place that knows whether the request arrived.
     */
    public const PAYMENT_UNKNOWN_KEY = 'payment_unknown_at';

    /**
     * Snapshot key listing the payments of this dunning cycle that came before the one in
     * `payment_id` — every one of them over (failed, expired, cancelled).
     *
     * `payment_id` names the payment *in flight*, and only that one. The retry after a failed
     * payment used to keep the failed id there while it created the next payment, which made
     * the next payment's create indistinguishable from the old one: it was sent with the same
     * idempotency key (and Mollie, inside its hour, answered with the failed payment), a throw
     * could not be marked as {@see PAYMENT_UNKNOWN_KEY} because an id was "already there", the
     * nightly pass skipped the row for the same reason, and the stale sweep polled the old,
     * failed payment and wrote the cycle off while the new one might be collecting. Moving the
     * id here when the next payment is asked for keeps the cycle's history — for the key and
     * for telling a lost payment apart from its predecessors — without pretending it is live.
     */
    public const PREVIOUS_PAYMENTS_KEY = 'previous_payment_ids';

    /**
     * Snapshot key: the refusals Mollie gave the create request of this cycle
     * (`['key' => stable key, 'refusals' => n, 'at' => …]`, see {@see MollieRefusal}).
     *
     * A 4xx binds its idempotency key to the refusal for an hour (measured 26 November 2026),
     * and `RetryUsageOverageChargeJob`'s whole ladder (1, 5, 15 minutes) lies inside that
     * hour: every retry got the first refusal replayed, even after the customer replaced
     * the mandate. Each definite refusal moves the key on; a timeout, 5xx or lost answer
     * does not. Bound to the stable key it was counted for, so a new amount or a new
     * payment of the cycle starts from none. A definite refusal also leaves no
     * {@see PAYMENT_UNKNOWN_KEY}: the outcome is known, so the debt is not frozen.
     */
    public const KEY_REFUSALS_KEY = 'key_refusals';

    /**
     * How many later renewals try to collect a debt again after its dunning cycle gave up.
     *
     * A cycle that spent its budget used to be the end: the operators were mailed, and the
     * next renewal's reset wrote the debt off. That is work for a person in a case a
     * machine can settle — most failed overage payments are a limit or an expired card,
     * and both are fixed by the customer within a period or two. So the debt stands (see
     * {@see deferFailedCycle()}) and each of the next renewals starts one fresh cycle for
     * it. Only when this many of them have failed as well is it written off for good, and
     * only then is anybody told.
     */
    public const MAX_RENEWAL_RECOLLECTIONS = 3;

    /** `subscription_meta` key counting the renewal re-collections of the standing debt. */
    public const RECOLLECTIONS_KEY = 'usage_overage_recollections';

    /**
     * Days a debt rests after a cycle gave up before a renewal may try again.
     *
     * The sweep charges the night before a renewal, so a cycle that fails does so hours
     * before that renewal arrives — and trying again the next morning is a fourth attempt
     * on a payment method that has just refused three. With this the renewal of the same
     * boundary stands down and the next one, a period later, collects.
     */
    public const RECOLLECT_AFTER_DAYS = 3;

    /**
     * Since when the subscription is past due **because of a standing overage debt**.
     *
     * Deliberately not `past_due_since`: that key is the clock of a failed *renewal*, and
     * `past_due_max_days` after it the subscription is cancelled. A customer whose
     * renewals are paid and who owes a few units of overage is locked (no new bookings)
     * but not on that clock — the debt has its own end, {@see MAX_RENEWAL_RECOLLECTIONS}.
     * If a renewal fails as well, `past_due_since` is set by the renewal handler as for
     * anybody else and the ordinary dunning runs beside this.
     */
    public const PAST_DUE_KEY = 'overage_past_due_since';

    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
        private readonly VatCalculationService $vatService,
        private readonly InvoiceService $invoiceService,
    ) {
    }

    /**
     * Iterate the billable's wallets, build line items from any negative
     * balances, and create a Mollie payment for the gross amount. The
     * resulting BillingInvoice is created later, in the webhook.
     *
     * The result always says which of the three possible things happened —
     * "nothing owed" and "payment created" used to be indistinguishable.
     */
    public function handle(Billable $billable): UsageOverageChargeResult
    {
        // The wallets stay negative until the charge that covers them settles — with
        // SEPA that is days, not seconds — so rebuilding line items from them while a
        // payment is in flight bills the same debt again. `safeCharge()` and
        // `handleExplicit()` both guarded that; this entry point did not, and it is the
        // one an immediate cancellation goes through: the nightly sweep created the
        // period-end payment, the customer cancelled the next morning, and Mollie
        // collected both. The webhook cannot absorb it either — its idempotency is per
        // payment id, so each settles into its own invoice and credits the units again.
        if ($this->anotherChargeIsInFlight($billable)) {
            return UsageOverageChargeResult::nothingToCharge();
        }

        // A debt left standing by a failed cycle is collected again from here — the
        // renewal and the last sweep before a subscription ends both arrive through this
        // method — but not before it has rested: see RECOLLECT_AFTER_DAYS.
        if ($this->deferredDebtLines($billable) !== []) {
            // While it rests nothing is charged at all: there is one slot, the debt is in
            // it, and new overage on the wallets is carried by the reset until then.
            return $this->recollect($billable, includeStandingDebt: $this->deferredDebtIsDue($billable));
        }

        return $this->chargeFromWallets($billable);
    }

    // ── A debt whose dunning cycle gave up ────────────────────────────────────

    /**
     * An operator asks for the standing debt to be collected now.
     *
     * One more cycle, exactly like the one a renewal starts, without waiting out
     * {@see RECOLLECT_AFTER_DAYS} — somebody has just spoken to the customer. It **counts**
     * as one of the {@see MAX_RENEWAL_RECOLLECTIONS} attempts: every cycle is up to three
     * payments against the same mandate, and an attempt that did not count would let the
     * button dun without end. Asked again while that cycle's payment is on its way,
     * nothing happens (the in-flight guard), and the idempotency key of the charge is
     * the cycle's, so a double click cannot become two payments either.
     *
     * @return 'started'|'in_flight'|'nothing'
     */
    public function collectStandingDebtNow(Billable $billable): string
    {
        if ($this->anotherChargeIsInFlight($billable)) {
            return 'in_flight';
        }

        if ($this->deferredDebtLines($billable) === []) {
            return 'nothing';
        }

        $result = $this->recollect($billable, includeStandingDebt: true);

        return $result->outcome === UsageOverageChargeOutcome::Charged ? 'started' : 'nothing';
    }

    /**
     * The lines of a debt that is standing after a failed cycle, or [] when there is none.
     *
     * @return array<int, array<string, mixed>>
     */
    public function deferredDebtLines(Billable $billable): array
    {
        $meta = $billable->getBillingSubscriptionMeta();

        if (($meta['usage_overage_status'] ?? null) !== self::STATUS_FAILED) {
            return [];
        }

        // Only lines that have left the wallet. A `failed` snapshot written before debts
        // were deferred still has its units ON the wallet (its lines settle it), and those
        // are found there by the ordinary wallet pass — counting them here as well would
        // charge them twice.
        $snapshot = (array) ($meta[self::META_KEY] ?? []);

        return array_values(array_map(
            // A debt keeps the period it was used in when a later renewal collects it.
            // Lines of a snapshot written before lines carried one take the snapshot's.
            static fn (array $line): array => $line + array_filter([
                'period_start' => $snapshot['period_start'] ?? null,
                'period_end' => $snapshot['period_end'] ?? null,
            ]),
            array_filter(
                (array) ($snapshot['line_items'] ?? []),
                static fn ($line): bool => is_array($line)
                    && ($line['settles_wallet'] ?? true) === false
                    && (int) ($line['total_net'] ?? 0) > 0,
            ),
        ));
    }

    /**
     * Give every line without a usage period the current one, and return the lines with
     * the span they cover.
     *
     * @param  array<int, array<string, mixed>>  $lineItems
     * @return array{0: array<int, array<string, mixed>>, 1: ?string, 2: ?string}
     */
    private static function stampUsagePeriods(array $lineItems, ?string $currentStart, ?string $currentEnd): array
    {
        $start = null;
        $end = null;

        foreach ($lineItems as $index => $line) {
            if (! is_array($line)) {
                continue;
            }

            $line['period_start'] = ($line['period_start'] ?? null) ?: $currentStart;
            $line['period_end'] = ($line['period_end'] ?? null) ?: $currentEnd;
            $lineItems[$index] = $line;

            // Each bound parsed on its own before it may set the span: an unreadable
            // value neither enters the span nor blocks the other bound.
            $lineStart = self::parsedPeriodBound($line['period_start']);
            $lineEnd = self::parsedPeriodBound($line['period_end']);

            if ($lineStart !== null && ($start === null || $lineStart->lessThan($start[1]))) {
                $start = [(string) $line['period_start'], $lineStart];
            }

            if ($lineEnd !== null && ($end === null || $lineEnd->greaterThan($end[1]))) {
                $end = [(string) $line['period_end'], $lineEnd];
            }
        }

        return [$lineItems, $start[0] ?? null, $end[0] ?? null];
    }

    /**
     * Where the current period's usage ends: the next billing date — unless the
     * subscription has already ended before it.
     *
     * An immediate cancellation (and the past-due auto-cancel, which is one) sets
     * `subscription_ends_at` to now and then charges the overage accrued so far. Stamped
     * with `nextBillingDate()`, that final invoice stated a period the subscription never
     * reaches: cancelled on 12 March, it said 1–31 March. The end is the exclusive bound of
     * the last day of service — 00:00 UTC of the day after the end, the same convention as
     * every period boundary, so the invoice prints "1–12 March" (service periods are UTC
     * billing days, the end the day before the bound; see EInvoiceService::servicePeriod()).
     *
     * Only a subscription that is over *now*: a graceful cancel is charged by the last
     * sweep the day before its end, which is the next billing date, and keeps the full
     * period. Never later than the next billing date either.
     */
    private static function currentUsagePeriodEnd(Billable $billable): ?\Carbon\CarbonInterface
    {
        $nextBilling = $billable->nextBillingDate();
        $endsAt = $billable->getBillingSubscriptionEndsAt();

        if ($nextBilling === null
            || $endsAt === null
            || ! in_array($billable->getBillingSubscriptionStatus(), [SubscriptionStatus::Cancelled, SubscriptionStatus::Expired], true)
            || BillingTime::toUtc($endsAt)->greaterThan(BillingTime::nowUtc())
        ) {
            return $nextBilling;
        }

        $endBound = BillingTime::toUtc($endsAt)->startOfDay()->addDay();
        $periodStart = $billable->getBillingPeriodStartsAt();

        // An end before this period began says nothing about this period's usage.
        if ($periodStart !== null && $endBound->lessThanOrEqualTo(BillingTime::toUtc($periodStart))) {
            return $nextBilling;
        }

        return $endBound->lessThan($nextBilling) ? $endBound : $nextBilling;
    }

    private static function parsedPeriodBound(mixed $value): ?\Carbon\CarbonInterface
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return BillingTime::toUtc($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The lines of a retry, each with the period of the cycle it belongs to.
     *
     * A retry re-charges the snapshot's own lines, possibly after the renewal. Lines
     * stamped by this version keep their period. A snapshot from before lines carried one
     * gets the snapshot's period, or — older still — the period the cycle started in:
     * one interval back from the current anchor when the cycle started before it. Without
     * this, the retry stamped them with the period it happened to run in.
     *
     * @param  array<string, mixed>  $pending
     * @param  array<int, array<string, mixed>>  $lineItems
     * @return array<int, array<string, mixed>>
     */
    private function withCyclePeriod(Billable $billable, array $pending, array $lineItems): array
    {
        $periodStart = $pending['period_start'] ?? null;
        $periodEnd = $pending['period_end'] ?? null;

        if (! is_string($periodStart) || ! is_string($periodEnd) || $periodStart === '' || $periodEnd === '') {
            $periodStart = $periodEnd = null;
            $cycleStartedAt = self::parsedPeriodBound($pending['created_at'] ?? null);
            $anchor = $billable->getBillingPeriodStartsAt();

            if ($cycleStartedAt !== null && $anchor !== null && $cycleStartedAt->lessThan($anchor)) {
                $periodStart = BillingTime::subInterval($anchor, $billable->getBillingSubscriptionInterval())->toIso8601String();
                $periodEnd = BillingTime::toUtc($anchor)->toIso8601String();
            }
        }

        if ($periodStart === null || $periodEnd === null) {
            return $lineItems;
        }

        foreach ($lineItems as $index => $line) {
            if (is_array($line) && empty($line['period_start']) && empty($line['period_end'])) {
                $lineItems[$index]['period_start'] = $periodStart;
                $lineItems[$index]['period_end'] = $periodEnd;
            }
        }

        return $lineItems;
    }

    /**
     * The standing debt as the customer would be charged for it: net and gross, or null.
     *
     * @return array{net:int, gross:int, currency:string}|null
     */
    public function deferredDebtAmount(Billable $billable): ?array
    {
        if ($this->deferredDebtLines($billable) === []) {
            return null;
        }

        $snapshot = (array) ($billable->getBillingSubscriptionMeta()[self::META_KEY] ?? []);

        return [
            'net' => (int) ($snapshot['amount_net'] ?? 0),
            'gross' => (int) ($snapshot['amount_gross'] ?? 0),
            'currency' => (string) ($snapshot['currency'] ?? config('mollie-billing.currency', 'EUR')),
        ];
    }

    /** Whether the standing debt has rested long enough for another attempt. */
    public function deferredDebtIsDue(Billable $billable, ?\Carbon\CarbonInterface $at = null): bool
    {
        if ($this->deferredDebtLines($billable) === []) {
            return false;
        }

        // `$at`: asked for a moment to come — "will the renewal on that day collect it?"
        $at = $at !== null ? BillingTime::toUtc($at) : BillingTime::nowUtc();

        $failedAt = $billable->getBillingSubscriptionMeta()[self::META_KEY]['failed_at'] ?? null;

        if (! is_string($failedAt) || $failedAt === '') {
            return true;
        }

        try {
            return BillingTime::toUtc($failedAt)->addDays(self::RECOLLECT_AFTER_DAYS)->lessThanOrEqualTo($at);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * A dunning cycle has given up: decide what becomes of the debt.
     *
     * While renewals still have attempts left, the debt **stands** — as a receivable in
     * the snapshot, and off the wallet. Off the wallet, because a negative balance is
     * netted against the next period's quota by the reset that preserves it, which would
     * have the customer pay in units for something we go on to collect in money; the
     * units are credited back here and the lines are marked `settles_wallet: false`, so
     * the payment that finally settles credits nothing a second time.
     *
     * Once {@see MAX_RENEWAL_RECOLLECTIONS} renewals have tried, it is written off for
     * good: the snapshot goes, `OverageWrittenOff` records what was given up, and the
     * caller tells the operators.
     *
     * Only call this for a cycle that is over at Mollie — every payment of it failed,
     * expired or was cancelled. A payment that could still settle must keep its slot.
     *
     * @return 'deferred'|'written_off'|'none'
     */
    public function deferFailedCycle(Billable $billable): string
    {
        if (! $billable instanceof Model) {
            return 'none';
        }

        $outcome = 'none';
        $creditBack = [];
        $writtenOff = null;

        $this->mutateBillingMeta($billable, function (array $meta) use (&$outcome, &$creditBack, &$writtenOff): ?array {
            $snapshot = (array) ($meta[self::META_KEY] ?? []);
            $lines = array_values(array_filter((array) ($snapshot['line_items'] ?? []), 'is_array'));

            if ($snapshot === [] || $lines === []) {
                // Nothing recorded to carry: the marker alone must not linger.
                unset($meta['usage_overage_status'], $meta['usage_overage_attempts'], $meta[self::COUNTED_PAYMENTS_KEY]);
                $outcome = 'none';

                return $meta;
            }

            // Units still on the wallet as debt leave it, once.
            foreach ($lines as $index => $line) {
                if (($line['settles_wallet'] ?? true) === false) {
                    continue;
                }

                $creditBack[] = ['type' => (string) ($line['type'] ?? $line['code'] ?? ''), 'quantity' => (int) ($line['quantity'] ?? 0)];
                $lines[$index]['settles_wallet'] = false;
            }

            $recollections = (int) ($meta[self::RECOLLECTIONS_KEY] ?? 0);

            if ($recollections >= self::MAX_RENEWAL_RECOLLECTIONS) {
                $writtenOff = [
                    'line_items' => $lines,
                    'amount_net' => (int) ($snapshot['amount_net'] ?? 0),
                    'amount_gross' => (int) ($snapshot['amount_gross'] ?? 0),
                    'attempts' => $recollections,
                ];

                unset(
                    $meta[self::META_KEY],
                    $meta['usage_overage_status'],
                    $meta['usage_overage_attempts'],
                    $meta[self::COUNTED_PAYMENTS_KEY],
                    $meta[self::RECOLLECTIONS_KEY],
                );
                $outcome = 'written_off';

                return $meta;
            }

            $snapshot['line_items'] = $lines;
            $snapshot['failed_at'] = BillingTime::nowUtc()->toIso8601String();
            unset($snapshot[self::PAYMENT_UNKNOWN_KEY]);

            $meta[self::META_KEY] = $snapshot;
            $meta['usage_overage_status'] = self::STATUS_FAILED;
            $outcome = 'deferred';

            return $meta;
        });

        // After the commit: the wallet has its own lock and its own transaction.
        foreach ($creditBack as $credit) {
            if ($credit['type'] !== '' && $credit['quantity'] > 0) {
                app(WalletUsageService::class)->credit($billable, $credit['type'], $credit['quantity'], WalletUsageService::DEBT_DEFERRED_REASON);
            }
        }

        if ($writtenOff !== null) {
            event(new \GraystackIT\MollieBilling\Events\OverageWrittenOff(
                $billable,
                $writtenOff['line_items'],
                $writtenOff['amount_net'],
                $writtenOff['amount_gross'],
                $writtenOff['attempts'],
            ));
        }

        // A debt that stands locks the subscription; one that is gone unlocks it.
        match ($outcome) {
            'deferred' => $this->markPastDueForDebt($billable),
            default => $this->releasePastDueForDebt($billable),
        };

        return $outcome;
    }

    /**
     * THE answer to "may this subscription leave past due now?".
     *
     * Every path that ends a dunning episode — a paid renewal, a paid plan change, a
     * reversed chargeback, an operator's trial extension — asks here what status to
     * write instead of writing `Active` (or `Trial`) itself. While an overage debt
     * stands the answer is PastDue, whatever else has just been settled: the lock ends
     * in exactly one place, {@see releasePastDueForDebt()}.
     *
     * @param  array<string, mixed>  $meta  the subscription meta about to be written
     */
    public static function statusAfterRecovery(array $meta, SubscriptionStatus $recovered): SubscriptionStatus
    {
        return isset($meta[self::PAST_DUE_KEY]) ? SubscriptionStatus::PastDue : $recovered;
    }

    /** Past due, and an overage debt is (one of) the reason(s). */
    public static function isPastDueForOverageDebt(Billable $billable): bool
    {
        return $billable->isBillingPastDue()
            && isset($billable->getBillingSubscriptionMeta()[self::PAST_DUE_KEY]);
    }

    /**
     * Past due for the overage debt and for nothing else: every renewal is paid.
     *
     * This is the state in which the things written for a *failed renewal* are wrong —
     * the period is paid, so there is nothing to "reset", no charge to bring forward and
     * no cancellation clock to run.
     */
    public static function isPastDueForOverageDebtOnly(Billable $billable): bool
    {
        if (! self::isPastDueForOverageDebt($billable)) {
            return false;
        }

        $meta = $billable->getBillingSubscriptionMeta();

        return empty($meta['past_due_since']) && empty($meta['payment_failure']);
    }

    /**
     * The dunning cycle of an overage charge gave up: the subscription is past due until
     * the debt is paid or written off. The customer books nothing new in that state
     * (`ValidateSubscriptionChange::assertNoPastDueExpansion()`).
     *
     * Only a subscription that is still running can be dunned — the same guard
     * ChargebackHandler and SubscriptionPaymentHandler::failed() apply. Forcing PastDue
     * onto a Cancelled row inside its grace period would undo the cancellation.
     */
    public function markPastDueForDebt(Billable $billable): void
    {
        if (! $billable instanceof Model) {
            return;
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($billable): void {
            $locked = $billable->newQuery()
                ->withoutGlobalScope(\GraystackIT\MollieBilling\Scopes\BillingScope::class)
                ->whereKey($billable->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof Model) {
                return;
            }

            $status = $locked->getBillingSubscriptionStatus();

            if (! in_array($status, [SubscriptionStatus::Active, SubscriptionStatus::Trial, SubscriptionStatus::PastDue], true)) {
                return;
            }

            $meta = (array) ($locked->subscription_meta ?? []);
            $meta[self::PAST_DUE_KEY] ??= BillingTime::nowUtc()->toIso8601String();

            $write = ['subscription_status' => SubscriptionStatus::PastDue, 'subscription_meta' => $meta];
            $locked->forceFill($write)->save();
            $billable->forceFill($write)->syncOriginal();
        });
    }

    /**
     * The overage debt is gone — paid, or written off. The lock it caused goes with it;
     * the subscription returns to Active unless a renewal is unpaid as well, in which
     * case the ordinary renewal dunning keeps it past due and decides what happens next.
     *
     * A no-op for a subscription that is not past due for an overage debt.
     */
    public function releasePastDueForDebt(Billable $billable): void
    {
        if (! $billable instanceof Model) {
            return;
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($billable): void {
            $locked = $billable->newQuery()
                ->withoutGlobalScope(\GraystackIT\MollieBilling\Scopes\BillingScope::class)
                ->whereKey($billable->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof Model) {
                return;
            }

            $meta = (array) ($locked->subscription_meta ?? []);

            if (! isset($meta[self::PAST_DUE_KEY])) {
                return;
            }

            unset($meta[self::PAST_DUE_KEY]);
            $write = ['subscription_meta' => $meta];

            if ($locked->getBillingSubscriptionStatus() === SubscriptionStatus::PastDue
                && empty($meta['past_due_since'])
                && empty($meta['payment_failure'])
            ) {
                $write['subscription_status'] = SubscriptionStatus::Active;
            }

            $locked->forceFill($write)->save();
            $billable->forceFill($write)->syncOriginal();
        });
    }

    /**
     * One more cycle for the standing debt, together with whatever the wallets owe now.
     */
    private function recollect(Billable $billable, bool $includeStandingDebt = true): UsageOverageChargeResult
    {
        if (! $includeStandingDebt) {
            // Not yet: leave the snapshot untouched, there is one slot and the debt is in it.
            return UsageOverageChargeResult::nothingToCharge();
        }

        return $this->charge($billable, [
            ...$this->deferredDebtLines($billable),
            ...$this->buildLineItemsFromWallets($billable),
        ]);
    }

    /**
     * Same as {@see handle()} but with caller-supplied line items. Used by the
     * plan-change path (WalletPlanChangeAdjuster) where the wallets have already
     * been rebalanced, so their balances no longer describe what is owed.
     *
     * @param  array<int, array{type:string,quantity:int,unit_price_net:int,total_net:int}>  $lineItems
     */
    public function handleExplicit(Billable $billable, array $lineItems): UsageOverageChargeResult
    {
        if ($lineItems === []) {
            return UsageOverageChargeResult::nothingToCharge();
        }

        // `subscription_meta.usage_overage` is a single slot, and this path had no in-flight
        // guard while `safeCharge()` does. A plan-change charge therefore overwrote a
        // period-end charge that was still settling — inheriting its `created_at`, so its
        // dunning cycle and its counted-payments list too. `RetryUsageOverageChargeJob`
        // resolves the snapshot rather than a payment id, so a failure webhook for the
        // overwritten payment then acted on the new one: the *new* line items were charged a
        // second time while the overwritten charge's units were never recovered and were
        // invisible even to the stale-snapshot sweep, which only polls the id in the slot.
        if ($this->anotherChargeIsInFlight($billable)) {
            // Not an exception: the plan change itself is legitimate and must go through. The
            // excess stays on the wallet as a negative balance, which the next period-end
            // sweep charges once the slot is free.
            return UsageOverageChargeResult::nothingToCharge();
        }

        // A debt standing from a failed cycle lives in the same single slot. Charging the
        // plan change on its own would overwrite it — so it travels with this charge.
        return $this->charge($billable, [...$this->deferredDebtLines($billable), ...$lineItems]);
    }

    /**
     * Whether `handleExplicit()` would refuse right now because another overage charge holds
     * the slot — the plan-change preview asks, so it can say that the excess it quotes is
     * collected with the next debit rather than with the change. Read-only and silent: the
     * refusal itself is logged where it happens.
     */
    public function chargeIsInFlight(Billable $billable): bool
    {
        return $this->anotherChargeIsInFlight($billable, log: false);
    }

    /**
     * Whether a previous overage charge is still waiting for its webhook.
     *
     * `subscription_meta.usage_overage` is a single slot, so a second charge does not
     * merely duplicate the money — it overwrites the first one's snapshot, inheriting
     * its `created_at`, its dunning cycle and its counted-payments list, and leaving the
     * overwritten payment invisible to `CleanupStalePendingOverageChargeJob`, which only
     * ever polls the id currently in the slot.
     */
    private function anotherChargeIsInFlight(Billable $billable, bool $log = true): bool
    {
        $meta = $billable->getBillingSubscriptionMeta();
        $inFlight = (array) ($meta[self::META_KEY] ?? []);
        $inFlightPaymentId = (string) ($inFlight['payment_id'] ?? '');

        if (($meta['usage_overage_status'] ?? null) !== self::STATUS_PENDING) {
            return false;
        }

        // A payment whose creation we never got an answer for counts as in flight, even though
        // there is no id to name it by. Otherwise this guard reads "no id" as "no payment" and
        // the next sweep charges a debt Mollie may already be collecting.
        if ($inFlightPaymentId === '' && isset($inFlight[self::PAYMENT_UNKNOWN_KEY])) {
            if ($log) {
                Log::warning('Refusing an overage charge while a previous one may already exist at Mollie', [
                    'billable' => $billable instanceof Model ? $billable->getKey() : null,
                    'unknown_since' => (string) $inFlight[self::PAYMENT_UNKNOWN_KEY],
                ]);
            }

            return true;
        }

        if ($inFlightPaymentId === '' || $this->pendingChargeIsSettled($billable)) {
            return false;
        }

        if ($log) {
            Log::warning('Refusing an overage charge while another is still in flight', [
                'billable' => $billable instanceof Model ? $billable->getKey() : null,
                'in_flight_payment_id' => $inFlightPaymentId,
            ]);
        }

        return true;
    }

    /**
     * Charge whatever the wallets currently owe, without the in-flight guard.
     *
     * Only the retry path may use this: it is driven by the snapshot of the charge
     * that is in flight, so refusing on account of that same snapshot would make the
     * retry a no-op forever.
     */
    private function chargeFromWallets(Billable $billable): UsageOverageChargeResult
    {
        $lineItems = $this->buildLineItemsFromWallets($billable);

        if ($lineItems === []) {
            return UsageOverageChargeResult::nothingToCharge();
        }

        return $this->charge($billable, $lineItems);
    }

    /**
     * Retry an overage charge without consulting the wallets.
     *
     * This is the entry point retries must use. Rebuilding from wallet balances
     * cannot work after `handleExplicit()`: the plan-change path zeroes the
     * wallets *before* charging, so a retry would find nothing owed and report
     * success while the money was never collected. The persisted snapshot is the
     * source of truth, and a snapshot whose payment already produced an invoice
     * is reported as settled instead of charged a second time.
     */
    public function retry(Billable $billable): UsageOverageChargeResult
    {
        $pending = $this->pendingCharge($billable);

        if ($pending !== null && $this->pendingChargeIsSettled($billable)) {
            return UsageOverageChargeResult::alreadySettled(
                $pending['payment_id'] ?? null,
                (array) ($pending['line_items'] ?? []),
            );
        }

        $lineItems = (array) ($pending['line_items'] ?? []);

        if ($lineItems !== []) {
            return $this->charge($billable, $this->withCyclePeriod($billable, (array) $pending, $lineItems));
        }

        return $this->chargeFromWallets($billable);
    }

    /**
     * The snapshot of the overage charge in flight, or null when there is none.
     *
     * Exposed so the retry job and the daily sweep can inspect `payment_id`
     * before creating another payment.
     *
     * @return array<string, mixed>|null
     */
    public function pendingCharge(Billable $billable): ?array
    {
        if (! $billable instanceof Model) {
            return null;
        }

        $pending = $billable->getBillingSubscriptionMeta()[self::META_KEY] ?? null;

        return is_array($pending) && $pending !== [] ? $pending : null;
    }

    /**
     * Whether the pending charge's Mollie payment already produced an invoice.
     *
     * `SingleChargeHandler::paid()` normally clears the whole snapshot, so this
     * is the safety net for the case where the webhook created the invoice but
     * died before clearing it — without the check, the next retry pass would
     * charge the customer twice for the same overage.
     */
    /**
     * Whether a charge was handed to Mollie whose outcome we never learned.
     *
     * The one question that separates "this debt was never charged" from "this debt may already
     * be being collected". Every caller that would otherwise re-derive the debt from the
     * wallets, or hand it back to them, has to ask it — see
     * {@see self::PAYMENT_UNKNOWN_KEY}.
     */
    public function paymentMayExist(Billable $billable): bool
    {
        $meta = $billable->getBillingSubscriptionMeta();

        if (($meta['usage_overage_status'] ?? null) !== self::STATUS_PENDING) {
            return false;
        }

        $snapshot = (array) ($meta[self::META_KEY] ?? []);

        return (string) ($snapshot['payment_id'] ?? '') === ''
            && isset($snapshot[self::PAYMENT_UNKNOWN_KEY]);
    }

    /**
     * Record that a create-payment request left this process without an answer.
     *
     * Kept on the snapshot rather than beside it, so it travels with the charge it describes
     * and disappears with it — `persistPendingCharge()` rewrites the whole snapshot when an id
     * finally arrives, which is exactly when the marker stops being true.
     */
    private function markPaymentUnknown(Billable $billable): void
    {
        if (! $billable instanceof Model) {
            return;
        }

        $this->mutateBillingMeta($billable, function (array $meta): ?array {
            $snapshot = (array) ($meta[self::META_KEY] ?? []);

            if ($snapshot === [] || (string) ($snapshot['payment_id'] ?? '') !== '') {
                // Either there is no snapshot to mark, or an id arrived in the meantime — a
                // sibling delivery, or the retry that succeeded. Both make the marker false.
                return null;
            }

            $snapshot[self::PAYMENT_UNKNOWN_KEY] ??= BillingTime::nowUtc()->toIso8601String();
            $meta[self::META_KEY] = $snapshot;

            return $meta;
        });
    }

    /** Count a definite refusal of the create request on the snapshot. Never throws. */
    private function recordKeyRefusal(Billable $billable, string $stableKey): void
    {
        if (! $billable instanceof Model) {
            return;
        }

        rescue(function () use ($billable, $stableKey): void {
            $this->mutateBillingMeta($billable, static function (array $meta) use ($stableKey): ?array {
                $snapshot = (array) ($meta[self::META_KEY] ?? []);

                if ($snapshot === [] || (string) ($snapshot['payment_id'] ?? '') !== '') {
                    return null;
                }

                $snapshot[self::KEY_REFUSALS_KEY] = MollieRefusal::recordRefusal($snapshot[self::KEY_REFUSALS_KEY] ?? null, $stableKey);
                $meta[self::META_KEY] = $snapshot;

                return $meta;
            });
        }, null, true);
    }

    /**
     * Drop the marker because Mollie has answered: no such payment exists.
     *
     * Only `CleanupStalePendingOverageChargeJob` may call this, and only after Mollie itself
     * has listed the customer's payments — clearing it on a guess would re-open the double
     * charge the marker exists to prevent.
     */
    public function forgetUnknownPayment(Billable $billable): void
    {
        if (! $billable instanceof Model) {
            return;
        }

        $this->mutateBillingMeta($billable, function (array $meta): ?array {
            $snapshot = (array) ($meta[self::META_KEY] ?? []);

            if (! isset($snapshot[self::PAYMENT_UNKNOWN_KEY])) {
                return null;
            }

            unset($snapshot[self::PAYMENT_UNKNOWN_KEY]);
            $meta[self::META_KEY] = $snapshot;

            return $meta;
        });
    }

    /**
     * Adopt a payment Mollie turned out to hold for a snapshot that lost its id.
     *
     * The snapshot keeps its `created_at` — this is the same cycle, not a new one — so the
     * dunning budget and the counted-payments list stay intact.
     */
    public function adoptUnknownPayment(Billable $billable, string $paymentId): void
    {
        if (! $billable instanceof Model || $paymentId === '') {
            return;
        }

        $this->mutateBillingMeta($billable, function (array $meta) use ($paymentId): ?array {
            $snapshot = (array) ($meta[self::META_KEY] ?? []);

            if ($snapshot === [] || (string) ($snapshot['payment_id'] ?? '') !== '') {
                return null;
            }

            $snapshot['payment_id'] = $paymentId;
            unset($snapshot[self::PAYMENT_UNKNOWN_KEY]);
            $meta[self::META_KEY] = $snapshot;

            return $meta;
        });
    }

    public function pendingChargeIsSettled(Billable $billable): bool
    {
        $paymentId = (string) ($this->pendingCharge($billable)['payment_id'] ?? '');

        if ($paymentId === '') {
            return false;
        }

        return BillingInvoice::query()->where('mollie_payment_id', $paymentId)->exists();
    }

    /**
     * @return array<int, array{type:string,quantity:int,unit_price_net:int,total_net:int}>
     */
    private function buildLineItemsFromWallets(Billable $billable): array
    {
        if (! $billable instanceof Model) {
            return [];
        }

        $items = [];
        $planCode = $billable->getBillingSubscriptionPlanCode() ?? '';
        $interval = $billable->getBillingSubscriptionInterval();

        // Force a fresh fetch — wallets is a MorphMany.
        $wallets = $billable->wallets()->get();

        foreach ($wallets as $wallet) {
            $balance = (int) $wallet->balanceInt;

            if ($balance >= 0) {
                continue;
            }

            $slug = (string) $wallet->slug;
            $quantity = abs($balance);

            // Debt that carries its own price goes first, and at that price: plan-change
            // excess that could not be charged when the plan changed was consumed on the
            // OLD plan. Priced from the current plan it was billed 4.5 times too high in
            // the reproduction, and not at all when the new plan has no overage price.
            foreach (WalletUsageService::getDeferredOverage($wallet) as $deferred) {
                $items[] = [
                    'kind' => 'overage',
                    'code' => $slug,
                    'type' => $slug,
                    'label' => $this->overageLabel($slug),
                    'quantity' => $deferred['quantity'],
                    'unit_price_net' => $deferred['unit_price_net'],
                    'total_net' => $deferred['quantity'] * $deferred['unit_price_net'],
                    // Tells the settlement which record these units came from.
                    'deferred_price' => true,
                ];

                $quantity -= $deferred['quantity'];
            }

            if ($quantity <= 0) {
                continue;
            }

            // What is left is ordinary period overage, priced by the plan the billable is on.
            $unitPrice = (int) ($this->catalog->usageOveragePrice($planCode, $interval, $slug) ?? 0);

            if ($unitPrice <= 0) {
                continue;
            }

            $items[] = [
                'kind' => 'overage',
                'code' => $slug,
                'type' => $slug,
                'label' => $this->overageLabel($slug),
                'quantity' => $quantity,
                'unit_price_net' => $unitPrice,
                'total_net' => $quantity * $unitPrice,
            ];
        }

        return $items;
    }

    /**
     * Localised, human-readable overage line label, e.g. "Mehrverbrauch: SMS".
     * Quantity is intentionally not included — it is rendered separately in the
     * invoice's "Menge" column.
     */
    private function overageLabel(string $usageType): string
    {
        return (string) __('billing::portal.invoice_line_overage', [
            'type' => $this->catalog->usageTypeName($usageType),
        ]);
    }

    /**
     * @param  array<int, array{type:string,quantity:int,unit_price_net:int,total_net:int}>  $lineItems
     */
    private function charge(Billable $billable, array $lineItems): UsageOverageChargeResult
    {
        $totalNet = array_sum(array_column($lineItems, 'total_net'));

        if ($totalNet <= 0) {
            return UsageOverageChargeResult::nothingToCharge();
        }

        // Shared fallback with checkout and the webhook: the country decides the
        // rate actually charged, so all sites must agree on it. Not
        // `default_billing_country`, which only pre-selects a dropdown.
        $country = $billable->getBillingCountry()
            ?? (string) config('mollie-billing.checkout_country_fallback', 'DE');

        $vat = $this->vatService->calculate($country, $totalNet, $billable);

        // The invoice the webhook writes for this payment derives its VAT from the
        // line items, one wallet at a time, so the amount we collect has to be built
        // the same way — see BillingMoney::grossWithLineVat(). With one line per
        // metered usage type the rounding residuals add up fast enough to trip
        // reconcileWithPaidAmount() on an otherwise perfectly ordinary charge. The
        // snapshot carries the same numbers, so a retry re-charges what was booked.
        $gross = BillingMoney::grossWithLineVat(
            array_map(static fn (array $line): int => (int) ($line['total_net'] ?? 0), $lineItems),
            (float) $vat['rate'],
        );
        $vat = [...$vat, 'vat' => $gross - $totalNet, 'gross' => $gross];

        // Persist the snapshot BEFORE talking to Mollie and mark the charge
        // pending. If the API call fails the amount owed is still on record, so
        // the daily sweep can pick it up instead of the charge evaporating with
        // the wallet balances it was derived from.
        $this->persistPendingCharge($billable, null, $lineItems, $vat);

        $stableKey = $this->idempotencyKeyFor($billable, $vat);
        $idempotencyKey = $stableKey === null
            ? null
            : MollieRefusal::key($stableKey, MollieRefusal::refusalsFor(($this->pendingCharge($billable) ?? [])[self::KEY_REFUSALS_KEY] ?? null, $stableKey));

        try {
            $payment = $this->createMolliePayment($billable, $vat, $lineItems, $idempotencyKey);
        } catch (\Throwable $e) {
            // Mollie answered with a refusal: no payment, and the key is bound to the
            // refusal. The retry carries a new one (see KEY_REFUSALS_KEY). Nothing was
            // charged and that is known, so the debt is NOT frozen (maintainer's decision,
            // 27 November 2026): no marker, the snapshot stays `pending` without an id and
            // the ordinary retry ladder — RetryUsageOverageChargeJob, pass 3 of the nightly
            // sweep — tries again at once instead of waiting for the stale sweep to ask
            // Mollie a question whose answer is already in hand.
            if (MollieRefusal::isDefinite($e)) {
                if ($stableKey !== null) {
                    $this->recordKeyRefusal($billable, $stableKey);
                }

                throw $e;
            }

            // The request left this process. Whether Mollie created the payment is now unknown
            // — and "unknown" must not be read as "no", because the next sweep would charge the
            // same debt again while the first payment settles into its own invoice. The marker
            // freezes the debt for `CleanupStalePendingOverageChargeJob` to reconcile against
            // Mollie's payment list, and the throw still reaches the caller so the retry ladder
            // runs (inside Mollie's one-hour idempotency window it is answered with the
            // original payment rather than a second one).
            $this->markPaymentUnknown($billable);

            throw $e;
        }

        $paymentId = is_object($payment) ? ($payment->id ?? null) : null;
        $this->persistPendingCharge($billable, is_string($paymentId) ? $paymentId : null, $lineItems, $vat);

        // The actual BillingInvoice is created in the webhook handler when
        // the payment moves to "paid" state, so it cannot be returned here.
        return UsageOverageChargeResult::charged(
            is_string($paymentId) ? $paymentId : null,
            $lineItems,
            (int) $vat['net'],
            (int) $vat['gross'],
        );
    }

    /**
     * Write the pending overage onto `subscription_meta` so the webhook can create
     * the BillingInvoice when the payment confirms, and so a retry can rebuild the
     * exact same line items without the wallets.
     *
     * @param  array<int, array<string, mixed>>  $lineItems
     * @param  array{net:int,vat:int,gross:int,rate:float}  $vat
     */
    private function persistPendingCharge(Billable $billable, ?string $paymentId, array $lineItems, array $vat): void
    {
        if (! $billable instanceof Model) {
            return;
        }

        // Through the locked mutator: the read below used to be a plain in-memory
        // attribute read followed, after a Mollie round-trip, by a `forceFill` of the whole
        // JSON column — so a renewal webhook or a retry job that committed a different key
        // in between had its write reverted. `pending_refund_retries` is one of those keys,
        // and a resurrected refund line is a second payout to the customer.
        // Every line carries the period its usage was in. A line that has none yet is new
        // overage of the current period; a line that has one keeps it — the retry of a
        // cycle passes the snapshot's own lines, and a debt left standing by a failed
        // cycle keeps the period it was used in when a later renewal collects it together
        // with new overage. The snapshot's period is the span of its lines.
        [$lineItems, $periodStart, $periodEnd] = self::stampUsagePeriods(
            $lineItems,
            $billable->getBillingPeriodStartsAt()?->toIso8601String(),
            self::currentUsagePeriodEnd($billable)?->toIso8601String(),
        );

        $this->mutateBillingMeta($billable, function (array $meta) use ($paymentId, $lineItems, $vat, $periodStart, $periodEnd): array {
            $existing = (array) ($meta[self::META_KEY] ?? []);

            // A snapshot left behind by a cycle that gave up is a spent cycle, not this
            // one. Inheriting its `created_at` kept the cycle key identical, so
            // `usage_overage_counted_payments` still listed the three payments that had
            // already failed — and the new period's FIRST failure appended a fourth,
            // exceeded the budget and went straight to past_due with customer and admin
            // dunning mail, no retry ladder at all. Unsetting `usage_overage_attempts`
            // (below) never helped: the budget is derived from that id list, and the list
            // was still considered current.
            //
            // Within one cycle the inheritance is exactly right — p1 → p2 → p3 must keep
            // accumulating — so this only fires when the previous cycle is finished.
            if (($meta['usage_overage_status'] ?? null) === self::STATUS_FAILED) {
                // …and it is one of the renewals' attempts at a debt that was left standing.
                if ($existing !== []) {
                    $meta[self::RECOLLECTIONS_KEY] = (int) ($meta[self::RECOLLECTIONS_KEY] ?? 0) + 1;
                }

                $existing = [];
                unset($meta[self::COUNTED_PAYMENTS_KEY], $meta['usage_overage_attempts']);
            } elseif (($meta['usage_overage_status'] ?? null) === null) {
                // A debt nobody has tried to collect before starts with a full budget.
                unset($meta[self::RECOLLECTIONS_KEY]);
            }

            // The payments this cycle already made. Asking for a new one (`$paymentId === null`)
            // while the slot still names an earlier payment means that payment is over — the
            // retry after its failure, or a charge after it settled — so it leaves the slot for
            // the history instead of standing in for the payment about to be created. See
            // PREVIOUS_PAYMENTS_KEY.
            $previousPayments = array_values(array_filter(
                (array) ($existing[self::PREVIOUS_PAYMENTS_KEY] ?? []),
                static fn ($id): bool => is_string($id) && $id !== '',
            ));
            $existingPaymentId = (string) ($existing['payment_id'] ?? '');

            if ($paymentId === null && $existingPaymentId !== '' && ! in_array($existingPaymentId, $previousPayments, true)) {
                $previousPayments[] = $existingPaymentId;
            }

            $meta[self::META_KEY] = [
                'payment_id' => $paymentId,
                'line_items' => $lineItems,
                'amount_net' => $vat['net'],
                'amount_gross' => $vat['gross'],
                'vat_amount' => $vat['vat'],
                'vat_rate' => $vat['rate'],
                'currency' => (string) config('mollie-billing.currency', 'EUR'),
                'created_at' => $existing['created_at'] ?? BillingTime::nowUtc()->toIso8601String(),
                // The period the usage was in, per line (see stampUsagePeriods()) and as
                // their span. The invoice is written when the payment settles — days later
                // for SEPA, after the renewal has moved the billable's period on — and read
                // the period from the billable then, so October's usage was invoiced as
                // November's. Kept here rather than in the payment's metadata, which Mollie
                // caps at 1024 bytes and the line items already fill; SingleChargeHandler
                // reads it from here.
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ];

            if ($previousPayments !== []) {
                $meta[self::META_KEY][self::PREVIOUS_PAYMENTS_KEY] = $previousPayments;
            }

            // The refusals of the create request travel with the cycle until a payment
            // exists — the retry that asks again has to send the moved-on key.
            if ($paymentId === null && is_array($existing[self::KEY_REFUSALS_KEY] ?? null)) {
                $meta[self::META_KEY][self::KEY_REFUSALS_KEY] = $existing[self::KEY_REFUSALS_KEY];
            }

            $meta['usage_overage_status'] = self::STATUS_PENDING;

            // A new payment starts a new dunning cycle. The retry job leaves the counter at
            // its maximum when it gives up, so without this reset the next period's first
            // failure already exceeded the budget: PastDue plus customer and admin dunning
            // after a single attempt, with no retry ladder ever again for that billable.
            if ($paymentId !== null && $paymentId !== ($existing['payment_id'] ?? null)) {
                unset($meta['usage_overage_attempts']);
            }

            return $meta;
        });
    }

    /**
     * Create a one-off Mollie payment for the overage gross amount.
     *
     * Uses Mollie\Laravel\Facades\Mollie if available; otherwise expects a
     * `mollie.api`/`Mollie\Api\MollieApiClient` binding in the container.
     *
     * @param  array{net:int,vat:int,gross:int,rate:float}  $vat
     * @param  array<int, array<string, mixed>>  $lineItems
     */
    /**
     * A key that identifies *this debt in this dunning cycle*, so a retry cannot buy a
     * second payment for it.
     *
     * The failure it exists for: `createMolliePayment()` throws after Mollie already created
     * the payment — a read timeout on the response is the ordinary case — so no payment id is
     * ever stored, and `RetryUsageOverageChargeJob` comes back 60 seconds later and charges
     * the same debt again. Both payments settle, each into its own invoice under the webhook's
     * per-payment idempotency, and the customer paid twice. With a stable key Mollie replays
     * the first response instead, and the retry learns the payment id it lost.
     *
     * What goes into it, and why each part:
     *
     *  - the snapshot's `created_at` is the *cycle* id. `persistPendingCharge()` inherits it
     *    while a cycle continues and only mints a new one after a cycle has failed, so p1 → p2
     *    → p3 of one cycle share a key and the next cycle's legitimate charge does not;
     *  - the gross amount, because Mollie answers 400 to a reused key whose parameters
     *    differ. If more usage accrued between two attempts the amount moves, and this makes
     *    that a new key rather than a rejected request;
     *  - the billable, so two customers can never collide;
     *  - the payment of this cycle that came before, when there is one. A retry after a
     *    *failed payment* is a new payment, not a repeat of the old request — and a card
     *    refuses within minutes, well inside Mollie's hour. With the cycle's key alone Mollie
     *    answered p2's request with p1, the failed payment, so the cycle never made a second
     *    or third attempt and sat `pending` until the stale sweep wrote it off. The previous
     *    id moves the key on exactly when a new payment is meant, and stays put across the
     *    retries of asking for that one payment.
     *
     * Mollie caches a key for **one hour**. That covers the ladder of one create request —
     * `backoff()` is `[60, 300, 900]`, so all three retries land inside ~21 minutes — and
     * deliberately not the nightly sweep, which is a day later and a genuinely new decision.
     *
     * @param  array{net:int,vat:int,gross:int,rate:float}  $vat
     */
    private function idempotencyKeyFor(Billable $billable, array $vat): ?string
    {
        if (! $billable instanceof Model) {
            return null;
        }

        $pending = (array) ($this->pendingCharge($billable) ?? []);
        $cycleStartedAt = (string) ($pending['created_at'] ?? '');

        if ($cycleStartedAt === '') {
            // No snapshot to key off. Better no key than an unstable one: a key that changes
            // per attempt buys nothing, and one that repeats across different charges would
            // suppress a legitimate second payment.
            return null;
        }

        // Readable rather than hashed, like `RefundInvoiceService`'s: when support has to ask
        // Mollie what happened to a key, the key should say what it was for.
        $key = sprintf(
            'usage-overage:%s:%s:%s:%d',
            $billable->getMorphClass(),
            (string) $billable->getKey(),
            $cycleStartedAt,
            (int) $vat['gross'],
        );

        $previousPayments = (array) ($pending[self::PREVIOUS_PAYMENTS_KEY] ?? []);
        $previousPayment = (string) (end($previousPayments) ?: '');

        return $previousPayment === '' ? $key : $key.':after:'.$previousPayment;
    }

    private function createMolliePayment(Billable $billable, array $vat, array $lineItems, ?string $idempotencyKey = null): object
    {
        $currency = (string) config('mollie-billing.currency', 'EUR');
        $amountValue = BillingMoney::mollieAmount((int) $vat['gross'], $currency);

        return MollieCall::send(new CreatePaymentRequest(
            description: 'Usage overage',
            amount: new Money($currency, $amountValue),
            metadata: [
                'type' => 'overage',
                'billable_type' => $billable instanceof Model ? $billable->getMorphClass() : null,
                'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
                // Without the usage periods a retry or a recollection carries over from the
                // snapshot: Mollie caps metadata at 1024 bytes, and the webhook reads the
                // periods from the snapshot (SingleChargeHandler::overageLinePeriods()).
                'line_items' => array_map(
                    static fn ($line) => is_array($line) ? array_diff_key($line, ['period_start' => true, 'period_end' => true]) : $line,
                    $lineItems,
                ),
            ],
            sequenceType: 'recurring',
            mandateId: $billable->getMollieMandateId(),
            customerId: $billable->getMollieCustomerId(),
            webhookUrl: route(BillingRoute::webhook()),
        ), $idempotencyKey);
    }
}
