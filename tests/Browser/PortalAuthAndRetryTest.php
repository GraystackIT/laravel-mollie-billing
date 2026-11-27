<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Browser\Matrix\CouponWorkbench;
use GraystackIT\MollieBilling\Tests\Browser\Matrix\OperationsWorkbench;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Workbench\App\Support\WorkbenchSetting;

/**
 * Who may drive the portal, and where it lives.
 *
 *  - 40: the portal mounted the way a multi-tenant app mounts it — under
 *    `{organization:slug}`, named `tenant.`, with `urlParametersUsing()` — clicked through
 *    dashboard, plan, seats, addons and invoices with a Livewire action on each: every link,
 *    every redirect, the invoice download and the return URL handed to Mollie keep `/acme/`,
 *    and no request ends in an `UrlGenerationException`.
 *  - 41: a member of one organization opening another's portal (403), and replaying the
 *    actions of their own plan, seats and addons pages while the session's tenant resolver
 *    points at the other organization (403, nothing booked on either).
 *  - 42: the billing permission revoked while the seats page is open: "Update seats" is a 403,
 *    nothing booked, nothing sent to Mollie.
 *  - 43: an operator demoted while the refund modal is open: the refund is a 403, no refund at
 *    Mollie, no credit note.
 *
 * The levers live in the workbench: `tenant_mount` (WorkbenchServiceProvider::
 * registerTenantMount()), `/_wb/member-of/{ids}` and `/_wb/point-tenant/{id}` (memberships
 * apart from the session's tenant), `deny_billing_access` (authUsing() answers no) and
 * `deny_billing_admin` (AdminUser::canAccessBillingAdmin() answers no). The server reads all
 * of them on every request, so an open tab meets them on its next click.
 */
class PortalAuthAndRetryTest extends BrowserTestCase
{
    use CouponWorkbench;
    use OperationsWorkbench;

    private const PREVIEW_BUSINESS = '[wire\\:click="previewFor(\'business\')"]';

    private const PREVIEW_PRO = '[wire\\:click="previewFor(\'pro\')"]';

    private const APPLY = '[x-on\\:click*="applyChange"]';

    private const ENABLE_SOFTDRINKS = '[wire\\:click="enableAddon(\'softdrinks\')"]';

    private const ADDON_POLL = '[wire\\:poll\\.3s="pollForChange"]';

    private const REFUND_AMOUNT = 'input[wire\\:model="refundAmount"]';

    private const REFUND_FULL = '[wire\\:click="refundFull"]';

    /** Bytes of the served app's log before the test, so only this test's lines are read. */
    private int $logOffset = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $log = $this->serverLogPath();
        $this->logOffset = is_file($log) ? (int) filesize($log) : 0;
    }

    // ── 40. The portal under /{organization:slug} ───────────────────────────

    public function test_a_tenant_prefixed_portal_keeps_the_tenant_in_every_link_action_and_redirect(): void
    {
        WorkbenchSetting::set('tenant_mount', '1');

        $acme = $this->makeActiveSubscriber('pro', 'monthly', ['name' => 'Acme']);
        // A second organization, so a link that lost its tenant cannot pass by luck.
        $this->makeActiveSubscriber('pro', 'monthly', ['name' => 'Beta']);
        $this->renewSubscription($acme);
        $invoice = BillingInvoice::query()->where('billable_id', $acme->getKey())->sole();

        $this->coupons()->creditsCoupon('TOPUP250', ['Tokens' => 250]);
        $this->coupons()->create([
            'code' => 'TENANT10',
            'name' => '10 % for tenants',
            'type' => CouponType::Recurring,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 10,
            'discount_periods' => 3,
        ]);

        $this->browse(function (Browser $browser) use ($acme, $invoice): void {
            $this->actAs($browser, $acme, '/acme/billing');
            $this->waitForPathContains($browser, '/acme/billing');
            $browser->waitFor('[wire\\:click="redeemCoupon"]', 15)->pause(400);
            $this->assertSame('/acme/billing', $this->path($browser));
            $this->recordLivewire($browser);
            $this->assertEveryBillingLinkCarries($browser, 'acme', 'dashboard');
            $this->shot($browser, 'Tenant: Dashboard von Acme');

            // Dashboard: a code redeemed through /livewire/update, which re-renders every link.
            $browser->type('couponCode', 'TOPUP250');
            $this->clickWire($browser, '[wire\\:click="redeemCoupon"]');
            $browser->waitForText('TOPUP250', 15)->pause(600);
            $this->assertCleanLivewire($browser, 'dashboard: redeem');
            $this->assertEveryBillingLinkCarries($browser, 'acme', 'dashboard after redeem');

            // Plan: through the navigation, a preview and a code applied to it.
            $this->followNav($browser, '/acme/billing/plan');
            $browser->waitFor(self::PREVIEW_BUSINESS, 15)->pause(400);
            $this->recordLivewire($browser);
            $this->clickWire($browser, self::PREVIEW_BUSINESS);
            $browser->waitFor(self::APPLY, 15)->pause(400);
            $browser->waitFor('input[wire\\:model="couponInput"]', 10)->type('couponInput', 'TENANT10');
            $this->clickWire($browser, '[wire\\:click="applyCoupon"]');
            $browser->waitForText('TENANT10', 15)->pause(600);
            $this->assertCleanLivewire($browser, 'plan: preview + code');
            $this->assertEveryBillingLinkCarries($browser, 'acme', 'plan');
            $this->shot($browser, 'Tenant: Plan mit Code');

            // Seats: one more seat, booked.
            $this->followNav($browser, '/acme/billing/seats');
            $browser->waitFor('[wire\\:click="increment"]', 15)->pause(400);
            $this->recordLivewire($browser);
            $this->clickWire($browser, '[wire\\:click="increment"]');
            $this->waitForPresence($browser, '[dusk="seats-due-now"]', 15);
            $browser->pause(400);
            $this->clickWire($browser, '[wire\\:click="syncSeats"]');
            $browser->waitUsing(15, 200, fn (): bool => $acme->fresh()->getBillingSeatCount() === 4, 'the seat was never booked');
            $browser->pause(800);
            $this->assertCleanLivewire($browser, 'seats: increment + update');
            $this->assertEveryBillingLinkCarries($browser, 'acme', 'seats');
            $this->shot($browser, 'Tenant: Sitz gebucht');
        });

        $seatCharge = (string) ($acme->fresh()->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'] ?? '');
        $this->assertNotSame('', $seatCharge, 'the seat is charged for the rest of the period');
        $this->assertSame(200, $this->deliverWebhook($seatCharge));

        $this->browse(function (Browser $browser) use ($acme, $invoice): void {
            // Addons: Softdrinks on, its charge settled by the webhook, announced by the poll.
            $browser->visit('/acme/billing/addons');
            $this->waitForPresence($browser, self::ENABLE_SOFTDRINKS, 15);
            $browser->pause(400);
            $this->recordLivewire($browser);
            $this->confirmInModal($browser, 'enable-addon-softdrinks', self::ENABLE_SOFTDRINKS);
            $browser->waitUsing(15, 200, fn (): bool => in_array('softdrinks', $acme->fresh()->getActiveBillingAddonCodes(), true),
                'the addon was never switched on');
            $addonCharge = (string) ($acme->fresh()->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'] ?? '');
            $this->assertNotSame('', $addonCharge);
            $this->assertSame(200, $this->deliverWebhook($addonCharge));
            $browser->waitForText(__('billing::portal.addons_flash.enabled', ['addon' => 'Softdrinks']), 20)->pause(400);
            $this->assertCleanLivewire($browser, 'addons: enable');
            $this->assertEveryBillingLinkCarries($browser, 'acme', 'addons');
            $this->shot($browser, 'Tenant: Add-on aktiviert');

            // Invoices: the download link carries the tenant and serves the PDF.
            $this->followNav($browser, '/acme/billing/invoices');
            $download = sprintf('/acme/billing/invoices/%d/download', $invoice->id);
            $this->waitForPresence($browser, 'a[href$="'.$download.'"]', 15);
            $this->recordLivewire($browser);
            $this->refreshFirstComponent($browser);
            $this->assertCleanLivewire($browser, 'invoices: refresh');
            $this->assertEveryBillingLinkCarries($browser, 'acme', 'invoices');
            $this->assertSame(0, $this->domCount($browser, 'a[href*="/billing/invoices/"][href*="/download"]:not([href*="/acme/billing/"])'), 'a download link without the tenant');
            $fetched = $this->opsFetch($browser, $download);
            $this->assertSame(200, $fetched['status'], 'the invoice is downloadable under the tenant');
            $this->assertSame('%PDF', substr($fetched['head'], 0, 4));
            $this->shot($browser, 'Tenant: Rechnungen');

            // The checkout of a tenant that already subscribes sends it home — to its home.
            $browser->visit('/acme/billing/checkout');
            $this->waitForPathContains($browser, '/acme/billing');
            $browser->waitFor('[wire\\:click="redeemCoupon"]', 15);
            $this->assertSame('/acme/billing', $this->path($browser), 'the checkout redirect keeps the tenant');
        });

        $acme->refresh();
        $this->assertSame(4, $acme->getBillingSeatCount());
        $this->assertContains('softdrinks', $acme->getActiveBillingAddonCodes());
        // A fresh instance: HasWallets keeps the wallets it loaded on the model.
        $this->assertSame(250, TestBillable::query()->findOrFail($acme->getKey())->purchasedBillingCredits('Tokens'), 'the code on the dashboard was booked');
        $this->assertSame(1, BillingInvoice::query()->where('mollie_payment_id', $seatCharge)->count(), 'the seat charge invoiced once');
        $this->assertNoUrlGenerationErrors();
    }

    public function test_a_tenant_on_the_free_plan_upgrades_and_mollie_returns_it_to_its_own_return_page(): void
    {
        WorkbenchSetting::set('tenant_mount', '1');

        $free = $this->couponBillableIn('free');
        $free->forceFill(['name' => 'Freebird'])->save();
        $this->makeActiveSubscriber('pro', 'monthly', ['name' => 'Beta']);

        $this->browse(function (Browser $browser) use ($free): void {
            $this->actAs($browser, $free, '/freebird/billing/plan');
            $this->waitForPathContains($browser, '/freebird/billing');
            $browser->visit('/freebird/billing/plan');
            $browser->waitFor(self::PREVIEW_PRO, 15)->pause(400);
            $this->recordLivewire($browser);
            $this->clickWire($browser, self::PREVIEW_PRO);
            $browser->waitFor(self::APPLY, 15)->pause(400);
            $this->clickWire($browser, self::APPLY);
            $this->waitForPresence($browser, '[dusk="upgrade-total"]', 15);
            $browser->pause(400);
            $this->assertCleanLivewire($browser, 'free → pro: confirmation');
            $this->assertEveryBillingLinkCarries($browser, 'freebird', 'upgrade confirmation');
            $browser->script('document.querySelector(\'[wire\\\\:click="confirmAndPay"]\').click()');

            $this->waitForPathContains($browser, '/fake-mollie/checkout/');
            $payment = $this->latestFakePayment();
            $this->assertSame('first', $payment['sequence_type']);
            $this->assertStringStartsWith('/freebird/billing/return', (string) parse_url((string) $payment['redirect_url'], PHP_URL_PATH), 'the return URL handed to Mollie keeps the tenant');
            $this->assertSame('/billing/webhook', (string) parse_url((string) $payment['webhook_url'], PHP_URL_PATH), 'the webhook stays outside the tenant');

            $this->settleAtFakeMollie($browser, 'paid');
            $browser->waitForText(__('billing::portal.return.title'), 20)->pause(400);
            $this->assertStringStartsWith('/freebird/billing/return', $this->path($browser));
            $this->assertEveryBillingLinkCarries($browser, 'freebird', 'return page');
            $this->shot($browser, 'Tenant: Rückkehr von Mollie');

            $browser->clickLink(__('billing::portal.return.to_dashboard'));
            $this->waitForPathContains($browser, '/freebird/billing');
            $browser->waitFor('[wire\\:click="redeemCoupon"]', 15);
            $this->assertSame('/freebird/billing', $this->path($browser));
        });

        $free->refresh();
        $this->assertSame('pro', $free->getBillingSubscriptionPlanCode());
        $this->assertSame(SubscriptionSource::Mollie, $free->subscription_source);
        $this->assertSame(1, DB::table('fake_mollie_payments')->count(), 'one payment');
        $this->assertSame(1, BillingInvoice::query()->where('billable_id', $free->getKey())->count(), 'one invoice');
        $this->assertNoUrlGenerationErrors();
    }

    // ── 41. Another organization's portal ───────────────────────────────────

    public function test_a_member_of_one_organization_gets_403_on_another_organizations_portal(): void
    {
        WorkbenchSetting::set('tenant_mount', '1');

        $acme = $this->makeActiveSubscriber('starter', 'monthly', ['name' => 'Acme']);
        $beta = $this->makeActiveSubscriber('starter', 'monthly', ['name' => 'Beta']);
        $betaBefore = $this->snapshot($beta);

        $this->browse(function (Browser $browser) use ($acme): void {
            $this->actAs($browser, $acme, '/acme/billing');
            $this->waitForPathContains($browser, '/acme/billing');
            $browser->waitFor('[wire\\:click="redeemCoupon"]', 15);

            foreach (['/beta/billing', '/beta/billing/plan', '/beta/billing/invoices', '/beta/billing/seats', '/beta/billing/addons'] as $path) {
                $browser->visit($path)->waitForText('403', 15);
                $browser->assertSee('Forbidden')->assertDontSee('Beta')->assertDontSee('Starter');
                $this->assertSame(403, $this->opsFetch($browser, $path)['status'], $path);
            }
            $this->shot($browser, 'Fremde Organisation: 403');

            // The own portal is untouched by the refusals.
            $this->assertSame(200, $this->opsFetch($browser, '/acme/billing/plan')['status']);
        });

        $this->assertEquals($betaBefore, $this->snapshot($beta), 'nothing changed on Beta');
        $this->assertSame(0, DB::table('fake_mollie_payments')->count());
    }

    public function test_replayed_portal_actions_are_refused_while_the_session_tenant_points_at_another_organization(): void
    {
        $acme = $this->makeActiveSubscriber('pro', 'monthly', ['name' => 'Acme']);
        $beta = $this->makeActiveSubscriber('pro', 'monthly', ['name' => 'Beta']);
        $acmeBefore = $this->snapshot($acme);
        $betaBefore = $this->snapshot($beta);

        $this->browse(function (Browser $browser) use ($acme, $beta): void {
            $this->actAs($browser, $acme, '/_wb/member-of/'.$acme->getKey());

            // Plan: the preview is the user's own; then the session names Beta, and Apply is
            // sent from the page that was loaded for Acme.
            $browser->visit('/billing/plan')->waitFor(self::PREVIEW_BUSINESS, 15)->pause(400);
            $this->clickWire($browser, self::PREVIEW_BUSINESS);
            $browser->waitFor(self::APPLY, 15)->pause(400);
            $this->recordLivewire($browser);
            $this->pointTenantAt($browser, $beta);
            $this->clickWire($browser, self::APPLY);
            $this->assertRefusedWith403($browser, 'plan: apply');
            $this->shot($browser, 'Plan: Apply mit fremdem Tenant → 403');

            // Seats.
            $this->pointTenantAt($browser, $acme);
            $browser->visit('/billing/seats')->waitFor('[wire\\:click="increment"]', 15)->pause(400);
            $this->clickWire($browser, '[wire\\:click="increment"]');
            $this->waitForPresence($browser, '[dusk="seats-due-now"]', 15);
            $browser->pause(400);
            $this->recordLivewire($browser);
            $this->pointTenantAt($browser, $beta);
            $this->clickWire($browser, '[wire\\:click="syncSeats"]');
            $this->assertRefusedWith403($browser, 'seats: update');

            // Addons.
            $this->pointTenantAt($browser, $acme);
            $browser->visit('/billing/addons');
            $this->waitForPresence($browser, self::ENABLE_SOFTDRINKS, 15);
            $browser->pause(400);
            $this->recordLivewire($browser);
            $this->pointTenantAt($browser, $beta);
            $this->confirmInModal($browser, 'enable-addon-softdrinks', self::ENABLE_SOFTDRINKS);
            $this->assertRefusedWith403($browser, 'addons: enable');

            // And the pages themselves, opened fresh while the session names Beta.
            $browser->visit('/billing/plan')->waitForText('403', 15);
            $browser->assertSee('Forbidden');
        });

        $this->assertEquals($betaBefore, $this->snapshot($beta), 'nothing booked on Beta');
        $this->assertEquals($acmeBefore, $this->snapshot($acme), 'nor on Acme');
        $this->assertSame(0, DB::table('fake_mollie_payments')->count(), 'no payment at Mollie');
    }

    // ── 42. Billing permission revoked mid-session ──────────────────────────

    public function test_update_seats_after_the_billing_permission_was_revoked_is_a_403_and_books_nothing(): void
    {
        $billable = $this->makeActiveSubscriber('pro', 'monthly');
        $before = $this->snapshot($billable);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/seats');
            $browser->waitFor('[wire\\:click="increment"]', 15)->pause(400);
            $this->clickWire($browser, '[wire\\:click="increment"]');
            $this->waitForPresence($browser, '[dusk="seats-due-now"]', 15);
            $browser->pause(400);
            $this->recordLivewire($browser);
            $this->shot($browser, 'Ein Sitz mehr gewählt');

            WorkbenchSetting::set('deny_billing_access', '1');

            $this->clickWire($browser, '[wire\\:click="syncSeats"]');
            $this->assertRefusedWith403($browser, 'seats: update after revoke');
            $this->shot($browser, 'Recht entzogen: 403');

            $browser->refresh()->waitForText('403', 15);
            $browser->assertSee('Forbidden');

            // Given back: the page shows the seats as they were.
            WorkbenchSetting::forget('deny_billing_access');
            $browser->visit('/billing/seats')->waitFor('[dusk="seats-current"]', 15);
            $browser->assertSeeIn('[dusk="seats-current"]', '3');
        });

        $this->assertEquals($before, $this->snapshot($billable), 'seat count, meta and Mollie untouched');
        $this->assertSame(3, $billable->fresh()->getBillingSeatCount());
        $this->assertSame(0, DB::table('fake_mollie_payments')->count(), 'no payment');
    }

    // ── 43. Operator demoted mid-session ────────────────────────────────────

    public function test_a_refund_from_an_operator_demoted_mid_session_is_a_403_and_books_nothing(): void
    {
        $billable = $this->opsSubscriber('starter', 0, [], 'DE');
        $invoice = $this->opsRenew($billable, '11.78');

        $this->browse(function (Browser $browser) use ($billable, $invoice): void {
            $this->loginAdmin($browser);
            $this->opsOpenBillableTab($browser, $billable, 'invoices');
            $this->clickWire($browser, '[wire\\:click="openRefund('.$invoice->id.')"]');
            $browser->waitFor(self::REFUND_AMOUNT, 10)->pause(400);
            $this->recordLivewire($browser);
            $this->shot($browser, 'Erstattungsdialog offen');

            WorkbenchSetting::set('deny_billing_admin', '1');

            $this->clickWire($browser, self::REFUND_FULL);
            $browser->pause(300);
            $browser->acceptDialog();

            $this->assertRefusedWith403($browser, 'admin: refund after demotion');
            $browser->assertDontSee(__('billing::admin.billables.invoices.flash.refund_processed'));
            $this->shot($browser, 'Operator entzogen: 403');

            $browser->visit('/billing/admin/billables/'.$billable->getKey())->waitForText('403', 15);
            $browser->assertSee('Forbidden');
        });

        $this->assertSame(0, DB::table('fake_mollie_refunds')->count(), 'no refund at Mollie');
        $this->assertSame(0, BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count(), 'no credit note');
        $this->assertSame(0, $invoice->fresh()->effectiveRefundedNet());
        $this->assertFalse($invoice->fresh()->isFullyRefunded());
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function path(Browser $browser): string
    {
        return (string) parse_url($browser->driver->getCurrentURL(), PHP_URL_PATH);
    }

    /** Click the portal navigation's link to `$path` — the link itself, as rendered. */
    private function followNav(Browser $browser, string $path): void
    {
        $selector = 'a[href$="'.$path.'"]';
        $this->waitForPresence($browser, $selector, 15);
        $browser->script(sprintf('document.querySelector(%s).click()', json_encode($selector)));
        $browser->waitUsing(15, 100, fn (): bool => $this->path($browser) === $path, "never arrived at {$path}");
        $browser->pause(600);
    }

    /**
     * Every link and form target on the page that leads into the portal or the checkout
     * carries the tenant's prefix — read from the DOM, hidden ones (modals) included.
     */
    private function assertEveryBillingLinkCarries(Browser $browser, string $slug, string $where): void
    {
        $targets = (array) $browser->script(<<<'JS'
            return [
                ...[...document.querySelectorAll('a[href]')].map((a) => a.href),
                ...[...document.querySelectorAll('form[action]')].map((f) => f.action),
            ].filter((u) => u.startsWith(window.location.origin))
             .map((u) => new URL(u).pathname)
             .filter((p) => p.includes('/billing'));
        JS)[0];

        $this->assertNotEmpty($targets, "{$where}: no portal links found at all");

        $lost = array_values(array_filter($targets, static fn (string $p): bool => ! str_starts_with($p, '/'.$slug.'/billing')));
        $this->assertSame([], $lost, "{$where}: links without /{$slug}/");
    }

    /**
     * Record, from now on, the status of every Livewire update the page sends. Livewire
     * calls the global `fetch` per request, so a wrapper sees each one.
     */
    private function recordLivewire(Browser $browser): void
    {
        $browser->script(<<<'JS'
            window.__wbStatuses = [];
            if (! window.__wbFetchWrapped) {
                window.__wbFetchWrapped = true;
                const original = window.fetch.bind(window);
                window.fetch = async (...args) => {
                    const response = await original(...args);
                    const url = String(args[0] && args[0].url ? args[0].url : args[0]);
                    if (url.includes('/update')) { window.__wbStatuses.push(response.status); }
                    return response;
                };
            }
        JS);
    }

    /** @return list<int> */
    private function livewireStatuses(Browser $browser): array
    {
        return array_map('intval', (array) $browser->script('return window.__wbStatuses || [];')[0]);
    }

    /** What Livewire's error dialog shows, or null when none is open. */
    private function errorModalText(Browser $browser): ?string
    {
        $text = $browser->script(<<<'JS'
            const frame = document.querySelector('#livewire-error iframe');
            return frame && frame.contentDocument ? frame.contentDocument.body.innerText : null;
        JS)[0];

        return is_string($text) ? $text : null;
    }

    /** Every Livewire request since recording answered 200, and no error dialog opened. */
    private function assertCleanLivewire(Browser $browser, string $what): void
    {
        $statuses = $this->livewireStatuses($browser);

        $this->assertNotEmpty($statuses, "{$what}: no Livewire request was recorded");
        $this->assertSame([], array_values(array_filter($statuses, static fn (int $s): bool => $s !== 200)), "{$what}: a Livewire request failed");
        $this->assertNull($this->errorModalText($browser), "{$what}: Livewire showed an error");
    }

    /** The action's request answered 403, and the customer sees the refusal. */
    private function assertRefusedWith403(Browser $browser, string $what): void
    {
        $browser->waitUsing(15, 100, fn (): bool => $this->livewireStatuses($browser) !== [], "{$what}: the action was never sent");
        $browser->pause(400);

        $this->assertSame([403], $this->livewireStatuses($browser), $what);

        $browser->waitUsing(10, 100, fn (): bool => $this->errorModalText($browser) !== null, "{$what}: no refusal shown");
        $text = (string) $this->errorModalText($browser);
        $this->assertStringContainsString('403', $text, $what);
        $this->assertStringContainsString('Forbidden', $text, $what);
    }

    /** Point the session's tenant at another billable, from the page that is open. */
    private function pointTenantAt(Browser $browser, TestBillable $billable): void
    {
        $this->assertSame(200, $this->opsFetch($browser, '/_wb/point-tenant/'.$billable->getKey())['status']);
    }

    /** Send the request a poll sends ($refresh) on the page's first component. */
    private function refreshFirstComponent(Browser $browser): void
    {
        $before = count($this->livewireStatuses($browser));

        $browser->script(<<<'JS'
            const el = document.querySelector('[wire\\:id]');
            window.Livewire.find(el.getAttribute('wire:id')).$refresh();
        JS);

        $browser->waitUsing(10, 100, fn (): bool => count($this->livewireStatuses($browser)) > $before, 'the refresh was never answered')
            ->pause(600);
    }

    /**
     * What a booking would change: the billable's own columns and the Mollie state behind it.
     *
     * @return array<string, mixed>
     */
    private function snapshot(TestBillable $billable): array
    {
        $billable = $billable->fresh();
        $subscriptionId = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');
        $subscription = $subscriptionId !== ''
            ? (array) DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->first(['amount_value', 'metadata', 'updated_at', 'status'])
            : null;

        return [
            'plan' => $billable->getBillingSubscriptionPlanCode(),
            'seats' => $billable->getBillingSeatCount(),
            'addons' => $billable->getActiveBillingAddonCodes(),
            'meta' => $billable->getBillingSubscriptionMeta(),
            'status' => $billable->getBillingSubscriptionStatus()?->value,
            'invoices' => BillingInvoice::query()->where('billable_id', $billable->getKey())->count(),
            'mollie_subscription' => $subscription,
        ];
    }

    private function serverLogPath(): string
    {
        return static::applicationBasePath().'/storage/logs/laravel.log';
    }

    /** No request of this test ended in a route that could not be built. */
    private function assertNoUrlGenerationErrors(): void
    {
        $log = $this->serverLogPath();
        $written = is_file($log) ? (string) file_get_contents($log, false, null, $this->logOffset) : '';

        $this->assertStringNotContainsString('UrlGenerationException', $written);
        $this->assertStringNotContainsString('Missing required parameter', $written);
    }
}
