<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\PaymentSucceeded;
use GraystackIT\MollieBilling\Events\RenewalUndercollected;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Notifications\UndercollectionChargedNotification;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Services\Webhook\SubscriptionPaymentHandler;
use GraystackIT\MollieBilling\Services\Webhook\WebhookSupport;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;
use GraystackIT\MollieBilling\Support\MollieCall;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Mollie\Api\Http\Data\Money as MollieMoney;
use Mollie\Api\Http\Requests\CreatePaymentRequest;

/**
 * Collects what a period was charged too little — as its own payment, with its own
 * invoice, exactly once.
 *
 * Mollie creates a recurring payment with the amount the subscription holds at that
 * moment, and a change can come too late for it. Two cases are recognised with certainty
 * ({@see RenewalUndercollected}): a seat or addon booked after Mollie had already created
 * the next debit, and a plan change whose SEPA payment was still pending when the period
 * renewed. In both the customer holds, for a whole period, more than the period's payment
 * covered.
 *
 * The counterpart of {@see SupersededRenewalRefund}: that one returns what a renewal
 * collected too much, this one collects what it collected too little. The two exclude
 * each other by construction — a renewal has a surplus or a shortfall, never both.
 *
 * What happens:
 *
 *  1. The case is **claimed** under the billable's row lock (`subscription_meta.undercollection_charges`,
 *     keyed `reason:invoice id`). A re-delivered webhook finds the claim and does nothing.
 *  2. A recurring payment on the mandate is created for the difference plus the VAT that
 *     applies to the billable today, with an idempotency key that is stable for the case.
 *  3. The customer is told the amount and the reason — before the money moves.
 *  4. When it settles, it is invoiced as one `undercollection` line referring to the
 *     renewal it completes.
 *  5. When it **fails**, the ordinary dunning takes over: `SubscriptionPaymentHandler::failed()`,
 *     the same call a failed renewal makes — past due, the customer's payment-failed mail,
 *     the auto-cancel clock. There is no second mechanism.
 *
 * An admin is told only when the automatic way is not available: the subscription is not
 * in a state that may be charged (past due, cancelled, expired), there is no mandate, or
 * Mollie refused to create the payment. The event is dispatched — and audited — in every
 * case; it carries the payment id when one was created.
 *
 * Nothing is charged for a difference a discount or a waiver explains (both callers
 * measure against an expected amount that already contains them), nor within the
 * one-cent rounding slack.
 */
class UndercollectionCharge
{
    use MutatesBillingMeta;

    /** `subscription_meta` key: case key => what was done about it. */
    public const META_KEY = 'undercollection_charges';

    /** Payment metadata key carrying the case key; routes the webhook back here. */
    public const METADATA_KEY = 'undercollection';

    /** Line kind of the back-charge invoice. */
    public const LINE_KIND = 'undercollection';

    /**
     * Rounding slack: per-line VAT can put a renewal a cent off its expected gross, and
     * that is documented as not being a mismatch at all.
     */
    private const TOLERANCE = 1;

    public function __construct(
        private readonly VatCalculationService $vat,
        private readonly InvoiceService $invoices,
        private readonly WebhookSupport $support,
    ) {
    }

    /**
     * Collect the shortfall of one period. Posts to Mollie, so never inside a transaction.
     *
     * @param  ?BillingInvoice  $invoice  the renewal that paid for the period
     * @param  list<array{kind: string, code: ?string, quantity: int, net: int, label?: string}>  $components
     *                                                                                                     what the shortfall is made of at list price — the seats and addons
     *                                                                                                     the period holds beyond what its payment was created for. The
     *                                                                                                     balance is then invoiced with one line each ({@see lines()}).
     * @return bool whether there was a shortfall that this call dealt with (charged or,
     *              where that is not possible, reported)
     */
    public function settle(
        Billable $billable,
        ?BillingInvoice $invoice,
        int $expectedNet,
        int $collectedNet,
        string $reason,
        ?string $forPlanCode = null,
        array $components = [],
    ): bool {
        $shortfall = $expectedNet - $collectedNet;

        if ($shortfall <= self::TOLERANCE || ! ($billable instanceof Model)) {
            return false;
        }

        $key = $reason.':'.($invoice?->getKey() ?? 'none');

        // Exactly once per case. Claimed before anything else happens, under the row lock:
        // a second delivery of the same webhook, or two workers, find the claim.
        $claimed = false;

        $this->mutateBillingMeta($billable, function (array $meta) use ($key, $shortfall, $expectedNet, $collectedNet, &$claimed): ?array {
            $cases = (array) ($meta[self::META_KEY] ?? []);

            if (array_key_exists($key, $cases)) {
                return null;
            }

            $claimed = true;
            $cases[$key] = [
                'shortfall_net' => $shortfall,
                'expected_net' => $expectedNet,
                'collected_net' => $collectedNet,
                'claimed_at' => BillingTime::nowUtc()->toIso8601String(),
            ];
            $meta[self::META_KEY] = $cases;

            return $meta;
        });

        if (! $claimed) {
            return false;
        }

        $billable->refresh();

        $obstacle = $this->obstacle($billable);

        if ($obstacle !== null) {
            $this->record($billable, $key, ['outcome' => 'reported', 'obstacle' => $obstacle]);
            $this->announce($billable, $invoice, $expectedNet, $collectedNet, $shortfall, $reason, null);
            $this->tellAdmins($billable, $invoice, $shortfall, $reason, $obstacle);

            return true;
        }

        $lines = $this->lines($billable, $invoice, $shortfall, $reason, $forPlanCode, $components);
        $amountGross = (int) array_sum(array_column($lines, 'amount_gross'));
        $currency = BillingMoney::normalizeCurrency($invoice?->currency ?? config('mollie-billing.currency', 'EUR'));

        try {
            $payment = MollieCall::send(new CreatePaymentRequest(
                description: 'Balance for the billing period'.($invoice?->serial_number ? ' ('.$invoice->serial_number.')' : ''),
                amount: new MollieMoney($currency, BillingMoney::mollieAmount($amountGross, $currency)),
                metadata: [
                    // Routed by the webhook controller to ProrataChargeHandler, which
                    // hands anything carrying METADATA_KEY back to this class.
                    'type' => 'prorata_charge',
                    self::METADATA_KEY => $key,
                    'billable_type' => $billable->getMorphClass(),
                    'billable_id' => (string) $billable->getKey(),
                ],
                sequenceType: 'recurring',
                mandateId: $billable->getMollieMandateId(),
                customerId: $billable->getMollieCustomerId(),
                webhookUrl: route(BillingRoute::webhook()),
            ), sprintf('undercollection:%s:%s:%s:%d', $billable->getMorphClass(), (string) $billable->getKey(), $key, $amountGross));
        } catch (\Throwable $e) {
            Log::error('The balance of an undercollected period could not be charged', [
                'billable_id' => $billable->getKey(),
                'invoice_id' => $invoice?->getKey(),
                'shortfall_net' => $shortfall,
                'error' => $e->getMessage(),
            ]);

            $this->record($billable, $key, ['outcome' => 'reported', 'obstacle' => 'payment_not_created', 'error' => mb_substr($e->getMessage(), 0, 300)]);
            $this->announce($billable, $invoice, $expectedNet, $collectedNet, $shortfall, $reason, null);
            $this->tellAdmins($billable, $invoice, $shortfall, $reason, 'payment_not_created');

            return true;
        }

        $paymentId = is_object($payment) ? (string) ($payment->id ?? '') : '';

        $this->record($billable, $key, [
            'outcome' => 'charged',
            'payment_id' => $paymentId,
            // `line` is the first one: what the screens and `configurationLeft()` read.
            'line' => $lines[0],
            'lines' => $lines,
            'amount_net' => $shortfall,
            'amount_gross' => $amountGross,
            'currency' => $currency,
            'reason' => $reason,
            'invoice_id' => $invoice?->getKey(),
            'period_start' => $invoice?->period_start?->toIso8601String(),
            'period_end' => $invoice?->period_end?->toIso8601String(),
        ]);

        Log::info('Charging the balance of an undercollected period', [
            'billable_id' => $billable->getKey(),
            'invoice_id' => $invoice?->getKey(),
            'payment_id' => $paymentId,
            'shortfall_net' => $shortfall,
            'reason' => $reason,
        ]);

        $this->announce($billable, $invoice, $expectedNet, $collectedNet, $shortfall, $reason, $paymentId);
        $this->tellCustomer($billable, $amountGross, $currency, $reason);

        return true;
    }

    /**
     * Whether a payment's metadata says it is one of ours.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function owns(array $metadata): bool
    {
        return isset($metadata[self::METADATA_KEY]) && (string) $metadata[self::METADATA_KEY] !== '';
    }

    /**
     * The back-charge settled: invoice it.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function paid(object $payment, Billable $billable, array $metadata): void
    {
        if (! ($billable instanceof Model) || $this->support->invoiceAlreadyExistsForPayment($payment)) {
            return;
        }

        $key = (string) ($metadata[self::METADATA_KEY] ?? '');
        $case = (array) ($billable->getBillingSubscriptionMeta()[self::META_KEY][$key] ?? []);
        // One line per component since the balance of seats and addons names them;
        // a case claimed before that carries its single `line`.
        $lines = array_values(array_filter((array) ($case['lines'] ?? []), 'is_array'));

        if ($lines === [] && is_array($case['line'] ?? null) && $case['line'] !== []) {
            $lines = [(array) $case['line']];
        }

        if ($lines === []) {
            // The claim is gone (meta reset by a new subscription). The money is real:
            // book it for what Mollie collected rather than lose it.
            $gross = $this->support->amountFromMolliePayment($payment);
            $rate = (float) $this->vat->calculate((string) ($billable->getBillingCountry() ?? ''), max(1, $gross), $billable)['rate'];
            $net = $this->support->netFromGross($gross, $rate);
            $line = [
                'kind' => self::LINE_KIND, 'code' => null, 'label' => 'Balance for a billing period', 'quantity' => 1,
                'unit_price_net' => $net, 'amount_net' => $net, 'vat_rate' => $rate, 'vat_amount' => $gross - $net, 'amount_gross' => $gross,
            ];
            $lines = [$line];
        }

        $invoice = $this->invoices->createInvoice(
            billable: $billable,
            kind: InvoiceKind::Subscription,
            molliePaymentId: (string) $payment->id,
            mollieSubscriptionId: null,
            lineItems: $lines,
            periodStart: isset($case['period_start']) ? BillingTime::toUtc((string) $case['period_start']) : null,
            periodEnd: isset($case['period_end']) ? BillingTime::toUtc((string) $case['period_end']) : null,
            currency: $payment->amount->currency ?? null,
        );

        if ($key !== '') {
            $this->record($billable, $key, ['paid_at' => BillingTime::nowUtc()->toIso8601String()]);
        }

        $this->returnWhatTheConfigurationNoLongerUses($billable, $invoice, $case);

        $this->support->safely(fn () => event(new PaymentSucceeded($billable, $invoice)), 'undercollection.payment_succeeded_event', $billable);
        $this->support->safely(fn () => $this->support->notifyInvoiceAvailable($billable, $invoice), 'undercollection.invoice_notification', $billable);
    }

    /**
     * The back-charge failed: this is an unpaid recurring amount like any other, and the
     * ordinary dunning handles it.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function failed(object $payment, Billable $billable, array $metadata): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        $key = (string) ($metadata[self::METADATA_KEY] ?? '');
        $case = (array) ($billable->getBillingSubscriptionMeta()[self::META_KEY][$key] ?? []);

        // Declined before, and closed since by a change that left the configuration it
        // was for: the dunning it started has ended with that change, and the same
        // failure delivered again must not start another.
        if (! empty($case['failed_at']) && ! empty($case['left_at'])) {
            return;
        }

        if ($key !== '') {
            $this->record($billable, $key, [
                'failed_at' => BillingTime::nowUtc()->toIso8601String(),
                'failure' => (string) ($payment->details?->failureReason ?? $payment->status ?? 'unknown'),
            ]);
        }

        app(SubscriptionPaymentHandler::class)->failed($payment, $billable->refresh());
    }

    /**
     * The configuration a still-unpaid balance pays for has just been left — the plan or
     * the interval changed.
     *
     * A balance is priced for what the customer holds from the day it took effect to the
     * end of the period. A plan or interval change closes that period out: it credits
     * what *is* paid — the balance is not — and charges the new configuration for the
     * days that are left. From here on the balance is owed only for the days the old
     * configuration ran. The moment is noted; when the payment arrives after all,
     * {@see returnWhatTheConfigurationNoLongerUses()} gives the unused days back.
     *
     * For a balance that was **declined** this is also where it is closed: it is not
     * collected again, it no longer shows as open, and the dunning it started ends with
     * the change that left it ({@see \GraystackIT\MollieBilling\Support\BillingPolicy::runningPeriodIsPaid()}).
     *
     * @param  string  $planCode  the plan the billable was on before the change
     */
    public function configurationLeft(Billable $billable, string $planCode): void
    {
        if (! ($billable instanceof Model) || $planCode === '') {
            return;
        }

        $this->mutateBillingMeta($billable, function (array $meta) use ($planCode): ?array {
            $cases = (array) ($meta[self::META_KEY] ?? []);
            $changed = false;

            foreach ($cases as $key => $case) {
                if (! is_array($case)
                    || ($case['outcome'] ?? null) !== 'charged'
                    || ! empty($case['paid_at'])
                    || ! empty($case['left_at'])
                    // The balance of a plan change is for the plan that change ENTERED,
                    // and it is claimed in the same step that announces the change: it
                    // is left only when that plan is.
                    || (($case['line']['kind'] ?? null) === 'plan' && (string) ($case['line']['code'] ?? '') !== $planCode)
                ) {
                    continue;
                }

                $cases[$key]['left_at'] = BillingTime::nowUtc()->toIso8601String();
                $changed = true;
            }

            if (! $changed) {
                return null;
            }

            $meta[self::META_KEY] = $cases;

            return $meta;
        });
    }

    /**
     * Seats or addons were given back while the balance that pays for them is not in.
     *
     * A component of a period whose renewal was created too early is paid in two parts:
     * on the renewal's line and on the balance's. A credit takes the same share out of
     * both (`ProrataComposer::balanceRefundLines()`) — but only out of what is paid, and
     * an open or declined balance is not. Its share is noted here instead, per line key
     * (`seats`, `addon:<code>`, `addon_seats:<code>`) as the fraction of the renewal
     * line's units that went; when the payment arrives after all, that fraction is
     * returned for the days from now on.
     *
     * @param  array<string, float>  $fractions  line key => units given back / units of the line
     */
    public function componentsGivenBack(Billable $billable, array $fractions): void
    {
        $fractions = array_filter($fractions, fn ($f): bool => (float) $f > 0);

        if (! ($billable instanceof Model) || $fractions === []) {
            return;
        }

        $this->mutateBillingMeta($billable, function (array $meta) use ($fractions): ?array {
            $cases = (array) ($meta[self::META_KEY] ?? []);
            $changed = false;

            foreach ($cases as $key => $case) {
                if (! is_array($case)
                    || ($case['outcome'] ?? null) !== 'charged'
                    || ! empty($case['paid_at'])
                    || ! empty($case['left_at'])
                ) {
                    continue;
                }

                foreach ((array) ($case['lines'] ?? []) as $line) {
                    $lineKey = is_array($line) ? ProrataComposer::lineKey($line) : '';

                    if (! isset($fractions[$lineKey])) {
                        continue;
                    }

                    $cases[$key]['released'][] = [
                        'key' => $lineKey,
                        'fraction' => min(1.0, (float) $fractions[$lineKey]),
                        'at' => BillingTime::nowUtc()->toIso8601String(),
                    ];
                    $changed = true;
                }
            }

            if (! $changed) {
                return null;
            }

            $meta[self::META_KEY] = $cases;

            return $meta;
        });
    }

    /**
     * A balance that was collected after its configuration had been left: the days from
     * then to the end of what each line was priced for are credited at once — its own
     * credit note, a refund on its own payment. The customer has then paid for the plan,
     * the seat or the addon exactly as long as it ran.
     *
     * @param  array<string, mixed>  $case
     */
    private function returnWhatTheConfigurationNoLongerUses(Billable $billable, BillingInvoice $invoice, array $case): void
    {
        $left = ! empty($case['left_at']) ? BillingTime::toUtc((string) $case['left_at']) : null;
        $released = array_values(array_filter((array) ($case['released'] ?? []), 'is_array'));

        if ($left === null && $released === []) {
            return;
        }

        $refunds = [];

        foreach ((array) ($invoice->line_items ?? []) as $index => $line) {
            $lineNet = is_array($line) ? (int) ($line['amount_net'] ?? 0) : 0;

            if ($lineNet <= 0 || empty($line['period_start']) || empty($line['period_end'])) {
                continue;
            }

            $start = BillingTime::toUtc((string) $line['period_start']);
            $end = BillingTime::toUtc((string) $line['period_end']);
            // Whole days, as the balance itself was priced (30.00 × 26/28 for 26 days).
            $total = (int) round($start->diffInDays($end));

            if ($total <= 0) {
                continue;
            }

            // The share of the line's days that lie after a given moment.
            $unusedFrom = function (\Carbon\CarbonInterface $at) use ($start, $end, $total): float {
                if ($at->gte($end)) {
                    return 0.0;
                }

                $used = $at->lt($start) ? 0 : min($total, (int) round($start->diffInDays($at)));

                return ($total - $used) / $total;
            };

            // Units given back one by one first, then whatever was still held when the
            // configuration was left as a whole.
            $held = 1.0;
            $unused = 0.0;
            $from = $left;
            $lineKey = ProrataComposer::lineKey($line);

            foreach ($released as $release) {
                if ((string) ($release['key'] ?? '') !== $lineKey || empty($release['at'])) {
                    continue;
                }

                $fraction = min($held, (float) ($release['fraction'] ?? 0));
                $at = BillingTime::toUtc((string) $release['at']);
                $unused += $fraction * $unusedFrom($at);
                $held -= $fraction;
                $from ??= $at;
            }

            if ($left !== null) {
                $unused += $held * $unusedFrom($left);
            }

            $net = min($lineNet, (int) round($lineNet * $unused));

            if ($net <= 0 || $from === null) {
                continue;
            }

            $rate = (float) ($line['vat_rate'] ?? 0);
            $vat = (int) round($net * $rate / 100);
            $kind = (string) ($line['kind'] ?? '');

            $refunds[] = new \GraystackIT\MollieBilling\Support\ProrataLine(
                originalInvoice: $invoice,
                originalLineItemIndex: (int) $index,
                kind: in_array($kind, ['plan', 'seats', 'addon', 'addon_seats'], true) ? $kind : 'plan',
                code: isset($line['code']) ? (string) $line['code'] : null,
                label: (string) ($line['label'] ?? 'Balance'),
                quantity: max(1, (int) ($line['quantity'] ?? 1)),
                amountNet: -$net,
                vatRate: $rate,
                amountVat: -$vat,
                amountGross: -($net + $vat),
                periodStart: $from,
                periodEnd: $end,
                daysActive: 0,
                daysRemaining: 0,
                isCouponCovered: false,
                direction: 'refund',
            );
        }

        if ($refunds === []) {
            return;
        }

        $this->support->safely(
            fn () => $this->invoices->createRefund($billable, $refunds, 'Balance for a configuration that was left before it was paid — unused days returned'),
            'undercollection.unused_days_refund',
            $billable,
        );
    }

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_NOT_POSSIBLE = 'not_possible';

    /** Declined, and closed by a plan or interval change: not collected again. */
    public const STATUS_CLOSED = 'closed';

    /**
     * Every balance this billable was (or could not be) charged, newest first, as the
     * screens show them.
     *
     * `pending` — the payment exists and has not settled; `paid` — it is invoiced;
     * `failed` — Mollie declined it (the subscription is in dunning); `closed` — declined,
     * and the configuration it was for has been left since, so it is not owed in this
     * form any more; `not_possible` — no payment was created, `obstacle` says why.
     *
     * @return list<array{key: string, status: string, reason: string, amount_gross: int, amount_net: int, currency: string, obstacle: ?string, payment_id: ?string, invoice_id: ?int, charged_invoice_id: ?int, at: ?string}>
     */
    public static function cases(Billable $billable): array
    {
        $out = [];

        foreach ((array) ($billable->getBillingSubscriptionMeta()[self::META_KEY] ?? []) as $key => $case) {
            if (! is_array($case) || ! isset($case['outcome'])) {
                continue;
            }

            $paymentId = isset($case['payment_id']) && $case['payment_id'] !== '' ? (string) $case['payment_id'] : null;
            $charged = $paymentId !== null
                ? BillingInvoice::query()->where('mollie_payment_id', $paymentId)->first()
                : null;

            $status = match (true) {
                ($case['outcome'] ?? null) !== 'charged' => self::STATUS_NOT_POSSIBLE,
                $charged !== null => self::STATUS_PAID,
                ! empty($case['failed_at']) && ! empty($case['left_at']) => self::STATUS_CLOSED,
                ! empty($case['failed_at']) => self::STATUS_FAILED,
                default => self::STATUS_PENDING,
            };

            $out[] = [
                'key' => (string) $key,
                'status' => $status,
                'reason' => (string) ($case['reason'] ?? explode(':', (string) $key)[0]),
                'amount_gross' => (int) ($case['amount_gross'] ?? $case['line']['amount_gross'] ?? $case['shortfall_net'] ?? 0),
                'amount_net' => (int) ($case['amount_net'] ?? $case['line']['amount_net'] ?? $case['shortfall_net'] ?? 0),
                'currency' => (string) ($case['currency'] ?? config('mollie-billing.currency', 'EUR')),
                'obstacle' => isset($case['obstacle']) ? (string) $case['obstacle'] : null,
                'payment_id' => $paymentId,
                'invoice_id' => isset($case['invoice_id']) ? (int) $case['invoice_id'] : null,
                'charged_invoice_id' => $charged?->getKey(),
                'at' => isset($case['failed_at']) ? (string) $case['failed_at'] : (isset($case['claimed_at']) ? (string) $case['claimed_at'] : null),
            ];
        }

        return array_reverse($out);
    }

    /**
     * The balances the customer still has to know about: not settled yet, or declined.
     *
     * @return list<array<string, mixed>>
     */
    public static function openCases(Billable $billable): array
    {
        return array_values(array_filter(
            self::cases($billable),
            fn (array $case): bool => in_array($case['status'], [self::STATUS_PENDING, self::STATUS_FAILED], true),
        ));
    }

    /** Whether this invoice is the charge of such a balance. */
    public static function isBalanceInvoice(?BillingInvoice $invoice): bool
    {
        foreach ((array) ($invoice?->line_items ?? []) as $line) {
            if (is_array($line) && (($line['kind'] ?? null) === self::LINE_KIND || ($line['undercollection'] ?? false) === true)) {
                return true;
            }
        }

        return false;
    }

    private function obstacle(Billable $billable): ?string
    {
        // Not while past due (a charge already failed — dunning is running), and not for
        // a subscription that has ended.
        if (! in_array($billable->getBillingSubscriptionStatus(), [SubscriptionStatus::Active, SubscriptionStatus::Trial], true)) {
            return 'subscription_'.($billable->getBillingSubscriptionStatus()?->value ?? 'unknown');
        }

        if (! $billable->hasMollieMandate() || empty($billable->getMollieCustomerId())) {
            return 'no_mandate';
        }

        return null;
    }

    /**
     * The lines of the back-charge.
     *
     * The balance of a plan change is one line naming the plan ({@see line()}). The
     * balance of seats and addons that came after the debit was created used to be one
     * amount as well, of a kind no later credit reads — so a seat given back in that
     * period was credited from the renewal alone, whose seat line carries the
     * `adjustment` down to what was collected: 5.00 × 50/55 × 19/28 = 3.08 for a seat
     * that was paid 5.00, where 3.39 is owed.
     *
     * With `$components` the balance states what it is made of: one line per component
     * (`seats`, `addon`, `addon_seats`), `undercollection: true`, over the renewal's
     * period, pointing at the renewal it completes. `ProrataComposer` credits a
     * component against the renewal's line and its balance line together.
     *
     * The total does not move. The nets are the components' list amounts scaled to the
     * shortfall (largest remainder), and the VAT is rounded per line and then settled on
     * the largest line so that it sums to exactly what the single line carried — the
     * amount collected and the VAT declared are the same to the cent either way.
     *
     * @param  list<array{kind: string, code: ?string, quantity: int, net: int, label?: string}>  $components
     * @return non-empty-list<array<string, mixed>>
     */
    private function lines(Billable $billable, ?BillingInvoice $invoice, int $shortfall, string $reason, ?string $forPlanCode, array $components): array
    {
        $single = $this->line($billable, $invoice, $shortfall, $reason, $forPlanCode);

        $components = array_values(array_filter(
            $components,
            fn (array $c): bool => (int) ($c['net'] ?? 0) > 0 && in_array($c['kind'] ?? null, ['seats', 'addon', 'addon_seats'], true),
        ));
        $listTotal = (int) array_sum(array_column($components, 'net'));

        if ($components === [] || $listTotal <= 0 || $single['kind'] !== self::LINE_KIND) {
            return [$single];
        }

        $rate = (float) $single['vat_rate'];
        $nets = self::split($shortfall, $components);

        $vats = array_map(fn (int $net): int => (int) round($net * $rate / 100), $nets);
        $largest = array_keys($nets, max($nets))[0];
        $vats[$largest] += (int) $single['vat_amount'] - array_sum($vats);

        $lines = [];

        foreach ($components as $i => $component) {
            if ($nets[$i] <= 0) {
                continue;
            }

            $quantity = max(1, (int) ($component['quantity'] ?? 1));

            $lines[] = [
                ...$single,
                'kind' => (string) $component['kind'],
                'code' => $component['code'] ?? null,
                // What the invoice list shows for the document as a whole.
                'reason_label' => $single['label'],
                'label' => __('billing::portal.undercollection_component', [
                    'component' => (string) ($component['label'] ?? $component['kind']),
                    'invoice' => (string) ($invoice?->serial_number ?? ''),
                ]),
                // One position at the amount that was missing for this component. The
                // units it stands for are the renewal line's; `completes_quantity` says
                // how many of them were short, for the reader of the document.
                'quantity' => 1,
                'completes_quantity' => $quantity,
                'unit_price' => $nets[$i],
                'unit_price_net' => $nets[$i],
                'amount_net' => $nets[$i],
                'vat_amount' => $vats[$i],
                'amount_gross' => $nets[$i] + $vats[$i],
            ];
        }

        return $lines !== [] ? $lines : [$single];
    }

    /**
     * A shortfall spread over the components it is made of, in proportion to their list
     * amounts, to the cent (largest remainder). Index-aligned with `$components`.
     *
     * One rule in one place: the balance is invoiced in these amounts, and the renewal's
     * `adjustment` is read as taking exactly these amounts off its lines
     * ({@see SupersededRenewalRefund::nameWhatTheAdjustmentReduces()}).
     *
     * @param  array<int, array{net: int}>  $components
     * @return array<int, int>
     */
    public static function split(int $shortfall, array $components): array
    {
        $listTotal = (int) array_sum(array_column($components, 'net'));
        $nets = [];
        $remainders = [];

        if ($listTotal <= 0 || $shortfall <= 0) {
            return array_map(fn (): int => 0, $components);
        }

        foreach ($components as $i => $component) {
            $exact = $shortfall * (int) $component['net'] / $listTotal;
            $nets[$i] = (int) floor($exact);
            $remainders[$i] = $exact - $nets[$i];
        }

        arsort($remainders);
        $left = $shortfall - array_sum($nets);

        foreach (array_keys($remainders) as $i) {
            if ($left <= 0) {
                break;
            }

            $nets[$i]++;
            $left--;
        }

        return $nets;
    }

    /**
     * The one line of the back-charge, with the VAT that applies to the billable today.
     *
     * @return array<string, mixed>
     */
    private function line(Billable $billable, ?BillingInvoice $invoice, int $shortfall, string $reason, ?string $forPlanCode = null): array
    {
        $vat = $this->vat->calculate(
            (string) ($billable->getBillingCountry() ?? config('mollie-billing.checkout_country_fallback', 'DE')),
            $shortfall,
            $billable,
        );

        $start = $invoice?->period_start ?? BillingTime::nowUtc();
        $end = $invoice?->period_end ?? BillingTime::nowUtc();

        // The balance of a plan change is plan money: the line names the plan it pays for,
        // so a further change in the same period finds it and credits it
        // (`ProrataComposer::planRefundLines()`). `undercollection` marks it as the
        // balance it is — it carries the difference, not the plan's price — and it runs
        // from now, the moment the plan takes effect, not from the start of the period:
        // it was priced for exactly those days.
        $forPlan = $reason === 'plan_change_spanned_renewal' && $forPlanCode !== null && $forPlanCode !== '';
        if ($forPlan) {
            $start = BillingTime::nowUtc();
        }

        return [
            'kind' => $forPlan ? 'plan' : self::LINE_KIND,
            'code' => $forPlan ? $forPlanCode : null,
            'undercollection' => true,
            'label' => __('billing::portal.undercollection_line.'.$reason, ['invoice' => (string) ($invoice?->serial_number ?? '')]),
            'quantity' => 1,
            'unit_price' => $shortfall,
            'unit_price_net' => $shortfall,
            'amount_net' => $shortfall,
            'vat_rate' => (float) $vat['rate'],
            'vat_amount' => (int) $vat['vat'],
            'amount_gross' => $shortfall + (int) $vat['vat'],
            'period_start' => $start->toIso8601String(),
            'period_end' => $end->toIso8601String(),
            'reason' => $reason,
            // Not `parent_invoice_id`: that key marks a CREDIT against another invoice.
            'completes_invoice_id' => $invoice?->getKey(),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function record(Model $billable, string $key, array $values): void
    {
        $this->mutateBillingMeta($billable, function (array $meta) use ($key, $values): array {
            $cases = (array) ($meta[self::META_KEY] ?? []);
            $cases[$key] = array_merge((array) ($cases[$key] ?? []), $values);
            $meta[self::META_KEY] = $cases;

            return $meta;
        });
    }

    private function announce(Billable $billable, ?BillingInvoice $invoice, int $expectedNet, int $collectedNet, int $shortfall, string $reason, ?string $paymentId): void
    {
        try {
            event(new RenewalUndercollected($billable, $invoice, $expectedNet, $collectedNet, $shortfall, $reason, $paymentId));
        } catch (\Throwable $e) {
            // A listener must not be able to fail the webhook that noticed this.
            Log::error('RenewalUndercollected listener failed', ['error' => $e->getMessage()]);
        }
    }

    private function tellCustomer(Billable $billable, int $amountGross, string $currency, string $reason): void
    {
        try {
            $notification = MollieBilling::resolveNotification(
                UndercollectionChargedNotification::class,
                $billable,
                $amountGross,
                $currency,
                $reason,
            );

            $recipients = MollieBilling::notifyBillingAdmins($billable);
            $recipients = is_array($recipients) ? $recipients : iterator_to_array($recipients);

            if ($recipients !== []) {
                Notification::send($recipients, $notification);

                return;
            }

            $email = $billable->getBillingEmail();

            if (is_string($email) && $email !== '') {
                Notification::route('mail', $email)->notify($notification);
            }
        } catch (\Throwable $e) {
            Log::error('Could not announce the balance charge to the customer', ['error' => $e->getMessage()]);
        }
    }

    private function tellAdmins(Billable $billable, ?BillingInvoice $invoice, int $shortfall, string $reason, string $obstacle): void
    {
        try {
            $admins = MollieBilling::notifyAdmin();
            $admins = is_array($admins) ? $admins : iterator_to_array($admins);

            if ($admins === []) {
                return;
            }

            $currency = (string) ($invoice?->currency ?? config('mollie-billing.currency', 'EUR'));

            Notification::send($admins, MollieBilling::resolveNotification(
                AdminPlanChangeFailedNotification::class,
                'A billing period was collected at less than its configuration costs ('
                    .BillingMoney::format($shortfall, $currency).' net short) and the balance could not be charged automatically',
                [
                    'billable_type' => $billable instanceof Model ? $billable->getMorphClass() : null,
                    'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
                    'invoice_id' => $invoice?->getKey(),
                    'invoice_serial' => $invoice?->serial_number,
                    'shortfall_net' => $shortfall,
                    'currency' => $currency,
                    'reason' => $reason,
                    'obstacle' => $obstacle,
                ],
            ));
        } catch (\Throwable $e) {
            Log::error('Could not notify the admins of an undercollected period', ['error' => $e->getMessage()]);
        }
    }
}
