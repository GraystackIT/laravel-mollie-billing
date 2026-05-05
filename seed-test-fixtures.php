<?php

/*
|--------------------------------------------------------------------------
| Seed-Snippet: Past Due + Country Mismatches
|--------------------------------------------------------------------------
|
| Ausführen in der konsumierenden App per Tinker:
|
|     php artisan tinker
|     >>> require '/pfad/zu/seed-test-fixtures.php';
|
| Oder als Einmal-Skript:
|
|     php artisan tinker --execute="require '/pfad/zu/seed-test-fixtures.php';"
|
| Voraussetzung: config('mollie-billing.billable_model') ist gesetzt und es
| existieren mindestens 4 Billables in der DB. Andernfalls scrollt das Skript
| über die vorhandenen und nimmt, was da ist.
*/

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;

$billableClass = config('mollie-billing.billable_model');

if (! $billableClass) {
    throw new RuntimeException("config('mollie-billing.billable_model') ist nicht gesetzt.");
}

$billables = $billableClass::query()->orderBy('id')->limit(4)->get();

if ($billables->count() < 2) {
    throw new RuntimeException(
        "Mindestens 2 Billables nötig, gefunden: {$billables->count()}. "
        ."Bitte zuerst zwei Test-Billables anlegen."
    );
}

// ---------------------------------------------------------------------------
// 1) Zwei Past-Due-Billables
// ---------------------------------------------------------------------------

$pastDueSpecs = [
    [
        'plan' => 'starter',
        'interval' => SubscriptionInterval::Monthly,
        'reason' => 'insufficient_funds',
    ],
    [
        'plan' => 'pro',
        'interval' => SubscriptionInterval::Yearly,
        'reason' => 'card_expired',
    ],
];

foreach ($billables->take(2)->values() as $i => $b) {
    $spec = $pastDueSpecs[$i];

    $b->forceFill([
        'subscription_status' => SubscriptionStatus::PastDue,
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_plan_code' => $spec['plan'],
        'subscription_interval' => $spec['interval'],
        'subscription_period_starts_at' => now()->subDays(35),
        'subscription_meta' => array_merge(
            $b->subscription_meta ?? [],
            [
                'payment_failure' => [
                    'reason' => $spec['reason'],
                    'failed_at' => now()->subDays(2)->toIso8601String(),
                ],
            ],
        ),
    ])->save();

    echo "Past Due gesetzt: #{$b->getKey()} {$b->name} ({$spec['plan']}/{$spec['interval']->value}, {$spec['reason']})\n";
}

// ---------------------------------------------------------------------------
// 2) Zwei Country Mismatches (pending)
// ---------------------------------------------------------------------------

// Für die Mismatches verwenden wir möglichst die nächsten zwei Billables,
// damit Past Due und Mismatches sich nicht überlappen. Falls nur 2 da sind,
// fallen wir auf dieselben zurück.
$mismatchTargets = $billables->slice(2, 2);
if ($mismatchTargets->count() < 2) {
    $mismatchTargets = $billables->take(2);
}

$mismatchSpecs = [
    ['user' => 'DE', 'payment' => 'AT'],
    ['user' => 'NL', 'payment' => 'FR'],
];

foreach ($mismatchTargets->values() as $i => $b) {
    $spec = $mismatchSpecs[$i];

    $b->forceFill([
        'tax_country_user' => $spec['user'],
        'tax_country_payment' => $spec['payment'],
        'pm_country' => $spec['payment'],
        'country_mismatch_flagged_at' => now()->subHours(6),
    ])->save();

    $mismatch = BillingCountryMismatch::create([
        'billable_type' => $b->getMorphClass(),
        'billable_id' => $b->getKey(),
        'tax_country_user' => $spec['user'],
        'tax_country_payment' => $spec['payment'],
        'status' => CountryMismatchStatus::Pending,
    ]);

    echo "Mismatch #{$mismatch->id} angelegt: #{$b->getKey()} {$b->name} ({$spec['user']} ↔ {$spec['payment']})\n";
}

echo "\nFertig.\n";
