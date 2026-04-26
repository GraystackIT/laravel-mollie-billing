<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Models;

use GraystackIT\MollieBilling\Contracts\Billable;
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

    /**
     * The paid Subscription invoice that paid for the currently running plan period.
     *
     * Used as the source of truth for VAT rate / country / vat_number on prorata
     * charges and refunds: we are legally required to reverse a charge with the
     * exact VAT treatment that was originally applied — not the customer's
     * current B2B/B2C status, which may have changed mid-period.
     *
     * Returns null when no such invoice exists (e.g. local→Mollie transition,
     * or first period before any payment was recorded). Callers must handle that.
     */
    public static function currentPeriodSubscriptionInvoice(Billable $billable): ?self
    {
        if (! ($billable instanceof Model)) {
            return null;
        }

        $now = now();

        return self::query()
            ->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $billable->getKey())
            ->where('invoice_kind', InvoiceKind::Subscription)
            ->where('status', InvoiceStatus::Paid)
            ->where('period_start', '<=', $now)
            ->where('period_end', '>=', $now)
            ->latest('period_start')
            ->first();
    }
}
