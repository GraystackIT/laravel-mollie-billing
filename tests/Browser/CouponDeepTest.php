<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\NextChargeSummary;
use GraystackIT\MollieBilling\Services\Billing\PlanWaiver;
use GraystackIT\MollieBilling\Support\BillingAuditEntry;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Browser\Matrix\CouponWorkbench;
use GraystackIT\MollieBilling\Tests\Browser\Matrix\OperationsWorkbench;
use GraystackIT\MollieBilling\Tests\Support\PdfText;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Dusk\Browser;

/**
 * A code or a gift whose circumstances change after it was given — driven end to end.
 *
 * The rules are the ones decided in docs/decisions.md, "Coupons and gifts review round
 * (26 November 2026)":
 *
 *  - the grants of a subscription end with it: a gift that ran out is revoked as a record
 *    only and takes nothing from the free plan the tenant took afterwards;
 *  - revoking finished free plan periods leaves a later, separately given waiver alone;
 *  - the checkout is the gate for a campaign's own state: a code whose last slot was taken
 *    while this customer's payment travelled is honoured for the payment and its periods;
 *  - a code switched off while the payment travelled is honoured for that payment, and the
 *    Mollie subscription is created without the recurring discount.
 *
 * Starter 9.90, Pro 29.00, Softdrinks 4.90; German consumer, 19 %.
 * A 20 % recurring code on Starter: 9.90 − 1.98 = 7.92 net → 1.50 VAT → 9.42 gross.
 */
class CouponDeepTest extends BrowserTestCase
{
    use CouponWorkbench;
    use OperationsWorkbench;

    private const ADDRESS = [
        'billing_street' => 'Musterweg 12',
        'billing_postal_code' => '10115',
        'billing_city' => 'Berlin',
        'billing_country' => 'DE',
        'tax_country_user' => 'DE',
    ];

    // ── Gap 7: a gift that ran out is revoked after the tenant took the free plan ──

    public function test_revoking_a_gift_that_ran_out_leaves_the_free_plan_taken_afterwards(): void
    {
        $this->travelSharedClockTo('2027-01-10 10:00:00');
        $this->serverConfig('mollie-billing-plans.plans.changes-free.hidden', false);

        $billable = $this->makeBillable(self::ADDRESS);
        // A one-off gift code (one redemption), as an operator hands it to one tenant.
        $this->coupons()->accessGrantCoupon('GIFT-RANOUT', 'pro', 'monthly', [], 30, null, 1);

        // ── The tenant redeems the gift on the dashboard ──────────────────────
        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing');
            $this->redeemOnDashboard($browser, 'GIFT-RANOUT');

            $browser->waitForText(__('billing::portal.coupon_grant_activated', [
                'code' => 'GIFT-RANOUT',
                'plan' => 'Pro',
                'until' => (string) BillingTime::display(now()->addDays(30), $billable)?->translatedFormat('d. M Y'),
            ]), 15);
            $this->shot($browser, 'Geschenk eingelöst: Pro für 30 Tage');
        });

        $billable->refresh();
        $gift = CouponRedemption::query()->sole();

        $this->assertSame(SubscriptionSource::Local, $billable->subscription_source);
        $this->assertSame(SubscriptionStatus::Active, $billable->subscription_status);
        $this->assertSame('pro', $billable->subscription_plan_code);
        $this->assertSame('2027-02-09', $billable->subscription_ends_at?->toDateString());

        // ── Past the gift's end: the nightly pass expires it and ends its grants ──
        $this->travelSharedClockTo('2027-02-10 01:00:00');
        PrepareUsageOverageJob::dispatchSync();

        $billable->refresh();
        $this->assertSame(SubscriptionStatus::Expired, $billable->subscription_status, 'the gift ran out');
        $this->assertNotNull(
            ((array) $gift->fresh()->grant_applied_snapshot)['subscription_ended_at'] ?? null,
            'the nightly pass marks the gift as ended with its subscription',
        );

        // ── The tenant checks out the free plan ───────────────────────────────
        $this->browse(function (Browser $browser) use ($billable): void {
            $this->walkCheckoutToConfirm($browser, $billable, '/billing/checkout?plan=changes-free&interval=monthly');
            $this->assertShownAmount($browser, 'confirm-total', 0, 'the free plan costs nothing');
            $this->shot($browser, 'Free-Plan nach dem Geschenk');

            // Nothing to pay: no trip to Mollie.
            $this->clickWire($browser, '[wire\\:click="submit"]');
            $this->waitForPathContains($browser, '/billing', 20);
            $browser->pause(800);
        });

        $billable->refresh();
        $this->assertSame('changes-free', $billable->subscription_plan_code);
        $this->assertSame(SubscriptionStatus::Active, $billable->subscription_status);
        $this->assertSame(SubscriptionSource::Local, $billable->subscription_source);
        $this->assertNull($billable->subscription_ends_at);

        // ── The operator revokes the old gift row, with a reason ──────────────
        $this->browse(function (Browser $browser) use ($billable, $gift): void {
            $this->loginAdmin($browser);
            $this->openSubscriptionTab($browser, $billable);

            // The ended gift is still listed with a revoke button — that is the row in question.
            $browser->waitFor('[dusk="grant-row-'.$gift->id.'"]', 10)
                ->assertSeeIn('[dusk="grant-row-'.$gift->id.'"]', 'GIFT-RANOUT');
            $this->assertTabShowsFreePlan($browser);
            $this->shot($browser, 'Abo-Tab: Free-Plan, altes Geschenk gelistet');

            $this->clickWire($browser, '[wire\\:click="openRevoke('.$gift->id.')"]');
            $browser->waitFor('textarea[name="revokeNote"]', 10)->pause(400)
                ->type('revokeNote', 'Deep: gift withdrawn after it ran out');
            $this->clickWire($browser, 'form[wire\\:submit="revokeGrant"] button[type="submit"]');
            $browser->waitForText(__('billing::admin.billables.subscription.flash.grant_revoked', [
                'reason' => __('billing::admin.billables.subscription.revoke_reason.admin_revoke'),
            ]), 15)->pause(500);
            $this->shot($browser, 'Altes Geschenk widerrufen');

            // The row is gone from the list, the free plan is still what the tab shows.
            $this->assertSame(0, $this->domCount($browser, '[dusk="grant-row-'.$gift->id.'"]'), 'the revoked row is gone');
            $this->assertSame(0, $this->domCount($browser, '[wire\\:click="openRevoke('.$gift->id.')"]'));
            $browser->assertSee(__('billing::admin.billables.subscription.grants_empty_title'));
            $this->assertTabShowsFreePlan($browser);

            // Reloaded from scratch, not just the component's state after the action.
            $this->openSubscriptionTab($browser, $billable);
            $this->assertTabShowsFreePlan($browser);
            $this->assertSame(0, $this->domCount($browser, '[dusk="grant-row-'.$gift->id.'"]'));
        });

        // ── The tenant's portal and the app area behind the subscription gate ──
        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing');
            $browser->waitForText('Free', 15)->pause(500);

            $shown = $this->nextChargeOnScreen($browser);
            $this->assertSame(NextChargeSummary::STATE_FREE, $shown['state'], 'the portal shows the free plan as running');
            $this->assertStringContainsString(__('billing::portal.next_charge.free'), $shown['text']);
            $this->assertStringNotContainsString('/billing/checkout', (string) parse_url($browser->driver->getCurrentURL(), PHP_URL_PATH));
            $this->shot($browser, 'Portal: Free-Plan läuft weiter');

            $browser->visit('/_wb/app')->waitForText('APP-AREA-OK', 10);
        });

        $billable->refresh();
        $gift->refresh();

        $this->assertSame(SubscriptionStatus::Active, $billable->subscription_status, 'the free plan is not wiped to Expired');
        $this->assertSame(SubscriptionSource::Local, $billable->subscription_source);
        $this->assertSame('changes-free', $billable->subscription_plan_code);
        $this->assertNull($billable->subscription_ends_at, 'no end date given to the free plan');
        $this->assertTrue($billable->hasAccessibleBillingSubscription());
        $this->assertTrue($gift->isRevoked());
        $this->assertSame('admin_revoke: Deep: gift withdrawn after it ran out', $gift->revoked_reason);
        $this->assertFalse($gift->coupon->fresh()->active, 'a one-off grant code is not put back on offer');
        $this->assertSame(1, BillingAuditEntry::model()::query()->where('event', 'grant_revoked')->count());
        $this->assertSame(0, DB::table('fake_mollie_payments')->count(), 'nothing of this ever went to Mollie');
    }

    // ── Gap 8: finished free plan periods revoked while a later waiver runs ──────

    public function test_revoking_finished_free_periods_leaves_the_later_waiver_and_its_mollie_amount(): void
    {
        $this->travelSharedClockTo('2027-01-10 10:00:00');

        // Pro + Softdrinks: the plan can be waived while the addon stays paid, so a waived
        // renewal is a real charge (4.90 + 19 % = 5.83) and not a deferral.
        $billable = $this->makeActiveSubscriber('pro', 'monthly', ['active_addon_codes' => ['softdrinks']]);
        $chargeDay = Carbon::instance($billable->nextBillingDate());
        DB::table('fake_mollie_subscriptions')
            ->where('id', $billable->getBillingSubscriptionMeta()['mollie_subscription_id'])
            ->update(['amount_value' => '40.34', 'next_payment_date' => $chargeDay->toDateString()]);

        // ── PLANFREE-A: one free period, given by the operator ────────────────
        $this->browse(function (Browser $browser) use ($billable): void {
            $this->loginAdmin($browser);
            $this->openSubscriptionTab($browser, $billable);
            $this->giveFreePeriods($browser, 1);
            $this->shot($browser, 'Ein Freimonat gegeben');
            $this->assertNextChargeMatchesBooks($browser, $billable, 'after one free period');
        });

        $first = CouponRedemption::query()->sole();
        $this->assertSame('5.83', $this->mollieSubscriptionAmount($billable), 'the plan is waived, the addon stays paid');

        // ── The waived renewal arrives ───────────────────────────────────────
        $this->travelSharedClockTo($chargeDay->copy()->setTime(9, 5));
        $this->renewSubscription($billable->fresh());

        $waived = BillingInvoice::query()->latest('id')->first();
        $this->assertSame([490, 583], [(int) $waived->amount_net, (int) $waived->amount_gross], 'the waived renewal is the addon only');
        $this->assertSame(-2900, (int) collect($waived->line_items)->where('kind', 'coupon')->sum('total_net'), 'the plan is shown and taken off');
        $this->assertNull(collect($waived->line_items)->firstWhere('kind', 'adjustment'));

        // A's one period is used up: the marker is gone and Mollie is back on the full price.
        $billable->refresh();
        $this->assertNull(app(PlanWaiver::class)->marker($billable), 'the first waiver ran out at its renewal');
        $this->assertSame('40.34', $this->mollieSubscriptionAmount($billable));

        // ── A few days later: PLANFREE-B, three free periods ─────────────────
        $this->travelSharedClockTo($chargeDay->copy()->addDays(5)->setTime(10, 0));

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->loginAdmin($browser);
            $this->openSubscriptionTab($browser, $billable);
            $this->giveFreePeriods($browser, 3);
            $this->shot($browser, 'Drei Freimonate gegeben');
        });

        $second = CouponRedemption::query()
            ->whereKeyNot($first->id)
            ->get()
            ->first(fn (CouponRedemption $r): bool => (((array) $r->grant_applied_snapshot)['mode'] ?? null) === 'plan_waiver');
        $this->assertNotNull($second, 'the second waiver is a redemption of its own');

        $billable->refresh();
        $waiverBefore = app(PlanWaiver::class)->marker($billable);
        $this->assertSame((int) $second->coupon_id, (int) ($waiverBefore['coupon_id'] ?? 0), 'B holds the slot');
        $this->assertSame('5.83', $this->mollieSubscriptionAmount($billable));
        $mollieRowBefore = (array) DB::table('fake_mollie_subscriptions')
            ->where('id', $billable->getBillingSubscriptionMeta()['mollie_subscription_id'])->first();

        // ── The operator revokes A's finished row ────────────────────────────
        $this->browse(function (Browser $browser) use ($billable, $first, $second): void {
            $this->loginAdmin($browser);
            $this->openSubscriptionTab($browser, $billable);

            $browser->waitFor('[dusk="grant-row-'.$first->id.'"]', 10);
            $this->assertSame(1, $this->domCount($browser, '[dusk="grant-row-'.$second->id.'"]'), 'both waivers are listed');

            $this->clickWire($browser, '[wire\\:click="openRevoke('.$first->id.')"]');
            $browser->waitFor('textarea[name="revokeNote"]', 10)->pause(400)
                ->type('revokeNote', 'Deep: first free month given by mistake');
            $this->clickWire($browser, 'form[wire\\:submit="revokeGrant"] button[type="submit"]');
            $browser->waitForText(__('billing::admin.billables.subscription.flash.grant_revoked', [
                'reason' => __('billing::admin.billables.subscription.revoke_reason.admin_revoke'),
            ]), 15)->pause(500);
            $this->shot($browser, 'Erster Freimonat widerrufen, zweiter bleibt');

            $this->assertSame(0, $this->domCount($browser, '[dusk="grant-row-'.$first->id.'"]'), 'A is gone from the list');
            $this->assertSame(1, $this->domCount($browser, '[dusk="grant-row-'.$second->id.'"]'), 'B is still listed');
            $this->assertSame(1, $this->domCount($browser, '[wire\\:click="openRevoke('.$second->id.')"]'), 'B can still be revoked');

            $this->assertPlanStillFree($browser, $billable, 'tab after revoking A');
        });

        // ── The customer's portal ────────────────────────────────────────────
        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing');
            $browser->waitForText('Pro', 15)->pause(500);
            $this->assertPlanStillFree($browser, $billable, 'portal after revoking A');
            $this->shot($browser, 'Portal: nächste Abbuchung nur Softdrinks');
        });

        $billable->refresh();

        $this->assertTrue($first->fresh()->isRevoked());
        $this->assertFalse($second->fresh()->isRevoked(), 'B is not touched');
        $this->assertSame($waiverBefore, app(PlanWaiver::class)->marker($billable), "B's waiver is exactly as it was");
        $this->assertSame('5.83', $this->mollieSubscriptionAmount($billable), 'Mollie still collects the waived amount');
        $this->assertSame(
            $mollieRowBefore,
            (array) DB::table('fake_mollie_subscriptions')->where('id', $billable->getBillingSubscriptionMeta()['mollie_subscription_id'])->first(),
            'the revoke sent nothing to Mollie',
        );
        $this->assertSame(SubscriptionStatus::Active, $billable->subscription_status);

        // And the next renewal is still waived by B.
        $this->travelSharedClockTo(Carbon::instance($billable->nextBillingDate())->setTime(9, 5));
        $this->renewSubscription($billable->fresh());
        $next = BillingInvoice::query()->latest('id')->first();

        $this->assertSame(583, (int) $next->amount_gross, 'the renewal after the revoke is still the addon only');
        $this->assertSame(-2900, (int) collect($next->line_items)->where('kind', 'coupon')->sum('total_net'));
    }

    // ── Gap 9: the campaign's last slot is taken while A's payment travels ───────

    public function test_a_code_whose_last_slot_was_taken_while_the_payment_travelled_is_honoured(): void
    {
        $coupon = $this->coupons()->create([
            'code' => 'LASTSLOT',
            'name' => 'Twenty percent, one customer only',
            'type' => CouponType::Recurring,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 20,
            'discount_periods' => 3,
            'max_redemptions' => 1,
        ]);

        $a = $this->makeBillable(self::ADDRESS + ['name' => 'Customer A GmbH']);
        $b = $this->makeBillable(self::ADDRESS + ['name' => 'Customer B GmbH']);
        $c = $this->makeBillable(self::ADDRESS + ['name' => 'Customer C GmbH']);

        $this->browse(function (Browser $first, Browser $second) use ($a, $b, $c): void {
            // A applies the code and goes to Mollie — and leaves the page open.
            $this->walkCheckoutToConfirm($first, $a, '/billing/checkout?plan=starter&interval=monthly');
            $this->applyCheckoutCode($first, 'LASTSLOT');
            $this->assertShownAmount($first, 'confirm-total', 942, 'A agrees to 9.42');
            $this->clickWire($first, '[wire\\:click="submit"]');
            $this->waitForPathContains($first, '/fake-mollie/checkout/');
            $first->waitFor('@fake-mollie-pay', 10);
            $this->shot($first, 'A: Mollie-Seite offen');

            // B takes the code's only slot and pays.
            $this->walkCheckoutToConfirm($second, $b, '/billing/checkout?plan=starter&interval=monthly');
            $this->applyCheckoutCode($second, 'LASTSLOT');
            $this->assertShownAmount($second, 'confirm-total', 942, 'B agrees to 9.42');
            $this->clickWire($second, '[wire\\:click="submit"]');
            $this->settleAtFakeMollie($second, 'paid');
            $second->waitForText(__('billing::portal.return.title'), 20);
            $this->shot($second, 'B: bezahlt, Kampagne voll');

            $this->assertSame(1, (int) Coupon::query()->where('code', 'LASTSLOT')->value('redemptions_count'), 'the campaign is full');

            // Now A pays the 9.42 the checkout agreed with them.
            $this->settleAtFakeMollie($first, 'paid');
            $first->waitForText(__('billing::portal.return.title'), 20);
            $first->assertDontSee(__('billing::portal.return.failed_title'));
            $this->shot($first, 'A: bezahlt, Rückkehrseite');

            // The dashboard announces the discounted next charge.
            $first->visit('/billing')->waitForText('Starter', 15)->pause(500);
            $shown = $this->nextChargeOnScreen($first);
            $this->assertSame(NextChargeSummary::STATE_SCHEDULED, $shown['state']);
            $this->assertSame($this->money(942), $this->squashMoney((string) $shown['gross']), 'A: next charge is discounted');
            $this->assertStringContainsString(__('billing::portal.next_charge.discount', ['code' => 'LASTSLOT']), $shown['text']);
            $this->assertNextChargeMatchesBooks($first, $a, 'A after the campaign filled up');
            $this->shot($first, 'A: nächste Abbuchung mit Rabatt');

            $this->assertInvoiceInPortal($first, $a, 942);

            // The gate still holds for anybody who starts a checkout now: the campaign is full.
            $this->walkCheckoutToConfirm($second, $c, '/billing/checkout?plan=starter&interval=monthly');
            $second->waitFor('[wire\\:click="applyCoupon"]', 15)->pause(300)->type('coupon_input', 'LASTSLOT');
            $this->clickWire($second, '[wire\\:click="applyCoupon"]');
            $second->waitForText(__('billing::checkout.coupon_exhausted'), 15);
            $this->assertSame(0, $this->domCount($second, '[dusk="confirm-discount"]'), 'a refused code takes nothing off');
            $this->assertShownAmount($second, 'confirm-total', 1178, 'C pays the full price');
            $this->shot($second, 'C: Kampagne ausgeschöpft');
        });

        $a->refresh();
        $invoice = BillingInvoice::query()->where('billable_id', $a->getKey())->sole();

        $this->assertInvoiceCarriesTheCode($invoice, 'LASTSLOT', 792, 942);
        $this->assertSame('9.42', $this->mollieSubscriptionAmount($a), "A's Mollie subscription holds the discount");
        $this->assertSame('9.42', $this->mollieSubscriptionAmount($b->fresh()));
        $this->assertSame('LASTSLOT', $a->getBillingSubscriptionMeta()['active_recurring_coupon']['code'] ?? null, 'the discount runs for its periods');
        $this->assertSame(SubscriptionStatus::Active, $a->subscription_status);

        // One payment each, nothing charged twice.
        foreach ([$a, $b] as $billable) {
            $this->assertSame(1, DB::table('fake_mollie_payments')->where('customer_id', $billable->fresh()->mollie_customer_id)->count());
        }

        // The campaign's limit stood: A's redemption could not be recorded behind it —
        // audited, not silent; and nothing was reported as a wrong amount.
        $this->assertSame(1, (int) $coupon->fresh()->redemptions_count);
        $this->assertSame(0, CouponRedemption::query()->where('billable_id', $a->getKey())->count());
        $this->assertSame(1, BillingAuditEntry::model()::query()->where('event', 'coupon_redemption_record_missing')->count());
        $this->assertSame(0, BillingAuditEntry::model()::query()->where('event', 'payment_amount_mismatch')->count());

        // The second period is still discounted.
        $this->renewSubscription($a);
        $renewal = BillingInvoice::query()->where('billable_id', $a->getKey())->latest('id')->first();
        $this->assertSame(942, (int) $renewal->amount_gross);
        $this->assertNotNull(collect($renewal->line_items)->firstWhere('kind', 'coupon'));
        $this->assertNull(collect($renewal->line_items)->firstWhere('kind', 'adjustment'));
    }

    // ── Gap 10: promotion link, then the code is switched off before the webhook ──

    public function test_a_promotion_code_switched_off_while_the_payment_travelled_is_honoured_for_that_payment_only(): void
    {
        $coupon = $this->coupons()->create([
            'code' => 'SPRINGFLIGHT',
            'name' => 'Spring campaign',
            'type' => CouponType::Recurring,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 20,
            'discount_periods' => 3,
            'auto_apply_token' => 'spring-flight',
        ]);

        $billable = $this->makeBillable(self::ADDRESS);

        $this->browse(function (Browser $customer, Browser $operator) use ($billable, $coupon): void {
            // The link parks the code; the checkout applies it without anything typed.
            $this->actAs($customer, $billable, route(BillingRoute::promotion(), ['token' => 'spring-flight'], false));
            $this->waitForPathContains($customer, 'checkout');
            $this->walkCheckoutToConfirm($customer, null, '/billing/checkout?plan=starter&interval=monthly');
            $customer->assertSee('SPRINGFLIGHT');
            $this->assertShownAmount($customer, 'confirm-total', 942, 'total with the campaign code applied');
            $this->shot($customer, 'Kampagnen-Link: Code vorbelegt');

            $this->clickWire($customer, '[wire\\:click="submit"]');
            $this->waitForPathContains($customer, '/fake-mollie/checkout/');
            $customer->waitFor('@fake-mollie-pay', 10);

            // The operator switches the code off in the coupon list.
            $this->loginAdmin($operator);
            $operator->visit('/billing/admin/coupons')->waitFor('[dusk="coupon-row-'.$coupon->id.'"]', 15)->pause(400);
            $this->tickCouponRow($operator, $coupon->id);
            $operator->waitFor('[wire\\:click="deactivateSelected"]', 10);
            $this->clickWire($operator, '[wire\\:click="deactivateSelected"]');
            $operator->waitUsing(15, 150, fn (): bool => str_contains(
                $operator->text('[dusk="coupon-row-'.$coupon->id.'"]'),
                __('billing::admin.common.inactive'),
            ), 'Waited for the row to read Inactive.');
            $this->shot($operator, 'Code in der Liste abgeschaltet');
            $this->assertFalse($coupon->fresh()->active);

            // The customer pays what the checkout agreed.
            $this->settleAtFakeMollie($customer, 'paid');
            $customer->waitForText(__('billing::portal.return.title'), 20);
            $customer->assertSee(__('billing::portal.return.body'))
                ->assertDontSee(__('billing::portal.return.failed_title'));
            $this->shot($customer, 'Rückkehrseite nach bezahlter Zahlung');

            // The discount ends here: the next charge is the full price, without a discount line.
            $customer->visit('/billing')->waitForText('Starter', 15)->pause(500);
            $shown = $this->nextChargeOnScreen($customer);
            $this->assertSame(NextChargeSummary::STATE_SCHEDULED, $shown['state']);
            $this->assertSame($this->money(1178), $this->squashMoney((string) $shown['gross']), 'next charge at the full price');
            $this->assertStringNotContainsString(__('billing::portal.next_charge.discount', ['code' => 'SPRINGFLIGHT']), $shown['text']);
            $this->assertNextChargeMatchesBooks($customer, $billable, 'after the code was switched off in flight');
            $this->shot($customer, 'Nächste Abbuchung: voller Preis');

            $this->assertInvoiceInPortal($customer, $billable, 942);
        });

        $billable->refresh();
        $invoice = BillingInvoice::query()->sole();

        // The payment made with the code is booked as such …
        $this->assertInvoiceCarriesTheCode($invoice, 'SPRINGFLIGHT', 792, 942);
        $this->assertSame('9.42', $this->latestFakePayment()['amount_value']);
        $this->assertSame(1, CouponRedemption::query()->where('billable_id', $billable->getKey())->count());
        $this->assertSame(0, BillingAuditEntry::model()::query()->where('event', 'payment_amount_mismatch')->count());

        // … and switching it off ended the discount: Mollie holds the full price.
        $this->assertSame('11.78', $this->mollieSubscriptionAmount($billable));
        // The marker is written with the redemption, but a switched-off code's marker is
        // not honoured by anything that prices a charge (`isMarkerStillRedeemable()` reads
        // `active`) — the same state as a code switched off under a running subscriber,
        // cleared by the next renewal.
        $this->assertSame(0, (int) app(NextChargeSummary::class)->for($billable)['discount_net']);
        $this->assertSame(SubscriptionStatus::Active, $billable->subscription_status);
        $this->assertSame(1, DB::table('fake_mollie_payments')->count(), 'one payment, nothing charged twice');

        $this->renewSubscription($billable);
        $renewal = BillingInvoice::query()->latest('id')->first();
        $this->assertSame(1178, (int) $renewal->amount_gross, 'the second period is charged in full');
        $this->assertNull(collect($renewal->line_items)->firstWhere('kind', 'coupon'));
        $this->assertNull(collect($renewal->line_items)->firstWhere('kind', 'adjustment'));
        $this->assertNull($billable->fresh()->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null, 'the renewal clears the dead marker');
        $this->assertSame('11.78', $this->mollieSubscriptionAmount($billable));
        $this->assertSame(0, BillingAuditEntry::model()::query()->where('event', 'payment_amount_mismatch')->count());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Walk the checkout to its confirm step. `$billable` null: the session is already the
     * customer's (a promotion link was followed in it).
     */
    private function walkCheckoutToConfirm(Browser $browser, ?TestBillable $billable, string $path): void
    {
        if ($billable !== null) {
            $this->actAs($browser, $billable, $path);
        } else {
            $browser->visit($path);
        }

        // Billing step locked (address on file): the plan step is open, plan preselected.
        $browser->waitFor('[wire\\:click="next"]', 15)->pause(500);
        $this->clickWire($browser, '[wire\\:click="next"]');
        $browser->pause(700);

        // The extras step only exists for a plan that sells addons or seats.
        if ($this->domCount($browser, '[wire\\:click="submit"]') === 0) {
            $this->clickWire($browser, '[wire\\:click="next"]');
        }

        $browser->waitFor('[wire\\:click="submit"]', 15)->pause(500);
    }

    private function applyCheckoutCode(Browser $browser, string $code): void
    {
        $browser->waitFor('[wire\\:click="applyCoupon"]', 15)->pause(300)->type('coupon_input', $code);
        $this->clickWire($browser, '[wire\\:click="applyCoupon"]');
        $this->waitForPresence($browser, '[wire\\:click="removeCoupon"]', 20);
        $browser->pause(400);
    }

    private function giveFreePeriods(Browser $browser, int $periods): void
    {
        $browser->type('input[wire\\:model="freePeriods"]', (string) $periods)->pause(200);
        $this->clickWire($browser, 'form[wire\\:submit="giveFreePlanPeriods"] button[type="submit"]');
        $browser->waitForText(__('billing::admin.billables.subscription.flash.free_periods_given', ['count' => $periods]), 15)->pause(500);
    }

    private function tickCouponRow(Browser $browser, int $couponId): void
    {
        $css = '[dusk="coupon-row-'.$couponId.'"] ui-checkbox';
        $this->waitForPresence($browser, $css);
        $element = $browser->driver->findElement(\Facebook\WebDriver\WebDriverBy::cssSelector($css));
        $browser->driver->executeScript('arguments[0].scrollIntoView({block: "center"});', [$element]);
        $browser->pause(150);
        $element->click();
        $browser->waitUsing(10, 100, fn (): bool => (bool) $browser->script(sprintf(
            'const el = document.querySelector(%s); return el !== null && el.hasAttribute("data-checked");',
            json_encode($css),
        ))[0], 'The coupon row did not become ticked.');
    }

    /** Plan, status and source on the admin tab: the free plan, running, local. */
    private function assertTabShowsFreePlan(Browser $browser): void
    {
        $browser->assertSeeIn('[dusk="sub-plan"]', 'changes-free')
            ->assertSeeIn('[dusk="sub-status"]', SubscriptionStatus::Active->label())
            ->assertSeeIn('[dusk="sub-source"]', SubscriptionSource::Local->label());
        $this->assertSame(NextChargeSummary::STATE_FREE, $this->nextChargeOnScreen($browser)['state']);
    }

    /** The next charge is the addon only, with the plan line taken off until B's end. */
    private function assertPlanStillFree(Browser $browser, TestBillable $billable, string $what): void
    {
        $summary = app(NextChargeSummary::class)->for($billable->fresh());
        $shown = $this->nextChargeOnScreen($browser);

        $this->assertSame(NextChargeSummary::STATE_SCHEDULED, $shown['state'], $what);
        $this->assertSame($this->money(583), $this->squashMoney((string) $shown['gross']), "{$what}: the addon only");
        $this->assertSame(2900, (int) $summary['discount_net'], "{$what}: the whole plan is waived");
        $this->assertNotNull($summary['plan_free_until'] ?? null, "{$what}: a waiver is running");
        $this->assertStringContainsString(__('billing::portal.next_charge.plan_free_until', [
            'date' => BillingTime::billingDay($summary['plan_free_until'])?->translatedFormat('d. M Y'),
        ]), $shown['text'], $what);
        $this->assertNextChargeMatchesBooks($browser, $billable, $what);
    }

    /** The invoice row in the customer's portal: the discounted gross, and a PDF behind it. */
    private function assertInvoiceInPortal(Browser $browser, TestBillable $billable, int $gross): void
    {
        $invoice = BillingInvoice::query()->where('billable_id', $billable->getKey())->latest('id')->firstOrFail();

        $browser->visit('/billing/invoices')->waitFor('[dusk="invoice-row-'.$invoice->id.'"]', 15)->pause(400);
        $this->assertShownAmount($browser, 'invoice-'.$invoice->id.'-gross', $gross, 'invoice gross in the portal');

        $href = (string) $browser->attribute('[dusk="invoice-row-'.$invoice->id.'"] a', 'href');
        $download = $this->opsFetch($browser, $href);
        $this->assertSame(200, $download['status']);
        $this->assertStringStartsWith('%PDF', $download['head']);
        $this->shot($browser, 'Rechnung im Portal');
    }

    /** Booked with the code: a coupon line, no payment adjustment — on the row and in the PDF. */
    private function assertInvoiceCarriesTheCode(BillingInvoice $invoice, string $code, int $net, int $gross): void
    {
        $lines = collect($invoice->line_items);
        $couponLine = $lines->firstWhere('kind', 'coupon');

        $this->assertSame([$net, $gross], [(int) $invoice->amount_net, (int) $invoice->amount_gross]);
        $this->assertNotNull($couponLine, 'the invoice carries the coupon line');
        $this->assertSame(-198, (int) $couponLine['total_net']);
        $this->assertNull($lines->firstWhere('kind', 'adjustment'), 'no payment adjustment');

        $this->assertTrue($invoice->hasPdf());
        $pdf = PdfText::of((string) Storage::disk((string) $invoice->pdf_disk)->get((string) $invoice->pdf_path));
        $this->assertStringContainsString(PdfText::squash((string) $couponLine['label']), $pdf);
        $this->assertStringNotContainsString('Payment adjustment', $pdf);
    }

    private function money(int $minor): string
    {
        return $this->squashMoney(BillingMoney::format($minor));
    }

    private function squashMoney(string $value): string
    {
        return str_replace([' ', "\u{00a0}", "\u{202f}"], '', trim($value));
    }
}
