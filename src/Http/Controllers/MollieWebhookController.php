<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Http\Controllers;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\MandateUpdated;
use GraystackIT\MollieBilling\Events\OverageCharged;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
use GraystackIT\MollieBilling\Events\PaymentFailed;
use GraystackIT\MollieBilling\Events\PaymentSucceeded;
use GraystackIT\MollieBilling\Events\SubscriptionCreated;
use GraystackIT\MollieBilling\Exceptions\PaymentNotFoundException;
use GraystackIT\MollieBilling\Jobs\RetryUsageOverageChargeJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingProcessedWebhook;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\InvoiceAvailableNotification;
use GraystackIT\MollieBilling\Notifications\SubscriptionPaymentFailedNotification;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\CreateSubscription;
use GraystackIT\MollieBilling\Services\Billing\MollieSalesInvoiceService;
use GraystackIT\MollieBilling\Services\Vat\CountryMatchService;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Mollie\Laravel\Facades\Mollie;

class MollieWebhookController extends Controller
{
    public function __construct(
        protected readonly MollieSalesInvoiceService $salesInvoiceService,
        protected readonly VatCalculationService $vatService,
        protected readonly SubscriptionCatalogInterface $catalog,
        protected readonly CreateSubscription $createSubscription,
        protected readonly CountryMatchService $countryMatchService,
        protected readonly WalletUsageService $walletService,
        protected readonly CouponService $couponService,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $paymentId = (string) $request->input('id', '');

        if ($paymentId === '') {
            return response('', 200);
        }

        $reservation = $this->reserve($paymentId);
        if ($reservation === null) {
            return response('', 200);
        }

        try {
            $payment = $this->fetchPayment($paymentId);
        } catch (PaymentNotFoundException) {
            $reservation->delete();
            return response('', 200);
        } catch (\Throwable $e) {
            Log::warning('Mollie API lookup failed during webhook', ['id' => $paymentId, 'error' => $e->getMessage()]);
            $reservation->delete();
            return response('', 503);
        }

        $billable = $this->resolveBillableFromMetadata($payment);

        try {
            $this->route($payment, $billable);

            $reservation->update([
                'event_signature' => BillingProcessedWebhook::finalSignature($paymentId, (string) ($payment->status ?? 'unknown')),
                'processed_at' => now(),
            ]);

            return response('', 200);
        } catch (\Throwable $e) {
            Log::error('Billing webhook handler failed', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $reservation->delete();
            return response('', 500);
        }
    }

    protected function reserve(string $paymentId): ?BillingProcessedWebhook
    {
        $signature = BillingProcessedWebhook::pendingSignature($paymentId);

        $existingFinal = BillingProcessedWebhook::query()
            ->where('mollie_payment_id', $paymentId)
            ->where('event_signature', '!=', $signature)
            ->whereNotNull('processed_at')
            ->exists();

        if ($existingFinal) {
            return null;
        }

        $reservation = BillingProcessedWebhook::firstOrCreate(
            ['mollie_payment_id' => $paymentId, 'event_signature' => $signature],
            ['received_at' => now()],
        );

        if (! $reservation->wasRecentlyCreated) {
            return null;
        }

        return $reservation;
    }

    protected function fetchPayment(string $paymentId): object
    {
        try {
            $client = Mollie::api();
            /** @phpstan-ignore-next-line — SDK magic property. */
            return $client->payments->get($paymentId);
        } catch (\Mollie\Api\Exceptions\ApiException $e) {
            if (method_exists($e, 'getCode') && $e->getCode() === 404) {
                throw new PaymentNotFoundException($paymentId);
            }
            throw $e;
        }
    }

    protected function route(object $payment, ?Billable $billable): void
    {
        $status = (string) ($payment->status ?? 'unknown');
        $metadata = (array) ($payment->metadata ?? []);
        if (is_object($payment->metadata ?? null)) {
            $metadata = json_decode(json_encode($payment->metadata), true) ?: [];
        }

        $type = (string) ($metadata['type'] ?? '');
        $subscriptionId = (string) ($payment->subscriptionId ?? '');

        if ($status === 'paid') {
            if ($billable === null) {
                Log::warning('Paid webhook with unresolvable billable', ['id' => $payment->id]);
                return;
            }

            if ($type === 'mandate_only') {
                $this->handleMandateOnlyPaid($payment, $billable);
                return;
            }

            if ($type === 'overage' || $type === 'prorata') {
                $this->handleSingleChargePaid($payment, $billable, $type, $metadata);
                return;
            }

            if ($this->hasRefunds($payment)) {
                $this->handleRefundWebhook($payment, $billable);
                return;
            }

            if ($subscriptionId !== '') {
                $this->handleSubscriptionPaymentPaid($payment, $billable, $metadata);
                return;
            }

            $this->handleFirstPaymentPaid($payment, $billable, $metadata);
            return;
        }

        if (in_array($status, ['failed', 'canceled', 'expired'], true)) {
            if ($billable === null) {
                return;
            }

            if ($type === 'overage' || $type === 'prorata') {
                $this->handleSingleChargeFailed($payment, $billable, $type);
                return;
            }

            $this->handleSubscriptionPaymentFailed($payment, $billable);
        }
    }

    protected function handleMandateOnlyPaid(object $payment, Billable $billable): void
    {
        if (! ($billable instanceof \Illuminate\Database\Eloquent\Model)) {
            return;
        }

        $previousMandate = $billable->mollie_mandate_id;

        $billable->forceFill([
            'mollie_customer_id' => (string) ($payment->customerId ?? $billable->mollie_customer_id),
            'mollie_mandate_id' => (string) ($payment->mandateId ?? $billable->mollie_mandate_id),
        ])->save();

        event(new MandateUpdated($billable, $previousMandate, $billable->mollie_mandate_id));
    }

    protected function handleFirstPaymentPaid(object $payment, Billable $billable, array $metadata): void
    {
        if (! ($billable instanceof \Illuminate\Database\Eloquent\Model)) {
            return;
        }

        $planCode = (string) ($metadata['plan_code'] ?? '');
        $interval = (string) ($metadata['interval'] ?? 'monthly');
        $addonCodes = (array) ($metadata['addon_codes'] ?? []);
        $extraSeats = (int) ($metadata['extra_seats'] ?? 0);

        if ($planCode === '') {
            return;
        }

        $billable->forceFill([
            'mollie_customer_id' => (string) ($payment->customerId ?? $billable->mollie_customer_id),
            'mollie_mandate_id' => (string) ($payment->mandateId ?? $billable->mollie_mandate_id),
            'tax_country_payment' => strtoupper((string) ($payment->countryCode ?? $billable->tax_country_payment ?? '')),
        ])->save();

        try {
            $this->countryMatchService->check($billable);
        } catch (\Throwable $e) {
            Log::warning('Country match check failed', ['error' => $e->getMessage()]);
        }

        $lineItems = $this->buildSubscriptionLineItems($planCode, $interval, $addonCodes, $extraSeats, $billable);

        $invoice = $this->salesInvoiceService->createForPayment($payment, 'subscription', $lineItems, $billable);

        $amountGrossNet = $this->amountFromMolliePayment($payment);

        try {
            $this->createSubscription->handle($billable, [
                'plan_code' => $planCode,
                'interval' => $interval,
                'addon_codes' => $addonCodes,
                'extra_seats' => $extraSeats,
                'amount_gross' => $amountGrossNet,
                'mandate_id' => $billable->mollie_mandate_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('CreateSubscription during webhook failed', ['error' => $e->getMessage()]);
        }

        $billable->forceFill([
            'subscription_source' => SubscriptionSource::Mollie,
            'subscription_status' => SubscriptionStatus::Active,
            'subscription_period_starts_at' => now(),
        ])->save();

        $this->applyPendingCreditsCoupons($billable);

        event(new SubscriptionCreated($billable, $planCode, $interval));
        event(new PaymentSucceeded($billable, $invoice));

        $this->notifyInvoiceAvailable($billable, $invoice);
    }

    protected function handleSubscriptionPaymentPaid(object $payment, Billable $billable, array $metadata): void
    {
        if (! ($billable instanceof \Illuminate\Database\Eloquent\Model)) {
            return;
        }

        $planCode = $billable->getBillingSubscriptionPlanCode() ?? (string) ($metadata['plan_code'] ?? '');
        $interval = $billable->getBillingSubscriptionInterval() ?? (string) ($metadata['interval'] ?? 'monthly');
        $addonCodes = $billable->getActiveBillingAddonCodes();
        $extraSeats = $billable->getExtraBillingSeats();

        $expectedNet = $this->computeNet($planCode, $interval, $addonCodes, $extraSeats, $billable);
        $vat = $this->vatService->calculate((string) ($billable->getBillingCountry() ?? ''), $expectedNet, $billable->vat_number);
        $actualGross = $this->amountFromMolliePayment($payment);

        if (abs($actualGross - (int) $vat['gross']) > 1) {
            event(new PaymentAmountMismatch($billable, (string) $payment->id, (int) $vat['gross'], $actualGross));
        }

        $lineItems = $this->buildSubscriptionLineItems($planCode, $interval, $addonCodes, $extraSeats, $billable);
        $invoice = $this->salesInvoiceService->createForPayment($payment, 'subscription', $lineItems, $billable);

        foreach ($addonCodes as $ignored) {
            // placeholder — line item construction already accounts for addons.
        }

        // Recharge wallets: add included usage on top of current balance.
        foreach ($this->catalog->includedUsages($planCode, $interval) as $type => $units) {
            try {
                $this->walletService->credit($billable, (string) $type, (int) $units);
            } catch (\Throwable $e) {
                Log::warning('Wallet credit failed during webhook', ['type' => $type, 'error' => $e->getMessage()]);
            }
        }

        $paidAt = $payment->paidAt ?? null;
        $periodStartsAt = $paidAt ? \Carbon\Carbon::parse((string) $paidAt) : now();

        $billable->forceFill([
            'subscription_period_starts_at' => $periodStartsAt,
        ])->save();

        event(new PaymentSucceeded($billable, $invoice));

        $this->notifyInvoiceAvailable($billable, $invoice);
    }

    protected function handleSingleChargePaid(object $payment, Billable $billable, string $type, array $metadata): void
    {
        if (! ($billable instanceof \Illuminate\Database\Eloquent\Model)) {
            return;
        }

        $lineItems = (array) ($metadata['line_items'] ?? []);
        if (empty($lineItems)) {
            $actual = $this->amountFromMolliePayment($payment);
            $lineItems = [[
                'kind' => $type,
                'label' => ucfirst($type).' charge',
                'quantity' => 1,
                'unit_price_net' => $actual,
                'unit_price' => $actual,
                'total_net' => $actual,
            ]];
        }

        $invoice = $this->salesInvoiceService->createForPayment($payment, $type, $lineItems, $billable);

        if ($type === 'overage') {
            $meta = $billable->getBillingSubscriptionMeta();
            unset($meta['usage_overage'], $meta['usage_overage_status'], $meta['usage_overage_attempts']);
            $billable->forceFill(['subscription_meta' => $meta])->save();

            event(new OverageCharged($billable, $invoice, $lineItems));
        }

        if ($type === 'prorata') {
            $meta = $billable->getBillingSubscriptionMeta();
            unset($meta['prorata_pending_payment_id']);
            $billable->forceFill(['subscription_meta' => $meta])->save();
        }

        event(new PaymentSucceeded($billable, $invoice));
        $this->notifyInvoiceAvailable($billable, $invoice);
    }

    protected function handleSubscriptionPaymentFailed(object $payment, Billable $billable): void
    {
        if (! ($billable instanceof \Illuminate\Database\Eloquent\Model)) {
            return;
        }

        $meta = $billable->getBillingSubscriptionMeta();
        $meta['payment_failure'] = [
            'payment_id' => (string) $payment->id,
            'failed_at' => now()->toIso8601String(),
            'reason' => (string) ($payment->details->failureReason ?? $payment->status ?? 'unknown'),
        ];

        $billable->forceFill([
            'subscription_status' => SubscriptionStatus::PastDue,
            'subscription_meta' => $meta,
        ])->save();

        event(new PaymentFailed($billable, (string) $payment->id, $meta['payment_failure']['reason']));

        $recipients = MollieBilling::notifyBillingAdmins($billable);
        if (! empty($recipients)) {
            Notification::send($recipients, new SubscriptionPaymentFailedNotification($billable, (string) $payment->id));
        }
    }

    protected function handleSingleChargeFailed(object $payment, Billable $billable, string $type): void
    {
        if ($type === 'overage' && $billable instanceof \Illuminate\Database\Eloquent\Model) {
            RetryUsageOverageChargeJob::dispatch(
                $billable->getMorphClass(),
                $billable->getKey(),
                1,
            );
            return;
        }

        event(new PaymentFailed($billable, (string) $payment->id, (string) ($payment->status ?? 'unknown')));
    }

    protected function handleRefundWebhook(object $payment, Billable $billable): void
    {
        $refunds = [];
        try {
            /** @phpstan-ignore-next-line — SDK magic. */
            $refunds = $payment->refunds();
        } catch (\Throwable) {
            return;
        }

        foreach ($refunds as $refund) {
            $refundAmountCents = (int) round(((float) ($refund->amount->value ?? 0)) * 100);

            $exists = BillingInvoice::query()
                ->where('invoice_kind', 'credit_note')
                ->where('amount_gross', -$refundAmountCents)
                ->whereHas('parent', fn ($q) => $q->where('mollie_payment_id', (string) $payment->id))
                ->exists();

            if ($exists) {
                continue;
            }

            $original = BillingInvoice::query()
                ->where('mollie_payment_id', (string) $payment->id)
                ->first();

            if ($original === null) {
                continue;
            }

            // Convert gross refund to net using the original invoice rate.
            $rate = (float) $original->vat_rate;
            $netAmount = $rate > 0
                ? (int) round($refundAmountCents / (1 + $rate / 100))
                : $refundAmountCents;

            $creditNote = $this->salesInvoiceService->createCreditNote($original, $netAmount);
            $creditNote->refund_reason_code = \GraystackIT\MollieBilling\Enums\RefundReasonCode::Other;
            $creditNote->refund_reason_text = 'synced from Mollie dashboard';
            $creditNote->save();

            $original->refunded_net = (int) $original->refunded_net + $netAmount;
            $original->save();
        }
    }

    protected function resolveBillableFromMetadata(object $payment): ?Billable
    {
        $metadata = (array) ($payment->metadata ?? []);
        if (is_object($payment->metadata ?? null)) {
            $metadata = json_decode(json_encode($payment->metadata), true) ?: [];
        }

        $type = (string) ($metadata['billable_type'] ?? '');
        $id = $metadata['billable_id'] ?? null;

        if ($type === '' || $id === null || ! class_exists($type)) {
            return null;
        }

        return $type::find($id);
    }

    protected function applyPendingCreditsCoupons(Billable $billable): void
    {
        $recent = $billable->redeemedBillingCoupons()
            ->with('coupon')
            ->where('applied_at', '>=', now()->subHour())
            ->get();

        foreach ($recent as $redemption) {
            $coupon = $redemption->coupon;
            if ($coupon && $coupon->type?->value === 'credits') {
                try {
                    $this->couponService->applyCreditsToWallets($billable, $redemption);
                } catch (\Throwable $e) {
                    Log::warning('Failed to apply credits coupon', ['error' => $e->getMessage()]);
                }
            }
        }
    }

    /**
     * @param  array<int, string>  $addonCodes
     * @return array<int, array<string, mixed>>
     */
    protected function buildSubscriptionLineItems(string $planCode, string $interval, array $addonCodes, int $extraSeats, Billable $billable): array
    {
        $items = [];

        $base = $this->catalog->basePriceNet($planCode, $interval);
        $items[] = [
            'kind' => 'plan',
            'label' => $this->catalog->planName($planCode) ?? $planCode,
            'code' => $planCode,
            'quantity' => 1,
            'unit_price' => $base,
            'unit_price_net' => $base,
            'total_net' => $base,
        ];

        if ($extraSeats > 0) {
            $seat = (int) ($this->catalog->seatPriceNet($planCode, $interval) ?? 0);
            $items[] = [
                'kind' => 'seat',
                'label' => 'Extra seats',
                'code' => null,
                'quantity' => $extraSeats,
                'unit_price' => $seat,
                'unit_price_net' => $seat,
                'total_net' => $seat * $extraSeats,
            ];
        }

        foreach ($addonCodes as $code) {
            $price = $this->catalog->addonPriceNet($code, $interval);
            $qty = $billable->getBillingAddonQuantity($code) ?: 1;
            $items[] = [
                'kind' => 'addon',
                'label' => $code,
                'code' => $code,
                'quantity' => $qty,
                'unit_price' => $price,
                'unit_price_net' => $price,
                'total_net' => $price * $qty,
            ];
        }

        return $items;
    }

    protected function computeNet(string $planCode, string $interval, array $addonCodes, int $extraSeats, Billable $billable): int
    {
        $sum = $this->catalog->basePriceNet($planCode, $interval);
        $sum += $extraSeats * (int) ($this->catalog->seatPriceNet($planCode, $interval) ?? 0);
        foreach ($addonCodes as $code) {
            $qty = $billable->getBillingAddonQuantity($code) ?: 1;
            $sum += $qty * $this->catalog->addonPriceNet($code, $interval);
        }

        return $sum;
    }

    protected function amountFromMolliePayment(object $payment): int
    {
        return (int) round(((float) ($payment->amount->value ?? 0)) * 100);
    }

    protected function hasRefunds(object $payment): bool
    {
        if (property_exists($payment, 'amountRefunded') && is_object($payment->amountRefunded)) {
            return ((float) ($payment->amountRefunded->value ?? 0)) > 0;
        }

        return false;
    }

    protected function notifyInvoiceAvailable(Billable $billable, BillingInvoice $invoice): void
    {
        $recipients = MollieBilling::notifyBillingAdmins($billable);
        if (empty($recipients)) {
            return;
        }

        Notification::send($recipients, new InvoiceAvailableNotification($billable, $invoice));
    }
}
