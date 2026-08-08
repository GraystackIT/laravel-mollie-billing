<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Models;

use GraystackIT\MollieBilling\Casts\UtcDatetime;
use Illuminate\Database\Eloquent\Model;

class BillingProcessedWebhook extends Model
{
    public $timestamps = false;

    protected $table = 'billing_processed_webhooks';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'received_at' => UtcDatetime::class,
            'processed_at' => UtcDatetime::class,
        ];
    }

    /**
     * Signature of an in-progress reservation. The `@` prefix keeps it outside
     * the value space of {@see outcomeSignature()}: `pending` is itself a valid
     * Mollie payment status, so a plain `:pending` suffix would collide with the
     * outcome of a delivery that reported that status.
     */
    public static function pendingSignature(string $paymentId): string
    {
        return $paymentId.':@pending';
    }

    /**
     * Signature of what a delivery actually did — the payment's terminal status
     * plus the refunded total. Deduplication is keyed on this rather than on the
     * payment id alone: Mollie re-delivers the webhook of an already-paid
     * payment whenever a refund is created (the status stays `paid`), and that
     * re-delivery carries new information which has to be processed.
     */
    public static function outcomeSignature(
        string $paymentId,
        string $status,
        int $refundedCents = 0,
        int $chargedBackCents = 0,
    ): string {
        $signature = $paymentId.':'.$status;

        if ($refundedCents > 0) {
            $signature .= ':refunded:'.$refundedCents;
        }

        // Chargebacks need their own component. A chargeback leaves the status at `paid`
        // and touches only `amountChargedBack`, so without this the re-delivery's
        // signature was byte-identical to the paid one already on record and the money
        // leaving went unnoticed: invoice still fully paid, VAT and OSS over-declared,
        // customer still subscribed. Separate from the refund total rather than summed,
        // so a refund and a chargeback of equal value cannot mask one another.
        if ($chargedBackCents > 0) {
            $signature .= ':chargedback:'.$chargedBackCents;
        }

        return $signature;
    }

    /**
     * @deprecated Use {@see outcomeSignature()}, which also encodes the refund state.
     */
    public static function finalSignature(string $paymentId, string $status): string
    {
        return self::outcomeSignature($paymentId, $status);
    }
}
