<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Support;

use GraystackIT\MollieBilling\Support\MollieCall;
use GraystackIT\MollieBilling\Support\MolliePaymentCredits;
use Mollie\Api\Http\Requests\GetPaginatedPaymentChargebacksRequest;
use Mollie\Api\Http\Requests\GetPaginatedPaymentRefundsRequest;
use Mollie\Api\Resources\Payment;

/**
 * The test suite's {@see MolliePaymentCredits}.
 *
 * Most webhook tests hand the controller a payment stub (an anonymous class, or
 * `LongRunPayment`) whose `refunds()` / `chargebacks()` list what Mollie would answer.
 * For such a stub this double builds the typed request the real reader would send, holds
 * it to the same checks {@see MollieCall::send()} makes — so a list read from inside a
 * transaction still fails the test — and answers from the stub.
 *
 * An SDK {@see Payment} is never answered here: it goes the production way, through
 * MollieCall and whatever client the test faked (`Mollie::fake()` with SDK responses —
 * see ChargebackSdkContractTest).
 */
class StubAwarePaymentCredits extends MolliePaymentCredits
{
    public function refunds(object $payment): array
    {
        if (! $this->isStub($payment, 'refunds')) {
            return parent::refunds($payment);
        }

        MollieCall::assertSendable(new GetPaginatedPaymentRefundsRequest((string) $payment->id, null, self::PAGE_SIZE));

        return $this->listed($payment->refunds());
    }

    public function chargebacks(object $payment): array
    {
        if (! $this->isStub($payment, 'chargebacks')) {
            return parent::chargebacks($payment);
        }

        MollieCall::assertSendable(new GetPaginatedPaymentChargebacksRequest((string) $payment->id, null, self::PAGE_SIZE));

        return $this->listed($payment->chargebacks());
    }

    private function isStub(object $payment, string $method): bool
    {
        return ! $payment instanceof Payment && method_exists($payment, $method);
    }

    /**
     * @param  iterable<int, object>  $items
     * @return list<object>
     */
    private function listed(iterable $items): array
    {
        $list = [];

        foreach ($items as $item) {
            $list[] = $item;
        }

        return $list;
    }
}
