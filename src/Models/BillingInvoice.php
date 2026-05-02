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

    public function isFullyRefunded(): bool
    {
        return $this->refunded_net >= $this->amount_net && $this->amount_net > 0;
    }

    public function remainingRefundableNet(): int
    {
        return max(0, $this->amount_net - $this->refunded_net);
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
     * Tage von $reference bis line_item.period_end (immer relativ zu jetzt — der
     * $reference-Parameter ist legacy und wird ignoriert; Single-Source-of-Truth
     * ist BillingPolicy::prorataPeriodDays).
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
     * Alle paid Original-Line-Items, deren Periode den aktuellen Zeitpunkt umfasst
     * (line_item.period_start <= now <= line_item.period_end), gefiltert nach (kind, optional code),
     * die noch nicht vollständig refundiert wurden.
     *
     * Sortiert nach line.period_start DESC, secondary nach line_index DESC (deterministisch).
     *
     * remaining_quantity = original_line.quantity - bereits_refundierte_quantity (Summe aller
     * Refund-Invoice-line_items mit parent_invoice_id=invoice.id, parent_line_item_index=line_index).
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

        // 1. Alle Charge-Invoices (Subscription/OneTimeOrder/Overage) für den Billable mit paid status,
        //    die mind. eine line haben mit (kind, ggf. code) und deren line.period den now umfasst.
        $invoices = self::query()
            ->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $billable->getKey())
            ->where('status', InvoiceStatus::Paid)
            ->whereIn('invoice_kind', [InvoiceKind::Subscription, InvoiceKind::OneTimeOrder, InvoiceKind::Overage])
            ->get();

        // 2. Pre-load alle Refund-Invoices des Billables für die Mengenrechnung.
        $refundInvoices = self::query()
            ->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $billable->getKey())
            ->where('invoice_kind', InvoiceKind::Refund)
            ->get();

        $alreadyRefunded = []; // [parent_invoice_id][parent_line_item_index] => quantity
        foreach ($refundInvoices as $refund) {
            foreach ((array) ($refund->line_items ?? []) as $rline) {
                $pid = $rline['parent_invoice_id'] ?? null;
                $pidx = $rline['parent_line_item_index'] ?? null;
                if ($pid === null || $pidx === null) {
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

                // Periode auflösen: Line, dann Invoice (kein Billable-Fallback —
                // periodenlose Items dürfen nicht rückwirkend zur aktuellen Periode gezählt werden).
                $rawStart = $line['period_start'] ?? $invoice->period_start;
                $rawEnd = $line['period_end'] ?? $invoice->period_end;
                if ($rawStart === null || $rawEnd === null) {
                    continue;
                }

                $start = \GraystackIT\MollieBilling\Support\BillingTime::toUtc($rawStart);
                $end = \GraystackIT\MollieBilling\Support\BillingTime::toUtc($rawEnd);

                // Line muss in der aktuellen Billable-Subscription-Periode laufen:
                // - line_start liegt nicht nach billable.period_end (nicht in der Zukunft)
                // - line_end stimmt mit billable.period_end überein (Toleranz 1 Tag).
                //   Damit fallen Items aus alten/abgelösten Perioden raus, deren period_end
                //   nicht zur aktuellen Billable-Periode passt (z.B. monatliche Invoices nach
                //   Wechsel auf jährlich).
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
                    '_period_start' => $start, // intern für Sortierung
                ];
            }
        }

        // Sortierung nach period_start DESC, secondary line_index DESC.
        usort($result, function (array $a, array $b): int {
            $cmp = $b['_period_start']->getTimestamp() <=> $a['_period_start']->getTimestamp();
            if ($cmp !== 0) {
                return $cmp;
            }
            return $b['line_index'] <=> $a['line_index'];
        });

        // Sortierungs-Schlüssel entfernen.
        return array_map(fn ($r) => [
            'invoice' => $r['invoice'],
            'line_index' => $r['line_index'],
            'remaining_quantity' => $r['remaining_quantity'],
        ], $result);
    }

}
