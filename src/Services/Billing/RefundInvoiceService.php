<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Events\InvoiceRefunded;
use GraystackIT\MollieBilling\Events\WalletCredited;
use GraystackIT\MollieBilling\Exceptions\InvalidRefundTargetException;
use GraystackIT\MollieBilling\Exceptions\RefundExceedsInvoiceAmountException;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Notifications\AdminRefundFailedNotification;
use GraystackIT\MollieBilling\Notifications\RefundProcessedNotification;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Laravel\Facades\Mollie;

class RefundInvoiceService
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly WalletUsageService $wallet,
    ) {
    }

    /**
     * @param  array{amount_net?: ?int, wallet_credits?: array<string,int>, reason_code: RefundReasonCode, reason_text?: ?string, notify_user?: bool}  $request
     */
    public function refund(BillingInvoice $invoice, array $request): BillingInvoice
    {
        $reasonCode = $request['reason_code'] ?? throw new \InvalidArgumentException('reason_code is required.');
        $reasonText = $request['reason_text'] ?? null;
        $walletCredits = $request['wallet_credits'] ?? [];
        $notifyUser = $request['notify_user'] ?? true;

        if ($reasonCode === RefundReasonCode::Other && ($reasonText === null || trim($reasonText) === '')) {
            throw new \InvalidArgumentException('reason_text is required when reason_code is Other.');
        }

        if ($invoice->status !== InvoiceStatus::Paid) {
            throw new InvalidRefundTargetException('Only paid invoices can be refunded.');
        }

        if (empty($invoice->mollie_payment_id)) {
            throw new InvalidRefundTargetException('Invoice has no Mollie payment id.');
        }

        return DB::transaction(function () use ($invoice, $request, $reasonCode, $reasonText, $walletCredits, $notifyUser): BillingInvoice {
            $invoice = BillingInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            $refundAmountNet = $request['amount_net'] ?? $invoice->remainingRefundableNet();

            if ($refundAmountNet <= 0) {
                throw new \InvalidArgumentException('Refund amount must be positive.');
            }

            if ($refundAmountNet > $invoice->remainingRefundableNet()) {
                throw new RefundExceedsInvoiceAmountException(
                    "Refund of {$refundAmountNet} exceeds remaining refundable amount {$invoice->remainingRefundableNet()}."
                );
            }

            $rate = (float) $invoice->vat_rate;
            $refundVat = (int) round($refundAmountNet * $rate / 100);
            $refundGross = $refundAmountNet + $refundVat;

            /** @var Billable|null $billable */
            $billable = $invoice->billable()->first();

            try {
                $this->callMollieRefund($invoice->mollie_payment_id, $refundGross);
            } catch (\Throwable $e) {
                Log::warning('Mollie refund failed for invoice '.$invoice->id, ['exception' => $e->getMessage()]);

                $recipients = MollieBilling::notifyAdmin();
                if ($billable !== null && ! empty($recipients)) {
                    Notification::send($recipients, new AdminRefundFailedNotification($billable, $e));
                }

                throw $e;
            }

            $creditNote = $this->invoices->createCreditNote($invoice, $refundAmountNet);
            $creditNote->refund_reason_code = $reasonCode;
            $creditNote->refund_reason_text = $reasonText;
            $creditNote->save();

            $invoice->refunded_net = (int) $invoice->refunded_net + $refundAmountNet;
            $invoice->save();

            if ($billable !== null) {
                foreach ($walletCredits as $usageType => $units) {
                    $this->wallet->credit($billable, (string) $usageType, (int) $units);
                }

                event(new InvoiceRefunded($billable, $invoice, $creditNote, $request));

                if ($notifyUser) {
                    $recipients = MollieBilling::notifyBillingAdmins($billable);
                    if (! empty($recipients)) {
                        Notification::send($recipients, new RefundProcessedNotification($billable, $creditNote));
                    }
                }
            }

            return $creditNote;
        });
    }

    public function refundFully(BillingInvoice $invoice, RefundReasonCode $reason, ?string $reasonText = null): BillingInvoice
    {
        $walletCredits = [];

        if ($invoice->invoice_kind === 'overage') {
            foreach ((array) $invoice->line_items as $item) {
                $type = $item['code'] ?? $item['type'] ?? null;
                $qty = (int) ($item['quantity'] ?? 0);

                if ($type !== null && $qty > 0) {
                    $walletCredits[$type] = ($walletCredits[$type] ?? 0) + $qty;
                }
            }
        }

        return $this->refund($invoice, [
            'amount_net' => null,
            'wallet_credits' => $walletCredits,
            'reason_code' => $reason,
            'reason_text' => $reasonText,
        ]);
    }

    public function refundPartially(BillingInvoice $invoice, int $amountNet, RefundReasonCode $reason, ?string $reasonText = null): BillingInvoice
    {
        return $this->refund($invoice, [
            'amount_net' => $amountNet,
            'wallet_credits' => [],
            'reason_code' => $reason,
            'reason_text' => $reasonText,
        ]);
    }

    public function refundOverageUnits(BillingInvoice $invoice, string $usageType, int $units, RefundReasonCode $reason, ?string $reasonText = null): BillingInvoice
    {
        if ($invoice->invoice_kind !== 'overage') {
            throw new InvalidRefundTargetException('refundOverageUnits only applies to overage invoices.');
        }

        $unitPrice = $this->unitPriceFor($invoice, $usageType);

        return $this->refund($invoice, [
            'amount_net' => $unitPrice * $units,
            'wallet_credits' => [$usageType => $units],
            'reason_code' => $reason,
            'reason_text' => $reasonText,
        ]);
    }

    /**
     * @param  array<string,int>  $unitsPerType
     */
    public function refundOverageUnitsBulk(BillingInvoice $invoice, array $unitsPerType, RefundReasonCode $reason, ?string $reasonText = null): BillingInvoice
    {
        if ($invoice->invoice_kind !== 'overage') {
            throw new InvalidRefundTargetException('refundOverageUnitsBulk only applies to overage invoices.');
        }

        $amountNet = 0;
        foreach ($unitsPerType as $type => $units) {
            $amountNet += $this->unitPriceFor($invoice, (string) $type) * (int) $units;
        }

        return $this->refund($invoice, [
            'amount_net' => $amountNet,
            'wallet_credits' => $unitsPerType,
            'reason_code' => $reason,
            'reason_text' => $reasonText,
        ]);
    }

    public function refundOverageMoneyOnly(BillingInvoice $invoice, ?int $amountNet, RefundReasonCode $reason, ?string $reasonText = null): BillingInvoice
    {
        return $this->refund($invoice, [
            'amount_net' => $amountNet,
            'wallet_credits' => [],
            'reason_code' => $reason,
            'reason_text' => $reasonText,
        ]);
    }

    public function creditWalletOnly(Billable $billable, string $usageType, int $units, string $reasonText): void
    {
        $this->wallet->credit($billable, $usageType, $units);
        event(new WalletCredited($billable, $usageType, $units, $reasonText));
    }

    private function unitPriceFor(BillingInvoice $invoice, string $usageType): int
    {
        foreach ((array) $invoice->line_items as $item) {
            $type = $item['code'] ?? $item['type'] ?? null;
            if ($type === $usageType) {
                return (int) ($item['unit_price_net'] ?? $item['unit_price'] ?? 0);
            }
        }

        throw new InvalidRefundTargetException("Usage type {$usageType} not found in invoice line items.");
    }

    protected function callMollieRefund(string $paymentId, int $grossCents): void
    {
        Mollie::send(new CreatePaymentRefundRequest(
            paymentId: $paymentId,
            amount: new Money(
                (string) config('mollie-billing.currency', 'EUR'),
                number_format($grossCents / 100, 2, '.', ''),
            ),
        ));
    }
}
