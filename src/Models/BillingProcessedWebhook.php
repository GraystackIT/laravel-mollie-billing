<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Models;

use Illuminate\Database\Eloquent\Model;

class BillingProcessedWebhook extends Model
{
    public $timestamps = false;

    protected $table = 'billing_processed_webhooks';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public static function pendingSignature(string $paymentId): string
    {
        return $paymentId.':pending';
    }

    public static function finalSignature(string $paymentId, string $status): string
    {
        return $paymentId.':'.$status;
    }
}
