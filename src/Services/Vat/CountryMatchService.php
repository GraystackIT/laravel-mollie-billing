<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Vat;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Events\CountryMismatchFlagged;
use GraystackIT\MollieBilling\Events\CountryMismatchResolved;
use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\CountryMismatchNotification;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;

class CountryMatchService
{
    public function __construct(
        private readonly VatCalculationService $vat,
        private readonly InvoiceService $salesInvoices,
    ) {
    }

    /**
     * Compare user-declared and payment-method countries.
     * Flags a mismatch (idempotent) when:
     *   - both are present and differ, or
     *   - tax_country_payment is present but tax_country_user is missing
     *     (a data bug upstream — recorded as user='?' so admins can act).
     */
    public function check(Billable $billable): void
    {
        /** @var Model $model */
        $model = $billable;

        $countries = [
            'tax_country_user' => $this->normalize($model->getAttribute('tax_country_user')),
            'tax_country_payment' => $this->normalize($model->getAttribute('tax_country_payment')),
        ];

        if ($countries['tax_country_payment'] === null) {
            // Nothing to compare against yet (e.g. before first payment).
            return;
        }

        if ($countries['tax_country_user'] === null) {
            $this->flag($billable, [
                'tax_country_user' => '?',
                'tax_country_payment' => $countries['tax_country_payment'],
            ]);

            return;
        }

        if ($countries['tax_country_user'] !== $countries['tax_country_payment']) {
            $this->flag($billable, $countries);
        }
    }

    /**
     * Flag a mismatch — idempotent: if a row with the same (user, payment)
     * already exists for this billable in any non-final state, do nothing.
     *
     * @param  array{tax_country_user:?string,tax_country_payment:?string}  $countries
     */
    public function flag(Billable $billable, array $countries): ?BillingCountryMismatch
    {
        /** @var Model $model */
        $model = $billable;

        $query = BillingCountryMismatch::query()
            ->where('billable_type', $model->getMorphClass())
            ->where('billable_id', $model->getKey())
            ->where('tax_country_user', $countries['tax_country_user'] ?? '');

        $paymentCountry = $countries['tax_country_payment'] ?? null;
        $paymentCountry === null
            ? $query->whereNull('tax_country_payment')
            : $query->where('tax_country_payment', $paymentCountry);

        $existing = $query->first();

        if ($existing !== null) {
            return null;
        }

        $mismatch = BillingCountryMismatch::create([
            'billable_type' => $model->getMorphClass(),
            'billable_id' => $model->getKey(),
            'tax_country_user' => $countries['tax_country_user'] ?? '',
            'tax_country_payment' => $countries['tax_country_payment'] ?? null,
            'status' => CountryMismatchStatus::Pending,
        ]);

        $model->forceFill(['country_mismatch_flagged_at' => BillingTime::nowUtc()])->save();

        CountryMismatchFlagged::dispatch($billable, $mismatch);

        $notifiables = MollieBilling::notifyAdmin();
        if (! empty($notifiables)) {
            Notification::send(
                $notifiables,
                new CountryMismatchNotification($billable, $mismatch),
            );
        }

        return $mismatch;
    }

    public function resolve(Billable $billable, BillingCountryMismatch $mismatch, mixed $resolvedBy): void
    {
        $resolvedById = $resolvedBy instanceof Model ? $resolvedBy->getKey() : $resolvedBy;

        $mismatch->forceFill([
            'status' => CountryMismatchStatus::Resolved,
            'resolved_at' => BillingTime::nowUtc(),
            'resolved_by_user_id' => $resolvedById,
        ])->save();

        CountryMismatchResolved::dispatch($billable, $mismatch, $resolvedBy);

        // If a corrective VAT rate differs from the original invoice's plan-line rate, issue a credit note.
        $original = $billable->latestBillingInvoice();
        if ($original instanceof BillingInvoice) {
            $newCountry = $this->normalize($mismatch->tax_country_user);
            if ($newCountry !== null) {
                try {
                    $newRate = $this->vat->vatRateFor($newCountry);
                    $originalRate = $this->originalPlanVatRate($original);

                    if ($originalRate !== null && abs($newRate - $originalRate) > 0.001) {
                        $this->salesInvoices->createCreditNote($original, $original->amount_net);
                    }
                } catch (\Throwable) {
                    // Non-EU / unknown country during reconciliation: skip auto-credit-note.
                }
            }
        }
    }

    /**
     * Liest die VAT-Rate des Plan-Line-Items aus der Original-Invoice.
     * Multi-VAT-Invoices können mehrere Raten haben — die Plan-Line ist hier die referenzielle.
     */
    private function originalPlanVatRate(BillingInvoice $invoice): ?float
    {
        foreach ((array) ($invoice->line_items ?? []) as $line) {
            if (($line['kind'] ?? null) === 'plan' && isset($line['vat_rate'])) {
                return (float) $line['vat_rate'];
            }
        }
        // Fallback: erste Line.
        $first = ($invoice->line_items ?? [])[0] ?? null;
        return $first !== null && isset($first['vat_rate']) ? (float) $first['vat_rate'] : null;
    }

    private function normalize(mixed $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return strtoupper((string) $code);
    }
}
