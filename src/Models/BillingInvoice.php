<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Models;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Support\BillingRoute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use GraystackIT\MollieBilling\Casts\UtcDatetime;

class BillingInvoice extends Model
{
    protected $table = 'billing_invoices';

    protected $guarded = [];

    /** @var \Illuminate\Support\Collection<int, self>|null */
    protected ?\Illuminate\Support\Collection $refundChildren = null;

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'invoice_kind' => InvoiceKind::class,
            'refund_reason_code' => RefundReasonCode::class,
            'line_items' => 'array',
            'payment_method_details' => 'array',
            'period_start' => UtcDatetime::class,
            'period_end' => UtcDatetime::class,
            'created_at' => UtcDatetime::class,
            'updated_at' => UtcDatetime::class,
        ];
    }

    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The VIES validation that was active when this invoice was issued.
     *
     * For tax-audit purposes this is the authoritative answer to "on what
     * VAT-number validation did this specific invoice rely?". Null for B2C
     * invoices without a VAT number, or for invoices created before the
     * audit-trail feature was introduced.
     */
    public function vatValidation(): BelongsTo
    {
        return $this->belongsTo(BillingVatValidation::class, 'vat_validation_id');
    }

    /**
     * Share one credit-note lookup across a page of invoices of the same billable.
     *
     * `effectiveRefundedNet()` is called once per row by the admin invoice tab, and each call
     * loaded every credit note of the billable again — 100 invoices meant 100 identical
     * queries. They all want the same answer, so hand it to them once.
     *
     * @param  iterable<self>  $invoices
     */
    public static function shareRefundChildren(iterable $invoices): void
    {
        $first = null;

        foreach ($invoices as $invoice) {
            $first ??= $invoice;
        }

        if ($first === null) {
            return;
        }

        $children = self::query()
            ->where('billable_type', $first->billable_type)
            ->where('billable_id', $first->billable_id)
            ->where('invoice_kind', InvoiceKind::Refund)
            ->get(['line_items']);

        foreach ($invoices as $invoice) {
            // Only for rows of the billable the set was loaded for — a mixed collection
            // would otherwise be told about somebody else's credit notes.
            if ((string) $invoice->billable_id === (string) $first->billable_id
                && $invoice->billable_type === $first->billable_type
            ) {
                $invoice->refundChildren = $children;
            }
        }
    }

    /**
     * Every credit note of this invoice's billable, loaded once per instance.
     *
     * The three readers below each ran the same query, and `creditNetForGross()` calls into
     * them up to eighteen times for a single refund — all inside the transaction that holds
     * the invoice row lock and the serial range, which is what made a lock-wait timeout
     * against a concurrent admin refund realistic. The reference lives inside `line_items`
     * JSON so it cannot be narrowed in SQL; loading it once is the whole win.
     *
     * Memoised per instance, deliberately not per process: the refund paths snapshot
     * `effectiveRefundedNet()` before writing a credit note and read it again afterwards, on
     * a fresh instance.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    protected function refundChildren(): \Illuminate\Support\Collection
    {
        return $this->refundChildren ??= self::query()
            ->where('billable_type', $this->billable_type)
            ->where('billable_id', $this->billable_id)
            ->where('invoice_kind', InvoiceKind::Refund)
            ->get(['line_items']);
    }

    public function isFullyRefunded(): bool
    {
        // Against what this invoice holds, which for every invoice that collected its own
        // payment is `amount_net`. A settled plan-switch document's header is zero, so read
        // off the header it could never be "fully refunded" — the panel kept offering the
        // refund of an invoice with nothing left.
        $base = $this->refundableBaseNet();

        return $base > 0 && $this->effectiveRefundedNet() >= $base;
    }

    public function remainingRefundableNet(): int
    {
        return max(0, $this->refundableBaseNet() - $this->effectiveRefundedNet());
    }

    /**
     * The value this invoice itself holds, before anything was credited back out of it.
     *
     * Its own charges only — lines carrying a `parent_invoice_id` are credits *against
     * another* invoice, not value this one collected. For an ordinary invoice that is
     * every line, so this equals `amount_net` (a coupon line is negative and has no
     * parent, so it still reduces the base exactly as it reduces the header).
     *
     * The difference is the saldo-zero plan-switch invoice
     * ({@see InvoiceService::createPlanSwitchInvoice()}), which persists a charge for the
     * new plan next to the credit that paid for it. Its header nets to zero because no new
     * money moved — but the charge line is real, unused service the customer has paid for
     * out of the previous invoice, and a later change in the same period has to be able to
     * credit it. Read off the header, the pool was `max(0, 0 − 0)` and every candidate line
     * from such an invoice was silently dropped, so a third change in one period credited
     * the components the switch had consumed at 0.
     *
     * The books stay balanced across the two documents: the switch only exists when its
     * charge is fully covered by credits booked against the source invoice, so the source's
     * remaining base drops by exactly what the switch's base gains — together they never
     * exceed what the payment collected.
     */
    /**
     * The invoice whose payment settled this one's charges, if any.
     *
     * Set only by {@see InvoiceService::createPlanSwitchInvoice()}: an equal-price change
     * mid-period charges the new plan and credits the old for the same amount, so the new
     * plan's remaining days are paid for out of the invoice that collected the period. That
     * relationship cannot be expressed by sharing `mollie_payment_id` — it is unique, and
     * that uniqueness is the webhook's idempotency guard.
     */
    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'settled_by_invoice_id');
    }

    /**
     * The Mollie payment a refund of this invoice has to be posted against.
     *
     * Its own, or — for a settled document — the one that actually holds the money.
     *
     * Follows the chain rather than a single hop. `createPlanSwitchInvoice()` now points a
     * settlement at the invoice that really collected the payment, but rows written before
     * that fix can carry a switch→switch link: the guard there tested this accessor, which
     * resolved one hop and therefore accepted a switch invoice as a target. The resulting
     * document answered null here and was unrefundable — a third change in one period could
     * never be paid out. Walking the chain repairs those rows too. The `seen` set is not
     * paranoia: a corrupted cycle would otherwise loop forever inside a webhook.
     */
    public function refundablePaymentId(): ?string
    {
        $invoice = $this;
        $seen = [];

        while ($invoice !== null) {
            if ($invoice->mollie_payment_id !== null) {
                return (string) $invoice->mollie_payment_id;
            }

            $key = (int) $invoice->getKey();

            if ($invoice->settled_by_invoice_id === null || isset($seen[$key])) {
                return null;
            }

            $seen[$key] = true;
            $invoice = $invoice->settledBy;
        }

        return null;
    }

    public function refundableBaseNet(): int
    {
        // An invoice that collected its own payment is measured by its header, full stop:
        // that is the money Mollie took, and the lines can legitimately state something
        // else — a mismatch booked to the paid amount, VAT recomputed after the fact. Only
        // a settled document has to be read line by line, and it is settled precisely
        // because its header cannot describe it.
        if ($this->settled_by_invoice_id === null) {
            return (int) $this->amount_net;
        }

        return $this->ownChargeTotal('amount_net', (int) $this->amount_net);
    }

    /**
     * `refundableBaseNet()`'s gross counterpart — the ceiling a refund of this invoice may
     * not exceed at Mollie. Same rule, same reason: a settled plan-switch invoice's header
     * gross is zero while the service its charge line paid for is real.
     */
    public function refundableBaseGross(): int
    {
        if ($this->settled_by_invoice_id === null) {
            return (int) $this->amount_gross;
        }

        return $this->ownChargeTotal('amount_gross', (int) $this->amount_gross);
    }

    /**
     * Sum one amount field over this invoice's own charge lines — the ones that do not carry
     * a `parent_invoice_id`, i.e. are not credits against another invoice.
     *
     * @param  int  $fallback  used when the invoice has no line items at all
     */
    private function ownChargeTotal(string $field, int $fallback): int
    {
        $lines = (array) ($this->line_items ?? []);

        if ($lines === []) {
            return $fallback;
        }

        $total = 0;
        foreach ($lines as $line) {
            if (($line['parent_invoice_id'] ?? null) !== null) {
                continue;
            }

            $total += (int) ($line[$field] ?? 0);
        }

        return $total;
    }

    /**
     * Authoritative refunded-net for this invoice: the larger of the cached
     * `refunded_net` column and the value derived live from refund-invoice
     * line items pointing back at this invoice. The derivation closes the gap
     * for legacy paths that issued refunds without updating the cache column
     * (some plan-change refunds before the bug fix), so refundable accounting
     * stays consistent regardless of which refund path produced the credit
     * note.
     */
    public function effectiveRefundedNet(): int
    {
        return max((int) $this->refunded_net, $this->derivedRefundedNet());
    }

    /**
     * Sum the absolute net of every refund-invoice line item that references
     * this invoice via `parent_invoice_id`. Returns 0 if no refund children
     * exist.
     */
    public function derivedRefundedNet(): int
    {
        $sum = 0;

        foreach ($this->refundChildren() as $child) {
            foreach ((array) ($child->line_items ?? []) as $line) {
                if ((int) ($line['parent_invoice_id'] ?? 0) !== (int) $this->getKey()) {
                    continue;
                }
                $sum += self::creditSign($line) * (int) abs((int) ($line['amount_net'] ?? 0));
            }
        }

        return max(0, $sum);
    }

    /**
     * +1 for a credit line, −1 for a line that takes a credit back out of the books.
     *
     * A refund Mollie later reports as `failed` or `canceled` never reached the customer,
     * so its credit note is undone by a reversal document
     * ({@see \GraystackIT\MollieBilling\Services\Billing\InvoiceService::reverseBookedCredit()}).
     * That document is a Refund-kind child too and points at the same original line —
     * which every reader here would have added on top, since they sum magnitudes. The
     * flag is what tells a reversal from a credit; the amount's own sign cannot, because
     * the readers have always been tolerant of either spelling.
     *
     * @param  array<string, mixed>  $line
     */
    public static function creditSign(array $line): int
    {
        return ($line['reverses_credit'] ?? false) === true ? -1 : 1;
    }

    /**
     * Net and VAT already credited back per original line item, keyed by the
     * line's index in this invoice's `line_items`.
     *
     * Read from every refund-invoice line that points at this invoice via
     * `parent_invoice_id` **and** carries a `parent_line_item_index`. Credit
     * lines without that index (custom `line_items` handed to the refund
     * service) cannot be attributed to a single line and are therefore absent
     * here — invoice-level accounting still sees them through
     * {@see derivedRefundedNet()}.
     *
     * Credit-note amounts are stored negative; the sums are absolute so callers
     * can subtract them from what the original charged.
     *
     * @return array<int, array{net: int, vat: int}>
     */
    public function creditedPerOriginalLine(): array
    {
        $credited = [];

        foreach ($this->refundChildren() as $child) {
            foreach ((array) ($child->line_items ?? []) as $line) {
                if ((int) ($line['parent_invoice_id'] ?? 0) !== (int) $this->getKey()) {
                    continue;
                }
                if (! isset($line['parent_line_item_index'])) {
                    continue;
                }

                $index = (int) $line['parent_line_item_index'];
                $credited[$index] ??= ['net' => 0, 'vat' => 0];
                $credited[$index]['net'] += self::creditSign($line) * abs((int) ($line['amount_net'] ?? 0));
                $credited[$index]['vat'] += self::creditSign($line) * abs((int) ($line['vat_amount'] ?? 0));
            }
        }

        foreach ($credited as $index => $totals) {
            $credited[$index] = ['net' => max(0, $totals['net']), 'vat' => max(0, $totals['vat'])];
        }

        return $credited;
    }

    /**
     * VAT already credited back against this invoice, counting **every** credit line
     * that points at it — with or without a `parent_line_item_index`.
     *
     * {@see creditedPerOriginalLine()} deliberately skips unindexed lines, because
     * they cannot be attributed to one original line. Summing that map to answer the
     * invoice-wide question "how much VAT is left to credit" therefore under-counts:
     * a credit note created with custom `line_items` was invisible, and the next
     * automatic refund credited VAT that had already been given back.
     */
    public function creditedVatTotal(): int
    {
        $credited = 0;

        foreach ($this->refundChildren() as $child) {
            foreach ((array) ($child->line_items ?? []) as $line) {
                if ((int) ($line['parent_invoice_id'] ?? 0) !== (int) $this->getKey()) {
                    continue;
                }

                $credited += self::creditSign($line) * abs((int) ($line['vat_amount'] ?? 0));
            }
        }

        return max(0, $credited);
    }

    public function hasPdf(): bool
    {
        return $this->pdf_path !== null;
    }

    public function getDownloadUrl(): ?string
    {
        // Deliberately NOT gated on `hasPdf()`. A render that failed — a transient disk
        // outage, a DOMPDF font error, a currency the renderer refused — leaves the row
        // with no `pdf_path`, and the invoice then had no link at all: the customer could
        // see the charge and never obtain the document, permanently, with no way to ask for
        // it. `InvoiceDownloadController` regenerates on demand and 404s only when that
        // fails too, which is the honest outcome to show.
        $billable = $this->billable()->first();
        if (! $billable) {
            return null;
        }

        $parameters = MollieBilling::resolveUrlParameters($billable);
        $parameters['invoice'] = $this;

        return route(BillingRoute::name('invoice.download'), $parameters);
    }

    /**
     * A single line item by index, or null.
     *
     * @return array<string, mixed>|null
     */
    public function lineItem(int $index): ?array
    {
        $items = $this->line_items ?? [];

        return $items[$index] ?? null;
    }

    /**
     * Total seat count derived from this invoice's line_items.
     *
     * Sums the plan's includedSeats (from catalog, looked up by plan-line code)
     * plus all seats-line quantities. This is the authoritative seat count for
     * the period this invoice covers — apps should sync it back to the billable's
     * subscription_meta.seat_count after each successful recurring charge so that
     * future plan-change pro-rata math operates on the truly paid-for state.
     *
     * Returns null if the invoice has no plan-line (e.g. mid-cycle activation
     * invoice that only covers seats/addons without a plan).
     */
    public function deriveSeatCount(): ?int
    {
        $planCode = null;
        $extraSeats = 0;

        foreach ((array) ($this->line_items ?? []) as $line) {
            $kind = $line['kind'] ?? null;
            if ($kind === 'plan') {
                $planCode = $line['code'] ?? null;
            } elseif (in_array($kind, ['seats', 'seat'], true)) {
                $extraSeats += (int) ($line['quantity'] ?? 0);
            }
        }

        if ($planCode === null) {
            return null;
        }

        $includedSeats = app(\GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface::class)
            ->includedSeats((string) $planCode);

        return $includedSeats + $extraSeats;
    }

    /**
     * All paid original line items whose period contains the current moment
     * (line_item.period_start <= now <= line_item.period_end), filtered by (kind, optional code),
     * that have not yet been fully refunded.
     *
     * Sorted by line.period_start DESC, secondary by line_index DESC (deterministic).
     *
     * remaining_quantity = original_line.quantity - already_refunded_quantity (sum of all
     * refund invoice line_items with parent_invoice_id=invoice.id, parent_line_item_index=line_index).
     *
     * @return list<array{invoice: self, line_index: int, remaining_quantity: int}>
     */
    public static function currentPeriodLines(Billable $billable, string $kind, ?string $code = null): array
    {
        if (! ($billable instanceof Model)) {
            return [];
        }

        // Accept both singular and plural seat kinds to stay compatible with line items
        // written before the kind alias was normalized.
        $kindAliases = match ($kind) {
            'seats', 'seat' => ['seats', 'seat'],
            default => [$kind],
        };

        // Authoritative current period: the billable's active subscription window.
        // Line items only count when they fall within this window — anything from prior
        // (cancelled / migrated / mid-cycle) periods must be excluded so refunds do not
        // touch invoices the user has long left behind.
        $billablePeriodStart = $billable->getBillingPeriodStartsAt();
        $billablePeriodEnd = $billable->nextBillingDate();

        if ($billablePeriodStart === null || $billablePeriodEnd === null) {
            return [];
        }

        // 1. All charge invoices (Subscription/OneTimeOrder/Overage) for the billable with paid status,
        //    that have at least one line with (kind, optionally code) whose line.period contains now.
        // A refund candidate has to be an invoice a refund can actually be paid out of, and
        // `createRefund()` needs a `mollie_payment_id` to post one. The only document here
        // without one is the saldo-zero plan-switch invoice
        // ({@see InvoiceService::createPlanSwitchInvoice()}), which is a Subscription and is
        // Paid, so it was offered — and since candidates are sorted newest-invoice-first, it
        // won over the invoice that actually holds the money. Its header net is 0, so
        // `applyRemainingRefundablePool()` read a budget of `max(0, 0 − 0)` and dropped every
        // line it had just selected; on the seat and addon paths the loop had already
        // decremented its remaining quantity for the discarded candidate, so the real
        // invoice's line was never reached at all. A downgrade after an equally-priced
        // sidegrade therefore credited nothing.
        $invoices = self::query()
            ->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $billable->getKey())
            ->where('status', InvoiceStatus::Paid)
            ->where(function ($q): void {
                // A candidate has to be an invoice a refund can actually be posted against.
                // Either it collected a payment itself, or its charges were settled out of one
                // that did — the saldo-zero plan-switch invoice is the second kind, and
                // excluding it left a third change in one period crediting nothing.
                $q->whereNotNull('mollie_payment_id')->orWhereNotNull('settled_by_invoice_id');
            })
            ->whereIn('invoice_kind', [InvoiceKind::Subscription, InvoiceKind::OneTimeOrder, InvoiceKind::Overage])
            ->get();

        // 2. Pre-load all refund invoices of the billable for the quantity calculation.
        $refundInvoices = self::query()
            ->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $billable->getKey())
            ->where('invoice_kind', InvoiceKind::Refund)
            ->get();

        // Quantity aggregation for per-unit refunds (e.g. reducing a seat).
        // ONLY structured refund lines (kind plan/seats/addon) count — generic
        // cash refund lines (kind 'refund', from the webhook after a dashboard refund or
        // goodwill full refund) refund a monetary amount at the invoice level and
        // must not be counted as "1 plan unit consumed", otherwise a single
        // cash refund would block all further plan changes.
        $alreadyRefunded = []; // [parent_invoice_id][parent_line_item_index] => quantity
        foreach ($refundInvoices as $refund) {
            foreach ((array) ($refund->line_items ?? []) as $rline) {
                $pid = $rline['parent_invoice_id'] ?? null;
                $pidx = $rline['parent_line_item_index'] ?? null;
                if ($pid === null || $pidx === null) {
                    continue;
                }
                if (! in_array(($rline['kind'] ?? null), ['plan', 'seats', 'seat', 'addon', 'addon_seats'], true)) {
                    continue;
                }
                // A reversed refund gives its units back: the money never left, so the
                // quantity it had consumed is refundable again.
                $alreadyRefunded[$pid][$pidx] = ($alreadyRefunded[$pid][$pidx] ?? 0)
                    + self::creditSign($rline) * (int) ($rline['quantity'] ?? 0);
            }
        }

        $result = [];
        foreach ($invoices as $invoice) {
            foreach ((array) ($invoice->line_items ?? []) as $idx => $line) {
                if (! in_array(($line['kind'] ?? null), $kindAliases, true)) {
                    continue;
                }
                if ($code !== null && ($line['code'] ?? null) !== $code) {
                    continue;
                }

                // Resolve period: line, then invoice (no billable fallback —
                // periodless items must not be retroactively counted toward the current period).
                $rawStart = $line['period_start'] ?? $invoice->period_start;
                $rawEnd = $line['period_end'] ?? $invoice->period_end;
                if ($rawStart === null || $rawEnd === null) {
                    continue;
                }

                $start = \GraystackIT\MollieBilling\Support\BillingTime::toUtc($rawStart);
                $end = \GraystackIT\MollieBilling\Support\BillingTime::toUtc($rawEnd);

                // Line must fall within the current billable subscription period:
                // - line_start is not after billable.period_end (not in the future)
                // - line_end matches billable.period_end (tolerance 1 day).
                //   This excludes items from old/superseded periods whose period_end
                //   does not match the current billable period (e.g. monthly invoices after
                //   switching to yearly).
                if ($start->greaterThan($billablePeriodEnd)) {
                    continue;
                }
                if (abs($end->diffInDays($billablePeriodEnd, false)) > 1) {
                    continue;
                }

                // Negative lines are credits, not charges. The plan-switch invoice persists
                // its refund lines alongside its charge lines under the same `plan`/`seats`/
                // `addon` kinds, so without this a credit already granted was offered up as
                // something further to credit. Zero stays a candidate: a fully coupon-covered
                // line has no cash to give back but still holds quantity, and dropping it
                // would let the same unit be credited twice from an older invoice.
                if ((int) ($line['amount_net'] ?? 0) < 0) {
                    continue;
                }

                $originalQty = (int) ($line['quantity'] ?? 0);
                $refundedQty = (int) ($alreadyRefunded[$invoice->getKey()][$idx] ?? 0);
                $remaining = max(0, $originalQty - $refundedQty);

                if ($remaining <= 0) {
                    continue;
                }

                $result[] = [
                    'invoice' => $invoice,
                    'line_index' => $idx,
                    'remaining_quantity' => $remaining,
                    '_period_start' => $start, // internal, used for sorting
                    '_invoice_id' => (int) $invoice->getKey(), // internal, used for sorting
                ];
            }
        }

        // Sort by period_start DESC, then invoice_id DESC, then line_index DESC.
        // The invoice_id tiebreaker is important because multiple invoices in the same
        // subscription period (e.g. mid-cycle seat purchases) share the same period_start.
        // We want to refund the NEWEST invoice first — otherwise the refund runs
        // against a Mollie payment that has already been fully refunded (Mollie 409).
        usort($result, function (array $a, array $b): int {
            $cmp = $b['_period_start']->getTimestamp() <=> $a['_period_start']->getTimestamp();
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = $b['_invoice_id'] <=> $a['_invoice_id'];
            if ($cmp !== 0) {
                return $cmp;
            }
            return $b['line_index'] <=> $a['line_index'];
        });

        // Remove the sort keys.
        return array_map(fn ($r) => [
            'invoice' => $r['invoice'],
            'line_index' => $r['line_index'],
            'remaining_quantity' => $r['remaining_quantity'],
        ], $result);
    }

}
