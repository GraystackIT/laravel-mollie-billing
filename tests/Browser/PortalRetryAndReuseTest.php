<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Browser\Matrix\CouponWorkbench;
use GraystackIT\MollieBilling\Tests\Browser\Matrix\OperationsWorkbench;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Workbench\App\FakeMollie\FakeMollie;

/**
 * A second attempt after the first one did not go through, and the first payment that is
 * still there when the customer comes back.
 *
 *  - 44: a declined plan-change charge, then the same upgrade again, paid: the plan page says
 *    "plan changed" and nothing about the failed payment, and the header shows the new plan.
 *    The same on the addons page: a declined addon charge, then enabled and paid, says
 *    "enabled". (The outcome markers describe the latest attempt — docs/decisions.md, "An
 *    accepted change supersedes the last one's outcome".)
 *  - 45: a free-plan customer confirms the upgrade, goes Back from Mollie and confirms again:
 *    the same payment, one payment at Mollie, one invoice after paying.
 *  - 46: a gifted tenant enables a paid addon, goes Back from Mollie and enables it again: one
 *    first payment at Mollie.
 *  - 47: an unfinished change on record (`plan_change_unfinished`, payment `paid`): the warning
 *    in English and German; a new change completed afterwards removes it and says "plan
 *    changed".
 *
 * Every webhook is delivered from this process, against the shared world; the open page sees
 * the outcome through its own poll.
 *
 * Workbench catalog, 19 % VAT: Starter 9.90 → 11.78 gross, Business 99.00 → 117.81 gross,
 * Softdrinks 4.90.
 */
class PortalRetryAndReuseTest extends BrowserTestCase
{
    use CouponWorkbench;
    use OperationsWorkbench;

    private const PREVIEW_BUSINESS = '[wire\\:click="previewFor(\'business\')"]';

    private const PREVIEW_PRO = '[wire\\:click="previewFor(\'pro\')"]';

    private const APPLY = '[x-on\\:click*="applyChange"]';

    private const CANCEL_PENDING = '[wire\\:click="cancelPendingChange"]';

    private const UNFINISHED = '[dusk="plan-change-unfinished"]';

    private const CONFIRM_AND_PAY = '[wire\\:click="confirmAndPay"]';

    private const ENABLE_SOFTDRINKS = '[wire\\:click="enableAddon(\'softdrinks\')"]';

    private const ADDON_POLL = '[wire\\:poll\\.3s="pollForChange"]';

    // ── 44. Plan page: declined, then the same upgrade paid ─────────────────

    public function test_an_upgrade_paid_after_a_declined_one_reads_plan_changed_without_the_failure(): void
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/plan');

            // First attempt: the charge is declined.
            $declined = $this->startUpgradeToBusiness($browser, $billable);
            FakeMollie::settle($declined, 'failed', 'DE');
            $this->assertSame(200, $this->deliverWebhook($declined));

            $browser->waitForText(__('billing::portal.flash.plan_change_failed'), 20);
            $this->waitForAbsence($browser, self::CANCEL_PENDING, 15);
            $this->assertContains(['text' => __('billing::portal.flash.plan_change_failed'), 'variant' => 'danger'], $this->toastsSeen($browser));
            $this->assertSame('Starter', $this->headerPlan($browser));
            $this->assertNotNull($billable->fresh()->getBillingSubscriptionMeta()['plan_change_failed_at'] ?? null);
            $this->shot($browser, 'Erster Versuch abgelehnt');

            // Second attempt, as the page asks: the same upgrade, and it is paid.
            $paid = $this->startUpgradeToBusiness($browser, $billable);
            $this->assertNotSame($declined, $paid);

            // Accepted: the earlier decline no longer stands beside the change.
            $this->assertArrayNotHasKey('plan_change_failed_at', $billable->fresh()->getBillingSubscriptionMeta());

            FakeMollie::settle($paid, 'paid', 'DE');
            $this->assertSame(200, $this->deliverWebhook($paid));

            $browser->waitUsing(20, 200, fn (): bool => $this->headerPlan($browser) === 'Business', 'the header never showed Business');
            $this->waitForAbsence($browser, self::CANCEL_PENDING, 15);
            $browser->pause(600);
            $this->shot($browser, 'Zweiter Versuch bezahlt');

            $this->assertSame(
                [['text' => __('billing::portal.flash.plan_changed'), 'variant' => 'success']],
                $this->toastsSeen($browser),
                'the retry is announced as changed, and only that',
            );
            $browser->assertDontSee(__('billing::portal.flash.plan_change_failed'));

            // A reload says the same.
            $browser->refresh()->waitFor(self::PREVIEW_PRO, 15)->pause(400);
            $browser->assertDontSee(__('billing::portal.flash.plan_change_failed'));
            $this->assertSame('Business', $this->headerPlan($browser));
        });

        $billable->refresh();
        $meta = $billable->getBillingSubscriptionMeta();

        $this->assertSame('business', $billable->getBillingSubscriptionPlanCode());
        foreach (['plan_change_failed_at', 'plan_change_failed_reason', 'pending_plan_change', 'pending_prorata_change', 'prorata_pending_payment_id'] as $key) {
            $this->assertArrayNotHasKey($key, $meta);
        }
        $this->assertSame('117.81', $this->mollieSubscriptionAmount($billable));
        $charges = DB::table('fake_mollie_payments')->where('sequence_type', 'recurring')->orderBy('created_at')->pluck('status')->all();
        $this->assertSame(['failed', 'paid'], $charges, 'two charges, the first declined');
        $this->assertSame(1, BillingInvoice::query()->where('billable_id', $billable->getKey())->count(), 'one invoice, for the paid charge');
    }

    // ── 44. Addons page: declined, then enabled and paid ────────────────────

    public function test_an_addon_enabled_after_a_declined_charge_reads_enabled(): void
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly');
        $enabled = __('billing::portal.addons_flash.enabled', ['addon' => 'Softdrinks']);

        $this->browse(function (Browser $browser) use ($billable, $enabled): void {
            $this->actAs($browser, $billable, '/billing/addons');

            $declined = $this->enableSoftdrinksAwaitingPayment($browser, $billable);
            FakeMollie::settle($declined, 'failed', 'DE');
            $this->assertSame(200, $this->deliverWebhook($declined));

            $browser->waitForText(__('billing::portal.flash.plan_change_failed'), 20)->pause(400);
            $this->assertSame(
                [['text' => __('billing::portal.flash.plan_change_failed'), 'variant' => 'danger']],
                $this->toastsSeen($browser),
            );
            $this->assertNotContains('softdrinks', $billable->fresh()->getActiveBillingAddonCodes(), 'the unpaid addon is taken back');
            $this->shot($browser, 'Add-on: Zahlung abgelehnt');

            // Enabled again — and this time paid.
            $browser->refresh();
            $paid = $this->enableSoftdrinksAwaitingPayment($browser, $billable);
            FakeMollie::settle($paid, 'paid', 'DE');
            $this->assertSame(200, $this->deliverWebhook($paid));

            $browser->waitForText($enabled, 20)->pause(600);
            $this->waitForAbsence($browser, self::ADDON_POLL, 15);
            $this->shot($browser, 'Add-on: zweiter Versuch bezahlt');

            $this->assertSame([['text' => $enabled, 'variant' => 'success']], $this->toastsSeen($browser));
            $browser->assertDontSee(__('billing::portal.flash.plan_change_failed'));
        });

        $billable->refresh();
        $this->assertContains('softdrinks', $billable->getActiveBillingAddonCodes());
        $this->assertArrayNotHasKey('plan_change_failed_at', $billable->getBillingSubscriptionMeta());
        $this->assertArrayNotHasKey('pending_prorata_change', $billable->getBillingSubscriptionMeta());
        // Starter 9.90 + Softdrinks 4.90 = 14.80 net, 17.61 gross.
        $this->assertSame('17.61', $this->mollieSubscriptionAmount($billable));
        $this->assertSame(1, BillingInvoice::query()->where('billable_id', $billable->getKey())->count());
    }

    // ── 45. Free plan → paid, Back from Mollie, confirm again ───────────────

    public function test_a_free_plan_upgrade_confirmed_again_after_back_from_mollie_reuses_its_payment(): void
    {
        $billable = $this->couponBillableIn('free');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/plan');

            $this->confirmLocalUpgradeToPro($browser);
            $this->waitForPathContains($browser, '/fake-mollie/checkout/');
            $first = $browser->driver->getCurrentURL();
            $this->assertSame(1, DB::table('fake_mollie_payments')->count());
            $this->shot($browser, 'Mollie: erste Zahlung');

            // Back from the payment page, and confirm again.
            $browser->back();
            $this->waitForPathContains($browser, '/billing/plan');
            $browser->pause(600);
            $this->confirmLocalUpgradeToPro($browser);
            $this->waitForPathContains($browser, '/fake-mollie/checkout/');

            $this->assertSame($first, $browser->driver->getCurrentURL(), 'the same payment page');
            $this->assertSame(1, DB::table('fake_mollie_payments')->count(), 'one payment at Mollie');
            $this->shot($browser, 'Mollie: dieselbe Zahlung');

            $this->settleAtFakeMollie($browser, 'paid');
            $browser->waitForText(__('billing::portal.return.title'), 20)->pause(400);
            $browser->assertSee(__('billing::portal.return.to_dashboard'));
            $this->shot($browser, 'Upgrade bezahlt');

            $browser->clickLink(__('billing::portal.return.to_dashboard'));
            $browser->waitForText('Pro', 15);
        });

        $billable->refresh();
        $payment = DB::table('fake_mollie_payments')->sole();

        $this->assertSame('pro', $billable->getBillingSubscriptionPlanCode());
        $this->assertSame(SubscriptionSource::Mollie, $billable->subscription_source);
        $this->assertSame('paid', (string) $payment->status);
        $this->assertSame(1, BillingInvoice::query()->where('billable_id', $billable->getKey())->count(), 'exactly one invoice');
        $this->assertSame((string) $payment->id, (string) BillingInvoice::query()->where('billable_id', $billable->getKey())->value('mollie_payment_id'));
        $this->assertSame(1, DB::table('fake_mollie_subscriptions')->whereNull('canceled_at')->count(), 'one subscription');
    }

    // ── 46. Gift: paid addon, Back from Mollie, enable again ────────────────

    public function test_a_gifted_tenant_enabling_a_paid_addon_again_after_back_from_mollie_reuses_its_payment(): void
    {
        $billable = $this->couponBillableIn('gifted');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/addons');
            $this->waitForPresence($browser, self::ENABLE_SOFTDRINKS, 15);
            $browser->pause(400);
            $this->confirmInModal($browser, 'enable-addon-softdrinks', self::ENABLE_SOFTDRINKS);
            $this->waitForPathContains($browser, '/fake-mollie/checkout/');
            $first = $browser->driver->getCurrentURL();
            $this->assertSame('first', $this->latestFakePayment()['sequence_type']);
            $this->shot($browser, 'Geschenk: Add-on bei Mollie');

            $browser->back();
            $this->waitForPathContains($browser, '/billing/addons');
            $this->waitForPresence($browser, self::ENABLE_SOFTDRINKS, 15);
            $browser->pause(600);
            $this->confirmInModal($browser, 'enable-addon-softdrinks', self::ENABLE_SOFTDRINKS);
            $this->waitForPathContains($browser, '/fake-mollie/checkout/');

            $this->assertSame($first, $browser->driver->getCurrentURL(), 'the same payment page');
            $this->assertSame(1, DB::table('fake_mollie_payments')->count(), 'one first payment at Mollie');

            $this->settleAtFakeMollie($browser, 'paid');
            $browser->waitForText(__('billing::portal.return.title'), 20);
            $this->shot($browser, 'Geschenk: Add-on bezahlt');
        });

        $billable->refresh();

        $this->assertSame(1, DB::table('fake_mollie_payments')->count());
        $this->assertSame('paid', (string) DB::table('fake_mollie_payments')->value('status'));
        $this->assertContains('softdrinks', $billable->getActiveBillingAddonCodes());
        $this->assertSame('pro', $billable->getBillingSubscriptionPlanCode(), 'the gift is kept');
        $this->assertSame(1, BillingInvoice::query()->where('billable_id', $billable->getKey())->count(), 'one invoice');
        $this->assertSame(1, DB::table('fake_mollie_subscriptions')->whereNull('canceled_at')->count(), 'one subscription');
    }

    // ── 47. An unfinished change on record, then a new one ──────────────────

    public function test_the_unfinished_change_warning_reads_in_both_languages_and_goes_with_the_next_completed_change(): void
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly');
        $this->seedMeta($billable, function (array $meta): array {
            $meta['plan_change_unfinished'] = ['payment' => 'paid', 'at' => now('UTC')->subHours(2)->toIso8601String()];

            return $meta;
        });

        $english = 'Your payment for the plan change has been received, but the change could not be completed automatically. We are taking care of it and will get back to you — please do not start the change again.';
        $german = 'Deine Zahlung für die Planänderung ist eingegangen, die Änderung konnte aber nicht automatisch abgeschlossen werden. Wir kümmern uns darum und melden uns – bitte starte die Änderung nicht erneut.';

        $this->browse(function (Browser $browser) use ($billable, $english, $german): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitFor(self::UNFINISHED, 15)->pause(400);
            $browser->assertSeeIn(self::UNFINISHED, $english);
            $browser->assertDontSee(__('billing::portal.flash.plan_change_failed'));
            $this->assertSame(1, $this->domCount($browser, self::UNFINISHED));
            $this->shot($browser, 'Unfinished: English');

            $this->serverConfig('app.locale', 'de');
            app()->setLocale('de');

            $browser->refresh()->waitFor(self::UNFINISHED, 15)->pause(400);
            $browser->assertSeeIn(self::UNFINISHED, $german);
            $browser->assertDontSee($english);
            $this->shot($browser, 'Unfinished: Deutsch');

            // A new change, completed.
            $paymentId = $this->startUpgradeToBusiness($browser, $billable);
            $this->waitForAbsence($browser, self::UNFINISHED, 15);
            FakeMollie::settle($paymentId, 'paid', 'DE');
            $this->assertSame(200, $this->deliverWebhook($paymentId));

            $browser->waitUsing(20, 200, fn (): bool => $this->headerPlan($browser, 'Aktueller Plan') === 'Business', 'the header never showed Business');
            $this->waitForAbsence($browser, self::CANCEL_PENDING, 15);
            $browser->pause(600);
            $this->shot($browser, 'Neuer Wechsel abgeschlossen');

            $this->assertSame([['text' => 'Plan geändert.', 'variant' => 'success']], $this->toastsSeen($browser));
            $this->assertSame(0, $this->domCount($browser, self::UNFINISHED));
            $browser->assertDontSee($german);

            $browser->refresh()->waitFor(self::PREVIEW_PRO, 15)->pause(400);
            $this->assertSame(0, $this->domCount($browser, self::UNFINISHED), 'gone after a reload');
        });

        $billable->refresh();
        $this->assertSame('business', $billable->getBillingSubscriptionPlanCode());
        $this->assertArrayNotHasKey('plan_change_unfinished', $billable->getBillingSubscriptionMeta());
        $this->assertSame('117.81', $this->mollieSubscriptionAmount($billable));
        $this->assertSame(1, BillingInvoice::query()->where('billable_id', $billable->getKey())->count());
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * On the plan page: preview Business and apply it now, with the charge left open at
     * Mollie. Starts recording the page. Returns the charge's payment id.
     */
    private function startUpgradeToBusiness(Browser $browser, TestBillable $billable): string
    {
        $this->setRecurringOutcome('open');

        $browser->waitFor(self::PREVIEW_BUSINESS, 15)->pause(400);
        $this->recordPage($browser);
        $this->clickWire($browser, self::PREVIEW_BUSINESS);
        $browser->waitFor(self::APPLY, 15)->pause(400);
        $this->clickWire($browser, self::APPLY);

        $browser->waitUsing(15, 200, fn (): bool => $this->pendingPaymentId($billable) !== '', 'the upgrade never started waiting for its payment');
        $this->waitForPresence($browser, self::CANCEL_PENDING, 15);
        $browser->pause(400);

        $this->setRecurringOutcome('paid');

        $paymentId = $this->pendingPaymentId($billable);
        $this->assertSame('open', FakeMollie::payment($paymentId)['status']);
        $this->assertSame('business', $billable->fresh()->getBillingSubscriptionMeta()['pending_plan_change']['plan_code'] ?? null);

        return $paymentId;
    }

    /** On the addons page: switch Softdrinks on with its charge left open. Returns the id. */
    private function enableSoftdrinksAwaitingPayment(Browser $browser, TestBillable $billable): string
    {
        $this->setRecurringOutcome('open');

        $this->waitForPresence($browser, self::ENABLE_SOFTDRINKS, 15);
        $browser->pause(400);
        $this->recordPage($browser);
        $this->confirmInModal($browser, 'enable-addon-softdrinks', self::ENABLE_SOFTDRINKS);
        $browser->waitUsing(15, 200, fn (): bool => $this->pendingPaymentId($billable) !== '', 'the addon charge never started waiting');
        $this->waitForPresence($browser, self::ADDON_POLL, 15);
        $browser->pause(400);

        $this->setRecurringOutcome('paid');

        return $this->pendingPaymentId($billable);
    }

    /**
     * On the plan page of a free-plan customer: Pro, Apply, and "Confirm and pay" in the
     * confirmation. After Back from Mollie the page is whatever the browser restores — a
     * fresh plan page, or the confirmation it left — so both are handled.
     */
    private function confirmLocalUpgradeToPro(Browser $browser): void
    {
        $browser->waitUsing(15, 200, fn (): bool => $this->domCount($browser, self::PREVIEW_PRO) > 0
            || $this->domCount($browser, self::CONFIRM_AND_PAY) > 0, 'the plan page never loaded');

        if ($this->domCount($browser, '[dusk="upgrade-total"]') === 0) {
            $browser->waitFor(self::PREVIEW_PRO, 15)->pause(400);
            $this->clickWire($browser, self::PREVIEW_PRO);
            $browser->waitFor(self::APPLY, 15)->pause(400);
            $this->clickWire($browser, self::APPLY);
            $this->waitForPresence($browser, '[dusk="upgrade-total"]', 15);
            $browser->pause(400);
        }

        // Inside a dialog WebDriver does not consider interactable: click it in the page.
        $browser->script('document.querySelector(\'[wire\\\\:click="confirmAndPay"]\').click()');
    }

    private function pendingPaymentId(TestBillable $billable): string
    {
        return (string) ($billable->fresh()->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'] ?? '');
    }

    /** @param \Closure(array<string, mixed>): array<string, mixed> $change */
    private function seedMeta(TestBillable $billable, \Closure $change): void
    {
        $fresh = $billable->fresh();
        $fresh->forceFill(['subscription_meta' => $change($fresh->getBillingSubscriptionMeta())])->save();
    }

    /** Capture, from now on, every toast the page dispatches (Flux's `toast-show`). */
    private function recordPage(Browser $browser): void
    {
        $browser->script(<<<'JS'
            window.__wbToasts = [];
            if (! window.__wbToastsHooked) {
                window.__wbToastsHooked = true;
                document.addEventListener('toast-show', (e) => {
                    const d = Array.isArray(e.detail) ? e.detail[0] : e.detail;
                    window.__wbToasts.push({ text: d?.slots?.text ?? null, variant: d?.dataset?.variant ?? null });
                });
            }
        JS);
    }

    /** @return list<array{text: ?string, variant: ?string}> */
    private function toastsSeen(Browser $browser): array
    {
        return (array) $browser->script('return window.__wbToasts || [];')[0];
    }

    /** The plan named in the page header's "current plan" card. */
    private function headerPlan(Browser $browser, ?string $label = null): ?string
    {
        return $browser->script(sprintf(<<<'JS'
            const sub = [...document.querySelectorAll('[data-flux-subheading]')].find((e) => e.textContent.trim() === %s);
            const heading = sub ? sub.parentElement.querySelector('[data-flux-heading]') : null;
            return heading ? heading.textContent.trim() : null;
        JS, json_encode($label ?? __('billing::portal.current_plan'))))[0];
    }
}
