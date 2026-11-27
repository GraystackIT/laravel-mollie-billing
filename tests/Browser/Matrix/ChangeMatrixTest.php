<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser\Matrix;

use Carbon\Carbon;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\PlanWaiver;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\ChargeDeferral;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Browser\BrowserTestCase;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Changes to a running subscription, in the browser, as a matrix:
 * change kind × discount state.
 *
 * Every case walks the same chain and compares the links to each other:
 *
 *   the amount the screen shows as due now      == what the test Mollie was asked to collect
 *   the amount the screen shows as coming back  == what the test Mollie was asked to refund
 *   both                                        == what the invoice and the credit note book
 *   and the recurring amount the test Mollie holds afterwards
 *     == what the next renewal is invoiced at, with no adjustment line.
 *
 * The subscriber's current period was paid by a renewal a moment ago, so every pro-rata
 * factor is 1 and the numbers on screen are whole prices — what is being tested is that
 * the links agree, not the day arithmetic (that is the feature matrix,
 * tests/Feature/Subscription/ChangeMatrix*Test.php).
 *
 * Workbench catalog, DE at 19 %: pro 29.00 (3 seats incl., 9.90/seat), business 99.00
 * (10 incl., 7.90/seat), ops-team 49.00 (2 incl., 7.00/seat), addons softdrinks 4.90,
 * priority-support 9.90, ops-crm 15.00 + 3.00/seat.
 */
class ChangeMatrixTest extends BrowserTestCase
{
    use CouponWorkbench;
    use OperationsWorkbench;

    /** @return array<string, array{0: string, 1: string}> */
    public static function cells(): array
    {
        $kinds = [
            'seat_up', 'seat_down', 'addon_on', 'addon_off', 'seat_addon_on', 'seat_addon_off',
            'plan_up', 'plan_down', 'plan_down_keep_seats', 'interval_to_yearly', 'interval_to_monthly',
        ];
        $discounts = ['pct', 'fixed', 'full', 'waiver_end', 'waiver_paid', 'waiver_periods', 'waiver_gift'];

        $cells = [];

        // Every change kind without a discount …
        foreach ($kinds as $kind) {
            $cells["{$kind} / none"] = [$kind, 'none'];
        }

        // … and every discount state with the kinds that move money in each direction.
        foreach ($discounts as $discount) {
            foreach (['seat_up', 'seat_down', 'addon_on', 'addon_off', 'plan_up', 'plan_down'] as $kind) {
                $cells["{$kind} / {$discount}"] = [$kind, $discount];
            }
        }

        // An interval change is refused under a waiver (its own test below, one cell per
        // waiver state and direction); under every coupon state it is a matrix cell, in
        // both directions.
        foreach (['pct', 'fixed', 'full'] as $discount) {
            $cells["interval_to_yearly / {$discount}"] = ['interval_to_yearly', $discount];
            $cells["interval_to_monthly / {$discount}"] = ['interval_to_monthly', $discount];
        }

        return $cells;
    }

    #[DataProvider('cells')]
    public function test_shown_amount_is_charged_booked_and_renewed(string $kind, string $discount): void
    {
        [$plan, $seats, $addons, $interval] = match ($kind) {
            'seat_down' => ['pro', 5, [], 'monthly'],
            'addon_off' => ['pro', 4, ['softdrinks'], 'monthly'],
            'seat_addon_on' => ['ops-team', 4, [], 'monthly'],
            'seat_addon_off' => ['ops-team', 4, ['ops-crm'], 'monthly'],
            'plan_down', 'plan_down_keep_seats' => ['business', 12, [], 'monthly'],
            'interval_to_monthly' => ['pro', 4, [], 'yearly'],
            default => ['pro', 4, [], 'monthly'],
        };

        $billable = $this->changeSubscriber($plan, $seats, $addons, $interval, $discount);
        $before = $this->documentIds($billable);

        $shown = ['charge' => 0, 'refund' => 0];

        $this->browse(function (Browser $browser) use ($billable, $kind, $discount, &$shown): void {
            $shown = match ($kind) {
                'seat_up' => $this->changeSeats($browser, $billable, +1),
                'seat_down' => $this->changeSeats($browser, $billable, -1),
                // Under a gift the softdrinks are already there; the addon to buy is the other one.
                'addon_on' => $this->toggleAddon($browser, $billable, $discount === 'waiver_gift' ? 'priority-support' : 'softdrinks', enable: true),
                'addon_off' => $this->toggleAddon($browser, $billable, 'softdrinks', enable: false),
                'seat_addon_on' => $this->toggleAddon($browser, $billable, 'ops-crm', enable: true),
                'seat_addon_off' => $this->toggleAddon($browser, $billable, 'ops-crm', enable: false),
                'plan_up' => $this->changePlan($browser, $billable, 'business'),
                'plan_down' => $this->changePlan($browser, $billable, 'pro'),
                'plan_down_keep_seats' => $this->changePlan($browser, $billable, 'pro', keepSeats: true),
                'interval_to_yearly' => $this->changePlan($browser, $billable, 'pro', interval: 'yearly'),
                'interval_to_monthly' => $this->changePlan($browser, $billable, 'pro', interval: 'monthly'),
            };
        });

        $this->settlePendingCharge($billable);
        $billable->refresh();

        [$collected, $refunded, $charged, $credited] = $this->movedSince($billable, $before);

        // Where money has to move, a cell must not pass on 0 == 0.
        if (in_array($discount, ['none', 'pct', 'fixed'], true)) {
            if (in_array($kind, ['seat_up', 'addon_on', 'seat_addon_on', 'plan_up', 'interval_to_yearly'], true)) {
                $this->assertGreaterThan(0, $shown['charge'], "{$kind} / {$discount}: an amount is due now");
            }
            if (in_array($kind, ['seat_down', 'addon_off', 'seat_addon_off', 'plan_down', 'interval_to_monthly'], true)) {
                $this->assertGreaterThan(0, $shown['refund'], "{$kind} / {$discount}: a credit comes back");
            }
        }

        $this->assertSame($shown['charge'], $collected, "{$kind} / {$discount}: due now on screen vs. collected at the test Mollie");
        $this->assertSame($shown['refund'], $refunded, "{$kind} / {$discount}: credit on screen vs. refunded at the test Mollie");
        $this->assertSame($shown['charge'], $charged, "{$kind} / {$discount}: due now on screen vs. booked on invoices");
        $this->assertSame($shown['refund'], $credited, "{$kind} / {$discount}: credit on screen vs. booked on credit notes");

        // What Mollie holds afterwards is what the next renewal is invoiced at.
        $this->assertRenewsAtWhatMollieHolds($billable, "{$kind} / {$discount}");

        if ($kind === 'seat_addon_on') {
            // A per-seat addon bills its seats on a line of their own: 4 seats, 1 included.
            $kinds = BillingInvoice::query()->where('billable_id', $billable->getKey())->where('id', '>', $before[0])
                ->get()->flatMap(fn (BillingInvoice $i): array => array_column((array) $i->line_items, 'kind'))->all();
            $this->assertContains('addon_seats', $kinds, 'the per-seat share of the addon is its own invoice line');
        }

        if ($kind === 'plan_down_keep_seats') {
            $this->assertSame(12, $billable->getBillingSeatCount(), 'the seats the customer chose to keep');
        }

        if ($kind === 'plan_down') {
            $this->assertSame(3, $billable->getBillingSeatCount(), 'a plan change lands on the seats in use or included');
        }
    }

    // ── An interval change under each waiver state ──────────────────────────

    /** @return array<string, array{0: string, 1: string}> waiver state, interval the subscriber is on */
    public static function waiverIntervalCells(): array
    {
        $cells = [];

        foreach (['waiver_end', 'waiver_paid', 'waiver_periods', 'waiver_gift'] as $waiver) {
            foreach (['monthly', 'yearly'] as $from) {
                $cells["{$waiver} / from {$from}"] = [$waiver, $from];
            }
        }

        return $cells;
    }

    /**
     * docs/decisions.md, "An interval change is refused while a plan waiver runs": a
     * waiver's amount and lifetime are denominated in the interval it was given in. So
     * under every waiver state — ending on a date, continuing paid, counted in periods,
     * with a gifted addon — and in both directions the preview answers with the reason,
     * offers nothing to confirm, and nothing moves.
     */
    #[DataProvider('waiverIntervalCells')]
    public function test_an_interval_change_under_each_waiver_state_is_refused_with_its_reason_and_moves_nothing(string $waiver, string $from): void
    {
        $to = $from === 'monthly' ? 'yearly' : 'monthly';
        $billable = $this->changeSubscriber('pro', 4, [], $from, $waiver);
        $before = $this->documentIds($billable);
        $held = (string) $this->mollieSubscriptionAmount($billable);

        $this->browse(function (Browser $browser) use ($billable, $to, $waiver): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitFor('[wire\\:click^="previewFor"]', 15)->pause(400);
            $this->wireSet($browser, '[wire\\:click^="previewFor"]', ['selectedInterval' => $to]);
            $browser->waitFor('[wire\\:click="previewFor(\'pro\')"]', 15)->pause(300);
            $this->clickWire($browser, '[wire\\:click="previewFor(\'pro\')"]');
            $browser->waitForText(__('billing::portal.error_interval_change_under_waiver'), 15);
            $this->shot($browser, "Intervallwechsel unter {$waiver}: abgelehnt");

            // Nothing to confirm: the apply button is the disabled one.
            $this->assertSame(0, $this->domCount($browser, '[x-on\\:click*="applyChange"]'), "{$waiver}: no apply button");
        });

        $billable->refresh();

        $this->assertSame($from, (string) $billable->getBillingSubscriptionInterval(), "{$waiver}: the interval stays");
        $this->assertSame('pro', (string) $billable->getBillingSubscriptionPlanCode());
        $this->assertSame([0, 0, 0, 0], $this->movedSince($billable, $before), "{$waiver}: nothing collected, refunded or booked");
        $this->assertSame($held, (string) $this->mollieSubscriptionAmount($billable), "{$waiver}: what the test Mollie holds stays");
        $this->assertNull($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null);
        $this->assertNull($billable->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null);
        $this->assertNotNull(app(PlanWaiver::class)->marker($billable), "{$waiver}: the waiver is untouched");
    }

    // ── A plan change that keeps a per-seat addon ───────────────────────────

    /** @return array<string, array{0: string, 1: int, 2: string, 3: int, 4: int}> from, seats, to, seats afterwards, seats the addon includes */
    public static function perSeatAddonPlanChanges(): array
    {
        return [
            // 4 seats on a plan that includes 2 → a plan that includes 6: lands on 6.
            'up: ops-team → ops-team-plus' => ['ops-team', 4, 'ops-team-plus', 6, 0],
            // 6 seats, 1 in use → a plan that includes 2: lands on 2.
            'down: ops-team-plus → ops-team' => ['ops-team-plus', 6, 'ops-team', 2, 0],
            // The same with an addon that includes two seats of its own: 4 → 6 seats bills
            // 2 → 4 addon seats …
            'up, addon includes 2 seats' => ['ops-team', 4, 'ops-team-plus', 6, 2],
            // … and 6 → 2 seats bills 4 → none: no charge row for the addon's seats at all.
            'down, addon includes 2 seats' => ['ops-team-plus', 6, 'ops-team', 2, 2],
        ];
    }

    /**
     * The per-seat share of an addon on the plan-change PREVIEW.
     *
     * `ops-crm` costs 15.00 plus 3.00 for every seat of the subscription beyond the
     * addon's own included seats (it has none): billed addon seats = max(0, seats −
     * included seats of the addon), where `seats` is never below what the plan includes.
     * A plan change lands on the seats in use or included by the new plan, so the addon's
     * seat count moves with the plan — and the preview has to say so on a row of its own:
     *
     *   the row and its amount on screen   == what the rule gives (computed here)
     *   the total shown as due / credited  == collected / refunded at the test Mollie
     *   the `addon_seats` invoice line     == the same amount, for the same seats
     *   the recurring amount Mollie holds  == the new plan + addon + addon seats, + 19 %
     *   and the next renewal bills the addon seats at that count.
     */
    #[DataProvider('perSeatAddonPlanChanges')]
    public function test_a_plan_change_previews_charges_and_books_the_per_seat_addon_on_its_own_row(string $from, int $seats, string $to, int $seatsAfter, int $addonIncluded): void
    {
        $this->serverConfig('mollie-billing-plans.plans.ops-team.hidden', false);
        $this->serverConfig('mollie-billing-plans.plans.ops-team-plus.hidden', false);

        if ($addonIncluded > 0) {
            $this->serverConfig('mollie-billing-plans.addons.ops-crm.included_seats', $addonIncluded);
        }

        $billable = $this->changeSubscriber($from, $seats, ['ops-crm'], 'monthly', 'none');
        $before = $this->documentIds($billable);

        // From the rule, not from the code. The catalog, net: ops-team 49.00 (2 seats
        // incl., 7.00/seat), ops-team-plus 89.00 (6 incl., 6.00/seat), ops-crm 15.00 +
        // 3.00/seat beyond the seats it includes itself ($addonIncluded).
        $plans = ['ops-team' => [4900, 2, 700], 'ops-team-plus' => [8900, 6, 600]];
        $addonSeatsBefore = max(0, max($seats, $plans[$from][1]) - $addonIncluded);
        $addonSeatsAfter = max(0, max($seatsAfter, $plans[$to][1]) - $addonIncluded);
        $this->assertSame([$seats - $addonIncluded, max(0, $seatsAfter - $addonIncluded)], [$addonSeatsBefore, $addonSeatsAfter]);

        // The running period began yesterday; one day of it is used.
        $start = Carbon::now('UTC')->subDay()->startOfDay();
        $total = (int) $start->diffInDays($start->copy()->addMonth());
        $rest = fn (int $net): int => (int) round($net * ($total - 1) / $total);
        $gross = fn (int $net): int => $net + (int) round($net * 0.19);

        $chargeNet = $rest(300 * $addonSeatsAfter);
        $refundNet = $rest(300 * $addonSeatsBefore);

        $catalog = app(SubscriptionCatalogInterface::class);
        $chargeLabel = trans_choice('billing::portal.prorata_label_addon_seats_charge', $addonSeatsAfter, [
            'count' => $addonSeatsAfter, 'addon' => 'Ops CRM', 'plan' => $catalog->planName($to),
        ]);
        $refundLabel = trans_choice('billing::portal.prorata_label_addon_seats_refund', $addonSeatsBefore, [
            'count' => $addonSeatsBefore, 'addon' => 'Ops CRM', 'plan' => $catalog->planName($from),
        ]);

        $shown = ['charge' => 0, 'refund' => 0];
        $rows = [];

        $this->browse(function (Browser $browser) use ($billable, $to, $seatsAfter, $chargeLabel, $refundLabel, &$shown, &$rows): void {
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitFor('[wire\\:click="previewFor(\''.$to.'\')"]', 15)->pause(400);
            $this->clickWire($browser, '[wire\\:click="previewFor(\''.$to.'\')"]');
            $browser->waitFor('[dusk="seats-after-change"]', 15)->pause(400);
            $browser->assertSeeIn('[dusk="seats-after-change"]', trans_choice('billing::portal.preview_seats_after_change', $seatsAfter, ['count' => $seatsAfter]));

            foreach (['charge' => $chargeLabel, 'refund' => $refundLabel] as $side => $label) {
                $rows[$side] = $this->previewRowAmounts($browser, $label);
            }

            $shown = [
                'charge' => $this->domCount($browser, '[dusk="prorata-total"]') > 0 ? $this->shownMinor($browser, 'prorata-total') : 0,
                'refund' => $this->domCount($browser, '[dusk="prorata-refund-separate"]') > 0 ? $this->shownMinor($browser, 'prorata-refund-separate') : 0,
            ];
            $this->shot($browser, 'Planwechsel mit Per-Seat-Addon: Vorschau');

            $this->clickWire($browser, '[x-on\\:click*="applyChange"]');
            $browser->pause(1500);
        });

        // The preview rows: one for the seats the addon is billed for from now on, one for
        // the seats it was billed for until now — each with the amount the rule gives.
        // No seats to bill means no row — not a row at 0.
        $this->assertSame($addonSeatsAfter > 0 ? ['+'.$gross($chargeNet)] : [], $rows['charge'], "the preview row \"{$chargeLabel}\"");
        $this->assertSame($addonSeatsBefore > 0 ? ['-'.$gross($refundNet)] : [], $rows['refund'], "the preview row \"{$refundLabel}\"");

        $this->settlePendingCharge($billable);
        $billable->refresh();

        [$collected, $refunded, $charged, $credited] = $this->movedSince($billable, $before);

        $this->assertGreaterThan(0, $shown['charge'], 'the new plan is charged');
        $this->assertGreaterThan(0, $shown['refund'], 'the old plan is credited');
        $this->assertSame([$shown['charge'], $shown['refund']], [$collected, $refunded], 'shown vs. moved at the test Mollie');
        $this->assertSame([$shown['charge'], $shown['refund']], [$charged, $credited], 'shown vs. booked');

        // The same row on the documents.
        $lines = BillingInvoice::query()->where('billable_id', $billable->getKey())->where('id', '>', $before[0])->get()
            ->flatMap(fn (BillingInvoice $i): array => array_values(array_filter(
                (array) $i->line_items,
                fn (array $l): bool => ($l['kind'] ?? null) === 'addon_seats' && ($l['code'] ?? null) === 'ops-crm',
            )))
            ->map(fn (array $l): array => [(int) $l['quantity'], (int) ($l['amount_net'] ?? $l['total_net'] ?? 0)])
            ->sortBy(fn (array $l): int => $l[1])->values()->all();

        $this->assertSame(
            array_values(array_filter([[$addonSeatsBefore, -$refundNet], [$addonSeatsAfter, $chargeNet]], fn (array $l): bool => $l[0] > 0)),
            $lines,
            'one addon_seats line on the credit note and one on the invoice: seats and net',
        );

        // What Mollie holds from now on: plan + addon + its seats, + 19 %.
        $recurringNet = $plans[$to][0] + 1500 + 300 * $addonSeatsAfter;
        $this->assertSame($seatsAfter, $billable->getBillingSeatCount());
        $this->assertSame(['ops-crm'], $billable->getActiveBillingAddonCodes());
        $this->assertSame(
            number_format($gross($recurringNet) / 100, 2, '.', ''),
            (string) $this->mollieSubscriptionAmount($billable),
            'the recurring amount carries the addon seats of the new plan',
        );

        $lastInvoice = (int) BillingInvoice::query()->max('id');
        $this->assertRenewsAtWhatMollieHolds($billable, "{$from} → {$to} with ops-crm");

        $renewalLine = collect((array) BillingInvoice::query()->where('billable_id', $billable->getKey())->where('id', '>', $lastInvoice)->firstOrFail()->line_items)
            ->first(fn (array $l): bool => ($l['kind'] ?? null) === 'addon_seats');

        if ($addonSeatsAfter === 0) {
            $this->assertNull($renewalLine, 'no addon seats beyond the ones the addon includes: no line');

            return;
        }

        $this->assertNotNull($renewalLine, 'the renewal bills the addon seats on their own line');
        $this->assertSame(
            [$addonSeatsAfter, 300 * $addonSeatsAfter],
            [(int) $renewalLine['quantity'], (int) ($renewalLine['amount_net'] ?? $renewalLine['total_net'] ?? 0)],
            'the renewal bills the addon for the seats of the new plan',
        );
    }

    /**
     * The amounts of the preview rows that carry exactly this label, signed, in gross
     * minor units: `+2125`, `-1416`.
     *
     * @return list<string>
     */
    protected function previewRowAmounts(Browser $browser, string $label): array
    {
        $found = $browser->script(
            'return Array.from(document.querySelectorAll("div.flex.items-baseline.justify-between"))'
            .'.filter(function (row) { var l = row.querySelector("div.min-w-0 > div"); return l && l.textContent.trim() === '.json_encode($label).'; })'
            .'.map(function (row) { return row.querySelector("span.tabular-nums").textContent.trim(); });'
        )[0] ?? [];

        return array_map(
            fn (string $text): string => (str_contains($text, '−') ? '-' : '+').preg_replace('/\D/', '', $text),
            array_values((array) $found),
        );
    }

    /** Three changes inside one paid period, each quoted, collected and booked on its own. */
    public function test_several_changes_in_one_period_each_move_what_they_show(): void
    {
        $billable = $this->changeSubscriber('pro', 4, [], 'monthly', 'none');

        $steps = [
            'seat up' => fn (Browser $b): array => $this->changeSeats($b, $billable, +1),
            'addon on' => fn (Browser $b): array => $this->toggleAddon($b, $billable, 'softdrinks', enable: true),
            'seat down' => fn (Browser $b): array => $this->changeSeats($b, $billable, -1),
            'addon off' => fn (Browser $b): array => $this->toggleAddon($b, $billable, 'softdrinks', enable: false),
        ];

        foreach ($steps as $name => $step) {
            $before = $this->documentIds($billable);
            $shown = ['charge' => 0, 'refund' => 0];

            $this->browse(function (Browser $browser) use ($step, &$shown): void {
                $shown = $step($browser);
            });

            $this->settlePendingCharge($billable);
            [$collected, $refunded, $charged, $credited] = $this->movedSince($billable, $before);

            $this->assertSame([$shown['charge'], $shown['refund']], [$collected, $refunded], "{$name}: shown vs. moved at the test Mollie");
            $this->assertSame([$shown['charge'], $shown['refund']], [$charged, $credited], "{$name}: shown vs. booked");
        }

        // Back where it started: 29.00 + one extra seat 9.90, + 19 %.
        $this->assertSame('46.29', (string) $this->mollieSubscriptionAmount($billable));
        $this->assertRenewsAtWhatMollieHolds($billable, 'after four changes');
    }

    /** What the addon and seat screens say about a per-seat addon, before anything is bought. */
    public function test_a_per_seat_addon_is_priced_per_seat_on_screen(): void
    {
        $billable = $this->changeSubscriber('ops-team', 4, ['ops-crm'], 'monthly', 'none');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing/addons');
            $this->waitForPresence($browser, '[wire\\:click="disableAddon(\'ops-crm\')"]', 15);
            // 3.00 net per seat, + 19 %.
            $browser->assertSee(__('billing::portal.addon_price_per_seat', ['price' => BillingMoney::format(357)]));
            $this->shot($browser, 'Per-Seat-Addon: Preis je Platz');

            // And a further seat costs the plan's seat price plus the addon's: 7.00 + 3.00, + 19 %.
            $browser->visit('/billing/seats')->waitFor('[wire\\:click="increment"]', 15)->pause(400);
            $browser->assertSee(BillingMoney::format(1190));
            $this->shot($browser, 'Per-Seat-Addon: Platzpreis');
        });
    }

    // ── A second change after a plan change that settled across a renewal ───

    /**
     * Business → Pro with a slow payment: the renewal is collected for Business, then the
     * payment settles, Pro takes effect and the difference is returned against the
     * renewal. No invoice line says "Pro" for this period — and yet the customer has paid
     * for it. A further change, Pro → Starter, is settled as if the period had been paid
     * on Pro from its first day: Pro 29.00 is credited and Starter 9.90 charged, each for
     * the days left of the period that began yesterday, + 19 %.
     */
    public function test_a_second_change_credits_the_plan_reached_across_a_renewal(): void
    {
        $billable = $this->makeActiveSubscriber('business', 'monthly');
        $this->setIpCountry('DE');
        $billable->forceFill(['subscription_meta' => array_merge($billable->getBillingSubscriptionMeta(), ['seat_count' => 10, 'used_seats' => 1])])->save();
        app(PlanWaiver::class)->syncMollie($billable->refresh());
        $subscriptionId = (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'];

        // The downgrade's payment stays open …
        $this->setRecurringOutcome('open');
        app(\GraystackIT\MollieBilling\Services\Billing\UpdateSubscription::class)
            ->update($billable, ['plan_code' => 'pro', 'interval' => 'monthly', 'apply_at' => 'immediate']);
        $this->setRecurringOutcome('paid');
        $pending = (string) $billable->refresh()->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'];

        // … the renewal is collected for Business …
        DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->update(['next_payment_date' => now()->subDay()->toDateString()]);
        $renewal = $this->opsRenew($billable, (string) $this->mollieSubscriptionAmount($billable));
        $this->assertSame(11781, (int) $renewal->amount_gross);

        // … and then the payment settles: Pro from here on.
        \Workbench\App\FakeMollie\FakeMollie::settle($pending, 'paid', 'DE');
        $this->deliverWebhook($pending);
        $billable->refresh();
        $this->assertSame('pro', $billable->getBillingSubscriptionPlanCode());

        $before = $this->documentIds($billable);
        $shown = ['charge' => 0, 'refund' => 0];

        $this->browse(function (Browser $browser) use ($billable, &$shown): void {
            $shown = $this->changePlan($browser, $billable, 'starter');
        });

        $this->settlePendingCharge($billable);
        [$collected, $refunded, $charged, $credited] = $this->movedSince($billable, $before);

        $this->assertSame([self::restOfPaidPeriod(990), self::restOfPaidPeriod(2900)], [$shown['charge'], $shown['refund']], 'what the rule gives: Starter charged, Pro credited');
        $this->assertSame([$shown['charge'], $shown['refund']], [$collected, $refunded], 'shown vs. moved at the test Mollie');
        $this->assertSame([$shown['charge'], $shown['refund']], [$charged, $credited], 'shown vs. booked');
        $this->assertSame('starter', $billable->fresh()->getBillingSubscriptionPlanCode());
        $this->assertRenewsAtWhatMollieHolds($billable, 'second change after a spanned downgrade');
    }

    /**
     * Past due on a paid period. Pro → Business two days ago with a slow payment; the
     * renewal is collected for Pro yesterday; the payment settles today, Business takes
     * effect and the rest of the period is charged as a balance — which is declined: past
     * due. The period IS paid (the renewal), so Business → Starter is an ordinary change,
     * not the past-due reset: what the renewal paid for Pro (29.00) is credited and Starter
     * (9.90) charged, each for the days left of the period that began yesterday, + 19 %.
     * The reset would have charged Starter in full and credited nothing.
     */
    public function test_a_plan_change_from_a_declined_balance_is_an_ordinary_change_and_ends_the_dunning(): void
    {
        $billable = $this->makeActiveSubscriber('pro', 'monthly');
        $this->setIpCountry('DE');
        $billable->forceFill(['subscription_meta' => array_merge($billable->getBillingSubscriptionMeta(), ['used_seats' => 1])])->save();
        app(PlanWaiver::class)->syncMollie($billable->refresh());
        $subscriptionId = (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'];

        // The upgrade's payment stays open; it was asked for two days ago, before the renewal.
        $this->setRecurringOutcome('open');
        app(\GraystackIT\MollieBilling\Services\Billing\UpdateSubscription::class)
            ->update($billable, ['plan_code' => 'business', 'interval' => 'monthly', 'apply_at' => 'immediate']);
        $this->setRecurringOutcome('paid');
        $meta = $billable->refresh()->getBillingSubscriptionMeta();
        $meta['pending_prorata_change']['created_at'] = now()->subDays(2)->toIso8601String();
        $billable->forceFill(['subscription_meta' => $meta])->save();
        $pending = (string) $meta['pending_prorata_change']['charge_payment_id'];

        // … the renewal is collected for Pro yesterday …
        DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->update(['next_payment_date' => now()->subDay()->toDateString()]);
        $this->opsRenew($billable, (string) $this->mollieSubscriptionAmount($billable));

        // … the payment settles: Business, and the balance is charged (left open) — and declined.
        $this->setRecurringOutcome('open');
        $payments = DB::table('fake_mollie_payments')->pluck('id')->all();
        \Workbench\App\FakeMollie\FakeMollie::settle($pending, 'paid', 'DE');
        $this->deliverWebhook($pending);
        $balance = DB::table('fake_mollie_payments')->whereNotIn('id', $payments)->first();
        $this->assertNotNull($balance, 'the balance of the upgrade is charged');
        \Workbench\App\FakeMollie\FakeMollie::settle((string) $balance->id, 'failed', 'DE');
        $this->deliverWebhook((string) $balance->id);
        $this->setRecurringOutcome('paid');

        $billable->refresh();
        $this->assertSame('business', $billable->getBillingSubscriptionPlanCode());
        $this->assertSame(\GraystackIT\MollieBilling\Enums\SubscriptionStatus::PastDue, $billable->getBillingSubscriptionStatus());
        $periodStart = (int) $billable->getBillingPeriodStartsAt()?->timestamp;

        $before = $this->documentIds($billable);
        $shown = ['charge' => 0, 'refund' => 0];

        $this->browse(function (Browser $browser) use ($billable, &$shown): void {
            $shown = $this->changePlan($browser, $billable, 'starter');
        });

        $this->settlePendingCharge($billable);
        [$collected, $refunded, $charged, $credited] = $this->movedSince($billable, $before);

        $this->assertSame([self::restOfPaidPeriod(990), self::restOfPaidPeriod(2900)], [$shown['charge'], $shown['refund']], 'what the rule gives: Starter charged, the paid Pro share credited');
        $this->assertSame([$shown['charge'], $shown['refund']], [$collected, $refunded], 'shown vs. moved at the test Mollie');
        $this->assertSame([$shown['charge'], $shown['refund']], [$charged, $credited], 'shown vs. booked');

        $billable->refresh();
        $this->assertSame('starter', $billable->getBillingSubscriptionPlanCode());
        $this->assertSame(\GraystackIT\MollieBilling\Enums\SubscriptionStatus::Active, $billable->getBillingSubscriptionStatus(), 'the change ends the dunning');
        $this->assertSame(
            [\GraystackIT\MollieBilling\Services\Billing\UndercollectionCharge::STATUS_CLOSED],
            array_column(\GraystackIT\MollieBilling\Services\Billing\UndercollectionCharge::cases($billable), 'status'),
            'the declined balance is closed',
        );
        $this->assertSame($periodStart, (int) $billable->getBillingPeriodStartsAt()?->timestamp, 'the period stays the one the renewal paid for');
        $this->assertRenewsAtWhatMollieHolds($billable, 'plan change from a declined balance');
    }

    /**
     * A seat paid by a balance. Pro with its three included seats; Mollie prepares the
     * next debit, then a fourth seat is added; the debit is collected yesterday at the old
     * amount and the seat is charged as a balance of its own (9.90 + 19 %), which is paid.
     * Giving the seat back credits what was paid for it — 9.90 for the days left of the
     * period that began yesterday, + 19 % — out of the balance. Before, the renewal's seat
     * line was read with the adjustment spread over it and less came back.
     */
    public function test_a_seat_paid_by_a_balance_is_credited_at_what_was_paid_for_it(): void
    {
        $billable = $this->makeActiveSubscriber('pro', 'monthly');
        $this->setIpCountry('DE');
        $billable->forceFill(['subscription_meta' => array_merge($billable->getBillingSubscriptionMeta(), ['seat_count' => 3, 'used_seats' => 1])])->save();
        app(PlanWaiver::class)->syncMollie($billable->refresh());
        $subscriptionId = (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'];

        // Mollie prepares the debit for three seats …
        $prepared = DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->first(['amount_value', 'metadata']);

        // … a fourth is added (and its prorata paid) …
        app(\GraystackIT\MollieBilling\Services\Billing\UpdateSubscription::class)->update($billable, ['seats' => 4]);
        $this->settlePendingCharge($billable);

        // … and the debit prepared before that is collected yesterday.
        DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->update(['metadata' => $prepared->metadata, 'next_payment_date' => now()->subDay()->toDateString()]);
        $payments = DB::table('fake_mollie_payments')->pluck('id')->all();
        $renewal = $this->opsRenew($billable, (string) $prepared->amount_value);
        $balance = DB::table('fake_mollie_payments')->whereNotIn('id', $payments)->where('id', '!=', $renewal->mollie_payment_id)->first();
        $this->assertNotNull($balance, 'the seat is charged as a balance');
        $this->assertSame(number_format((990 + (int) round(990 * 0.19)) / 100, 2, '.', ''), (string) $balance->amount_value);
        \Workbench\App\FakeMollie\FakeMollie::settle((string) $balance->id, 'paid', 'DE');
        $this->deliverWebhook((string) $balance->id);

        $before = $this->documentIds($billable);
        $shown = ['charge' => 0, 'refund' => 0];

        $this->browse(function (Browser $browser) use ($billable, &$shown): void {
            $shown = ['charge' => 0, 'refund' => $this->changeSeats($browser, $billable->fresh(), -1)['refund'] ?? 0];
        });

        [$collected, $refunded, $charged, $credited] = $this->movedSince($billable, $before);

        $this->assertSame(self::restOfPaidPeriod(990), $shown['refund'], 'what the rule gives: the seat\'s 9.90 for the days left');
        $this->assertSame([0, $shown['refund']], [$collected, $refunded], 'shown vs. moved at the test Mollie');
        $this->assertSame([0, $shown['refund']], [$charged, $credited], 'shown vs. booked');
        $this->assertSame(3, $billable->fresh()->getBillingSeatCount());
    }

    /**
     * A fixed-amount coupon after a downgrade that settled across the renewal. Business
     * with Softdrinks under 5.00 off; the downgrade to Pro is asked for two days ago, the
     * renewal is collected for Business yesterday, the payment settles today. Softdrinks
     * switched off is credited at no more than the share the 5.00 leaves of what the
     * customer is on now (Pro + Softdrinks, 33.90): 4.90 × 28.90/33.90 for the days left,
     * + 19 % — not at the share it was paid at under Business (103.90, of which 98.90).
     */
    public function test_an_addon_after_a_spanned_downgrade_under_a_fixed_coupon_is_credited_at_the_current_share(): void
    {
        $billable = $this->makeActiveSubscriber('business', 'monthly', ['active_addon_codes' => ['softdrinks']]);
        $this->setIpCountry('DE');
        $billable->forceFill(['subscription_meta' => array_merge($billable->getBillingSubscriptionMeta(), ['seat_count' => 10, 'used_seats' => 1])])->save();
        $billable->refresh();

        $coupon = $this->coupons()->create([
            'code' => strtoupper('CHGC'.substr(uniqid(), -6)),
            'name' => 'Change matrix fixed (C)',
            'type' => CouponType::Recurring,
            'discount_type' => DiscountType::Fixed,
            'discount_value' => 500,
            'max_redemptions_per_billable' => 12,
        ]);
        $this->coupons()->applyRecurringMarker($coupon, $billable, 9900 + 490, 'monthly');
        app(PlanWaiver::class)->syncMollie($billable->refresh());
        $subscriptionId = (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'];

        // The downgrade's payment stays open; it was asked for two days ago.
        $this->setRecurringOutcome('open');
        app(\GraystackIT\MollieBilling\Services\Billing\UpdateSubscription::class)
            ->update($billable, ['plan_code' => 'pro', 'interval' => 'monthly', 'apply_at' => 'immediate']);
        $this->setRecurringOutcome('paid');
        $meta = $billable->refresh()->getBillingSubscriptionMeta();
        $meta['pending_prorata_change']['created_at'] = now()->subDays(2)->toIso8601String();
        $billable->forceFill(['subscription_meta' => $meta])->save();
        $pending = (string) $meta['pending_prorata_change']['charge_payment_id'];

        // The renewal is collected for Business yesterday, then the downgrade settles.
        DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->update(['next_payment_date' => now()->subDay()->toDateString()]);
        $this->opsRenew($billable, (string) $this->mollieSubscriptionAmount($billable));
        \Workbench\App\FakeMollie\FakeMollie::settle($pending, 'paid', 'DE');
        $this->deliverWebhook($pending);
        $this->assertSame('pro', $billable->fresh()->getBillingSubscriptionPlanCode());

        $before = $this->documentIds($billable);
        $shown = ['charge' => 0, 'refund' => 0];

        $this->browse(function (Browser $browser) use ($billable, &$shown): void {
            $shown = $this->toggleAddon($browser, $billable->fresh(), 'softdrinks', false);
        });

        [$collected, $refunded, $charged, $credited] = $this->movedSince($billable, $before);

        // The rule, computed here: list × share of the current configuration × days left.
        $start = \Carbon\Carbon::now()->subDay()->startOfDay();
        $total = (int) $start->diffInDays($start->copy()->addMonth());
        $net = (int) round(490 * ((2900 + 490 - 500) / (2900 + 490)) * round(($total - 1) / $total, 6));
        $atRenewalShare = (int) round(490 * ((9900 + 490 - 500) / (9900 + 490)) * ($total - 1) / $total);

        $this->assertGreaterThan($net, $atRenewalShare, 'the renewal\'s share would credit more');
        $this->assertSame($net + (int) round($net * 0.19), $shown['refund'], 'what the rule gives: Softdrinks at the current share');
        $this->assertSame([0, $shown['refund']], [$collected, $refunded], 'shown vs. moved at the test Mollie');
        $this->assertSame([0, $shown['refund']], [$charged, $credited], 'shown vs. booked');
    }

    // ── Down to the free plan ───────────────────────────────────────────────

    /** @return array<string, array{0: string, 1: string}> */
    public static function freeDowngrades(): array
    {
        $cells = [];

        foreach (['immediate', 'end_of_period'] as $timing) {
            foreach (['plain', 'seats_addon', 'pct', 'fixed', 'waiver_paid'] as $variant) {
                $cells["{$timing} / {$variant}"] = [$timing, $variant];
            }
        }

        return $cells;
    }

    #[DataProvider('freeDowngrades')]
    public function test_a_downgrade_to_the_free_plan_returns_what_the_screen_says_and_ends_the_mollie_subscription(string $timing, string $variant): void
    {
        $this->serverConfig('mollie-billing-plans.plans.changes-free.hidden', false);
        config(['mollie-billing-plans.plans.changes-free.hidden' => false]);

        [$seats, $addons, $discount] = match ($variant) {
            'plain' => [3, [], 'none'],
            'seats_addon' => [5, ['softdrinks'], 'none'],
            'waiver_paid' => [5, ['softdrinks'], 'waiver_paid'],
            default => [5, ['softdrinks'], $variant],
        };

        $billable = $this->changeSubscriber('pro', $seats, $addons, 'monthly', $discount);
        $subscriptionId = (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'];
        $before = $this->documentIds($billable);
        $shown = ['charge' => 0, 'refund' => 0];
        $what = "free / {$timing} / {$variant}";

        $this->browse(function (Browser $browser) use ($billable, $timing, &$shown): void {
            $target = '[wire\\:click="previewFor(\'changes-free\')"]';
            $this->actAs($browser, $billable, '/billing/plan');
            $browser->waitFor($target, 15)->pause(400);
            $this->clickWire($browser, $target);
            $browser->waitFor('[x-on\\:click*="applyChange"]', 15)->pause(600);

            if ($timing === 'end_of_period') {
                $this->jsClick($browser, '[\\@click*="end_of_period"]');
            } else {
                $shown = [
                    'charge' => $this->domCount($browser, '[dusk="prorata-total"]') > 0 ? $this->shownMinor($browser, 'prorata-total') : 0,
                    'refund' => $this->domCount($browser, '[dusk="prorata-refund-separate"]') > 0 ? $this->shownMinor($browser, 'prorata-refund-separate') : 0,
                ];
                // Nothing is charged, so the sentence must not wait for a payment.
                if ($shown['refund'] > 0) {
                    $browser->assertSee(__('billing::portal.prorata_refund_only', ['amount' => BillingMoney::format($shown['refund'])]));
                }
            }

            $this->shot($browser, 'Gratis-Plan: Vorschau');
            $this->clickWire($browser, '[x-on\\:click*="applyChange"]');
            $browser->pause(1800);
            $this->shot($browser, 'Gratis-Plan: danach');
        });

        $this->settlePendingCharge($billable);
        $billable->refresh();

        if ($timing === 'end_of_period') {
            // Scheduled: nothing moves today, the paid period is used up.
            $this->assertSame('changes-free', $billable->getBillingSubscriptionMeta()['scheduled_change']['plan_code'] ?? null, $what);
            $this->assertSame('pro', $billable->getBillingSubscriptionPlanCode(), $what);
            $this->assertSame([0, 0, 0, 0], $this->movedSince($billable, $before), $what.': nothing collected, refunded or booked');

            // The night it is due.
            $billable->forceFill(['scheduled_change_at' => now()->subMinute()])->save();
            app(\GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange::class)->apply($billable->fresh());
            $billable->refresh();

            if (! empty($billable->getBillingSubscriptionMeta()['scheduled_change'])) {
                // It waits for the renewal that closes the paid period — and goes with it.
                $this->opsRenew($billable, (string) $this->mollieSubscriptionAmount($billable));
                $billable->refresh();
            }
        } else {
            [$collected, $refunded, $charged, $credited] = $this->movedSince($billable, $before);

            $this->assertSame(0, $collected, $what.': nothing is collected');
            $this->assertSame($shown['refund'], $refunded, $what.': credit on screen vs. refunded at the test Mollie');
            $this->assertSame($shown['refund'], $credited, $what.': credit on screen vs. booked on credit notes');
            $this->assertSame(0, $charged, $what.': no charge is booked');

            if (in_array($variant, ['plain', 'seats_addon', 'pct', 'fixed'], true)) {
                $this->assertGreaterThan(0, $shown['refund'], $what.': the unused paid share comes back');
            }
        }

        $this->assertSame('changes-free', $billable->getBillingSubscriptionPlanCode(), $what);
        $this->assertSame(\GraystackIT\MollieBilling\Enums\SubscriptionSource::Local, $billable->subscription_source, $what.': a free plan runs without Mollie');
        $this->assertSame([], array_values($billable->getActiveBillingAddonCodes()), $what.': the free plan allows no add-ons');
        $this->assertSame(1, $billable->getBillingSeatCount(), $what.': the free plan has one seat');
        $this->assertNotNull(
            DB::table('fake_mollie_subscriptions')->where('id', $subscriptionId)->value('canceled_at'),
            $what.': the Mollie subscription is cancelled',
        );
    }

    // ── Every change kind in every state ────────────────────────────────────

    /**
     * What each cell has to do, derived from the rules — not from what the code does:
     *
     * - Trial (docs/plan-changes.md, "Nothing is prorated during a trial"): seats and
     *   addons are applied at once and move no money in either direction. A plan or
     *   interval change ends the trial and is charged, nothing credited (nothing was
     *   paid): a plan change pays "for the rest of the local period" — the new price for
     *   the days that are left of it; an interval change opens a new period and is
     *   charged in full.
     * - Past due ("Past-Due Reset"): adding a seat or a paid addon is refused until the
     *   open payment is settled; taking one away is allowed and credits nothing (the
     *   period was not paid); a plan or interval change is the reset — the full first
     *   period of the new configuration, no credit.
     * - Cancelled, still running: `schedule()` refuses, "the path is to resubscribe"; and a
     *   settled charge is never applied to a subscription that no longer runs, so a plan
     *   or interval change must be refused before it charges — and so must an added seat
     *   or paid addon, which is a charge of exactly that kind. For taking a seat or an
     *   addon AWAY on a cancelled subscription there is NO rule — those two cells are
     *   `open`: recorded, checked for consistency only, and listed for the maintainer.
     * - A plan change lands on max(seats in use, seats the new plan includes).
     *
     * Amounts (DE, 19 %), one seat in use:
     *   → business (10 seats included):          99.00            = 117.81
     *   → pro (3 included):                      29.00            =  34.51
     *   → pro yearly, 4 seats (1 extra):         290.00 + 99.00   = 462.91
     * and for the trial's plan change the same net × (days left / days of the period that
     * began nine days ago), computed in {@see restOfTrialPeriod()}.
     *
     * Decided by the maintainer on 16 Nov 2026:
     * - Cancelled: a seat or an addon given BACK is still credited — the customer paid for
     *   it and no longer uses it: the unused paid share, 9.90 (seat) or 4.90 (addon) net
     *   for the days left of the period that began yesterday, + 19 %
     *   ({@see restOfPaidPeriod()}). The end date and the cancelled Mollie subscription
     *   are not touched.
     * - Past due: scheduling an EXPANSION (a dearer plan) for the period end is refused;
     *   scheduling a reduction stays possible and the screen says the open payment
     *   remains due. Under a pure overage debt no plan change is scheduled at all — the
     *   period is paid, the change could only be dropped on its night.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: ?int, 4: ?int}> state, kind, outcome, gross charged, gross refunded
     */
    public static function stateCells(): array
    {
        $expected = [
            'trial' => [
                'seat_up' => ['free', 0], 'seat_down' => ['free', 0], 'addon_on' => ['free', 0], 'addon_off' => ['free', 0],
                'plan_up' => ['moves', self::restOfTrialPeriod(9900)], 'plan_down' => ['moves', self::restOfTrialPeriod(2900)], 'interval' => ['moves', 46291],
                'scheduled' => ['scheduled', 0],
            ],
            'past_due' => [
                'seat_up' => ['refused', 0], 'seat_down' => ['free', 0], 'addon_on' => ['refused', 0], 'addon_off' => ['free', 0],
                'plan_up' => ['moves', 11781], 'plan_down' => ['moves', 3451], 'interval' => ['moves', 46291],
                'scheduled' => ['scheduled', 0], 'scheduled_up' => ['refused', 0],
            ],
            'past_due_overage' => [
                'scheduled' => ['refused', 0], 'scheduled_up' => ['refused', 0],
            ],
            'cancelled' => [
                // A seat or a paid addon is a charge like any other: refused. Giving one back
                // credits the unused paid share.
                'seat_up' => ['refused', 0], 'seat_down' => ['moves', 0, self::restOfPaidPeriod(990)],
                'addon_on' => ['refused', 0], 'addon_off' => ['moves', 0, self::restOfPaidPeriod(490)],
                'plan_up' => ['refused', 0], 'plan_down' => ['refused', 0], 'interval' => ['refused', 0],
                'scheduled' => ['refused', 0],
            ],
        ];

        $cells = [];
        foreach ($expected as $state => $kinds) {
            foreach ($kinds as $kind => $cell) {
                $cells["{$state} / {$kind}"] = [$state, $kind, $cell[0], $cell[1], $cell[2] ?? 0];
            }
        }

        return $cells;
    }

    /**
     * Gross for `$net` over what is left of a monthly period that began nine days ago
     * (the trial subscriber of {@see stateSubscriber()}), at 19 %.
     */
    protected static function restOfTrialPeriod(int $net): int
    {
        $start = \Carbon\Carbon::now()->subDays(9)->startOfDay();
        $total = (int) $start->diffInDays($start->copy()->addMonth());

        return (int) round(round($net * ($total - 9) / $total) * 1.19);
    }

    /**
     * Gross credited for `$net` over what is left of the monthly period the cancelled
     * subscriber of {@see stateSubscriber()} paid for — it began yesterday — at 19 %.
     */
    protected static function restOfPaidPeriod(int $net): int
    {
        $start = \Carbon\Carbon::now()->subDay()->startOfDay();
        $total = (int) $start->diffInDays($start->copy()->addMonth());

        return (int) round(round($net * ($total - 1) / $total) * 1.19);
    }

    #[DataProvider('stateCells')]
    public function test_a_change_in_this_state_moves_what_it_shows_or_is_refused_with_its_reason(string $state, string $kind, string $expected, ?int $expectedCharge, ?int $expectedRefund): void
    {
        [$plan, $seats, $addons] = match ($kind) {
            'seat_down' => ['pro', 5, []],
            'addon_off' => ['pro', 4, ['softdrinks']],
            'plan_down', 'scheduled' => ['business', 10, []],
            'scheduled_up' => ['pro', 3, []],
            default => ['pro', 4, []],
        };

        $billable = $this->stateSubscriber($state, $plan, $seats, $addons);
        $before = $this->documentIds($billable);
        $was = $this->configurationOf($billable);
        $endsAt = $billable->subscription_ends_at;
        $heldAtMollie = (string) $this->mollieSubscriptionAmount($billable);
        $shown = ['charge' => 0, 'refund' => 0];
        $text = '';
        $what = "{$state} / {$kind}";

        $this->browse(function (Browser $browser) use ($billable, $kind, &$shown, &$text): void {
            [$shown, $text] = $this->attemptChange($browser, $billable, $kind);
            $this->shot($browser, 'nach dem Versuch');
        });

        $this->settlePendingCharge($billable);
        $billable->refresh();

        [$collected, $refunded, $charged, $credited] = $this->movedSince($billable, $before);
        $is = $this->configurationOf($billable);

        $outcome = match (true) {
            $is['scheduled'] !== null && $was['scheduled'] === null => 'scheduled',
            $collected + $refunded + $charged + $credited > 0 => 'moves',
            $is !== $was => 'free',
            default => 'refused',
        };

        $this->assertSame($expected, $outcome, $what.': what the rules say must happen');
        $this->assertSame([$expectedCharge, $expectedRefund], [$collected, $refunded], $what.': the amounts the rules give');

        if ($state === 'cancelled') {
            // Whatever a cancelled subscription still does, it ends when it was going to
            // end, and its cancelled Mollie subscription is not re-priced.
            $this->assertSame((string) $endsAt, (string) $billable->subscription_ends_at, $what.': the end date');
            $this->assertSame($heldAtMollie, (string) $this->mollieSubscriptionAmount($billable), $what.': the amount at Mollie');
        }

        if ($state === 'past_due_overage') {
            // The refusal says what to do instead: settle the amount; seats and add-ons can
            // still be reduced.
            $this->assertStringContainsString((string) __('billing::portal.error_past_due_plan_change'), $text, $what);
        }

        if ($state === 'past_due' && $kind === 'scheduled') {
            $this->assertStringContainsString('[[schedule-past-due-notice]]', $text, $what.': the screen says the open payment remains due');
        }

        // Whatever happened: the screen and the books agree.
        $this->assertSame($shown['charge'], $collected, $what.': due now on screen vs. collected at the test Mollie');
        $this->assertSame($shown['refund'], $refunded, $what.': credit on screen vs. refunded at the test Mollie');
        $this->assertSame($collected, $charged, $what.': collected vs. booked on invoices');
        $this->assertSame($refunded, $credited, $what.': refunded vs. booked on credit notes');

        if ($outcome === 'refused') {
            $said = array_filter($this->knownReasons(), fn (string $reason): bool => str_contains($text, $reason));
            $this->assertNotSame([], $said, $what.': a refusal names its reason on screen. Screen said: '.mb_substr(preg_replace('/\s+/', ' ', $text), 0, 600));
        }
    }

    /** @return array<string, string> the sentences a refusal may be explained with */
    protected function knownReasons(): array
    {
        return [
            'past_due' => (string) __('billing::portal.error_past_due_expansion'),
            'past_due_booking' => (string) __('billing::portal.error_past_due_booking'),
            'past_due_plan_change' => (string) __('billing::portal.error_past_due_plan_change'),
            'pending_change' => (string) __('billing::portal.pending_change_blocks_modifications'),
            'interval_waiver' => (string) __('billing::portal.error_interval_change_under_waiver'),
            'cancelled' => (string) __('billing::portal.error_subscription_ended'),
            // In the grace period the addons and seats screens say until when, and that
            // the cancellation is to be withdrawn first (decision 5) — the part after the date.
            'cancelled_addons' => \Illuminate\Support\Str::after((string) __('billing::portal.error_subscription_ending_addons', ['date' => '§']), '§'),
            'cancelled_seats' => \Illuminate\Support\Str::after((string) __('billing::portal.error_subscription_ending_seats', ['date' => '§']), '§'),
        ];
    }

    /** @return array<string, mixed> */
    protected function configurationOf(TestBillable $billable): array
    {
        $billable = $billable->fresh();

        return [
            'plan' => $billable->getBillingSubscriptionPlanCode(),
            'interval' => (string) $billable->getBillingSubscriptionInterval(),
            'seats' => $billable->getBillingSeatCount(),
            'addons' => array_values($billable->getActiveBillingAddonCodes()),
            'status' => $billable->getBillingSubscriptionStatus()?->value,
            'scheduled' => $billable->getBillingSubscriptionMeta()['scheduled_change']['plan_code'] ?? null,
        ];
    }

    /**
     * A subscriber in a state other than Active.
     *
     * @param  list<string>  $addons
     */
    protected function stateSubscriber(string $state, string $plan, int $seats, array $addons): TestBillable
    {
        if ($state === 'past_due_overage') {
            // The period is paid; only a usage overage could not be collected.
            $billable = $this->changeSubscriber($plan, $seats, $addons, 'monthly', 'none');
            $billable->forceFill([
                'subscription_status' => \GraystackIT\MollieBilling\Enums\SubscriptionStatus::PastDue,
                'subscription_meta' => array_merge($billable->getBillingSubscriptionMeta(), [
                    \GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly::PAST_DUE_KEY => now()->toIso8601String(),
                ]),
            ])->save();

            return $billable->refresh();
        }

        if ($state === 'cancelled') {
            // Paid up, cancelled, running until the period ends.
            $billable = $this->changeSubscriber($plan, $seats, $addons, 'monthly', 'none');
            $billable->forceFill([
                'subscription_status' => \GraystackIT\MollieBilling\Enums\SubscriptionStatus::Cancelled,
                'subscription_ends_at' => $billable->nextBillingDate(),
            ])->save();
            DB::table('fake_mollie_subscriptions')
                ->where('id', (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'])
                ->update(['status' => 'canceled', 'canceled_at' => now()]);

            return $billable->refresh();
        }

        $billable = $this->makeActiveSubscriber($plan, 'monthly', ['active_addon_codes' => array_values($addons)] + ($state === 'trial'
            ? ['subscription_status' => \GraystackIT\MollieBilling\Enums\SubscriptionStatus::Trial, 'trial_ends_at' => now()->addDays(5), 'subscription_period_starts_at' => now()->subDays(9)]
            : ['subscription_status' => \GraystackIT\MollieBilling\Enums\SubscriptionStatus::PastDue, 'subscription_period_starts_at' => now()->subDays(33)]));
        $this->setIpCountry('DE');

        $meta = array_merge($billable->getBillingSubscriptionMeta(), ['seat_count' => $seats, 'used_seats' => 1]);
        if ($state === 'past_due') {
            $meta['past_due_since'] = now()->subDays(2)->toIso8601String();
        }
        $billable->forceFill(['subscription_meta' => $meta])->save();
        app(PlanWaiver::class)->syncMollie($billable->refresh());

        return $billable->refresh();
    }

    /**
     * Try one change on its screen, however the screen answers.
     *
     * @return array{0: array{charge: int, refund: int}, 1: string} what was shown, and what the page said afterwards
     */
    protected function attemptChange(Browser $browser, TestBillable $billable, string $kind): array
    {
        $shown = ['charge' => 0, 'refund' => 0];
        $page = fn (): string => (string) $browser->script('return document.body.innerText;')[0];
        $amount = fn (string $dusk): int => $this->domCount($browser, '[dusk="'.$dusk.'"]') > 0 ? $this->shownMinor($browser, $dusk, hidden: true) : 0;
        $disabled = fn (string $css): bool => (bool) $browser->script(sprintf('const e = document.querySelector(%s); return e === null || e.disabled === true || e.hasAttribute("disabled");', json_encode($css)))[0];

        if (in_array($kind, ['seat_up', 'seat_down'], true)) {
            $step = $kind === 'seat_up' ? 'increment' : 'decrement';
            $this->actAs($browser, $billable, '/billing/seats');
            $browser->pause(1500);

            if ($disabled('[wire\\:click="'.$step.'"]')) {
                return [$shown, $page()];
            }

            $this->clickWire($browser, '[wire\\:click="'.$step.'"]');
            $browser->pause(1200);
            $shown = ['charge' => $amount('seats-due-now'), 'refund' => $amount('seats-credit')];

            if (! $disabled('[wire\\:click="syncSeats"]')) {
                $this->clickWire($browser, '[wire\\:click="syncSeats"]');
                $browser->pause(1500);
            }

            return [$shown, $page()];
        }

        if (in_array($kind, ['addon_on', 'addon_off'], true)) {
            $enable = $kind === 'addon_on';
            $action = '[wire\\:click="'.($enable ? 'enableAddon' : 'disableAddon').'(\'softdrinks\')"]';
            $this->actAs($browser, $billable, '/billing/addons');
            $browser->pause(1500);

            if ($this->domCount($browser, $action) === 0) {
                return [$shown, $page()];
            }

            $shown = $enable
                ? ['charge' => $amount('addon-due-now-softdrinks'), 'refund' => 0]
                : ['charge' => 0, 'refund' => $amount('addon-credit-softdrinks')];
            $this->confirmInModal($browser, ($enable ? 'enable' : 'disable').'-addon-softdrinks', $action);
            $browser->pause(1200);

            return [$shown, $page()];
        }

        // Plan screen: an upgrade, a downgrade, the other interval, or a downgrade at the period end.
        $target = match ($kind) {
            'plan_up', 'scheduled_up' => 'business',
            'interval' => 'pro',
            default => 'pro',
        };
        $select = '[wire\\:click="previewFor(\''.$target.'\')"]';

        $this->actAs($browser, $billable, '/billing/plan');
        $browser->pause(1500);

        if ($this->domCount($browser, '[wire\\:click^="previewFor"]') === 0) {
            return [$shown, $page()];
        }

        if ($kind === 'interval') {
            $this->wireSet($browser, '[wire\\:click^="previewFor"]', ['selectedInterval' => 'yearly']);
            $browser->pause(600);
        }

        if ($this->domCount($browser, $select) === 0) {
            return [$shown, $page()];
        }

        $this->clickWire($browser, $select);
        $browser->pause(1800);

        if ($this->domCount($browser, '[x-on\\:click*="applyChange"]') === 0) {
            return [$shown, $page()];
        }

        $notice = '';

        if (in_array($kind, ['scheduled', 'scheduled_up'], true)) {
            if ($this->domCount($browser, '[\\@click*="end_of_period"]') === 0) {
                return [$shown, $page()];
            }
            $notice = $this->domCount($browser, '[dusk="schedule-past-due-notice"]') > 0 ? ' [[schedule-past-due-notice]]' : '';
            $this->jsClick($browser, '[\\@click*="end_of_period"]');
        } else {
            $shown = ['charge' => $amount('prorata-total'), 'refund' => $amount('prorata-refund-separate')];
        }

        $this->clickWire($browser, '[x-on\\:click*="applyChange"]');
        $browser->pause(1800);

        return [$shown, $page().$notice];
    }

    // ── Setting up ──────────────────────────────────────────────────────────

    /**
     * An active subscriber under a discount state, whose running period was paid for by a
     * renewal a moment ago (unless the discount covers everything, in which case nothing
     * is collected).
     *
     * @param  list<string>  $addons
     */
    protected function changeSubscriber(string $plan, int $seats, array $addons, string $interval, string $discount): TestBillable
    {
        if ($discount === 'waiver_gift' && ! in_array('softdrinks', $addons, true)) {
            $addons[] = 'softdrinks';
        }

        $billable = $this->makeActiveSubscriber($plan, $interval, ['active_addon_codes' => array_values($addons)]);
        $this->setIpCountry('DE');

        $billable->forceFill(['subscription_meta' => array_merge(
            $billable->getBillingSubscriptionMeta(),
            ['seat_count' => $seats, 'used_seats' => 1],
        )])->save();
        $billable->refresh();

        $catalog = app(SubscriptionCatalogInterface::class);
        $net = SubscriptionAmount::net($catalog, $billable, $plan, $interval, $seats, $addons);
        $code = strtoupper('CHG'.substr(uniqid(), -6));

        if (in_array($discount, ['pct', 'fixed', 'full'], true)) {
            $coupon = $this->coupons()->create([
                'code' => $code,
                'name' => 'Change matrix '.$discount,
                'type' => CouponType::Recurring,
                'discount_type' => $discount === 'fixed' ? DiscountType::Fixed : DiscountType::Percentage,
                'discount_value' => match ($discount) {
                    'fixed' => 500,
                    'full' => 100,
                    default => 20,
                },
                'max_redemptions_per_billable' => 12,
            ]);
            $this->coupons()->applyRecurringMarker($coupon, $billable, $net, $interval);

            if ($discount === 'full') {
                $billable->refresh();
                $meta = $billable->getBillingSubscriptionMeta();
                $meta[ChargeDeferral::META_KEY] = Carbon::parse((string) $meta['active_recurring_coupon']['valid_until'])->addDay()->toIso8601String();
                $billable->forceFill(['subscription_meta' => $meta])->save();
            }
        }

        if (str_starts_with($discount, 'waiver')) {
            $granted = $discount === 'waiver_gift' ? ['softdrinks'] : [];
            $grant = $this->coupons()->accessGrantCoupon($code, $plan, $interval, $granted, 90);

            app(PlanWaiver::class)->grant(
                $billable,
                $grant,
                $plan,
                $interval,
                $granted,
                // Past the next renewal either way, so that the waiver — not the end it
                // brings — is the state under test: 75 days on a monthly cycle, 440 on a
                // yearly one.
                $discount === 'waiver_periods' ? null : now()->addDays($interval === 'yearly' ? 440 : 75),
                $discount === 'waiver_periods' ? 4 : null,
                $discount === 'waiver_paid' ? PlanWaiver::ON_EXPIRY_CONTINUE_PAID : PlanWaiver::ON_EXPIRY_END,
            );
        }

        // The test Mollie holds what the package sends for this configuration …
        app(PlanWaiver::class)->syncMollie($billable->refresh());

        // … and has just collected it, so the running period is paid for.
        if ($this->recurringNet($billable) > 0) {
            // The renewal that pays the running period was due yesterday: the package
            // anchors the period to the day Mollie scheduled the charge for.
            DB::table('fake_mollie_subscriptions')
                ->where('id', (string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id'])
                ->update(['next_payment_date' => now()->subDay()->toDateString()]);
            $this->opsRenew($billable, (string) $this->mollieSubscriptionAmount($billable));
        } else {
            $billable->forceFill(['subscription_period_starts_at' => now()->subDay()])->save();
        }

        return $billable->refresh();
    }

    protected function recurringNet(TestBillable $billable): int
    {
        $billable = $billable->fresh();
        $net = SubscriptionAmount::net(
            app(SubscriptionCatalogInterface::class),
            $billable,
            (string) $billable->getBillingSubscriptionPlanCode(),
            (string) $billable->getBillingSubscriptionInterval(),
            $billable->getBillingSeatCount(),
            $billable->getActiveBillingAddonCodes(),
        );

        return max(0, $net - $this->coupons()->computeMarkerDiscount($billable, $net));
    }

    // ── Doing the change in the browser; each returns the amount shown, signed ──

    /**
     * Seats screen: one step up or down, then "update".
     *
     * @return array{charge: int, refund: int} gross minor units shown
     */
    protected function changeSeats(Browser $browser, TestBillable $billable, int $step): array
    {
        $this->actAs($browser, $billable, '/billing/seats');
        $browser->waitFor('[wire\\:click="increment"]', 15)->pause(400);

        $this->clickWire($browser, $step > 0 ? '[wire\\:click="increment"]' : '[wire\\:click="decrement"]');

        $dusk = $step > 0 ? 'seats-due-now' : 'seats-credit';
        $browser->pause(1200);
        // No line at all where nothing moves: a seat that was never paid for (a discount
        // that covered everything) has nothing to give back.
        $amount = $this->domCount($browser, '[dusk="'.$dusk.'"]') > 0 ? $this->shownMinor($browser, $dusk) : 0;
        $this->shot($browser, 'Sitze: Betrag vor dem Bestätigen');

        $this->clickWire($browser, '[wire\\:click="syncSeats"]');
        $browser->pause(1500);

        return $step > 0 ? ['charge' => $amount, 'refund' => 0] : ['charge' => 0, 'refund' => $amount];
    }

    /**
     * Addons screen: open the confirm dialog, read the amount, confirm.
     *
     * @return array{charge: int, refund: int}
     */
    protected function toggleAddon(Browser $browser, TestBillable $billable, string $code, bool $enable): array
    {
        $this->actAs($browser, $billable, '/billing/addons');
        $action = ($enable ? 'enableAddon' : 'disableAddon')."('{$code}')";
        $this->waitForPresence($browser, '[wire\\:click="'.$action.'"]', 15);
        $browser->pause(400);

        $dusk = ($enable ? 'addon-due-now-' : 'addon-credit-').$code;

        // The amount sits inside the (still closed) dialog; it is in the DOM either way.
        // A change that moves nothing — a granted addon — shows no amount at all.
        $amount = $this->domCount($browser, '[dusk="'.$dusk.'"]') > 0
            ? $this->shownMinor($browser, $dusk, hidden: true)
            : 0;

        $this->confirmInModal($browser, ($enable ? 'enable-addon-' : 'disable-addon-').$code, '[wire\\:click="'.$action.'"]');
        $browser->pause(1500);
        $this->shot($browser, 'Addon: nach dem Bestätigen');

        return $enable ? ['charge' => $amount, 'refund' => 0] : ['charge' => 0, 'refund' => $amount];
    }

    /**
     * Plan screen: pick the target, optionally keep the extra seats, apply immediately.
     * The charge and the credit are two movements at Mollie and two figures on screen.
     *
     * @return array{charge: int, refund: int}
     */
    protected function changePlan(Browser $browser, TestBillable $billable, string $target, ?string $interval = null, bool $keepSeats = false): array
    {
        $this->actAs($browser, $billable, '/billing/plan');
        // The current plan has no "select" button under its own interval.
        $browser->waitFor('[wire\\:click^="previewFor"]', 15)->pause(400);

        if ($interval !== null) {
            $this->wireSet($browser, '[wire\\:click^="previewFor"]', ['selectedInterval' => $interval]);
            $browser->waitFor('[wire\\:click="previewFor(\''.$target.'\')"]', 15)->pause(300);
        }

        $this->clickWire($browser, '[wire\\:click="previewFor(\''.$target.'\')"]');
        $browser->waitFor('[dusk="seats-after-change"]', 15)->pause(400);

        if ($target === 'pro' && $interval === null && $billable->getBillingSubscriptionPlanCode() === 'business') {
            // 12 seats, 1 in use, pro includes 3: the change lands on 3 and says so; the
            // other 9 are the customer's to keep.
            $browser->assertSeeIn('[dusk="seats-after-change"]', trans_choice('billing::portal.preview_seats_after_change', 3, ['count' => 3]));
            $browser->assertSee(trans_choice('billing::portal.preview_seats_keep_extra_count', 9, ['count' => 9]));
        }

        if ($keepSeats) {
            $this->clickWire($browser, '[wire\\:click="toggleKeepExtraSeats"]');
            $browser->pause(900);
            $browser->assertSeeIn('[dusk="seats-after-change"]', trans_choice('billing::portal.preview_seats_after_change', 12, ['count' => 12]));
            $browser->assertSee(__('billing::portal.preview_seats_drop_extra'));
        }

        $shown = [
            'charge' => $this->domCount($browser, '[dusk="prorata-total"]') > 0 ? $this->shownMinor($browser, 'prorata-total') : 0,
            'refund' => $this->domCount($browser, '[dusk="prorata-refund-separate"]') > 0 ? $this->shownMinor($browser, 'prorata-refund-separate') : 0,
        ];
        $this->shot($browser, 'Planwechsel: Vorschau');

        $this->clickWire($browser, '[x-on\\:click*="applyChange"]');
        $browser->pause(1500);

        return $shown;
    }

    // ── Reading the screen and the books ────────────────────────────────────

    /** A `dusk`-anchored amount as minor units (always positive). */
    protected function shownMinor(Browser $browser, string $dusk, bool $hidden = false): int
    {
        $text = $hidden
            ? (string) $browser->script('return document.querySelector(\'[dusk="'.$dusk.'"]\').textContent;')[0]
            : $browser->text('[dusk="'.$dusk.'"]');

        return (int) preg_replace('/\D/', '', $text);
    }

    /** @return array{0: int, 1: list<string>, 2: list<string>} highest invoice id, refund ids and payment ids so far */
    protected function documentIds(TestBillable $billable): array
    {
        return [
            (int) BillingInvoice::query()->max('id'),
            array_column(DB::table('fake_mollie_refunds')->get(['id'])->all(), 'id'),
            array_column(DB::table('fake_mollie_payments')->get(['id'])->all(), 'id'),
        ];
    }

    /** The prorata charge is created `paid` at the fake; its webhook is Phase 2. */
    protected function settlePendingCharge(TestBillable $billable): void
    {
        $pending = $billable->fresh()->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'] ?? null;

        if (is_string($pending) && $pending !== '') {
            $this->deliverWebhook($pending);
        }

        // Anything else the change collected (a usage overage it could not rebalance) is a
        // payment of its own with a webhook of its own.
        foreach (DB::table('fake_mollie_payments')->where('status', 'paid')->pluck('id') as $id) {
            if ($id !== $pending && ! BillingInvoice::query()->where('mollie_payment_id', $id)->exists()) {
                $this->deliverWebhook((string) $id);
            }
        }
    }

    /**
     * What moved since `$before`, gross minor units: [collected at the test Mollie,
     * refunded at the test Mollie, booked on invoices, booked on credit notes].
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    protected function movedSince(TestBillable $billable, array $before): array
    {
        [$invoiceId, $refundIds, $paymentIds] = $before;

        $collected = 0;
        foreach (DB::table('fake_mollie_payments')->whereNotIn('id', $paymentIds)->where('status', 'paid')->get() as $payment) {
            $collected += BillingMoney::minorFromMollieAmount((string) $payment->amount_value, 'EUR');
        }

        $refunded = 0;
        foreach (DB::table('fake_mollie_refunds')->whereNotIn('id', $refundIds)->get() as $refund) {
            $refunded += BillingMoney::minorFromMollieAmount((string) $refund->amount_value, 'EUR');
        }

        $charged = 0;
        $credited = 0;
        foreach (BillingInvoice::query()->where('billable_id', $billable->getKey())->where('id', '>', $invoiceId)->get() as $invoice) {
            if ($invoice->invoice_kind === InvoiceKind::Refund) {
                $credited += abs((int) $invoice->amount_gross);
            } else {
                $charged += (int) $invoice->amount_gross;
            }
        }

        return [$collected, $refunded, $charged, $credited];
    }

    protected function assertRenewsAtWhatMollieHolds(TestBillable $billable, string $what): void
    {
        $billable = $billable->fresh();

        if ($billable->getBillingSubscriptionSource() !== 'mollie' || $this->recurringNet($billable) === 0) {
            // Nothing is collected while the discount covers everything; the charge is
            // deferred past it.
            if ($this->recurringNet($billable) === 0 && $billable->getBillingSubscriptionSource() === 'mollie') {
                $this->assertNotNull(ChargeDeferral::until($billable), "{$what}: a fully covered subscription has its charge deferred");
            }

            return;
        }

        $held = (string) $this->mollieSubscriptionAmount($billable);
        $renewal = $this->opsRenew($billable, $held);

        $this->assertSame(
            BillingMoney::minorFromMollieAmount($held, 'EUR'),
            (int) $renewal->amount_gross,
            "{$what}: the renewal is invoiced at what the test Mollie holds",
        );
        $this->assertNotContains(
            'adjustment',
            array_column((array) $renewal->line_items, 'kind'),
            "{$what}: the renewal needs no adjustment line",
        );
        $this->assertSame(InvoiceKind::Subscription, $renewal->invoice_kind);
    }
}
