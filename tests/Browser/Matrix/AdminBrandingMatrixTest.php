<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser\Matrix;

use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Tests\Browser\BrowserTestCase;
use Laravel\Dusk\Browser;

/**
 * Configuration a person can see: branding, back links, header components, the admin
 * panel's language, the display time zone and the currency symbol. Each case changes one
 * setting for the served app and looks at the page.
 */
class AdminBrandingMatrixTest extends BrowserTestCase
{
    use OperationsWorkbench;

    public function test_logo_favicon_and_company_name_brand_both_layouts(): void
    {
        $this->serverConfig('mollie-billing.logo_url', '/build/brand-logo.svg');
        $this->serverConfig('mollie-billing.favicon_url', '/brand/favicon.png');
        $this->serverConfig('mollie-billing.company_name', 'Graystack Billing');

        $billable = $this->opsSubscriber('starter');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->loginAdmin($browser);
            $browser->waitFor('header img', 15)->pause(400);

            expect((string) $browser->attribute('header img', 'src'))->toEndWith('/build/brand-logo.svg')
                ->and((string) $browser->script('return document.querySelector("link[rel=icon]").getAttribute("href");')[0])->toBe('/brand/favicon.png')
                ->and((string) $browser->script('return document.title;')[0])->toBe(__('billing::admin.title', ['app' => 'Graystack Billing']));
            $this->shot($browser, 'Branded admin');

            $this->actAs($browser, $billable);
            $browser->waitFor('header img', 15)->pause(400);

            expect((string) $browser->attribute('header img', 'src'))->toEndWith('/build/brand-logo.svg')
                ->and((string) $browser->script('return document.querySelector("link[rel=icon]").getAttribute("href");')[0])->toBe('/brand/favicon.png')
                ->and((string) $browser->script('return document.title;')[0])->toBe(__('billing::portal.title', ['app' => 'Graystack Billing']));
            $this->shot($browser, 'Branded portal');
        });
    }

    public function test_primary_color_changes_the_accent(): void
    {
        $accent = function (Browser $browser): string {
            return (string) $browser->script(
                'const el = document.querySelector("[data-flux-main], main, body *"); return getComputedStyle(el).getPropertyValue("--color-accent").trim();'
            )[0];
        };

        $seen = [];

        foreach (['teal', 'rose'] as $colour) {
            $this->serverConfig('mollie-billing.primary_color', $colour);

            $this->browse(function (Browser $browser) use ($accent, $colour, &$seen): void {
                $this->loginAdmin($browser);
                $browser->waitForText(__('billing::admin.dashboard.title'), 15)->pause(400);
                $seen[$colour] = $accent($browser);
                $this->shot($browser, 'Accent '.$colour);
            });
        }

        expect($seen['teal'])->not->toBe('')
            ->and($seen['rose'])->not->toBe('')
            ->and($seen['rose'])->not->toBe($seen['teal']);
    }

    public function test_back_links_lead_where_they_are_configured_to(): void
    {
        $this->serverConfig('mollie-billing.dashboard_url', 'https://app.example.test/home');
        $billable = $this->opsSubscriber('starter');

        $this->browse(function (Browser $browser) use ($billable): void {
            // The portal sends a customer back to their app.
            $this->actAs($browser, $billable);
            $browser->waitForText('Starter', 15)->pause(300);
            expect($this->domCount($browser, 'a[href="https://app.example.test/home"]'))->toBeGreaterThan(0);

            // The admin panel falls back to the same place while it has no own target …
            $this->loginAdmin($browser);
            $browser->waitForText(__('billing::admin.dashboard.title'), 15)->pause(300);
            expect($this->domCount($browser, 'a[href="https://app.example.test/home"]'))->toBeGreaterThan(0);
        });

        // … and goes its own way once it has one.
        $this->serverConfig('mollie-billing.admin_back_url', 'https://backoffice.example.test/');

        $this->browse(function (Browser $browser): void {
            $browser->visit('/billing/admin')->waitForText(__('billing::admin.dashboard.title'), 15)->pause(300);

            expect($this->domCount($browser, 'a[href="https://backoffice.example.test/"]'))->toBeGreaterThan(0)
                ->and($this->domCount($browser, 'a[href="https://app.example.test/home"]'))->toBe(0);
            $this->shot($browser, 'Admin back link');
        });
    }

    public function test_header_components_are_rendered_in_admin_and_portal(): void
    {
        $billable = $this->opsSubscriber('starter');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->loginAdmin($browser);
            $browser->waitForText(__('billing::admin.dashboard.title'), 15)->assertDontSee('header-probe-marker');
        });

        $this->serverConfig('mollie-billing.header_components', ['wb-header-probe']);

        $this->browse(function (Browser $browser) use ($billable): void {
            $browser->visit('/billing/admin')->waitForText('header-probe-marker', 15);
            $this->shot($browser, 'Header component in the admin panel');

            $this->actAs($browser, $billable);
            $browser->waitForText('header-probe-marker', 15);
        });
    }

    public function test_admin_locale_pins_the_panel_language_and_leaves_the_portal_alone(): void
    {
        $this->serverConfig('mollie-billing.admin_locale', 'de');
        $billable = $this->opsSubscriber('starter');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->loginAdmin($browser);
            $browser->waitForText(__('billing::admin.dashboard.title', [], 'de'), 15)->pause(300);

            expect((string) $browser->script('return document.documentElement.lang;')[0])->toBe('de');
            $browser->assertSee(__('billing::admin.nav.billables', [], 'de'));
            $this->shot($browser, 'Admin auf Deutsch');

            // The customer portal keeps the app's own locale.
            $this->actAs($browser, $billable);
            $browser->waitForText('Starter', 15)->pause(300);
            expect((string) $browser->script('return document.documentElement.lang;')[0])->toBe('en');
        });
    }

    public function test_display_time_zone_and_currency_symbol(): void
    {
        $billable = $this->opsSubscriber('starter');
        $invoice = $this->opsRenew($billable, '11.78');

        // Booked at 23:30 UTC on the 14th: the 15th in Tokyo.
        $invoice->forceFill(['created_at' => '2026-03-14 23:30:00'])->save();

        $date = fn (Browser $browser): string => trim((string) $browser->script(
            'return document.querySelector(\'[dusk="invoice-row-'.$invoice->id.'"] td\').innerText;'
        )[0]);

        $this->browse(function (Browser $browser) use ($billable, $invoice, $date): void {
            $this->actAs($browser, $billable, '/billing/invoices');
            $browser->waitFor('[dusk="invoice-row-'.$invoice->id.'"]', 15)->pause(300);

            expect($date($browser))->toContain('14.')
                ->and($browser->text('[dusk="invoice-'.$invoice->id.'-gross"]'))->toBe('€11.78');
        });

        $this->serverConfig('mollie-billing.billing_timezone', 'Asia/Tokyo');
        $this->serverConfig('mollie-billing.currency_symbol', 'EUR ');

        $this->browse(function (Browser $browser) use ($invoice, $date): void {
            $browser->visit('/billing/invoices')->waitFor('[dusk="invoice-row-'.$invoice->id.'"]', 15)->pause(300);

            expect($date($browser))->toContain('15.');
            expect(str_replace("\u{00a0}", ' ', $browser->text('[dusk="invoice-'.$invoice->id.'-gross"]')))->toBe(BillingMoney::format(1178));
            expect(BillingMoney::format(1178))->toStartWith('EUR');
            $this->shot($browser, 'Tokyo time, spelled-out currency');
        });
    }

    public function test_admin_routes_can_be_left_to_the_app(): void
    {
        // With auto-loading off and nothing mounted by the app, the panel does not exist.
        $this->serverConfig('mollie-billing.routes.auto_load_admin', false);

        $this->browse(function (Browser $browser): void {
            $browser->visit('/');
            $response = $this->opsFetch($browser, '/billing/admin/billables');

            expect($response['status'])->toBe(404);
        });

        $this->serverConfig('mollie-billing.routes.auto_load_admin', true);

        $this->browse(function (Browser $browser): void {
            $this->loginAdmin($browser);
            $browser->visit('/billing/admin/billables')->waitForText(__('billing::admin.billables.title'), 15);
        });
    }

    protected function tearDown(): void
    {
        $this->setIpCountry(null);

        parent::tearDown();
    }
}
