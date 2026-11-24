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
                'billing/admin/invoices' => 'Rechnungen',
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

    /**
     * The coupon form's explanations, rendered. The feature suite passes `<flux:…>` tags
     * through uncompiled, so only here can it be seen that the (i) next to an option is a
     * real, openable tooltip — and that the form still submits with the labels moved out
     * of the controls.
     */
    public function test_the_coupon_form_explains_every_option_and_creates_a_plan_waiver(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAdmin($browser);
            $browser->visit('/billing/admin/coupons/create')
                ->waitForText(__('billing::admin.coupons.create.title'), 15)->pause(600);

            // One (i) per option, each a compiled Flux tooltip rather than a raw tag.
            $tooltips = (int) $browser->script('return document.querySelectorAll("[data-flux-tooltip]").length;')[0];
            expect($tooltips)->toBeGreaterThan(8);
            expect($browser->driver->getPageSource())->not->toContain('<flux:');

            // The selected type is explained in full, always visible.
            $browser->assertSee(__('billing::admin.coupons.type_info.single_payment'));
            $this->shot($browser, 'Coupon-Formular mit Erklärungen');

            // Opening an (i) shows what the option does.
            $this->clickWire($browser, '[data-flux-tooltip] button');
            $browser->pause(500)->assertSee(__('billing::admin.coupons.create.info.intent'));

            // …and wraps: these are sentences, and an unwrapped one runs off the screen.
            $width = (int) $browser->script('const t = document.querySelector("[data-flux-tooltip-content]:popover-open"); return t ? Math.round(t.getBoundingClientRect().width) : -1;')[0];
            expect($width)->toBeGreaterThan(0)->toBeLessThanOrEqual(360);
            $this->shot($browser, 'Tooltip geöffnet');
            $browser->driver->getKeyboard()->sendKeys(\Facebook\WebDriver\WebDriverKeys::ESCAPE);
            $browser->pause(300);

            // First the intent ("free time"), which swaps the kinds on offer …
            $browser->script(<<<'JS'
                const form = document.querySelector('form[wire\\:submit="save"]');
                window.Livewire.find(form.closest('[wire\\:id]').getAttribute('wire:id')).set('intent', 'free_time');
            JS);
            $browser->waitFor('select[wire\\:model\\.live="type"] option[value="plan_waiver"]', 10)
                ->assertSee(__('billing::admin.coupons.intent_info.free_time'));

            // … then the kind, which swaps the explanation and the fields.
            $browser->script(<<<'JS'
                const s = document.querySelector('select[wire\\:model\\.live="type"]');
                s.value = "plan_waiver";
                s.dispatchEvent(new Event("change", {bubbles: true}));
            JS);
            $browser->waitForText(__('billing::admin.coupons.type_info.plan_waiver'), 10)
                ->waitFor('input[wire\\:model="waiver_periods"]', 10)->pause(300);
            $this->shot($browser, 'Typ Plan gratis');

            $browser->type('input[wire\\:model="code"]', 'BROWSERFREE3')
                ->type('input[wire\\:model="waiver_periods"]', '3')->pause(300);
            $this->clickWire($browser, 'form[wire\\:submit="save"] button[type="submit"]');
            $browser->pause(1500);
        });

        $coupon = \GraystackIT\MollieBilling\Models\Coupon::query()->where('code', 'BROWSERFREE3')->first();

        expect($coupon)->not->toBeNull()
            ->and($coupon->type)->toBe(\GraystackIT\MollieBilling\Enums\CouponType::PlanWaiver)
            ->and((int) $coupon->waiver_periods)->toBe(3);
    }

    /**
     * Giving free time from the subscription tab — the three actions that replace
     * "create a coupon and send the code".
     */
    public function test_an_operator_gives_free_plan_periods_from_the_subscription_tab(): void
    {
        $billable = $this->makeActiveSubscriber('pro', 'monthly');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->loginAdmin($browser);
            $browser->visit('/billing/admin/billables/'.$billable->getKey())
                ->waitForText(__('billing::admin.billables.subscription.free_time.title'), 15)->pause(600);
            expect($browser->driver->getPageSource())->not->toContain('<flux:');
            $this->shot($browser, 'Gratiszeit vergeben');

            $browser->type('input[wire\\:model="freePeriods"]', '2')->pause(300);
            $this->clickWire($browser, 'form[wire\\:submit="giveFreePlanPeriods"] button[type="submit"]');
            $browser->waitForText(__('billing::admin.billables.subscription.flash.free_periods_given', ['count' => 2]), 15)->pause(500);
            $this->shot($browser, 'Plan zwei Perioden gratis');
        });

        $marker = app(\GraystackIT\MollieBilling\Services\Billing\PlanWaiver::class)->marker(TestBillable::sole());

        expect($marker)->not->toBeNull()
            ->and($marker['max_applications'])->toBe(2);
    }
}
