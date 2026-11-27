<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Tests\Browser\Matrix\OperationsWorkbench;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Workbench\App\FakeMollie\FakeMollie;
use Workbench\App\Support\WorkbenchSetting;

/**
 * The overage final invoice of an immediate cancellation states the real end.
 *
 * Period 11 February – 11 March, an operator cancels "now" on 20 February with 20 SMS beyond
 * the quota. The overage is charged at once; its invoice used to state the period up to
 * 11 March, a billing date the subscription never reached. It ends with the cancellation day
 * now: the period bound is 21 February 00:00 UTC, the service ran through 20 February.
 */
class ImmediateCancelOverageInvoiceTest extends BrowserTestCase
{
    use OperationsWorkbench;

    protected function setUp(): void
    {
        parent::setUp();

        WorkbenchSetting::set('honour_idempotency_keys', '1');
    }

    protected function tearDown(): void
    {
        $this->setRecurringOutcome('paid');
        $this->setIpCountry(null);

        parent::tearDown();
    }

    public function test_the_overage_invoice_of_an_immediate_cancellation_ends_with_the_cancellation_day(): void
    {
        $this->travelSharedClockTo('2027-02-20 10:00');

        $billable = $this->opsSubscriber('pro');
        $billable->forceFill([
            'subscription_period_starts_at' => Carbon::parse('2027-02-11 09:00', 'UTC'),
        ])->save();
        $billable = $billable->fresh();
        $this->assertSame('2027-03-11', $billable->nextBillingDate()?->toDateString());

        // 20 SMS over Pro's 50: 20 × 15 ct = 3.00 net.
        app(WalletUsageService::class)->debit($billable, 'SMS', 70);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->loginAdmin($browser);
            $browser->visit('/billing/admin/billables/'.$billable->getKey())
                ->waitFor('[wire\\:click="openCancel(true)"]', 15)->pause(400);

            $this->clickWire($browser, '[wire\\:click="openCancel(true)"]');
            $browser->waitFor('textarea[name="cancelNote"]', 10)->pause(300);
            $browser->type('cancelNote', 'Customer left, ticket #4712')->pause(200);
            $this->clickWire($browser, 'form[wire\\:submit="confirmCancel"] button[type="submit"]');
            $browser->waitFor('[dusk="sub-status"]', 15)
                ->waitUsing(20, 200, fn (): bool => str_contains($browser->text('[dusk="sub-status"]'), SubscriptionStatus::Cancelled->label()));
            $this->shot($browser, 'Sofort gekündigt, Overage berechnet');
        });

        $this->assertSame(SubscriptionStatus::Cancelled, $billable->fresh()->getBillingSubscriptionStatus());

        $payments = DB::table('fake_mollie_payments')->get()
            ->map(fn ($row): array => (array) $row)
            ->filter(fn (array $row): bool => (json_decode((string) $row['metadata'], true)['type'] ?? null) === 'overage')
            ->values()
            ->all();
        $this->assertCount(1, $payments, 'the cancellation charged the overage once');

        if ($payments[0]['status'] !== 'paid') {
            FakeMollie::settle($payments[0]['id'], 'paid');
        }

        $this->travelSharedClockTo('2027-02-22 09:00');
        $this->assertSame(200, $this->deliverWebhook($payments[0]['id']));

        $invoice = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Overage->value)->sole();
        $this->assertSame(300, (int) $invoice->amount_net);
        $this->assertSame('2027-02-11', $invoice->period_start?->utc()->toDateString());
        $this->assertSame('2027-02-21 00:00:00', $invoice->period_end?->utc()->toDateTimeString(), 'the real end, not the billing date never reached');

        $this->browse(function (Browser $browser) use ($invoice): void {
            $this->loginAdmin($browser);
            $browser->visit('/billing/admin/invoices')
                ->waitFor('button[wire\\:click="openDetail('.$invoice->id.')"]', 15)->pause(400);
            $this->clickWire($browser, 'button[wire\\:click="openDetail('.$invoice->id.')"]');
            $browser->waitFor('[dusk="invoice-detail-net"]', 15)->pause(400);

            $browser->assertSee('2027-02-11 – 2027-02-21');
            $browser->assertDontSee('2027-02-11 – 2027-03-11');
            $this->shot($browser, 'Overage-Schlussrechnung: Zeitraum bis zum Kündigungstag');
        });
    }
}
