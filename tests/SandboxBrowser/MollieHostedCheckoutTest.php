<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\SandboxBrowser;

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use GraystackIT\MollieBilling\Services\Billing\UpgradeLocalToMollie;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Storage;
use Mollie\Api\Http\Requests\GetMandateRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Api\Http\Requests\GetSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A complete checkout against the real Mollie test system.
 *
 * Every case starts the checkout through the package's own services, pays (or does not
 * pay) on Mollie's hosted page in Chrome, delivers the webhook, and then holds the whole
 * chain against the real API: what Mollie collected, the invoice, the Mollie subscription
 * with its amount and next payment date, the mandate, the coupon redemption.
 *
 * Pro is 10.00 net; the customer is Austrian, so 20 % VAT: 12.00.
 */
class MollieHostedCheckoutTest extends SandboxBrowserTestCase
{
    public function test_a_first_booking_without_a_coupon(): void
    {
        $billable = $this->newCustomer();

        $payment = $this->checkout($billable, ['plan_code' => 'pro', 'amount_gross' => 1200], 'paid', 'plain');

        $this->assertSame('paid', (string) $payment->status);
        $this->assertSame('12.00', $payment->amount->value);
        $this->assertSame('first', (string) $payment->sequenceType);
        $this->assertNotEmpty($payment->mandateId ?? null);
        $this->assertNotEmpty($payment->paidAt ?? null);

        $invoice = BillingInvoice::query()->where('mollie_payment_id', (string) $payment->id)->sole();
        $this->assertSame([1000, 200, 1200], [(int) $invoice->amount_net, (int) $invoice->amount_vat, (int) $invoice->amount_gross]);
        $this->assertSame(InvoiceKind::Subscription, $invoice->invoice_kind);
        $this->assertSame('eps', $invoice->payment_method_details['method'] ?? null);

        $billable->refresh();
        $this->assertSame(SubscriptionStatus::Active, $billable->getBillingSubscriptionStatus());
        $this->assertSame(SubscriptionSource::Mollie->value, $billable->getBillingSubscriptionSource());
        $this->assertSame(100, $billable->remainingBillingQuota('Tokens'));

        $subscription = $this->mollieSubscriptionOf($billable);
        $nextCharge = BillingTime::addInterval(BillingTime::nowUtc(), 'monthly')->toDateString();

        $this->assertSame('active', (string) $subscription->status);
        $this->assertSame('12.00', $subscription->amount->value);
        $this->assertSame('1 month', (string) $subscription->interval);
        $this->assertSame($nextCharge, (string) $subscription->nextPaymentDate);
        $this->assertSame((string) $billable->getMollieMandateId(), (string) $subscription->mandateId);
        $this->assertSame($nextCharge, $billable->nextBillingDate()->toDateString());

        $this->assertMandateIsValid($billable);
    }

    public function test_a_first_booking_with_a_single_payment_percentage_coupon(): void
    {
        app(CouponService::class)->create([
            'code' => 'SBXHALF', 'name' => 'Half off once', 'type' => CouponType::SinglePayment,
            'discount_type' => DiscountType::Percentage, 'discount_value' => 50,
        ]);
        $billable = $this->newCustomer();

        // 10.00 − 50 % = 5.00 net → 6.00.
        $payment = $this->checkout($billable, ['plan_code' => 'pro', 'coupon_code' => 'SBXHALF', 'amount_gross' => 600], 'paid', 'single-payment');

        $this->assertSame('6.00', $payment->amount->value);

        $invoice = BillingInvoice::query()->where('mollie_payment_id', (string) $payment->id)->sole();
        $this->assertSame([500, 100, 600], [(int) $invoice->amount_net, (int) $invoice->amount_vat, (int) $invoice->amount_gross]);
        $this->assertSame(-500, (int) collect($invoice->line_items)->firstWhere('kind', 'coupon')['amount_net']);

        // Once means once: the subscription renews at the full price.
        $subscription = $this->mollieSubscriptionOf($billable);
        $this->assertSame('12.00', $subscription->amount->value);

        $redemption = CouponRedemption::query()->sole();
        $this->assertSame(500, (int) $redemption->discount_amount_net);
        $this->assertSame((int) $invoice->id, (int) $redemption->invoice_id);
        $this->assertMandateIsValid($billable);
    }

    public function test_a_first_booking_with_a_recurring_percentage_coupon(): void
    {
        app(CouponService::class)->create([
            'code' => 'SBXTWENTY', 'name' => '20 % for three periods', 'type' => CouponType::Recurring,
            'discount_type' => DiscountType::Percentage, 'discount_value' => 20, 'max_redemptions_per_billable' => 3,
        ]);
        $billable = $this->newCustomer();

        // 10.00 − 20 % = 8.00 net → 9.60, now and at the next two renewals.
        $payment = $this->checkout($billable, ['plan_code' => 'pro', 'coupon_code' => 'SBXTWENTY', 'amount_gross' => 960], 'paid', 'recurring');

        $this->assertSame('9.60', $payment->amount->value);

        $invoice = BillingInvoice::query()->where('mollie_payment_id', (string) $payment->id)->sole();
        $this->assertSame([800, 160, 960], [(int) $invoice->amount_net, (int) $invoice->amount_vat, (int) $invoice->amount_gross]);

        $subscription = $this->mollieSubscriptionOf($billable);
        $this->assertSame('9.60', $subscription->amount->value, 'The discount has to be in the amount Mollie collects next.');

        $this->assertSame(200, (int) CouponRedemption::query()->sole()->discount_amount_net);
        $this->assertSame('SBXTWENTY', $billable->refresh()->getBillingSubscriptionMeta()['active_recurring_coupon']['code'] ?? null);
    }

    public function test_a_fully_covered_booking_takes_the_zero_amount_mandate_path(): void
    {
        app(CouponService::class)->create([
            'code' => 'SBXFREE', 'name' => 'Three periods free', 'type' => CouponType::Recurring,
            'discount_type' => DiscountType::Percentage, 'discount_value' => 100, 'max_redemptions_per_billable' => 3,
        ]);
        $billable = $this->newCustomer();

        $payment = $this->checkout($billable, ['plan_code' => 'pro', 'coupon_code' => 'SBXFREE', 'amount_gross' => 0], 'paid', 'full-coverage');

        // Mollie accepts €0.00 only as the first payment of a recurring sequence.
        $this->assertSame('paid', (string) $payment->status);
        $this->assertSame('0.00', $payment->amount->value);
        $this->assertSame('mandate_only', $payment->metadata->type ?? null);

        $billable->refresh();
        $this->assertSame(SubscriptionStatus::Active, $billable->getBillingSubscriptionStatus());
        $this->assertSame(0, (int) BillingInvoice::query()->where('mollie_payment_id', (string) $payment->id)->sole()->amount_gross);

        // Mollie cannot hold 0: the full price, with the first charge after the coupon.
        $subscription = $this->mollieSubscriptionOf($billable);
        $this->assertSame('12.00', $subscription->amount->value);
        $this->assertGreaterThan(
            BillingTime::nowUtc()->addDays(89)->toDateString(),
            (string) $subscription->nextPaymentDate,
            'Three free periods: the first charge must lie past them.',
        );
        $this->assertSame((string) $subscription->nextPaymentDate, substr((string) $billable->getBillingSubscriptionMeta()['charge_deferred_until'], 0, 10));
        $this->assertMandateIsValid($billable);
    }

    public function test_a_trial_signup(): void
    {
        $billable = $this->newCustomer();

        $payment = $this->checkout($billable, ['plan_code' => 'trialplan', 'amount_gross' => 1200], 'paid', 'trial');

        $this->assertSame('0.00', $payment->amount->value, 'A trial captures a mandate and collects nothing.');

        $billable->refresh();
        $this->assertSame(SubscriptionStatus::Trial, $billable->getBillingSubscriptionStatus());
        $this->assertSame(BillingTime::nowUtc()->addDays(14)->toDateString(), $billable->getBillingTrialEndsAt()->toDateString());
        $this->assertSame(0, BillingInvoice::query()->count(), 'Nothing was collected, so nothing is invoiced.');

        // The first charge is the trial's end, at the full price.
        $subscription = $this->mollieSubscriptionOf($billable);
        $this->assertSame('12.00', $subscription->amount->value);
        $this->assertSame(BillingTime::nowUtc()->addDays(14)->toDateString(), (string) $subscription->nextPaymentDate);
        $this->assertMandateIsValid($billable);
    }

    public function test_extras_bought_on_a_gifted_plan(): void
    {
        $billable = $this->newCustomer();

        $coupons = app(CouponService::class);
        $coupon = $coupons->accessGrantCoupon('SBXGIFT'.strtoupper(bin2hex(random_bytes(2))), 'pro', 'monthly', [], 90, 'continue_paid');
        $coupons->validate((string) $coupon->code, $billable->refresh(), []);
        $coupons->redeem($coupon, $billable->refresh(), []);
        $giftEnds = $billable->refresh()->getBillingSubscriptionEndsAt();

        // The plan stays free; SSO 5.00 net is bought on top → 6.00.
        $result = app(UpgradeLocalToMollie::class)->handleKeepingGrant($billable->refresh(), ['sso'], 1);
        $this->track($billable);

        $this->completeMollieCheckout((string) $result['checkout_url'], 'paid', 'gift-extras');
        $this->deliverMollieWebhook((string) $result['payment_id'])->assertStatus(200);

        $payment = Mollie::send(new GetPaymentRequest((string) $result['payment_id']));
        $this->assertSame('6.00', $payment->amount->value);

        $invoice = BillingInvoice::query()->where('mollie_payment_id', (string) $payment->id)->sole();
        $this->assertSame([500, 100, 600], [(int) $invoice->amount_net, (int) $invoice->amount_vat, (int) $invoice->amount_gross]);

        $billable->refresh();
        $this->assertSame(SubscriptionSource::Mollie->value, $billable->getBillingSubscriptionSource());
        $this->assertSame(['sso'], array_values($billable->getActiveBillingAddonCodes()));
        $this->assertSame('plan_waiver', $billable->getBillingSubscriptionMeta()['active_recurring_coupon']['kind'] ?? null);
        $this->assertSame($giftEnds->toDateString(), substr((string) $billable->getBillingSubscriptionMeta()['active_recurring_coupon']['valid_until'], 0, 10));

        // Mollie renews the extras only, while the gift runs.
        $subscription = $this->mollieSubscriptionOf($billable);
        $this->assertSame('6.00', $subscription->amount->value);
        $this->assertMandateIsValid($billable);
    }

    /** @return array<string, array{0: string}> */
    public static function attemptsThatDoNotPay(): array
    {
        return ['failed' => ['failed'], 'expired' => ['expired']];
    }

    /**
     * Measured: an attempt that fails or expires at the bank does not end the payment.
     * Mollie shows the method list again and the payment stays `open` until it runs out
     * (15 minutes) — so the webhook the package sees in between carries `open`, and that
     * delivery must neither activate anything nor use up the payment's one booking.
     */
    #[DataProvider('attemptsThatDoNotPay')]
    public function test_an_attempt_that_does_not_pay_leaves_the_payment_open_and_a_retry_books_it(string $outcome): void
    {
        $billable = $this->newCustomer();

        $result = app(StartSubscriptionCheckout::class)->handle($billable, [
            'plan_code' => 'pro', 'amount_gross' => 1200, 'interval' => 'monthly', 'addon_codes' => [], 'extra_seats' => 0,
        ]);
        $this->track($billable);

        $this->completeMollieCheckout((string) $result['checkout_url'], $outcome, 'unpaid-'.$outcome);
        $this->deliverMollieWebhook((string) $result['payment_id'])->assertStatus(200);
        $payment = Mollie::send(new GetPaymentRequest((string) $result['payment_id']));

        $this->assertSame('open', (string) $payment->status, 'Mollie keeps the payment open after an unpaid attempt.');
        $this->assertEmpty($payment->paidAt ?? null);
        $this->assertNotEmpty($payment->expiresAt ?? null);

        $billable->refresh();
        $this->assertFalse($billable->hasAccessibleBillingSubscription());
        $this->assertNotSame(SubscriptionStatus::PastDue, $billable->getBillingSubscriptionStatus(), 'An unpaid checkout is not a failed renewal.');
        $this->assertSame(0, BillingInvoice::query()->count());

        // The customer tries again on the same payment and pays: the delivery that saw
        // `open` must not have consumed the payment.
        $this->completeMollieCheckout((string) $result['checkout_url'], 'paid', 'unpaid-'.$outcome.'-retry');
        $this->deliverMollieWebhook((string) $payment->id)->assertStatus(200);

        $this->assertSame('paid', (string) Mollie::send(new GetPaymentRequest((string) $payment->id))->status);
        $this->assertSame(1200, (int) BillingInvoice::query()->where('mollie_payment_id', (string) $payment->id)->sole()->amount_gross);
        $this->assertSame(SubscriptionStatus::Active, $billable->refresh()->getBillingSubscriptionStatus());
        $this->assertSame('12.00', $this->mollieSubscriptionOf($billable)->amount->value);

        // And once more: nothing doubles.
        $this->deliverMollieWebhook((string) $payment->id)->assertStatus(200);
        $this->assertSame(1, BillingInvoice::query()->count());
    }

    public function test_a_cancelled_checkout_activates_nothing(): void
    {
        $billable = $this->newCustomer();

        $result = app(StartSubscriptionCheckout::class)->handle($billable, [
            'plan_code' => 'pro', 'amount_gross' => 1200, 'interval' => 'monthly', 'addon_codes' => [], 'extra_seats' => 0,
        ]);
        $this->track($billable);

        $this->completeMollieCheckout((string) $result['checkout_url'], 'canceled', 'unpaid-canceled');

        // Mollie redirects first and settles the status a few seconds later.
        $payment = $this->waitForMollieStatus((string) $result['payment_id'], 'canceled');
        $this->deliverMollieWebhook((string) $payment->id)->assertStatus(200);

        $this->assertNotEmpty($payment->canceledAt ?? null);
        $this->assertEmpty($payment->paidAt ?? null);
        $this->assertEmpty($payment->mandateId ?? null, 'A cancelled first payment leaves no mandate.');

        $billable->refresh();
        $this->assertFalse($billable->hasAccessibleBillingSubscription());
        $this->assertNotSame(SubscriptionStatus::PastDue, $billable->getBillingSubscriptionStatus(), 'An aborted checkout is not a failed renewal.');
        $this->assertSame(0, BillingInvoice::query()->count());
        $this->assertSame('', (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? ''));

        // Delivered again: still nothing.
        $this->deliverMollieWebhook((string) $payment->id)->assertStatus(200);
        $this->assertSame(0, BillingInvoice::query()->count());
    }
}
