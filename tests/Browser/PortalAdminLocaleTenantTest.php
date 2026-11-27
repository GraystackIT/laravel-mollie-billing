<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Browser\Matrix\CouponWorkbench;
use GraystackIT\MollieBilling\Tests\Browser\Matrix\OperationsWorkbench;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Workbench\App\FakeMollie\FakeMollie;
use Workbench\App\Support\WorkbenchSetting;

/**
 * The portal and the admin panel as a German-speaking or a multi-tenant app shows them:
 *
 *  - 50: every portal page and every admin screen in German — no raw `billing::` key on any
 *    of them, `<html lang="de">`, and money in the German number format ("€34,51"), never in
 *    the English one;
 *  - 53: the invoice PDF behind the dashboard's "recent invoices" card and the invoice archive
 *    under `/{organization}/billing` — a PDF for the own invoice, 403 for another
 *    organization's invoice id under either prefix or none;
 *  - 54: the return page opened for another organization — by its tenant prefix while signed
 *    in as a member of a different one, and by `?organization=<id>` as a signed-out visitor
 *    and as a signed-in member of another organization: never the other's state, no polling,
 *    and Mollie is never asked about the other organization's pending payment (the fake
 *    counts every GetPaymentRequest per payment, `count_get_payments`).
 */
class PortalAdminLocaleTenantTest extends BrowserTestCase
{
    use CouponWorkbench;
    use OperationsWorkbench;

    // ── 50. German walkthrough ──────────────────────────────────────────────

    public function test_every_portal_page_and_admin_screen_in_german_shows_no_raw_key_and_german_amounts(): void
    {
        $this->serverConfig('app.locale', 'de');
        app()->setLocale('de');

        $billable = $this->opsSubscriber('pro', 0, [], 'DE');
        $invoice = $this->opsRenew($billable, '34.51');
        $coupon = $this->coupons()->create([
            'code' => 'DEUTSCH10',
            'name' => '10 % auf Deutsch',
            'type' => CouponType::Recurring,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 10,
            'discount_periods' => 3,
        ]);

        $gross = BillingMoney::format((int) $invoice->amount_gross);
        $this->assertSame('€34,51', str_replace("\u{00a0}", '', $gross), 'the test process formats in German too');

        $portal = [
            '/billing' => 'Dashboard',
            '/billing/plan' => 'Plan',
            '/billing/seats' => 'Sitze',
            '/billing/addons' => 'Add-ons',
            '/billing/products' => 'Produkte',
            '/billing/usage' => 'Verbrauch',
            '/billing/invoices' => 'Rechnungen',
            '/billing/billing-data' => 'Rechnungsdaten',
        ];

        $this->browse(function (Browser $browser) use ($billable, $portal): void {
            $this->actAs($browser, $billable, '/billing');

            foreach ($portal as $path => $label) {
                $this->localeVisit($browser, $path);
                $this->assertGermanPage($browser, "portal {$path}");
                $this->shot($browser, 'Portal auf Deutsch: '.$label);
            }

            // Where money is the subject, it is there in German.
            $this->localeVisit($browser, '/billing/invoices');
            $this->assertStringContainsString('€34,51', $this->opsNormaliseMoney($this->opsPageText($browser)), 'the invoice gross in German');
            $this->localeVisit($browser, '/billing/products');
            $this->assertStringContainsString('€58,31', $this->opsNormaliseMoney($this->opsPageText($browser)), 'the token pack in German');
            $this->localeVisit($browser, '/billing');
            $this->assertStringContainsString('€34,51', $this->opsNormaliseMoney($this->opsPageText($browser)), 'the dashboard\'s recent invoice in German');
        });

        $admin = [
            '/billing/admin' => 'Dashboard',
            '/billing/admin/billables' => 'Billables',
            '/billing/admin/invoices' => 'Rechnungen',
            '/billing/admin/coupons' => 'Coupons',
            '/billing/admin/coupons/create' => 'Coupon anlegen',
            '/billing/admin/coupons/'.$coupon->id => 'Coupon-Detail',
            '/billing/admin/billables/'.$billable->getKey().'/grant' => 'Zugang vergeben',
            '/billing/admin/scheduled-changes' => 'Geplante Änderungen',
            '/billing/admin/past-due' => 'Überfällig',
            '/billing/admin/mismatches' => 'Länder-Abweichungen',
            '/billing/admin/refunds' => 'Erstattungen',
            '/billing/admin/oss' => 'OSS',
            '/billing/admin/bulk' => 'Bulk',
        ];

        $this->browse(function (Browser $browser) use ($billable, $invoice, $admin): void {
            $this->loginAdmin($browser);

            foreach ($admin as $path => $label) {
                $this->localeVisit($browser, $path);
                $this->assertGermanPage($browser, "admin {$path}");
                $this->shot($browser, 'Admin auf Deutsch: '.$label);
            }

            $this->localeVisit($browser, '/billing/admin/invoices');
            $this->assertStringContainsString('€34,51', $this->opsNormaliseMoney($this->opsPageText($browser)), 'the admin invoice list in German');

            foreach (['subscription', 'invoices', 'wallet', 'audit'] as $tab) {
                $this->opsOpenBillableTab($browser, $billable, $tab);
                $this->assertGermanPage($browser, "admin billable tab {$tab}");
                $this->shot($browser, 'Admin-Billable auf Deutsch: '.$tab);
            }

            // The refund dialog, opened: its hint, example and totals in German.
            $this->opsOpenBillableTab($browser, $billable, 'invoices');
            $this->clickWire($browser, '[wire\\:click="openRefund('.$invoice->id.')"]');
            $browser->waitFor('input[wire\\:model="refundAmount"]', 10)->pause(400);
            $this->assertGermanPage($browser, 'admin refund dialog');
            $this->assertStringContainsString('10,50', $this->opsPageText($browser), 'the typable example in German');
            $this->shot($browser, 'Erstattungsdialog auf Deutsch');
        });
    }

    // ── Decision 11: the customer is addressed as "du" ──────────────────────

    /**
     * The German checkout and the portal say "du" throughout (maintainer decision of
     * 26 November 2026). Before it, the checkout opened on "Wählen Sie den passenden Plan"
     * and the dashboard's coupon card said "Geben Sie einen beliebigen Code ein" next to
     * tiles saying "deine". The admin screens are left out on purpose: they address the
     * operator and keep their own register. The wording is asserted literally, not through
     * `__()`, so the test fails if the translation slides back.
     */
    public function test_the_german_checkout_and_portal_address_the_customer_as_du(): void
    {
        $this->serverConfig('app.locale', 'de');
        app()->setLocale('de');

        $prospect = $this->makeBillable([
            'billing_street' => 'Musterweg 12',
            'billing_postal_code' => '10115',
            'billing_city' => 'Berlin',
        ]);
        $subscriber = $this->opsSubscriber('pro', 0, [], 'DE');

        $this->browse(function (Browser $browser) use ($prospect, $subscriber): void {
            $plans = '[wire\\:click*="plan_code"]';
            $next = '[wire\\:click="next"]';
            $submit = '[wire\\:click="submit"]';

            $this->actAs($browser, $prospect, '/billing/checkout');
            $browser->waitFor($plans, 15)->pause(400);
            $browser->assertSee('Wähle den passenden Plan')
                ->assertSee('Du kannst deinen Plan jederzeit in den Abrechnungseinstellungen ändern.');
            $this->assertInformalGerman($browser, 'checkout: plan step');
            $this->shot($browser, 'Checkout auf Deutsch (du): Planwahl');

            $browser->click($plans)->pause(600);
            $this->clickWire($browser, $next);
            $browser->pause(700);

            if ($this->domCount($browser, $submit) === 0) {
                $browser->assertSee('Passe dein Abonnement an');
                $this->assertInformalGerman($browser, 'checkout: add-on step');
                $this->clickWire($browser, $next);
            }

            $browser->waitFor($submit, 15)->pause(400);
            $browser->assertSee('Überprüfe deine Auswahl.');
            $this->assertInformalGerman($browser, 'checkout: confirm step');
            $this->shot($browser, 'Checkout auf Deutsch (du): Bestätigung');

            $this->actAs($browser, $subscriber, '/billing');

            foreach (['/billing', '/billing/plan', '/billing/seats', '/billing/addons', '/billing/products', '/billing/usage', '/billing/invoices', '/billing/billing-data'] as $path) {
                $this->localeVisit($browser, $path);
                $this->assertInformalGerman($browser, "portal {$path}");
            }

            $this->localeVisit($browser, '/billing');
            $browser->assertSee('Gib einen beliebigen Code ein');
            $this->shot($browser, 'Portal auf Deutsch (du): Dashboard');
        });
    }

    // ── 53. Invoice PDF under a tenant prefix ───────────────────────────────

    public function test_the_invoice_pdf_is_served_under_the_tenant_prefix_and_another_organizations_is_refused(): void
    {
        WorkbenchSetting::set('tenant_mount', '1');

        $acme = $this->makeActiveSubscriber('pro', 'monthly', ['name' => 'Acme']);
        $beta = $this->makeActiveSubscriber('pro', 'monthly', ['name' => 'Beta']);
        $this->renewSubscription($acme);
        $this->renewSubscription($beta);
        $own = BillingInvoice::query()->where('billable_id', $acme->getKey())->sole();
        $foreign = BillingInvoice::query()->where('billable_id', $beta->getKey())->sole();

        $this->browse(function (Browser $browser) use ($acme, $own, $foreign): void {
            $this->actAs($browser, $acme, '/acme/billing');
            $this->waitForPathContains($browser, '/acme/billing');

            $download = sprintf('/acme/billing/invoices/%d/download', $own->id);

            // The dashboard's recent-invoices card: the PDF link the customer clicks.
            $this->waitForPresence($browser, 'a[href$="'.$download.'"]', 15);
            $this->assertSame(1, $this->domCount($browser, 'a[href$="'.$download.'"]'), 'one PDF link on the dashboard card');
            $this->assertSame(0, $this->domCount($browser, 'a[href*="/invoices/'.$foreign->id.'/download"]'), 'no link to the other organization\'s invoice');
            $this->assertPdf($this->opsFetch($browser, (string) $browser->attribute('a[href$="'.$download.'"]', 'href')), 'dashboard card');
            $this->shot($browser, 'Dashboard: PDF-Link mit Tenant');

            // The invoice archive.
            $browser->visit('/acme/billing/invoices');
            $this->waitForPresence($browser, 'a[href$="'.$download.'"]', 15);
            $this->assertPdf($this->opsFetch($browser, (string) $browser->attribute('a[href$="'.$download.'"]', 'href')), 'invoice archive');
            $this->shot($browser, 'Rechnungsarchiv: PDF-Link mit Tenant');

            // The other organization's invoice id, under the own prefix, the other's prefix
            // and the admin-free path: refused, and nothing of it is served.
            foreach ([
                sprintf('/acme/billing/invoices/%d/download', $foreign->id),
                sprintf('/beta/billing/invoices/%d/download', $foreign->id),
            ] as $path) {
                $fetched = $this->opsFetch($browser, $path);
                $this->assertSame(403, $fetched['status'], $path);
                $this->assertStringNotContainsString('pdf', strtolower($fetched['type']), $path);
            }

            $browser->visit(sprintf('/acme/billing/invoices/%d/download', $foreign->id))->waitForText('403', 15);
            $browser->assertSee('Forbidden');
            $this->shot($browser, 'Fremde Rechnung: 403');

            // A made-up id is not found, and the own invoice is still served afterwards.
            $this->assertSame(404, $this->opsFetch($browser, '/acme/billing/invoices/999999/download')['status']);
            $this->assertPdf($this->opsFetch($browser, $download), 'own invoice after the refusals');
        });
    }

    // ── 54. The return page for a stranger ──────────────────────────────────

    public function test_the_return_page_opened_for_another_organization_shows_nothing_of_it_and_never_asks_mollie(): void
    {
        WorkbenchSetting::set('count_get_payments', '1');

        $acme = $this->makeActiveSubscriber('pro', 'monthly', ['name' => 'Acme']);
        [$beta, $paymentId] = $this->billableWithOpenFirstPayment('Beta');

        // Signed in as a member of Acme, tenant-prefixed portal: Beta's return page.
        WorkbenchSetting::set('tenant_mount', '1');

        $this->browse(function (Browser $browser) use ($acme): void {
            $this->actAs($browser, $acme, '/acme/billing');
            $this->waitForPathContains($browser, '/acme/billing');

            $browser->visit('/beta/billing/return');
            $this->assertStrangerSeesNoSubscription($browser, 'tenant prefix');
            $this->shot($browser, 'Rückkehrseite von Beta als Acme-Mitglied');
        });
        $this->assertSame(0, $this->getPaymentCount($paymentId), 'tenant prefix: Mollie asked about Beta\'s payment');

        // Without the tenant mount, by query parameter.
        WorkbenchSetting::forget('tenant_mount');

        $this->browse(function (Browser $browser) use ($acme, $beta): void {
            // Signed out entirely: the parameter is the only thing that names a billable.
            $browser->visit('/_wb/logout');
            $browser->visit('/billing/return?organization='.$beta->getKey());
            $this->assertStrangerSeesNoSubscription($browser, 'signed out');
            $this->shot($browser, 'Rückkehrseite mit ?organization= abgemeldet');

            // Signed in as Acme: the page reports Acme's own (active) subscription, never Beta's.
            $this->actAs($browser, $acme, '/billing/return?organization='.$beta->getKey());
            $browser->waitForText(__('billing::portal.return.title'), 15)->pause(4500);
            $this->assertSame(0, $this->pollingElements($browser), 'signed in as Acme: polling');
            $browser->assertDontSee(__('billing::portal.return.processing_title'));
            $this->shot($browser, 'Rückkehrseite mit ?organization= als Acme');
        });
        $this->assertSame(0, $this->getPaymentCount($paymentId), 'query parameter: Mollie asked about Beta\'s payment');

        // The control: Beta's own member on Beta's return page does wait, and asks Mollie.
        $this->browse(function (Browser $browser) use ($beta, $paymentId): void {
            $this->actAs($browser, $beta, '/billing/return');
            $browser->waitForText(__('billing::portal.return.processing_title'), 15);
            $browser->waitUsing(15, 200, fn (): bool => $this->getPaymentCount($paymentId) > 0, 'the own return page never asked Mollie');
            $this->shot($browser, 'Kontrolle: Betas eigene Rückkehrseite wartet');
        });

        $beta->refresh();
        $this->assertSame($paymentId, $beta->getPendingFirstPaymentId(), 'Beta\'s pending payment untouched');
        $this->assertSame('open', (string) DB::table('fake_mollie_payments')->where('id', $paymentId)->value('status'));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function localeVisit(Browser $browser, string $path): void
    {
        $browser->visit($path);
        $this->waitForPresence($browser, '[wire\\:id]', 15);
        $browser->pause(700);
    }

    /** No raw translation key, the German document language, no English-formatted money. */
    private function assertGermanPage(Browser $browser, string $where): void
    {
        $text = $this->opsPageText($browser);
        $html = (string) $browser->script('return document.body.innerHTML;')[0];

        $this->assertStringNotContainsString('billing::', $text, "{$where}: a raw translation key on screen");
        // Attributes too: placeholders, labels and confirm prompts are read by people.
        $this->assertDoesNotMatchRegularExpression('/(placeholder|aria-label|title|wire:confirm)="[^"]*billing::/', $html, "{$where}: a raw key in an attribute");
        $this->assertDoesNotMatchRegularExpression('/\b(portal|admin|checkout|audit|validation)\.[a-z_]+\.[a-z_.]+\b/', $text, "{$where}: an unnamespaced key on screen");
        $this->assertSame('de', (string) $browser->script('return document.documentElement.lang;')[0], "{$where}: <html lang>");
        $this->assertDoesNotMatchRegularExpression('/€-?\d{1,3}(,\d{3})*\.\d{2}(?!\d)/u', $this->opsNormaliseMoney($text), "{$where}: money in English format");
    }

    /** No polite-form pronoun on a customer screen: the German texts say "du" (decision 11). */
    private function assertInformalGerman(Browser $browser, string $where): void
    {
        $found = preg_match_all('/\b(Sie|Ihnen|Ihr|Ihre|Ihren|Ihrem|Ihrer|Ihres)\b/u', $this->opsPageText($browser), $m);

        $this->assertSame(0, $found, "{$where}: formal address on screen (".implode(', ', array_unique($m[0] ?? [])).')');
    }

    /** @param array{status:int, type:string, head:string, length:int} $fetched */
    private function assertPdf(array $fetched, string $where): void
    {
        $this->assertSame(200, $fetched['status'], $where);
        $this->assertStringContainsString('pdf', strtolower($fetched['type']), $where);
        $this->assertStringStartsWith('%PDF', $fetched['head'], $where);
    }

    /**
     * A billable whose subscription checkout waits for an open first payment at Mollie.
     *
     * @return array{0: TestBillable, 1: string}
     */
    private function billableWithOpenFirstPayment(string $name): array
    {
        $billable = $this->makeBillable(['name' => $name]);
        $payment = FakeMollie::createPayment([
            'amount' => ['value' => '34.51', 'currency' => 'EUR'],
            'description' => $name.' — Pro',
            'sequenceType' => 'first',
            'metadata' => ['billable_type' => $billable->getMorphClass(), 'billable_id' => (string) $billable->getKey(), 'plan_code' => 'pro', 'interval' => 'monthly'],
        ]);

        $billable->forceFill(['subscription_meta' => array_merge(
            $billable->getBillingSubscriptionMeta(),
            ['pending_first_payment_id' => $payment['id']],
        )])->save();

        return [$billable->refresh(), (string) $payment['id']];
    }

    private function assertStrangerSeesNoSubscription(Browser $browser, string $where): void
    {
        $browser->waitFor('[dusk="return-no-subscription"]', 15);
        // Longer than two fast ticks: a page that polled would have asked by now.
        $browser->pause(4500);

        $this->assertSame(0, $this->pollingElements($browser), "{$where}: the page polls");
        $browser->assertDontSee(__('billing::portal.return.processing_title'))
            ->assertDontSee(__('billing::portal.return.title'))
            ->assertDontSee(__('billing::portal.return.failed_title'))
            ->assertDontSee(__('billing::portal.return.abandoned_title'));
    }

    /** Elements carrying any `wire:poll…` attribute. */
    private function pollingElements(Browser $browser): int
    {
        return (int) $browser->script(<<<'JS'
            return [...document.querySelectorAll('*')]
                .filter((el) => [...el.attributes].some((a) => a.name.startsWith('wire:poll'))).length;
        JS)[0];
    }

    private function getPaymentCount(string $paymentId): int
    {
        return (int) WorkbenchSetting::get('get_payment_count.'.$paymentId, '0');
    }
}
