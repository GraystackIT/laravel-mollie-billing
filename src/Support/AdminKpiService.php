<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Aggregates admin dashboard KPIs (MRR, ARR, churn, trial conversion, …) with a short TTL cache.
 */
class AdminKpiService
{
    /**
     * Period length from which an invoice is treated as covering a year. Sits between a
     * long month (31) and a short year (365) so no real period can land on the wrong side.
     */
    private const YEARLY_PERIOD_DAYS = 300;

    /**
     * Monthly recurring revenue (in cents) from paid subscription invoices of the last
     * 30 days, normalised to monthly (yearly invoices /12).
     *
     * Summed per invoice rather than as one SQL `sum()`: the normalisation factor
     * depends on the period each invoice covers, and a single yearly customer summed
     * unnormalised reported twelve times the recurring revenue it actually represents
     * — with `arr()` multiplying that by twelve again.
     *
     * **What this measures, and what it does not.** The window is *invoiced revenue*, so a
     * customer contributes only in the 30 days around their own renewal. On a yearly base
     * that means the tile reads near zero for eleven months of the year — it is not the
     * contract-value MRR of the active subscription base. Changing that would mean pricing
     * every Active billable from the catalog instead, which is a different metric with a
     * different meaning for prorata and mid-cycle charges; the two-way split below is the
     * deliberate counterpart of this choice. Bind your own `AdminKpiService` if you want
     * the contract-value definition.
     */
    public function mrr(): int
    {
        return (int) $this->cached('mrr', function (): int {
            // Fallback spelled out: `safely()` answers `null` when there is no invoice
            // table (or the query fails) and this closure is typed `int`, so the missing
            // argument turned the safety net itself into a TypeError — the admin layout
            // 500ed on exactly the boot-time state the net exists for.
            return $this->safely(function (): int {
                return $this->monthlyNetBetween(BillingTime::nowUtc()->subDays(30), BillingTime::nowUtc());
            }, 0);
        });
    }

    public function arr(): int
    {
        return $this->mrr() * 12;
    }

    /** @return array<string, int> */
    public function activeSubscriptionsByStatus(): array
    {
        return $this->cached('active_subs', function (): array {
            $billableClass = config('mollie-billing.billable_model');
            $defaults = [
                SubscriptionStatus::New->value => 0,
                SubscriptionStatus::Active->value => 0,
                SubscriptionStatus::Trial->value => 0,
                SubscriptionStatus::Cancelled->value => 0,
                SubscriptionStatus::PastDue->value => 0,
                SubscriptionStatus::Expired->value => 0,
            ];

            if (! $billableClass || ! class_exists($billableClass)) {
                return $defaults;
            }

            return $this->safely(function () use ($billableClass, $defaults): array {
                $rows = $billableClass::query()
                    ->selectRaw('subscription_status, count(*) as count')
                    ->groupBy('subscription_status')
                    ->pluck('count', 'subscription_status')
                    ->all();

                foreach ($rows as $status => $count) {
                    $defaults[$status] = (int) $count;
                }

                return $defaults;
            }, $defaults);
        });
    }

    public function churnRate(int $days = 30): float
    {
        return (float) $this->cached('churn_'.$days, function () use ($days): float {
            $billableClass = config('mollie-billing.billable_model');
            if (! $billableClass || ! class_exists($billableClass)) {
                return 0.0;
            }

            return $this->safely(function () use ($billableClass, $days): float {
                $now = BillingTime::nowUtc();
                $since = $now->copy()->subDays($days);

                // Bounded on both sides. With only a lower bound this counted every
                // cancellation whose grace period ends in the FUTURE — a yearly customer
                // who cancelled eleven months ago and keeps access until next month was
                // churn today, and the number never decayed. A cancellation counts when
                // the access it was paid for actually ended, inside the window.
                $cancelled = (int) $billableClass::query()
                    ->where('subscription_status', SubscriptionStatus::Cancelled)
                    ->whereBetween('subscription_ends_at', [$since, $now])
                    ->count();

                $active = (int) $billableClass::query()
                    ->where('subscription_status', SubscriptionStatus::Active)
                    ->count();

                $total = $active + $cancelled;

                return $total === 0 ? 0.0 : round($cancelled / $total, 4);
            }, 0.0);
        });
    }

    /**
     * Share of the trials that *ended* in the window and left a usable mandate behind.
     *
     * One cohort, one filter, deliberately: the previous version counted a numerator of
     * "has a mandate" against a denominator of "is Expired", two overlapping sets. A
     * customer who converted and cancelled months later appeared in both, reading as a
     * 50% conversion for a single converted trial — while an unpaid trial sitting in
     * `PastDue` (where the lifecycle job parks it before expiry) appeared in neither and
     * silently lifted the rate. Trials still running are excluded rather than counted as
     * failures.
     */
    public function trialConversionRate(int $days = 90): float
    {
        return (float) $this->cached('trial_conv_'.$days, function () use ($days): float {
            $billableClass = config('mollie-billing.billable_model');
            if (! $billableClass || ! class_exists($billableClass)) {
                return 0.0;
            }

            return $this->safely(function () use ($billableClass, $days): float {
                $now = BillingTime::nowUtc();
                $since = $now->copy()->subDays($days);

                // Trials that ran out. Converting a trial nulls `trial_ends_at` (both
                // conversion paths do), so this set holds only the ones that did NOT
                // convert — which is why keying the whole cohort on this column reported
                // 0 % for every healthy business: the numerator could never be filled.
                $lapsed = (int) $billableClass::query()
                    ->whereNotNull('trial_ends_at')
                    ->whereBetween('trial_ends_at', [$since, $now])
                    ->count();

                // The conversions themselves, from the marker the two conversion paths
                // stamp. A fact rather than the old "has a mandate" proxy, which also
                // matched a customer who converted a year ago and cancelled since.
                // ISO-8601 UTC strings share one offset, so they order lexicographically.
                $converted = (int) $billableClass::query()
                    ->whereNotNull('subscription_meta->trial_converted_at')
                    ->whereBetween('subscription_meta->trial_converted_at', [
                        $since->toIso8601String(),
                        $now->toIso8601String(),
                    ])
                    ->count();

                $total = $lapsed + $converted;

                return $total === 0 ? 0.0 : round($converted / $total, 4);
            }, 0.0);
        });
    }

    public function openOverageCharges(): int
    {
        return (int) $this->cached('open_overage', function (): int {
            $billableClass = config('mollie-billing.billable_model');
            if (! $billableClass || ! class_exists($billableClass)) {
                return 0;
            }

            return $this->safely(function () use ($billableClass): int {
                $sum = 0;
                $key = (new $billableClass)->getKeyName();

                // chunkById, not chunk: chunk() paginates with LIMIT/OFFSET over an
                // unordered query, so a row can be served in two pages (counted twice)
                // or in none at all. The key column has to be selected for it to work.
                $billableClass::query()
                    ->whereNotNull('subscription_meta')
                    ->select([$key, 'subscription_meta'])
                    ->chunkById(200, function ($billables) use (&$sum): void {
                        foreach ($billables as $b) {
                            $meta = $b->subscription_meta ?? [];
                            $sum += (int) ($meta['usage_overage']['amount_net'] ?? 0);
                        }
                    });

                return $sum;
            }, 0);
        });
    }

    /** @return array<int, array{month: string, mrr: int}> */
    public function mrrTrend(int $months = 12): array
    {
        return (array) $this->cached('mrr_trend_'.$months, function () use ($months): array {
            return $this->safely(function () use ($months): array {
                $out = [];

                for ($i = $months - 1; $i >= 0; $i--) {
                    $start = BillingTime::nowUtc()->subMonths($i)->startOfMonth();
                    $end = $start->endOfMonth();

                    $out[] = [
                        'month' => $start->format('Y-m'),
                        'mrr' => $this->monthlyNetBetween($start, $end),
                    ];
                }

                return $out;
            }, []);
        });
    }

    /**
     * Sum of every paid subscription invoice created in the window, each contributing
     * only its monthly share.
     *
     * Streamed with `cursor()` over three columns: the window holds roughly one row per
     * paying customer per period, and hydrating all of them to sum an integer is the one
     * part of this class that grows with the customer base.
     */
    private function monthlyNetBetween(\Carbon\CarbonInterface $from, \Carbon\CarbonInterface $to): int
    {
        $sum = 0;

        BillingInvoice::query()
            ->where('invoice_kind', InvoiceKind::Subscription)
            ->where('status', InvoiceStatus::Paid)
            ->whereBetween('created_at', [$from, $to])
            ->select(['amount_net', 'refunded_net', 'period_start', 'period_end'])
            ->cursor()
            ->each(function (BillingInvoice $invoice) use (&$sum): void {
                $sum += $this->monthlyShareOf($invoice);
            });

        return $sum;
    }

    /**
     * The share of one invoice's net that belongs to a single month.
     *
     * The catalog only knows monthly and yearly intervals, so the covered period is a
     * reliable discriminator and no interval column is needed on the invoice. It is
     * deliberately a two-way split rather than a linear days→month scaling: prorata and
     * mid-cycle charges are {@see InvoiceKind::Subscription} too, and scaling a
     * three-day prorata line up to a full month would inflate MRR tenfold. Anything
     * shorter than a year therefore counts once, in full.
     */
    private function monthlyShareOf(BillingInvoice $invoice): int
    {
        // Net of what was given back. A fully refunded invoice — a billing error corrected
        // the same week — kept contributing its full value to MRR and ARR for the rest of
        // the window: revenue the customer no longer has. The cached column is used rather
        // than `effectiveRefundedNet()`, which would read every credit note's line items
        // once per invoice in a cursor that already grows with the customer base.
        $net = max(0, (int) $invoice->amount_net - (int) $invoice->refunded_net);
        $start = $invoice->period_start;
        $end = $invoice->period_end;

        if ($start === null || $end === null) {
            return $net;
        }

        return abs($start->diffInDays($end)) >= self::YEARLY_PERIOD_DAYS
            ? (int) round($net / 12)
            : $net;
    }

    private function cached(string $key, \Closure $resolver): mixed
    {
        return Cache::remember(
            'mollie-billing:kpi:'.$key,
            (int) config('mollie-billing.admin_kpi_cache_ttl', 300),
            $resolver,
        );
    }

    /**
     * Run a resolver safely — returns the fallback if the underlying query fails
     * (e.g. table missing during boot, migrations not yet run).
     *
     * @template T
     *
     * @param  \Closure(): T  $resolver
     * @param  T  $fallback
     * @return T
     */
    private function safely(\Closure $resolver, mixed $fallback = null): mixed
    {
        try {
            if (! Schema::hasTable('billing_invoices')) {
                return $fallback;
            }

            return $resolver();
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
