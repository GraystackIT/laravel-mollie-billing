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
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Events\CreditNoteIssued;
use GraystackIT\MollieBilling\Events\InvoiceCreated;
use GraystackIT\MollieBilling\Events\InvoicePdfRegenerated;
use GraystackIT\MollieBilling\Exceptions\LineItemTotalsMismatchException;
use GraystackIT\MollieBilling\Exceptions\RefundExceedsInvoiceAmountException;
use GraystackIT\MollieBilling\Models\BillingInvoice;
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
use Mollie\Laravel\Facades\Mollie;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InvoiceService
{
    use MutatesBillingMeta;

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
    public function createForPayment(object $payment, string $invoiceKind, array $lineItems, Billable $billable): BillingInvoice
    {
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
        $linePeriodStart = $billable->getBillingPeriodStartsAt() ?? BillingTime::nowUtc();
        $linePeriodEnd = $billable->nextBillingDate() ?? BillingTime::nowUtc()->addMonth();

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
    ): BillingInvoice {
        if ($amountNet <= 0) {
            throw new \InvalidArgumentException('Credit-note net amount must be positive.');
        }

        // Upper bound, not just a lower one: a caller that derives the net from a
        // gross amount can land above the original (e.g. a per-line-rounded
        // header divided by a single rate), and the surplus would be allocated
        // onto credit lines that exceed the original lines they reverse — with
        // refunded_net summing past amount_net so the invoice never reads as
        // fully refunded. Remaining-balance checks stay with the refund
        // services; this is the invariant of the document itself.
        if ($amountNet > (int) $original->amount_net) {
            throw new RefundExceedsInvoiceAmountException(
                "Credit note of {$amountNet} exceeds invoice {$original->id} net amount {$original->amount_net}."
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

        /** @var Billable $billable */
        $billable = $original->billable()->first();

        if ($billable !== null) {
            $this->generateAndStorePdf($creditNote, $billable);
            event(new CreditNoteIssued($billable, $creditNote, $original));
        }

        return $creditNote;
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
        $credited = $original->creditedPerOriginalLine();

        $parts = [];
        foreach ((array) ($original->line_items ?? []) as $index => $line) {
            if (! is_array($line)) {
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

        // Proportional allocation. The residual left by per-part rounding goes to
        // the largest part so the allocated nets add up to $amountNet exactly.
        $allocated = [];
        $sum = 0;
        foreach ($folded as $part) {
            $net = $amountNet === $totalNet
                ? $part['net']
                : (int) round($amountNet * $part['net'] / $totalNet);
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
        return max(0, min($vat, abs((int) $original->amount_vat) - $original->creditedVatTotal()));
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
            $pdfContent = $this->withLocale($locale, fn (): ?string => $this->buildPdfInvoice($invoice, $billable)->getPdfOutput());

            if ($pdfContent === null) {
                Log::warning('PDF invoice generation returned null', ['invoice_id' => $invoice->id]);

                return false;
            }

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
            tax_number: $sellerConfig['tax_number'] ?? null,
            email: $sellerConfig['email'] ?? null,
            phone: $sellerConfig['phone'] ?? null,
        );

        $buyer = new Buyer(
            name: $billable->getBillingName(),
            address: new Address(
                street: $billable->getBillingStreet(),
                city: $billable->getBillingCity(),
                postal_code: $billable->getBillingPostalCode(),
                country: CountryResolver::name($billable->getBillingCountry()),
            ),
            tax_number: $billable->vat_number ?? null,
            email: $billable->getBillingEmail(),
        );

        $isCredit = $invoice->invoice_kind === InvoiceKind::Refund;

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

            // Per-item billing_period or description only — no fallback to global period.
            $description = $item['description'] ?? $item['billing_period'] ?? null;

            // Per-item VAT: line_item.vat_rate is the source of truth.
            $taxPercentage = (float) ($item['vat_rate'] ?? 0);

            $items[] = new PdfInvoiceItem(
                label: (string) ($item['label'] ?? $item['kind'] ?? 'Line'),
                unit_price: Money::ofMinor($netUnit, $currency),
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
        $description = ! empty($invoice->refund_reason_text)
            ? (string) $invoice->refund_reason_text
            : null;

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
        $methodLabel = ucfirst(str_replace('_', ' ', $method));
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

        $payment = Mollie::send(new CreatePaymentRequest(
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

        $meta = $billable->getBillingSubscriptionMeta();
        $pending = (array) ($meta['country_corrections'] ?? []);
        $paymentId = is_object($payment) ? (string) ($payment->id ?? '') : '';
        if ($paymentId !== '') {
            $pending[$paymentId] = [
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
            $this->mutateBillingMeta($billable, fn (array $meta): array => [
                ...$meta,
                'country_corrections' => $pending,
            ]);
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
                'parent_invoice_id' => (int) $original->id,
                'description' => $line['description'] ?? null,
            ];
        }

        return $cloned;
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

        $this->generateAndStorePdf($invoice, $billable);
        event(new InvoiceCreated($billable, $invoice));

        return $invoice;
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

        // Mollie metadata is hard-capped at 1024 bytes — keep it to a minimal correlation
        // payload. The full charge/refund line list lives in subscription_meta.pending_prorata_change
        // and is read back by the Phase-2 webhook.
        $payment = Mollie::send(new CreatePaymentRequest(
            description: "Plan change for {$intent->newPlan}",
            amount: new MollieMoney($currency, self::molliePrice($grossTotal, $currency)),
            metadata: [
                'type' => 'prorata_charge',
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
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
        $this->mutateBillingMeta($billable, fn (array $meta): array => [
            ...$meta,
            'pending_prorata_change' => [
                'charge_payment_id' => is_object($payment) ? ($payment->id ?? null) : null,
                'charge_lines' => array_map(fn (ProrataLine $l) => $l->toArray(), $chargeLines),
                'refund_lines' => array_map(fn (ProrataLine $l) => $l->toArray(), $pendingRefundLines),
                'intent' => $intent->toArray(),
                'created_at' => BillingTime::nowUtc()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Internally makes N Mollie refund calls (against originalInvoice.mollie_payment_id per line)
     * and persists the result as a BillingInvoice with invoice_kind=Refund.
     *
     * Each call is posted in the currency of the invoice it refunds — Mollie
     * rejects any other — and the credit note inherits that currency.
     *
     * Mollie 409 idempotency per line: if a refund line_item already exists with
     * (parent_invoice_id, parent_line_item_index, amount_net) → skipped.
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

        foreach ($effective as $line) {
            $original = $line->originalInvoice;
            if ($original === null || $original->mollie_payment_id === null) {
                $failedLines[] = ['line' => $line->toArray(), 'error' => 'Missing original invoice or payment_id'];
                continue;
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
            $alreadyRefundedNet = $refundedNetPerInvoice[$originalKey] ?? $original->effectiveRefundedNet();
            $restRefundableNet = max(0, (int) $original->amount_net - $alreadyRefundedNet);
            $restRefundableGross = $restRefundableNet + ($restRefundableNet > 0 ? $this->creditNoteVat($original, $restRefundableNet) : 0);

            $restRefundable = min((int) $original->amount_gross, $restRefundableGross);
            $refundAmount = min($absGross, $restRefundable);

            if ($refundAmount < $absGross) {
                Log::warning('Refund amount clamped below local gross', [
                    'billable' => $billable->getKey(),
                    'original_invoice_id' => $original->getKey(),
                    'mollie_payment_id' => $original->mollie_payment_id,
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

            $idempotencyKey = sprintf(
                'refund:%s:%s:%s:%d:%d:%s',
                $billable->getMorphClass(),
                (string) $billable->getKey(),
                (string) $original->getKey(),
                (int) ($line->originalLineItemIndex ?? 0),
                (int) $refundAmount,
                $line->refundToken,
            );

            // Mollie's SDK auto-resets the key on response (ResetIdempotencyKey
            // middleware), so we set it once per refund call and don't need
            // to clean up explicitly.
            Mollie::setIdempotencyKey($idempotencyKey);

            // The try covers the Mollie call and nothing else. It used to wrap the
            // bookkeeping below as well, so a failing `save()` — a lock-wait timeout
            // against a concurrent admin refund is enough — filed the line in
            // `$persistedLines` *and* in `$failedLines`: the credit note was written and a
            // retry marker queued for a refund Mollie had already paid out. The retry then
            // read `refunded_net` (whose write had just failed) as the remaining headroom
            // and paid the customer twice.
            try {
                $refund = Mollie::send(new CreatePaymentRefundRequest(
                    paymentId: $original->mollie_payment_id,
                    description: $description ?? "Plan change refund: {$line->label}",
                    amount: new MollieMoney($lineCurrency, self::molliePrice($refundAmount, $lineCurrency)),
                ));
            } catch (\Throwable $e) {
                $failedLines[] = ['line' => $line->toArray(), 'error' => $e->getMessage(), 'first_attempt_at' => BillingTime::nowUtc()->toIso8601String()];

                continue;
            }

            $line->mollieRefundId = is_object($refund) ? ($refund->id ?? null) : null;
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

            // Logged, never retried: the money has moved and the credit note carries the
            // line, so `effectiveRefundedNet()` derives the amount from the credit note
            // even when this cache write is lost. Queueing a retry here is what turned a
            // lost write into a second payout.
            try {
                $original->refunded_net = $newRefundedNet;
                $original->save();
            } catch (\Throwable $e) {
                Log::error('Refund booked at Mollie but refunded_net could not be updated', [
                    'billable' => $billable->getKey(),
                    'original_invoice_id' => $originalKey,
                    'mollie_refund_id' => $line->mollieRefundId,
                    'refunded_net' => $newRefundedNet,
                    'error' => $e->getMessage(),
                ]);
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

        foreach ($persistedLines as $line) {
            $sumNet += $line->amountNet;
            $sumVat += $line->amountVat;
            $sumGross += $line->amountGross;
            $lineItemsForPersist[] = $line->toArray();
            if ($line->originalInvoice !== null) {
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
        $invoice->vat_validation_id = $billable->currentVatValidation()?->getKey();
        $this->persistWithSerial($invoice, 'refund');

        $this->generateAndStorePdf($invoice, $billable);
        event(new CreditNoteIssued($billable, $invoice, null));

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

        foreach ($refundLines as $line) {
            if ($line->originalInvoice !== null) {
                $currencyFromOriginal ??= (string) $line->originalInvoice->currency;
            }
        }

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
        $invoice->mollie_payment_id = null;
        $invoice->mollie_subscription_id = null;
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
        foreach ($refundLines as $line) {
            $original = $line->originalInvoice;

            if ($original === null) {
                continue;
            }

            $original->refunded_net = min(
                (int) $original->amount_net,
                (int) $original->refunded_net + abs((int) $line->amountNet),
            );
            $original->save();
        }

        $this->generateAndStorePdf($invoice, $billable);
        event(new InvoiceCreated($billable, $invoice));

        return $invoice;
    }
}
