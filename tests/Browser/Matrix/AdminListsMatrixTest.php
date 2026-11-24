<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser\Matrix;

use GraystackIT\MollieBilling\Enums\AuditCategory;
use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\OssExportStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\MandateUpdated;
use GraystackIT\MollieBilling\Events\TrialStarted;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingOssExport;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Support\BillingAuditEntry;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Browser\BrowserTestCase;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\DataProvider;
use Workbench\App\FakeMollie\FakeMollie;

/**
 * The operator's lists and their actions: billables, the audit trail, the dashboard,
 * past-due, scheduled changes, refunds, country mismatches, OSS exports and bulk
 * actions. Every action is clicked the way an operator clicks it, and its effect is read
 * from the database, the test Mollie, or the screen.
 */
class AdminListsMatrixTest extends BrowserTestCase
{
    use OperationsWorkbench;

    // ── Billables list ──────────────────────────────────────────────────────

    public function test_billables_list_searches_filters_by_every_status_and_sorts_by_every_column(): void
    {
        $rows = [
            ['Alpha GmbH', 'zeta@list.test', 'starter', SubscriptionStatus::Active],
            ['Bravo AG', 'yankee@list.test', 'pro', SubscriptionStatus::Trial],
            ['Charlie KG', 'xray@list.test', 'business', SubscriptionStatus::PastDue],
            ['Delta OHG', 'whisky@list.test', 'starter', SubscriptionStatus::Cancelled],
            ['Echo SE', 'victor@list.test', null, SubscriptionStatus::Expired],
            ['Foxtrot UG', 'uniform@list.test', null, SubscriptionStatus::New],
        ];

        foreach ($rows as [$name, $email, $plan, $status]) {
            $this->makeBillable([
                'name' => $name,
                'email' => $email,
                'subscription_plan_code' => $plan,
                'subscription_status' => $status,
            ]);
        }

        $this->browse(function (Browser $browser) use ($rows): void {
            $this->loginAdmin($browser);
            $browser->visit('/billing/admin/billables')->waitForText('Alpha GmbH', 15)->pause(500);
            $this->shot($browser, 'Billables');

            // Every status the package has is a filter, and each shows exactly its rows.
            foreach (SubscriptionStatus::cases() as $status) {
                $browser->select('select[wire\\:model\\.live="statusFilter"]', $status->value)->pause(900);

                $expected = array_values(array_map(
                    static fn (array $row): string => $row[0],
                    array_filter($rows, static fn (array $row): bool => $row[3] === $status),
                ));

                expect($this->firstColumn($browser))->toEqualCanonicalizing($expected, 'status filter '.$status->value);
            }

            $browser->select('select[wire\\:model\\.live="statusFilter"]', '')->pause(900);

            // Search by name and by e-mail.
            foreach (['Charlie' => ['Charlie KG'], 'whisky@' => ['Delta OHG'], 'no-such-customer' => []] as $term => $expected) {
                $browser->type('input[type="search"]', $term)->pause(1200);
                expect($this->firstColumn($browser))->toBe($expected, 'search '.$term);
            }

            $browser->clear('input[type="search"]')->type('input[type="search"]', ' ')->pause(1200);

            // Every sortable column, both directions.
            $columns = [
                'name' => 0,
                'email' => 1,
                'subscription_plan_code' => 2,
                'subscription_status' => 3,
            ];

            // A billable without a plan shows a dash and sorts as the NULL it is: first.
            $sortedCopy = static function (array $values): array {
                usort($values, static fn (string $a, string $b): int => strcasecmp($a === '—' ? '' : $a, $b === '—' ? '' : $b));

                return $values;
            };

            // Wait for the order instead of a fixed pause: on a loaded machine the
            // second click's round trip once outlasted 900 ms and the "descending"
            // read still showed the ascending rows. A wrong order still fails — the
            // wait times out and the assertion names what the screen shows.
            $waitFor = static function (Browser $browser, callable $condition): void {
                try {
                    $browser->waitUsing(10, 100, $condition);
                } catch (\Facebook\WebDriver\Exception\TimeoutException) {
                    // fall through to the assertion for a readable diff
                }
            };

            foreach ($columns as $column => $index) {
                $this->clickWire($browser, '[wire\\:click="sort(\''.$column.'\')"]');
                $waitFor($browser, function () use ($browser, $index, $sortedCopy): bool {
                    $values = $this->column($browser, $index);

                    return $values === $sortedCopy($values);
                });
                $ascending = $this->column($browser, $index);

                $this->clickWire($browser, '[wire\\:click="sort(\''.$column.'\')"]');
                $waitFor($browser, fn (): bool => $this->column($browser, $index) === array_reverse($ascending));
                $descending = $this->column($browser, $index);

                expect($ascending)->toBe($sortedCopy($ascending), 'ascending by '.$column)
                    ->and($descending)->toBe(array_reverse($ascending), 'descending by '.$column);
            }

            $this->shot($browser, 'Sorted by status');
        });
    }

    public function test_billable_detail_names_the_plan_and_shows_the_mandate_mollie_holds(): void
    {
        $billable = $this->makeActiveSubscriber('pro', 'monthly');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->loginAdmin($browser);
            $browser->visit('/billing/admin/billables/'.$billable->getKey())
                ->waitForText('Acme GmbH', 15)->pause(600);

            // planName(): the catalog's name for the code, not the code.
            expect($this->opsTileValue($browser, __('billing::admin.common.plan')))->toBe('Pro');

            // mandate(): read live from (the test) Mollie — its id is on the card.
            $browser->assertSee((string) $billable->mollie_mandate_id);
            $browser->assertDontSee(__('billing::admin.billables.show.mandate_none'));
            $this->shot($browser, 'Billable detail');
        });

        // A billable without a mandate says so.
        $none = $this->makeBillable(['name' => 'No Mandate Ltd']);

        $this->browse(function (Browser $browser) use ($none): void {
            $browser->visit('/billing/admin/billables/'.$none->getKey())
                ->waitForText('No Mandate Ltd', 15)->pause(400)
                ->assertSee(__('billing::admin.billables.show.mandate_none'));

            // An unknown id is a "not found" page, not an error.
            $browser->visit('/billing/admin/billables/999999')
                ->waitForText(__('billing::admin.billables.show.not_found_title'), 15);
        });
    }

    // ── Audit tab ───────────────────────────────────────────────────────────

    public function test_audit_tab_filters_by_every_category(): void
    {
        $this->setIpCountry('NL');
        $billable = $this->makeActiveSubscriber('starter', 'monthly');

        // One real event per category, produced the way the package produces it.
        // Payment + Invoice + Compliance: a renewal from a foreign card.
        $subscriptionId = (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'];
        $payment = FakeMollie::chargeSubscription($subscriptionId);
        DB::table('fake_mollie_payments')->where('id', $payment['id'])->update(['country_code' => 'NL']);
        $this->deliverWebhook($payment['id']);

        // Usage + Coupon: a credits coupon.
        $coupon = $this->coupons()->create([
            'code' => 'AUDITCREDITS',
            'name' => 'Audit credits',
            'type' => \GraystackIT\MollieBilling\Enums\CouponType::Credits,
            'credits_payload' => ['Tokens' => 25],
        ]);
        $this->coupons()->redeem($coupon, $billable->fresh(), []);

        // Trial + PaymentMethod: the events themselves.
        event(new TrialStarted($billable->fresh(), 'starter', 14));
        event(new MandateUpdated($billable->fresh(), 'mdt_old', (string) $billable->mollie_mandate_id));

        // Subscription: a cancellation.
        $billable->fresh()->cancelBillingSubscription();

        $labels = [];
        foreach (BillingAuditEntry::model()::query()->get() as $activity) {
            $entry = new BillingAuditEntry($activity);
            $labels[(string) $entry->category()?->value][] = $entry->title();
        }

        // The world holds every category — otherwise the filter proves nothing.
        expect(array_keys($labels))->toEqualCanonicalizing(array_map(fn (AuditCategory $c): string => $c->value, AuditCategory::cases()));

        $this->browse(function (Browser $browser) use ($billable, $labels): void {
            $this->loginAdmin($browser);
            $this->opsOpenBillableTab($browser, $billable, 'audit');
            $this->shot($browser, 'Audit trail');

            foreach (AuditCategory::cases() as $category) {
                $browser->select('select[wire\\:model\\.live="category"]', $category->value)->pause(1000);

                $shown = $this->auditTitles($browser);

                // Exactly this category's entries, under the category's own label.
                expect($shown)->toEqualCanonicalizing($labels[$category->value], 'audit filter '.$category->value);
                expect($this->auditBadges($browser))->toBe([$category->label()], 'audit badge '.$category->value);
            }

            $this->shot($browser, 'Audit trail filtered');

            $browser->select('select[wire\\:model\\.live="category"]', '')->pause(1000);
            expect(count($this->auditTitles($browser)))->toBe(array_sum(array_map('count', $labels)));
        });
    }

    // ── Dashboard ───────────────────────────────────────────────────────────

    public function test_dashboard_tiles_show_the_figures_the_data_gives(): void
    {
        // Two paying subscribers with one renewal each: 9.90 + 29.00 net this month.
        $starter = $this->opsSubscriber('starter');
        $pro = $this->opsSubscriber('pro');
        $this->opsRenew($starter, '11.78');
        $this->opsRenew($pro, '34.51');

        // One past due, one with a change scheduled for tomorrow, one trial ending in two
        // days, one open mismatch.
        $this->makeBillable(['name' => 'Late Ltd', 'subscription_status' => SubscriptionStatus::PastDue]);
        $this->makeBillable(['name' => 'Soon Ltd', 'scheduled_change_at' => now()->addDay()]);
        $this->makeBillable(['name' => 'Later Ltd', 'scheduled_change_at' => now()->addWeeks(3)]);
        $this->makeBillable([
            'name' => 'Trial Ltd',
            'subscription_status' => SubscriptionStatus::Trial,
            'trial_ends_at' => now()->addDays(2),
        ]);

        BillingCountryMismatch::query()->create([
            'billable_type' => $starter->getMorphClass(),
            'billable_id' => $starter->getKey(),
            'tax_country_user' => 'DE',
            'tax_country_payment' => 'NL',
            'tax_country_ip' => 'NL',
            'status' => CountryMismatchStatus::Pending,
        ]);

        // The figures are cached (admin_kpi_cache_ttl) in a store both processes share, and
        // that store outlives the database reset between scenarios.
        \Illuminate\Support\Facades\Cache::flush();

        $this->browse(function (Browser $browser): void {
            $this->loginAdmin($browser);
            $browser->waitForText(__('billing::admin.dashboard.title'), 15)->pause(600);
            $this->shot($browser, 'Dashboard');

            $tile = fn (string $key): string => $this->opsNormaliseMoney($this->opsTileValue($browser, __('billing::admin.dashboard.'.$key)));
            $money = fn (int $minor): string => $this->opsNormaliseMoney(BillingMoney::format($minor));

            // MRR: the net invoiced in the last 30 days; ARR: twelve times that.
            expect($tile('mrr'))->toBe($money(990 + 2900))
                ->and($tile('arr'))->toBe($money((990 + 2900) * 12))
                ->and($tile('churn'))->toBe('0.0%')
                ->and($tile('past_due'))->toBe('1')
                ->and($tile('mismatches'))->toBe('1')
                // Only the change due within a week counts.
                ->and($tile('scheduled_changes'))->toBe('1')
                ->and($tile('trials_ending'))->toBe('1');

            // The tiles that link lead to their lists.
            $browser->clickLink(__('billing::admin.dashboard.past_due'))->waitForText('Late Ltd', 15);
        });
    }

    // ── Past due ────────────────────────────────────────────────────────────

    public function test_past_due_list_sorts_and_retries_the_open_overage_charge(): void
    {
        $this->setRecurringOutcome('failed');

        // Past due with an overage charge that failed and is waiting for its retry.
        $debtor = $this->opsSubscriber('starter');
        $debtor->forceFill(['name' => 'Zulu Debt GmbH'])->save();
        app(\GraystackIT\MollieBilling\Services\Wallet\WalletUsageService::class)->debit($debtor->fresh(), 'SMS', 45);
        $first = app(ChargeUsageOverageDirectly::class)->handle($debtor->fresh());
        $debtor->fresh()->forceFill(['subscription_status' => SubscriptionStatus::PastDue])->save();

        $this->makeBillable(['name' => 'Alpha Late GmbH', 'subscription_status' => SubscriptionStatus::PastDue]);
        $this->makeBillable(['name' => 'Not Late GmbH', 'subscription_status' => SubscriptionStatus::Active]);

        // The card works again.
        $this->setRecurringOutcome('paid');

        $this->browse(function (Browser $browser) use ($debtor): void {
            $this->loginAdmin($browser);
            $browser->visit('/billing/admin/past-due')->waitForText('Zulu Debt GmbH', 15)->pause(500);

            // Only past-due billables are listed.
            expect($this->firstColumn($browser, links: true))->toEqualCanonicalizing(['Zulu Debt GmbH', 'Alpha Late GmbH']);

            $this->clickWire($browser, '[wire\\:click="sort(\'name\')"]');
            $browser->pause(900);
            expect($this->firstColumn($browser, links: true))->toBe(['Alpha Late GmbH', 'Zulu Debt GmbH']);

            $this->clickWire($browser, '[wire\\:click="sort(\'name\')"]');
            $browser->pause(900);
            expect($this->firstColumn($browser, links: true))->toBe(['Zulu Debt GmbH', 'Alpha Late GmbH']);

            $this->clickWire($browser, '[wire\\:click="sort(\'updated_at\')"]');
            $browser->pause(900);
            $this->shot($browser, 'Past due');

            // Retry: a new payment for the same debt reaches Mollie.
            $this->clickWire($browser, '[wire\\:click="retry('.$debtor->getKey().')"]');
            $browser->waitForText(__('billing::admin.past_due.retry_dispatched', ['name' => 'Zulu Debt GmbH']), 20);
            $this->shot($browser, 'Retry dispatched');
        });

        $retry = $this->latestFakePayment();

        // 25 SMS over Starter's 20 at 18 ct = 4.50 net, 5.36 with 19 %.
        expect($retry['id'])->not->toBe($first->paymentId)
            ->and($retry['amount_value'])->toBe('5.36')
            ->and($retry['status'])->toBe('paid');
    }

    public function test_retry_starts_a_new_collection_for_an_overage_debt_whose_cycle_gave_up(): void
    {
        $this->setRecurringOutcome('failed');

        $debtor = $this->opsSubscriber('starter');
        $debtor->forceFill(['name' => 'Standing Debt GmbH'])->save();
        app(\GraystackIT\MollieBilling\Services\Wallet\WalletUsageService::class)->debit($debtor->fresh(), 'SMS', 45);

        // 25 SMS over at 18 ct = 4.50 net, 5.36 gross. Three payments, three refusals.
        app(ChargeUsageOverageDirectly::class)->handle($debtor->fresh());

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->deliverWebhook($this->latestFakePayment()['id']);
        }

        expect($debtor->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::PastDue)
            ->and($debtor->fresh()->getBillingSubscriptionMeta()['usage_overage_status'] ?? null)->toBe(ChargeUsageOverageDirectly::STATUS_FAILED)
            ->and(DB::table('fake_mollie_payments')->where('amount_value', '5.36')->count())->toBe(3);

        // The operator has spoken to the customer: the card works again.
        $this->setRecurringOutcome('paid');

        $this->browse(function (Browser $browser) use ($debtor): void {
            $this->loginAdmin($browser);
            $browser->visit('/billing/admin/past-due')->waitForText('Standing Debt GmbH', 15)->pause(500);

            $this->clickWire($browser, '[wire\\:click="retry('.$debtor->getKey().')"]');
            $browser->waitForText(__('billing::admin.past_due.debt_collection_started', ['name' => 'Standing Debt GmbH']), 20);
            $this->shot($browser, 'Debt collection started');

            // One more payment for the same debt — and a second click does not make another.
            expect(DB::table('fake_mollie_payments')->where('amount_value', '5.36')->count())->toBe(4);

            $this->clickWire($browser, '[wire\\:click="retry('.$debtor->getKey().')"]');
            $browser->waitForText(__('billing::admin.past_due.debt_collection_in_flight', ['name' => 'Standing Debt GmbH']), 20);
            $this->shot($browser, 'Debt collection already in flight');
        });

        expect(DB::table('fake_mollie_payments')->where('amount_value', '5.36')->count())->toBe(4)
            ->and((int) ($debtor->fresh()->getBillingSubscriptionMeta()[ChargeUsageOverageDirectly::RECOLLECTIONS_KEY] ?? 0))->toBe(1);

        $paid = DB::table('fake_mollie_payments')->where('amount_value', '5.36')->where('status', 'paid')->sole();
        $this->deliverWebhook((string) $paid->id);

        // Paid: invoiced once, the debt is gone and so is the lock.
        expect((int) BillingInvoice::query()->where('mollie_payment_id', $paid->id)->sole()->amount_net)->toBe(450)
            ->and($debtor->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
            ->and($debtor->fresh()->getBillingSubscriptionMeta())->not->toHaveKey('usage_overage');

        $this->browse(function (Browser $browser): void {
            $browser->visit('/billing/admin/past-due')->pause(1200);
            $browser->assertDontSee('Standing Debt GmbH');
        });
    }

    // ── Scheduled changes ───────────────────────────────────────────────────

    public function test_scheduled_changes_list_sorts_cancels_and_applies(): void
    {
        $schedule = function (string $name, string $targetPlan, int $inDays): TestBillable {
            $billable = $this->opsSubscriber('business');
            $billable->forceFill(['name' => $name])->save();
            $this->opsRenew($billable, '117.81');

            app(\GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange::class)
                ->schedule($billable->fresh(), ['plan_code' => $targetPlan]);

            $billable->fresh()->forceFill(['scheduled_change_at' => now()->addDays($inDays)])->save();

            return $billable->fresh();
        };

        $cancelled = $schedule('Bravo Downgrade GmbH', 'pro', 9);
        $applied = $schedule('Alpha Downgrade GmbH', 'starter', 3);

        $this->browse(function (Browser $browser) use ($cancelled, $applied): void {
            $this->loginAdmin($browser);
            $browser->visit('/billing/admin/scheduled-changes')->waitForText('Alpha Downgrade GmbH', 15)->pause(500);
            $this->shot($browser, 'Scheduled changes');

            // Default: soonest first.
            expect($this->firstColumn($browser, links: true))->toBe(['Alpha Downgrade GmbH', 'Bravo Downgrade GmbH']);

            $this->clickWire($browser, '[wire\\:click="sort(\'scheduled_change_at\')"]');
            $browser->pause(900);
            expect($this->firstColumn($browser, links: true))->toBe(['Bravo Downgrade GmbH', 'Alpha Downgrade GmbH']);

            $this->clickWire($browser, '[wire\\:click="sort(\'name\')"]');
            $browser->pause(900);
            expect($this->firstColumn($browser, links: true))->toBe(['Alpha Downgrade GmbH', 'Bravo Downgrade GmbH']);

            // Cancel: the change is gone, the plan stays.
            $this->clickWire($browser, '[wire\\:click="cancel('.$cancelled->getKey().')"]');
            $browser->pause(300);
            $this->acceptDialogIfOpen($browser);
            $browser->waitForText(__('billing::admin.scheduled_changes.cancelled'), 20)->pause(500);

            // Apply now: the change happens today.
            $this->clickWire($browser, '[wire\\:click="applyNow('.$applied->getKey().')"]');
            $browser->pause(300);
            $this->acceptDialogIfOpen($browser);
            // This change costs money, so the screen says it has been started, not done.
            $browser->waitForText(__('billing::admin.scheduled_changes.applied_pending_payment'), 30)->pause(500);
            $this->shot($browser, 'Applied');
        });

        // The charge it started is at the test Mollie; the plan moves when that settles.
        $charge = (array) DB::table('fake_mollie_payments')->where('metadata', 'like', '%prorata_charge%')->sole();
        expect($applied->fresh()->subscription_plan_code)->toBe('business');

        $this->deliverWebhook($charge['id']);

        $invoice = BillingInvoice::query()->where('mollie_payment_id', $charge['id'])->sole();
        expect(BillingMoney::mollieAmount((int) $invoice->amount_gross))->toBe($charge['amount_value']);

        $cancelled->refresh();
        $applied->refresh();

        expect($cancelled->subscription_plan_code)->toBe('business')
            ->and($cancelled->scheduled_change_at)->toBeNull()
            ->and($cancelled->getBillingSubscriptionMeta())->not->toHaveKey('scheduled_change')
            ->and($applied->subscription_plan_code)->toBe('starter')
            ->and($applied->scheduled_change_at)->toBeNull();
    }

    // ── Refunds list ────────────────────────────────────────────────────────

    public function test_refunds_list_sorts_by_every_column_and_opens_the_credit_note(): void
    {
        $service = app(\GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService::class);

        $small = $this->opsRenew($this->opsSubscriber('starter'), '11.78');
        $large = $this->opsRenew($this->opsSubscriber('pro'), '34.51');

        $service->refundPartially($small, 300, \GraystackIT\MollieBilling\Enums\RefundReasonCode::Goodwill, null);
        $this->travel(2)->minutes();
        $service->refundFully($large, \GraystackIT\MollieBilling\Enums\RefundReasonCode::ServiceOutage, 'Outage on the 3rd');

        $notes = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->orderBy('id')->get();
        [$smallNote, $largeNote] = [$notes[0], $notes[1]];

        $this->browse(function (Browser $browser) use ($smallNote, $largeNote, $large): void {
            $this->loginAdmin($browser);
            $browser->visit('/billing/admin/refunds')->waitForText($smallNote->serial_number, 15)->pause(500);

            $serials = fn (): array => $this->column($browser, 1);

            // Default: newest first.
            expect($serials())->toBe([$largeNote->serial_number, $smallNote->serial_number]);

            $this->clickWire($browser, '[wire\\:click="sort(\'serial_number\')"]');
            $browser->pause(900);
            expect($serials())->toBe([$smallNote->serial_number, $largeNote->serial_number]);

            // By amount: −34.51 sorts before −3.57 ascending.
            $this->clickWire($browser, '[wire\\:click="sort(\'amount_gross\')"]');
            $browser->pause(900);
            expect($serials())->toBe([$largeNote->serial_number, $smallNote->serial_number]);

            $this->clickWire($browser, '[wire\\:click="sort(\'created_at\')"]');
            $browser->pause(900);
            expect($serials())->toBe([$smallNote->serial_number, $largeNote->serial_number]);
            $this->shot($browser, 'Refunds');

            // The detail names the invoice it credits, the reason and its text.
            $this->clickWire($browser, '[wire\\:click="openDetail('.$largeNote->id.')"]');
            $browser->waitForText(\GraystackIT\MollieBilling\Enums\RefundReasonCode::ServiceOutage->label(), 15)->pause(300)
                ->assertSee('Outage on the 3rd')
                ->assertSee((string) $large->serial_number);
            expect($this->opsSeesMoney($browser, (int) $largeNote->amount_gross))->toBeTrue();
            $this->shot($browser, 'Refund detail');
        });
    }

    // ── Invoices ────────────────────────────────────────────────────────────

    public function test_invoice_list_filters_by_range_exports_a_zip_and_leads_to_the_billable(): void
    {
        $acme = $this->opsSubscriber('starter');
        $globex = $this->opsSubscriber('pro');

        $current = $this->opsRenew($acme, '11.78');
        $older = $this->opsRenew($globex, '34.51');

        // One invoice into last month, so the quick filters have something to tell apart.
        $lastMonth = \GraystackIT\MollieBilling\Support\BillingTime::nowUtc()->startOfMonth()->subMonthNoOverflow()->addDays(4)->setTime(10, 0);
        DB::table('billing_invoices')->where('id', $older->id)->update(['created_at' => $lastMonth->format('Y-m-d H:i:s')]);

        $expected = function (?string $preset): array {
            [$from, $to] = $preset !== null ? \GraystackIT\MollieBilling\Support\AdminInvoiceFilter::presetRange($preset) : [null, null];

            return (new \GraystackIT\MollieBilling\Support\AdminInvoiceFilter($from, $to))->query()
                ->orderByDesc('created_at')->orderByDesc('id')
                ->get()->map(fn (BillingInvoice $i) => $i->serial_number ?? '#'.$i->id)->all();
        };

        expect($expected('last_month'))->toBe([$older->serial_number])
            ->and($expected('this_month'))->toContain($current->serial_number);

        $this->browse(function (Browser $browser) use ($acme, $current, $older, $expected, $lastMonth): void {
            $this->loginAdmin($browser);
            $browser->visit('/billing/admin/invoices')->waitForText((string) $current->serial_number, 15)->pause(500);

            $serials = fn (): array => $this->column($browser, 1);

            expect($serials())->toBe($expected(null));
            // Every time says it is UTC, and so does the header.
            $browser->assertSee($lastMonth->format('Y-m-d').' 10:00 UTC')
                ->assertSee(__('billing::admin.utc.badge'));
            $this->shot($browser, 'Invoices');

            $browser->click('[dusk="invoice-preset-last_month"]')->pause(900);
            expect($serials())->toBe($expected('last_month'));

            $browser->click('[dusk="invoice-preset-this_month"]')->pause(900);
            expect($serials())->toBe($expected('this_month'));
            $this->shot($browser, 'Invoices: this month');

            // The filter is in the URL: a bookmarked range opens as it was left.
            [$from, $to] = \GraystackIT\MollieBilling\Support\AdminInvoiceFilter::presetRange('last_month');
            $browser->visit('/billing/admin/invoices?from='.$from.'&to='.$to)->waitForText((string) $older->serial_number, 15)->pause(500);
            expect($serials())->toBe([$older->serial_number]);

            // The export link carries that range and answers with a ZIP.
            $href = (string) $browser->attribute('[dusk="invoice-export"]', 'href');
            expect($href)->toContain('from='.$from)->toContain('to='.$to);

            $zip = $this->opsFetch($browser, $href);
            expect($zip['status'])->toBe(200)
                ->and($zip['type'])->toContain('zip')
                ->and($zip['head'])->toStartWith('PK');

            $this->clickWire($browser, '[wire\\:click="clearFilters"]');
            $browser->pause(900);
            expect($serials())->toBe($expected(null));

            $this->clickWire($browser, '[wire\\:click="sort(\'amount_gross\')"]');
            $browser->pause(900);
            expect($serials()[0])->not->toBe($older->serial_number);

            // The detail, and its PDF shown in the browser rather than downloaded.
            $this->clickWire($browser, 'button[wire\\:click="openDetail('.$current->id.')"]');
            $browser->waitForText(__('billing::admin.invoices.detail.date'), 15)->pause(300);
            expect($this->opsSeesMoney($browser, (int) $current->amount_gross))->toBeTrue();
            $this->shot($browser, 'Invoice detail');

            $view = $this->opsFetch($browser, route('billing.admin.invoice.view', ['invoice' => $current]));
            expect($view['status'])->toBe(200)
                ->and($view['type'])->toContain('pdf')
                ->and($view['head'])->toStartWith('%PDF');

            // The row's action menu leads to the billable the invoice belongs to.
            $browser->visit('/billing/admin/invoices')->waitForText((string) $current->serial_number, 15)->pause(500);
            $this->clickWire($browser, '[dusk="invoice-actions-'.$current->id.'"]');
            $browser->waitFor('[dusk="invoice-billable-'.$current->id.'"]', 10);
            $this->clickWire($browser, '[dusk="invoice-billable-'.$current->id.'"]');
            $browser->waitForLocation('/billing/admin/billables/'.$acme->getKey(), 15);
        });
    }

    // ── Country mismatches ──────────────────────────────────────────────────

    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function mismatchResolutions(): array
    {
        // how the country is chosen, the country, gross of the reissued 9.90
        return [
            'the declared country (DE, 19 %)' => ['user', 'DE', 1178],
            'the payment country (FR, 20 %)' => ['payment', 'FR', 1188],
            'the IP country (NL, 21 %)' => ['ip', 'NL', 1198],
            'a country entered by hand (AT, 20 %)' => ['manual', 'AT', 1188],
        ];
    }

    #[DataProvider('mismatchResolutions')]
    public function test_mismatch_is_resolved_with_a_justification_and_the_invoice_reissued(string $via, string $country, int $reissuedGross): void
    {
        $this->setIpCountry('NL');
        $billable = $this->makeActiveSubscriber('starter', 'monthly', ['tax_country_ip' => 'NL']);

        // Declared DE, card FR, IP NL.
        $subscriptionId = (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'];
        $payment = FakeMollie::chargeSubscription($subscriptionId);
        DB::table('fake_mollie_payments')->where('id', $payment['id'])->update(['country_code' => 'FR']);
        $this->deliverWebhook($payment['id']);

        $mismatch = BillingCountryMismatch::query()->sole();
        $original = BillingInvoice::query()->sole();

        expect($mismatch->status)->toBe(CountryMismatchStatus::Pending)
            ->and((int) $original->amount_gross)->toBe(1178);

        $this->browse(function (Browser $browser) use ($billable, $mismatch, $via, $country): void {
            // While it is open, the customer cannot book anything new.
            $this->actAs($browser, $billable, '/billing/checkout');
            $this->waitForPathContains($browser, '/billing');
            $browser->pause(600);
            expect((string) parse_url($browser->driver->getCurrentURL(), PHP_URL_PATH))->not->toContain('checkout');

            $this->loginAdmin($browser);
            $browser->visit('/billing/admin/mismatches')->waitForText('Acme GmbH', 15)->pause(500);
            $this->shot($browser, 'Open mismatch');

            // Sorting the pending tab by each country column keeps the row.
            foreach (['tax_country_user', 'tax_country_payment', 'tax_country_ip'] as $column) {
                $this->clickWire($browser, '[wire\\:click="sort(\''.$column.'\')"]');
                $browser->pause(700)->assertSee('Acme GmbH');
            }

            // Open, think better of it, and close again: nothing happens.
            $this->clickWire($browser, '[wire\\:click="openResolve('.$mismatch->id.')"]');
            $browser->waitFor('textarea[wire\\:model="resolvingJustification"]', 10)->pause(500);
            $browser->script('window.Livewire.find(document.querySelector(\'textarea[wire\\\\:model="resolvingJustification"]\').closest("[wire\\\\:id]").getAttribute("wire:id")).call("cancelResolve"); window.Flux.modal("resolve-mismatch").close();');
            $browser->pause(800);
            expect(BillingCountryMismatch::query()->sole()->status)->toBe(CountryMismatchStatus::Pending);

            $this->clickWire($browser, '[wire\\:click="openResolve('.$mismatch->id.')"]');
            $browser->waitFor('textarea[wire\\:model="resolvingJustification"]', 10)->pause(500);

            // The three signals are offered by name.
            $browser->assertSee(__('billing::admin.mismatches.modal.user_option', ['country' => 'DE']))
                ->assertSee(__('billing::admin.mismatches.modal.payment_option', ['country' => 'FR']))
                ->assertSee(__('billing::admin.mismatches.modal.ip_option', ['country' => 'NL']));

            if ($via === 'manual') {
                $browser->type('input[wire\\:model\\.live="resolvingChosen"]', $country);
            } else {
                $this->jsClick($browser, 'ui-radio[value="'.$country.'"], input[type="radio"][value="'.$country.'"], [data-flux-radio][value="'.$country.'"]');
            }

            $browser->pause(600);

            // Without a justification it is refused.
            $this->clickWire($browser, '[wire\\:click="confirmResolve"]');
            $browser->waitForText(__('billing::admin.mismatches.modal.justification_required'), 15);
            $this->shot($browser, 'Justification required');

            $browser->type('textarea[wire\\:model="resolvingJustification"]', 'Customer sent a utility bill, ticket 815');
            $this->clickWire($browser, '[wire\\:click="confirmResolve"]');
            $browser->waitForText(__('billing::admin.mismatches.modal.resolved'), 30)->pause(800);
            $this->shot($browser, 'Resolved');

            // It has moved to the resolved tab, which sorts by the resolution date.
            $browser->press(__('billing::admin.mismatches.tab_resolved'))->pause(1000);
            $browser->assertSee('Acme GmbH');
            $this->clickWire($browser, '[wire\\:click="sort(\'resolved_at\')"]');
            $browser->pause(700)->assertSee('Acme GmbH');
            $this->shot($browser, 'Resolved tab');
        });

        $mismatch->refresh();

        expect($mismatch->status)->toBe(CountryMismatchStatus::Resolved)
            ->and($mismatch->chosen_country)->toBe($country)
            ->and(TestBillable::query()->find($billable->getKey())->getBillingCountry())->toBe($country);

        // The operator's reasoning is kept with the verified country.
        $verified = TestBillable::query()->find($billable->getKey())->getBillingSubscriptionMeta()['tax_country_verified'] ?? [];

        expect($verified['country'] ?? null)->toBe($country)
            ->and($verified['justification'] ?? null)->toBe('Customer sent a utility bill, ticket 815');

        // The wrongly rated invoice was paid back in full at the test Mollie …
        $refunds = DB::table('fake_mollie_refunds')->get();

        expect($refunds)->toHaveCount(1)
            ->and((string) $refunds->first()->amount_value)->toBe('11.78')
            ->and(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->sum('amount_gross'))->toBe(-1178);

        // … and charged again at the chosen country's rate.
        $reissue = (array) DB::table('fake_mollie_payments')->where('metadata', 'like', '%country_correction%')->sole();

        expect($reissue['amount_value'])->toBe(BillingMoney::mollieAmount($reissuedGross));

        // Settled, it is booked as an invoice of exactly that amount in the chosen country.
        $this->deliverWebhook($reissue['id']);

        $reissued = BillingInvoice::query()->where('mollie_payment_id', $reissue['id'])->sole();

        expect((int) $reissued->amount_gross)->toBe($reissuedGross)
            ->and((string) $reissued->country)->toBe($country);
    }

    // ── OSS exports ─────────────────────────────────────────────────────────

    public function test_oss_export_is_generated_downloaded_and_every_status_is_shown(): void
    {
        // An invoice in scope: a German consumer billed by the Austrian seller.
        $this->opsRenew($this->opsSubscriber('starter'), '11.78');
        $year = (int) now()->format('Y');

        $this->browse(function (Browser $browser) use ($year): void {
            $this->loginAdmin($browser);
            $browser->visit('/billing/admin/oss')->waitForText((string) $year, 15)->pause(500);
            $this->shot($browser, 'OSS: nothing generated yet');

            // Generate: the queue is sync, so the export is ready when the action returns.
            $this->clickWire($browser, '[wire\\:click="generate('.$year.')"]');
            $browser->waitForText(__('billing::admin.oss.queued', ['year' => $year]), 20)->pause(800);
            $browser->assertSee(OssExportStatus::Ready->label());
            $this->shot($browser, 'OSS: ready');

            // Download: a CSV that carries the German sale.
            $href = (string) $browser->attribute('a[href*="/oss/"]', 'href');
            $download = $this->opsFetch($browser, $href);

            expect($download['status'])->toBe(200)
                ->and($download['type'])->toContain('csv')
                ->and($download['length'])->toBeGreaterThan(20);
        });

        $export = BillingOssExport::query()->sole();

        expect($export->status)->toBe(OssExportStatus::Ready)
            ->and((int) $export->rows_count)->toBe(1);

        // The other three states, as rows an operator would find.
        // (The failed run is this year's latest, which is where its reason is shown.)
        BillingOssExport::query()->create(['year' => $year, 'status' => OssExportStatus::Failed, 'failure_reason' => 'Disk full while writing the export']);
        BillingOssExport::query()->create(['year' => $year - 2, 'status' => OssExportStatus::Processing]);
        $queued = BillingOssExport::query()->create(['year' => $year - 3, 'status' => OssExportStatus::Queued]);

        $this->browse(function (Browser $browser) use ($year): void {
            $browser->visit('/billing/admin/oss')->waitForText(OssExportStatus::Failed->label(), 15)->pause(500);

            foreach (OssExportStatus::cases() as $status) {
                $browser->assertSee($status->label());
            }

            $browser->assertSee('Disk full while writing the export');
            $this->shot($browser, 'OSS: every status');

            // A run is in flight for this install: the same year is refused meanwhile.
            BillingOssExport::query()->where('year', $year)->update(['status' => OssExportStatus::Processing->value, 'updated_at' => now()]);
            $browser->visit('/billing/admin/oss')->waitForText(__('billing::admin.oss.generating'), 15)->pause(400);
            $browser->script('window.Livewire.find(document.querySelector("[wire\\\\:id]").getAttribute("wire:id")).call("generate", '.$year.');');
            $browser->waitForText(__('billing::admin.oss.already_running'), 15);
        });

        // A run that hangs is reclaimed: a day later the year can be generated again.
        \Illuminate\Support\Facades\DB::table('billing_oss_exports')->where('year', $year)->update([
            'status' => OssExportStatus::Processing->value,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        $queued->delete();

        $this->browse(function (Browser $browser) use ($year): void {
            $browser->visit('/billing/admin/oss')->pause(1200);
            $browser->script('window.Livewire.find(document.querySelector("[wire\\\\:id]").getAttribute("wire:id")).call("generate", '.$year.');');
            $browser->waitForText(__('billing::admin.oss.queued', ['year' => $year]), 20)->pause(600);
            $this->shot($browser, 'OSS: stuck run reclaimed');
        });

        // Both abandoned rows of the year were closed as failed; the new run is ready.
        expect(BillingOssExport::query()->where('year', $year)->where('status', OssExportStatus::Ready)->count())->toBe(1)
            ->and(BillingOssExport::query()->where('year', $year)->where('status', OssExportStatus::Failed)->count())->toBe(2);
    }

    // ── Bulk actions ────────────────────────────────────────────────────────

    public function test_bulk_credit_reaches_every_billable_on_the_plan_and_only_those(): void
    {
        $one = $this->opsSubscriber('pro');
        $two = $this->opsSubscriber('pro');
        $other = $this->opsSubscriber('starter');

        $this->browse(function (Browser $browser): void {
            $this->loginAdmin($browser);
            $browser->visit('/billing/admin/bulk')->waitFor('select[wire\\:model\\.live="planFilter"]', 15)->pause(500);

            $browser->select('select[wire\\:model\\.live="planFilter"]', 'pro')->pause(600)
                ->select('select[wire\\:model="creditType"]', 'SMS')
                ->type('input[wire\\:model="creditUnits"]', '40');

            // A credit for a whole plan needs a reason.
            $this->clickWire($browser, '[wire\\:click="creditWallets"]');
            $browser->pause(300)->acceptDialog()->waitForText(__('billing::admin.bulk.reason_required'), 15);

            $browser->type('[wire\\:model="creditReason"]', 'Outage compensation');
            $this->clickWire($browser, '[wire\\:click="creditWallets"]');
            $browser->pause(300)->acceptDialog()
                ->waitForText(__('billing::admin.bulk.credited', ['count' => 2]), 20);
            $this->shot($browser, 'Bulk credit');
        });

        // Pro includes 50 SMS; Starter 20.
        expect((int) $one->fresh()->getWallet('SMS')->balanceInt)->toBe(90)
            ->and((int) $two->fresh()->getWallet('SMS')->balanceInt)->toBe(90)
            ->and((int) $other->fresh()->getWallet('SMS')->balanceInt)->toBe(20)
            // Registered as purchased, so a reset does not take it back.
            ->and(\GraystackIT\MollieBilling\Services\Wallet\WalletUsageService::getPurchasedBalance($one->fresh()->getWallet('SMS')))->toBe(40);
    }

    public function test_bulk_trial_extension_moves_every_trial_on_the_plan_and_no_paying_customer(): void
    {
        $trialing = $this->opsSubscriber('pro');
        $trialing->forceFill([
            'subscription_status' => SubscriptionStatus::Trial,
            'trial_ends_at' => now()->addDays(3),
        ])->save();

        $paying = $this->opsSubscriber('pro');

        $this->browse(function (Browser $browser): void {
            $this->loginAdmin($browser);
            $browser->visit('/billing/admin/bulk')->waitFor('select[wire\\:model\\.live="planFilter"]', 15)->pause(500);

            $browser->select('select[wire\\:model\\.live="planFilter"]', 'pro')->pause(600)
                ->type('input[wire\\:model="trialDays"]', '10');

            $this->clickWire($browser, '[wire\\:click="extendTrials"]');
            $browser->pause(300)->acceptDialog()
                ->waitForText(__('billing::admin.bulk.extended', ['count' => 1]), 20);
            $this->shot($browser, 'Bulk trial extension');

            // A plan nobody is trialling on says so instead of reporting success.
            $browser->select('select[wire\\:model\\.live="planFilter"]', 'business')->pause(600);
            $this->clickWire($browser, '[wire\\:click="extendTrials"]');
            $browser->pause(300)->acceptDialog()
                ->waitForText(__('billing::admin.bulk.no_matching_trials'), 20);
        });

        expect($trialing->fresh()->trial_ends_at->toDateString())->toBe(now()->addDays(10)->toDateString())
            ->and($paying->fresh()->subscription_status)->toBe(SubscriptionStatus::Active)
            ->and($paying->fresh()->trial_ends_at)->toBeNull();

        // Mollie's charge date of the trial followed the new trial end.
        $subscriptionId = (string) $trialing->fresh()->getBillingSubscriptionMeta()['mollie_subscription_id'];
        $subscription = DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->first();

        expect((string) $subscription->start_date)->toBe(now()->addDays(10)->toDateString());
    }

    // ── Reading tables ──────────────────────────────────────────────────────

    /**
     * @return list<string> the text of one column, top to bottom
     */
    private function column(Browser $browser, int $index): array
    {
        return array_values(array_filter(array_map('trim', (array) $browser->script(
            'return Array.from(document.querySelectorAll("table tbody tr")).map(r => (r.children['.$index.']?.innerText || "").split("\n")[0]);'
        )[0]), static fn (string $value): bool => true));
    }

    /**
     * @return list<string>
     */
    private function firstColumn(Browser $browser, bool $links = false): array
    {
        if ($links) {
            return array_values(array_map('trim', (array) $browser->script(
                'return Array.from(document.querySelectorAll("table tbody tr")).map(r => (r.querySelector("a")?.innerText || r.children[0].innerText).split("\n")[0]);'
            )[0]));
        }

        return $this->column($browser, 0);
    }

    /** @return list<string> */
    private function auditTitles(Browser $browser): array
    {
        return array_values(array_map('trim', (array) $browser->script(
            'return Array.from(document.querySelectorAll("ol > li")).map(li => li.querySelector("[data-flux-text]")?.innerText || "");'
        )[0]));
    }

    /** @return list<string> the distinct category badges on screen */
    private function auditBadges(Browser $browser): array
    {
        return array_values(array_unique(array_map('trim', (array) $browser->script(
            'return Array.from(document.querySelectorAll("ol > li [data-flux-badge]")).map(b => b.innerText);'
        )[0])));
    }

    private function acceptDialogIfOpen(Browser $browser): void
    {
        try {
            $browser->driver->switchTo()->alert()->accept();
        } catch (\Throwable) {
            // No confirmation on this action.
        }
    }

    protected function tearDown(): void
    {
        $this->setIpCountry(null);
        $this->setRecurringOutcome('paid');

        parent::tearDown();
    }
}
