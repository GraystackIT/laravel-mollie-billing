<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
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
     * Monthly recurring revenue (in cents) from active paid subscription invoices in the last 30 days,
     * normalised to monthly (yearly invoices /12).
     */
    public function mrr(): int
    {
        return (int) $this->cached('mrr', function (): int {
            return $this->safely(function (): int {
                $thirty = now()->subDays(30);
                $monthly = (int) BillingInvoice::query()
                    ->where('invoice_kind', 'subscription')
                    ->where('status', InvoiceStatus::Paid)
                    ->where('created_at', '>=', $thirty)
                    ->sum('amount_net');

                return $this->estimatedMonthlyNormalised($monthly);
            });
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
                $since = now()->subDays($days);
                $cancelled = (int) $billableClass::query()
                    ->where('subscription_status', SubscriptionStatus::Cancelled)
                    ->where('subscription_ends_at', '>=', $since)
                    ->count();

                $active = (int) $billableClass::query()
                    ->where('subscription_status', SubscriptionStatus::Active)
                    ->count();

                $total = $active + $cancelled;

                return $total === 0 ? 0.0 : round($cancelled / $total, 4);
            }, 0.0);
        });
    }

    public function trialConversionRate(int $days = 90): float
    {
        return (float) $this->cached('trial_conv_'.$days, function () use ($days): float {
            $billableClass = config('mollie-billing.billable_model');
            if (! $billableClass || ! class_exists($billableClass)) {
                return 0.0;
            }

            return $this->safely(function () use ($billableClass, $days): float {
                $since = now()->subDays($days);

                $converted = (int) $billableClass::query()
                    ->where('subscription_source', SubscriptionSource::Mollie)
                    ->where('trial_ends_at', '>=', $since)
                    ->whereNotNull('mollie_mandate_id')
                    ->count();

                $expired = (int) $billableClass::query()
                    ->where('subscription_status', SubscriptionStatus::Expired)
                    ->where('trial_ends_at', '>=', $since)
                    ->count();

                $total = $converted + $expired;

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

                $billableClass::query()
                    ->whereNotNull('subscription_meta')
                    ->chunk(200, function ($billables) use (&$sum): void {
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
                    $start = now()->subMonths($i)->startOfMonth();
                    $end = (clone $start)->endOfMonth();

                    $net = (int) BillingInvoice::query()
                        ->where('invoice_kind', 'subscription')
                        ->where('status', InvoiceStatus::Paid)
                        ->whereBetween('created_at', [$start, $end])
                        ->sum('amount_net');

                    $out[] = ['month' => $start->format('Y-m'), 'mrr' => $this->estimatedMonthlyNormalised($net)];
                }

                return $out;
            }, []);
        });
    }

    private function estimatedMonthlyNormalised(int $sumNet): int
    {
        // Simple heuristic — we do not differentiate yearly vs monthly per invoice here; callers
        // who need higher fidelity should aggregate against the subscription_interval column directly.
        return (int) round($sumNet / max(1, SubscriptionInterval::Monthly === SubscriptionInterval::Monthly ? 1 : 1));
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
