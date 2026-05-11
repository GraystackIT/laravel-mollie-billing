<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\DuplicatePaymentReceived;
use GraystackIT\MollieBilling\Events\PaymentSucceeded;
use GraystackIT\MollieBilling\Events\SubscriptionCreated;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\CreateSubscription;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class FirstPaymentHandler
{
    public function __construct(
        protected readonly WebhookSupport $support,
        protected readonly SubscriptionCatalogInterface $catalog,
        protected readonly FirstPaymentArtifacts $firstPaymentArtifacts,
        protected readonly FirstPaymentCouponPricing $firstPaymentCouponPricing,
        protected readonly CreateSubscription $createSubscription,
        protected readonly WalletUsageService $walletService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function handle(object $payment, Billable $billable, array $metadata): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        $planCode = (string) ($metadata['plan_code'] ?? '');
        $interval = (string) ($metadata['interval'] ?? 'monthly');
        $addonCodes = (array) ($metadata['addon_codes'] ?? []);
        $extraSeats = (int) ($metadata['extra_seats'] ?? 0);

        if ($planCode === '') {
            return;
        }

        if ($billable->hasAccessibleBillingSubscription()) {
            Log::warning('Duplicate first-payment received — billable already has an active subscription', [
                'billable_id' => $billable->getKey(),
                'payment_id' => $payment->id ?? null,
            ]);

            DuplicatePaymentReceived::dispatch($billable, (string) ($payment->id ?? ''));

            return;
        }

        if ($this->support->invoiceAlreadyExistsForPayment($payment)) {
            Log::info('Webhook re-delivery: invoice exists for first-payment, skipping', [
                'payment_id' => $payment->id ?? null,
                'billable_id' => $billable->getKey(),
            ]);
            return;
        }

        $invoice = $this->firstPaymentArtifacts->persist($payment, $billable, $planCode, $interval, $addonCodes, $extraSeats);

        $pricedCoupon = $this->firstPaymentCouponPricing->price(
            $billable,
            (string) ($metadata['coupon_code'] ?? ''),
            $planCode,
            $interval,
            $addonCodes,
            $extraSeats,
        );
        $recurringDiscountNet = $pricedCoupon['recurring_discount_net'] ?? 0;

        try {
            $this->createSubscription->handle($billable, [
                'plan_code' => $planCode,
                'interval' => $interval,
                'addon_codes' => $addonCodes,
                'extra_seats' => $extraSeats,
                'recurring_discount_net' => $recurringDiscountNet,
                'mandate_id' => $billable->mollie_mandate_id,
            ]);
        } catch (\Throwable $e) {
            $this->support->reportSubscriptionActivationFailure(
                $billable,
                $planCode,
                $interval,
                $invoice,
                $payment,
                'CreateSubscription during webhook failed',
                $e,
            );
            event(new PaymentSucceeded($billable, $invoice));
            $this->support->notifyInvoiceAvailable($billable, $invoice);

            return;
        }

        if ($pricedCoupon !== null) {
            $this->firstPaymentCouponPricing->redeem(
                $billable,
                $pricedCoupon,
                $planCode,
                $interval,
                (int) $invoice->id,
            );
        }

        $billable->forceFill([
            'subscription_source' => SubscriptionSource::Mollie,
            'subscription_status' => SubscriptionStatus::Active,
            'subscription_period_starts_at' => BillingTime::nowUtc(),
        ])->save();

        foreach ($this->catalog->includedUsages($planCode, $interval) as $type => $quantity) {
            if ((int) $quantity > 0) {
                $this->walletService->credit($billable, (string) $type, (int) $quantity, 'subscription_activation');
            }
        }

        event(new SubscriptionCreated($billable, $planCode, $interval));
        event(new PaymentSucceeded($billable, $invoice));

        $this->support->notifyInvoiceAvailable($billable, $invoice);

        MollieBilling::runAfterCheckout($billable, true);
    }
}
