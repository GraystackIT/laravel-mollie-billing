<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Support\MandateSummary;
use Mollie\Api\Http\Requests\GetMandateRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * Single source of truth for "what does this billable's active payment method
 * look like?". Used by both the customer-facing billing portal and the admin
 * panel — the DTO it returns ({@see MandateSummary}) renders translatable
 * fields lazily so each side picks up its own locale.
 *
 * No caching: the answer is read live on every call. Fail-soft — any Mollie
 * error returns null so the surrounding view can fall back to a "no method on
 * file" state instead of erroring out.
 */
class MollieMandateInspector
{
    public function inspect(Billable $billable): ?MandateSummary
    {
        $customerId = $billable->getMollieCustomerId();
        $mandateId = method_exists($billable, 'getMollieMandateId')
            ? $billable->getMollieMandateId()
            : ($billable->mollie_mandate_id ?? null);

        if (! is_string($customerId) || $customerId === '') {
            return null;
        }
        if (! is_string($mandateId) || $mandateId === '') {
            return null;
        }

        try {
            $mandate = Mollie::send(new GetMandateRequest($customerId, $mandateId));
        } catch (\Throwable) {
            return null;
        }

        $details = is_object($mandate->details ?? null)
            ? json_decode(json_encode($mandate->details), true) ?: []
            : (array) ($mandate->details ?? []);

        $signatureDate = null;
        if (! empty($mandate->signatureDate)) {
            try {
                $signatureDate = \Carbon\Carbon::parse((string) $mandate->signatureDate)->setTimezone('UTC');
            } catch (\Throwable) {
                $signatureDate = null;
            }
        }

        return new MandateSummary(
            id: (string) $mandate->id,
            status: (string) ($mandate->status ?? 'unknown'),
            method: (string) ($mandate->method ?? 'unknown'),
            details: $details,
            mandateReference: isset($mandate->mandateReference) ? (string) $mandate->mandateReference : null,
            signatureDate: $signatureDate,
        );
    }
}
