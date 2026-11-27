<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\SandboxBrowser;

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Jobs\RetryRefundLineJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Webhook\UnwantedPaymentRefund;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * The retry of an automatic refund whose run threw after Mollie had paid out — against the
 * real Mollie test API, on a payment that really settled.
 *
 * `UnwantedPaymentRefund` parks a refund whose `createRefund()` threw under
 * `pending_refund_retries`, marked "outcome unknown". The retry then relies on two things
 * only Mollie can confirm: a refund lists the `metadata` it was created with (the token is
 * how the retry recognises its own refund), and one refund per token — the retry adopts the
 * refund instead of posting a second one. The feature suite fakes both
 * (`UnwantedPaymentRefundThrowRetryTest`); this measures them.
 */
class UnwantedPaymentRefundRetryContractTest extends SandboxBrowserTestCase
{
    /** @return list<object> */
    private function refundsAtMollie(string $paymentId): array
    {
        $payment = Mollie::send(new GetPaymentRequest($paymentId));
        $refunds = [];

        foreach ($payment->refunds() as $refund) {
            $refunds[] = $refund;
        }

        return $refunds;
    }

    public function test_a_refund_whose_credit_note_failed_is_adopted_by_the_retry_not_paid_twice(): void
    {
        $billable = $this->newCustomer();
        $payment = $this->checkout($billable, ['plan_code' => 'pro', 'amount_gross' => 1200], 'paid', 'unwanted-refund-retry');
        $this->assertSame('paid', (string) $payment->status);
        $this->mollieSubscriptionOf($billable);

        $invoice = BillingInvoice::query()->where('mollie_payment_id', (string) $payment->id)->sole();

        // Mollie pays the refund out; writing its credit note then fails once.
        $failures = 1;
        BillingInvoice::creating(function (BillingInvoice $note) use (&$failures): void {
            if ($note->invoice_kind === InvoiceKind::Refund && $failures-- > 0) {
                throw new \RuntimeException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
            }
        });

        app(UnwantedPaymentRefund::class)->settle(
            $payment,
            $billable->refresh(),
            UnwantedPaymentRefund::REASON_ACTIVATION_FAILED,
            'Subscription could not be activated',
        );

        $billable->refresh();
        $pending = array_values((array) ($billable->getBillingSubscriptionMeta()['pending_refund_retries'] ?? []));
        $token = UnwantedPaymentRefund::fullRefundToken($invoice);

        $this->assertCount(1, $pending, 'The refund whose run threw must be queued for retry.');
        $this->assertTrue((bool) ($pending[0]['line']['refund_outcome_unknown'] ?? false));
        $this->assertSame($token, $pending[0]['line']['refund_token'] ?? null);

        // Measured: the refund exists at Mollie and lists the token it was created with.
        $before = $this->refundsAtMollie((string) $payment->id);
        $this->assertCount(1, $before, 'Mollie must hold exactly the one refund the first run posted.');
        $metadata = $before[0]->metadata ?? null;
        $listedToken = is_object($metadata) ? ($metadata->refund_token ?? null) : (is_array($metadata) ? ($metadata['refund_token'] ?? null) : null);
        $this->assertSame($token, $listedToken, 'Mollie must list a refund with the metadata it was created with.');

        // The retry, as the sweep would run it.
        $job = new RetryRefundLineJob(TestBillable::class, $billable->getKey(), (array) $pending[0]['line'], (string) $pending[0]['first_attempt_at']);
        app()->call([$job, 'handle']);

        $after = $this->refundsAtMollie((string) $payment->id);
        $creditNote = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->sole();

        $this->assertCount(1, $after, 'The retry must adopt the refund Mollie already holds, not post a second one.');
        $this->assertSame((string) $before[0]->id, (string) ($creditNote->line_items[0]['mollie_refund_id'] ?? ''));
        $this->assertSame(-1200, (int) $creditNote->amount_gross);
        $this->assertSame((int) $invoice->amount_net, (int) $invoice->refresh()->refunded_net);
        $this->assertSame([], array_values((array) ($billable->refresh()->getBillingSubscriptionMeta()['pending_refund_retries'] ?? [])));
    }
}
