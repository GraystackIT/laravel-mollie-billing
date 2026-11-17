<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser\Matrix;

use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Tests\Browser\BrowserTestCase;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Metered usage as two people see it: the operator crediting a wallet, and the customer
 * reading their meters and their usage history. What the operator does on one screen has
 * to show on the other — and in the next overage charge.
 */
class UsageWalletMatrixTest extends BrowserTestCase
{
    use OperationsWorkbench;

    /**
     * @return array<string, array{0: string, 1: int, 2: int, 3: string}>
     */
    public static function usageTypes(): array
    {
        // type, Starter's included quota, overage price (ct), gross of 5 units at 19 %
        return [
            'Tokens (rollover)' => ['Tokens', 50, 12, '0.71'],
            'SMS (no rollover)' => ['SMS', 20, 18, '1.07'],
        ];
    }

    #[DataProvider('usageTypes')]
    public function test_wallet_credit_shows_in_the_portal_and_lowers_the_next_overage_charge(string $type, int $quota, int $price, string $overageGross): void
    {
        $billable = $this->opsSubscriber('starter');
        $wallets = app(WalletUsageService::class);

        // Ten units over the quota.
        $wallets->debit($billable->fresh(), $type, $quota + 10);

        $this->browse(function (Browser $browser) use ($billable, $type): void {
            // The customer sees the overage …
            $this->actAs($browser, $billable, '/billing/usage');
            $browser->waitFor('[dusk="usage-meter-'.$type.'"]', 15)->pause(500);
            expect($browser->text('[dusk="usage-meter-'.$type.'"]'))->toContain('+10 '.__('billing::portal.overage'));
            expect($browser->attribute('[dusk="usage-meter-'.$type.'"]', 'data-state'))->toBe('danger');
            $this->shot($browser, 'Overage in the portal');

            // … and the operator credits 25 units.
            $this->loginAdmin($browser);
            $this->opsOpenBillableTab($browser, $billable, 'wallet');
            $browser->assertSee(__('billing::admin.billables.wallet.overage'));

            $browser->select('select[wire\\:model="creditType"]', $type)
                ->type('input[wire\\:model="creditUnits"]', '25')
                ->type('input[wire\\:model="creditReason"]', 'Goodwill after the outage');
            $this->clickWire($browser, 'form[wire\\:submit="credit"] button[type="submit"]');
            $browser->waitForText(__('billing::admin.billables.wallet.flash.credited', ['units' => 25, 'type' => $type]), 20)->pause(500);
            $this->shot($browser, 'Credited');

            // Zero is not something the form sends: the field itself refuses it (min="1"),
            // and the balance on screen is the credited one.
            $browser->type('input[wire\\:model="creditUnits"]', '0');
            $this->clickWire($browser, 'form[wire\\:submit="credit"] button[type="submit"]');
            $browser->pause(800)->assertDontSee(__('billing::admin.billables.wallet.flash.credited', ['units' => 0, 'type' => $type]));
        });

        $wallet = $billable->fresh()->getWallet($type);

        // −10 + 25, and the credit is registered as purchased so a reset keeps it.
        expect((int) $wallet->balanceInt)->toBe(15)
            ->and(WalletUsageService::getPurchasedBalance($wallet))->toBe(25);

        $this->browse(function (Browser $browser) use ($billable, $type): void {
            $this->actAs($browser, $billable, '/billing/usage');
            $browser->waitFor('[dusk="usage-meter-'.$type.'"]', 15)->pause(500);

            $meter = $browser->text('[dusk="usage-meter-'.$type.'"]');

            // The overage badge is gone; 15 of the 25 credited units are left.
            expect($meter)->not->toContain(__('billing::portal.overage'))
                ->and($meter)->toContain(__('billing::portal.usage_purchased_remaining', ['remaining' => '15', 'total' => '25']));

            // The credit is in the history under the reason the operator gave.
            $browser->assertSee('Goodwill after the outage');
            $this->shot($browser, 'Credit in the portal');
        });

        // 20 more units: 5 past everything. The next overage charge is for those 5 only.
        $wallets->debit($billable->fresh(), $type, 20);
        $result = app(ChargeUsageOverageDirectly::class)->handle($billable->fresh());

        expect($result->wasCharged())->toBeTrue()
            ->and($result->amountNet)->toBe(5 * $price);

        $payment = $this->latestFakePayment();
        expect($payment['amount_value'])->toBe($overageGross);

        $this->deliverWebhook($payment['id']);

        $invoice = BillingInvoice::query()->where('mollie_payment_id', $payment['id'])->sole();

        expect((int) $invoice->amount_net)->toBe(5 * $price)
            ->and(BillingMoney::mollieAmount((int) $invoice->amount_gross))->toBe($overageGross)
            ->and((int) $billable->fresh()->getWallet($type)->balanceInt)->toBe(0);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: int, 4: int, 5: list<string>}>
     */
    public static function historyFilters(): array
    {
        // type filter, from (days ago), to (days ago), units used, events, meters shown
        return [
            'everything' => ['', '6', '0', 45, 3, ['Tokens', 'SMS']],
            'Tokens only' => ['Tokens', '6', '0', 40, 2, ['Tokens']],
            'SMS only' => ['SMS', '6', '0', 5, 1, ['SMS']],
            'from yesterday' => ['', '1', '0', 35, 2, ['Tokens', 'SMS']],
            'up to two days ago' => ['', '6', '2', 10, 1, ['Tokens', 'SMS']],
            'a range with nothing in it' => ['SMS', '6', '2', 0, 0, ['SMS']],
        ];
    }

    /**
     * @param  list<string>  $meters
     */
    #[DataProvider('historyFilters')]
    public function test_usage_history_in_every_filter(string $type, string $fromDaysAgo, string $toDaysAgo, int $units, int $events, array $meters): void
    {
        $billable = $this->opsSubscriber('pro');
        $wallets = app(WalletUsageService::class);

        // 10 Tokens four days ago, 30 Tokens and 5 SMS today, plus a credit that is not usage.
        $wallets->debit($billable->fresh(), 'Tokens', 10);
        DB::table('transactions')->where('type', 'withdraw')->update([
            'created_at' => now()->subDays(4), 'updated_at' => now()->subDays(4),
        ]);
        $wallets->debit($billable->fresh(), 'Tokens', 30);
        $wallets->debit($billable->fresh(), 'SMS', 5);
        $wallets->credit($billable->fresh(), 'SMS', 100, 'coupon_credit');

        $this->browse(function (Browser $browser) use ($billable, $type, $fromDaysAgo, $toDaysAgo, $units, $events, $meters): void {
            $this->actAs($browser, $billable, '/billing/usage');
            $browser->waitFor('[dusk="usage-history-net"]', 15)->pause(500);

            if ($type !== '') {
                $browser->select('select[wire\\:model\\.live="usageType"]', $type)->pause(900);
            }

            $this->setDate($browser, 'dateFrom', now()->subDays((int) $fromDaysAgo)->format('Y-m-d'));
            $this->setDate($browser, 'dateTo', now()->subDays((int) $toDaysAgo)->format('Y-m-d'));
            $browser->pause(1200);
            $this->shot($browser, 'Usage history');

            // Consumption only: the 100 credited SMS are not "usage".
            expect($browser->text('[dusk="usage-history-net"]'))->toBe(number_format($units))
                ->and($browser->attribute('[dusk="usage-history-events"]', 'data-count'))->toBe((string) $events);

            // The meters follow the type filter.
            foreach (['Tokens', 'SMS'] as $slug) {
                expect($this->domCount($browser, '[dusk="usage-meter-'.$slug.'"]'))->toBe(in_array($slug, $meters, true) ? 1 : 0, 'meter '.$slug);
            }
        });
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: string}>
     */
    public static function thresholds(): array
    {
        // threshold %, Tokens used of Pro's 100, expected state of the meter
        return [
            'default 80 %, 60 used' => [80, 60, 'ok'],
            'default 80 %, 85 used' => [80, 85, 'warning'],
            'lowered to 50 %, 60 used' => [50, 60, 'warning'],
            'raised to 95 %, 85 used' => [95, 85, 'ok'],
            'everything used' => [80, 100, 'danger'],
        ];
    }

    #[DataProvider('thresholds')]
    public function test_usage_threshold_decides_when_the_meter_warns(int $threshold, int $used, string $state): void
    {
        $this->serverConfig('mollie-billing.usage_threshold_percent', $threshold);

        $billable = $this->opsSubscriber('pro');
        app(WalletUsageService::class)->debit($billable->fresh(), 'Tokens', $used);

        $this->browse(function (Browser $browser) use ($billable, $used, $state): void {
            $this->actAs($browser, $billable, '/billing/usage');
            $browser->waitFor('[dusk="usage-meter-Tokens"]', 15)->pause(500);

            expect($browser->attribute('[dusk="usage-meter-Tokens"]', 'data-state'))->toBe($state)
                ->and($browser->attribute('[dusk="usage-meter-Tokens"]', 'data-used-percent'))->toBe((string) $used);

            // …and the bar is the colour that state stands for.
            $colour = ['ok' => 'bg-emerald-500', 'warning' => 'bg-amber-500', 'danger' => 'bg-red-500'][$state];
            expect((string) $browser->attribute('[dusk="usage-meter-Tokens-used"]', 'class'))->toContain($colour);

            expect($browser->text('[dusk="usage-meter-Tokens"]'))
                ->toContain(__('billing::portal.usage_plan_remaining', ['remaining' => (string) (100 - $used), 'total' => '100']));

            $this->shot($browser, 'Meter: '.$state);
        });
    }

    public function test_a_failed_overage_charge_is_shown_as_open_until_a_renewal_collects_it(): void
    {
        $this->setRecurringOutcome('failed');

        $billable = $this->opsSubscriber('starter');
        app(WalletUsageService::class)->debit($billable->fresh(), 'SMS', 45);

        // 25 SMS over at 18 ct = 4.50 net, 5.36 gross. Three payments, three refusals.
        app(ChargeUsageOverageDirectly::class)->handle($billable->fresh());

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->deliverWebhook($this->latestFakePayment()['id']);
        }

        $meta = $billable->fresh()->getBillingSubscriptionMeta();

        expect($meta['usage_overage_status'] ?? null)->toBe(ChargeUsageOverageDirectly::STATUS_FAILED)
            ->and(DB::table('fake_mollie_payments')->where('status', 'failed')->count())->toBe(3)
            // The units are back on the wallet; the debt stands as money.
            ->and((int) $billable->fresh()->getWallet('SMS')->balanceInt)->toBe(0);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/usage');
            $browser->waitFor('[dusk="outstanding-overage"]', 15)->pause(400);

            expect($this->opsNormaliseMoney($browser->text('[dusk="outstanding-overage"]')))
                ->toContain($this->opsNormaliseMoney(BillingMoney::format(536)));

            // No overage badge: the wallet owes nothing.
            expect($browser->text('[dusk="usage-meter-SMS"]'))->not->toContain(__('billing::portal.overage'));
            $this->shot($browser, 'Open overage charge');

            // Further overage is blocked until the payment is settled, and the page says so.
            $browser->waitFor('[dusk="overage-locked"]', 10);
            expect($browser->text('[dusk="overage-locked"]'))->toContain(__('billing::portal.usage_overage_locked_past_due'));

            // The plan is paid: the application behind the subscription gate stays open.
            $browser->visit('/_wb/app')->waitForText('APP-AREA-OK', 15);
            $this->shot($browser, 'Application reachable with an overage debt');

            // The dashboard says what is owed and that nothing new can be booked …
            $browser->visit('/billing')->waitFor('[dusk="overage-debt-banner"]', 15)->pause(400);

            expect($this->opsNormaliseMoney($browser->text('[dusk="overage-debt-banner"]')))
                ->toContain($this->opsNormaliseMoney(BillingMoney::format(536)))
                ->toContain($this->opsNormaliseMoney(__('billing::portal.overage_debt_banner', ['amount' => BillingMoney::format(536)])));
            // The next charge is announced with the debt in it: 11.78 for the plan + 5.36.
            expect($browser->attribute('[data-next-charge]', 'data-next-charge'))->toBe('scheduled')
                ->and($this->opsNormaliseMoney($browser->text('[data-next-charge-debt]')))
                ->toContain($this->opsNormaliseMoney(__('billing::portal.next_charge.overage_debt')))
                ->toContain($this->opsNormaliseMoney(BillingMoney::format(536)))
                ->and($this->opsNormaliseMoney($browser->text('[data-next-charge-total]')))
                ->toContain($this->opsNormaliseMoney(BillingMoney::format(1178 + 536)));
            // … and does not offer to bring the next renewal forward: that period is paid.
            $browser->assertDontSee(__('billing::portal.force_charge_now'));
            $this->shot($browser, 'Dashboard with an open overage debt');

            // The addon page tells the customer why it will not take a booking.
            $browser->visit('/billing/addons')->waitForText(__('billing::portal.error_past_due_expansion'), 15);
            $this->shot($browser, 'Addons locked by the debt');
        });

        expect($billable->fresh()->getBillingSubscriptionStatus())->toBe(\GraystackIT\MollieBilling\Enums\SubscriptionStatus::PastDue)
            ->and(fn () => $billable->fresh()->enableBillingAddon('softdrinks'))
            ->toThrow(\GraystackIT\MollieBilling\Exceptions\PastDueExpansionNotAllowedException::class);

        // The card works again; the next renewal collects it.
        $this->setRecurringOutcome('paid');
        $this->travel(ChargeUsageOverageDirectly::RECOLLECT_AFTER_DAYS + 1)->days();
        $this->opsRenew($billable, '11.78');

        $collected = DB::table('fake_mollie_payments')->where('status', 'paid')->where('amount_value', '5.36')->first();
        expect($collected)->not->toBeNull();
        $this->deliverWebhook((string) $collected->id);

        expect(BillingInvoice::query()->where('mollie_payment_id', $collected->id)->sole()->amount_net)->toBe(450);
        expect($billable->fresh()->getBillingSubscriptionStatus())->toBe(\GraystackIT\MollieBilling\Enums\SubscriptionStatus::Active);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/usage');
            $browser->waitFor('[dusk="usage-meter-SMS"]', 15)->pause(400);

            expect($this->domCount($browser, '[dusk="outstanding-overage"]'))->toBe(0);

            // Paid: the lock is gone from the dashboard too.
            $browser->visit('/billing')->waitForText('Starter', 15)->pause(400);
            expect($this->domCount($browser, '[dusk="overage-debt-banner"]'))->toBe(0);
        });
    }

    public function test_the_application_closes_when_a_renewal_is_unpaid(): void
    {
        $billable = $this->opsSubscriber('starter');
        $meta = $billable->fresh()->getBillingSubscriptionMeta();
        $meta['past_due_since'] = now('UTC')->toIso8601String();
        $billable->fresh()->forceFill([
            'subscription_status' => \GraystackIT\MollieBilling\Enums\SubscriptionStatus::PastDue,
            'subscription_meta' => $meta,
        ])->save();

        $this->browse(function (Browser $browser) use ($billable): void {
            // Sent to the portal instead of the application …
            $this->actAs($browser, $billable, '/_wb/app');
            $this->waitForPathContains($browser, '/billing');
            $browser->assertDontSee('APP-AREA-OK');

            // … where the usage page says that overage is blocked.
            $browser->visit('/billing/usage')->waitFor('[dusk="overage-locked"]', 15);
            $this->shot($browser, 'Unpaid renewal: no application, no overage');
        });

        // And the wallet agrees: the 20 included SMS, not one more.
        app(WalletUsageService::class)->debit($billable->fresh(), 'SMS', 20);

        expect(fn () => app(WalletUsageService::class)->debit($billable->fresh(), 'SMS', 1))
            ->toThrow(\GraystackIT\MollieBilling\Exceptions\UsageLimitExceededException::class);
    }

    private function setDate(Browser $browser, string $property, string $value): void
    {
        $browser->script(sprintf(
            'const input = document.querySelector(%s); input.value = %s; input.dispatchEvent(new Event("input", {bubbles: true})); input.dispatchEvent(new Event("change", {bubbles: true}));',
            json_encode('input[wire\\:model\\.live\\.debounce\\.300ms="'.$property.'"]'),
            json_encode($value),
        ));
    }

    protected function tearDown(): void
    {
        $this->setIpCountry(null);
        $this->setRecurringOutcome('paid');

        parent::tearDown();
    }
}
