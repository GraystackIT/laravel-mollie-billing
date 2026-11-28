<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use Brick\Money\Currency;
use Brick\Money\Exception\UnknownCurrencyException;
use Brick\Money\Money;
use Elegantly\Invoices\Pdf\PdfInvoice;
use Elegantly\Invoices\Pdf\PdfInvoiceItem;
use Elegantly\Invoices\Support\Address;
use Elegantly\Invoices\Support\Buyer;
use Elegantly\Invoices\Support\PaymentInstruction;
use Elegantly\Invoices\Support\Seller;
use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Events\CreditNoteIssued;
use GraystackIT\MollieBilling\Events\InvoiceCreated;
use GraystackIT\MollieBilling\Events\InvoicePdfRegenerated;
use GraystackIT\MollieBilling\Exceptions\LineItemTotalsMismatchException;
use GraystackIT\MollieBilling\Exceptions\InvalidBillingInputException;
use GraystackIT\MollieBilling\Exceptions\InvalidRefundTargetException;
use GraystackIT\MollieBilling\Exceptions\RefundExceedsInvoiceAmountException;
use GraystackIT\MollieBilling\Exceptions\RefundExceedsLineAmountException;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Vat\CountryMatchService;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\CountryResolver;
use GraystackIT\MollieBilling\Support\ProrataLine;
use Mollie\Api\Http\Data\Money as MollieMoney;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\GetPaymentRefundRequest;
use Mollie\Laravel\Facades\Mollie;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use GraystackIT\MollieBilling\Support\MollieCall;
use GraystackIT\MollieBilling\Support\MollieRefusal;
use GraystackIT\MollieBilling\Support\MolliePaymentCredits;

class InvoiceService
{
    use MutatesBillingMeta;

    /** Lease of the per-invoice refund lock. Nothing renews it. */
    private const REFUND_LOCK_SECONDS = 120;

    /**
     * How long after taking a refund lock `createRefund()` trusts what it read under it.
     * Half the lease: past this point the lease may run out before the line is decided, and
     * RefundHandler could book or reverse against the invoice meanwhile — so each line reads
     * again, as it did before the snapshot existed.
     */
    private const REFUND_SNAPSHOT_TRUSTED_SECONDS = 60;

    /**
     * `subscription_meta` key for a prorata payment that is being created: written before
     * Mollie is asked, removed in the same write that records the payment. Still there
     * afterwards means Mollie may hold a payment nothing points at — see
     * {@see resolveChargeInCreation()}.
     */
    public const CHARGE_IN_CREATION_KEY = 'prorata_charge_in_creation';

    /**
     * Whether billing_invoices carries the optional `locale` column, resolved
     * once per process so the check does not run on every invoice.
     */
    private static ?bool $hasLocaleColumn = null;

    public function __construct(
        private readonly VatCalculationService $vat,
        private readonly InvoiceNumberGenerator $numberGenerator,
    ) {}

    /**
     * Maps a Mollie `metadata.type` string to the matching InvoiceKind.
     * Subtypes (`'prorata'`, `'addon'`, `'seats'`) are all classified as `Subscription` —
     * the subtype is readable via line_items[*].kind.
     */
    public static function mapTypeToInvoiceKind(string $type): InvoiceKind
    {
        return match ($type) {
            'subscription', 'prorata', 'addon', 'seats', 'prorata_charge', 'mid_cycle_addon', 'mid_cycle_seats' => InvoiceKind::Subscription,
            'overage' => InvoiceKind::Overage,
            'one_time_order' => InvoiceKind::OneTimeOrder,
            'refund' => InvoiceKind::Refund,
            default => throw new \InvalidArgumentException("Unknown metadata.type: {$type}"),
        };
    }

    /**
     * Build a BillingInvoice from a Mollie payment + line items. VAT is recomputed
     * from the billable's billing country (authoritative). If the net summed over
     * the line items disagrees with the expected net derived from the items, a
     * LineItemTotalsMismatchException is thrown.
     *
     * The stored `amount_vat` is the sum of the per-line VAT, not VAT on the
     * summed net: the PDF taxes every line separately, so only a per-line sum
     * keeps the persisted header identical to the rendered document.
     *
     * A line may carry its own `vat_rate` for mixed-rate invoices, but only
     * where the supply is taxed at all: on a reverse-charged (or otherwise
     * zero-rated) supply every line is forced to 0%.
     *
     * @param  array<int, array<string, mixed>>  $lineItems
     */
    public function createForPayment(
        object $payment,
        string $invoiceKind,
        array $lineItems,
        Billable $billable,
        ?CarbonInterface $periodStart = null,
        ?CarbonInterface $periodEnd = null,
    ): BillingInvoice {
        /** @var Model&Billable $billable */
        $expectedNet = $this->expectedNetFromLineItems($lineItems);
        $summedNet = $this->sumTotalNet($lineItems);

        if ($expectedNet !== $summedNet) {
            throw new LineItemTotalsMismatchException($expectedNet, $summedNet);
        }

        $country = (string) ($billable->getBillingCountry() ?? '');
        $vat = $this->vat->calculate($country, $summedNet, $billable);
        $rate = (float) $vat['rate'];

        // A reverse-charged (or otherwise zero-rated) supply carries no VAT on
        // ANY line. A caller-supplied `vat_rate` is a per-line override for
        // mixed-rate invoices, never a licence to re-introduce tax the country
        // lookup ruled out — and createForPayment() is public API, so the clamp
        // has to live here rather than in the emitters.
        $zeroRated = abs($rate) < 0.0001;

        // Period for line items: prefer the billable's current subscription period
        // (so currentPeriodLines() can later locate them for refunds), else fall back
        // to "now → now+1 month" as a defensive default for one-off charges.
        //
        // A caller that is *starting* a period has to pass it explicitly. The billable's
        // anchor still describes the period that just ended at that point — the renewal
        // handler advances it after the invoice exists — so a renewal invoice was stamped
        // with the expired window. `currentPeriodLines()` drops any line whose period_end is
        // more than a day from the billable's own period end, so it could not find the very
        // invoice that paid for the current period: every mid-period downgrade after a
        // renewal composed its refund against nothing and credited 0.
        $linePeriodStart = $periodStart ?? $billable->getBillingPeriodStartsAt() ?? BillingTime::nowUtc();
        $linePeriodEnd = $periodEnd ?? $billable->nextBillingDate() ?? BillingTime::addInterval(BillingTime::nowUtc(), 'monthly');

        $normalizedLines = array_map(function (array $line) use ($rate, $zeroRated, $linePeriodStart, $linePeriodEnd): array {
            // Normalize legacy kind alias.
            if (($line['kind'] ?? null) === 'seat') {
                $line['kind'] = 'seats';
            }

            $netLine = self::lineNet($line);
            $lineRate = $zeroRated ? 0.0 : (float) ($line['vat_rate'] ?? $rate);
            $vatLine = (int) round($netLine * $lineRate / 100);

            $line['vat_rate'] = $lineRate;
            $line['amount_net'] = $netLine;
            $line['vat_amount'] = $vatLine;
            $line['amount_gross'] = $netLine + $vatLine;
            $line['period_start'] = $line['period_start'] ?? $linePeriodStart->toIso8601String();
            $line['period_end'] = $line['period_end'] ?? $linePeriodEnd->toIso8601String();

            return $line;
        }, $lineItems);

        $summedLineVat = 0;
        foreach ($normalizedLines as $line) {
            $summedLineVat += (int) $line['vat_amount'];
        }

        $invoice = new BillingInvoice();
        $invoice->billable_type = $billable->getMorphClass();
        $invoice->billable_id = $billable->getKey();
        $invoice->mollie_payment_id = (string) $payment->id;
        $invoice->mollie_subscription_id = $payment->subscriptionId ?? null;
        // Map the Mollie metadata-type string onto an InvoiceKind. Today's values (`'prorata'`,
        // `'addon'`, `'seats'`) are all classified as Subscription bookings. The subtype is
        // readable from line_items[*].kind.
        $invoiceKindEnum = self::mapTypeToInvoiceKind($invoiceKind);
        $invoice->invoice_kind = $invoiceKindEnum;
        $invoice->status = InvoiceStatus::Paid;
        $invoice->country = strtoupper($country);
        // From the payment, not from config: this document records money Mollie has
        // already collected, and `BILLING_CURRENCY` can have changed since the charge
        // was created — a renewal booked weeks later would then be labelled with a
        // currency the customer was never charged in, and a refund of it would be
        // rejected by Mollie for a currency mismatch.
        $invoice->currency = self::normalizeCurrency($payment->amount->currency ?? null);
        $invoice->amount_net = (int) $vat['net'];
        $invoice->amount_vat = $summedLineVat;
        $invoice->amount_gross = (int) $vat['net'] + $summedLineVat;
        $invoice->period_start = $linePeriodStart;
        $invoice->period_end = $linePeriodEnd;
        $invoice->line_items = $normalizedLines;
        $invoice->payment_method_details = self::extractPaymentMethodDetails($payment);
        $invoice->refunded_net = 0;
        $invoice->vat_validation_id = $billable->currentVatValidation()?->getKey();
        $invoice->mismatch_id = $billable->latestOpenCountryMismatch()?->id;
        $this->persistWithSerial($invoice, $invoiceKindEnum->value);

        $this->generateAndStorePdf($invoice, $billable);

        event(new InvoiceCreated($billable, $invoice));

        return $invoice;
    }

    /**
     * Create a credit-note BillingInvoice (negative amounts) that references the
     * original invoice. The credit note re-uses the ORIGINAL invoice's per-line
     * VAT rates so that refunds computed at today's rate do not drift from the
     * rates used at the time the invoice was issued.
     *
     * VAT is credited per original line (see {@see creditNoteVat()}), so an
     * invoice that mixes rates — plan at 20%, addon at 10% — credits exactly the
     * VAT it charged and the credit note's header matches its own line items.
     *
     * @param  array<int, array<string, mixed>>|null  $lineItems  Custom line items; if null, one credit line per credited original line is created.
     *
     * @throws RefundExceedsInvoiceAmountException  when more net is credited than the original charged.
     */
    /**
     * @param  array{key:string,value:string}|null  $externalReference
     *   The id of the Mollie refund or chargeback this note reverses, stamped onto every
     *   line BEFORE the row is committed. Callers used to stamp it in a second save, but
     *   between the two the row was already persisted and the synchronous
     *   `CreditNoteIssued` event had run: a throwing app listener or a DB blip there
     *   produced a 500, and the re-delivery found no stamp — so it created a SECOND
     *   credit note for the same refund. `effectiveRefundedNet()` counts the orphan's
     *   lines, so `refunded_net` double-booked, local VAT was under-declared and later
     *   legitimate refunds were refused as "exceeds remaining".
     */
    public function createCreditNote(
        BillingInvoice $original,
        int $amountNet,
        ?array $lineItems = null,
        ?string $description = null,
        ?array $externalReference = null,
        bool $deferDocument = false,
    ): BillingInvoice {
        if ($amountNet <= 0) {
            throw new InvalidBillingInputException('Credit-note net amount must be positive.', 'billing::exceptions.refund.amount_positive');
        }

        // Upper bound, not just a lower one: a caller that derives the net from a
        // gross amount can land above the original (e.g. a per-line-rounded
        // header divided by a single rate), and the surplus would be allocated
        // onto credit lines that exceed the original lines they reverse — with
        // refunded_net summing past amount_net so the invoice never reads as
        // fully refunded. Remaining-balance checks stay with the refund
        // services; this is the invariant of the document itself. Measured against what the
        // invoice holds — its header, except for a settled plan-switch document, whose
        // header is zero while its own charge lines are real, paid-for service.
        if ($amountNet > $original->refundableBaseNet()) {
            throw new RefundExceedsInvoiceAmountException(
                "Credit note of {$amountNet} exceeds invoice {$original->id} net amount {$original->refundableBaseNet()}."
            );
        }

        $creditVat = $this->creditNoteVat($original, $amountNet, $lineItems);
        $creditGross = $amountNet + $creditVat;

        // For custom line_items: caller passes complete lines (with their own vat_rate fields).
        // When null: one refund line per credited original line, so the rendered
        // per-line VAT adds up to the header VAT even across mixed rates.
        if ($lineItems !== null) {
            $resolvedLineItems = $lineItems;
        } else {
            $periodStart = ($original->period_start ?? BillingTime::nowUtc())->toIso8601String();
            $periodEnd = ($original->period_end ?? BillingTime::nowUtc())->toIso8601String();
            $parts = $this->allocateCredit($original, $amountNet);
            $labelPerLine = count($parts) > 1;

            $resolvedLineItems = [];
            foreach ($parts as $part) {
                $resolvedLineItems[] = [
                    'kind' => 'refund',
                    'code' => null,
                    'label' => $labelPerLine && $part['label'] !== null
                        ? __('billing::portal.credit_note_line_label', ['serial' => $original->serial_number, 'label' => $part['label']])
                        : __('billing::portal.credit_note_label', ['serial' => $original->serial_number]),
                    'quantity' => 1,
                    'unit_price_net' => -$part['net'],
                    'amount_net' => -$part['net'],
                    'vat_rate' => $part['rate'],
                    'vat_amount' => -$part['vat'],
                    'amount_gross' => -($part['net'] + $part['vat']),
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'parent_invoice_id' => $original->id,
                    'parent_line_item_index' => $part['index'],
                    // Stamped here, not afterwards — see the constructor docblock.
                    'mollie_refund_id' => ($externalReference['key'] ?? null) === 'mollie_refund_id'
                        ? $externalReference['value']
                        : null,
                ];

                if (($externalReference['key'] ?? null) !== null && $externalReference['key'] !== 'mollie_refund_id') {
                    $resolvedLineItems[count($resolvedLineItems) - 1][$externalReference['key']] = $externalReference['value'];
                }
            }
        }

        $creditNote = new BillingInvoice;
        $creditNote->billable_type = $original->billable_type;
        $creditNote->billable_id = $original->billable_id;
        $creditNote->mollie_payment_id = null;
        $creditNote->mollie_subscription_id = $original->mollie_subscription_id;
        $creditNote->invoice_kind = InvoiceKind::Refund;
        $creditNote->status = InvoiceStatus::Refunded;
        $creditNote->country = $original->country;
        $creditNote->currency = self::normalizeCurrency($original->currency);
        $creditNote->amount_net = -$amountNet;
        $creditNote->amount_vat = -$creditVat;
        $creditNote->amount_gross = -$creditGross;
        $creditNote->line_items = $resolvedLineItems;
        $creditNote->payment_method_details = $original->payment_method_details;
        $creditNote->refund_reason_text = $description;
        $creditNote->refunded_net = 0;
        // Credit notes anchor on the same VAT validation as the original invoice —
        // they are a correction of that specific tax event, not a fresh decision.
        $creditNote->vat_validation_id = $original->vat_validation_id;
        $this->persistWithSerial($creditNote, 'refund');

        // `$deferDocument` lets a caller inside a transaction hand the rendering and the
        // announcement back out again: a DOMPDF render plus a disk write is the longest
        // thing in any refund, and holding the invoice row lock and the serial range across
        // it is what made a lock-wait timeout against a concurrent refund realistic. The
        // caller then calls {@see issueCreditNoteDocument()} once it has committed.
        if (! $deferDocument) {
            $this->issueCreditNoteDocument($creditNote, $original);
        }

        return $creditNote;
    }

    /**
     * Render a credit note's PDF and announce it.
     *
     * Split out of {@see createCreditNote()} so a caller can persist inside a transaction
     * and do this after committing. Safe to call twice — a document already on the disk is
     * kept (see {@see renderDeferredDocument()}) — but nothing calls it twice.
     */
    public function issueCreditNoteDocument(BillingInvoice $creditNote, BillingInvoice $original): void
    {
        /** @var Billable|null $billable */
        $billable = $original->billable()->first();

        if ($billable === null) {
            return;
        }

        $this->renderDeferredDocument($creditNote, $billable);
        self::announceBooked(new CreditNoteIssued($billable, $creditNote, $original));
    }

    /**
     * Dispatch an event about money that has already moved, with a listener's failure
     * reported rather than propagated.
     *
     * Every credit-note announcement comes after the refund left Mollie and the booking
     * committed; a listener cannot take either back. Propagated, its throw read as a
     * failed refund: RetryRefundLineJob was retried with the same line — clamped to what
     * was left, so under a new idempotency key, paying out again — the admin screen showed
     * "the payment provider rejected the request" for a refund that had gone out, and the
     * customer's RefundProcessedNotification was skipped. Same rule as WalletCredited.
     */
    public static function announceBooked(object $event): void
    {
        try {
            event($event);
        } catch (\Throwable $e) {
            // The reporter can fail too; not reported again, which could throw once more.
            rescue(static fn () => report($e), null, false);
        }
    }

    /**
     * VAT to credit when `$amountNet` of `$original` is refunded.
     *
     * Every original line is taxed at its own rate, so the credit VAT is the sum
     * of the per-line VAT — never one line's rate applied to the whole net. For a
     * full credit that reproduces the VAT actually charged; for a partial credit
     * the net is allocated across the lines proportionally first and capped
     * against the VAT earlier credit notes already gave back, so repeated
     * partials can never credit more tax than the invoice charged.
     *
     * @param  array<int, array<string, mixed>>|null  $lineItems  Custom credit lines; when they carry `vat_amount`, they are authoritative.
     */
    public function creditNoteVat(BillingInvoice $original, int $amountNet, ?array $lineItems = null): int
    {
        if ($lineItems !== null) {
            $customVat = 0;
            $hasVat = false;
            foreach ($lineItems as $line) {
                if (array_key_exists('vat_amount', $line)) {
                    $hasVat = true;
                    $customVat += (int) $line['vat_amount'];
                }
            }

            if ($hasVat) {
                return abs($customVat);
            }
        }

        $vat = 0;
        foreach ($this->allocateCredit($original, $amountNet) as $part) {
            $vat += $part['vat'];
        }

        return $vat;
    }

    /**
     * Net to credit for a gross amount that was actually refunded at Mollie.
     *
     * Mollie only ever knows the gross of the original payment; the net/VAT
     * split is ours. Dividing that gross by a single VAT rate mis-splits every
     * invoice whose lines are not uniformly taxed — 45.90 EUR off a 3000@20% +
     * 900@10% invoice backs out 3825 instead of 3900 net, leaving 89 cents of
     * returned money on no credit note at all and `refunded_net` permanently
     * short of `amount_net`. The net is therefore resolved against the
     * invoice's own line composition: the largest remaining net whose per-line
     * credit VAT (see {@see creditNoteVat()}) still fits inside the money
     * Mollie gave back. A gross that covers the whole remaining balance maps
     * onto the remaining net exactly, so a full refund always closes the
     * invoice out.
     *
     * Returns 0 when nothing is left to credit, or when not even one cent of
     * net fits into `$grossAmount`.
     */
    public function creditNetForGross(BillingInvoice $original, int $grossAmount): int
    {
        if ($grossAmount <= 0) {
            return 0;
        }

        $remainingNet = $original->remainingRefundableNet();

        if ($remainingNet <= 0) {
            return 0;
        }

        $grossFor = fn (int $net): int => $net + $this->creditNoteVat($original, $net);

        $remainingGross = $grossFor($remainingNet);

        if ($grossAmount >= $remainingGross) {
            return $remainingNet;
        }

        // The seed is exact up to per-line VAT rounding, so the walk moves a few
        // cents at most. Both loops are bounded because per-part rounding can
        // make gross(net) non-monotonic by a cent and must not be able to spin.
        $net = (int) floor($grossAmount * $remainingNet / max(1, $remainingGross));
        $net = max(1, min($remainingNet, $net));

        for ($i = 0; $i < 8 && $net > 1 && $grossFor($net) > $grossAmount; $i++) {
            $net--;
        }

        for ($i = 0; $i < 8 && $net < $remainingNet && $grossFor($net + 1) <= $grossAmount; $i++) {
            $net++;
        }

        return $grossFor($net) <= $grossAmount ? $net : 0;
    }

    /**
     * The lines of `$original` a refund can be booked against one at a time, with what is
     * left of each.
     *
     * The same lines {@see allocateCredit()} spreads a refund over: discount lines are
     * folded into the line they reduced, so a line offers what was actually paid for it,
     * and its budget is net and VAT charged minus what earlier credit notes gave back on
     * it — never more than the invoice as a whole has left, which a credit booked without
     * a line (a plan switch settled out of this invoice, a legacy refund) can make smaller.
     * Lines with nothing left are left out unless `$withExhausted` asks for them. An
     * invoice whose lines cannot be folded into positive ones offers none — it is refunded
     * as a whole only. A settled plan-switch invoice offers its own charge lines; the
     * credits it carries against the invoice it was settled out of are not its lines.
     *
     * @return list<array{index: int, label: ?string, net: int, rate: float, remaining_net: int, remaining_vat: int}>
     */
    public function refundableLines(BillingInvoice $original, bool $withExhausted = false): array
    {
        $lines = [];
        $invoiceRemaining = $original->remainingRefundableNet();

        foreach ($this->foldedLineParts($original) as $part) {
            $remainingNet = max(0, min($part['net'] - $part['credited_net'], $invoiceRemaining));

            if ($remainingNet <= 0 && ! $withExhausted) {
                continue;
            }

            $lines[] = [
                'index' => $part['index'],
                'label' => $part['label'],
                'net' => $part['net'],
                'rate' => $part['rate'],
                'remaining_net' => $remainingNet,
                'remaining_vat' => max(0, $part['charged_vat'] - $part['credited_vat']),
            ];
        }

        return $lines;
    }

    /**
     * The credit-note line that refunds `$amountNet` of one line of `$original`.
     *
     * Taxed at that line's own rate and inside that line's remaining VAT, exactly as
     * {@see allocateCredit()} taxes a chunk — so a line refunded in several steps gives
     * back precisely the VAT it charged — and pointed at the line through
     * `parent_line_item_index`, which is what later refunds measure the line's budget by.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidRefundTargetException      the index names no line that can be refunded on its own
     * @throws RefundExceedsLineAmountException  more than is left of the line
     */
    public function creditLineFor(BillingInvoice $original, int $lineIndex, int $amountNet): array
    {
        if ($amountNet <= 0) {
            throw new InvalidBillingInputException('Refund amount must be positive.', 'billing::exceptions.refund.amount_positive');
        }

        $part = null;
        foreach ($this->foldedLineParts($original) as $candidate) {
            if ($candidate['index'] === $lineIndex) {
                $part = $candidate;
            }
        }

        if ($part === null) {
            throw (new InvalidRefundTargetException("Line {$lineIndex} of invoice {$original->id} cannot be refunded on its own."))
                ->withTranslation('billing::exceptions.refund.line_not_refundable');
        }

        $remainingNet = max(0, min($part['net'] - $part['credited_net'], $original->remainingRefundableNet()));

        if ($amountNet > $remainingNet) {
            throw new RefundExceedsLineAmountException($lineIndex, $remainingNet, $amountNet);
        }

        $vat = $this->capAtRemainingInvoiceVat($original, $this->partCreditVat([
            'net' => $amountNet,
            'rate' => $part['rate'],
            'charged_net' => $part['net'],
            'charged_vat' => $part['charged_vat'],
            'credited_net' => $part['credited_net'],
            'credited_vat' => $part['credited_vat'],
        ]));

        return [
            'kind' => 'refund',
            'code' => null,
            'label' => $part['label'] !== null
                ? __('billing::portal.credit_note_line_label', ['serial' => $original->serial_number, 'label' => $part['label']])
                : __('billing::portal.credit_note_label', ['serial' => $original->serial_number]),
            'quantity' => 1,
            'unit_price_net' => -$amountNet,
            'amount_net' => -$amountNet,
            'vat_rate' => $part['rate'],
            'vat_amount' => -$vat,
            'amount_gross' => -($amountNet + $vat),
            'period_start' => ($original->period_start ?? BillingTime::nowUtc())->toIso8601String(),
            'period_end' => ($original->period_end ?? BillingTime::nowUtc())->toIso8601String(),
            'parent_invoice_id' => $original->id,
            'parent_line_item_index' => $lineIndex,
            'mollie_refund_id' => null,
        ];
    }

    /**
     * Every line of `$original` with its charged net and VAT and what earlier credit notes
     * gave back on it.
     *
     * @return list<array{index: int, net: int, rate: float, label: ?string, charged_vat: int, credited_net: int, credited_vat: int}>
     */
    private function originalLineParts(BillingInvoice $original): array
    {
        $credited = $original->creditedPerOriginalLine();

        $parts = [];
        foreach ((array) ($original->line_items ?? []) as $index => $line) {
            // A line carrying a `parent_invoice_id` is a credit against ANOTHER invoice — on
            // a settled plan-switch document, the old plan credited out of the invoice that
            // paid for the period. It is not a discount of this invoice's charges, and folded
            // into them it cancelled them out: nothing left to credit on a document whose
            // charge line is real, paid-for service ({@see BillingInvoice::refundableBaseNet()}).
            if (! is_array($line) || ($line['parent_invoice_id'] ?? null) !== null) {
                continue;
            }

            $net = self::lineNet($line);
            $rate = (float) ($line['vat_rate'] ?? 0.0);

            $parts[] = [
                'index' => (int) $index,
                'net' => $net,
                'rate' => $rate,
                'label' => isset($line['label']) ? (string) $line['label'] : (isset($line['description']) ? (string) $line['description'] : null),
                // What the document says this line was taxed — not net × rate:
                // the original rounded per line, so only its own figure adds
                // back up to the header VAT it charged.
                'charged_vat' => array_key_exists('vat_amount', $line) ? (int) $line['vat_amount'] : (int) round($net * $rate / 100),
                'credited_net' => (int) ($credited[(int) $index]['net'] ?? 0),
                'credited_vat' => (int) ($credited[(int) $index]['vat'] ?? 0),
            ];
        }

        return $parts;
    }

    /**
     * {@see originalLineParts()} with the discount lines folded into the lines they
     * reduced; empty when that leaves a line that is not positive.
     *
     * @return list<array{index: int, net: int, rate: float, label: ?string, charged_vat: int, credited_net: int, credited_vat: int}>
     */
    private function foldedLineParts(BillingInvoice $original): array
    {
        $parts = $this->originalLineParts($original);

        $totalNet = 0;
        foreach ($parts as $part) {
            $totalNet += $part['net'];
        }

        return $this->foldNegativeParts($parts, $totalNet);
    }

    /**
     * Split a credited net amount across the original invoice's line items.
     *
     * Every part carries the budget of the line it credits — the net and the VAT
     * that line was actually charged, minus what earlier credit notes already
     * gave back — so a chunk can never credit more tax than is left and the
     * chunk that exhausts a line absorbs the rounding residual exactly (see
     * {@see partCreditVat()}).
     *
     * Discount lines (negative net) are folded into the positive line that
     * carries the same VAT rate, budget included: keeping them as separate
     * credit lines would make `BillingInvoice::derivedRefundedNet()` — which
     * sums the absolute net per refund line — count the discount twice, and
     * dropping their VAT would credit tax the discount had already removed.
     * Degenerate input (no lines, a non-positive total, or a fold that cannot
     * produce positive parts) falls back to a single part at the dominant line's
     * rate, capped against the invoice's own remaining VAT.
     *
     * @return list<array{index: int, net: int, vat: int, rate: float, label: ?string}>
     */
    private function allocateCredit(BillingInvoice $original, int $amountNet): array
    {
        $parts = $this->originalLineParts($original);

        $totalNet = 0;
        foreach ($parts as $part) {
            $totalNet += $part['net'];
        }

        $folded = $this->foldNegativeParts($parts, $totalNet);

        if ($folded === []) {
            $fallback = $this->dominantPart($parts);

            return [[
                'index' => $fallback['index'],
                'net' => $amountNet,
                'vat' => $this->capAtRemainingInvoiceVat($original, (int) round($amountNet * $fallback['rate'] / 100)),
                'rate' => $fallback['rate'],
                'label' => $fallback['label'],
            ]];
        }

        // Proportional allocation, weighted by what each line has LEFT. Weighted by the
        // original shares, a refund that followed a refund of one line (a refund by line,
        // `refundOverageUnits()`, any credit note with custom lines) put that line's share
        // onto a line with nothing left — credited at 0 VAT there, while the line that
        // still had money was taxed on too little: the customer got VAT short. Before
        // anything was credited the two weightings are the same. The residual left by
        // per-part rounding goes to the largest part so the nets add up exactly.
        $remainingTotal = 0;
        foreach ($folded as $part) {
            $remainingTotal += max(0, $part['net'] - $part['credited_net']);
        }

        $byRemaining = $remainingTotal > 0;

        $allocated = [];
        $sum = 0;
        foreach ($folded as $part) {
            $remaining = max(0, $part['net'] - $part['credited_net']);

            $net = match (true) {
                $byRemaining && $amountNet === $remainingTotal => $remaining,
                $byRemaining => (int) round($amountNet * $remaining / $remainingTotal),
                $amountNet === $totalNet => $part['net'],
                default => (int) round($amountNet * $part['net'] / $totalNet),
            };
            $sum += $net;
            // `net` becomes the credited chunk; the line's own (folded) net is
            // kept as the budget partCreditVat() measures the chunk against.
            $part['charged_net'] = $part['net'];
            $part['net'] = $net;
            $allocated[] = $part;
        }

        if ($sum !== $amountNet) {
            $largest = 0;
            foreach ($allocated as $i => $part) {
                if (abs($part['net']) > abs($allocated[$largest]['net'])) {
                    $largest = $i;
                }
            }
            $allocated[$largest]['net'] += $amountNet - $sum;
        }

        $result = [];
        foreach ($allocated as $part) {
            if ($part['net'] === 0) {
                continue;
            }

            $part['vat'] = $this->partCreditVat($part);
            $result[] = $part;
        }

        return $result !== [] ? $result : [[
            'index' => $folded[0]['index'],
            'net' => $amountNet,
            'vat' => $this->capAtRemainingInvoiceVat($original, (int) round($amountNet * $folded[0]['rate'] / 100)),
            'rate' => $folded[0]['rate'],
            'label' => $folded[0]['label'],
        ]];
    }

    /**
     * VAT to credit for one allocated chunk of an original line.
     *
     * Rounding every chunk on its own over-credits across repeated partial
     * refunds: four 25-cent refunds of a 100-cent line at 19% round to 4 × 5 =
     * 20 for 19 charged, and the fourth Mollie refund then asks for more gross
     * than the payment collected — which Mollie rejects, so the last legitimate
     * partial refund becomes impossible. The chunk is therefore capped at the
     * line's not-yet-credited VAT, and the chunk that exhausts the line's
     * remaining net absorbs the whole residual, so the sum over all refunds is
     * exactly the VAT charged.
     *
     * @param  array{net: int, rate: float, charged_net: int, charged_vat: int, credited_net: int, credited_vat: int}  $part
     */
    private function partCreditVat(array $part): int
    {
        $remainingNet = $part['charged_net'] - $part['credited_net'];
        $remainingVat = $part['charged_vat'] - $part['credited_vat'];

        if ($remainingVat <= 0) {
            return 0;
        }

        if ($part['net'] >= $remainingNet) {
            return $remainingVat;
        }

        return min((int) round($part['net'] * $part['rate'] / 100), $remainingVat);
    }

    /**
     * Cap a blended single-part credit at the VAT the invoice has left to give
     * back. Used only on the degenerate fallback paths, where no per-line budget
     * can be established but the invoice-level ceiling still holds — including
     * the case where the header charged no VAT at all (reverse charge), which
     * must not gain any through a blended rate.
     *
     * @param  array<int, array{net: int, vat: int}>  $credited
     */
    private function capAtRemainingInvoiceVat(BillingInvoice $original, int $vat): int
    {
        // Invoice-wide question, so it needs the invoice-wide count. Summing the
        // per-original-line map would miss every credit line without a
        // `parent_line_item_index` — a credit note created with custom `line_items` —
        // and the cap would then permit VAT that has already been credited back.
        // A settled plan-switch document nets to zero in its header; the VAT it can give
        // back is its own charge lines', like its refundable net.
        $chargedVat = $original->settled_by_invoice_id === null
            ? abs((int) $original->amount_vat)
            : max(0, $original->refundableBaseGross() - $original->refundableBaseNet());

        return max(0, min($vat, $chargedVat - $original->creditedVatTotal()));
    }

    /**
     * Merge negative-net parts into the positive part with the same VAT rate
     * (or the largest positive one when no rate matches). Returns an empty list
     * when the merge cannot leave every remaining part positive — the caller
     * then falls back to a single blended part.
     *
     * The negative part's VAT budget travels with its net even when it lands on
     * a differently-taxed line: a discount removed tax from the invoice, so
     * dropping its VAT here would credit tax that was never charged.
     *
     * @param  list<array{index: int, net: int, rate: float, label: ?string, charged_vat: int, credited_net: int, credited_vat: int}>  $parts
     * @return list<array{index: int, net: int, rate: float, label: ?string, charged_vat: int, credited_net: int, credited_vat: int}>
     */
    private function foldNegativeParts(array $parts, int $totalNet): array
    {
        $positives = array_values(array_filter($parts, fn (array $p): bool => $p['net'] > 0));
        $negatives = array_values(array_filter($parts, fn (array $p): bool => $p['net'] < 0));

        if ($positives === [] || $totalNet <= 0) {
            return [];
        }

        foreach ($negatives as $negative) {
            $target = null;
            foreach ($positives as $i => $positive) {
                if (abs($positive['rate'] - $negative['rate']) > 0.0001) {
                    continue;
                }
                if ($target === null || $positives[$i]['net'] > $positives[$target]['net']) {
                    $target = $i;
                }
            }

            if ($target === null) {
                $target = 0;
                foreach ($positives as $i => $positive) {
                    if ($positive['net'] > $positives[$target]['net']) {
                        $target = $i;
                    }
                }
            }

            $positives[$target]['net'] += $negative['net'];
            $positives[$target]['charged_vat'] += $negative['charged_vat'];
            $positives[$target]['credited_net'] += $negative['credited_net'];
            $positives[$target]['credited_vat'] += $negative['credited_vat'];
        }

        foreach ($positives as $positive) {
            if ($positive['net'] <= 0) {
                return [];
            }
        }

        return $positives;
    }

    /**
     * The line that should carry a blended single-part credit: the one with the
     * largest absolute net, so its VAT rate dominates the result. Only the
     * identifying subset of a part is read — the VAT budgets do not survive a
     * blend and the caller caps the result invoice-wide instead.
     *
     * @param  list<array{index: int, net: int, rate: float, label: ?string, ...}>  $parts
     * @return array{index: int, net: int, rate: float, label: ?string}
     */
    private function dominantPart(array $parts): array
    {
        $dominant = ['index' => 0, 'net' => 0, 'rate' => 0.0, 'label' => null];

        foreach ($parts as $part) {
            if (abs($part['net']) > abs($dominant['net'])) {
                $dominant = $part;
            }
        }

        return $dominant;
    }

    /**
     * Generate a PDF from the BillingInvoice data and store it on the configured disk.
     * Failures are logged but do not prevent the invoice from being persisted.
     *
     * Rendering happens under a pinned locale (see {@see pdfLocale()}) so that a
     * document regenerated later — e.g. by InvoiceDownloadController when the blob
     * went missing — comes back in the language and number format of the original.
     */
    protected function generateAndStorePdf(BillingInvoice $invoice, Billable $billable): bool
    {
        $locale = $this->pdfLocale($invoice);

        try {
            $pdfContent = $this->withLocale($locale, function () use ($invoice, $billable): ?string {
                // The vendor template formats every date with `invoices.date_format`.
                $previousFormat = config('invoices.date_format');
                config()->set('invoices.date_format', $this->pdfDateFormat());

                try {
                    return $this->buildPdfInvoice($invoice, $billable)->getPdfOutput();
                } finally {
                    config()->set('invoices.date_format', $previousFormat);
                }
            });

            if ($pdfContent === null) {
                Log::warning('PDF invoice generation returned null', ['invoice_id' => $invoice->id]);

                return false;
            }

            $pdfContent = $this->withLocale($locale, fn (): string => $this->attachEInvoice($pdfContent, $invoice, $billable));

            $disk = (string) config('mollie-billing.invoices.disk', 'local');
            $basePath = rtrim((string) config('mollie-billing.invoices.path', 'billing/invoices'), '/');
            $filename = Str::slug($invoice->serial_number ?? 'invoice-'.$invoice->id).'.pdf';
            $path = $basePath.'/'.$invoice->created_at->format('Y/m').'/'.$filename;

            Storage::disk($disk)->put($path, $pdfContent);

            $invoice->pdf_disk = $disk;
            $invoice->pdf_path = $path;
            $this->rememberPdfLocale($invoice, $locale);
            $invoice->save();

            return true;
        } catch (\Throwable $e) {
            // An invoice without a document is a compliance problem, not a hiccup:
            // log at error level with everything needed to reproduce it (a
            // misspelled currency code is the most common cause).
            Log::error('PDF invoice generation failed', [
                'invoice_id' => $invoice->id,
                'serial_number' => $invoice->serial_number,
                'currency' => $invoice->currency,
                'locale' => $locale,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Turn the rendered PDF into a hybrid ZUGFeRD / Factur-X e-invoice (EN 16931 XML
     * embedded in a PDF/A-3), see {@see EInvoiceService}.
     *
     * A failure here keeps the plain PDF rather than leaving the invoice without any
     * document: the readable invoice is still a valid invoice to the customer, and a
     * missing structured part is repaired by regenerating once the cause (typically
     * incomplete seller data) is fixed. It is logged at error level because from 2027 a
     * German B2B invoice without the structured part is not an e-invoice.
     */
    protected function attachEInvoice(string $pdf, BillingInvoice $invoice, Billable $billable): string
    {
        $eInvoice = app(EInvoiceService::class);

        if (! $eInvoice->isEnabled()) {
            return $pdf;
        }

        try {
            return $eInvoice->embedIntoPdf($pdf, $invoice, $billable);
        } catch (\Throwable $e) {
            Log::error('E-invoice (ZUGFeRD) could not be embedded; the invoice PDF was stored without it', [
                'invoice_id' => $invoice->id,
                'serial_number' => $invoice->serial_number,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return $pdf;
        }
    }

    /**
     * Locale the invoice PDF is rendered in: the locale stored on the invoice
     * when the (optional) `locale` column exists, else the configured invoice
     * locale, else the application's base locale.
     *
     * Never the *active* locale: that is whatever request happens to trigger a
     * (re)generation, so a regenerated document would not match the original.
     * `app.locale` is no anchor either — App::setLocale() writes it — hence the
     * fall back to `app.fallback_locale`, which stays put. Set
     * `mollie-billing.invoices.locale` to choose the invoice language explicitly.
     */
    protected function pdfLocale(BillingInvoice $invoice): string
    {
        $candidates = [
            $invoice->getAttribute('locale'),
            config('mollie-billing.invoices.locale'),
            config('app.fallback_locale'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return 'en';
    }

    /**
     * Persist the rendering locale when the billing_invoices table has a `locale`
     * column. Without the column the configured locale keeps regenerations stable.
     */
    private function rememberPdfLocale(BillingInvoice $invoice, string $locale): void
    {
        self::$hasLocaleColumn ??= Schema::hasColumn($invoice->getTable(), 'locale');

        if (self::$hasLocaleColumn) {
            $invoice->locale = $locale;
        }
    }

    /**
     * Run $callback with the given locale active, restoring the previous one.
     */
    private function withLocale(string $locale, \Closure $callback): mixed
    {
        $previous = app()->getLocale();

        if ($locale === $previous) {
            return $callback();
        }

        app()->setLocale($locale);

        try {
            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }

    /**
     * Re-render the PDF for an existing invoice. Deletes the previous file (if any)
     * before writing the new one to avoid orphaned blobs on the disk. Serial number,
     * line items and amounts are unchanged — this only regenerates the rendered PDF.
     *
     * Returns true when a fresh PDF was persisted, false when the underlying renderer
     * failed (errors are already logged by generateAndStorePdf()).
     */
    public function regeneratePdf(BillingInvoice $invoice): bool
    {
        /** @var Billable|null $billable */
        $billable = $invoice->billable()->first();
        if ($billable === null) {
            throw new \LogicException("Cannot regenerate PDF: invoice {$invoice->id} has no billable.");
        }

        // Render first, delete afterwards. This used to delete the blob and null the
        // pointer *before* rendering, and `generateAndStorePdf()` swallows every failure —
        // so a bad currency code, a font error or an S3 hiccup left the invoice with no
        // document at all and no copy anywhere. On the normal path there is nothing to
        // clean up either: the target path is derived from serial number and created_at,
        // so the new render overwrites the very file that was being deleted.
        $previousDisk = $invoice->pdf_disk;
        $previousPath = $invoice->pdf_path;

        // The renderer's own verdict, not `hasPdf()`: with the old document left in place
        // a failed render still answers "has a PDF", and reporting that as a successful
        // regeneration would hide exactly the failure an operator clicked to fix.
        $success = $this->generateAndStorePdf($invoice, $billable) && $invoice->fresh()?->hasPdf() === true;

        if ($success && $previousPath !== null && $previousDisk !== null
            && ($previousDisk !== $invoice->pdf_disk || $previousPath !== $invoice->pdf_path)
        ) {
            // Only when the new document actually landed somewhere else — otherwise this
            // would delete what was just written.
            try {
                Storage::disk($previousDisk)->delete($previousPath);
            } catch (\Throwable $e) {
                Log::warning('Could not delete the superseded invoice PDF after regeneration', [
                    'invoice_id' => $invoice->id,
                    'disk' => $previousDisk,
                    'path' => $previousPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($success) {
            event(new InvoicePdfRegenerated($billable, $invoice->fresh() ?? $invoice));
        }

        return $success;
    }

    /**
     * The invoice with its PDF on the disk, regenerated first when it is not there; null
     * when it could not be.
     *
     * The DB may reference a PDF that no longer exists on the disk (bucket switched,
     * database copied between environments, blob deleted), or carry no pointer at all —
     * which is what `generateAndStorePdf()` leaves behind when a render fails. Both are
     * regenerable, so both take the same path. Shared by the single download and the
     * admin's ZIP export, so a document is never served from one and missing in the other.
     */
    public function ensureStoredPdf(BillingInvoice $invoice): ?BillingInvoice
    {
        if (self::pdfIsStored($invoice)) {
            return $invoice;
        }

        // Serialised per invoice: a double-click (or several tabs, or an export running
        // next to a download) would otherwise regenerate the same PDF concurrently, and
        // each run deletes the file the other just wrote. The deferred render right after a
        // booking takes the same lock ({@see renderDeferredDocument()}).
        $lock = self::documentLock($invoice);

        try {
            $lock->block(15);

            // The winner may already have rebuilt it while we waited.
            $invoice = $invoice->fresh() ?? $invoice;

            if (! self::pdfIsStored($invoice)) {
                $this->regeneratePdf($invoice);
            }
        } catch (\Throwable $e) {
            Log::warning('Invoice PDF missing on disk and could not be regenerated', [
                'invoice_id' => $invoice->id,
                'disk' => $invoice->pdf_disk,
                'path' => $invoice->pdf_path,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $lock->release();
        }

        $invoice = $invoice->fresh();

        return $invoice instanceof BillingInvoice && self::pdfIsStored($invoice) ? $invoice : null;
    }

    /**
     * The one per-invoice lock every render outside the booking itself takes: a download
     * click ({@see ensureStoredPdf()}) and the deferred render after a commit.
     */
    private static function documentLock(BillingInvoice $invoice): \Illuminate\Contracts\Cache\Lock
    {
        return Cache::lock('billing:invoice-pdf-regenerate:'.$invoice->id, 30);
    }

    /**
     * Render a document that was deferred until after its booking committed — under the
     * same lock as a download click, and not again when that click got there first.
     *
     * The PDF link is shown from the moment the row exists (decision 13), so a customer can
     * click between the commit and this render. Without the shared lock both ran at once
     * and the later write truncated the file the click was streaming. A render does not
     * touch the serial number, so the overlap never cost one; it cost a readable document.
     *
     * A lock that cannot be had in time is no reason to leave the invoice without its
     * document: the render goes ahead, as it did before.
     *
     * @return bool whether the invoice has a stored PDF afterwards
     */
    private function renderDeferredDocument(BillingInvoice $invoice, Billable $billable): bool
    {
        $lock = self::documentLock($invoice);
        $held = false;

        try {
            $held = (bool) $lock->block(15);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            // Rendered regardless — see above.
        }

        try {
            $stored = $invoice->exists ? BillingInvoice::query()->withoutGlobalScopes()->find($invoice->getKey()) : null;

            if ($stored instanceof BillingInvoice && self::pdfIsStored($stored)) {
                // A download click rendered it while the booking was finishing. The
                // caller's model learns where, without a second write over the file.
                $invoice->forceFill([
                    'pdf_disk' => $stored->pdf_disk,
                    'pdf_path' => $stored->pdf_path,
                ])->syncOriginalAttributes(['pdf_disk', 'pdf_path']);

                return true;
            }

            return $this->generateAndStorePdf($invoice, $billable);
        } finally {
            if ($held) {
                $lock->release();
            }
        }
    }

    /** Whether the invoice's PDF is on its disk right now — what ensureStoredPdf() would not have to regenerate. */
    public function hasStoredPdf(BillingInvoice $invoice): bool
    {
        return self::pdfIsStored($invoice);
    }

    private static function pdfIsStored(BillingInvoice $invoice): bool
    {
        if (! $invoice->hasPdf()) {
            return false;
        }

        // A disk that is no longer configured (renamed or removed in a disk migration) holds
        // nothing: missing, so it is regenerated onto the current disk instead of every
        // download failing on Storage::disk(). Errors of a configured disk still surface.
        if (! is_array(config('filesystems.disks.'.$invoice->pdf_disk))) {
            return false;
        }

        return Storage::disk($invoice->pdf_disk)->exists($invoice->pdf_path);
    }

    /**
     * Build a PdfInvoice value object from BillingInvoice data for PDF rendering.
     */
    protected function buildPdfInvoice(BillingInvoice $invoice, Billable $billable): PdfInvoice
    {
        $currency = self::assertKnownCurrency(self::normalizeCurrency($invoice->currency));
        $sellerConfig = (array) config('mollie-billing.invoices.seller', []);
        $addressConfig = (array) ($sellerConfig['address'] ?? []);

        $seller = new Seller(
            company: $sellerConfig['company'] ?? config('app.name'),
            name: $sellerConfig['name'] ?? null,
            address: new Address(
                street: $addressConfig['street'] ?? null,
                city: $addressConfig['city'] ?? null,
                postal_code: $addressConfig['postal_code'] ?? null,
                state: $addressConfig['state'] ?? null,
                country: CountryResolver::name($addressConfig['country'] ?? null),
            ),
            email: $sellerConfig['email'] ?? null,
            phone: $sellerConfig['phone'] ?? null,
            fields: $this->sellerTaxFields(),
        );

        $buyer = new Buyer(
            name: $billable->getBillingName(),
            address: new Address(
                street: $billable->getBillingStreet(),
                city: $billable->getBillingCity(),
                postal_code: $billable->getBillingPostalCode(),
                country: CountryResolver::name($billable->getBillingCountry()),
            ),
            email: $billable->getBillingEmail(),
            fields: $this->buyerTaxFields($invoice, $billable),
        );

        $isCredit = $invoice->invoice_kind === InvoiceKind::Refund;

        // Once per document: for a credit note it looks up the invoices it corrects.
        $deliveryDate = app(EInvoiceService::class)->deliveryDate($invoice);

        $items = [];
        foreach ((array) $invoice->line_items as $item) {
            $qty = (int) ($item['quantity'] ?? $item['qty'] ?? 1);
            $netUnit = (int) ($item['unit_price_net'] ?? $item['unit_price'] ?? 0);
            $lineNet = self::lineNet($item, $qty * $netUnit);

            // The renderer builds its subtotal from quantity × unit_price, while the
            // stored net is what was actually charged. Pro-rata lines legitimately
            // have quantity > 1 with a rounded total (3 seats × 283 ≠ 850), so
            // collapse those to a single unit carrying the true total — otherwise
            // the document total drifts a few cents from amount_net/amount_gross.
            if ($qty !== 1 && $qty * $netUnit !== $lineNet) {
                $qty = 1;
                $netUnit = $lineNet;
            }

            // The line's own text, then its service period — the period is a mandatory
            // statement, the text optional. Joined on one line: the template escapes
            // the description and prints no line breaks.
            $description = implode(' · ', array_filter([
                is_string($item['description'] ?? $item['billing_period'] ?? null)
                    ? trim((string) ($item['description'] ?? $item['billing_period']))
                    : null,
                $this->servicePeriodText($item, $invoice, $billable, $deliveryDate),
            ])) ?: null;

            // Per-item VAT: line_item.vat_rate is the source of truth.
            $taxPercentage = (float) ($item['vat_rate'] ?? 0);

            // The booked VAT wins over the rate whenever it can be expressed per unit.
            // Given only a percentage the renderer recomputes `net × rate`, and the stored
            // figure is not always that number — a partial credit note allocates its VAT
            // across the lines it reverses rather than recomputing each one — so the
            // document totalled a cent or two away from the `amount_gross` it is the
            // document *of*. `unit_tax` takes precedence in the renderer and is multiplied
            // by the quantity, so it is used only when the stored amount divides evenly;
            // otherwise the rate stays, exactly as before.
            $storedVat = array_key_exists('vat_amount', $item) ? (int) $item['vat_amount'] : null;
            $unitTax = $storedVat !== null && $qty > 0 && $storedVat % $qty === 0
                ? Money::ofMinor(intdiv($storedVat, $qty), $currency)
                : null;

            $items[] = new PdfInvoiceItem(
                label: (string) ($item['label'] ?? $item['kind'] ?? 'Line'),
                unit_price: Money::ofMinor($netUnit, $currency),
                unit_tax: $unitTax,
                tax_percentage: $taxPercentage,
                quantity: $qty,
                description: $description,
            );
        }

        $logo = $this->resolveLogoPath(config('mollie-billing.invoices.logo'));
        $paymentInstruction = $this->buildPaymentInstruction($invoice, $isCredit);

        // refund_reason_text doubles as a general invoice description: credit
        // notes use it for the refund reason, country-correction reissues use
        // it to explain what happened. Render it for both kinds when set.
        //
        // Escaped here because the vendor template prints it with `{!! !!}` and ships DOMPDF
        // with `isRemoteEnabled`: an `<img src>` typed into the admin refund form was fetched
        // by the server while rendering. The template's `whitespace-pre-line` keeps the line
        // breaks, which is all the formatting this field was ever meant to carry.
        $description = ! empty($invoice->refund_reason_text)
            ? e((string) $invoice->refund_reason_text)
            : null;

        // A zero-rated supply has to say why on its face — for reverse charge the
        // "Steuerschuldnerschaft des Leistungsempfängers" (§ 14a (5) UStG, § 11 (1a) UStG
        // AT). The same text is the exemption reason in the embedded e-invoice XML.
        $exemptionNote = app(EInvoiceService::class)->taxExemptionNote($invoice, $billable);
        if ($exemptionNote !== null) {
            $description = $description !== null
                ? $description."\n\n".e($exemptionNote)
                : e($exemptionNote);
        }

        // PDF/A-3 (the container of a ZUGFeRD invoice) requires every font to be embedded.
        // DOMPDF never embeds its default Helvetica, so the e-invoice renders in DejaVu
        // Sans, which ships with DOMPDF and is embedded as a subset. An app that set its
        // own template font keeps it.
        $templateData = (array) (config('invoices.pdf.template_data') ?? []);
        if (app(EInvoiceService::class)->isEnabled() && empty($templateData['font'])) {
            $templateData['font'] = (string) config('mollie-billing.invoices.e_invoice.font', 'DejaVu Sans');
        }

        // Force the tax column on the PDF whenever at least one line has a
        // non-zero VAT rate. The library's default heuristic only shows it when
        // totalTaxAmount() > 0, which fails for credit notes (negative totals)
        // even though the lines themselves carry tax rates and need to be
        // shown for tax-compliance reasons.
        $hasAnyTaxedLine = false;
        foreach ((array) $invoice->line_items as $line) {
            if (((float) ($line['vat_rate'] ?? 0)) > 0) {
                $hasAnyTaxedLine = true;
                break;
            }
        }
        $taxLabel = $hasAnyTaxedLine ? 'invoices::invoice.tax_label' : null;

        // PdfInvoice derives its currency from the first line item and falls back to
        // `invoices.default_currency` — vendor default 'USD' — when there is none.
        // This package never publishes an `invoices` config, so an invoice with an
        // empty line_items array would render as dollars. Pin the invoice's own
        // currency instead of relying on the items.
        $pdfInvoice = new class(
            type: $isCredit ? __('billing::portal.credit_note_type') : __('billing::portal.invoice_type'),
            state: $this->mapInvoiceState($invoice->status),
            serial_number: $invoice->serial_number,
            created_at: $invoice->created_at,
            seller: $seller,
            buyer: $buyer,
            items: $items,
            description: $description,
            tax_label: $taxLabel,
            paymentInstructions: $paymentInstruction !== null ? [$paymentInstruction] : [],
            templateData: $templateData,
            logo: $logo,
        ) extends PdfInvoice
        {
            public string $pinnedCurrency = 'EUR';

            public function getCurrency(): string
            {
                return $this->pinnedCurrency;
            }
        };

        $pdfInvoice->pinnedCurrency = $currency;

        return $pdfInvoice;
    }

    /**
     * The seller's VAT ID and tax number, each with its label, in the wording of the
     * seller's country ("UID-Nr." in Austria, "USt-IdNr." in Germany). The template
     * printed `tax_number` bare, and a VAT ID kept in that key was indistinguishable
     * from a tax number. § 14 (4) Nr. 2 UStG accepts either; § 11 (1) Z 3 lit. i
     * UStG 1994 and every reverse-charge invoice need the VAT ID. Same pair as BT-31 /
     * BT-32 in the embedded e-invoice.
     *
     * @return array<string, string>
     */
    private function sellerTaxFields(): array
    {
        $eInvoice = app(EInvoiceService::class);
        $identifiers = $eInvoice->sellerIdentifiers();
        $fields = [];

        if ($identifiers['vat_id'] !== null) {
            $fields[$eInvoice->sellerCountryText('seller_vat_id')] = $identifiers['vat_id'];
        }

        if ($identifiers['tax_number'] !== null) {
            $fields[$eInvoice->sellerCountryText('seller_tax_number')] = $identifiers['tax_number'];
        }

        return $fields;
    }

    /**
     * The buyer's VAT ID with its label: the number the invoice relied on (its own VIES
     * validation) — the one the reverse-charge note names — falling back to the number on
     * the billable for an invoice without a validation.
     *
     * @return array<string, string>
     */
    private function buyerTaxFields(BillingInvoice $invoice, Billable $billable): array
    {
        $eInvoice = app(EInvoiceService::class);
        $vatId = $eInvoice->buyerVatId($invoice) ?? (trim((string) ($billable->vat_number ?? '')) ?: null);

        return $vatId === null ? [] : [$eInvoice->sellerCountryText('seller_vat_id') => $vatId];
    }

    /**
     * "Service period: 01.11.2026 – 30.11.2026" for a line, from its stored period (the
     * invoice's when the line has none), with the inclusive end date the e-invoice states
     * as BG-26. § 14 (4) Nr. 6 UStG / § 11 (1) Z 3 lit. d UStG 1994: the readable invoice
     * names when the service was rendered.
     *
     * @param  array<string, mixed>  $item
     */
    private function servicePeriodText(array $item, BillingInvoice $invoice, Billable $billable, ?\DateTimeInterface $deliveryDate): ?string
    {
        $format = $this->pdfDateFormat();

        // A product is delivered once, on payment: "Leistungsdatum", not a period
        // (see EInvoiceService::deliveryDate(), which the XML states as BT-72). Resolved
        // once per document by the caller.
        if ($deliveryDate !== null) {
            return __('billing::portal.service_date', ['date' => $deliveryDate->format($format)]);
        }

        [$start, $end] = app(EInvoiceService::class)->servicePeriod(
            $item['period_start'] ?? $invoice->period_start,
            $item['period_end'] ?? $invoice->period_end,
            $billable,
        );

        if ($start === null || $end === null) {
            return null;
        }

        if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
            return __('billing::portal.service_date', ['date' => $start->format($format)]);
        }

        return __('billing::portal.service_period', [
            'start' => $start->format($format),
            'end' => $end->format($format),
        ]);
    }

    /**
     * Date format of the PDF: `invoices.date_format` (`BILLING_INVOICE_DATE_FORMAT`),
     * else the rendering locale's (`d.m.Y` in German). The vendor template reads
     * `invoices.date_format`, whose shipped default `Y-m-d` printed "2026-11-01" on a
     * German invoice.
     */
    protected function pdfDateFormat(): string
    {
        $configured = config('mollie-billing.invoices.date_format');

        if (is_string($configured) && trim($configured) !== '') {
            return $configured;
        }

        $format = __('billing::portal.invoice_date_format');

        return $format !== 'billing::portal.invoice_date_format' ? (string) $format : 'Y-m-d';
    }

    /**
     * Resolve the logo config value to a base64 data-URI for DOMPDF.
     *
     * Accepts: absolute local path, public-relative path, APP_URL-based URL,
     * or an already-encoded data-URI. The result is always a data-URI so
     * DOMPDF renders the logo without needing isRemoteEnabled.
     */
    private function resolveLogoPath(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        $value = (string) $value;

        // Already a data-URI — return as-is.
        if (str_starts_with($value, 'data:')) {
            return $value;
        }

        // Extract the file basename and build every candidate path we can think of.
        $candidates = [];

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            $appUrl = rtrim((string) config('app.url'), '/');
            if ($appUrl !== '' && str_starts_with($value, $appUrl.'/')) {
                $candidates[] = public_path(ltrim(substr($value, strlen($appUrl)), '/'));
            }
            $parsed = parse_url($value);
            if (isset($parsed['path'])) {
                $candidates[] = public_path(ltrim($parsed['path'], '/'));
            }
        } elseif (str_starts_with($value, '/')) {
            $candidates[] = $value;
            $candidates[] = public_path(ltrim($value, '/'));
        } else {
            $candidates[] = public_path($value);
        }

        // Always try base_path and resource_path as well.
        $basename = basename($value);
        $candidates[] = public_path($basename);
        $candidates[] = base_path($value);
        $candidates[] = resource_path($basename);

        // Deduplicate.
        $candidates = array_unique($candidates);

        foreach ($candidates as $path) {
            if (file_exists($path) && is_file($path)) {
                $contents = file_get_contents($path);
                if ($contents !== false) {
                    $mime = mime_content_type($path) ?: 'image/png';

                    return 'data:'.$mime.';base64,'.base64_encode($contents);
                }
            }
        }

        Log::warning('Invoice logo could not be resolved to a local file', [
            'configured_value' => $value,
            'tried_paths' => $candidates,
            'public_path' => public_path(),
        ]);

        return null;
    }

    private function mapInvoiceState(InvoiceStatus $status): string
    {
        return match ($status) {
            InvoiceStatus::Paid => __('billing::portal.invoice_state_paid'),
            InvoiceStatus::Refunded => __('billing::portal.invoice_state_refunded'),
            InvoiceStatus::Open => __('billing::portal.invoice_state_pending'),
            InvoiceStatus::Failed => __('billing::portal.invoice_state_failed'),
        };
    }

    /**
     * Extract payment method details from a Mollie payment object for storage.
     *
     * @return array{method: string, summary: string, details: array<string, mixed>}|null
     */
    public static function extractPaymentMethodDetails(object $payment): ?array
    {
        $method = $payment->method ?? null;
        if ($method === null) {
            return null;
        }

        $details = is_object($payment->details ?? null)
            ? json_decode(json_encode($payment->details), true) ?: []
            : (array) ($payment->details ?? []);

        $method = (string) $method;
        $summary = self::buildPaymentSummary($method, $details);

        return [
            'method' => $method,
            'summary' => $summary,
            'details' => $details,
        ];
    }

    /**
     * Build a human-readable summary for a payment method (e.g. "Visa •••• 1234, 12/2027").
     *
     * @param  array<string, mixed>  $details
     */
    private static function buildPaymentSummary(string $method, array $details): ?string
    {
        if ($method === 'creditcard') {
            $parts = [];
            if (! empty($details['cardLabel'])) {
                $parts[] = (string) $details['cardLabel'];
            }
            if (! empty($details['cardNumber'])) {
                $parts[] = '**** '.(string) $details['cardNumber'];
            }
            $summary = implode(' ', $parts);
            if (! empty($details['cardExpiryDate'])) {
                try {
                    $summary .= ', '.\Carbon\Carbon::parse((string) $details['cardExpiryDate'])->format('m/Y');
                } catch (\Throwable) {
                }
            }

            return $summary ?: null;
        }

        if ($method === 'directdebit') {
            $parts = [];
            if (! empty($details['consumerName'])) {
                $parts[] = (string) $details['consumerName'];
            }
            if (! empty($details['consumerAccount'])) {
                $parts[] = (string) $details['consumerAccount'];
            }

            return $parts !== [] ? implode(' - ', $parts) : null;
        }

        if ($method === 'paypal') {
            return ! empty($details['consumerAccount']) ? (string) $details['consumerAccount'] : null;
        }

        return ucfirst(str_replace('_', ' ', $method));
    }

    /**
     * Build a PaymentInstruction for the PDF footer showing payment method details.
     */
    private function buildPaymentInstruction(BillingInvoice $invoice, bool $isCredit): ?PaymentInstruction
    {
        $pmd = $invoice->payment_method_details;
        if (! is_array($pmd) || empty($pmd['method'])) {
            return null;
        }

        $method = (string) $pmd['method'];
        // "Kreditkarte" rather than "Creditcard": the portal's labels, the raw Mollie
        // method id only for a method nobody translated.
        $methodKey = 'billing::portal.payment_method.method.'.$method;
        $methodLabel = Lang::has($methodKey) ? (string) __($methodKey) : ucfirst(str_replace('_', ' ', $method));
        $details = (array) ($pmd['details'] ?? []);

        $name = $isCredit
            ? __('billing::portal.refund_to', ['method' => $methodLabel])
            : __('billing::portal.charged_via', ['method' => $methodLabel]);

        $fields = [];

        if ($method === 'creditcard') {
            if (! empty($details['cardHolder'])) {
                $fields[__('billing::portal.payment_method.card_holder')] = (string) $details['cardHolder'];
            }
            if (! empty($details['cardLabel'])) {
                $fields[__('billing::portal.payment_method.card_brand')] = (string) $details['cardLabel'];
            }
            if (! empty($details['cardNumber'])) {
                $fields[__('billing::portal.payment_method.card_number')] = '**** '.(string) $details['cardNumber'];
            }
            if (! empty($details['cardExpiryDate'])) {
                try {
                    $fields[__('billing::portal.payment_method.expires_label')] = \Carbon\Carbon::parse((string) $details['cardExpiryDate'])->format('m/Y');
                } catch (\Throwable) {
                }
            }
        } elseif ($method === 'directdebit') {
            if (! empty($details['consumerName'])) {
                $fields[__('billing::portal.payment_method.account_holder')] = (string) $details['consumerName'];
            }
            if (! empty($details['consumerAccount'])) {
                $fields[__('billing::portal.payment_method.iban')] = (string) $details['consumerAccount'];
            }
            if (! empty($details['consumerBic'])) {
                $fields[__('billing::portal.payment_method.bic')] = (string) $details['consumerBic'];
            }
        } elseif ($method === 'paypal') {
            if (! empty($details['consumerName'])) {
                $fields[__('billing::portal.payment_method.account_holder')] = (string) $details['consumerName'];
            }
            if (! empty($details['consumerAccount'])) {
                $fields[__('billing::portal.payment_method.paypal_email')] = (string) $details['consumerAccount'];
            }
        }

        return new PaymentInstruction(
            name: $name,
            fields: $fields,
        );
    }

    /**
     * Net of a single line item. `total_net` and `amount_net` are equivalent
     * spellings — emitters use both (SubscriptionAmount writes `total_net`,
     * webhook coupon lines write `amount_net`) — and a line that carries neither
     * contributes $fallback.
     *
     * @param  array<string, mixed>  $item
     */
    private static function lineNet(array $item, int $fallback = 0): int
    {
        if (array_key_exists('total_net', $item)) {
            return (int) $item['total_net'];
        }

        if (array_key_exists('amount_net', $item)) {
            return (int) $item['amount_net'];
        }

        return $fallback;
    }

    /**
     * Sum the line items' net amounts.
     *
     * @param  array<int, array<string, mixed>>  $lineItems
     */
    private function sumTotalNet(array $lineItems): int
    {
        $sum = 0;
        foreach ($lineItems as $item) {
            $sum += self::lineNet($item);
        }

        return $sum;
    }

    /**
     * Expected net = quantity * unit_price, summed across items, falling back to
     * the line's own net where the item does not spell out both factors.
     *
     * `qty` is accepted as an alias for `quantity`. Both this and sumTotalNet()
     * must recognise the same line shapes: a shape that only one of them
     * understands makes the LineItemTotalsMismatchException guard blind to a
     * genuine divergence.
     *
     * @param  array<int, array<string, mixed>>  $lineItems
     */
    private function expectedNetFromLineItems(array $lineItems): int
    {
        $sum = 0;
        foreach ($lineItems as $item) {
            $quantity = $item['quantity'] ?? $item['qty'] ?? null;

            if ($quantity !== null && array_key_exists('unit_price', $item)) {
                $sum += (int) $quantity * (int) $item['unit_price'];
            } else {
                $sum += self::lineNet($item);
            }
        }

        return $sum;
    }

    /**
     * The package-wide currency, normalised. Correct for a fresh charge; derived
     * documents (credit notes, corrections, refunds) must inherit their parent's
     * currency instead.
     */
    private static function configuredCurrency(): string
    {
        return self::normalizeCurrency(null);
    }

    /**
     * Hand failed refund lines to RetryRefundLineJob via subscription_meta.
     *
     * Under a lock: the retry job's WithoutOverlapping serialises the retry chain
     * against itself, but a plan change refunding through ProrataExecutor holds no
     * such lock, and an unlocked append writes back a snapshot in which the
     * concurrent job's line is still pending — resurrecting it into a second payout
     * on the next sweep.
     *
     * Every Billable is an Eloquent model (the contract itself returns Builder and
     * MorphMany, and this method's callers use forceFill/save on it), so the
     * instanceof only narrows the type for the shared locking helper.
     *
     * @param  list<array<string, mixed>>  $failedLines
     */
    private function appendPendingRefundRetries(Billable $billable, array $failedLines): void
    {
        if (! $billable instanceof Model) {
            return;
        }

        $this->mutateBillingMeta($billable, function (array $meta) use ($failedLines): array {
            $existing = (array) ($meta['pending_refund_retries'] ?? []);
            $meta['pending_refund_retries'] = array_merge($existing, $failedLines);

            return $meta;
        });
    }

    /**
     * Retained as public API for callers and subclasses; {@see BillingMoney} holds
     * the implementation so the invoice side and the outbound Mollie side cannot
     * disagree about a currency's scale.
     */
    /**
     * The invoice at the end of a settlement chain — the one holding a Mollie payment.
     *
     * Returns null when the chain leads nowhere (or loops), which makes the caller write an
     * unsettled document rather than one pointing at money that does not exist.
     */
    public static function settlementRoot(?BillingInvoice $invoice): ?BillingInvoice
    {
        $seen = [];

        while ($invoice !== null && $invoice->mollie_payment_id === null) {
            $key = (int) $invoice->getKey();

            if ($invoice->settled_by_invoice_id === null || isset($seen[$key])) {
                return null;
            }

            $seen[$key] = true;
            $invoice = $invoice->settledBy;
        }

        return $invoice;
    }

    /**
     * Clear any idempotency key left on the shared Mollie client.
     *
     * Tolerant by design: the accessor differs across SDK versions and a test double may
     * expose neither, and failing to clean up a key must never take down the caller that
     * just moved money.
     */
    public static function forgetIdempotencyKey(): void
    {
        // One implementation, on the door every Mollie request goes through. It owns the key's
        // lifetime now that `MollieCall::send()` can set one, and two copies of a cleanup this
        // subtle would have drifted.
        MollieCall::forgetIdempotencyKey();
    }

    public static function normalizeCurrency(?string $currency): string
    {
        return BillingMoney::normalizeCurrency($currency);
    }

    /**
     * @throws \InvalidArgumentException  when the code is not an ISO-4217 currency.
     */
    private static function assertKnownCurrency(string $currency): string
    {
        try {
            Currency::of($currency);
        } catch (UnknownCurrencyException $e) {
            throw new \InvalidArgumentException("Unknown invoice currency '{$currency}'.", 0, $e);
        }

        return $currency;
    }

    /** @see BillingMoney::mollieAmount() */
    public static function molliePrice(int $minor, ?string $currency = null): string
    {
        return BillingMoney::mollieAmount($minor, $currency);
    }

    /** @see BillingMoney::minorFromMollieAmount() */
    public static function mollieAmountToMinor(string|float|int|null $amount, ?string $currency = null): int
    {
        return BillingMoney::minorFromMollieAmount($amount, $currency);
    }

    /**
     * Allocate the serial number and INSERT the row in one transaction.
     *
     * The generator's row lock only holds until the transaction that read the
     * highest serial commits, so allocating in a transaction of its own lets a
     * concurrent generator hand out the same number and the serial_number unique
     * index rejects one of the two at save time — after the Mollie side effects
     * have already happened.
     *
     * Lock-order constraint: when this runs inside a caller's transaction the
     * generator's lock is held until that outer transaction commits. Callers
     * that also lock a specific billing_invoices row must therefore keep the
     * generator's lock footprint narrow — `InvoiceNumberGenerator` has to select
     * the highest serial over an index-usable range, never a scan that locks
     * unrelated rows, or an admin refund (row lock first, serial second) and a
     * renewal webhook (serial first) deadlock on the same table. The loser's
     * QueryException carries no `serial_number`, so it is rethrown and the
     * webhook 500s.
     */
    private function persistWithSerial(BillingInvoice $invoice, string $kindForSerial): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                DB::transaction(function () use ($invoice, $kindForSerial): void {
                    $invoice->serial_number = $this->numberGenerator->generate($kindForSerial);
                    $invoice->save();
                });

                return;
            } catch (QueryException $e) {
                // Engines that do not lock the not-yet-existing serial range can
                // still collide; the unique index catches it, so re-read the
                // counter instead of losing an invoice whose money already moved.
                if ($attempt >= 3 || ! self::isSerialCollision($e)) {
                    throw $e;
                }

                $invoice->serial_number = null;
            }
        }
    }

    private static function isSerialCollision(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'serial_number');
    }

    // ========================================================================
    // Country-mismatch correction: refund + reissue with corrected VAT rate.
    // ========================================================================

    /**
     * Issue a Mollie one-off charge for the gross amount of an original invoice,
     * recomputed at a new country's VAT rate (which may include reverse-charge).
     *
     * Mirrors the `usage_overage` pattern: the request is sent with metadata
     * `type=country_correction`, and the resulting BillingInvoice is created
     * later in the webhook handler when the payment confirms.
     *
     * @return object  The Mollie payment object (has $id and $status).
     */
    public function issueCorrectionCharge(BillingInvoice $original, Billable $billable, string $newCountry, int $mismatchId, ?string $creditNoteSerial = null): object
    {
        /** @var Model&Billable $billable */
        if (! $billable->hasMollieMandate()) {
            throw new \LogicException('Cannot issue correction charge: billable has no Mollie mandate.');
        }

        $newCountry = strtoupper($newCountry);
        $newLines = $this->cloneLineItemsWithRate($original, $newCountry, $billable);

        // Summed from the lines, not recomputed from the total: createCorrectionInvoice()
        // builds the reissue invoice's header out of these same lines, so deriving the
        // charge any other way lets the collected amount and the booked amount differ.
        // With mixed rates they differ by the whole reduced-rate delta; even on a single
        // rate `round(Σ net × rate)` and `Σ round(net × rate)` drift by a cent per line.
        $sumNet = 0;
        $sumGross = 0;
        foreach ($newLines as $line) {
            $sumNet += (int) ($line['amount_net'] ?? 0);
            $sumGross += (int) ($line['amount_gross'] ?? 0);
        }

        if ($sumNet <= 0) {
            throw new \LogicException('Correction charge net amount must be positive.');
        }

        // Charge in the currency the original invoice was booked in — the reissue
        // invoice inherits it too (see createCorrectionInvoice()), so bank, ledger
        // and PDF must not disagree after a BILLING_CURRENCY change.
        $currency = self::normalizeCurrency($original->currency);

        $payment = MollieCall::send(new CreatePaymentRequest(
            description: 'Country correction for invoice '.($original->serial_number ?? $original->id),
            amount: new MollieMoney($currency, self::molliePrice($sumGross, $currency)),
            metadata: [
                'type' => 'country_correction',
                'mismatch_id' => $mismatchId,
                'original_invoice_id' => (int) $original->id,
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
            ],
            sequenceType: 'recurring',
            mandateId: $billable->getMollieMandateId(),
            customerId: $billable->getMollieCustomerId(),
            webhookUrl: route(BillingRoute::webhook()),
        ));

        // Stash context for the webhook handler. Mollie metadata is capped at
        // 1024 bytes, so we keep the customer-facing payload (line items,
        // period, matching credit-note serial) on subscription_meta keyed by
        // payment_id. The caller passes the credit-note serial after the
        // refund step so the reissue PDF can reference it.
        $oldCountry = strtoupper((string) ($original->country ?? ''));

        $paymentId = is_object($payment) ? (string) ($payment->id ?? '') : '';
        if ($paymentId !== '') {
            $entry = [
                'mismatch_id' => $mismatchId,
                'original_invoice_id' => (int) $original->id,
                'old_country' => $oldCountry,
                'new_country' => $newCountry,
                'credit_note_serial' => $creditNoteSerial,
                'line_items' => $newLines,
                'period_start' => $original->period_start?->toIso8601String(),
                'period_end' => $original->period_end?->toIso8601String(),
                'created_at' => BillingTime::nowUtc()->toIso8601String(),
            ];

            // Added to the map read under the lock, never to the caller's snapshot: that
            // snapshot predates the Mollie call above, and the previous correction of the
            // same resolve() can be paid and cleared by its webhook in the meantime.
            // Replaying the snapshot brought that entry back for good — the dashboard kept
            // showing a pending re-charge and the sweeper polled the payment every hour.
            $this->mutateBillingMeta($billable, function (array $meta) use ($paymentId, $entry): array {
                $corrections = (array) ($meta['country_corrections'] ?? []);
                $corrections[$paymentId] = $entry;
                $meta['country_corrections'] = $corrections;

                return $meta;
            });
        }

        return $payment;
    }

    /**
     * Persist a BillingInvoice for a confirmed `country_correction` payment.
     *
     * Unlike `createForPayment()`, this preserves the ORIGINAL invoice's period
     * (semantically correct for OSS / accounting — the reissue belongs to the
     * original supply period, not "now").
     */
    public function createCorrectionInvoice(object $payment, BillingInvoice $original, Billable $billable): BillingInvoice
    {
        /** @var Model&Billable $billable */
        $paymentId = (string) ($payment->id ?? '');
        $meta = $billable->getBillingSubscriptionMeta();
        $pending = (array) ($meta['country_corrections'][$paymentId] ?? []);

        $lineItems = (array) ($pending['line_items'] ?? []);
        if ($lineItems === []) {
            throw new \LogicException("Cannot create correction invoice: no pending state for payment {$paymentId}.");
        }

        $newCountry = strtoupper((string) ($pending['new_country'] ?? $billable->getBillingCountry() ?? ''));
        $sumNet = 0;
        $sumVat = 0;
        $sumGross = 0;
        foreach ($lineItems as $line) {
            $sumNet += (int) ($line['amount_net'] ?? 0);
            $sumVat += (int) ($line['vat_amount'] ?? 0);
            $sumGross += (int) ($line['amount_gross'] ?? 0);
        }

        $invoice = new BillingInvoice;
        $invoice->billable_type = $billable->getMorphClass();
        $invoice->billable_id = $billable->getKey();
        $invoice->mollie_payment_id = $paymentId;
        $invoice->mollie_subscription_id = $original->mollie_subscription_id;
        $invoice->invoice_kind = InvoiceKind::Subscription;
        $invoice->status = InvoiceStatus::Paid;
        $invoice->country = $newCountry;
        $invoice->currency = self::normalizeCurrency($original->currency);
        $invoice->amount_net = $sumNet;
        $invoice->amount_vat = $sumVat;
        $invoice->amount_gross = $sumGross;
        $invoice->line_items = $lineItems;
        $invoice->payment_method_details = self::extractPaymentMethodDetails($payment);
        // Keep the ORIGINAL invoice's period (this is a corrective reissue, not
        // a fresh charge for "now").
        $invoice->period_start = $original->period_start;
        $invoice->period_end = $original->period_end;
        $invoice->refunded_net = 0;
        // Use the billable's CURRENT VAT validation — after the VIES re-check
        // this is the entry that justifies the reverse-charge decision (or its
        // absence) reflected in the new VAT amounts.
        $invoice->vat_validation_id = $billable->currentVatValidation()?->getKey();
        // Anchor the reissue to the resolved mismatch for audit traceability.
        // The mismatch itself is no longer Pending here (resolve() has flipped
        // it), so latestOpenCountryMismatch() would return null — read the id
        // from the pending state instead.
        $reissueMismatchId = (int) ($pending['mismatch_id'] ?? 0);
        if ($reissueMismatchId > 0) {
            $invoice->mismatch_id = $reissueMismatchId;
        }
        // Customer-facing explanation rendered on the PDF (same template as the
        // matching credit note).
        $invoice->refund_reason_text = $this->correctionReissueReason($original, $pending);
        $this->persistWithSerial($invoice, InvoiceKind::Subscription->value);

        $this->generateAndStorePdf($invoice, $billable);

        // Clear the pending entry so a duplicate webhook (idempotency) can no-op — under the
        // lock, and re-reading rather than replaying the snapshot taken before the serial
        // allocation and the PDF render above.
        $this->mutateBillingMeta($billable, function (array $meta) use ($paymentId): array {
            unset($meta['country_corrections'][$paymentId]);

            if (empty($meta['country_corrections'])) {
                unset($meta['country_corrections']);
            }

            return $meta;
        });

        event(new InvoiceCreated($billable, $invoice));

        return $invoice;
    }

    /**
     * Rebuild line items at the new country's VAT rate, keeping kind/quantity
     * and unit_price_net constant. VAT may collapse to 0 under reverse-charge.
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * Customer-facing explanation rendered on the reissue invoice PDF.
     *
     * @param  array<string, mixed>  $pending  the country_corrections entry from subscription_meta
     */
    private function correctionReissueReason(BillingInvoice $original, array $pending): string
    {
        $oldCountry = strtoupper((string) ($pending['old_country'] ?? ''));
        $newCountry = strtoupper((string) ($pending['new_country'] ?? ''));
        $creditNoteSerial = (string) ($pending['credit_note_serial'] ?? '');

        return (string) __('billing::portal.country_correction_reissue_reason', [
            'original_serial' => (string) ($original->serial_number ?? $original->id),
            'old_country' => $oldCountry,
            'new_country' => $newCountry,
            'old_rate' => $this->formatRateForCountry($oldCountry, $original),
            'new_rate' => $this->formatRateForCountry($newCountry),
            'credit_note_serial' => $creditNoteSerial,
            'support_email' => $this->correctionSupportEmail(),
        ]);
    }

    private function formatRateForCountry(string $country, ?BillingInvoice $reference = null): string
    {
        try {
            $rate = $this->vat->vatRateFor($country);
        } catch (\Throwable) {
            $rate = 0.0;
            if ($reference !== null) {
                $first = ((array) $reference->line_items)[0] ?? [];
                $rate = (float) ($first['vat_rate'] ?? 0.0);
            }
        }

        return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
    }

    private function correctionSupportEmail(): string
    {
        $candidates = [
            config('mollie-billing.invoices.seller.email'),
            config('mail.from.address'),
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return 'support';
    }

    private function cloneLineItemsWithRate(BillingInvoice $original, string $newCountry, Billable $billable): array
    {
        $vat = $this->vat->calculate($newCountry, 1, $billable); // probe to detect reverse-charge
        $newStandardRate = (float) $vat['rate'];
        $oldStandardRate = $this->standardRateFor((string) ($original->country ?? ''));

        $now = BillingTime::nowUtc();
        $periodStart = ($original->period_start ?? $now)->toIso8601String();
        $periodEnd = ($original->period_end ?? $now)->toIso8601String();

        $cloned = [];
        $clonedAt = [];
        foreach ((array) $original->line_items as $index => $line) {
            $netLine = (int) ($line['amount_net'] ?? 0);
            $lineRate = $this->correctedLineRate(
                (float) ($line['vat_rate'] ?? $oldStandardRate),
                $oldStandardRate,
                $newStandardRate,
                $original,
                $newCountry,
                (int) $index,
            );
            $vatLine = (int) round($netLine * $lineRate / 100);

            $cloned[] = [
                'kind' => (string) ($line['kind'] ?? 'plan'),
                'code' => $line['code'] ?? null,
                'label' => (string) ($line['label'] ?? ($line['kind'] ?? 'Line')),
                'quantity' => (int) ($line['quantity'] ?? 1),
                'unit_price_net' => (int) ($line['unit_price_net'] ?? $netLine),
                'amount_net' => $netLine,
                'vat_rate' => $lineRate,
                'vat_amount' => $vatLine,
                'amount_gross' => $netLine + $vatLine,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                // Not `parent_invoice_id`: that key marks a CREDIT against another invoice,
                // and every reader of a document's own charges skips such lines. On the
                // reissue it left nothing to refund by — a full refund credited 0 % VAT and
                // paid back only the net — so the reference to the corrected invoice has a
                // key of its own, like the undercollection's `completes_invoice_id`.
                'corrects_invoice_id' => (int) $original->id,
                'description' => $line['description'] ?? null,
            ];
            $clonedAt[(int) $index] = count($cloned) - 1;
        }

        return [...$cloned, ...$this->refundedBeforeCorrectionLines($original, $cloned, $clonedAt, $periodStart, $periodEnd)];
    }

    /**
     * The reductions that bring a reissue down to what remained paid on the original.
     *
     * A refund made before the correction — goodwill, a partial refund by line, a plan
     * change's credit — already gave that money back. The correction then refunds only the
     * remainder, so the reissue may charge only the remainder too: cloning the original at
     * full value collected the earlier refund a second time over the mandate.
     *
     * One negative line per original line that was credited, at that line's corrected rate
     * and naming it in `applies_to`, so the reissue reads like any invoice with a reduction
     * (refunds fold it into its line, a prorata credit takes it off that component). Credit
     * that names no line is set against the largest one.
     *
     * @param  list<array<string, mixed>>  $cloned
     * @param  array<int, int>  $clonedAt  original line index → position in `$cloned`
     * @return list<array<string, mixed>>
     */
    private function refundedBeforeCorrectionLines(BillingInvoice $original, array $cloned, array $clonedAt, string $periodStart, string $periodEnd): array
    {
        $credited = $this->creditedOutsideCorrection($original);

        $reductions = [];
        foreach ($credited['per_line'] as $index => $net) {
            if ($net > 0 && isset($clonedAt[$index])) {
                $reductions[$clonedAt[$index]] = ($reductions[$clonedAt[$index]] ?? 0) + $net;
            } else {
                $credited['unattributed'] += max(0, $net);
            }
        }

        if ($credited['unattributed'] > 0) {
            $largest = null;
            foreach ($cloned as $position => $line) {
                if ($largest === null || (int) $line['amount_net'] > (int) $cloned[$largest]['amount_net']) {
                    $largest = $position;
                }
            }

            if ($largest !== null) {
                $reductions[$largest] = ($reductions[$largest] ?? 0) + $credited['unattributed'];
            }
        }

        $lines = [];
        foreach ($reductions as $position => $net) {
            $line = $cloned[$position];
            $rate = (float) $line['vat_rate'];
            $vat = (int) round(-$net * $rate / 100);

            $lines[] = [
                'kind' => 'refunded',
                'code' => $line['code'] ?? null,
                'label' => (string) __('billing::portal.country_correction_refunded_label', [
                    'serial' => (string) ($original->serial_number ?? $original->id),
                    'label' => (string) $line['label'],
                ]),
                'quantity' => 1,
                'unit_price_net' => -$net,
                'amount_net' => -$net,
                'vat_rate' => $rate,
                'vat_amount' => $vat,
                'amount_gross' => -$net + $vat,
                'applies_to' => [ProrataComposer::lineKey($line)],
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'corrects_invoice_id' => (int) $original->id,
            ];
        }

        return $lines;
    }

    /**
     * Net credited back on `$original` by every refund except the country correction's own,
     * per original line index, plus what names no line.
     *
     * The correction's own refund is recognised by its reason
     * ({@see CountryMatchService::CORRECTION_REFUND_REASON}); a resolve that refunded an
     * invoice and stopped before charging it still owes the whole reissue on the retry.
     * A reversal (the refund failed at Mollie) takes its credit back out again.
     *
     * @return array{per_line: array<int, int>, unattributed: int, total: int}
     */
    public function creditedOutsideCorrection(BillingInvoice $original): array
    {
        $notes = BillingInvoice::query()
            ->where('billable_type', $original->billable_type)
            ->where('billable_id', $original->billable_id)
            ->where('invoice_kind', InvoiceKind::Refund)
            ->orderBy('id')
            ->get(['id', 'line_items', 'refund_reason_code', 'refund_reason_text']);

        $correctionNotes = [];
        foreach ($notes as $note) {
            if ($note->refund_reason_code === RefundReasonCode::BillingError
                && $note->refund_reason_text === CountryMatchService::CORRECTION_REFUND_REASON
            ) {
                $correctionNotes[(int) $note->id] = true;
            }
        }

        $perLine = [];
        $unattributed = 0;
        foreach ($notes as $note) {
            if (isset($correctionNotes[(int) $note->id])) {
                continue;
            }

            foreach ((array) ($note->line_items ?? []) as $line) {
                if (! is_array($line) || (int) ($line['parent_invoice_id'] ?? 0) !== (int) $original->id) {
                    continue;
                }

                if (isset($correctionNotes[(int) ($line['reversed_credit_note_id'] ?? 0)])) {
                    continue;
                }

                $net = BillingInvoice::creditSign($line) * abs((int) ($line['amount_net'] ?? 0));

                if (isset($line['parent_line_item_index'])) {
                    $index = (int) $line['parent_line_item_index'];
                    $perLine[$index] = ($perLine[$index] ?? 0) + $net;
                } else {
                    $unattributed += $net;
                }
            }
        }

        $perLine = array_map(static fn (int $net): int => max(0, $net), $perLine);
        $unattributed = max(0, $unattributed);

        return [
            'per_line' => $perLine,
            'unattributed' => $unattributed,
            'total' => min($original->refundableBaseNet(), array_sum($perLine) + $unattributed),
        ];
    }

    /**
     * The rate a single line carries after the invoice is corrected to another country.
     *
     * A correction must not flatten the invoice onto one rate. Stamping the new
     * country's standard rate over every line over-taxes the customer — an AT invoice
     * of 2000 @ 20% plus 1000 @ 10% corrected to DE collected 35.70 instead of 34.50 —
     * and it mis-buckets OSS, because `OssProtocolService::collectBuckets()` buckets by
     * the line's own `vat_rate`.
     *
     * Three cases, because that is as far as the data goes:
     *  - reverse charge, or a zero-rated line: stays 0. A discount line and an
     *    already-exempt supply have no destination equivalent to look up.
     *  - the origin country's standard rate: becomes the destination's standard rate.
     *    This is the overwhelming majority of lines.
     *  - anything else — a reduced rate, which only ever enters as a caller-supplied
     *    per-line override: kept as-is and logged. `vatRateFor()` returns one rate per
     *    country, so the package has no way to derive "the German equivalent of the
     *    Austrian reduced rate"; keeping the line's own rate at least preserves the
     *    reduction rather than silently raising it to standard.
     */
    private function correctedLineRate(
        float $lineRate,
        float $oldStandardRate,
        float $newStandardRate,
        BillingInvoice $original,
        string $newCountry,
        int $index,
    ): float {
        if ($newStandardRate <= 0.0 || abs($lineRate) < 0.001) {
            return 0.0;
        }

        if (abs($lineRate - $oldStandardRate) < 0.001) {
            return $newStandardRate;
        }

        Log::warning('Country correction kept a non-standard line rate: no destination equivalent is derivable', [
            'invoice_id' => $original->id,
            'line_index' => $index,
            'line_vat_rate' => $lineRate,
            'from_country' => $original->country,
            'from_standard_rate' => $oldStandardRate,
            'to_country' => $newCountry,
            'to_standard_rate' => $newStandardRate,
        ]);

        return $lineRate;
    }

    /**
     * The origin country's standard rate, used only to recognise which lines were
     * charged at it. A country the calculator does not know degrades to 0, which makes
     * every line fall through to the "keep and log" branch rather than being silently
     * re-rated off a wrong baseline.
     */
    private function standardRateFor(string $country): float
    {
        if ($country === '') {
            return 0.0;
        }

        try {
            return $this->vat->vatRateFor($country);
        } catch (\Throwable) {
            return 0.0;
        }
    }

    // ========================================================================
    // New API for plan-change batch charges/refunds (multi-VAT lines)
    // ========================================================================

    /**
     * Generic charge-invoice persistence for webhook routing.
     * Called by the webhook for all paid events (recurring subscription, mid-cycle,
     * plan-change batch charges, overage, one-time order).
     *
     * NO Mollie call here — that already happened earlier (the webhook only reacts to paid).
     *
     * @param  array<int, array<string, mixed>>  $lineItems  each with kind/code/label/quantity/
     *   unit_price_net/amount_net/vat_rate/vat_amount/amount_gross/period_start/period_end
     */
    public function createInvoice(
        Billable $billable,
        InvoiceKind $kind,
        ?string $molliePaymentId,
        ?string $mollieSubscriptionId,
        array $lineItems,
        ?\Carbon\CarbonInterface $periodStart = null,
        ?\Carbon\CarbonInterface $periodEnd = null,
        ?string $currency = null,
        bool $deferDocument = false,
    ): BillingInvoice {
        /** @var Model&Billable $billable */
        $sumNet = 0;
        $sumVat = 0;
        $sumGross = 0;
        foreach ($lineItems as $line) {
            $sumNet += (int) ($line['amount_net'] ?? 0);
            $sumVat += (int) ($line['vat_amount'] ?? 0);
            $sumGross += (int) ($line['amount_gross'] ?? 0);
        }

        $country = (string) ($billable->getBillingCountry() ?? '');

        $invoice = new BillingInvoice;
        $invoice->billable_type = $billable->getMorphClass();
        $invoice->billable_id = $billable->getKey();
        $invoice->mollie_payment_id = $molliePaymentId;
        $invoice->mollie_subscription_id = $mollieSubscriptionId;
        $invoice->invoice_kind = $kind;
        $invoice->status = InvoiceStatus::Paid;
        $invoice->country = strtoupper($country);
        // Same rule as createForPayment(): when the caller knows the currency the money
        // moved in, that wins over today's config. Only a purely local document (the
        // zero-amount audit invoice for a fully coupon-covered order) has none, and for
        // that one the configured currency is correct because it was priced just now.
        $invoice->currency = self::normalizeCurrency($currency);
        $invoice->amount_net = $sumNet;
        $invoice->amount_vat = $sumVat;
        $invoice->amount_gross = $sumGross;
        $invoice->line_items = $lineItems;
        $invoice->period_start = $periodStart;
        $invoice->period_end = $periodEnd;
        $invoice->refunded_net = 0;
        $invoice->vat_validation_id = $billable->currentVatValidation()?->getKey();
        $invoice->mismatch_id = $billable->latestOpenCountryMismatch()?->id;
        $this->persistWithSerial($invoice, $kind->value);

        // Same reason `createCreditNote()` has this: a caller inside a transaction hands
        // the render and the announcement back out. A rollback undoes the row but not the
        // file, so the disk kept an invoice document carrying a serial number that no
        // invoice in the books has — and until then the render held the invoice row lock
        // and the serial range. {@see issueInvoiceDocument()}.
        if (! $deferDocument) {
            $this->issueInvoiceDocument($invoice, $billable);
        }

        return $invoice;
    }

    /**
     * Render an invoice's PDF and announce it.
     *
     * Split out of {@see createInvoice()} so a caller can persist inside a transaction and
     * do this after committing. Safe to call twice — a document already on the disk is kept
     * (see {@see renderDeferredDocument()}) — but nothing calls it twice.
     */
    public function issueInvoiceDocument(BillingInvoice $invoice, Billable $billable): void
    {
        $this->storeInvoiceDocument($invoice, $billable);
        event(new InvoiceCreated($billable, $invoice));
    }

    /**
     * Render and store an invoice's PDF without announcing it — for a caller that
     * announces the document as a step of its own (`ProrataChargeHandler`).
     *
     * Takes the billable the caller already holds. {@see regeneratePdf()} resolves it
     * through the invoice's relation, under `BillingScope`: in a webhook there is no
     * tenant context for an app's `applyBillingScope()` to key off, so it finds nothing
     * and the invoice is left without a document.
     *
     * @return bool whether a PDF was stored (failures are logged by generateAndStorePdf())
     */
    public function storeInvoiceDocument(BillingInvoice $invoice, Billable $billable): bool
    {
        return $this->renderDeferredDocument($invoice, $billable);
    }

    /**
     * Phase 1 for plan change: sends CreatePaymentRequest with metadata.type='prorata_charge'
     * and persists the pending state in subscription_meta.pending_prorata_change.
     *
     * Persistence of the charge invoice happens later in the webhook via createInvoice().
     *
     * @param  list<ProrataLine>  $chargeLines
     * @param  list<ProrataLine>  $pendingRefundLines  Executed after charge-webhook OK
     */
    public function createCharge(
        Billable $billable,
        array $chargeLines,
        array $pendingRefundLines,
        PlanChangeIntent $intent,
    ): void {
        /** @var Model&Billable $billable */
        if (! $billable->hasMollieMandate()) {
            throw new \LogicException('Cannot create charge: billable has no Mollie mandate.');
        }

        $grossTotal = array_sum(array_map(fn (ProrataLine $l) => $l->amountGross, $chargeLines));
        if ($grossTotal <= 0) {
            return; // Coupon-covered or zero — no Mollie charge
        }

        $currency = self::configuredCurrency();

        // A payment an earlier attempt created without our learning its id: take it over
        // for the same change, or get it out of the way for a different one — never a
        // second debit next to it.
        if ($this->takeOverChargeInCreation($billable, $intent)) {
            return;
        }

        // Noted BEFORE Mollie is asked, in a write of its own that commits at once. If the
        // answer is lost (a timeout) or the write below fails, Mollie holds a payment
        // nothing points at; the token in its metadata is how the next attempt — or the
        // payment's own webhook — finds it again.
        $token = (string) Str::uuid();
        $chargeLinesData = array_map(fn (ProrataLine $l) => $l->toArray(), $chargeLines);
        $refundLinesData = array_map(fn (ProrataLine $l) => $l->toArray(), $pendingRefundLines);

        $this->mutateBillingMeta($billable, fn (array $meta): array => [
            ...$meta,
            self::CHARGE_IN_CREATION_KEY => [
                'token' => $token,
                'charge_lines' => $chargeLinesData,
                'refund_lines' => $refundLinesData,
                'intent' => $intent->toArray(),
                'created_at' => BillingTime::nowUtc()->toIso8601String(),
            ],
        ]);

        // Mollie metadata is hard-capped at 1024 bytes — keep it to a minimal correlation
        // payload. The full charge/refund line list lives in subscription_meta.pending_prorata_change
        // and is read back by the Phase-2 webhook.
        $payment = MollieCall::send(new CreatePaymentRequest(
            description: "Plan change for {$intent->newPlan}",
            amount: new MollieMoney($currency, self::molliePrice($grossTotal, $currency)),
            metadata: [
                'type' => 'prorata_charge',
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
                'charge_token' => $token,
            ],
            sequenceType: 'recurring',
            mandateId: $billable->getMollieMandateId(),
            customerId: $billable->getMollieCustomerId(),
            webhookUrl: route(BillingRoute::webhook()),
        ));

        // Under the row lock, touching only this key. The read used to happen before the
        // Mollie round-trip above and the whole JSON column was written back afterwards, so
        // anything a concurrent writer committed in that window was reverted — including a
        // `pending_refund_retries` line the retry job had just consumed, which
        // SweepPendingRetriesJob then paid out a second time.
        $this->mutateBillingMeta($billable, function (array $meta) use ($payment, $chargeLinesData, $refundLinesData, $intent): array {
            unset($meta[self::CHARGE_IN_CREATION_KEY]);

            return [
                ...$meta,
                'pending_prorata_change' => [
                    'charge_payment_id' => is_object($payment) ? ($payment->id ?? null) : null,
                    'charge_lines' => $chargeLinesData,
                    'refund_lines' => $refundLinesData,
                    'intent' => $intent->toArray(),
                    'created_at' => BillingTime::nowUtc()->toIso8601String(),
                ],
            ];
        });
    }

    /**
     * Deal with a payment an earlier attempt may have created without our learning its id.
     *
     * Returns true when that payment was adopted for this very change — it is then the
     * change's pending payment, and no new one may be created. False when there is nothing
     * in the way (no marker, Mollie holds no such payment, or it is dead). A lost payment
     * for a *different* change is cancelled where Mollie allows it; otherwise it is left to
     * settle and is returned in full by the webhook (`UnwantedPaymentRefund`), because
     * nothing claims it.
     *
     * @throws \RuntimeException when Mollie cannot say whether the payment exists — creating a
     *         second one on a guess is exactly the double debit this exists to prevent
     */
    private function takeOverChargeInCreation(Billable $billable, PlanChangeIntent $intent): bool
    {
        /** @var Model&Billable $billable */
        $marker = $billable->refresh()->getBillingSubscriptionMeta()[self::CHARGE_IN_CREATION_KEY] ?? null;

        if (! is_array($marker)) {
            return false;
        }

        $payment = $this->resolveChargeInCreation($billable, $marker);

        if ($payment === null) {
            $this->forgetChargeInCreation($billable, (string) ($marker['token'] ?? ''));

            return false;
        }

        if ($this->sameIntent((array) ($marker['intent'] ?? []), $intent->toArray())) {
            return $this->adoptChargeInCreation($billable, (string) ($marker['token'] ?? ''), (string) $payment->id);
        }

        if (($payment->isCancelable ?? false) === true) {
            try {
                MollieCall::send(new \Mollie\Api\Http\Requests\CancelPaymentRequest((string) $payment->id));
            } catch (\Throwable $e) {
                Log::warning('A lost prorata payment of an earlier change could not be cancelled — it is returned if it settles', [
                    'billable' => $billable->getKey(),
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->forgetChargeInCreation($billable, (string) ($marker['token'] ?? ''));

        return false;
    }

    /**
     * The live payment Mollie holds for a noted charge, or null when it holds none — or
     * only a dead one, or one that has already been booked (its webhook arrived and
     * returned it).
     *
     * @param  array<string, mixed>  $marker  the {@see CHARGE_IN_CREATION_KEY} entry
     *
     * @throws \RuntimeException when Mollie cannot be asked
     */
    public function resolveChargeInCreation(Billable $billable, array $marker): ?object
    {
        $token = (string) ($marker['token'] ?? '');
        $customerId = (string) ($billable->getMollieCustomerId() ?? '');

        if ($token === '' || $customerId === '') {
            return null;
        }

        try {
            $payments = MollieCall::send(new \Mollie\Api\Http\Requests\GetPaginatedCustomerPaymentsRequest($customerId, limit: 50));
        } catch (\Throwable $e) {
            throw new \RuntimeException('Mollie could not be asked whether an earlier prorata payment exists: '.$e->getMessage(), 0, $e);
        }

        if (! is_iterable($payments)) {
            $payments = is_object($payments) ? ($payments->data ?? ($payments->payments ?? [])) : [];
        }

        foreach (is_iterable($payments) ? $payments : [] as $payment) {
            $metadata = (array) json_decode((string) json_encode($payment->metadata ?? []), true);

            if (($metadata['type'] ?? null) !== 'prorata_charge' || (string) ($metadata['charge_token'] ?? '') !== $token) {
                continue;
            }

            if (in_array((string) ($payment->status ?? ''), ['failed', 'canceled', 'expired'], true)) {
                return null;
            }

            if (BillingInvoice::query()->where('mollie_payment_id', (string) $payment->id)->exists()) {
                return null;
            }

            return $payment;
        }

        return null;
    }

    /**
     * Make a noted charge the change's pending payment, as if its creation had answered.
     * Only while nothing else holds the slot. Returns whether it was adopted.
     */
    public function adoptChargeInCreation(Billable $billable, string $token, string $paymentId): bool
    {
        if (! ($billable instanceof Model) || $token === '' || $paymentId === '') {
            return false;
        }

        $adopted = false;

        $this->mutateBillingMeta($billable, function (array $meta) use ($token, $paymentId, &$adopted): ?array {
            $marker = $meta[self::CHARGE_IN_CREATION_KEY] ?? null;

            if (! is_array($marker) || (string) ($marker['token'] ?? '') !== $token || ! empty($meta['pending_prorata_change'])) {
                return null;
            }

            unset($meta[self::CHARGE_IN_CREATION_KEY]);
            $meta['pending_prorata_change'] = [
                'charge_payment_id' => $paymentId,
                'charge_lines' => (array) ($marker['charge_lines'] ?? []),
                'refund_lines' => (array) ($marker['refund_lines'] ?? []),
                'intent' => (array) ($marker['intent'] ?? []),
                'created_at' => (string) ($marker['created_at'] ?? BillingTime::nowUtc()->toIso8601String()),
            ];
            $adopted = true;

            return $meta;
        });

        if ($adopted) {
            Log::info('A prorata payment whose creation went unanswered was found at Mollie and taken over', [
                'billable' => $billable->getKey(),
                'payment_id' => $paymentId,
            ]);
        }

        return $adopted;
    }

    /** Drop the note of a charge in creation — only the one with this token. */
    public function forgetChargeInCreation(Billable $billable, string $token): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        $this->mutateBillingMeta($billable, function (array $meta) use ($token): ?array {
            if ((string) ($meta[self::CHARGE_IN_CREATION_KEY]['token'] ?? '') !== $token) {
                return null;
            }

            unset($meta[self::CHARGE_IN_CREATION_KEY]);

            return $meta;
        });
    }

    /**
     * Whether two serialised intents describe the same change. The billable keys are left
     * out: a morph alias and a class name name the same row.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function sameIntent(array $a, array $b): bool
    {
        $normalise = function (array $intent): array {
            unset($intent['billable_type'], $intent['billable_id']);

            foreach (['current_addons', 'new_addons'] as $key) {
                $addons = (array) ($intent[$key] ?? []);
                ksort($addons);
                $intent[$key] = $addons;
            }

            ksort($intent);

            return $intent;
        };

        return $normalise($a) == $normalise($b);
    }

    /**
     * Internally makes N Mollie refund calls (against originalInvoice.mollie_payment_id per line)
     * and persists the result as a BillingInvoice with invoice_kind=Refund.
     *
     * Each call is posted in the currency of the invoice it refunds — Mollie
     * rejects any other — and the credit note inherits that currency.
     *
     * Idempotent per line, locally: if a refund line_item already exists with
     * (parent_invoice_id, parent_line_item_index, amount_net) → skipped. (Not a Mollie
     * status: Mollie answers a reused key with the first answer, or 400 for other
     * parameters — measured 26 November 2026, tests/Sandbox/MollieIdempotencyContractTest.)
     *
     * Coupon-covered lines are filtered out.
     *
     * Failed lines are stored in subscription_meta.pending_refund_retries.
     *
     * @param  list<ProrataLine>  $refundLines  all direction='refund'
     * @return BillingInvoice|null  null when all lines are coupon-covered or failed
     */
    /**
     * A copy of a refund line reduced to what Mollie will actually return.
     *
     * `ProrataLine`'s amounts are readonly, so this builds a new instance — the same
     * thing {@see ProrataComposer} does when a line does not fit the invoice's remaining
     * refundable budget.
     */
    private function cappedRefundLine(
        ProrataLine $line,
        BillingInvoice $original,
        int $cappedGross,
        int $uncappedGross,
    ): ProrataLine {
        // Net from the gross through the invoice's own line composition, never one
        // line's rate applied to the whole amount — that is what creditNetForGross()
        // is for, and it is what keeps a mixed-rate invoice's books straight.
        $cappedNet = $this->creditNetForGross($original, $cappedGross);

        if ($cappedNet <= 0) {
            $rate = $line->vatRate;
            $cappedNet = $rate > 0 ? (int) round($cappedGross / (1 + $rate / 100)) : $cappedGross;
        }

        $cappedVat = max(0, $cappedGross - $cappedNet);

        $capped = new ProrataLine(
            originalInvoice: $line->originalInvoice,
            originalLineItemIndex: $line->originalLineItemIndex,
            kind: $line->kind,
            code: $line->code,
            label: $line->label,
            quantity: $line->quantity,
            amountNet: -$cappedNet,
            vatRate: $line->vatRate,
            amountVat: -$cappedVat,
            amountGross: -$cappedGross,
            periodStart: $line->periodStart,
            periodEnd: $line->periodEnd,
            daysActive: $line->daysActive,
            daysRemaining: $line->daysRemaining,
            isCouponCovered: $line->isCouponCovered,
            direction: $line->direction,
        );

        // The capped line is the same refund: it keeps the token that is part of its Mollie
        // idempotency key. A fresh one sent a retried refund under a new key — paid again —
        // and left nothing on the credit note to recognise the line by.
        $capped->refundToken = $line->refundToken;
        $capped->refundKeyRefusals = $line->refundKeyRefusals;

        $capped->refundCapNote = [
            'alreadyRefundedNet' => (int) $original->effectiveRefundedNet(),
            'originalAmountNet' => (int) $original->amount_net,
            'uncappedRefundNet' => abs($line->amountNet),
            'cappedRefundNet' => $cappedNet,
            'uncappedRefundGross' => $uncappedGross,
            'cappedRefundGross' => $cappedGross,
        ];

        return $capped;
    }

    /**
     * What the books hold for each of these refund-line tokens, read from the billable's
     * credit notes in one query: `standing` when a booking carrying the token still stands,
     * `reversed` when every booking carrying it was taken back by
     * {@see reverseBookedCredit()} (Mollie reported the refund `failed` or `canceled` — the
     * money never left). A token with no booking at all is absent from the result.
     *
     * Only `standing` settles a line. Shared with ProrataChargeHandler, whose resumed run
     * must not drop a line a reversal re-opened any more than {@see createRefund()} may.
     *
     * @param  list<string>  $tokens
     * @return array<string, 'standing'|'reversed'>
     */
    public function refundTokenBookings(Billable $billable, array $tokens): array
    {
        $wanted = array_flip(array_filter($tokens, static fn (mixed $t): bool => is_string($t) && $t !== ''));

        if ($wanted === []) {
            return [];
        }

        return array_intersect_key($this->creditNoteBookings($billable)['tokens'], $wanted);
    }

    /**
     * Every refund the billable's credit notes have booked, read in one query: the state
     * of each refund-line token (see {@see refundTokenBookings()}) and every Mollie refund
     * id a credit-note line carries.
     *
     * {@see createRefund()} reads this once per refund lock instead of once per question
     * per line. Each lookup used to load and decode every credit note of the billable
     * again — three times per retried line — so a plan change with plan, seat and addon
     * lines, on a billable with a long refund history, re-read that history up to nine
     * times while holding the refund lock. Within one lock nothing else can book against
     * the invoice it guards (RefundHandler and RefundInvoiceService take the same lock),
     * and the credit note this run writes is persisted only after its last Mollie call.
     *
     * @return array{tokens: array<string, 'standing'|'reversed'>, refund_ids: array<string, true>}
     */
    private function creditNoteBookings(Billable $billable): array
    {
        $empty = ['tokens' => [], 'refund_ids' => []];

        if (! ($billable instanceof Model)) {
            return $empty;
        }

        $lines = BillingInvoice::query()
            ->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $billable->getKey())
            ->where('invoice_kind', InvoiceKind::Refund)
            ->get(['line_items'])
            ->flatMap(fn (BillingInvoice $note): array => array_values(array_filter((array) $note->line_items, 'is_array')));

        $refundIds = [];
        $reversedRefundIds = [];

        foreach ($lines as $line) {
            $refundId = $line['mollie_refund_id'] ?? null;

            if (! is_string($refundId) || $refundId === '') {
                continue;
            }

            // Any line, the reversal's mirror included: "already on the books" asks whether
            // a credit note carries the id at all.
            $refundIds[$refundId] = true;

            if (BillingInvoice::creditSign($line) === -1) {
                $reversedRefundIds[$refundId] = true;
            }
        }

        $tokens = [];

        foreach ($lines as $line) {
            $token = $line['refund_token'] ?? null;

            if (! is_string($token) || $token === '' || BillingInvoice::creditSign($line) === -1
                || ($tokens[$token] ?? null) === 'standing') {
                continue;
            }

            $refundId = $line['mollie_refund_id'] ?? null;

            $tokens[$token] = is_string($refundId) && $refundId !== '' && isset($reversedRefundIds[$refundId])
                ? 'reversed'
                : 'standing';
        }

        return ['tokens' => $tokens, 'refund_ids' => $refundIds];
    }

    /**
     * The refund an earlier attempt created for this token at Mollie, if there is one.
     *
     * Matched on the token {@see createRefund()} sends as refund metadata, in the payment's
     * refund list (every page, through MollieCall), which the caller fetches once per
     * payment and refund lock. A refund Mollie reports as `failed` or `canceled` did not
     * pay out and does not count — the line is posted again.
     *
     * @param  iterable<object>  $refunds
     */
    private function refundCreatedWithToken(iterable $refunds, string $token): ?object
    {
        foreach ($refunds as $refund) {
            $metadata = $refund->metadata ?? null;
            $carried = is_object($metadata) ? ($metadata->refund_token ?? null) : (is_array($metadata) ? ($metadata['refund_token'] ?? null) : null);

            if ($carried === $token
                && ! in_array(strtolower((string) ($refund->status ?? '')), ['failed', 'canceled'], true)
                && (string) ($refund->id ?? '') !== ''
            ) {
                return $refund;
            }
        }

        return null;
    }

    /**
     * The `refunded_net` each original should hold once this batch's credit note exists,
     * read from the books rather than the batch's running tracker: what the billable's
     * credit notes already derive for it (standing credits minus reversals, read fresh)
     * plus the lines this batch still books against it.
     *
     * Used when the final look at the books dropped a line — the tracker counted that line,
     * and only the books know whether the other actor's booking of it still stands.
     *
     * @param  array<int|string, array{0: BillingInvoice, 1: int, 2: ?string}>  $writes
     * @param  list<ProrataLine>  $persistedLines
     * @return array<int|string, array{0: BillingInvoice, 1: int, 2: ?string}>
     */
    private function refundedNetFromBooks(array $writes, array $persistedLines): array
    {
        $keptNet = [];

        foreach ($persistedLines as $line) {
            $key = $line->originalInvoice?->getKey();

            if ($key !== null) {
                $keptNet[$key] = ($keptNet[$key] ?? 0) + (int) abs($line->amountNet);
            }
        }

        foreach ($writes as $key => [$original, , $mollieRefundId]) {
            $fresh = BillingInvoice::query()->whereKey($key)->first();

            if ($fresh === null) {
                unset($writes[$key]);

                continue;
            }

            $writes[$key] = [$original, $fresh->effectiveRefundedNet() + ($keptNet[$key] ?? 0), $mollieRefundId];
        }

        return $writes;
    }

    /**
     * Take the per-invoice refund lock, waiting briefly for a holder to finish.
     *
     * Waits rather than refusing outright, unlike RefundInvoiceService: that one answers an
     * operator who can click again, this one runs inside a plan change whose charge has
     * already settled. Returns null when the lock stays taken — the caller then queues the
     * line for {@see \GraystackIT\MollieBilling\Jobs\RetryRefundLineJob}.
     */
    private function acquireRefundLock(int|string $invoiceId): ?\Illuminate\Contracts\Cache\Lock
    {
        $lock = Cache::lock('billing:invoice-refund:'.$invoiceId, self::REFUND_LOCK_SECONDS);

        try {
            $lock->block(3);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            return null;
        }

        return $lock;
    }

    /**
     * Take a booked credit back out of the books, because the money never left.
     *
     * A refund is booked when it is created — that is the only moment the package is
     * guaranteed to hear about it — but Mollie can still report it as `failed` (the bank
     * bounced it) or `canceled` (withdrawn while queued) afterwards, and the funds return
     * to the merchant. The credit note then stated a refund the customer never received:
     * revenue and output VAT under-declared, and `refunded_net` refusing a second attempt
     * at the very refund that had just failed.
     *
     * A credit note is a tax document and is not deleted. It is reversed by a document of
     * its own that mirrors the affected lines with the sign flipped and `reverses_credit`
     * set, which is what {@see BillingInvoice::creditSign()} reads to subtract instead of
     * add. `refunded_net` on every invoice the lines pointed at drops by the same amount.
     *
     * Idempotent: lines carrying `$referenceKey = $referenceValue` are reversed once.
     * `$fallbackParent` is the invoice whose `refunded_net` to restore for lines that name
     * no `parent_invoice_id` of their own.
     * Wallet units a refund handed back are NOT taken away again — they may have been
     * consumed since; the caller alerts an operator instead.
     *
     * @return BillingInvoice|null  the reversal document, or null when there was nothing
     *                              (left) to reverse
     */
    public function reverseBookedCredit(
        Billable $billable,
        string $referenceKey,
        string $referenceValue,
        ?string $description = null,
        ?BillingInvoice $fallbackParent = null,
    ): ?BillingInvoice {
        /** @var Model&Billable $billable */
        $reversal = DB::transaction(function () use ($billable, $referenceKey, $referenceValue, $description, $fallbackParent): ?BillingInvoice {
            $notes = BillingInvoice::query()
                ->where('billable_type', $billable->getMorphClass())
                ->where('billable_id', $billable->getKey())
                ->where('invoice_kind', InvoiceKind::Refund)
                ->orderBy('id')
                ->get();

            $source = null;
            $lines = [];
            $sumNet = 0;
            $sumVat = 0;
            $sumGross = 0;
            $netPerParent = [];

            foreach ($notes as $note) {
                foreach ((array) ($note->line_items ?? []) as $line) {
                    if (! is_array($line) || ($line[$referenceKey] ?? null) !== $referenceValue) {
                        continue;
                    }

                    if (BillingInvoice::creditSign($line) === -1) {
                        return null;
                    }

                    $source ??= $note;

                    $mirrored = $line;
                    unset($mirrored['wallet_credits'], $mirrored['refund_cap_note'], $mirrored['refund_token']);

                    foreach (['unit_price', 'unit_price_net', 'total_net', 'amount_net', 'vat_amount', 'amount_gross'] as $field) {
                        if (isset($mirrored[$field])) {
                            $mirrored[$field] = abs((int) $mirrored[$field]);
                        }
                    }

                    $mirrored['reverses_credit'] = true;
                    $mirrored['reversed_credit_note_id'] = (int) $note->getKey();

                    $net = (int) ($mirrored['amount_net'] ?? $mirrored['total_net'] ?? 0);
                    $vat = (int) ($mirrored['vat_amount'] ?? 0);

                    $sumNet += $net;
                    $sumVat += $vat;
                    $sumGross += (int) ($mirrored['amount_gross'] ?? ($net + $vat));

                    // A credit note written from custom line items names no parent on its
                    // lines; the caller knows which invoice it was booked against.
                    $parentId = (int) ($line['parent_invoice_id'] ?? $fallbackParent?->getKey() ?? 0);
                    if ($parentId > 0) {
                        $netPerParent[$parentId] = ($netPerParent[$parentId] ?? 0) + $net;
                    }

                    $lines[] = $mirrored;
                }
            }

            if ($source === null || $lines === []) {
                return null;
            }

            // Snapshot BEFORE the reversal exists: afterwards `effectiveRefundedNet()`
            // already derives the lower figure from it.
            $parents = [];
            foreach ($netPerParent as $parentId => $net) {
                $parent = BillingInvoice::query()->whereKey($parentId)->lockForUpdate()->first();

                if ($parent !== null) {
                    $parents[] = [$parent, max(0, $parent->effectiveRefundedNet() - $net)];
                }
            }

            $document = new BillingInvoice;
            $document->billable_type = $source->billable_type;
            $document->billable_id = $source->billable_id;
            $document->mollie_payment_id = null;
            $document->mollie_subscription_id = $source->mollie_subscription_id;
            $document->invoice_kind = InvoiceKind::Refund;
            // Not `Refunded`: nothing was. This document restores a charge.
            $document->status = InvoiceStatus::Paid;
            $document->country = $source->country;
            $document->currency = self::normalizeCurrency($source->currency);
            $document->amount_net = $sumNet;
            $document->amount_vat = $sumVat;
            $document->amount_gross = $sumGross;
            $document->line_items = $lines;
            $document->payment_method_details = $source->payment_method_details;
            $document->refund_reason_text = $description;
            $document->refunded_net = 0;
            // Same tax event as the credit note it undoes.
            $document->vat_validation_id = $source->vat_validation_id;
            $this->persistWithSerial($document, 'refund');

            foreach ($parents as [$parent, $refundedNet]) {
                $parent->refunded_net = $refundedNet;
                $parent->save();
            }

            return $document;
        });

        if ($reversal !== null) {
            $this->generateAndStorePdf($reversal, $billable);
        }

        return $reversal;
    }

    public function createRefund(
        Billable $billable,
        array $refundLines,
        ?string $description = null,
    ): ?BillingInvoice {
        /** @var Model&Billable $billable */
        $effective = array_values(array_filter($refundLines, fn (ProrataLine $l) => ! $l->isCouponCovered && $l->amountGross < 0));
        if (empty($effective)) {
            return null;
        }

        $persistedLines = [];
        $failedLines = [];
        // Each refund is posted against a specific original payment, so the
        // currency comes from that invoice — Mollie rejects a refund whose
        // currency differs from the payment's, which after a BILLING_CURRENCY
        // change is every refund of a historical invoice.
        $batchCurrency = null;

        // Multiple refund lines can target the same original invoice (e.g. plan + seats + addon
        // on a plan change). They are independent ProrataLine instances loaded via separate
        // currentPeriodLines() queries, so each one carries its own in-memory clone of the
        // original BillingInvoice — without coordination, every save() would write back its
        // own stale `refunded_net` and clobber the previous iteration's increment.
        $refundedNetPerInvoice = [];

        // One refund per invoice at a time, under the lock RefundInvoiceService and the
        // refund webhook already share — taken per line, for the invoice that holds the
        // payment, and held until the credit note below is committed.
        //
        // This path posts its refunds line by line and writes the document that carries
        // their `mollie_refund_id` only after the LAST call, while Mollie calls the payment
        // webhook as soon as the first refund exists. RefundHandler found no id on record,
        // booked the refund as "synced from Mollie dashboard", and the credit note written
        // here then booked it a second time: `effectiveRefundedNet()` read 50.00 for 30.00
        // returned, and the invoice refused every further refund. The reservation cannot
        // help — that delivery is for the original payment, a different id from the one
        // being processed. A cache lock rather than a transaction, because the Mollie calls
        // sit inside it.
        /** @var array<int|string, \Illuminate\Contracts\Cache\Lock|null> $refundLocks */
        $refundLocks = [];

        /** @var array<int|string, array{0: BillingInvoice, 1: int, 2: ?string}> $refundedNetWrites */
        $refundedNetWrites = [];

        // What the books hold and what Mollie lists, read once per refund lock rather than
        // once per line and question — see creditNoteBookings(). Dropped whenever another
        // invoice's lock is taken: until that lock was ours, the webhook could still book
        // against that invoice.
        /** @var array{tokens: array<string, 'standing'|'reversed'>, refund_ids: array<string, true>}|null $books */
        $books = null;
        /** @var array<string, list<object>> $mollieRefunds */
        $mollieRefunds = [];
        /** @var array<int|string, int> $lockTakenAt  unix time each refund lock was taken */
        $lockTakenAt = [];

        try {
            foreach ($effective as $line) {
                $original = $line->originalInvoice;
                // A settled invoice (the saldo-zero plan switch) holds no payment of its own —
                // its charges were paid for out of the invoice it settles against, and that is
                // where the refund has to be posted.
                $refundPaymentId = $original?->refundablePaymentId();

                if ($original === null || $refundPaymentId === null) {
                    $failedLines[] = ['line' => $line->toArray(), 'error' => 'Missing original invoice or payment_id'];
                    continue;
                }

                // Keyed on the invoice that holds the payment: that is the one the webhook
                // resolves from the payment id, and a settled plan-switch document refunds
                // out of its root's payment.
                $lockInvoiceId = (self::settlementRoot($original) ?? $original)->getKey();

                if (! array_key_exists($lockInvoiceId, $refundLocks)) {
                    $refundLocks[$lockInvoiceId] = $this->acquireRefundLock($lockInvoiceId);
                    $lockTakenAt[$lockInvoiceId] = BillingTime::nowUtc()->getTimestamp();
                    $books = null;
                }

                if ($refundLocks[$lockInvoiceId] === null) {
                    // Somebody else is refunding this invoice right now — an admin refund,
                    // or the webhook syncing a dashboard refund. Whatever this line
                    // validated is about to be stale, so it goes to the retry queue, which
                    // re-reads the remaining balance when its turn comes.
                    $failedLines[] = ['line' => $line->toArray(), 'error' => 'Another refund of this invoice is in progress', 'first_attempt_at' => BillingTime::nowUtc()->toIso8601String()];

                    continue;
                }

                // A line whose refund is already on the books is settled, whoever booked it.
                // A retried line arrives here after its Mollie answer was lost — and by then
                // the payment webhook has typically booked that very refund as "synced from
                // Mollie dashboard" (RefundHandler stamps the token from the refund's
                // metadata onto it). Posting the line again wrote a second credit note for
                // one refund, or — once the headroom clamp or Mollie's one-hour key cache
                // moved the key — paid the customer a second time; and with the invoice used
                // up, failed "Nothing left to refund" for seven days and dead-lettered as a
                // refund that never went out.
                //
                // On the books means a booking that stands. One that RefundHandler reversed —
                // Mollie reported the refund `failed` or `canceled` after it was synced — paid
                // nothing out, and settling the line on it dropped an owed refund with no
                // retry left. Such a line is posted again, as when the lookup below finds
                // the dead refund at Mollie before anything booked it.
                // Past the trusted window the lease may lapse before this line is decided, and
                // a standing token that another actor reversed meanwhile would settle a line
                // that was never paid — dropped with no retry. Read again for every such line.
                if (BillingTime::nowUtc()->getTimestamp() - $lockTakenAt[$lockInvoiceId] >= self::REFUND_SNAPSHOT_TRUSTED_SECONDS) {
                    $books = null;
                    $mollieRefunds = [];
                }

                $books ??= $this->creditNoteBookings($billable);
                $tokenBooking = $line->refundToken !== null ? ($books['tokens'][$line->refundToken] ?? null) : null;

                if ($tokenBooking === 'standing') {
                    Log::info('Refund line already on the books — not posted again', [
                        'billable' => $billable->getKey(),
                        'original_invoice_id' => $original->getKey(),
                        'refund_token' => $line->refundToken,
                    ]);

                    continue;
                }

                if ($tokenBooking === 'reversed') {
                    // A refund carrying this token exists at Mollie and is dead. Its key still
                    // replays it within the hour (the same amount comes back once the reversal
                    // restored the headroom), so the key moves on — the token stays, and the
                    // lookup below runs first, so a live refund a later attempt created is
                    // adopted rather than paid a second time.
                    $line->refundKeyRefusals++;
                    $line->refundOutcomeUnknown = true;
                }

                // Its earlier call threw, so Mollie may hold the refund without anything here
                // knowing. Asked, not guessed — the idempotency key only covers the first
                // hour of a retry ladder that runs for days. See "A lost create-payment
                // answer is marked, never guessed" in docs/decisions.md.
                $adopted = null;

                if ($line->refundOutcomeUnknown && $line->refundToken !== null) {
                    try {
                        $listedForThisLine = ! array_key_exists($refundPaymentId, $mollieRefunds);
                        $mollieRefunds[$refundPaymentId] ??= app(MolliePaymentCredits::class)->refunds((object) ['id' => $refundPaymentId]);
                        $adopted = $this->refundCreatedWithToken($mollieRefunds[$refundPaymentId], $line->refundToken);

                        // Listed for an earlier line: the status may have moved since — a
                        // queued refund can have failed meanwhile, and booking it would pay
                        // nothing out while the line stopped being retried. Asked again for
                        // this one refund, which is rare: only a lost answer gets here.
                        if ($adopted !== null && ! $listedForThisLine) {
                            $current = MollieCall::send(new GetPaymentRefundRequest($refundPaymentId, (string) $adopted->id));
                            $adopted = $this->refundCreatedWithToken([$current], $line->refundToken);
                        }
                    } catch (\Throwable $e) {
                        $failedLines[] = ['line' => $line->toArray(), 'error' => $e->getMessage(), 'first_attempt_at' => BillingTime::nowUtc()->toIso8601String()];

                        continue;
                    }

                    if ($adopted !== null && isset($books['refund_ids'][(string) $adopted->id])) {
                        continue;
                    }
                }

                // No (parent_invoice_id, idx, amount_net)-style idempotency check here:
                // the same triple can legitimately recur when a user reduces seats,
                // re-adds them, then reduces again — each event is a distinct refund.
                // BillingInvoice::currentPeriodLines() already filters by remaining
                // quantity, so the composer never produces a refund line that exceeds
                // what's still on the original invoice.

                $absGross = abs($line->amountGross);

                // Mollie only knows the original payment's gross charge — it has
                // no concept of our local net/VAT split. The refund amount must
                // therefore never exceed what Mollie actually collected, minus
                // anything already refunded on that payment. If our local gross
                // drifts above Mollie's charge (e.g. due to a VAT-classification
                // change between original payment and refund), clamp and warn so
                // the drift is investigable.
                //
                // Use the running per-invoice tracker so successive lines targeting
                // the same original see refunds from earlier iterations in this loop
                // — the in-memory $original may be a separate clone whose attributes
                // are still stale after another iteration's save().
                //
                // The cap has two components:
                //   1. amount_gross is the absolute upper bound (what Mollie collected).
                //   2. The remaining refundable amount must be computed in net space
                //      (refunded_net is a net counter, consistent with
                //      remainingRefundableNet() and the CountryMatch /
                //      CountryMismatchResolution services) and converted back to
                //      gross via the original's per-line VAT before comparing to
                //      Mollie — one line's rate applied to the whole rest would
                //      misprice every mixed-VAT invoice.
                $originalKey = $original->getKey();
                $lineCurrency = self::normalizeCurrency($original->currency);
                $batchCurrency ??= $lineCurrency;

                if ($lineCurrency !== $batchCurrency) {
                    // One credit note can only carry one currency — flag it so the
                    // split is investigable instead of silently mis-booked.
                    Log::warning('Refund batch spans multiple currencies', [
                        'billable' => $billable->getKey(),
                        'original_invoice_id' => $originalKey,
                        'batch_currency' => $batchCurrency,
                        'line_currency' => $lineCurrency,
                    ]);
                }

                // effectiveRefundedNet(), not the raw column: a credit note whose
                // `refunded_net` write was lost is still a refund that happened, and reading
                // the column alone reported the full amount as still refundable.
                //
                // Measured against the value the invoice itself holds, not its header. They are
                // the same number for every invoice that collected a payment — but a settled
                // plan-switch invoice nets to zero by construction, so the header said there was
                // nothing to refund while its charge line was real, unused service. See
                // BillingInvoice::refundableBaseNet().
                $alreadyRefundedNet = $refundedNetPerInvoice[$originalKey] ?? $original->effectiveRefundedNet();
                $restRefundableNet = max(0, $original->refundableBaseNet() - $alreadyRefundedNet);
                $restRefundableGross = $restRefundableNet + ($restRefundableNet > 0 ? $this->creditNoteVat($original, $restRefundableNet) : 0);

                $restRefundable = min($original->refundableBaseGross(), $restRefundableGross);
                $refundAmount = min($absGross, $restRefundable);

                if ($adopted !== null) {
                    // The refund exists: book what Mollie paid out, not what would be sent now.
                    $refundAmount = abs(self::mollieAmountToMinor($adopted->amount->value ?? null, $adopted->amount->currency ?? $lineCurrency));

                    if ($refundAmount !== $absGross) {
                        $line = $this->cappedRefundLine($line, $original, $refundAmount, $absGross);
                    }
                } elseif ($refundAmount < $absGross) {
                    Log::warning('Refund amount clamped below local gross', [
                        'billable' => $billable->getKey(),
                        'original_invoice_id' => $original->getKey(),
                        'mollie_payment_id' => $refundPaymentId,
                        'local_gross' => $absGross,
                        'rest_refundable' => $restRefundable,
                        'refund_sent' => $refundAmount,
                    ]);

                    // The clamp used to affect the API call ONLY. The line was persisted
                    // unchanged and `refunded_net` incremented by the full net, so the credit
                    // note — a tax document — stated more than Mollie returned, `refunded_net`
                    // could exceed `amount_net`, and every later refund decision read an
                    // inflated base. The customer was short the difference with a log line as
                    // the only trace. Reached whenever something else refunded part of the same
                    // invoice between a plan change's Phase 1 and its Phase 2, which for SEPA
                    // is days apart.
                    //
                    // Rebuilt exactly as ProrataComposer caps a line: net from the clamped
                    // gross via the invoice's own line composition, VAT proportional to the
                    // line's own rate, and a `refundCapNote` so the reduction is visible
                    // rather than inferred.
                    $line = $this->cappedRefundLine($line, $original, $refundAmount, $absGross);
                }

                if ($refundAmount <= 0) {
                    $failedLines[] = ['line' => $line->toArray(), 'error' => 'Nothing left to refund on the original payment', 'first_attempt_at' => BillingTime::nowUtc()->toIso8601String()];
                    continue;
                }

                // Without an explicit idempotency key, Mollie deduplicates refund requests
                // against (payment_id, amount) — so two legitimate refunds for identical
                // amounts on the same payment (e.g. seat reduce → re-add → reduce again) get
                // rejected with 409 "duplicate refund". A token per refund line marks each
                // call as a distinct operation so Mollie processes them all.
                //
                // The token is minted once and then carried by the line, rather than being
                // fresh randomness per attempt: a refund whose HTTP response is lost has
                // already been created at Mollie, and the retry that follows must replay it
                // instead of paying the customer a second time.
                $line->refundToken ??= bin2hex(random_bytes(4));

                if ($adopted !== null) {
                    $refund = $adopted;
                } else {
                    // Moved on by every definite refusal of this line (a 4xx binds its key to
                    // the refusal for an hour — measured 26 November 2026), never by an outage
                    // or a lost answer. See MollieRefusal.
                    $idempotencyKey = MollieRefusal::key(sprintf(
                        'refund:%s:%s:%s:%d:%d:%s',
                        $billable->getMorphClass(),
                        (string) $billable->getKey(),
                        (string) $original->getKey(),
                        (int) ($line->originalLineItemIndex ?? 0),
                        (int) $refundAmount,
                        $line->refundToken,
                    ), $line->refundKeyRefusals);

                    // Set here, cleared in the `finally` below.
                    //
                    // The SDK's `ResetIdempotencyKey` middleware only runs on a response, and
                    // `ConvertResponseToException` turns a 4xx into a throw before it gets there —
                    // so a refund Mollie rejects left its key on the shared client, and the next
                    // mutating request through that client (a payment, a PATCH) carried it. Mollie
                    // answers a reused key with the *previous* response, which is how a plan
                    // change's charge could come back as the rejected refund's error.
                    Mollie::setIdempotencyKey($idempotencyKey);

                    // The try covers the Mollie call and nothing else. It used to wrap the
                    // bookkeeping below as well, so a failing `save()` — a lock-wait timeout
                    // against a concurrent admin refund is enough — filed the line in
                    // `$persistedLines` *and* in `$failedLines`: the credit note was written and a
                    // retry marker queued for a refund Mollie had already paid out. The retry then
                    // read `refunded_net` (whose write had just failed) as the remaining headroom
                    // and paid the customer twice.
                    try {
                        $refund = MollieCall::send(new CreatePaymentRefundRequest(
                            paymentId: $refundPaymentId,
                            description: $description ?? "Plan change refund: {$line->label}",
                            amount: new MollieMoney($lineCurrency, self::molliePrice($refundAmount, $lineCurrency)),
                            // How a retry finds this refund at Mollie once the key has expired,
                            // and how the webhook that books it tells which line it belongs to.
                            metadata: ['refund_token' => $line->refundToken],
                        ));
                    } catch (\Throwable $e) {
                        // A throw does not say the refund was not created — a read timeout
                        // arrives after Mollie has acted.
                        $line->refundOutcomeUnknown = true;

                        // …unless Mollie answered with a refusal: nothing was created by this
                        // attempt, and its key is bound to the refusal. The retry gets a new
                        // one. The flag above stays — an *earlier* attempt's answer may have
                        // been lost, and the retry still asks Mollie by the token first.
                        if (MollieRefusal::isDefinite($e)) {
                            $line->refundKeyRefusals++;
                        }
                        $failedLines[] = ['line' => $line->toArray(), 'error' => $e->getMessage(), 'first_attempt_at' => BillingTime::nowUtc()->toIso8601String()];

                        continue;
                    } finally {
                        // Whatever happened. The SDK clears the key from a *response*, and a 4xx
                        // is converted to a throw before that runs — leaving the key on the shared
                        // client for the next mutating request to reuse.
                        self::forgetIdempotencyKey();
                    }
                }

                $line->mollieRefundId = is_object($refund) ? ($refund->id ?? null) : null;
                $line->refundOutcomeUnknown = false;

                // Mollie answers a repeated key with the refund it already holds — and the
                // webhook may have booked that one since, from a refund that carried no token
                // to recognise the line by. One refund, one credit note.
                if ($adopted === null && $line->mollieRefundId !== null && isset($books['refund_ids'][(string) $line->mollieRefundId])) {
                    Log::info('Mollie replayed a refund that is already on the books — not booked again', [
                        'billable' => $billable->getKey(),
                        'original_invoice_id' => $originalKey,
                        'mollie_refund_id' => $line->mollieRefundId,
                    ]);

                    continue;
                }


                $persistedLines[] = $line;

                // Keep `refunded_net` on the original in sync with what we just
                // refunded at Mollie. Without this, a subsequent admin-initiated
                // refund on the same invoice would compute remainingRefundableNet()
                // against a stale base and either offer a refund that's already
                // exhausted or accept an amount that gets rejected by Mollie.
                //
                // Drive the new value off the running tracker (not $original->refunded_net)
                // because $original may be a stale clone whose in-memory attribute hasn't
                // been refreshed after an earlier iteration saved a different clone of the
                // same DB row.
                $newRefundedNet = $alreadyRefundedNet + (int) abs($line->amountNet);
                $refundedNetPerInvoice[$originalKey] = $newRefundedNet;

                // Written once the credit note exists (below), never before it: a credit
                // note whose write failed left the counter raised with no document behind
                // it, and the retry that then found the refund at Mollie by its token added
                // the same amount on top — `refunded_net` at twice what went back.
                $refundedNetWrites[$originalKey] = [$original, $newRefundedNet, $line->mollieRefundId];
            }

            // One fresh look at the books before the credit note is written. The checks in
            // the loop read the snapshot, which is sound while the refund lock holds — but
            // the lock's lease is fixed, and a batch that outlives it lets RefundHandler book
            // a refund this run just created. Booking it again here would be a second credit
            // note for one refund. One read per batch, after every Mollie call, so it also
            // sees a booking that landed while a *later* line was being posted.
            //
            // A dropped line's net is no longer this run's to count: the other actor's booking
            // either stands (and is on the books already) or was reversed meanwhile (and
            // credits nothing). The counters are then recomputed from the books.
            if ($persistedLines !== []) {
                $bookedNow = $this->creditNoteBookings($billable)['refund_ids'];
                $linesBefore = count($persistedLines);

                $persistedLines = array_values(array_filter($persistedLines, function (ProrataLine $line) use ($bookedNow, $billable): bool {
                    $refundId = (string) ($line->mollieRefundId ?? '');

                    if ($refundId === '' || ! isset($bookedNow[$refundId])) {
                        return true;
                    }

                    Log::info('Refund was booked by another actor during the batch — not booked again', [
                        'billable' => $billable->getKey(),
                        'original_invoice_id' => $line->originalInvoice?->getKey(),
                        'mollie_refund_id' => $refundId,
                    ]);

                    return false;
                }));

                if (count($persistedLines) !== $linesBefore) {
                    $refundedNetWrites = $this->refundedNetFromBooks($refundedNetWrites, $persistedLines);
                }
            }

            if (! empty($failedLines)) {
                $this->appendPendingRefundRetries($billable, $failedLines);
            }

            if (empty($persistedLines)) {
                return null;
            }

            // Persist the batch refund invoice.
            $sumNet = 0;
            $sumVat = 0;
            $sumGross = 0;
            $lineItemsForPersist = [];
            $countryFromOriginal = null;
            $currencyFromOriginal = null;
            $firstOriginal = null;

            foreach ($persistedLines as $line) {
                $sumNet += $line->amountNet;
                $sumVat += $line->amountVat;
                $sumGross += $line->amountGross;
                $lineItemsForPersist[] = $line->toArray();
                if ($line->originalInvoice !== null) {
                    $firstOriginal ??= $line->originalInvoice;
                    $countryFromOriginal ??= (string) $line->originalInvoice->country;
                    $currencyFromOriginal ??= (string) $line->originalInvoice->currency;
                }
            }

            $invoice = new BillingInvoice;
            $invoice->billable_type = $billable->getMorphClass();
            $invoice->billable_id = $billable->getKey();
            $invoice->mollie_payment_id = null;
            $invoice->mollie_subscription_id = null;
            $invoice->invoice_kind = InvoiceKind::Refund;
            $invoice->status = InvoiceStatus::Refunded;
            $invoice->country = strtoupper($countryFromOriginal ?? (string) ($billable->getBillingCountry() ?? ''));
            // Derived document: inherit the refunded invoice's currency, like country above.
            $invoice->currency = self::normalizeCurrency($currencyFromOriginal);
            $invoice->amount_net = $sumNet;
            $invoice->amount_vat = $sumVat;
            $invoice->amount_gross = $sumGross;
            $invoice->line_items = $lineItemsForPersist;
            $invoice->refund_reason_text = $description;
            $invoice->refunded_net = 0;
            // The original's validation, not today's — the same rule createCreditNote() states:
            // a credit note corrects one specific tax event, it is not a fresh decision. This
            // used to stamp `currentVatValidation()`, so a customer who bought as a consumer,
            // registered a VAT number and then downgraded got a credit note for the VAT they
            // had paid that pointed at a validation the sale never relied on.
            $invoice->vat_validation_id = $firstOriginal !== null
                ? $firstOriginal->vat_validation_id
                : $billable->currentVatValidation()?->getKey();
            $this->persistWithSerial($invoice, 'refund');

            // Logged, never retried: the money has moved and the credit note carries the
            // line, so `effectiveRefundedNet()` derives the amount from the credit note
            // even when this cache write is lost. Queueing a retry here is what turned a
            // lost write into a second payout.
            foreach ($refundedNetWrites as $originalKey => [$original, $newRefundedNet, $mollieRefundId]) {
                try {
                    $original->refunded_net = $newRefundedNet;
                    $original->save();
                } catch (\Throwable $e) {
                    Log::error('Refund booked at Mollie but refunded_net could not be updated', [
                        'billable' => $billable->getKey(),
                        'original_invoice_id' => $originalKey,
                        'mollie_refund_id' => $mollieRefundId,
                        'refunded_net' => $newRefundedNet,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            foreach ($refundLocks as $refundLock) {
                $refundLock?->release();
            }
        }

        $this->generateAndStorePdf($invoice, $billable);
        self::announceBooked(new CreditNoteIssued($billable, $invoice, null));

        return $invoice;
    }

    /**
     * Saldo-zero sidegrade: local plan-switch invoice with charge and refund lines (saldo=0).
     * NO Mollie call. Only a local accounting invoice for the audit trail.
     *
     * A derived document: it inherits the currency of the invoices its refund
     * lines were copied from, falling back to the configured currency when the
     * switch is charge-only.
     *
     * @param  list<ProrataLine>  $chargeLines
     * @param  list<ProrataLine>  $refundLines
     */
    public function createPlanSwitchInvoice(
        Billable $billable,
        array $chargeLines,
        array $refundLines,
    ): BillingInvoice {
        /** @var Model&Billable $billable */
        $allLines = array_merge($chargeLines, $refundLines);

        $sumNet = 0;
        $sumVat = 0;
        $sumGross = 0;
        $lineItemsForPersist = [];
        // The refund lines are copies of line items from the invoices they
        // reverse, so — exactly like createRefund() — this document inherits
        // their currency instead of stamping today's config on historical
        // amounts. Without it, a BILLING_CURRENCY change re-labels every
        // inherited refund amount with the new code.
        $currencyFromOriginal = null;

        foreach ($allLines as $line) {
            $sumNet += $line->amountNet;
            $sumVat += $line->amountVat;
            $sumGross += $line->amountGross;
            $lineItemsForPersist[] = $line->toArray();
        }

        // The payment this document's charge lines are settled out of, when there is exactly
        // one and it covers them in full.
        //
        // No new money moves here — the new plan's remaining days are paid for by the credit
        // booked against the invoice that collected it — so the switch used to carry no
        // payment id at all. That made its charge line unrefundable: `currentPeriodLines()`
        // only offers invoices a refund can actually be posted against, so a *third* change
        // in the same period credited the components this switch consumed at 0. Inheriting
        // the id makes the refund land where the money actually is.
        //
        // Guarded by "one source, fully covered", which is the same condition that keeps the
        // books balanced: the source invoice's refundable base has already dropped by the
        // credits below, so the two documents together can never exceed what that payment
        // collected. Anything more complicated (several source invoices, or a charge larger
        // than its credits) leaves the id null and the old, cautious behaviour stands.
        $sourceInvoices = [];
        $creditedNet = 0;

        foreach ($refundLines as $line) {
            if ($line->originalInvoice !== null) {
                $currencyFromOriginal ??= (string) $line->originalInvoice->currency;
                $sourceInvoices[(int) $line->originalInvoice->getKey()] = $line->originalInvoice;
                $creditedNet += abs((int) $line->amountNet);
            }
        }

        $chargedNet = 0;
        foreach ($chargeLines as $line) {
            $chargedNet += (int) $line->amountNet;
        }

        $settledAgainst = count($sourceInvoices) === 1 && $chargedNet <= $creditedNet
            ? reset($sourceInvoices)
            : null;

        // Settle against the invoice that actually collected the payment, not against
        // another switch document. The guard used to test `refundablePaymentId()`, which
        // resolves the chain — so a switch invoice passed it and became the target, and the
        // document written here then had no payment of its own *and* a parent with none
        // either: unrefundable, so a third change in one period was never paid out.
        $settledAgainst = self::settlementRoot($settledAgainst);

        $currency = self::normalizeCurrency($currencyFromOriginal);

        if ($chargeLines !== [] && $currency !== self::configuredCurrency()) {
            // One document, one currency: the charge lines were priced in the
            // configured currency, so flag the mix instead of mis-booking it.
            Log::warning('Plan-switch invoice mixes currencies', [
                'billable' => $billable->getKey(),
                'inherited_currency' => $currency,
                'configured_currency' => self::configuredCurrency(),
            ]);
        }

        $invoice = new BillingInvoice;
        $invoice->billable_type = $billable->getMorphClass();
        $invoice->billable_id = $billable->getKey();
        // Not `mollie_payment_id`: that column is unique, and its uniqueness is what keeps
        // the webhook idempotent. The settlement is a relationship of its own.
        $invoice->mollie_payment_id = null;
        $invoice->settled_by_invoice_id = $settledAgainst?->getKey();
        $invoice->mollie_subscription_id = $settledAgainst?->mollie_subscription_id;
        $invoice->invoice_kind = InvoiceKind::Subscription;
        $invoice->status = InvoiceStatus::Paid;
        $invoice->country = strtoupper((string) ($billable->getBillingCountry() ?? ''));
        $invoice->currency = $currency;
        $invoice->amount_net = $sumNet;
        $invoice->amount_vat = $sumVat;
        $invoice->amount_gross = $sumGross;
        $invoice->line_items = $lineItemsForPersist;
        $invoice->refunded_net = 0;
        $invoice->vat_validation_id = $billable->currentVatValidation()?->getKey();
        $this->persistWithSerial($invoice, 'subscription');

        // The refund lines are real credits against real invoices, so the originals have to
        // know about them. `derivedRefundedNet()` and `currentPeriodLines()` both filter on
        // `invoice_kind = Refund` and this document is a Subscription, so without this the
        // reversed line stayed 100% refundable *and* kept its full remaining quantity: a
        // later downgrade composed its refund against this switch invoice — which carries no
        // `mollie_payment_id` — and `createRefund()` could never pay it out, while the
        // invoice that actually holds the money still read as untouched.
        //
        // Accumulated per invoice id, not per line. The composer loads plan, seat and addon
        // candidates through three separate `currentPeriodLines()` queries, so three lines
        // crediting the same renewal invoice arrive holding three independent clones of that
        // row — each with `refunded_net = 0`. Writing them in a loop meant every save()
        // overwrote the previous increment, so only the last line was ever booked: a switch
        // crediting 1965 + 339 + 610 recorded 610, and `remainingRefundableNet()` reported
        // 3690 where 1386 was left. 2304 net of value already spent on the switch read as
        // still refundable, and a later admin refund pays that out in real money. Same
        // clobber `createRefund()` fixed with its own tracker.
        $refundedNetPerInvoice = [];

        foreach ($refundLines as $line) {
            $original = $line->originalInvoice;

            if ($original === null) {
                continue;
            }

            $id = (int) $original->getKey();
            $refundedNetPerInvoice[$id] ??= ['invoice' => $original, 'net' => (int) $original->refunded_net];
            $refundedNetPerInvoice[$id]['net'] += abs((int) $line->amountNet);
        }

        foreach ($refundedNetPerInvoice as $entry) {
            /** @var BillingInvoice $original */
            $original = $entry['invoice'];
            $original->refunded_net = min((int) $original->amount_net, (int) $entry['net']);
            $original->save();
        }

        $this->generateAndStorePdf($invoice, $billable);
        event(new InvoiceCreated($billable, $invoice));

        return $invoice;
    }
}
