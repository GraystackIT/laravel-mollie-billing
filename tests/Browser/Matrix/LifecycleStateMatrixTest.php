<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser\Matrix;

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Browser\BrowserTestCase;
use Laravel\Dusk\Browser;
use Workbench\App\Support\WorkbenchSetting;

/**
 * Every subscription state, reached the way a customer reaches it, and shown correctly.
 *
 * Each scenario walks a real path — checkout, a renewal at the test Mollie, a failed
 * charge, the dunning ladder, a cancellation, the nightly passes — and at every stop holds
 * three things against the database: the status badge the customer sees on the dashboard,
 * the date printed as "next billing" (or the end date), and which actions are offered and
 * which are not. The operator's view of the same billable is checked for the same status
 * and for the subscription source.
 *
 * Statuses × sources that exist: New/None, Active/Mollie, Active/Local, Trial/Mollie,
 * PastDue/Mollie, Cancelled/Mollie, Expired/None. The others cannot be produced by the
 * package (a Local subscription has nothing to fail a charge on).
 */
class LifecycleStateMatrixTest extends BrowserTestCase
{
    protected function tearDown(): void
    {
        WorkbenchSetting::forget('recurring_outcome');

        parent::tearDown();
    }

    /** Texts of every Flux badge on the page — where statuses and sources are shown. */
    private function badges(Browser $browser): array
    {
        return (array) $browser->script(
            'return Array.from(document.querySelectorAll("[data-flux-badge]")).map(e => (e.innerText || "").trim())'
        )[0];
    }

    private function hasButton(Browser $browser, string $text): bool
    {
        return (bool) $browser->script(sprintf(
            'return Array.from(document.querySelectorAll("button, a")).some(e => e.offsetParent !== null && (e.innerText || "").trim() === %s)',
            json_encode($text),
        ))[0];
    }

    /**
     * The customer's dashboard shows this billable's state as the database holds it.
     *
     * @param  list<string>  $offered  action labels that must be on the page
     * @param  list<string>  $withheld  action labels that must not be
     */
    private function assertDashboard(Browser $browser, TestBillable $billable, SubscriptionStatus $status, array $offered, array $withheld, ?string $banner = null, ?string $dateShown = null): void
    {
        $billable->refresh();

        expect($billable->subscription_status)->toBe($status, 'the database is not in the state this stop expects');

        $this->actAs($browser, $billable);
        $browser->waitForText(__('billing::portal.dashboard'), 15)->pause(700);
        $this->shot($browser, 'Dashboard: '.$status->label().' / '.($billable->subscription_source?->label() ?? '—'));

        if ($billable->getBillingSubscriptionPlanCode() !== null) {
            expect(in_array($status->label(), $this->badges($browser), true))->toBeTrue('the status badge does not say '.$status->label());
        } else {
            // Without a plan there is no badge: the dashboard says so instead.
            $browser->assertSee(__('billing::portal.no_subscription_banner'));
        }

        foreach (array_diff(array_map(fn (SubscriptionStatus $s): string => $s->label(), SubscriptionStatus::cases()), [$status->label()]) as $other) {
            expect(in_array($other, $this->badges($browser), true))->toBeFalse('a second status badge is shown: '.$other);
        }

        if ($banner !== null) {
            $browser->assertSee($banner);
        }

        if ($dateShown !== null) {
            $browser->assertSee($dateShown);
        }

        foreach ($offered as $label) {
            expect($this->hasButton($browser, $label))->toBeTrue('"'.$label.'" is not offered in state '.$status->label());
        }

        foreach ($withheld as $label) {
            expect($this->hasButton($browser, $label))->toBeFalse('"'.$label.'" is offered in state '.$status->label());
        }
    }

    /** The operator sees the same status, and the source. */
    private function assertAdmin(Browser $browser, TestBillable $billable, SubscriptionStatus $status, SubscriptionSource $source): void
    {
        $billable->refresh();

        expect($billable->subscription_source)->toBe($source);

        $this->loginAdmin($browser);
        $browser->visit('/billing/admin/billables/'.$billable->getKey())
            ->waitForText($billable->getBillingName(), 15)->pause(900);
        $this->shot($browser, 'Admin: '.$status->label().' / '.$source->label());

        $badges = $this->badges($browser);

        expect(in_array($status->label(), $badges, true))->toBeTrue('admin does not show status '.$status->label().' — badges: '.implode(' | ', $badges))
            ->and(in_array($source->label(), $badges, true))->toBeTrue('admin does not show source '.$source->label().' — badges: '.implode(' | ', $badges));
    }

    private function date(?\Carbon\CarbonInterface $at, TestBillable $billable): string
    {
        return (string) BillingTime::display($at, $billable)?->translatedFormat('d. M Y');
    }

    private function actions(): array
    {
        return [
            'cancel' => __('billing::portal.cancel_subscription'),
            'end_trial' => __('billing::portal.end_trial'),
            'resubscribe' => __('billing::portal.resubscribe'),
            'plan' => __('billing::portal.plan_change'),
        ];
    }

    public function test_checkout_takes_a_new_customer_to_an_active_mollie_subscription(): void
    {
        $a = $this->actions();
        $billable = $this->makeBillable([
            'billing_street' => 'Musterweg 12', 'billing_postal_code' => '10115', 'billing_city' => 'Berlin',
        ]);

        $this->browse(function (Browser $browser) use ($billable, $a): void {
            // New / None: nothing booked yet.
            $this->assertDashboard($browser, $billable, SubscriptionStatus::New, [$a['plan']], [$a['cancel'], $a['end_trial'], $a['resubscribe']]);
            $this->assertAdmin($browser, $billable, SubscriptionStatus::New, SubscriptionSource::None);

            // The checkout, paid at the test Mollie.
            $this->actAs($browser, $billable, '/billing/checkout');
            $browser->waitForText('Starter', 10);
            $browser->click('[wire\\:click*="\'starter\'"]')->pause(600);
            $this->clickWire($browser, '[wire\\:click="next"]');
            $browser->waitForText('Softdrinks', 10);
            $this->clickWire($browser, '[wire\\:click="next"]');
            $browser->waitFor('[wire\\:click="submit"]', 10)->pause(400);
            $this->clickWire($browser, '[wire\\:click="submit"]');
            $this->settleAtFakeMollie($browser, 'paid');
            $browser->waitForText(__('billing::portal.return.title'), 15);

            // Active / Mollie.
            $billable->refresh();
            $this->assertDashboard(
                $browser, $billable, SubscriptionStatus::Active,
                [$a['cancel'], $a['plan']], [$a['end_trial'], $a['resubscribe']],
                dateShown: $this->date($billable->nextBillingDate(), $billable),
            );
            $this->assertAdmin($browser, $billable, SubscriptionStatus::Active, SubscriptionSource::Mollie);
        });

        // What the dashboard promised as the next charge is what Mollie will collect.
        $subscription = \Illuminate\Support\Facades\DB::table('fake_mollie_subscriptions')->sole();
        $invoice = BillingInvoice::query()->sole();

        expect($subscription->amount_value)->toBe(BillingMoney::mollieAmount((int) $invoice->amount_gross, 'EUR'))
            ->and($this->latestFakePayment()['amount_value'])->toBe(BillingMoney::mollieAmount((int) $invoice->amount_gross, 'EUR'));
    }

    public function test_a_trial_converts_with_its_first_charge(): void
    {
        $a = $this->actions();
        $billable = $this->makeBillable([
            'billing_street' => 'Musterweg 12', 'billing_postal_code' => '10115', 'billing_city' => 'Berlin',
        ]);

        $this->browse(function (Browser $browser) use ($billable, $a): void {
            // Pro carries a 14-day trial: the checkout captures a mandate for €0.00.
            $this->actAs($browser, $billable, '/billing/checkout');
            $browser->waitForText('Pro', 10);
            $browser->click('[wire\\:click*="\'pro\'"]')->pause(600);
            $this->clickWire($browser, '[wire\\:click="next"]');
            $browser->waitForText('Softdrinks', 10);
            $this->clickWire($browser, '[wire\\:click="next"]');
            $browser->waitFor('[wire\\:click="submit"]', 10)->pause(400);
            $this->clickWire($browser, '[wire\\:click="submit"]');
            $this->settleAtFakeMollie($browser, 'paid');
            $browser->waitForText(__('billing::portal.return.title'), 15);

            // Trial / Mollie: the date shown is the trial's end, and the trial can be ended.
            $billable->refresh();
            $trialEnds = $this->date($billable->getBillingTrialEndsAt(), $billable);

            $this->assertDashboard(
                $browser, $billable, SubscriptionStatus::Trial,
                [$a['end_trial'], $a['plan']], [$a['cancel'], $a['resubscribe']],
                banner: __('billing::portal.trial_banner', ['date' => $trialEnds]),
                dateShown: $trialEnds,
            );
            $this->assertAdmin($browser, $billable, SubscriptionStatus::Trial, SubscriptionSource::Mollie);

            // The trial ends: Mollie collects the first charge.
            $this->renewSubscription($billable->refresh());

            $billable->refresh();
            $this->assertDashboard(
                $browser, $billable, SubscriptionStatus::Active,
                [$a['cancel'], $a['plan']], [$a['end_trial'], $a['resubscribe']],
                dateShown: $this->date($billable->nextBillingDate(), $billable),
            );
        });

        $invoice = BillingInvoice::query()->sole();

        // 29.00 + 19 % — the first money of this subscription.
        expect((int) $invoice->amount_gross)->toBe(3451)
            ->and($this->latestFakePayment()['amount_value'])->toBe('34.51');
    }

    public function test_a_trial_whose_first_charge_never_arrives_lapses(): void
    {
        $a = $this->actions();
        $billable = $this->makeActiveSubscriber('pro', 'monthly', [
            'subscription_status' => SubscriptionStatus::Trial,
            'trial_ends_at' => now()->addDays(3),
        ]);

        $this->browse(function (Browser $browser) use ($billable, $a): void {
            $this->assertDashboard($browser, $billable, SubscriptionStatus::Trial, [$a['end_trial']], [$a['cancel'], $a['resubscribe']]);

            // The trial's end passes and the nightly pass runs. A mandate holder is not
            // expired on that night: Mollie's first charge is due the same day and its
            // webhook decides. The trial stands until the grace period is over.
            $this->simulator()->trialExpired($billable->refresh());
            expect($billable->refresh()->subscription_status)->toBe(SubscriptionStatus::Trial);

            // No word from Mollie for longer than that: the charge is not coming.
            $billable->forceFill(['trial_ends_at' => now()->subDays(\GraystackIT\MollieBilling\Jobs\ProcessTrialLifecycleJob::FIRST_CHARGE_GRACE_DAYS + 1)])->save();
            \GraystackIT\MollieBilling\Jobs\ProcessTrialLifecycleJob::dispatchSync();

            $billable->refresh();
            $status = $billable->subscription_status;

            expect($status)->toBe(SubscriptionStatus::PastDue);
            $this->assertDashboard($browser, $billable, $status, [], [$a['end_trial']]);
            $this->assertAdmin($browser, $billable, $status, $billable->subscription_source);
        });
    }

    public function test_a_trial_without_a_mandate_ends_without_a_subscription(): void
    {
        $a = $this->actions();

        // A trial an app started on its own: no Mollie mandate, nothing to charge.
        $billable = $this->makeBillable([
            'billing_street' => 'Musterweg 12', 'billing_postal_code' => '10115', 'billing_city' => 'Berlin',
            'subscription_plan_code' => 'pro',
            'subscription_interval' => 'monthly',
            'subscription_source' => SubscriptionSource::Local,
            'subscription_status' => SubscriptionStatus::Trial,
            'subscription_period_starts_at' => now()->subDays(10),
            'trial_ends_at' => now()->addDays(4),
        ]);

        $this->browse(function (Browser $browser) use ($billable, $a): void {
            $this->assertDashboard($browser, $billable, SubscriptionStatus::Trial, [$a['end_trial']], [$a['cancel'], $a['resubscribe']]);
            $this->assertAdmin($browser, $billable, SubscriptionStatus::Trial, SubscriptionSource::Local);

            $this->simulator()->trialExpired($billable->refresh());

            $billable->refresh();

            expect($billable->subscription_status)->not->toBe(SubscriptionStatus::Trial)
                ->and($billable->hasAccessibleBillingSubscription())->toBeFalse();

            $this->assertDashboard($browser, $billable, $billable->subscription_status, [], [$a['end_trial'], $a['cancel']]);
        });
    }

    public function test_a_failed_renewal_runs_through_dunning_to_cancellation_or_recovers(): void
    {
        $a = $this->actions();
        $recovering = $this->makeActiveSubscriber('starter', 'monthly');
        $lost = $this->makeActiveSubscriber('starter', 'monthly');

        $this->browse(function (Browser $browser) use ($recovering, $lost, $a): void {
            // Both renewals fail.
            $this->setRecurringOutcome('failed');
            $this->renewSubscription($recovering->refresh());
            $this->renewSubscription($lost->refresh());

            // Past due / Mollie: the banner says so; cancelling is not offered while the
            // charge is outstanding, changing the plan (the way out) is.
            foreach ([$recovering, $lost] as $billable) {
                $this->assertDashboard(
                    $browser, $billable, SubscriptionStatus::PastDue,
                    [$a['plan']], [$a['cancel'], $a['end_trial'], $a['resubscribe']],
                    banner: __('billing::portal.past_due_banner'),
                );
            }
            $this->assertAdmin($browser, $lost, SubscriptionStatus::PastDue, SubscriptionSource::Mollie);

            // One recovers: Mollie's next charge goes through.
            $this->setRecurringOutcome('paid');
            $this->renewSubscription($recovering->refresh());

            $recovering->refresh();
            $this->assertDashboard(
                $browser, $recovering, SubscriptionStatus::Active,
                [$a['cancel']], [$a['resubscribe']],
                dateShown: $this->date($recovering->nextBillingDate(), $recovering),
            );

            // The other stays unpaid for the whole dunning period: cancelled by the nightly pass.
            $this->simulator()->pastDueAutoCancel($lost->refresh());

            $lost->refresh();
            expect($lost->subscription_status)->toBeIn([SubscriptionStatus::Cancelled, SubscriptionStatus::Expired]);

            $this->assertDashboard($browser, $lost, $lost->subscription_status, [], [$a['cancel'], $a['end_trial']]);
            $this->assertAdmin($browser, $lost, $lost->subscription_status, $lost->subscription_source);
        });

        // The recovery booked exactly one invoice; the lost subscription none.
        expect(BillingInvoice::query()->where('billable_id', $recovering->getKey())->count())->toBe(1)
            ->and(BillingInvoice::query()->where('billable_id', $lost->getKey())->count())->toBe(0);
    }

    public function test_cancel_at_period_end_resubscribe_cancel_again_and_expire(): void
    {
        $a = $this->actions();
        $billable = $this->makeActiveSubscriber('starter', 'monthly');

        $this->browse(function (Browser $browser) use ($billable, $a): void {
            $this->actAs($browser, $billable);
            $browser->waitForText('Starter', 10);

            // Cancel at the end of the period, from the dashboard.
            $browser->press($a['cancel'])->pause(400);
            $this->clickWire($browser, '[wire\\:click="cancelSubscription"]');
            $browser->waitForText($a['resubscribe'], 10)->pause(400);

            // Cancelled / Mollie: access until the period end, which is the date shown.
            $billable->refresh();
            $endsAt = $this->date($billable->getBillingSubscriptionEndsAt(), $billable);

            expect($billable->getBillingSubscriptionEndsAt()->toDateString())->toBe($billable->nextBillingDate()->toDateString());

            $this->assertDashboard(
                $browser, $billable, SubscriptionStatus::Cancelled,
                [$a['resubscribe'], $a['plan']], [$a['cancel'], $a['end_trial']],
                banner: __('billing::portal.cancelled_banner', ['date' => $endsAt]),
                dateShown: $endsAt,
            );
            $this->assertAdmin($browser, $billable, SubscriptionStatus::Cancelled, SubscriptionSource::Mollie);

            // Resubscribe inside the grace period.
            $this->actAs($browser, $billable);
            $browser->waitForText($a['resubscribe'], 10);
            $this->clickWire($browser, '[wire\\:click="resubscribe"]');
            $browser->waitForText($a['cancel'], 15)->pause(400);

            $billable->refresh();
            $this->assertDashboard(
                $browser, $billable, SubscriptionStatus::Active,
                [$a['cancel']], [$a['resubscribe']],
                dateShown: $this->date($billable->nextBillingDate(), $billable),
            );

            // Cancelled again and left alone until the period is over.
            $billable->cancelBillingSubscription(false);
            $this->simulator()->cancelledToExpired($billable->refresh());

            // Expired / None: no plan, nothing to cancel or resume — only a new booking.
            $billable->refresh();
            $this->assertDashboard($browser, $billable, SubscriptionStatus::Expired, [$a['plan']], [$a['cancel'], $a['end_trial'], $a['resubscribe']]);
            $this->assertAdmin($browser, $billable, SubscriptionStatus::Expired, SubscriptionSource::None);
        });

        expect($billable->refresh()->hasAccessibleBillingSubscription())->toBeFalse();
    }

    public function test_an_immediate_cancellation_ends_access_at_once(): void
    {
        $a = $this->actions();
        $billable = $this->makeActiveSubscriber('starter', 'monthly');

        $this->browse(function (Browser $browser) use ($billable, $a): void {
            $this->assertDashboard($browser, $billable, SubscriptionStatus::Active, [$a['cancel']], [$a['resubscribe']]);

            // An operator ends it now (the portal only offers the period end).
            $billable->refresh()->cancelBillingSubscription(true, 'fraud');

            $billable->refresh();

            expect($billable->hasAccessibleBillingSubscription())->toBeFalse()
                ->and(\Illuminate\Support\Facades\DB::table('fake_mollie_subscriptions')->sole()->status)->toBe('canceled');

            // No grace period, so nothing to resume.
            $this->assertDashboard($browser, $billable, $billable->subscription_status, [], [$a['cancel'], $a['resubscribe'], $a['end_trial']]);
            $this->assertAdmin($browser, $billable, $billable->subscription_status, $billable->subscription_source);
        });
    }

    public function test_a_gifted_plan_is_an_active_local_subscription_until_it_ends(): void
    {
        $a = $this->actions();
        $billable = $this->makeBillable([
            'billing_street' => 'Musterweg 12', 'billing_postal_code' => '10115', 'billing_city' => 'Berlin',
        ]);

        $coupon = $this->coupons()->accessGrantCoupon('GIFT30', 'starter', 'monthly', [], 30);
        $this->coupons()->redeem($coupon, $billable->refresh(), []);

        $this->browse(function (Browser $browser) use ($billable, $a): void {
            // Active / Local: valid until the gift's end; no charge date, because nothing is charged.
            $billable->refresh();
            $this->assertDashboard(
                $browser, $billable, SubscriptionStatus::Active,
                [$a['plan']], [$a['resubscribe'], $a['end_trial']],
                dateShown: $this->date($billable->getBillingSubscriptionEndsAt(), $billable),
            );
            $browser->assertSee(__('billing::portal.access_grant_valid_until'));
            $this->assertAdmin($browser, $billable, SubscriptionStatus::Active, SubscriptionSource::Local);

            // The gift runs out and the nightly pass sees it.
            $billable->refresh()->forceFill(['subscription_ends_at' => now()->subMinute()])->save();
            \GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob::dispatchSync();

            $billable->refresh();

            expect($billable->hasAccessibleBillingSubscription())->toBeFalse();

            $this->assertDashboard($browser, $billable, $billable->subscription_status, [$a['plan']], [$a['cancel'], $a['resubscribe']]);
            $this->assertAdmin($browser, $billable, $billable->subscription_status, $billable->subscription_source);
        });
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function mollieSubscriptionStatuses(): array
    {
        return [
            'pending' => ['pending', true],
            'active' => ['active', true],
            'suspended' => ['suspended', true],
            'canceled' => ['canceled', false],
            'completed' => ['completed', false],
        ];
    }

    /**
     * A past-due customer whose Mollie subscription still has a charge scheduled is told
     * the date and offered "charge now"; one whose Mollie subscription is over gets the
     * ordinary past-due banner — and the dead reference is dropped.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('mollieSubscriptionStatuses')]
    public function test_a_past_due_dashboard_follows_the_status_mollie_reports(string $mollieStatus, bool $live): void
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly', [
            'subscription_status' => SubscriptionStatus::PastDue,
        ]);
        $subscriptionId = (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'];
        $chargeOn = now()->addDays(5);

        \Illuminate\Support\Facades\DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->update([
            'status' => $mollieStatus,
            'start_date' => $chargeOn->toDateString(),
            'next_payment_date' => $chargeOn->toDateString(),
        ]);

        // The gate caches a snapshot per subscription id for a minute, in a file store both
        // processes share — and the workbench hands out the same ids test after test.
        \Illuminate\Support\Facades\Cache::forget('mollie_billing.sub_snapshot.'.$subscriptionId);

        $this->browse(function (Browser $browser) use ($billable, $live, $chargeOn, $mollieStatus): void {
            $this->actAs($browser, $billable);
            $browser->waitForText(__('billing::portal.dashboard'), 15)->pause(900);
            $this->shot($browser, 'Past due, Mollie-Abo: '.$mollieStatus);

            if ($live) {
                $browser->assertSee(__('billing::portal.pending_mollie_charge_banner', ['date' => $this->date($chargeOn, $billable)]))
                    ->assertSee(__('billing::portal.force_charge_now'))
                    ->assertDontSee(__('billing::portal.past_due_banner'));

                return;
            }

            $browser->assertSee(__('billing::portal.past_due_banner'))
                ->assertDontSee(__('billing::portal.force_charge_now'));
        });

        $meta = $billable->refresh()->getBillingSubscriptionMeta();

        expect(isset($meta['mollie_subscription_id']))->toBe($live, 'the reference to a subscription Mollie reports as '.$mollieStatus);
    }

    public function test_every_invoice_kind_and_status_is_labelled_for_customer_and_operator(): void
    {
        $billable = $this->makeActiveSubscriber('starter', 'monthly');

        // Subscription: a renewal. Overage: usage beyond the quota, charged and settled.
        // One-time order and the two statuses the package itself never writes (open,
        // failed) are documents an app can hold — placed directly, like an import would.
        // Starter includes 50 tokens: 70 used are 20 over, charged before the renewal refills.
        $this->simulator()->overageCharge($billable->refresh(), 'Tokens', 70);
        $this->deliverWebhook($this->latestFakePayment()['id']);
        $this->renewSubscription($billable->refresh());

        expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Overage)->count())->toBe(1);

        $renewal = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Subscription)->sole();

        foreach ([[InvoiceKind::OneTimeOrder, InvoiceStatus::Paid, 'IN-OTO-1'], [InvoiceKind::Subscription, InvoiceStatus::Open, 'IN-OPEN-1'], [InvoiceKind::Subscription, InvoiceStatus::Failed, 'IN-FAIL-1']] as [$kind, $status, $serial]) {
            BillingInvoice::create([
                'billable_type' => $billable->getMorphClass(), 'billable_id' => $billable->getKey(),
                'mollie_payment_id' => 'tr_'.strtolower($serial), 'serial_number' => $serial,
                'invoice_kind' => $kind, 'status' => $status, 'country' => 'DE', 'currency' => 'EUR',
                'amount_net' => 1000, 'amount_vat' => 190, 'amount_gross' => 1190,
                'line_items' => [['kind' => 'plan', 'label' => 'Imported', 'quantity' => 1, 'unit_price_net' => 1000, 'amount_net' => 1000, 'vat_rate' => 19.0, 'vat_amount' => 190, 'amount_gross' => 1190]],
            ]);
        }

        // Refund kind, Refunded status: a real refund of the renewal.
        $creditNote = app(RefundInvoiceService::class)->refundPartially($renewal, 500, RefundReasonCode::Goodwill, 'matrix');

        expect($creditNote->invoice_kind)->toBe(InvoiceKind::Refund)
            ->and($creditNote->status)->toBe(InvoiceStatus::Refunded);

        $this->browse(function (Browser $browser) use ($billable, $renewal, $creditNote): void {
            $this->actAs($browser, $billable, '/billing/invoices');
            $browser->waitForText(InvoiceStatus::Refunded->label(), 15)->pause(600);
            $this->shot($browser, 'Rechnungsliste: alle Arten und Status');

            foreach (InvoiceKind::cases() as $kind) {
                $browser->assertSee($kind->label());
            }

            foreach (InvoiceStatus::cases() as $status) {
                $browser->assertSee($status->label());
            }

            // The credit note states what went back, with its sign.
            $browser->assertSee(BillingMoney::format((int) $creditNote->amount_gross, 'EUR', true))
                ->assertSee(BillingMoney::format((int) $renewal->amount_gross));

            $this->loginAdmin($browser);
            $browser->visit('/billing/admin/billables/'.$billable->getKey())
                ->waitForText($billable->getBillingName(), 15)->pause(600);
            $browser->press(__('billing::admin.billables.show.tab.invoices'))->pause(1200);
            $browser->waitForText((string) $renewal->serial_number, 15);
            $this->shot($browser, 'Admin: Rechnungen, alle Arten und Status');

            foreach (InvoiceKind::cases() as $kind) {
                $browser->assertSee($kind->label());
            }

            foreach (InvoiceStatus::cases() as $status) {
                $browser->assertSee($status->label());
            }
        });

        // What Mollie was asked to give back is what the credit note books.
        expect((int) $creditNote->amount_gross)->toBe(-595)
            ->and((int) $renewal->fresh()->refunded_net)->toBe(500);
    }
}
