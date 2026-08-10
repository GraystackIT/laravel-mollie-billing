<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Testing\TestBillable;

/**
 * `MollieBilling::resolveBillable()` deliberately falls back, on the package's own
 * routes, to matching *any* query parameter against a billable's route key — that
 * is how a multi-tenant checkout mounted outside the tenant group finds its
 * organisation. The consequence is that `?org=<someone-else>` resolves a stranger,
 * so every consumer has to authorize before showing anything.
 *
 * The checkout's `mount()` did not: it copied the resolved billable's company name,
 * street, postal code, city, country and VAT number onto the component and rendered
 * them read-only. The write path was gated all along, and the portal components grew
 * a `readableBillable()` gate — this read was the one that was missed.
 *
 * Flux Pro is absent from this test environment, so these tests exercise the
 * resolution and authorization decision rather than the rendered HTML. What matters
 * is the decision: with authorization refused, no foreign field may be adopted.
 */
beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 2900, 'seat_price_net' => null, 'included_usages' => []]],
    ]);
});

afterEach(function (): void {
    MollieBilling::flushCallbacks();
});

function leakVictim(): TestBillable
{
    /** @var TestBillable $victim */
    $victim = TestBillable::create([
        'name' => 'Victim GmbH',
        'email' => 'victim@x.test',
        'billing_country' => 'AT',
    ]);

    $victim->forceFill([
        'billing_street' => 'Geheimgasse 7',
        'billing_postal_code' => '1010',
        'billing_city' => 'Wien',
        'vat_number' => 'ATU69934418',
        // No accessible subscription — exactly the state this branch was built for
        // and the state that makes the victim reachable.
        'subscription_status' => SubscriptionStatus::Expired,
    ])->save();

    return $victim->refresh();
}

it('does not resolve a foreign billable into the checkout when authorization refuses', function (): void {
    $victim = leakVictim();

    MollieBilling::authUsing(fn () => false);
    MollieBilling::resolveBillableUsing(fn () => null);

    // What the exploit was: a query parameter carrying the victim's route key on a
    // package route. The resolver still finds it — that is its documented job —
    // but the checkout must not adopt it.
    $request = Illuminate\Http\Request::create('/billing/checkout?org='.$victim->getRouteKey(), 'GET');
    $resolved = MollieBilling::resolveBillable($request);

    expect($resolved)->not->toBeNull()
        ->and($resolved->getKey())->toBe($victim->getKey())
        // …and this is the gate mount() now applies before touching a single field.
        ->and(MollieBilling::authorizes($request, $resolved))->toBeFalse();
});

it('adopts the billable when authorization allows it', function (): void {
    $owned = leakVictim();

    // The legitimate returning-customer path: the app's own callback says yes.
    MollieBilling::authUsing(fn ($request, $billable) => $billable->getKey() === $owned->getKey());

    $request = Illuminate\Http\Request::create('/billing/checkout?org='.$owned->getRouteKey(), 'GET');
    $resolved = MollieBilling::resolveBillable($request);

    // The fix is a gate, not a removal — this path has to keep working.
    expect($resolved)->not->toBeNull()
        ->and(MollieBilling::authorizes($request, $resolved))->toBeTrue();
});

it('refuses when the app authorizes a different billable', function (): void {
    $victim = leakVictim();

    /** @var TestBillable $mine */
    $mine = TestBillable::create(['name' => 'Mine', 'email' => 'mine@x.test', 'billing_country' => 'DE']);

    MollieBilling::authUsing(fn ($request, $billable) => $billable->getKey() === $mine->getKey());

    $request = Illuminate\Http\Request::create('/billing/checkout?org='.$victim->getRouteKey(), 'GET');
    $resolved = MollieBilling::resolveBillable($request);

    // Resolution succeeds and authorization refuses — the two are separate answers,
    // which is precisely why the consumer has to ask the second one.
    expect($resolved->getKey())->toBe($victim->getKey())
        ->and(MollieBilling::authorizes($request, $resolved))->toBeFalse();
});

it('gates the read in the checkout component itself, not only in the resolver', function (): void {
    // Guard against the fix being "documented" rather than implemented: the
    // authorization call has to sit in mount(), between resolveBillable() and the
    // first field assignment.
    $source = file_get_contents(__DIR__.'/../../../resources/views/livewire/billing/checkout.blade.php');

    $mountStart = strpos($source, 'public function mount(): void');
    $resolve = strpos($source, 'MollieBilling::resolveBillable(request())', $mountStart);
    $authorize = strpos($source, 'MollieBilling::authorizes(request(), $existing)', $mountStart);
    $firstField = strpos($source, '$this->company_name = $existing->getBillingName()', $mountStart);

    expect($resolve)->toBeInt()
        ->and($authorize)->toBeInt()
        ->and($firstField)->toBeInt()
        ->and($authorize)->toBeGreaterThan($resolve)
        ->and($firstField)->toBeGreaterThan($authorize);
});

it('gates every request-resolved read in the portal and checkout views', function (): void {
    // Every view that resolves a billable straight off the request must gate it —
    // `readableBillable()` in the portal components, an explicit `authorizes()` in
    // the two views that predate that helper. A new ungated consumer reopens the leak.
    $views = glob(__DIR__.'/../../../resources/views/livewire/billing/**/*.blade.php')
        + glob(__DIR__.'/../../../resources/views/livewire/billing/*.blade.php');

    $ungated = [];

    foreach ($views as $path) {
        $source = (string) file_get_contents($path);

        if (! str_contains($source, 'MollieBilling::resolveBillable(request())')) {
            continue;
        }

        if (str_contains($source, 'readableBillable') || str_contains($source, 'MollieBilling::authorizes(')) {
            continue;
        }

        $ungated[] = basename($path);
    }

    expect($ungated)->toBe([]);
});

it('keeps the identity but not the data when an app-resolved billable is refused', function (): void {
    $victim = leakVictim();

    MollieBilling::resolveBillableUsing(fn () => $victim);
    MollieBilling::authUsing(fn (): bool => false);

    $component = Livewire\Livewire::test('mollie-billing::checkout');

    // The pair survives, because it is what `submit()` authorizes against — dropping
    // it would let a member who is not the billing manager create a second billable.
    expect($component->get('billableId'))->toBe((string) $victim->getKey())
        ->and($component->get('billableClass'))->toBe($victim->getMorphClass());

    // None of the victim's data was disclosed, and the billing step is not locked
    // (which is what renders the fields read-only on the confirm step).
    expect($component->get('company_name'))->toBe('')
        ->and($component->get('billing_street'))->toBe('')
        ->and($component->get('billing_postal_code'))->toBe('')
        ->and($component->get('billing_city'))->toBe('')
        ->and($component->get('vat_number'))->toBeNull()
        ->and($component->get('billing_locked'))->toBeFalse();

    // And submitting still refuses, before validation gets a word in.
    $component->set('plan_code', 'pro')->call('submit')->assertForbidden();
});

it('drops a query-parameter guess entirely instead of half-adopting it', function (): void {
    $victim = leakVictim();

    // No app resolver: only the fallback scan can find this billable, and a guess
    // that is not this visitor's is not an identity worth keeping.
    MollieBilling::resolveBillableUsing(fn () => null);
    MollieBilling::authUsing(fn (): bool => false);

    $component = Livewire\Livewire::withQueryParams(['org' => $victim->getRouteKey()])
        ->test('mollie-billing::checkout');

    expect($component->get('billableId'))->toBeNull()
        ->and($component->get('company_name'))->toBe('')
        ->and($component->get('billing_locked'))->toBeFalse();
});
