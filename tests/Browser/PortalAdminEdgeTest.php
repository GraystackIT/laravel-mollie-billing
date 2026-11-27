<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Support\BillingAuditEntry;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Browser\Matrix\CouponWorkbench;
use GraystackIT\MollieBilling\Tests\Browser\Matrix\OperationsWorkbench;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Workbench\App\FakeMollie\FakeMollie;
use Workbench\App\Support\WorkbenchSetting;

/**
 * Portal and admin screens at their edges — a state that moved under an open tab, a crafted
 * call, a locale or a currency other than the default:
 *
 *  - 48: a country mismatch opened by a renewal webhook while the seats page is open —
 *    "Update seats" meets the persistent `billing.country-resolved` gate: the customer is
 *    sent to the dashboard (mismatch banner), nothing is booked, nothing reaches Mollie;
 *  - 49: an operator cancels from a subscription tab loaded before the subscription expired —
 *    the refusal is shown in the prompt (no 500), no audit entry, nothing changes;
 *  - 51: `admin_locale` = en with the app in German — a refund modal's refusal comes back in
 *    English, amounts in English format, across Livewire round-trips; the portal stays German;
 *  - 52: a refund of ¥500 on a JPY invoice — the modal's example is typable, the credit note
 *    is ¥500 net and Mollie is asked for ¥595, not ¥59 500;
 *  - 55: the products page on a free Local plan (and on an access grant) with
 *    `allow_one_time_orders` = false — no buy action, and a crafted `purchase()` (also one
 *    riding a 100 % code) is refused with an error toast, no payment, no units, no redemption.
 *
 * The German walkthrough (50), the tenant-prefixed invoice download (53) and the return page
 * for a stranger (54) are in PortalAdminLocaleTenantTest.
 */
class PortalAdminEdgeTest extends BrowserTestCase
{
    use CouponWorkbench;
    use OperationsWorkbench;

    private const ADMIN_CANCEL_SUBMIT = 'form[wire\\:submit="confirmCancel"] button[type="submit"]';

    private const REFUND_AMOUNT = 'input[wire\\:model="refundAmount"]';

    private const REFUND_SUBMIT = 'form[wire\\:submit="refund"] button[type="submit"]';

    // ── 48. Country mismatch opened while the seats page is open ────────────

    public function test_update_seats_is_refused_by_the_country_gate_when_a_webhook_opened_a_mismatch_meanwhile(): void
    {
        $billable = $this->makeActiveSubscriber('pro', 'monthly');
        $this->setIpCountry('NL');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/seats');
            $browser->waitFor('[wire\\:click="increment"]', 15)->pause(400);
            $this->clickWire($browser, '[wire\\:click="increment"]');
            $this->waitForPresence($browser, '[dusk="seats-due-now"]', 15);
            $browser->pause(400);
            $this->shot($browser, 'Ein Sitz mehr gewählt, Seite offen');

            // Mollie's scheduler renews from a Dutch card while the tab is open: the
            // three-way check opens a mismatch.
            $this->edgeRenewFromCountry($billable->fresh(), 'NL');
            $this->assertSame(CountryMismatchStatus::Pending, BillingCountryMismatch::query()->sole()->status);

            $this->edgeRecordLivewire($browser);
            $this->clickWire($browser, '[wire\\:click="syncSeats"]');

            // The persistent gate answers the Livewire update with its redirect; the page
            // follows it to the dashboard, where the correction lives.
            $browser->waitUsing(15, 100, fn (): bool => $this->edgePath($browser) === '/billing', 'the gate never sent the customer to the dashboard');
            $browser->waitForText(__('billing::portal.country_mismatch.banner_heading'), 15)->pause(400);
            $this->assertNull($this->edgeErrorModalText($browser), 'no error dialog');
            $this->shot($browser, 'Aktualisieren: zum Dashboard umgeleitet');

            // Opening the page again is refused the same way, with the gate's own message.
            $browser->visit('/billing/seats');
            $browser->waitUsing(15, 100, fn (): bool => $this->edgePath($browser) === '/billing', 'the seats page was not refused');
            $browser->waitForText(__('billing::portal.country_mismatch.middleware_block'), 15);
            $this->shot($browser, 'Sitze-Seite gesperrt bis zur Korrektur');
        });

        $billable->refresh();
        $meta = $billable->getBillingSubscriptionMeta();

        $this->assertSame(3, $billable->getBillingSeatCount(), 'no seat booked');
        $this->assertArrayNotHasKey('pending_prorata_change', $meta, 'no seat charge in flight');
        $this->assertSame(1, DB::table('fake_mollie_payments')->count(), 'only the renewal reached Mollie');
        $this->assertSame(1, BillingInvoice::query()->where('billable_id', $billable->getKey())->count(), 'only the renewal invoiced');
        $this->assertSame(
            '34.51',
            (string) DB::table('fake_mollie_subscriptions')->where('id', (string) $meta['mollie_subscription_id'])->value('amount_value'),
            'the Mollie subscription keeps Pro\'s amount, no seat added',
        );
    }

    // ── 49. Admin cancel on a stale tab ─────────────────────────────────────

    public function test_an_operator_cancel_from_a_tab_loaded_before_the_subscription_expired_is_refused_in_the_prompt(): void
    {
        $billable = $this->makeActiveSubscriber('pro', 'monthly');
        $subscriptionId = (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'];

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->loginAdmin($browser);
            $this->openSubscriptionTab($browser, $billable);
            $browser->waitFor('[wire\\:click="openCancel(false)"]', 15)->pause(300);
            $this->shot($browser, 'Abo-Tab: aktiv geladen');

            // The subscription ends behind the operator's back — the expiry job, say.
            $billable->forceFill([
                'subscription_status' => SubscriptionStatus::Expired,
                'subscription_ends_at' => now()->subDay(),
            ])->save();

            $this->edgeRecordLivewire($browser);
            $this->clickWire($browser, '[wire\\:click="openCancel(false)"]');
            $browser->waitFor('textarea[name="cancelNote"]', 10)->pause(400)
                ->type('cancelNote', 'Stale tab: the customer asked to stop');
            $this->clickWire($browser, self::ADMIN_CANCEL_SUBMIT);

            $browser->waitForText('There is no subscription to cancel.', 15)->pause(400);
            $this->assertEdgeCleanLivewire($browser, 'admin cancel on an expired row');
            $browser->assertDontSee(__('billing::admin.billables.subscription.flash.cancelled_grace'))
                ->assertDontSee(__('billing::admin.billables.subscription.flash.cancelled_now'));
            $this->shot($browser, 'Kündigung abgelehnt: nichts zu kündigen');

            // The immediate variant, sent from the same stale component, is refused alike.
            $this->edgeRecordLivewire($browser);
            $browser->script(<<<'JS'
                (async () => {
                    const el = document.querySelector('textarea[name="cancelNote"]').closest('[wire\\:id]');
                    const w = window.Livewire.find(el.getAttribute('wire:id'));
                    await w.call('openCancel', true);
                    await w.set('cancelNote', 'Stale tab: immediately');
                    await w.call('confirmCancel');
                    window.__edgeCrafted = true;
                })();
                window.__edgeCrafted = false;
            JS);
            $browser->waitUsing(15, 100, fn (): bool => (bool) $browser->script('return window.__edgeCrafted === true;')[0], 'the immediate cancel was never answered');
            $browser->pause(400);
            $this->assertEdgeCleanLivewire($browser, 'admin immediate cancel on an expired row');
            $browser->assertSee('There is no subscription to cancel.');
        });

        $billable->refresh();
        $this->assertSame(SubscriptionStatus::Expired, $billable->getBillingSubscriptionStatus(), 'the row stays expired, not "cancelled"');
        $this->assertSame(0, BillingAuditEntry::model()::query()->where('event', 'subscription_cancelled')->count(), 'no cancellation was audited');
        $this->assertSame('active', (string) DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->value('status'), 'nothing sent to Mollie');
    }

    // ── 51. admin_locale pinning across Livewire round-trips ────────────────

    public function test_a_pinned_english_admin_answers_a_refund_refusal_in_english_while_the_app_runs_german(): void
    {
        $this->serverConfig('app.locale', 'de');
        $this->serverConfig('mollie-billing.admin_locale', 'en');
        // serverConfig() moves this process's locale too; the test reads labels in English.
        app()->setLocale('en');

        $billable = $this->opsSubscriber('starter', 0, [], 'DE');
        $invoice = $this->opsRenew($billable, '11.78');

        $this->browse(function (Browser $browser) use ($billable, $invoice): void {
            $this->loginAdmin($browser);
            $this->opsOpenBillableTab($browser, $billable, 'invoices');
            $this->assertSame('en', (string) $browser->script('return document.documentElement.lang;')[0]);

            $this->clickWire($browser, '[wire\\:click="openRefund('.$invoice->id.')"]');
            $browser->waitFor(self::REFUND_AMOUNT, 10)->pause(400);
            $browser->assertSee(__('billing::admin.billables.invoices.modal.description', [], 'en'));
            $this->edgeRecordLivewire($browser);

            // Not a number.
            $browser->type(self::REFUND_AMOUNT, 'zehn');
            $this->clickWire($browser, self::REFUND_SUBMIT);
            $english = (string) __('billing::admin.billables.invoices.flash.invalid_amount', ['example' => '10.50'], 'en');
            $german = (string) __('billing::admin.billables.invoices.flash.invalid_amount', ['example' => '10,50'], 'de');
            $browser->waitForText($english, 15)->pause(300);
            $browser->assertDontSee($german);

            // More than is left: the amount in the message is formatted for the pinned locale.
            $browser->clear(self::REFUND_AMOUNT)->type(self::REFUND_AMOUNT, '50');
            $this->clickWire($browser, self::REFUND_SUBMIT);
            $browser->waitForText((string) __('billing::admin.billables.invoices.flash.exceeds_remaining', ['amount' => '€9.90'], 'en'), 15)->pause(300);
            $text = $this->opsPageText($browser);
            $this->assertStringNotContainsString('€9,90', $text, 'no German number format in the pinned admin');
            $this->assertStringNotContainsString('Der Betrag übersteigt', $text);

            // A third round-trip that re-renders the whole modal (the line select is live).
            $browser->script(<<<'JS'
                const el = document.querySelector('input[wire\\:model="refundAmount"]').closest('[wire\\:id]');
                window.Livewire.find(el.getAttribute('wire:id')).set('refundLine', '0');
            JS);
            $browser->pause(1200);
            $browser->assertSee(__('billing::admin.billables.invoices.modal.description', [], 'en'));
            $browser->assertDontSee(__('billing::admin.billables.invoices.modal.description', [], 'de'));
            $this->assertSame('en', (string) $browser->script('return document.documentElement.lang;')[0]);
            $this->assertEdgeCleanLivewire($browser, 'refund modal round-trips');
            $this->shot($browser, 'Admin bleibt Englisch');

            // The customer portal follows the app locale.
            $this->actAs($browser, $billable, '/billing/invoices');
            $browser->waitFor('[dusk="invoice-row-'.$invoice->id.'"]', 15)->pause(300);
            $this->assertSame('de', (string) $browser->script('return document.documentElement.lang;')[0]);
        });

        $this->assertSame(0, DB::table('fake_mollie_refunds')->count(), 'no refund at Mollie');
        $this->assertSame(0, BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count(), 'no credit note');
    }

    // ── 52. Admin refund on a zero-decimal currency ─────────────────────────

    public function test_a_refund_of_500_yen_is_booked_and_sent_to_mollie_as_500_yen_net(): void
    {
        $this->serverConfig('mollie-billing.currency', 'JPY');

        $billable = $this->opsSubscriber('starter', 0, [], 'DE');
        $subscriptionId = (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'];
        DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)
            ->update(['amount_value' => '1178', 'amount_currency' => 'JPY']);

        $invoice = $this->opsRenew($billable, '1178');
        $this->assertSame('JPY', strtoupper((string) $invoice->currency));
        $this->assertSame(990, (int) $invoice->amount_net, 'Starter at ¥990 net');
        $this->assertSame(1178, (int) $invoice->amount_gross);

        $this->browse(function (Browser $browser) use ($billable, $invoice): void {
            $this->loginAdmin($browser);
            $this->opsOpenBillableTab($browser, $billable, 'invoices');
            $this->clickWire($browser, '[wire\\:click="openRefund('.$invoice->id.')"]');
            $browser->waitFor(self::REFUND_AMOUNT, 10)->pause(400);

            // The example the modal gives is one the parser takes for this currency.
            $placeholder = (string) $browser->attribute(self::REFUND_AMOUNT, 'placeholder');
            $this->assertMatchesRegularExpression('/^\d+$/', $placeholder, 'a yen amount has no decimals');
            $this->assertStringContainsString($this->opsNormaliseMoney(BillingMoney::format(990, 'JPY')), $this->opsNormaliseMoney($this->opsPageText($browser)));
            $this->assertStringNotContainsString(__('billing::admin.billables.invoices.modal.description'), $this->opsPageText($browser), 'the dialog asks for decimals a yen amount does not have');
            $this->shot($browser, 'Erstattungsdialog in Yen');

            // A decimal is refused, with a hint that fits the currency.
            $browser->type(self::REFUND_AMOUNT, '500.50');
            $this->clickWire($browser, self::REFUND_SUBMIT);
            $browser->waitForText((string) __('billing::admin.billables.invoices.flash.invalid_amount_whole', ['example' => BillingMoney::number(10.5, 'JPY')]), 15)->pause(300);

            $browser->clear(self::REFUND_AMOUNT)->type(self::REFUND_AMOUNT, '500');
            $this->clickWire($browser, self::REFUND_SUBMIT);
            $browser->waitForText(__('billing::admin.billables.invoices.flash.refund_processed'), 20)->pause(400);
            $this->shot($browser, '¥500 erstattet');

            // Reopened: ¥490 left, in yen.
            $this->clickWire($browser, '[wire\\:click="openRefund('.$invoice->id.')"]');
            $browser->waitFor(self::REFUND_AMOUNT, 10)->pause(400);
            $text = $this->opsNormaliseMoney($this->opsPageText($browser));
            $this->assertStringContainsString($this->opsNormaliseMoney(BillingMoney::format(490, 'JPY')), $text);
            $this->assertStringNotContainsString($this->opsNormaliseMoney(BillingMoney::format(49000, 'JPY')), $text);

            // The customer's archive shows the credit note at its yen amount.
            $this->actAs($browser, $billable, '/billing/invoices');
            $browser->waitFor('[dusk="invoice-row-'.$invoice->id.'"]', 15)->pause(400);
            $text = $this->opsNormaliseMoney($this->opsPageText($browser));
            $this->assertStringContainsString($this->opsNormaliseMoney(BillingMoney::format(595, 'JPY')), $text);
            $this->assertStringNotContainsString($this->opsNormaliseMoney(BillingMoney::format(59500, 'JPY')), $text);
            $this->assertStringNotContainsString('5.95', $text);
        });

        $refunds = DB::table('fake_mollie_refunds')->get();
        $this->assertCount(1, $refunds, 'one refund at Mollie');
        $this->assertSame((string) $invoice->mollie_payment_id, (string) $refunds->first()->payment_id);
        $this->assertSame('595', (string) $refunds->first()->amount_value, '¥500 net + 19 % — not ¥59 500');
        $this->assertSame('JPY', (string) $refunds->first()->amount_currency);

        $creditNote = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->sole();
        $this->assertSame('JPY', strtoupper((string) $creditNote->currency));
        $this->assertSame(-500, (int) $creditNote->amount_net);
        $this->assertSame(-595, (int) $creditNote->amount_gross);
        $this->assertSame(500, $invoice->fresh()->effectiveRefundedNet());
        $this->assertSame(490, $invoice->fresh()->remainingRefundableNet());
    }

    // ── 55. Products on a free Local plan ───────────────────────────────────

    public function test_the_products_page_of_a_free_plan_offers_no_purchase_and_refuses_a_crafted_one(): void
    {
        $this->serverConfig('mollie-billing.local_subscription.allow_one_time_orders', false);

        $free = $this->couponBillableIn('free');
        $gifted = $this->couponBillableIn('gifted');
        $this->coupons()->create([
            'code' => 'PACKFREE',
            'name' => 'Token pack on the house',
            'type' => CouponType::SinglePayment,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 100,
        ]);

        $tokens = static fn (TestBillable $b): int => (int) (TestBillable::query()->findOrFail($b->getKey())->getWallet('Tokens')?->balanceInt ?? 0);
        $before = ['free' => $tokens($free), 'gifted' => $tokens($gifted)];
        $redemptionsBefore = CouponRedemption::query()->count();

        foreach (['free' => [$free, 'free_plan_no_products'], 'gifted' => [$gifted, 'access_grant_no_products']] as $state => [$billable, $notice]) {
            $this->browse(function (Browser $browser) use ($billable, $notice, $state): void {
                $this->actAs($browser, $billable, '/billing/products');
                $browser->waitFor('[dusk="product-price-token-pack-500"]', 15)->pause(400);
                $browser->assertSee(__('billing::portal.'.$notice));

                // No purchase action, no confirm dialog; every buy button is disabled.
                $this->assertSame(0, $this->domCount($browser, '[wire\\:click^="purchase("]'), "{$state}: a purchase action is on the page");
                $buttons = (array) $browser->script(sprintf(<<<'JS'
                    return [...document.querySelectorAll('button')]
                        .filter((b) => b.textContent.trim() === %s)
                        .map((b) => b.disabled);
                JS, json_encode(__('billing::portal.products.buy'))))[0];
                $this->assertNotEmpty($buttons, "{$state}: no buy button rendered");
                $this->assertSame([], array_values(array_filter($buttons, static fn ($disabled): bool => $disabled !== true)), "{$state}: an enabled buy button");
                $this->shot($browser, "Produkte gesperrt ({$state})");

                // Crafted: the action called directly, plain and with a 100 % code set on the
                // component (the inline-complete path that never reaches Mollie).
                $this->edgeRecordToasts($browser);
                $this->edgeCallOnProducts($browser, ['purchase', 'token-pack-500']);
                $browser->waitUsing(10, 100, fn (): bool => count($this->edgeToasts($browser)) >= 1, "{$state}: no toast for the crafted purchase");

                $browser->script(<<<'JS'
                    const el = document.querySelector('[dusk="product-price-token-pack-500"]').closest('[wire\\:id]');
                    const w = window.Livewire.find(el.getAttribute('wire:id'));
                    w.set('appliedCouponCodes', {'token-pack-500': ['PACKFREE']});
                JS);
                $browser->pause(800);
                $this->edgeCallOnProducts($browser, ['purchase', 'token-pack-500']);
                $browser->waitUsing(10, 100, fn (): bool => count($this->edgeToasts($browser)) >= 2, "{$state}: no toast for the crafted purchase with a full-cover code");
                $browser->pause(400);

                $toasts = $this->edgeToasts($browser);
                $this->assertCount(2, $toasts);
                foreach ($toasts as $toast) {
                    $this->assertSame('danger', $toast['variant'], "{$state}: the refusal is an error toast");
                    $this->assertNotSame(__('billing::portal.products.success'), $toast['text']);
                }
                $this->assertStringStartsWith('/billing/products', $this->edgePath($browser), "{$state}: not sent to Mollie");
                $this->shot($browser, "Gefälschter Kauf abgelehnt ({$state})");
            });
        }

        $this->assertSame(0, DB::table('fake_mollie_payments')->count(), 'no payment at Mollie');
        $this->assertSame(0, BillingInvoice::query()->count(), 'no invoice');
        $this->assertSame($before['free'], $tokens($free), 'no tokens for the free plan');
        $this->assertSame($before['gifted'], $tokens($gifted), 'no tokens for the gift');
        $this->assertSame($redemptionsBefore, CouponRedemption::query()->count(), 'the 100 % code was not redeemed');

        // The same page with one-time orders allowed offers the purchase: the gate above is the
        // setting, not a page that never sells.
        $this->serverConfig('mollie-billing.local_subscription.allow_one_time_orders', true);

        $this->browse(function (Browser $browser) use ($free): void {
            $this->actAs($browser, $free, '/billing/products');
            $browser->waitFor('[dusk="product-price-token-pack-500"]', 15)->pause(400);
            $browser->assertDontSee(__('billing::portal.free_plan_no_products'));
            $this->assertGreaterThan(0, $this->domCount($browser, '[wire\\:click="purchase(\'token-pack-500\')"]'));
        });
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function edgePath(Browser $browser): string
    {
        return (string) parse_url($browser->driver->getCurrentURL(), PHP_URL_PATH);
    }

    /** A renewal from a card in `$country` (Mollie's `countryCode`), webhook delivered. */
    private function edgeRenewFromCountry(TestBillable $billable, string $country): void
    {
        $subscriptionId = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');
        $payment = FakeMollie::chargeSubscription($subscriptionId);
        DB::table('fake_mollie_payments')->where('id', $payment['id'])->update(['country_code' => $country]);

        $this->assertSame(200, $this->deliverWebhook($payment['id']));
    }

    /** Record the status of every Livewire update the page sends from now on. */
    private function edgeRecordLivewire(Browser $browser): void
    {
        $browser->script(<<<'JS'
            window.__edgeStatuses = [];
            if (! window.__edgeFetchWrapped) {
                window.__edgeFetchWrapped = true;
                const original = window.fetch.bind(window);
                window.fetch = async (...args) => {
                    const response = await original(...args);
                    const url = String(args[0] && args[0].url ? args[0].url : args[0]);
                    if (url.includes('/update')) { window.__edgeStatuses.push(response.status); }
                    return response;
                };
            }
        JS);
    }

    /** What Livewire's error dialog shows, or null when none is open. */
    private function edgeErrorModalText(Browser $browser): ?string
    {
        $text = $browser->script(<<<'JS'
            const frame = document.querySelector('#livewire-error iframe');
            return frame && frame.contentDocument ? frame.contentDocument.body.innerText : null;
        JS)[0];

        return is_string($text) ? $text : null;
    }

    private function assertEdgeCleanLivewire(Browser $browser, string $what): void
    {
        $statuses = array_map('intval', (array) $browser->script('return window.__edgeStatuses || [];')[0]);

        $this->assertNotEmpty($statuses, "{$what}: no Livewire request was recorded");
        $this->assertSame([], array_values(array_filter($statuses, static fn (int $s): bool => $s !== 200)), "{$what}: a Livewire request failed");
        $this->assertNull($this->edgeErrorModalText($browser), "{$what}: Livewire showed an error");
    }

    private function edgeRecordToasts(Browser $browser): void
    {
        $browser->script(<<<'JS'
            window.__edgeToasts = [];
            if (! window.__edgeToastsHooked) {
                window.__edgeToastsHooked = true;
                document.addEventListener('toast-show', (e) => {
                    const d = Array.isArray(e.detail) ? e.detail[0] : e.detail;
                    window.__edgeToasts.push({ text: d?.slots?.text ?? null, variant: d?.dataset?.variant ?? null });
                });
            }
        JS);
    }

    /** @return list<array{text: ?string, variant: ?string}> */
    private function edgeToasts(Browser $browser): array
    {
        return (array) $browser->script('return window.__edgeToasts || [];')[0];
    }

    /** @param list<mixed> $call method name, then its arguments */
    private function edgeCallOnProducts(Browser $browser, array $call): void
    {
        $browser->script(sprintf(<<<'JS'
            const el = document.querySelector('[dusk="product-price-token-pack-500"]').closest('[wire\\:id]');
            const w = window.Livewire.find(el.getAttribute('wire:id'));
            w.call(...%s);
        JS, json_encode($call)));
    }
}
