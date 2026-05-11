<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Events\PaymentSucceeded;
use GraystackIT\MollieBilling\Events\SubscriptionUpgradedFromLocal;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\CreateSubscription;
use GraystackIT\MollieBilling\Services\Wallet\WalletPlanChangeAdjuster;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class LocalToMollieUpgradeHandler
{
    public function __construct(
        protected readonly WebhookSupport $support,
        protected readonly FirstPaymentArtifacts $firstPaymentArtifacts,
        protected readonly FirstPaymentCouponPricing $firstPaymentCouponPricing,
        protected readonly CreateSubscription $createSubscription,
        protected readonly WalletPlanChangeAdjuster $walletAdjuster,
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

        if ($this->support->invoiceAlreadyExistsForPayment($payment)) {
            Log::info('Webhook re-delivery: invoice exists for local-to-Mollie upgrade, skipping', [
                'payment_id' => $payment->id ?? null,
                'billable_id' => $billable->getKey(),
            ]);
            return;
        }

        $oldPlan = (string) ($billable->getBillingSubscriptionPlanCode() ?? '');
        $oldInterval = (string) ($billable->getBillingSubscriptionInterval() ?? 'monthly');

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
                'CreateSubscription during local upgrade failed',
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

        if ($oldPlan !== '') {
            $this->walletAdjuster->adjust($billable, $oldPlan, $oldInterval, $planCode, $interval);
        }

        event(new SubscriptionUpgradedFromLocal(
            $billable,
            $oldPlan,
            $oldInterval,
            $planCode,
            $interval,
        ));
        event(new PaymentSucceeded($billable, $invoice));

        $this->support->notifyInvoiceAvailable($billable, $invoice);

        MollieBilling::runAfterCheckout($billable, true);
    }
}
