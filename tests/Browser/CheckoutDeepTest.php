<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\RetrySubscriptionActivationJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Support\ActivationRetry;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Workbench\App\FakeMollie\FakeMollie;
use Workbench\App\Support\WorkbenchSetting;

/**
 * The checkout where it is easiest to pay twice or to book the wrong thing: a customer who
 * comes back — from an app page while the first payment is still being activated, from the
 * hosted page through the browser's Back button with another order in mind, through a
 * promotion link restricted to one plan, from a payment-method change on a running
 * subscription, or after a trial whose activation failed for good.
 *
 * Every scenario asserts what the customer sees AND what was booked: the fake Mollie's
 * payments, subscriptions and mandates, the invoices, the billable's state. See
 * docs/decisions.md, "Checkout: one payment per order, and what a failed activation leaves
 * behind (25 November 2026)".
 */
class CheckoutDeepTest extends BrowserTestCase
{
    private const SUBMIT = '[wire\\:click="submit"]';

    private const NEXT = '[wire\\:click="next"]';

    private const BACK = '[wire\\:click="back"]';

    protected function tearDown(): void
    {
        // The levers are rows in the shared settings table, which migrate:fresh clears for
        // the next test anyway; reset here too so a failed test cannot leave Mollie "down"
        // for the rest of this one's assertions in the gallery run.
        try {
            WorkbenchSetting::set('fail_create_subscription', '0');
            WorkbenchSetting::set('refuse_cancel_payment', '0');
            WorkbenchSetting::set('payments_cancelable', '0');
        } catch (\Throwable) {
        }

        parent::tearDown();
    }

    // ── Gap 1: a second checkout while the first payment awaits activation ──────

    public function test_a_checkout_while_the_paid_first_payment_awaits_activation_goes_to_the_return_page_and_charges_once(): void
    {
        $billable = $this->cdAddressOnFile();

        // Mollie takes the payment but refuses to create the subscription: the activation
        // waits on its retry ladder (~7 h in production).
        WorkbenchSetting::set('fail_create_subscription', '1');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->cdWalkToConfirm($browser, $billable, 'starter');
            $this->assertSame('payment', $this->cdBookingPath($browser));
            $this->clickWire($browser, self::SUBMIT);
            $this->settleAtFakeMollie($browser, 'paid');

            // Paid, not activated: the return page waits instead of calling it done.
            $browser->waitForText(__('billing::portal.return.processing_title'), 20)->pause(2500)
                ->assertDontSee(__('billing::portal.return.title'));
            $this->shot($browser, 'Bezahlt, Aktivierung wartet auf Wiederholung');
        });

        $billable->refresh();
        $firstPayment = $this->latestFakePayment();
        $this->assertSame('paid', $firstPayment['status']);
        $this->assertArrayHasKey($firstPayment['id'], (array) ($billable->getBillingSubscriptionMeta()[ActivationRetry::META_KEY] ?? []), 'the activation waits for its retry');
        $this->assertFalse($billable->hasAccessibleBillingSubscription());
        $this->assertSame(0, DB::table('fake_mollie_subscriptions')->count());

        $this->browse(function (Browser $browser) use ($billable): void {
            // The app's own page sends a customer without access to the checkout, and the
            // checkout — the activation still in flight — sends them on to the return page at
            // once: no plan cards, no form for an order that could not be placed (decision 3,
            // 26 November 2026).
            $this->actAs($browser, $billable, '/_wb/app');
            $this->waitForPathContains($browser, '/billing/return');
            $browser->waitForText(__('billing::portal.return.processing_title'), 20)
                ->assertDontSee('APP-AREA-OK')
                ->assertDontSee(__('billing::portal.return.no_subscription_title'));
            $this->assertSame(0, $this->domCount($browser, '[wire\\:click*="plan_code"]'), 'the checkout form is never shown');
            $this->shot($browser, 'App-Seite → Checkout → sofort Return-Seite');

            // Opened directly, too.
            $browser->visit('/billing/checkout');
            $this->waitForPathContains($browser, '/billing/return');
            $browser->waitForText(__('billing::portal.return.processing_title'), 20);
        });

        $this->assertSame(1, DB::table('fake_mollie_payments')->count(), 'no second payment was created at Mollie');
        $this->assertSame(0, DB::table('fake_mollie_subscriptions')->count());

        // Mollie is back; the retry the ladder would run next finishes the activation.
        WorkbenchSetting::set('fail_create_subscription', '0');
        app()->call([new RetrySubscriptionActivationJob($firstPayment['id'], $billable->getMorphClass(), $billable->getKey()), 'handle']);

        $this->browse(function (Browser $browser) use ($billable): void {
            $browser->visit('/billing/return')->waitForText(__('billing::portal.return.title'), 30);
            $this->shot($browser, 'Nach der Wiederholung aktiviert');

            $browser->visit('/_wb/app')->waitForText('APP-AREA-OK', 15);
            $browser->visit('/billing')->waitForText('Starter', 15)->assertDontSee('Business');
        });

        $billable->refresh();
        $this->assertSame(SubscriptionStatus::Active, $billable->subscription_status);
        $this->assertSame(SubscriptionSource::Mollie, $billable->subscription_source);
        $this->assertSame('starter', $billable->getBillingSubscriptionPlanCode(), 'the order that was paid');
        $this->assertArrayNotHasKey(ActivationRetry::META_KEY, $billable->getBillingSubscriptionMeta());
        $this->assertSame(1, DB::table('fake_mollie_payments')->count(), 'exactly one payment, ever');
        $this->assertSame(1, DB::table('fake_mollie_subscriptions')->count(), 'exactly one subscription');
        $this->assertSame('11.78', DB::table('fake_mollie_subscriptions')->value('amount_value'));

        $invoice = BillingInvoice::query()->sole();
        $this->assertSame($firstPayment['id'], $invoice->mollie_payment_id);
        $this->assertSame(1178, (int) $invoice->amount_gross, '9.90 net + 19 % DE VAT');
    }

    public function test_a_checkout_after_mollie_took_the_money_but_before_the_webhook_goes_to_the_return_page(): void
    {
        $billable = $this->cdAddressOnFile();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->cdWalkToConfirm($browser, $billable, 'starter');
            $this->clickWire($browser, self::SUBMIT);
            $this->waitForPathContains($browser, '/fake-mollie/checkout/');
            $browser->waitFor('@fake-mollie-pay', 10);

            // Paid at Mollie in another tab of the bank app; the webhook is still on its way.
            FakeMollie::settle($this->latestFakePayment()['id'], 'paid', 'DE');

            // The customer goes Back to the checkout: Mollie reports the payment on record
            // paid, so the checkout sends them to the return page at once, before any form.
            $browser->back();
            $this->waitForPathContains($browser, '/billing/return');
            $browser->waitForText(__('billing::portal.return.processing_title'), 20);
            $this->shot($browser, 'Bezahlt, Webhook unterwegs: Return-Seite');

            // The webhook arrives; the open page turns into the confirmation.
            $this->assertSame(200, $this->deliverWebhook($this->latestFakePayment()['id']));
            $browser->waitForText(__('billing::portal.return.title'), 30);
            $this->shot($browser, 'Webhook angekommen');
        });

        $this->assertSame(1, DB::table('fake_mollie_payments')->count(), 'no second payment beside the paid one');
        $this->assertSame(1, DB::table('fake_mollie_subscriptions')->count());

        $billable->refresh();
        $this->assertSame(SubscriptionStatus::Active, $billable->subscription_status);
        $this->assertSame('starter', $billable->getBillingSubscriptionPlanCode(), 'the order that was paid');
        $this->assertSame(1178, (int) BillingInvoice::query()->sole()->amount_gross);
    }

    public function test_a_checkout_form_opened_before_the_activation_started_still_cannot_place_a_second_order(): void
    {
        $billable = $this->cdAddressOnFile();

        $this->browse(function (Browser $browser) use ($billable): void {
            // Nothing in flight yet: the checkout opens as usual.
            $this->cdWalkToConfirm($browser, $billable, 'starter');
            $this->shot($browser, 'Checkout offen, nichts unterwegs');

            // Meanwhile a payment made in another tab is paid and its activation waits on the
            // retry ladder.
            $billable->refresh();
            $meta = $billable->getBillingSubscriptionMeta();
            $meta[ActivationRetry::META_KEY] = ['tr_other_tab' => [
                'context' => 'CreateSubscription during webhook failed',
                'reason' => 'Mollie hiccup',
                'first_failed_at' => now()->subMinutes(2)->toIso8601String(),
            ]];
            $billable->forceFill(['subscription_meta' => $meta])->save();

            // The open form's submit asks the same question the mount does.
            $this->clickWire($browser, self::SUBMIT);
            $this->waitForPathContains($browser, '/billing/return');
            $browser->waitForText(__('billing::portal.return.processing_title'), 20);
            $this->shot($browser, 'Offenes Formular: Return-Seite statt zweiter Zahlung');
        });

        $this->assertSame(0, DB::table('fake_mollie_payments')->count(), 'no payment was created');
    }

    // ── Gap 2: Back from the hosted page, another order ─────────────────────────

    public function test_back_from_mollie_with_a_changed_order_withdraws_the_old_payment_and_charges_the_new_order(): void
    {
        $billable = $this->cdAddressOnFile();
        // Real Mollie refuses to cancel an open first payment — any method, €0.00 included
        // (measured 26 November 2026, tests/Sandbox/MollieIdempotencyContractTest), and the
        // fake now does too. This pins the package's withdraw branch for a payment Mollie
        // reports cancelable; the path a real customer takes is the refusal below.
        WorkbenchSetting::set('payments_cancelable', '1');

        $this->browse(function (Browser $browser) use ($billable): void {
            // Starter monthly (paid now — Pro would be a €0 trial authorisation).
            $this->cdWalkToConfirm($browser, $billable, 'starter');
            $this->assertShownAmount($browser, 'confirm-total', 1178);
            $this->clickWire($browser, self::SUBMIT);

            $this->waitForPathContains($browser, '/fake-mollie/checkout/');
            $browser->waitFor('@fake-mollie-pay', 10)->assertSee('EUR 11.78')->assertSee('Subscription starter');
            $this->shot($browser, 'Mollie: Starter monatlich');

            // The browser's own Back button — not Mollie's cancel.
            $browser->back();
            $this->waitForPathContains($browser, '/billing/checkout');
            $this->shot($browser, 'Zurück im Checkout');

            // Another order: Business, yearly, with Priority Support.
            $this->cdBackToPlanStep($browser);
            $this->jsClick($browser, 'ui-radio[value="yearly"]');
            $browser->click('[wire\\:click*="\'business\'"]')->pause(600);
            $this->clickWire($browser, self::NEXT);
            $browser->waitForText('Priority Support', 10)->pause(300);
            $this->jsClick($browser, '[value="priority-support"]')->pause(400);
            $this->clickWire($browser, self::NEXT);
            $browser->waitFor(self::SUBMIT, 15)->pause(400);

            // 990.00 + 99.00 net, 19 % VAT per line: 1,178.10 + 117.81.
            $this->assertShownAmount($browser, 'confirm-total', 117810 + 11781);
            $this->shot($browser, 'Neue Bestellung: Business jährlich mit Addon');
            $this->clickWire($browser, self::SUBMIT);

            // A NEW payment for the new order, not the old link.
            $this->waitForPathContains($browser, '/fake-mollie/checkout/');
            $browser->waitFor('@fake-mollie-pay', 10)
                ->assertSee('EUR 1295.91')
                ->assertSee('Subscription business')
                ->assertDontSee('EUR 11.78');
            $this->shot($browser, 'Mollie: neue Zahlung über den neuen Betrag');
            $this->settleAtFakeMollie($browser, 'paid');

            $browser->waitForText(__('billing::portal.return.title'), 20);
            $browser->visit('/billing')->waitForText('Business', 15)
                ->assertSee(__('billing::portal.interval_yearly'))
                ->assertSee('Priority Support');
            $this->shot($browser, 'Portal: Business jährlich');
        });

        $payments = DB::table('fake_mollie_payments')->orderBy('created_at')->orderBy('id')->get();
        $this->assertCount(2, $payments);
        $this->assertSame(['canceled', 'paid'], $payments->pluck('status')->all(), 'the left order was withdrawn at Mollie before the new one was created');
        $this->assertSame('11.78', $payments[0]->amount_value);
        $this->assertSame('1295.91', $payments[1]->amount_value);

        $billable->refresh();
        $this->assertSame(SubscriptionStatus::Active, $billable->subscription_status);
        $this->assertSame('business', $billable->getBillingSubscriptionPlanCode());
        $this->assertSame(SubscriptionInterval::Yearly->value, $billable->getBillingSubscriptionInterval());
        $this->assertSame(['priority-support'], $billable->getActiveBillingAddonCodes());

        $invoice = BillingInvoice::query()->sole();
        $this->assertSame($payments[1]->id, $invoice->mollie_payment_id);
        $this->assertSame(129591, (int) $invoice->amount_gross);

        $subscription = DB::table('fake_mollie_subscriptions')->sole();
        $this->assertSame('1295.91', $subscription->amount_value);
        $this->assertSame('12 months', $subscription->interval);
    }

    public function test_back_from_mollie_with_a_changed_order_mollie_will_not_withdraw_creates_nothing_and_says_why(): void
    {
        $billable = $this->cdAddressOnFile();
        WorkbenchSetting::set('refuse_cancel_payment', '1');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->cdWalkToConfirm($browser, $billable, 'starter');
            $this->clickWire($browser, self::SUBMIT);
            $this->waitForPathContains($browser, '/fake-mollie/checkout/');
            $browser->waitFor('@fake-mollie-pay', 10);

            $browser->back();
            $this->waitForPathContains($browser, '/billing/checkout');
            $this->cdBackToPlanStep($browser);
            $browser->click('[wire\\:click*="\'business\'"]')->pause(600);
            $this->clickWire($browser, self::NEXT);
            $browser->waitForText('Priority Support', 10)->pause(300);
            $this->clickWire($browser, self::NEXT);
            $browser->waitFor(self::SUBMIT, 15)->pause(400);
            $this->clickWire($browser, self::SUBMIT);

            // Neither the old payment (another order at another price) nor a second one.
            // Shown as the wait notice with the earlier payment's expiry and link — see
            // CheckoutPendingPaymentWaitTest for its content.
            $browser->waitFor('@pending-other-order', 15)->assertPresent('@pending-other-order-resume');
            $this->assertStringContainsString('/billing/checkout', (string) $browser->driver->getCurrentURL());
            $this->shot($browser, 'Alte Zahlung nicht zurückziehbar: Hinweis');
        });

        $this->assertSame(1, DB::table('fake_mollie_payments')->count(), 'nothing new created beside a payable first payment');
        $this->assertSame('open', $this->latestFakePayment()['status']);
        $this->assertSame(0, BillingInvoice::query()->count());
        $this->assertFalse($billable->refresh()->hasAccessibleBillingSubscription());
    }

    // ── Gap 3: Back from a trial authorisation to a plan without a trial ────────

    public function test_back_from_a_trial_authorisation_to_a_plan_without_a_trial_charges_the_new_plan_in_full(): void
    {
        $billable = $this->cdAddressOnFile();
        // Real Mollie refuses to cancel an open first payment — any method, €0.00 included
        // (measured 26 November 2026, tests/Sandbox/MollieIdempotencyContractTest), and the
        // fake now does too. This pins the package's withdraw branch for a payment Mollie
        // reports cancelable; the path a real customer takes is the refusal below.
        WorkbenchSetting::set('payments_cancelable', '1');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->cdWalkToConfirm($browser, $billable, 'pro');
            $this->assertSame('trial', $this->cdBookingPath($browser));
            $browser->assertSeeIn('@confirm-submit-label', __('billing::checkout.confirm_start_trial'));
            $this->clickWire($browser, self::SUBMIT);

            $this->waitForPathContains($browser, '/fake-mollie/checkout/');
            $browser->waitFor('@fake-mollie-pay', 10)->assertSee('EUR 0.00');
            $this->shot($browser, 'Mollie: 0-€-Autorisierung für den Trial');

            $browser->back();
            $this->waitForPathContains($browser, '/billing/checkout');

            // Business has no trial_days.
            $this->cdBackToPlanStep($browser);
            $browser->click('[wire\\:click*="\'business\'"]')->pause(600);
            $this->clickWire($browser, self::NEXT);
            $browser->waitForText('Priority Support', 10)->pause(300);
            $this->clickWire($browser, self::NEXT);
            $browser->waitFor(self::SUBMIT, 15)->pause(400);

            $this->assertSame('payment', $this->cdBookingPath($browser));
            $browser->assertSeeIn('@confirm-submit-label', __('billing::checkout.confirm_and_pay'));
            $this->assertShownAmount($browser, 'confirm-total', 11781);
            $this->shot($browser, 'Business: sofort zahlen');
            $this->clickWire($browser, self::SUBMIT);

            $this->waitForPathContains($browser, '/fake-mollie/checkout/');
            $browser->waitFor('@fake-mollie-pay', 10)->assertSee('EUR 117.81')->assertDontSee('EUR 0.00');
            $this->shot($browser, 'Mollie: Business über 117,81');
            $this->settleAtFakeMollie($browser, 'paid');

            $browser->waitForText(__('billing::portal.return.title'), 20);
        });

        $payments = DB::table('fake_mollie_payments')->orderBy('created_at')->orderBy('id')->get();
        $this->assertCount(2, $payments);
        $this->assertSame('0.00', $payments[0]->amount_value);
        $this->assertSame('mandate_only', json_decode((string) $payments[0]->metadata, true)['type'] ?? null);
        $this->assertSame('canceled', $payments[0]->status, 'the €0 authorisation for the trial was withdrawn');
        $this->assertSame('117.81', $payments[1]->amount_value);
        $this->assertSame('first', $payments[1]->sequence_type);
        $this->assertSame('paid', $payments[1]->status);

        $billable->refresh();
        $this->assertSame(SubscriptionStatus::Active, $billable->subscription_status, 'a paid subscription, not a trial');
        $this->assertNull($billable->trial_ends_at);
        $this->assertSame('business', $billable->getBillingSubscriptionPlanCode());

        $invoice = BillingInvoice::query()->sole();
        $this->assertSame($payments[1]->id, $invoice->mollie_payment_id);
        $this->assertSame(11781, (int) $invoice->amount_gross);
        $this->assertSame('117.81', DB::table('fake_mollie_subscriptions')->sole()->amount_value);
    }

    // ── Gap 4: a promotion link with a code restricted to one plan ──────────────

    public function test_a_promotion_link_with_a_plan_restricted_code_applies_once_that_plan_is_picked_and_discounts_the_payment(): void
    {
        // Restricted to Business rather than Pro: Pro carries trial days, and on a trial
        // the trial wins over a first-payment code (docs/decisions.md) — nothing would be
        // paid today to carry the discount.
        $this->coupons()->create([
            'code' => 'BIZ20',
            'name' => 'Business launch offer',
            'type' => CouponType::SinglePayment,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 20,
            'applicable_plans' => ['business'],
            'auto_apply_token' => 'biz-launch',
        ]);

        $billable = $this->cdAddressOnFile();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, route(\GraystackIT\MollieBilling\Support\BillingRoute::promotion(), ['token' => 'biz-launch'], false));
            $this->waitForPathContains($browser, '/billing/checkout');
            $this->assertStringNotContainsString('plan=', (string) $browser->driver->getCurrentURL(), 'the link names no plan');

            // Another plan first: the code does not fit it and is not shown as applied.
            $browser->waitFor('[wire\\:click*="\'starter\'"]', 15)->pause(400);
            $browser->click('[wire\\:click*="\'starter\'"]')->pause(600);
            $this->clickWire($browser, self::NEXT);
            $browser->waitForText('Softdrinks', 10)->pause(300);
            $this->clickWire($browser, self::NEXT);
            $browser->waitFor(self::SUBMIT, 15)->pause(400);
            $browser->assertDontSee('BIZ20');
            $this->assertSame(0, $this->domCount($browser, '[wire\\:click="removeCoupon"]'));
            $this->assertShownAmount($browser, 'confirm-total', 1178);
            $this->shot($browser, 'Starter: Code passt nicht');

            // The plan it is for.
            $this->cdBackToPlanStep($browser);
            $browser->click('[wire\\:click*="\'business\'"]')->pause(600);
            $this->clickWire($browser, self::NEXT);
            $browser->waitForText('Priority Support', 10)->pause(300);
            $this->clickWire($browser, self::NEXT);
            $browser->waitFor(self::SUBMIT, 15)->pause(400);

            $browser->waitForText('BIZ20', 10)->assertSee('Business launch offer');
            // 99.00 − 20 % = 79.20 net → 94.25 gross.
            $this->assertShownAmount($browser, 'confirm-discount', 1980);
            $this->assertShownAmount($browser, 'confirm-total', 9425);
            $this->shot($browser, 'Business: Aktionscode angewendet');
            $this->clickWire($browser, self::SUBMIT);

            $this->waitForPathContains($browser, '/fake-mollie/checkout/');
            $browser->waitFor('@fake-mollie-pay', 10)->assertSee('EUR 94.25');
            $this->settleAtFakeMollie($browser, 'paid');
            $browser->waitForText(__('billing::portal.return.title'), 20);
        });

        $this->assertSame('94.25', $this->latestFakePayment()['amount_value']);

        $invoice = BillingInvoice::query()->sole();
        $this->assertSame(9425, (int) $invoice->amount_gross);
        $this->assertSame(7920, (int) $invoice->amount_net);
        $couponLine = collect($invoice->line_items)->firstWhere('kind', 'coupon');
        $this->assertNotNull($couponLine, 'the invoice carries the coupon line');
        $this->assertSame(1, CouponRedemption::query()->count());

        $billable->refresh();
        $this->assertSame('business', $billable->getBillingSubscriptionPlanCode());
        $this->assertSame(SubscriptionStatus::Active, $billable->subscription_status);
        // A single-payment code: the subscription collects the full price from the next period.
        $this->assertSame('117.81', DB::table('fake_mollie_subscriptions')->sole()->amount_value);
    }

    // ── Gap 5: a payment-method change on a running subscription ────────────────

    public function test_a_payment_method_change_after_a_paid_checkout_moves_the_subscription_and_the_next_renewal_books(): void
    {
        $billable = $this->cdAddressOnFile();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->cdWalkToConfirm($browser, $billable, 'starter');
            $this->clickWire($browser, self::SUBMIT);
            $this->settleAtFakeMollie($browser, 'paid');
            $browser->waitForText(__('billing::portal.return.title'), 20);
        });

        $billable->refresh();
        $oldMandate = (string) $billable->mollie_mandate_id;
        $subscriptionId = (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'];
        $this->assertNotSame('', $oldMandate);
        $this->assertSame($oldMandate, DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->value('mandate_id'), 'CreateSubscription bound the subscription to the checkout mandate');

        $this->browse(function (Browser $browser) use ($billable): void {
            $browser->visit('/billing/billing-data');
            $browser->waitFor('[wire\\:click="save"]', 15)->pause(400)
                ->assertSee(__('billing::portal.payment_method.status.valid'));

            $this->confirmInModal($browser, 'change-payment-method', '[wire\\:click="changePaymentMethod"]');
            $this->waitForPathContains($browser, '/fake-mollie/checkout/');
            $browser->waitFor('@fake-mollie-pay', 10)->assertSee('EUR 0.00');
            $this->shot($browser, 'Mollie: neues Zahlungsmittel autorisieren');
            $browser->click('@fake-mollie-pay');

            $this->waitForPathContains($browser, '/billing/billing-data');
            $browser->waitUntilMissingText(__('billing::portal.payment_method.awaiting_update'), 20)
                ->assertSee(__('billing::portal.payment_method.status.valid'));
            $this->shot($browser, 'Neues Zahlungsmittel aktiv');
        });

        $billable->refresh();
        $newMandate = (string) $billable->mollie_mandate_id;
        $subscription = DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->first();

        $this->assertNotSame($oldMandate, $newMandate);
        $this->assertSame($newMandate, $subscription->mandate_id, 'the running subscription was moved to the new mandate');
        $this->assertNull($subscription->canceled_at, 'revoking the old mandate did not cancel the subscription');
        $this->assertSame('active', $subscription->status);
        $this->assertFalse(DB::table('fake_mollie_mandates')->where('id', $oldMandate)->exists(), 'the old mandate was revoked');
        $this->assertSame(1, DB::table('fake_mollie_subscriptions')->count());
        $this->assertSame(1, BillingInvoice::query()->count(), 'a mandate change books nothing');

        // Mollie's scheduler fires on the moved subscription.
        $renewal = $this->renewSubscription($billable);
        $this->assertSame($newMandate, FakeMollie::payment($renewal)['mandate_id'], 'charged on the new mandate');

        $renewalInvoice = BillingInvoice::query()->where('mollie_payment_id', $renewal)->sole();

        $this->browse(function (Browser $browser) use ($renewalInvoice): void {
            $browser->visit('/billing/invoices')->waitFor('@invoice-row-'.$renewalInvoice->id, 15);
            $this->assertShownAmount($browser, 'invoice-'.$renewalInvoice->id.'-gross', 1178, 'the renewal on the invoice list');
            $this->shot($browser, 'Verlängerung gebucht');
        });

        $billable->refresh();
        $this->assertSame(SubscriptionStatus::Active, $billable->subscription_status);
        $this->assertFalse($billable->isBillingPastDue());
        $this->assertSame(2, BillingInvoice::query()->count());
        $this->assertSame(1178, (int) $renewalInvoice->amount_gross);
    }

    // ── Gap 6: a trial whose activation failed for good ─────────────────────────

    public function test_after_a_trial_activation_failed_for_good_the_checkout_still_offers_the_trial(): void
    {
        $billable = $this->cdAddressOnFile();
        WorkbenchSetting::set('fail_create_subscription', '1');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->cdWalkToConfirm($browser, $billable, 'pro');
            $browser->assertSeeIn('@confirm-submit-label', __('billing::checkout.confirm_start_trial'));
            $this->clickWire($browser, self::SUBMIT);

            $this->waitForPathContains($browser, '/fake-mollie/checkout/');
            $browser->waitFor('@fake-mollie-pay', 10)->assertSee('EUR 0.00');
            $this->settleAtFakeMollie($browser, 'paid');

            $browser->waitForText(__('billing::portal.return.processing_title'), 20);
            $this->shot($browser, 'Trial: Mandat da, Abo nicht angelegt');
        });

        $billable->refresh();
        $authorisation = $this->latestFakePayment();
        $capturedMandate = (string) $billable->mollie_mandate_id;
        $this->assertNotSame('', $capturedMandate, 'the authorisation captured a mandate');
        $this->assertArrayHasKey($authorisation['id'], (array) ($billable->getBillingSubscriptionMeta()[ActivationRetry::META_KEY] ?? []));

        // Mollie stays down for the whole ladder: the last attempt throws and the queue
        // worker hands the job to failed().
        $job = new RetrySubscriptionActivationJob($authorisation['id'], $billable->getMorphClass(), $billable->getKey());
        try {
            app()->call([$job, 'handle']);
            $this->fail('the retry must not succeed while Mollie refuses to create the subscription');
        } catch (\RuntimeException $e) {
            $job->failed($e);
        }

        $billable->refresh();
        $this->assertNull($billable->mollie_mandate_id, 'the mandate of the trial that never started was given back');
        $this->assertFalse(DB::table('fake_mollie_mandates')->where('id', $capturedMandate)->exists(), 'and revoked at Mollie');
        $this->assertArrayNotHasKey(ActivationRetry::META_KEY, $billable->getBillingSubscriptionMeta());
        $this->assertNotSame(SubscriptionStatus::Trial, $billable->subscription_status);
        $this->assertSame(0, BillingInvoice::query()->count(), 'a €0 authorisation has nothing to refund');

        WorkbenchSetting::set('fail_create_subscription', '0');

        $this->browse(function (Browser $browser) use ($billable): void {
            // Nothing is under way any more: the return page says so instead of polling.
            $browser->visit('/billing/return')->waitFor('@return-no-subscription', 15);
            $this->shot($browser, 'Return-Seite: kein Abo, nichts unterwegs');

            $this->cdWalkToConfirm($browser, $billable, 'pro');

            // The trial is still offered — not "pay now" for a trial the customer never had.
            $this->assertSame('trial', $this->cdBookingPath($browser));
            $browser->assertSeeIn('@confirm-submit-label', __('billing::checkout.confirm_start_trial'))
                ->assertDontSeeIn('@confirm-submit-label', __('billing::checkout.confirm_and_pay'));
            $this->shot($browser, 'Erneut: Trial wird angeboten');
            $this->clickWire($browser, self::SUBMIT);

            $this->waitForPathContains($browser, '/fake-mollie/checkout/');
            $browser->waitFor('@fake-mollie-pay', 10)->assertSee('EUR 0.00');
            $this->settleAtFakeMollie($browser, 'paid');
            $browser->waitForText(__('billing::portal.return.title'), 20);
        });

        $billable->refresh();
        $this->assertSame(SubscriptionStatus::Trial, $billable->subscription_status);
        $this->assertNotNull($billable->trial_ends_at);
        $this->assertTrue($billable->trial_ends_at->isAfter(now()->addDays(13)));
        $this->assertSame(0, BillingInvoice::query()->count(), 'nothing is charged during the trial');
        $this->assertSame(0, DB::table('fake_mollie_payments')->where('amount_value', '!=', '0.00')->count(), 'no payment ever collected money');
        $this->assertSame(1, DB::table('fake_mollie_subscriptions')->count());
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    private function cdAddressOnFile(): TestBillable
    {
        return $this->makeBillable([
            'billing_street' => 'Musterweg 12',
            'billing_postal_code' => '10115',
            'billing_city' => 'Berlin',
        ]);
    }

    /** Open the checkout (billing step locked), pick the plan, walk through to confirm. */
    private function cdWalkToConfirm(Browser $browser, TestBillable $billable, string $plan): void
    {
        $this->actAs($browser, $billable, '/billing/checkout');

        // A draft from an earlier visit may resume further along: go back to the plans.
        $this->cdBackToPlanStep($browser);
        $browser->click('[wire\\:click*="\''.$plan.'\'"]')->pause(600);
        $this->clickWire($browser, self::NEXT);
        $browser->pause(700);

        if ($this->domCount($browser, self::SUBMIT) === 0) {
            $this->clickWire($browser, self::NEXT);
        }

        $browser->waitFor(self::SUBMIT, 15)->pause(400);
    }

    /** Click the step's Back button until the plan cards are on screen. */
    private function cdBackToPlanStep(Browser $browser): void
    {
        $plans = '[wire\\:click*="plan_code"]';

        $browser->waitUsing(15, 200, fn (): bool => $this->domCount($browser, $plans) > 0
            || $this->domCount($browser, self::BACK) > 0, 'Waited for the checkout to render.');

        for ($i = 0; $i < 4 && $this->domCount($browser, $plans) === 0; $i++) {
            $this->clickWire($browser, self::BACK);
            $browser->pause(700);
        }

        $browser->waitFor($plans, 15)->pause(300);
    }

    private function cdBookingPath(Browser $browser): string
    {
        return (string) $browser->attribute('@confirm-submit-label', 'data-booking-path');
    }
}
