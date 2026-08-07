<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Laravel\Dusk\Browser;
use Workbench\App\Support\WorkbenchSetting;

/**
 * What happens to a subscription over time, driven from the outside: Mollie's own
 * scheduler charging a renewal, metered overage being collected, a renewal that
 * fails and the recovery out of past_due, and the cancelled → expired flip.
 *
 * These are the transitions a staging system cannot show you without waiting a
 * month; here they are a webhook and a job away.
 */
class LifecycleTest extends BrowserTestCase
{
    public function test_a_renewal_books_an_invoice_and_recharges_the_wallets(): void
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly');

        // Spend most of the plan quota, so the recharge is observable.
        app(\GraystackIT\MollieBilling\Services\Wallet\WalletUsageService::class)
            ->debit($billable, 'Tokens', 40, 'browser_test');

        expect(TestBillable::sole()->remainingBillingQuota('Tokens'))->toBe(10);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/usage');
            $browser->waitForText('Tokens', 10)->pause(400);
            $this->shot($browser, 'Verbrauch vor der Erneuerung');

            $this->renewSubscription($billable->refresh());

            $browser->visit('/billing')->waitForText('Starter', 10)->pause(600);
            $this->shot($browser, 'Dashboard nach der Erneuerung');

            // The list shows the invoice KIND, date and amounts — not the plan name.
            $browser->visit('/billing/invoices')->waitForText('€11.78', 10)->pause(400);
            $this->shot($browser, 'Rechnungsliste mit der Erneuerung');
        });

        // Wallets are recharged additively from the catalog's included quota.
        expect(TestBillable::sole()->remainingBillingQuota('Tokens'))->toBe(60)
            ->and(BillingInvoice::query()->count())->toBe(1)
            ->and((int) BillingInvoice::query()->sole()->amount_gross)->toBe(1178);
    }

    public function test_a_failed_renewal_pushes_the_customer_to_past_due_and_a_paid_one_recovers_them(): void
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly');

        // Every recurring charge fails from here on — Mollie's dunning.
        $this->setRecurringOutcome('failed');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable);
            $browser->waitForText('Starter', 10);

            $this->renewSubscription($billable->refresh());

            $browser->refresh()->waitForText(__('billing::portal.past_due_banner'), 10)->pause(400);
            $this->shot($browser, 'Past-Due-Banner nach fehlgeschlagener Erneuerung');

            expect(TestBillable::sole()->subscription_status)->toBe(SubscriptionStatus::PastDue);

            // The customer fixes their card and Mollie's retry succeeds.
            $this->setRecurringOutcome('paid');
            $this->renewSubscription(TestBillable::sole());

            $browser->refresh()->waitUntilMissingText(__('billing::portal.past_due_banner'), 10)->pause(400);
            $this->shot($browser, 'Wieder aktiv nach erfolgreicher Zahlung');
        });

        expect(TestBillable::sole()->subscription_status)->toBe(SubscriptionStatus::Active);
    }

    public function test_overage_beyond_the_included_quota_is_charged(): void
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/usage');
            $browser->waitForText('Tokens', 10)->pause(400);
            $this->shot($browser, 'Verbrauch im Rahmen');

            // 50 included, 70 used → 20 units of overage at 12 cents each.
            $this->simulator()->overageCharge($billable->refresh(), 'Tokens', 70);

            $browser->refresh()->pause(800);
            $this->shot($browser, 'Negatives Saldo: Overage entstanden');

            // The overage charge is a recurring payment; its webhook books the
            // invoice and settles the wallet back to zero.
            $payment = $this->latestFakePayment();
            expect($payment['sequence_type'])->toBe('recurring');
            $this->deliverWebhook($payment['id']);

            $browser->visit('/billing/invoices')
                ->waitForText(\GraystackIT\MollieBilling\Enums\InvoiceKind::Overage->label(), 10)->pause(400);
            $this->shot($browser, 'Overage-Rechnung');
        });

        $invoice = BillingInvoice::query()->where('invoice_kind', 'overage')->sole();

        expect((int) $invoice->amount_net)->toBe(240) // 20 × 12
            ->and(TestBillable::sole()->remainingBillingQuota('Tokens'))->toBe(0);
    }

    public function test_a_cancelled_subscription_expires_when_its_period_runs_out(): void
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable);
            $browser->waitForText('Starter', 10);

            $browser->press(__('billing::portal.cancel_subscription'));
            $browser->pause(400);
            $this->clickWire($browser, '[wire\\:click="cancelSubscription"]');
            $browser->waitForText(__('billing::portal.resubscribe'), 10)->pause(400);
            $this->shot($browser, 'Gekündigt, Zugriff bis Periodenende');

            // Pass 1 of PrepareUsageOverageJob flips cancelled → expired once the
            // period boundary is behind us, and clears the plan-scoped fields.
            $this->simulator()->cancelledToExpired(TestBillable::sole());

            $browser->refresh()->pause(800);
            $this->shot($browser, 'Abgelaufen: kein Plan mehr');
        });

        $billable = TestBillable::sole();

        expect($billable->subscription_status)->toBe(SubscriptionStatus::Expired)
            ->and($billable->getBillingSubscriptionPlanCode())->toBeNull()
            ->and($billable->hasAccessibleBillingSubscription())->toBeFalse();
    }

    protected function tearDown(): void
    {
        WorkbenchSetting::forget('recurring_outcome');

        parent::tearDown();
    }
}
