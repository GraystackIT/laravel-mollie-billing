# Configuration

Dieses Dokument beschreibt den Aufbau der beiden Konfigurationsdateien des Pakets:

- `config/mollie-billing.php` — globales Verhalten, ENV-getriebene Schalter, Rechnungsstellung, VAT/OSS-Erweiterungen
- `config/mollie-billing-plans.php` — Katalog: Pläne, Intervalle, Addons, Features, Produkte und Produktgruppen

Beide Dateien werden vom Service-Provider geladen. Der Plan-Katalog wird über `SubscriptionCatalogInterface` (Default: `Support\ConfigSubscriptionCatalog`) gelesen — Apps können diese Bindung in `AppServiceProvider` durch eine eigene Implementierung (z. B. DB-getrieben) ersetzen, ohne die Config-Struktur ändern zu müssen.

> Alle Geldbeträge sind **ganzzahlige Netto-Werte in der kleinsten Währungseinheit** (Cent für EUR). `2900` entspricht also 29,00 €. Brutto/VAT wird zur Laufzeit über `VatCalculationService` ergänzt.

---

## `config/mollie-billing.php`

Globale Einstellungen. Die meisten Werte sind über ENV-Variablen überschreibbar — der Default in der Datei greift, wenn die ENV nicht gesetzt ist.

### Billable & Branding

| Schlüssel | ENV | Beschreibung |
|-----------|-----|--------------|
| `billable_model` | `BILLING_BILLABLE_MODEL` | FQCN des Models, das `Billable` implementiert (User, Team, Organization, …). Pflichtwert vor der ersten Migration. |
| `logo_url` | `BILLING_LOGO_URL` | URL des Logos im Portal/Checkout-Header. |
| `favicon_url` | `BILLING_FAVICON_URL` | URL des Favicons der Portal-Views. |
| `primary_color` | `BILLING_PRIMARY_COLOR` | Flux/Tailwind-Farbname (`teal`, `indigo`, …). Default: `teal`. |
| `company_name` | — | Default: `APP_NAME`. Wird in Mails und Views verwendet. |
| `dashboard_url` | `BILLING_DASHBOARD_URL` | Ziel des Logo-Links im Portal. Akzeptiert eine URL oder `route:<name>` (z. B. `route:dashboard`). `null` → Link führt aufs Billing-Dashboard. |
| `checkout_back_url` | `BILLING_CHECKOUT_BACK_URL` | Default-Ziel des „Zurück"-Links im Checkout, wenn kein expliziter `$backUrl` gesetzt ist. |

### Checkout-Länderauswahl

```php
'checkout_countries' => [
    'regions' => ['EU'],   // aktuell unterstützt: 'EU' (27 Mitgliedsstaaten)
    'include' => [],       // zusätzliche ISO-3166-1-alpha-2-Codes, z. B. ['CH', 'GB']
    'exclude' => [],       // Codes, die aus der aufgelösten Liste entfernt werden
],
```

Länder aus `additional_countries` (siehe unten) werden automatisch ergänzt.

### Verhalten

| Schlüssel | ENV | Beschreibung |
|-----------|-----|--------------|
| `redirect_after_return` | `BILLING_REDIRECT_AFTER_RETURN` | URL, zu der nach erfolgreichem Mollie-Return weitergeleitet wird. |
| `require_payment_method_for_zero_amount` | `BILLING_REQUIRE_PM_ZERO` | Bei `true` wird auch für 0 €-Pläne ein Mandat verlangt (für spätere kostenpflichtige Wechsel). Default: `true`. |
| `currency` | `BILLING_CURRENCY` | ISO-4217-Code. Beträge in `mollie-billing-plans.php` und Coupons werden in dieser Währung interpretiert. Default: `EUR`. |
| `currency_symbol` | `BILLING_CURRENCY_SYMBOL` | Anzeigesymbol. Default: `€`. |
| `allow_overage_default` | `BILLING_ALLOW_OVERAGE` | Default für `Billable::allowsBillingOverage()`. Pro Billable überschreibbar. |
| `plan_change_mode` | `BILLING_PLAN_CHANGE_MODE` | `Immediate` / `EndOfPeriod` / `UserChoice`. Steuert, wann Planwechsel angewendet werden. Default: `UserChoice`. |
| `mollie_locale` | `BILLING_MOLLIE_LOCALE` | Locale für Mollie-Hosted-Pages. Bei `null` lässt Mollie automatisch erkennen. |
| `billable_key_type` | `BILLING_BILLABLE_KEY_TYPE` | `uuid` / `ulid` / `int`. **Vor der ersten Migration setzen** — beeinflusst FK-Spaltentypen. Default: `uuid`. |
| `overage_job_time` | `BILLING_OVERAGE_JOB_TIME` | Tageszeit (`HH:MM`) für `PrepareOverageCommand`. Default: `02:00`. |
| `usage_threshold_percent` | `BILLING_USAGE_THRESHOLD` | Schwelle für Nutzungs-Warnungs-Events (in %). Default: `80`. |
| `usage_rollover` | `BILLING_USAGE_ROLLOVER` | Globaler Default: Carry-over ungenutzter Wallet-Credits über Periodenwechsel. Pro Plan überschreibbar. Default: `false`. |
| `admin_kpi_cache_ttl` | `BILLING_ADMIN_KPI_TTL` | TTL des KPI-Caches im Admin-Panel (Sekunden). Default: `300`. |
| `show_yearly_savings` | `BILLING_SHOW_YEARLY_SAVINGS` | Zeigt die berechnete Ersparnis (jährlich vs. monatlich) im Plan-Selector. Default: `true`. |

### Rechnungen (`invoices`)

PDF-Rechnungen werden lokal über `elegantly/laravel-invoices` erzeugt und auf einem Laravel-Filesystem-Disk abgelegt.

```php
'invoices' => [
    'disk' => env('BILLING_INVOICE_DISK', 'local'),
    'path' => 'billing/invoices',           // → {disk}/billing/invoices/{YYYY/MM}/{serial}.pdf
    'logo' => env('BILLING_INVOICE_LOGO'),
    'seller' => [ /* Firmenstammdaten */ ],
    'serial_number' => [
        'format' => 'PP-YYCCCCCC',           // P=Prefix, Y=Jahr, C=Counter (je Zeichen = ein Slot)
        'prefix' => [
            'invoice'         => 'IN',
            'credit_note'     => 'CR',
            'one_time_order'  => 'OT',
        ],
    ],
    'temporary_url_expiry' => 30,            // Minuten (relevant für S3-kompatible Disks)
],
```

Unterstützte `logo`-Formate:

- `${APP_URL}/logo.png` (auflösung über `public_path`)
- relativer Pfad: `images/logo.png`
- absoluter Pfad: `/var/www/public/logo.png`
- Data-URI: `data:image/png;base64,…`

Seriennummern werden atomar via `InvoiceNumberGenerator` vergeben.

### IP-Geolocation

```php
'ip_geolocation' => [
    'driver'  => env('BILLING_IP_DRIVER', 'ipinfo_lite'),
    'drivers' => [
        'ipinfo_lite' => ['token' => env('IPINFO_TOKEN')],
        'null'        => [],
    ],
],
```

Wird vom `CountryMatchService` verwendet (Abgleich von User-, IP- und Payment-Country für VAT-Compliance). Eigene Driver können über `MollieBilling::ipGeolocation(...)` registriert werden.

### VAT / OSS

```php
'vat_rate_overrides' => [
    // 'DE' => 19.0,
],

'additional_countries' => [
    // 'CH' => ['vat_rate' => 8.1, 'name' => 'Switzerland'],
],
```

- `vat_rate_overrides` — überschreibt die per `mpociot/vat-calculator` ermittelten Sätze pro ISO-Code.
- `additional_countries` — fügt Länder hinzu, die nicht im EU-VAT-System sind. Diese tauchen automatisch in der Checkout-Länderliste auf.

### Queue

```php
'queue' => [
    'connection' => env('BILLING_QUEUE_CONNECTION'),
    'name'       => env('BILLING_QUEUE_NAME', 'billing'),
],
```

Alle Background-Jobs des Pakets (`PrepareUsageOverageJob`, `RetryUsageOverageChargeJob`, `TrialExpiredNotification`, …) werden auf diese Connection/Queue gelegt. `connection: null` → Default-Connection der App.

---

## `config/mollie-billing-plans.php`

Definiert den Katalog: was Kunden buchen können, was inkludiert ist und was Overage kostet.

Top-Level-Sektionen:

```php
return [
    'plans'           => [ /* … */ ],
    'features'        => [ /* … */ ],
    'addons'          => [ /* … */ ],
    'product_groups'  => [ /* … */ ],
    'products'        => [ /* … */ ],
];
```

### `plans`

Jeder Plan ist mit seinem `planCode` als Schlüssel definiert.

```php
'pro' => [
    'name'           => 'Pro',                              // Fallback wenn keine Übersetzung existiert
    'description'    => null,
    'tier'           => 2,                                  // numerische Stufe (Upgrade/Downgrade-Logik)
    'trial_days'     => 14,
    'included_seats' => 3,
    'feature_keys'   => ['dashboard', 'advanced-reports'],  // Verweise auf 'features'
    'allowed_addons' => ['softdrinks'],                     // Verweise auf 'addons'
    // 'usage_rollover' => true,                            // optional, überschreibt globalen Default
    'intervals' => [
        'monthly' => [
            'base_price_net'        => 2900,                // Cent — Plan-Grundpreis
            'seat_price_net'        => 990,                 // Cent pro zusätzlichem Seat über included_seats hinaus, oder null
            'included_usages'       => ['Tokens' => 100, 'SMS' => 50],
            'usage_overage_prices'  => ['Tokens' => 10, 'SMS' => 15],   // Cent pro Einheit
        ],
        'yearly' => [
            'base_price_net'        => 29000,
            'seat_price_net'        => 9900,
            'included_usages'       => ['Tokens' => 1500, 'SMS' => 600],
            'usage_overage_prices'  => ['Tokens' => 10, 'SMS' => 15],
        ],
    ],
],
```

| Feld | Beschreibung |
|------|--------------|
| `name`, `description` | Anzeigewerte. Werden — falls vorhanden — durch Übersetzungen unter `billing::plans.<code>.{name,description}` überschrieben. |
| `tier` | Ganzzahl. Höher = teurer/größer. Steuert Upgrade/Downgrade-Erkennung in `UpdateSubscription`. |
| `trial_days` | Trial-Länge in Tagen. `0` = kein Trial. |
| `included_seats` | Anzahl Seats, die im Grundpreis enthalten sind. `SyncSeats` rechnet alles darüber als zusätzliche Seats. |
| `feature_keys` | Liste von Feature-Keys aus dem `features`-Block. Werden zusammen mit Addon-Features durch `FeatureAccess` aufgelöst. |
| `allowed_addons` | Whitelist erlaubter Addons. Andere Addons können dem Plan nicht zugeschaltet werden. |
| `usage_rollover` *(optional)* | `true` / `false`. Überschreibt `mollie-billing.usage_rollover`. |
| `intervals` | Pflicht — pro unterstütztem Intervall (`monthly`, `yearly`) ein Block. |

**Pro Intervall-Block:**

| Feld | Pflicht | Beschreibung |
|------|---------|--------------|
| `base_price_net` | ✓ | Netto-Grundpreis in Cent. |
| `seat_price_net` | ✓ | Netto-Preis pro zusätzlichem Seat in Cent, oder `null` wenn der Plan keine Seat-Erweiterung erlaubt. |
| `included_usages` | ✓ | Map `usage_type => menge`. Diese Mengen werden bei jedem Periodenwechsel auf das Wallet aufaddiert (additiv — negative Salden aus Overage bleiben erhalten). |
| `usage_overage_prices` | ✓ | Map `usage_type => preis_cent_pro_einheit`. Wird abgerechnet, sobald das Wallet negativ wird. |

> **Wichtig:** `included_usages` und `usage_overage_prices` liegen **innerhalb** der `intervals.{monthly|yearly}`-Blöcke, nicht auf Plan-Ebene. Die `SubscriptionCatalogInterface`-Lookups sind immer per `(planCode, interval)` gekeyed.

### `features`

Definiert die im System verfügbaren Features. Plans und Addons referenzieren diese über `feature_keys`.

```php
'features' => [
    'dashboard' => [
        'name'        => 'Dashboard',
        'description' => null,
    ],
    'advanced-reports' => [
        'name'        => 'Advanced Reports',
        'description' => null,
    ],
],
```

`name`/`description` werden — falls vorhanden — durch Übersetzungen unter `billing::features.<key>.{name,description}` überschrieben. Verwendet von:

- `MollieBilling::features()` (Liste der aktiven Features für den Billable)
- `@planFeature('<key>')` Blade-Direktive
- `billing.feature:<key>[,<key>]` Middleware (OR-Semantik)

### `addons`

Buchbare Add-ons, die zusätzliche Features oder Kosten aufschalten.

```php
'addons' => [
    'softdrinks' => [
        'name'         => 'Softdrinks',
        'feature_keys' => ['softdrinks'],
        'intervals' => [
            'monthly' => ['price_net' => 490],
            'yearly'  => ['price_net' => 4900],
        ],
    ],
],
```

| Feld | Beschreibung |
|------|--------------|
| `name`, `description` *(optional)* | Werden durch `billing::addons.<code>.{name,description}` überschrieben, falls Übersetzung existiert. |
| `feature_keys` | Features, die durch das Addon freigeschaltet werden. Werden mit den Plan-Features zur Gesamtmenge gemerged. |
| `intervals.{monthly,yearly}.price_net` | Netto-Preis in Cent pro Intervall. |

> Addons tragen **nicht** zu `included_usages` bei — Wallet-Quoten sind plan-scoped.

### `product_groups`

Optionale Gruppierung für die One-Time-Order-Übersicht im Portal.

```php
'product_groups' => [
    'top-ups'  => ['name' => 'Top-Ups',  'sort' => 1],
    'services' => ['name' => 'Services', 'sort' => 2],
],
```

| Feld | Beschreibung |
|------|--------------|
| `name` | Anzeigename. Wird durch `billing::product_groups.<key>` überschrieben, falls Übersetzung existiert. |
| `sort` | Sortierreihenfolge im Portal (aufsteigend). |

### `products`

Einmalkäufe (Top-Ups, Beratungsstunden, etc.). Werden über die One-Time-Order-Flows verkauft.

```php
'products' => [
    'token-pack-500' => [
        'name'        => '500 Token Pack',
        'description' => 'Top up your account with 500 tokens.',
        'image_url'   => null,
        'price_net'   => 4900,
        'usage_type'  => 'Tokens',   // optional — verlinkt zum Wallet
        'quantity'    => 500,        // optional — Einheiten, die beim Kauf gutgeschrieben werden
        'group'       => 'top-ups',  // optional — Schlüssel aus 'product_groups'
    ],
    'consulting-hour' => [
        'name'        => '1h Consulting',
        'description' => 'Book a one-hour consulting session.',
        'price_net'   => 14900,
        'onetimeonly' => true,       // optional — nur einmal pro Billable kaufbar (Default: false)
        'group'       => 'services',
    ],
],
```

| Feld | Pflicht | Beschreibung |
|------|---------|--------------|
| `name`, `description` | `name` ✓ | Übersetzbar via `billing::products.<code>.{name,description}`. |
| `image_url` | — | Optional — Produktbild. |
| `price_net` | ✓ | Netto-Preis in Cent. |
| `usage_type` | — | Wenn gesetzt zusammen mit `quantity`, schreibt der Kauf diese Menge zusätzlich aufs entsprechende Wallet. |
| `quantity` | — | Siehe `usage_type`. |
| `onetimeonly` | — | Bei `true` kann das Produkt pro Billable nur einmal gekauft werden. |
| `group` | — | Verweis auf `product_groups`. |

---

## Übersetzungen

Anzeigetexte (`name`, `description`) werden — wenn eine Übersetzung existiert — aus den Sprachdateien geladen und überschreiben die Werte aus der Config. Verwendete Translation-Keys:

| Konfig-Bereich | Translation-Key |
|----------------|-----------------|
| `plans.<code>.name` / `description` | `billing::plans.<code>.{name,description}` |
| `addons.<code>.name` / `description` | `billing::addons.<code>.{name,description}` |
| `features.<key>.name` / `description` | `billing::features.<key>.{name,description}` |
| Usage-Type-Anzeige | `billing::usages.<type>` |
| `products.<code>.name` / `description` | `billing::products.<code>.{name,description}` |
| `product_groups.<key>` | `billing::product_groups.<key>` |

Siehe [translations.md](translations.md) für Details zur Sprachdatei-Veröffentlichung.

---

## DB-getriebener Katalog

Wer die Plan-/Addon-Daten in der DB statt in der Config halten will, implementiert `SubscriptionCatalogInterface` und bindet die Klasse in `AppServiceProvider`:

```php
$this->app->bind(SubscriptionCatalogInterface::class, MyDatabaseCatalog::class);
```

`config/mollie-billing-plans.php` wird dann ignoriert. Die Methoden des Interfaces müssen dieselben Lookup-Schlüssel respektieren (`planCode`, `interval`, `usageType`, `addonCode`, `featureKey`, `productCode`, `groupKey`).
