<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Models;

use Carbon\CarbonInterface;
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

    public function isFullyRefunded(): bool
    {
        return $this->effectiveRefundedNet() >= $this->amount_net && $this->amount_net > 0;
    }

    public function remainingRefundableNet(): int
    {
        return max(0, $this->amount_net - $this->effectiveRefundedNet());
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
        $children = self::query()
            ->where('billable_type', $this->billable_type)
            ->where('billable_id', $this->billable_id)
            ->where('invoice_kind', InvoiceKind::Refund)
            ->get(['line_items']);

        foreach ($children as $child) {
            foreach ((array) ($child->line_items ?? []) as $line) {
                if ((int) ($line['parent_invoice_id'] ?? 0) !== (int) $this->getKey()) {
                    continue;
                }
                $sum += (int) abs((int) ($line['amount_net'] ?? 0));
            }
        }

        return $sum;
    }

    public function hasPdf(): bool
    {
        return $this->pdf_path !== null;
    }

    public function getDownloadUrl(): ?string
    {
        if (! $this->hasPdf()) {
            return null;
        }

        $billable = $this->billable()->first();
        if (! $billable) {
            return null;
        }

        $parameters = MollieBilling::resolveUrlParameters($billable);
        $parameters['invoice'] = $this;

        return route(BillingRoute::name('invoice.download'), $parameters);
    }

    /**
     * Liefert ein einzelnes line_item per Index oder null.
     *
     * @return array<string, mixed>|null
     */
    public function lineItem(int $index): ?array
    {
        $items = $this->line_items ?? [];

        return $items[$index] ?? null;
    }

    /**
     * Tage von line_item.period_start bis line_item.period_end.
     */
    /**
     * Resolve the period for a single line item with fallback chain:
     * line.period_* → invoice.period_* → null.
     *
     * @param  array<string, mixed>  $line
     * @return array{0: ?CarbonInterface, 1: ?CarbonInterface}
     */
    private function lineItemPeriod(array $line): array
    {
        $rawStart = $line['period_start'] ?? $this->period_start;
        $rawEnd = $line['period_end'] ?? $this->period_end;

        return [
            $rawStart !== null ? \GraystackIT\MollieBilling\Support\BillingTime::toUtc($rawStart) : null,
            $rawEnd !== null ? \GraystackIT\MollieBilling\Support\BillingTime::toUtc($rawEnd) : null,
        ];
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

    public function lineItemDaysActive(int $index): int
    {
        $line = $this->lineItem($index);
        if ($line === null) {
            return 0;
        }

        [$start, $end] = $this->lineItemPeriod($line);
        if ($start === null || $end === null) {
            return 0;
        }

        return \GraystackIT\MollieBilling\Support\BillingPolicy::prorataPeriodDays($start, $end)['total'];
    }

    /**
     * Days from $reference to line_item.period_end (always relative to now — the
     * $reference parameter is legacy and is ignored; the single source of truth
     * is BillingPolicy::prorataPeriodDays).
     */
    public function lineItemDaysRemaining(int $index, ?CarbonInterface $reference = null): int
    {
        $line = $this->lineItem($index);
        if ($line === null) {
            return 0;
        }

        [$start, $end] = $this->lineItemPeriod($line);
        if ($start === null || $end === null) {
            return 0;
        }

        return \GraystackIT\MollieBilling\Support\BillingPolicy::prorataPeriodDays($start, $end)['remaining'];
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
        $invoices = self::query()
            ->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $billable->getKey())
            ->where('status', InvoiceStatus::Paid)
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
                if (! in_array(($rline['kind'] ?? null), ['plan', 'seats', 'seat', 'addon'], true)) {
                    continue;
                }
                $alreadyRefunded[$pid][$pidx] = ($alreadyRefunded[$pid][$pidx] ?? 0) + (int) ($rline['quantity'] ?? 0);
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
