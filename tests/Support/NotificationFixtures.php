<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Support;

use Carbon\CarbonImmutable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * Fixtures for the notification tests. Lives in an autoloaded class rather than
 * in one of the test files so every notification test file can be run on its own.
 */
class NotificationFixtures
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function billable(array $overrides = []): TestBillable
    {
        /** @var TestBillable $billable */
        $billable = TestBillable::create(array_merge([
            'name' => 'Acme',
            'email' => uniqid('acme-').'@example.test',
            'billing_country' => 'AT',
        ], $overrides));

        return $billable;
    }

    public static function invoice(
        TestBillable $billable,
        InvoiceKind $kind,
        int $gross,
        ?CarbonImmutable $createdAt = null,
    ): BillingInvoice {
        $net = (int) round($gross / 1.2);

        $invoice = BillingInvoice::create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'mollie_payment_id' => 'tr_'.uniqid(),
            'invoice_kind' => $kind,
            'status' => InvoiceStatus::Paid,
            'country' => 'AT',
            'currency' => 'EUR',
            'amount_net' => $net,
            'amount_vat' => $gross - $net,
            'amount_gross' => $gross,
            'line_items' => [],
        ]);

        if ($createdAt !== null) {
            $invoice->forceFill(['created_at' => $createdAt])->save();
        }

        return $invoice->refresh();
    }

    /**
     * Mirrors what ChargeUsageOverageDirectly persists before it creates the
     * Mollie payment — the only record of a charge that has not been invoiced yet.
     */
    public static function pendingOverage(TestBillable $billable, int $gross, string $currency = 'EUR'): void
    {
        $billable->forceFill(['subscription_meta' => [
            'usage_overage' => [
                'payment_id' => 'tr_overage',
                'amount_net' => (int) round($gross / 1.2),
                'amount_gross' => $gross,
                'currency' => $currency,
                'line_items' => [['type' => 'sms', 'quantity' => 4, 'unit_price_net' => 67, 'total_net' => 268]],
            ],
        ]])->save();
    }

    /**
     * Subject plus body of the rendered mail, for substring assertions.
     */
    public static function mailText(Notification $notification, object $notifiable): string
    {
        $mail = $notification->toMail($notifiable);

        return $mail->subject.' '.implode(' ', $mail->introLines);
    }

    /**
     * An exception whose trace carries a closure argument. PHP refuses to
     * serialize such a trace, so a queued notification must not hold on to the
     * Throwable itself.
     */
    public static function unserializableException(string $message = 'mollie declined'): Throwable
    {
        try {
            (fn (\Closure $c) => throw new \RuntimeException($message))(fn () => 1);
        } catch (Throwable $e) {
            return $e;
        }

        throw new \LogicException('unreachable');
    }
}
