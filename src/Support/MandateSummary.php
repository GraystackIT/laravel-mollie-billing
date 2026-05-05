<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

/**
 * Read-only DTO for an inspected Mollie mandate. Returned by
 * {@see \GraystackIT\MollieBilling\Services\Billing\MollieMandateInspector}.
 *
 * Translatable display fields (statusLabel, methodLabel, summary, expires)
 * are resolved on demand against the *current* app locale, so the same DTO
 * renders correctly in the customer portal (whatever the user's locale is)
 * and in the admin panel (forced English via AdminLocale).
 */
final class MandateSummary
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly string $method,
        public readonly array $details,
        public readonly ?string $mandateReference,
        public readonly ?\Carbon\CarbonInterface $signatureDate,
    ) {}

    public function statusColor(): string
    {
        return match ($this->status) {
            'valid' => 'lime',
            'pending' => 'amber',
            'invalid' => 'red',
            default => 'zinc',
        };
    }

    public function statusLabel(): string
    {
        return __('billing::portal.payment_method.status.'.$this->status);
    }

    public function methodLabel(): string
    {
        return __('billing::portal.payment_method.method.'.$this->method);
    }

    public function cardLast4(): ?string
    {
        if ($this->method !== 'creditcard') {
            return null;
        }

        $number = $this->details['cardNumber'] ?? null;

        return $number !== null && $number !== '' ? substr((string) $number, -4) : null;
    }

    public function ibanSuffix(): ?string
    {
        if ($this->method !== 'directdebit') {
            return null;
        }

        $iban = $this->details['consumerAccount'] ?? null;
        if ($iban === null || $iban === '') {
            return null;
        }

        return substr(str_replace(' ', '', (string) $iban), -4);
    }

    /**
     * Account identifier formatted for display: `•••• 1234` for card / IBAN,
     * full email for PayPal. Null for methods we don't render an account for.
     */
    public function accountDisplay(): ?string
    {
        return match ($this->method) {
            'creditcard' => ($l = $this->cardLast4()) !== null ? '•••• '.$l : null,
            'directdebit' => ($l = $this->ibanSuffix()) !== null ? '•••• '.$l : null,
            'paypal' => isset($this->details['consumerAccount'])
                ? (string) $this->details['consumerAccount']
                : null,
            default => null,
        };
    }

    public function holder(): ?string
    {
        $value = $this->details['cardHolder'] ?? $this->details['consumerName'] ?? null;

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    public function expiry(): ?\Carbon\CarbonInterface
    {
        $expiry = $this->details['cardExpiryDate'] ?? null;
        if ($expiry === null || $expiry === '') {
            return null;
        }

        try {
            return \Carbon\Carbon::parse((string) $expiry);
        } catch (\Throwable) {
            return null;
        }
    }

    public function isExpired(): bool
    {
        $expiry = $this->expiry();

        return $expiry !== null && $expiry->isPast();
    }

    public function isExpiringSoon(int $days = 60): bool
    {
        $expiry = $this->expiry();

        return $expiry !== null
            && ! $expiry->isPast()
            && $expiry->lessThan(now()->addDays($days));
    }

    /**
     * Flatten to the legacy array shape used by the portal billing-data view.
     * Translatable fields (statusLabel/methodLabel/summary) are resolved
     * against the locale active at call time, so the portal sees its own
     * locale and the admin sees forced English.
     *
     * @return array{
     *     id: string,
     *     status: string,
     *     statusColor: string,
     *     statusLabel: string,
     *     method: string,
     *     methodLabel: string,
     *     details: array<string, mixed>,
     *     mandateReference: ?string,
     *     signatureDate: ?\Carbon\CarbonInterface,
     *     summary: ?string,
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'statusColor' => $this->statusColor(),
            'statusLabel' => $this->statusLabel(),
            'method' => $this->method,
            'methodLabel' => $this->methodLabel(),
            'details' => $this->details,
            'mandateReference' => $this->mandateReference,
            'signatureDate' => $this->signatureDate,
            'summary' => $this->summary(),
        ];
    }

    /**
     * Human-readable single-line summary, e.g. "Visa •••• 4242 · Exp 12/2027"
     * for cards or "Jane Doe · NL00 INGB 0123 4567 89" for SEPA.
     */
    public function summary(): ?string
    {
        if ($this->method === 'creditcard') {
            $label = $this->details['cardLabel'] ?? __('billing::portal.payment_method.method.creditcard');
            $parts = [(string) $label];
            if (($account = $this->accountDisplay()) !== null) {
                $parts[] = $account;
            }
            $summary = implode(' ', $parts);

            $expiry = $this->expiry();
            if ($expiry !== null) {
                $summary .= ' · '.__('billing::portal.payment_method.expires', [
                    'date' => $expiry->format('m/Y'),
                ]);
            }

            return $summary;
        }

        if ($this->method === 'directdebit') {
            $parts = array_filter([
                $this->holder(),
                isset($this->details['consumerAccount']) ? (string) $this->details['consumerAccount'] : null,
            ]);

            return $parts === [] ? null : implode(' · ', $parts);
        }

        if ($this->method === 'paypal') {
            return isset($this->details['consumerAccount'])
                ? (string) $this->details['consumerAccount']
                : null;
        }

        return null;
    }
}
