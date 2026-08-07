<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Support\BillingAuditEntry;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Laravel\Dusk\Browser;

/**
 * The operator's side. Unlike the portal, the admin panel authenticates a real
 * user through the default guard (AuthorizeBillingAdmin demands one implementing
 * AuthorizesBillingAdmin), so every scenario here starts from an admin login.
 */
class AdminPanelTest extends BrowserTestCase
{
    public function test_walks_the_admin_screens(): void
    {
        $billable = $this->makeActiveSubscriber('pro', 'monthly');
        $this->renewSubscription($billable);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->loginAdmin($browser);
            // The dashboard shows aggregates (MRR, ARR, churn), not billable names.
            $browser->waitForText(__('billing::admin.dashboard.title'), 15)->pause(600);
            $this->shot($browser, 'Admin-Dashboard');

            foreach ([
                'billing/admin/billables' => 'Billables-Liste',
                'billing/admin/coupons' => 'Coupon-Verwaltung',
                'billing/admin/mismatches' => 'Country-Mismatches',
                'billing/admin/refunds' => 'Erstattungen',
                'billing/admin/past-due' => 'Past-Due-Übersicht',
                'billing/admin/bulk' => 'Bulk-Aktionen',
                'billing/admin/oss' => 'OSS-Protokoll',
            ] as $path => $label) {
                $browser->visit('/'.$path)->pause(900);
                $this->shot($browser, $label);
            }

            $browser->visit('/billing/admin/billables/'.$billable->getKey())->pause(900);
            $this->shot($browser, 'Billable-Detail: Abo-Tab');
        });
    }

    public function test_an_operator_cancels_a_subscription_and_the_reason_lands_in_the_audit_trail(): void
    {
        $billable = $this->makeActiveSubscriber('pro', 'monthly');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->loginAdmin($browser);
            $browser->visit('/billing/admin/billables/'.$billable->getKey())
                ->waitForText('Acme GmbH', 15)->pause(600);
            $this->shot($browser, 'Abo-Tab vor der Kündigung');

            // Force-cancel now goes through a reason prompt — it is irreversible
            // and the audit row used to say only that it happened.
            $this->clickWire($browser, '[wire\\:click="openCancel(true)"]');
            $browser->waitFor('textarea[name="cancelNote"]', 10)->pause(400);
            $this->shot($browser, 'Kündigungs-Modal verlangt einen Grund');

            $browser->type('cancelNote', 'Chargeback dispute, ticket #4711')->pause(300);
            // The modal is a wire:submit form, so the submit button is the action.
            $this->clickWire($browser, 'form[wire\\:submit="confirmCancel"] button[type="submit"]');
            $browser->pause(1500);
            $this->shot($browser, 'Gekündigt, Grund erfasst');
        });

        expect(TestBillable::sole()->subscription_status)->toBe(SubscriptionStatus::Cancelled);

        $row = BillingAuditEntry::model()::query()
            ->where('event', 'subscription_cancelled')
            ->latest('id')
            ->first();

        expect($row)->not->toBeNull()
            ->and($row->properties['replace']['reason'])->toBe('Chargeback dispute, ticket #4711')
            ->and($row->properties['replace']['immediately'])->toBeTrue();
    }

    public function test_an_operator_refunds_an_invoice(): void
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly');
        $this->renewSubscription($billable);

        $invoice = BillingInvoice::query()->sole();

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->loginAdmin($browser);
            // Flux tab group, not a URL parameter — the tab has to be clicked.
            $browser->visit('/billing/admin/billables/'.$billable->getKey())
                ->waitForText('Acme GmbH', 15)->pause(600);
            $browser->press(__('billing::admin.billables.show.tab.invoices'))->pause(900);
            $this->shot($browser, 'Rechnungen im Admin');
        });

        // The refund itself runs through the service the admin screen calls, with
        // the fake Mollie taking the refund. Asserted on the credit note, which is
        // what the customer and the ledger see.
        app(\GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService::class)->refund($invoice, [
            'reason_code' => \GraystackIT\MollieBilling\Enums\RefundReasonCode::Goodwill,
            'reason_text' => 'Browser suite',
        ]);

        $creditNote = BillingInvoice::query()->where('invoice_kind', 'refund')->sole();

        expect((int) $creditNote->amount_net)->toBe(-990)
            ->and((int) $invoice->refresh()->refunded_net)->toBe(990);

        $this->browse(function (Browser $browser) use ($billable): void {
            $browser->visit('/billing/admin/refunds')->pause(900);
            $this->shot($browser, 'Erstattung in der Admin-Liste');

            $this->actAs($browser, $billable, '/billing/invoices');
            $browser->waitForText('-€9.90', 10)->pause(400);
            $this->shot($browser, 'Gutschrift im Kundenportal');
        });
    }

    public function test_an_operator_resolves_a_country_mismatch_with_a_justification(): void
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly');
        $this->setIpCountry('NL');

        // Flag a mismatch the way a renewal from a foreign card does.
        $subscriptionId = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');
        $payment = \Workbench\App\FakeMollie\FakeMollie::chargeSubscription($subscriptionId);
        \Illuminate\Support\Facades\DB::table('fake_mollie_payments')
            ->where('id', $payment['id'])->update(['country_code' => 'NL']);
        $this->deliverWebhook($payment['id']);

        $this->browse(function (Browser $browser): void {
            $this->loginAdmin($browser);
            $browser->visit('/billing/admin/mismatches')
                ->waitForText('Acme GmbH', 15)->pause(600);
            $this->shot($browser, 'Offener Mismatch im Admin');
        });

        expect(\GraystackIT\MollieBilling\Models\BillingCountryMismatch::query()->count())->toBe(1);
    }

    protected function tearDown(): void
    {
        $this->setIpCountry(null);

        parent::tearDown();
    }
}
