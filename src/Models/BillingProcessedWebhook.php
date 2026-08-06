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
    public static function outcomeSignature(string $paymentId, string $status, int $refundedCents = 0): string
    {
        return $refundedCents > 0
            ? $paymentId.':'.$status.':refunded:'.$refundedCents
            : $paymentId.':'.$status;
    }

    /**
     * @deprecated Use {@see outcomeSignature()}, which also encodes the refund state.
     */
    public static function finalSignature(string $paymentId, string $status): string
    {
        return self::outcomeSignature($paymentId, $status);
    }
}
