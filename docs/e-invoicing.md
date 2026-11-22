# E-invoicing (EN 16931, ZUGFeRD, XRechnung)

Every invoice and credit note PDF the package stores is a **hybrid e-invoice**: the readable
PDF, converted to PDF/A-3, with the structured invoice attached as `factur-x.xml`
(UN/CEFACT CII, EN 16931). `EInvoiceService` builds it from the booked `BillingInvoice`;
`InvoiceService::generateAndStorePdf()` embeds it right after DOMPDF has rendered the page.
The library is [`horstoeko/invoicesuite`](https://github.com/horstoeko/invoicesuite), the
successor of `horstoeko/zugferd`.

## Why

| | Requirement | What covers it |
|---|---|---|
| **Germany** | § 14 (1) UStG since 2025: an *e-invoice* is a structured format that conforms to EN 16931. Every business has to be able to receive one since 1 January 2025; issuing is mandatory for B2B from 2027 (turnover above € 800,000) and from 2028 for everyone. ZUGFeRD from 2.0.1 qualifies in every profile except MINIMUM and BASIC-WL (BMF letters of 15 Oct 2024 and 2025). A PDF alone is an "other invoice". | Profile `en16931` (ZUGFeRD 2.x / Factur-X "EN 16931", the default) |
| **Germany, public buyers** | XRechnung (E-Rechnungsverordnung, mandatory since 2020), routed by Leitweg-ID. The federal platforms (ZRE, OZG-RE) take the XML on its own, not a PDF. | Profile `xrechnung` + `getBillingBuyerReference()`; the standalone XML from `EInvoiceService::xml()` (see *Standalone XML*) |
| **Austria, B2B** | No e-invoicing mandate. An electronic invoice needs the recipient's consent (§ 11 (2) UStG 1994); the hybrid is a normal PDF invoice to the reader. | Default profile |
| **Austria, federal government** | ebInterface or PEPPOL BIS through e-rechnung.gv.at (mandatory since 2014) | **Not covered**, see *Limits* |
| **EU** | Directive 2014/55/EU, EN 16931; ViDA (cross-border B2B digital reporting from July 2030) | EN 16931 core |

## Configuration

```php
'invoices' => [
    'seller' => [
        'company' => …, 'name' => …, 'email' => …, 'phone' => …,
        'tax_number' => env('BILLING_SELLER_TAX_NUMBER'), // Steuernummer (BT-32)
        'vat_id' => env('BILLING_SELLER_VAT_ID'),         // USt-IdNr. / UID (BT-31)
        'address' => [ … 'country' => 'DE' ],
    ],
    'e_invoice' => [
        'enabled' => env('BILLING_E_INVOICE_ENABLED', true),
        'profile' => env('BILLING_E_INVOICE_PROFILE', 'en16931'),   // or 'xrechnung'
        'exemption_reason' => env('BILLING_E_INVOICE_EXEMPTION_REASON'),
        'reverse_charge_text' => env('BILLING_REVERSE_CHARGE_TEXT'),
        'font' => env('BILLING_E_INVOICE_FONT', 'DejaVu Sans'),
    ],
],
```

- `seller.vat_id` is new. `seller.tax_number` was documented as "tax number" and is set to a VAT
  ID in many installations, so a value shaped like one (`ATU12345678`) is read as the VAT ID
  while `vat_id` is empty. Set both when you have both.
- `exemption_reason` is the text stated for a 0 % invoice that is neither reverse charge nor a
  supply outside the EU, e.g. a small business ("Gemäß § 19 UStG wird keine Umsatzsteuer
  berechnet." / "Umsatzsteuerfrei aufgrund der Kleinunternehmerregelung gem. § 6 Abs. 1 Z 27
  UStG").
- `reverse_charge_text` overrides the reverse-charge statement (PDF and XML exemption
  reason) in every locale. Unset, the statement is the phrase alone — the VAT IDs of both
  parties stand, labelled, with the parties — in the wording of the **seller's** country
  (Art. 219a (2)(a) VAT Directive: a cross-border reverse-charge invoice follows the
  seller's rules):
  - Austria: "Übergang der Steuerschuld auf den Leistungsempfänger" (§ 11 Abs. 1a UStG
    1994), VAT IDs labelled "UID-Nr."
  - Germany and everyone else: "Steuerschuldnerschaft des Leistungsempfängers" (§ 14a
    Abs. 5 UStG, Art. 226 Nr. 11a VAT Directive), "USt-IdNr."
  - English invoices: "Reverse charge"

  The country texts are translation keys `billing::portal.e_invoice.{key}_{country}`
  (`reverse_charge_reason_at`, `seller_vat_id_at`); publish the translations to add one. Without a configured seller country the wording falls back to the
  prefix of the seller's own VAT ID (`ATU…` → Austrian wording); the tax decision never does.
- The readable PDF prints the same values the XML states: the service period per line (BG-26,
  inclusive end date), the seller's VAT ID and tax number with labels, and the buyer's VAT ID
  from the invoice's own VIES validation.
- `font`: PDF/A-3 requires every font to be embedded, and DOMPDF never embeds its default
  Helvetica. While e-invoicing is on the PDF is rendered in DejaVu Sans (ships with DOMPDF,
  embedded as a subset), unless `invoices.pdf.template_data.font` is set.

`php artisan billing:check-config` warns about missing seller data: no VAT ID (reverse
charge needs one, BR-AE-02), a `vat_id` that is not shaped like one, an unknown profile, and
for `xrechnung` a missing seller contact (BR-DE-2, BR-DE-5 to 7).

### Buyer reference (Leitweg-ID)

BT-10 is optional in EN 16931 and mandatory in XRechnung. A billable model opts in:

```php
public function getBillingBuyerReference(): ?string
{
    return $this->leitweg_id ?? $this->purchase_order_number;
}
```

Without the method, the `xrechnung` profile states the billable's key.

## What the XML says

| EN 16931 | Source |
|---|---|
| BT-1 number, BT-2 date | `serial_number`, `created_at` as a UTC date — the date the readable PDF prints, so both parts agree |
| BT-3 type | `380` invoice, `381` credit note (`InvoiceKind::Refund`) |
| BT-5 currency | the invoice's own `currency`; amounts with that currency's decimals |
| BT-10 buyer reference | `getBillingBuyerReference()` (see above) |
| BT-22 notes | `refund_reason_text`; the reverse-charge statement |
| BT-25 preceding invoice | for a credit note: every invoice its lines name in `parent_invoice_id` |
| BT-27…BT-43 seller | `invoices.seller` |
| BT-44…BT-49 buyer | the billable's name, address and email; VAT ID from **the invoice's own** VIES validation |
| BG-14 / BG-26 period | subscriptions and usage: invoice and line `period_start`/`period_end` as UTC billing days (as everywhere in the package, see docs/timezone.md), the end being the day before the next period starts; a credit note without a header period takes the span of its lines; the PDF prints "Leistungszeitraum" per line |
| BT-72 delivery date | one-time orders (products) and credit notes for them: the invoice date, instead of a period — the PDF prints "Leistungsdatum". The stored period of a one-time order is the billable's subscription period and is not stated. |
| BG-16 payment means | `68` (online payment service) with the Mollie method and summary |
| BG-25 lines | `line_items`; a negative line (discount, credit) has a negative quantity and a positive price (BR-27); a line whose net is not quantity × unit price is stated as one unit carrying its net, as on the PDF |
| BG-23 VAT breakdown | per category and rate, VAT = sum of the booked line VAT |
| BT-113 / BT-115 | a booked document is settled: prepaid = grand total, due = 0. `Open`/`Failed` invoices are due on their issue date (BR-CO-25). |

VAT categories:

| Situation | Category | Exemption reason |
|---|---|---|
| line with a rate above 0 | `S` | — |
| 0 %, the invoice relied on a valid VIES validation, customer in another EU country than the seller | `AE` reverse charge | `VATEX-EU-AE` + the statement in the seller country's wording (see `reverse_charge_text`), also printed on the PDF |
| 0 %, customer outside the EU | `O` not subject to VAT | `VATEX-EU-O`; no VAT IDs (BR-O-02), the seller is identified by BT-29 |
| any other 0 %, and a 0 % line on a document that also has taxed lines (O excludes every other category) | `E` exempt | `invoices.e_invoice.exemption_reason` |

The document is built from what the invoice **stored**, not from the billable's current state:
a document regenerated a year later carries the figures that were booked. The buyer's name and
address are the exception, because the invoice does not snapshot them; the readable PDF reads
them from the billable as well.

## Standalone XML

The hybrid PDF suits recipients that accept ZUGFeRD. A portal that wants the structured
invoice on its own — the German federal platforms (ZRE, OZG-RE) for XRechnung, a customer's
ERP inbox — gets the same XML without the PDF:

```php
$xml = app(\GraystackIT\MollieBilling\Services\Billing\EInvoiceService::class)
    ->xml($invoice, $invoice->billable, 'xrechnung');   // or 'en16931'
```

It is built from the stored invoice, so it matches the XML embedded in the PDF of the same
profile. Delivering it to a portal (upload, PEPPOL, e-mail) is up to the app; other public
buyers (Länder, municipalities) set their own channels and may accept the hybrid.

## Failure

If the XML cannot be built or embedded, the error is logged (`E-invoice (ZUGFeRD) could not
be embedded…`) and the plain PDF is stored, so the customer still gets an invoice. A gap in
the master data is deterministic, so fix the cause and regenerate (`InvoiceService::regeneratePdf()`,
the admin's regenerate button, or delete the file and let the download regenerate it). The
library records what a profile cannot carry in a message bag rather than throwing;
`EInvoiceService` treats any such error as a failure, so a field is never silently dropped.

## Validation

`EInvoiceService::schemaErrors()` checks the XML against the CII schema; the feature tests
call it for every shape (`tests/Feature/Invoice/EInvoiceTest.php`).

The business rules (EN 16931 and XRechnung Schematron) need the KoSIT validator (Java):

```bash
mkdir kosit && cd kosit
curl -LO https://github.com/itplr-kosit/validator/releases/download/v1.5.0/validator-1.5.0-distribution.zip
curl -LO https://github.com/itplr-kosit/validator-configuration-xrechnung/releases/download/release-2025-03-21/validator-configuration-xrechnung_3.0.2_2025-03-21.zip
unzip -q validator-1.5.0-distribution.zip -d validator
unzip -q validator-configuration-xrechnung_3.0.2_2025-03-21.zip -d config
cd ..
BILLING_KOSIT_DIR=$PWD/kosit composer test:e-invoice
```

`tests/Feature/Invoice/EInvoiceKositTest.php` runs every shape the package issues (domestic,
OSS to another EU country, reverse charge, outside the EU, exempt, discount line, mixed
rates, zero-sum plan switch, open invoice, JPY, credit note) through both profiles. It is
skipped without `BILLING_KOSIT_DIR`.

## Limits

- **XRechnung needs the buyer's city and postcode** (BR-DE-8, BR-DE-9). The checkout collects
  them; a billable without them produces an XRechnung the portal rejects. EN 16931 itself
  only requires the country.
- **Austrian federal government (e-rechnung.gv.at)** accepts ebInterface and PEPPOL BIS, not
  ZUGFeRD. invoicesuite can build PEPPOL BIS 3.0 UBL (`peppol30invoice`); wiring it up and
  delivering through the USP is not part of the package.
- **PDF/A-3 conformance** is what the library produces from the DOMPDF output (embedded fonts,
  output intent, XMP metadata). It has not been checked with veraPDF. Receiving systems read
  the XML; the BMF treats the XML as the invoice when the two parts differ.
- **Corrections.** A credit note is type 381. A *Rechnungsberichtigung* of an e-invoice has to
  be an e-invoice with type 384 according to the BMF; the package corrects by credit note plus
  new invoice (country correction), which is not a 384 document.
- **The library is pre-1.0** (`^0.0.44`). Its API may change between patch releases; the
  version is pinned on purpose.
