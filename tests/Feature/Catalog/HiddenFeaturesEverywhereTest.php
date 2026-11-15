<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * A hidden feature (`features.{key}.visible = false`) unlocks everywhere and is named
 * nowhere a customer reads. HiddenFeaturesTest covers the catalog and the three screens
 * that list features; this covers the gates (model, Blade, middleware) and every OTHER
 * customer-facing surface — screens, mails, the invoice — where the rule is "no feature
 * name is printed at all", so a hidden one cannot leak either.
 */

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');

    config()->set('mollie-billing-plans.features', [
        'shown-one' => ['name' => 'Visible Dashboard Feature'],
        'internal-beta' => ['name' => 'Internal Beta Flag', 'description' => 'Secret beta description', 'visible' => false],
        'addon-secret' => ['name' => 'Secret Addon Flag', 'visible' => false],
    ]);
    config()->set('mollie-billing-plans.plans', [
        'pro' => [
            'name' => 'Pro', 'tier' => 2, 'included_seats' => 1,
            'feature_keys' => ['shown-one', 'internal-beta'],
            'allowed_addons' => ['extra'],
            'intervals' => ['monthly' => ['base_price_net' => 2900, 'seat_price_net' => 500, 'included_usages' => []]],
        ],
        'basic' => [
            'name' => 'Basic', 'tier' => 1, 'included_seats' => 1,
            'feature_keys' => ['shown-one'],
            'allowed_addons' => [],
            'intervals' => ['monthly' => ['base_price_net' => 900, 'seat_price_net' => null, 'included_usages' => []]],
        ],
    ]);
    config()->set('mollie-billing-plans.addons', [
        'extra' => ['name' => 'Extra', 'feature_keys' => ['addon-secret'], 'intervals' => ['monthly' => ['price_net' => 500]]],
    ]);
    config()->set('mollie-billing-plans.products', ['pack' => ['name' => 'Pack', 'price_net' => 1000]]);

    MollieBilling::authUsing(fn (): bool => true);
});

function hiddenEverywhereBillable(string $plan = 'pro', array $addons = ['extra']): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create(['name' => 'Hidden Co', 'email' => uniqid('hid-').'@x.test', 'billing_country' => 'AT']);
    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => $plan,
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(3),
        'active_addon_codes' => $addons,
        'subscription_meta' => ['mollie_subscription_id' => 'sub_hidden', 'seat_count' => 1],
        'mollie_customer_id' => 'cst_hidden',
        'mollie_mandate_id' => 'mdt_hidden',
        'billing_street' => 'Teststr. 1', 'billing_postal_code' => '1010', 'billing_city' => 'Wien',
    ])->save();

    $b = $b->fresh();
    MollieBilling::resolveBillableUsing(fn () => $b);

    return $b;
}

const HIDDEN_FEATURE_TEXTS = ['Internal Beta Flag', 'Secret beta description', 'Secret Addon Flag', 'internal-beta', 'addon-secret'];

// ── it unlocks ─────────────────────────────────────────────────────────────────

it('unlocks a hidden feature on the billable', function (): void {
    $with = hiddenEverywhereBillable();
    $without = hiddenEverywhereBillable('basic', []);

    expect($with->hasPlanFeature('internal-beta'))->toBeTrue()
        ->and($with->hasPlanFeature('addon-secret'))->toBeTrue()
        ->and($with->hasAllPlanFeatures(['shown-one', 'internal-beta', 'addon-secret']))->toBeTrue()
        ->and($with->hasAnyPlanFeature(['nope', 'internal-beta']))->toBeTrue()
        ->and(MollieBilling::features()->has($with, 'internal-beta'))->toBeTrue()
        ->and($without->hasPlanFeature('internal-beta'))->toBeFalse()
        ->and($without->hasPlanFeature('addon-secret'))->toBeFalse();
});

it('unlocks a hidden feature in @planFeature', function (string $plan, array $addons, string $expected): void {
    hiddenEverywhereBillable($plan, $addons);

    $html = Blade::render('@planFeature(\'internal-beta\')PLAN-ON @else PLAN-OFF @endplanFeature @planFeature(\'addon-secret\')ADDON-ON @else ADDON-OFF @endplanFeature');

    expect(preg_replace('/\s+/', ' ', trim($html)))->toBe($expected);
})->with([
    'plan and addon carry it' => ['pro', ['extra'], 'PLAN-ON ADDON-ON'],
    'only the plan carries it' => ['pro', [], 'PLAN-ON ADDON-OFF'],
    'neither' => ['basic', [], 'PLAN-OFF ADDON-OFF'],
]);

it('unlocks a hidden feature in the billing.feature middleware', function (): void {
    Route::middleware('billing.feature:internal-beta')->get('/_hidden/plan', fn () => 'plan-ok');
    Route::middleware('billing.feature:addon-secret')->get('/_hidden/addon', fn () => 'addon-ok');
    Route::middleware('billing.feature:nope,addon-secret')->get('/_hidden/any', fn () => 'any-ok');

    hiddenEverywhereBillable('pro', ['extra']);
    $this->get('/_hidden/plan')->assertOk()->assertSee('plan-ok');
    $this->get('/_hidden/addon')->assertOk()->assertSee('addon-ok');
    $this->get('/_hidden/any')->assertOk()->assertSee('any-ok');

    hiddenEverywhereBillable('basic', []);
    $this->get('/_hidden/plan')->assertRedirect();
    $this->get('/_hidden/addon')->assertRedirect();
    $this->getJson('/_hidden/any')->assertStatus(403);
});

it('does not name the missing feature when it refuses', function (): void {
    Route::middleware('billing.feature:internal-beta')->get('/_hidden/plan', fn () => 'plan-ok');
    hiddenEverywhereBillable('basic', []);

    $json = $this->getJson('/_hidden/plan')->assertStatus(403)->getContent();
    $redirect = $this->get('/_hidden/plan');

    foreach (HIDDEN_FEATURE_TEXTS as $text) {
        expect($json)->not->toContain($text)
            ->and((string) session('billing_status'))->not->toContain($text);
    }

    $redirect->assertRedirect();
});

// ── it is named nowhere ────────────────────────────────────────────────────────

it('keeps a hidden feature off every portal screen', function (string $component): void {
    $billable = hiddenEverywhereBillable();

    BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(), 'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_hidden', 'serial_number' => 'IN-1', 'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid, 'country' => 'AT', 'currency' => 'EUR',
        'amount_net' => 3400, 'amount_vat' => 680, 'amount_gross' => 4080,
        'line_items' => [
            ['kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1, 'amount_net' => 2900, 'vat_rate' => 20.0, 'vat_amount' => 580, 'amount_gross' => 3480],
            ['kind' => 'addon', 'code' => 'extra', 'label' => 'Extra', 'quantity' => 1, 'amount_net' => 500, 'vat_rate' => 20.0, 'vat_amount' => 100, 'amount_gross' => 600],
        ],
    ]);

    $page = Livewire::test($component);

    foreach (HIDDEN_FEATURE_TEXTS as $text) {
        $page->assertDontSee($text);
    }
})->with([
    'dashboard' => ['mollie-billing::dashboard'],
    'plan change' => ['mollie-billing::plan-change'],
    'addons' => ['mollie-billing::addons'],
    'seats' => ['mollie-billing::seats'],
    'products' => ['mollie-billing::products'],
    'invoices' => ['mollie-billing::invoices'],
    'billing data' => ['mollie-billing::billing-data'],
    'return' => ['mollie-billing::return'],
]);

it('keeps a hidden feature off every step of the checkout', function (): void {
    MollieBilling::resolveBillableUsing(fn () => null);

    $checkout = Livewire::test('mollie-billing::checkout')
        ->set('plan_code', 'pro')
        ->set('addon_codes', ['extra']);

    foreach ([1, 2, 3, 4] as $step) {
        $checkout->set('step', $step);

        foreach (HIDDEN_FEATURE_TEXTS as $text) {
            $checkout->assertDontSee($text);
        }
    }

    // The visible one is what the plan cards are fed, so the assertion above is not vacuous.
    expect(array_column($checkout->instance()->planFeatures('pro'), 'name'))->toBe(['Visible Dashboard Feature']);
});

it('prints no feature name in any mail, on the invoice, or on any other customer view', function (): void {
    // Mails and the invoice PDF cannot leak a hidden feature because they print no
    // feature at all. Pinned structurally: the catalog's feature lookups may only be
    // called by the three screens that list features — and those go through the
    // `visible…` variants (HiddenFeaturesTest).
    $root = dirname(__DIR__, 3);
    $allowed = [
        'resources/views/livewire/billing/checkout.blade.php',
        'resources/views/livewire/billing/plan-change.blade.php',
        'resources/views/livewire/billing/addons.blade.php',
        // Renders what the checkout component's own planFeatures() hands it.
        'resources/views/livewire/billing/components/checkout/step-plan.blade.php',
    ];

    $offenders = [];
    $unfiltered = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/resources/views', FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $relative = ltrim(str_replace($root, '', $file->getPathname()), '/');

        // The admin panel is for operators, who do see every feature.
        if (str_contains($relative, '/admin/')) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (preg_match('/featureName\(|featureDescription\(|allFeatures\(|[^e]PlanFeatures\(|[^e]AddonFeatures\(|feature_keys/', $source) && ! in_array($relative, $allowed, true)) {
            $offenders[] = $relative;
        }

        // Never the unfiltered lists on a customer view.
        if (preg_match('/catalog->planFeatures\(|catalog->addonFeatures\(|->allFeatures\(/i', $source)) {
            $unfiltered[] = $relative;
        }
    }

    foreach (['src/Notifications', 'src/Services/Billing/InvoiceService.php', 'src/Http/Controllers'] as $path) {
        $targets = is_dir($root.'/'.$path)
            ? iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$path, FilesystemIterator::SKIP_DOTS)))
            : [new SplFileInfo($root.'/'.$path)];

        foreach ($targets as $file) {
            if (preg_match('/featureName\(|featureDescription\(|planFeatures\(|addonFeatures\(|allFeatures\(/i', (string) file_get_contents($file->getPathname()))) {
                $offenders[] = ltrim(str_replace($root, '', $file->getPathname()), '/');
            }
        }
    }

    expect($offenders)->toBe([])->and($unfiltered)->toBe([]);
});
