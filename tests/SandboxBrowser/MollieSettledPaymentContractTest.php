<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\SandboxBrowser;

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CancelPaymentRefundRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Api\Http\Requests\RevokeMandateRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * What Mollie does with a payment that has really settled — measured.
 *
 * `tests/Sandbox` cannot settle a payment, so everything the package assumes about
 * refunds was taken from the API reference. A payment paid on Mollie's hosted test page
 * closes that gap. Each test states the assumption it checks; where the measurement and
 * the package disagree, the test fails with what was measured.
 *
 * Every measurement is also written to `screenshots/measurements.json`, so the numbers
 * the notes quote can be re-read after a run.
 */
class MollieSettledPaymentContractTest extends SandboxBrowserTestCase
{
    /**
     * A customer with a paid first payment, an invoice and an active subscription.
     *
     * @return array{0: TestBillable, 1: object, 2: BillingInvoice}
     */
    private function paidSignup(string $label): array
    {
        $billable = $this->newCustomer();
        $payment = $this->checkout($billable, ['plan_code' => 'pro', 'amount_gross' => 1200], 'paid', $label);

        $this->assertSame('paid', (string) $payment->status);
        $this->mollieSubscriptionOf($billable);

        return [$billable->refresh(), $payment, BillingInvoice::query()->where('mollie_payment_id', (string) $payment->id)->sole()];
    }

    /** @param array<string, mixed> $measured */
    private function record(string $what, array $measured): void
    {
        $path = self::artifactDirectory().'/measurements.json';
        $all = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
        $all[$what] = $measured + ['measured_at' => now()->toIso8601String()];

        file_put_contents($path, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        fwrite(STDERR, "\nMEASURED {$what}: ".json_encode($measured)."\n");
    }

    /** @return list<array{id: string, status: string, value: string}> */
    private function refundsOf(string $paymentId): array
    {
        $payment = Mollie::send(new GetPaymentRequest($paymentId));
        $rows = [];

        foreach ($payment->refunds() as $refund) {
            $rows[] = ['id' => (string) $refund->id, 'status' => (string) $refund->status, 'value' => (string) $refund->amount->value];
        }

        return $rows;
    }

    public function test_a_refund_of_a_settled_payment_is_listed_with_a_status_and_synced_once(): void
    {
        [$billable, $payment, $invoice] = $this->paidSignup('refund');

        // The package's own refund: 5.00 net → 6.00 gross of the 12.00.
        $creditNote = app(RefundInvoiceService::class)->refundPartially($invoice, 500, RefundReasonCode::Goodwill, 'sandbox');
        $refundId = (string) ($creditNote->line_items[0]['mollie_refund_id'] ?? '');

        $this->assertNotSame('', $refundId, 'The credit note must carry the Mollie refund id.');

        $after = Mollie::send(new GetPaymentRequest((string) $payment->id));
        $listed = $this->refundsOf((string) $payment->id);

        $this->record('refund_of_settled_payment', [
            'refunds' => $listed,
            'payment_status' => (string) $after->status,
            'amount_refunded' => $after->amountRefunded->value ?? null,
            'amount_remaining' => $after->amountRemaining->value ?? null,
        ]);

        // Assumptions of RefundHandler: the refund is listed, by the id we stored, with a
        // status that is one of Mollie's documented ones; the payment stays `paid`.
        $this->assertSame('paid', (string) $after->status);
        $this->assertCount(1, $listed);
        $this->assertSame($refundId, $listed[0]['id']);
        $this->assertSame('6.00', $listed[0]['value']);
        $this->assertContains($listed[0]['status'], ['queued', 'pending', 'processing', 'refunded']);

        // The webhook Mollie sends for the refund books nothing a second time.
        $this->deliverMollieWebhook((string) $payment->id)->assertStatus(200);
        $this->assertSame(1, BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count());
        $this->assertSame(500, (int) $invoice->refresh()->refunded_net);
    }

    public function test_a_cancelled_refund_is_reversed_in_the_books(): void
    {
        [$billable, $payment, $invoice] = $this->paidSignup('refund-cancel');

        $creditNote = app(RefundInvoiceService::class)->refundPartially($invoice, 500, RefundReasonCode::Goodwill, 'sandbox');
        $refundId = (string) $creditNote->line_items[0]['mollie_refund_id'];
        $before = $this->refundsOf((string) $payment->id);

        $cancelled = true;
        $error = null;

        try {
            Mollie::send(new CancelPaymentRefundRequest((string) $payment->id, $refundId));
        } catch (ApiException $e) {
            $cancelled = false;
            $error = $e->getMessage();
        }

        $after = $this->refundsOf((string) $payment->id);
        $paymentAfter = Mollie::send(new GetPaymentRequest((string) $payment->id));

        $this->record('cancelled_refund', [
            'status_before_cancel' => $before[0]['status'] ?? null,
            'cancel_accepted' => $cancelled,
            'cancel_error' => $error,
            'refunds_after_cancel' => $after,
            'amount_refunded_after' => $paymentAfter->amountRefunded->value ?? null,
        ]);

        if (! $cancelled) {
            $this->markTestIncomplete('Mollie did not let the refund be cancelled ('.$error.') — measured and recorded; nothing to reverse.');
        }

        $this->deliverMollieWebhook((string) $payment->id)->assertStatus(200);
        $invoice->refresh();

        $stillListed = collect($after)->firstWhere('id', $refundId);

        if ($stillListed !== null && in_array($stillListed['status'], ['canceled', 'failed'], true)) {
            // The case RefundHandler is written for: listed, with a status saying it did not pay out.
            $this->assertSame(1000, $invoice->remainingRefundableNet(), 'A cancelled refund must be taken out of the books again.');

            return;
        }

        if ($stillListed === null) {
            // Mollie drops a cancelled refund from the list. RefundHandler reverses on an
            // explicit status only, so the credit note would stand for money that never left.
            $this->assertSame(
                1000,
                $invoice->remainingRefundableNet(),
                'Mollie removes a cancelled refund from refunds() instead of listing it as canceled — the package has to reverse a booked refund that is no longer listed.',
            );

            return;
        }

        $this->fail('A cancelled refund is still listed as '.$stillListed['status'].' — see measurements.json.');
    }

    public function test_a_first_payment_can_be_refunded_after_its_mandate_was_revoked(): void
    {
        [$billable, $payment, $invoice] = $this->paidSignup('refund-revoked');

        Mollie::send(new RevokeMandateRequest((string) $billable->getMollieCustomerId(), (string) $billable->getMollieMandateId()));

        $refunded = true;
        $error = null;

        try {
            app(RefundInvoiceService::class)->refundPartially($invoice, 500, RefundReasonCode::Goodwill, 'sandbox');
        } catch (\Throwable $e) {
            $refunded = false;
            $error = $e->getMessage();
        }

        $this->record('refund_after_mandate_revoked', [
            'refund_accepted' => $refunded,
            'error' => $error,
            'refunds' => $this->refundsOf((string) $payment->id),
        ]);

        // UnwantedPaymentRefund and GrantExtrasPurchaseRefund both return a first payment
        // whose mandate is revoked in the same breath; that only works if this holds.
        $this->assertTrue($refunded, 'Mollie refused to refund a first payment once its mandate was revoked: '.$error);
    }

    public function test_a_recurring_payment_on_the_captured_mandate_is_created_now_and_paid_later(): void
    {
        [$billable, $payment] = $this->paidSignup('recurring');

        $recurring = Mollie::send(new CreatePaymentRequest(
            description: 'Sandbox recurring charge',
            amount: new Money('EUR', '5.95'),
            metadata: ['type' => 'overage', 'billable_id' => (string) $billable->getKey()],
            sequenceType: 'recurring',
            mandateId: (string) $billable->getMollieMandateId(),
            customerId: (string) $billable->getMollieCustomerId(),
        ));

        $states = [];
        for ($i = 0; $i < 4; $i++) {
            $current = Mollie::send(new GetPaymentRequest((string) $recurring->id));
            $states[] = ['after_seconds' => $i * 5, 'status' => (string) $current->status, 'paid_at' => $current->paidAt ?? null];

            if ((string) $current->status === 'paid') {
                break;
            }

            sleep(5);
        }

        $this->record('recurring_payment_on_mandate', [
            'method' => (string) ($recurring->method ?? ''),
            'created_at' => (string) $recurring->createdAt,
            'states' => $states,
            'mandate_method' => (string) ($payment->method ?? ''),
        ]);

        // What SubscriptionPaymentHandler::cycleStart() relies on: a charge on a mandate
        // exists (createdAt) before it is paid (paidAt), so the two are different moments.
        $this->assertNotEmpty($recurring->createdAt ?? null);
        $this->assertLessThan(120, abs(\Carbon\Carbon::parse((string) $recurring->createdAt)->diffInSeconds(now(), false)));
        $this->assertContains($states[0]['status'], ['pending', 'open', 'paid']);

        if ($states[0]['status'] !== 'paid') {
            $this->assertEmpty($states[0]['paid_at'], 'A charge that is not paid yet carries no paidAt.');
        }
    }

    public function test_the_test_system_offers_no_way_to_charge_a_payment_back(): void
    {
        [, $payment] = $this->paidSignup('chargeback');

        $fresh = Mollie::send(new GetPaymentRequest((string) $payment->id));
        $chargebacks = [];

        foreach ($fresh->chargebacks() as $chargeback) {
            $chargebacks[] = (string) $chargeback->id;
        }

        $this->record('chargeback', [
            'chargebacks_on_a_paid_payment' => $chargebacks,
            'amount_charged_back' => $fresh->amountChargedBack->value ?? null,
            'note' => 'The API has no endpoint that creates a chargeback, and the hosted test page offers paid/failed/canceled/expired only.',
        ]);

        // Pinned, so the day Mollie's test mode grows a chargeback this says so.
        $this->assertSame([], $chargebacks);
        $this->assertEmpty($fresh->amountChargedBack->value ?? null);
    }
}
