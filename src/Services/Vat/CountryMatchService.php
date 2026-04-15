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
use GraystackIT\MollieBilling\Services\Billing\MollieSalesInvoiceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;

class CountryMatchService
{
    public function __construct(
        private readonly VatCalculationService $vat,
        private readonly MollieSalesInvoiceService $salesInvoices,
    ) {
    }

    /**
     * Compare user-declared, IP, and payment-method countries.
     * If fewer than two of them match, flag a mismatch.
     */
    public function check(Billable $billable): void
    {
        /** @var Model $model */
        $model = $billable;

        $countries = [
            'tax_country_user' => $this->normalize($model->getAttribute('tax_country_user')),
            'tax_country_ip' => $this->normalize($model->getAttribute('tax_country_ip')),
            'tax_country_payment' => $this->normalize($model->getAttribute('tax_country_payment')),
        ];

        $present = array_filter($countries, fn ($c) => $c !== null);

        if (count($present) < 2) {
            // Not enough information to compare yet.
            return;
        }

        $counts = array_count_values($present);
        $maxAgreement = max($counts);

        // Less than 2 of 3 agree -> flag.
        if ($maxAgreement < 2) {
            $this->flag($billable, $countries);
        }
    }

    /**
     * @param  array{tax_country_user:?string,tax_country_ip:?string,tax_country_payment:?string}  $countries
     */
    public function flag(Billable $billable, array $countries): BillingCountryMismatch
    {
        /** @var Model $model */
        $model = $billable;

        $mismatch = BillingCountryMismatch::create([
            'billable_type' => $model->getMorphClass(),
            'billable_id' => $model->getKey(),
            'tax_country_user' => $countries['tax_country_user'] ?? '',
            'tax_country_ip' => $countries['tax_country_ip'] ?? null,
            'tax_country_payment' => $countries['tax_country_payment'] ?? null,
            'status' => CountryMismatchStatus::Pending,
        ]);

        $model->forceFill(['country_mismatch_flagged_at' => now()])->save();

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
            'resolved_at' => now(),
            'resolved_by_user_id' => $resolvedById,
        ])->save();

        CountryMismatchResolved::dispatch($billable, $mismatch, $resolvedBy);

        // If a corrective VAT rate differs from the original invoice's rate, issue a credit note.
        $original = $billable->latestBillingInvoice();
        if ($original instanceof BillingInvoice) {
            $newCountry = $this->normalize($mismatch->tax_country_user);
            if ($newCountry !== null) {
                try {
                    $newRate = $this->vat->vatRateFor($newCountry);
                    $originalRate = (float) $original->vat_rate;

                    if (abs($newRate - $originalRate) > 0.001) {
                        $this->salesInvoices->createCreditNote($original, $original->amount_net);
                    }
                } catch (\Throwable) {
                    // Non-EU / unknown country during reconciliation: skip auto-credit-note.
                }
            }
        }
    }

    private function normalize(mixed $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return strtoupper((string) $code);
    }
}
