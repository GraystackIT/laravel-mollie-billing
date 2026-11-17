<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser\Matrix;

use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\NextChargeSummary;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;

/**
 * Levers of the coupon and free-time matrix: the subscription states a code can be
 * typed into, and the few ways of driving and reading a screen its scenarios share.
 *
 * Workbench catalog (19 % VAT for the German default customer): Starter 9.90, Pro 29.00
 * with 3 seats included and 9.90 per extra seat, Softdrinks 4.90, Priority Support 9.90,
 * Free Forever 0.00.
 */
trait CouponWorkbench
{
    /** Every state of a subscription the customer can be in when they type a code. */
    public const COUPON_STATES = ['none', 'trial', 'active', 'free', 'gifted', 'past_due', 'cancelled'];

    /**
     * A billable in one of the states above. Billing address filled in everywhere: the
     * dashboard asks for it otherwise, and that is not what these scenarios are about.
     */
    protected function couponBillableIn(string $state): TestBillable
    {
        $address = [
            'billing_street' => 'Musterweg 12',
            'billing_postal_code' => '10115',
            'billing_city' => 'Berlin',
            'billing_country' => 'DE',
            'tax_country_user' => 'DE',
        ];

        return match ($state) {
            'none' => $this->makeBillable($address),
            'trial' => $this->makeActiveSubscriber('pro', 'monthly', [
                'subscription_status' => SubscriptionStatus::Trial,
                'trial_ends_at' => now()->addDays(5),
                'subscription_period_starts_at' => now()->subDays(9),
            ]),
            'active' => $this->makeActiveSubscriber('pro', 'monthly'),
            'past_due' => $this->makeActiveSubscriber('pro', 'monthly', [
                'subscription_status' => SubscriptionStatus::PastDue,
                'subscription_period_starts_at' => now()->subDays(33),
            ]),
            'cancelled' => $this->makeActiveSubscriber('pro', 'monthly', [
                'subscription_status' => SubscriptionStatus::Cancelled,
                'subscription_ends_at' => now()->addDays(20),
            ]),
            'free' => $this->makeBillable($address + [
                'subscription_source' => SubscriptionSource::Local,
                'subscription_status' => SubscriptionStatus::Active,
                'subscription_plan_code' => 'coupon-free',
                'subscription_interval' => 'monthly',
                'subscription_period_starts_at' => now()->subDays(10),
            ]),
            'gifted' => (function () use ($address): TestBillable {
                $billable = $this->makeBillable($address);
                $coupon = $this->coupons()->accessGrantCoupon('SEED-GIFT-'.strtoupper(substr(uniqid(), -5)), 'pro', 'monthly', [], 90, null, 1);
                $this->coupons()->redeem($coupon, $billable, []);

                return $billable->refresh();
            })(),
        };
    }

    /**
     * Set properties on the Livewire component that contains `$anchor`, one request each
     * and in order — for Flux controls (selects, checkbox groups, date pickers) whose DOM
     * a WebDriver cannot type into reliably. Plain inputs are typed into instead.
     *
     * @param  array<string, mixed>  $properties
     */
    protected function wireSet(Browser $browser, string $anchor, array $properties): Browser
    {
        $browser->waitFor($anchor, 15);

        $browser->script(sprintf(
            '(async () => { const el = document.querySelector(%s); const w = window.Livewire.find(el.closest("[wire\\\\:id]").getAttribute("wire:id")); for (const [k, v] of Object.entries(%s)) { await w.set(k, v); } window.__couponWireDone = true; })(); window.__couponWireDone = false;',
            json_encode($anchor),
            json_encode((object) $properties),
        ));

        return $browser->waitUsing(15, 100, fn (): bool => (bool) $browser->script('return window.__couponWireDone === true;')[0])
            ->pause(300);
    }

    /** Type a code into the dashboard's one field and submit it. */
    protected function redeemOnDashboard(Browser $browser, string $code): Browser
    {
        $browser->waitFor('[wire\\:click="redeemCoupon"]', 15);
        $browser->type('couponCode', $code);
        $this->clickWire($browser, '[wire\\:click="redeemCoupon"]');

        return $browser->pause(900);
    }

    /**
     * What the "next charge" block on screen says: its state, and — when a charge is
     * scheduled — the date and the gross amount as printed.
     *
     * @return array{state: ?string, date: ?string, gross: ?string, text: string}
     */
    protected function nextChargeOnScreen(Browser $browser): array
    {
        return (array) $browser->script(<<<'JS'
            const box = document.querySelector('[data-next-charge]');
            if (! box) { return {state: null, date: null, gross: null, text: ''}; }
            const pick = (sel) => { const n = box.querySelector(sel); return n ? n.textContent.trim() : null; };
            return {
                state: box.getAttribute('data-next-charge'),
                date: pick('[data-next-charge-date]'),
                gross: pick('[data-next-charge-gross]'),
                text: box.innerText,
            };
        JS)[0];
    }

    /**
     * The "next charge" block shows exactly what NextChargeSummary computes for the
     * billable as it now stands — and, when a charge is scheduled, the amount the test
     * Mollie holds for the subscription.
     */
    protected function assertNextChargeMatchesBooks(Browser $browser, TestBillable $billable, string $what): void
    {
        $billable = $billable->fresh();
        $summary = app(NextChargeSummary::class)->for($billable);
        $shown = $this->nextChargeOnScreen($browser);

        if (($summary['state'] ?? NextChargeSummary::STATE_NONE) === NextChargeSummary::STATE_NONE && $shown['state'] === null) {
            return;
        }

        expect($shown['state'])->toBe($summary['state'], "{$what}: state of the next-charge block");

        if ($summary['state'] !== NextChargeSummary::STATE_SCHEDULED) {
            return;
        }

        $normalise = static fn (?string $value): string => str_replace([' ', "\u{00a0}", "\u{202f}"], '', (string) $value);

        expect($normalise($shown['gross']))->toBe($normalise(BillingMoney::format((int) $summary['gross'])), "{$what}: gross of the next charge");

        if ((int) $summary['discount_net'] > 0) {
            expect(str_contains($normalise($shown['text']), $normalise(BillingMoney::format((int) $summary['discount_net']))))
                ->toBeTrue("{$what}: discount line of the next charge");
        }

        // What Mollie will actually collect — unless the charge is deferred, where Mollie
        // holds the full price behind the start date by design.
        $subscriptionId = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');
        $row = $subscriptionId !== '' ? DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->first() : null;

        if ($row !== null && ! ($summary['deferred'] ?? false) && (string) $row->status === 'active') {
            expect((string) $row->amount_value)->toBe(
                number_format(((int) $summary['gross']) / 100, 2, '.', ''),
                "{$what}: amount the test Mollie holds for the subscription",
            );
        }
    }

    /** The amount the test Mollie holds for the billable's subscription, e.g. "34.51". */
    protected function mollieSubscriptionAmount(TestBillable $billable): ?string
    {
        $subscriptionId = (string) ($billable->fresh()->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');
        $row = $subscriptionId !== '' ? DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->first() : null;

        return $row !== null ? (string) $row->amount_value : null;
    }

    /** The start / next payment date the test Mollie holds, as Y-m-d. */
    protected function mollieNextPaymentDate(TestBillable $billable): ?string
    {
        $subscriptionId = (string) ($billable->fresh()->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');
        $row = $subscriptionId !== '' ? DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->first() : null;

        return $row !== null ? substr((string) ($row->next_payment_date ?? $row->start_date), 0, 10) : null;
    }

    /** Open the admin's subscription tab for a billable. */
    protected function openSubscriptionTab(Browser $browser, TestBillable $billable): Browser
    {
        return $browser->visit('/billing/admin/billables/'.$billable->getKey())
            ->waitForText($billable->name, 15)
            ->pause(700);
    }
}
