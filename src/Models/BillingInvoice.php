<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Models;

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Support\BillingRoute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

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
            'vat_rate' => 'decimal:2',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
        ];
    }

    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_invoice_id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(self::class, 'parent_invoice_id');
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

        if (! $this->billable()) {
            return null;
        }

        $billable = $this->billable()->first();
        $parameters = MollieBilling::resolveUrlParameters($billable);
        $parameters['invoice'] = $this;

        return route(BillingRoute::name('invoice.download'), $parameters);
    }
}
