<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\LocalSubscriptionCannotPurchaseProductsException;
use GraystackIT\MollieBilling\Exceptions\NonEuCountryException;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\IpGeolocation\Contracts\IpGeolocationDriver;
use GraystackIT\MollieBilling\IpGeolocation\Drivers\DbIpDriver;
use GraystackIT\MollieBilling\IpGeolocation\IpGeolocationManager;
use GraystackIT\MollieBilling\IpGeolocation\Drivers\IpInfoLiteDriver;
use GraystackIT\MollieBilling\IpGeolocation\Drivers\NullDriver;
use GraystackIT\MollieBilling\Jobs\ProcessTrialLifecycleJob;
use GraystackIT\MollieBilling\Notifications\TrialEndingSoonNotification;
use GraystackIT\MollieBilling\Services\Billing\ActivateLocalSubscription;
use GraystackIT\MollieBilling\Services\Billing\StartOneTimeOrderCheckout;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\CountryResolver;
use GraystackIT\MollieBilling\Tests\Support\BookingMatrix;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/*
 * One test per configuration key of the checkout / VAT / country / currency / trial area:
 * each changes the value and asserts what the key is there to change.
 */

afterEach(fn () => Carbon::setTestNow());

// ── checkout ───────────────────────────────────────────────────────────────────

it('config checkout_back_url: is where the checkout\'s back link leads', function (): void {
    BookingMatrix::boot();
    MollieBilling::resolveBillableUsing(fn () => null);
    config()->set('mollie-billing.checkout_back_url', '/pricing-overview');

    // The layout links to `Sanitize::backUrl($backUrl) ?? config('…checkout_back_url')`.
    // (Its <flux:link> is only compiled in the browser suite, which asserts the href.)
    $resolve = fn (?string $back): string => \GraystackIT\MollieBilling\Support\Sanitize::backUrl(
        Livewire::withQueryParams(array_filter(['back' => $back]))->test('mollie-billing::checkout')->get('backUrl'),
    ) ?? config('mollie-billing.checkout_back_url', '/');

    // Nothing asked for, or something that leaves the site: the configured URL.
    expect($resolve(null))->toBe('/pricing-overview')
        ->and($resolve('https://evil.example/phish'))->toBe('/pricing-overview')
        ->and($resolve('//evil.example'))->toBe('/pricing-overview')
        // A local path asked for with ?back= wins over it.
        ->and($resolve('/plans'))->toBe('/plans');

    config()->set('mollie-billing.checkout_back_url', '/somewhere-else');
    expect($resolve(null))->toBe('/somewhere-else');
});

it('config checkout_countries.regions: decides whether the EU member states are offered', function (): void {
    config()->set('mollie-billing.checkout_countries', ['regions' => ['EU'], 'include' => [], 'exclude' => []]);
    expect(CountryResolver::resolve())->toHaveCount(27);

    config()->set('mollie-billing.checkout_countries', ['regions' => [], 'include' => ['AT'], 'exclude' => []]);
    expect(array_keys(CountryResolver::resolve()))->toBe(['AT']);
});

it('config checkout_countries.include: adds a country that can be invoiced, and only such a one', function (): void {
    config()->set('mollie-billing.checkout_countries', ['regions' => [], 'include' => ['DE', 'GB'], 'exclude' => []]);
    expect(array_keys(CountryResolver::resolve()))->toBe(['DE']);

    config()->set('mollie-billing.additional_countries', ['GB' => ['vat_rate' => 20.0, 'name' => 'United Kingdom']]);
    expect(array_keys(CountryResolver::resolve()))->toEqualCanonicalizing(['DE', 'GB']);
});

it('config checkout_countries.exclude: takes a country out of the selector and out of the checkout', function (): void {
    BookingMatrix::boot();
    MollieBilling::resolveBillableUsing(fn () => null);
    config()->set('mollie-billing.checkout_countries', ['regions' => ['EU'], 'include' => [], 'exclude' => ['DE']]);

    expect(CountryResolver::resolve())->not->toHaveKey('DE')->toHaveCount(26);

    Livewire::test('mollie-billing::checkout')
        ->set('company_name', 'X')->set('billing_street', 'Y 1')->set('billing_postal_code', '1')->set('billing_city', 'Z')
        ->set('billing_country', 'DE')
        ->call('next')
        ->assertHasErrors('billing_country');
});

it('config default_billing_country: is the country a checkout starts with when the IP says nothing', function (): void {
    BookingMatrix::boot();
    MollieBilling::resolveBillableUsing(fn () => null);

    config()->set('mollie-billing.default_billing_country', 'NL');
    expect(Livewire::test('mollie-billing::checkout')->get('billing_country'))->toBe('NL');

    config()->set('mollie-billing.default_billing_country', 'FR');
    expect(Livewire::test('mollie-billing::checkout')->get('billing_country'))->toBe('FR');
});

it('config redirect_after_return: is where a finished checkout sends the customer', function (): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable('AT');
    app(ActivateLocalSubscription::class)->handle($billable, 'free', 'monthly');
    Route::get('/app-home', fn () => 'home')->name('app.home');

    config()->set('mollie-billing.redirect_after_return', null);
    expect(Livewire::test('mollie-billing::return')->instance()->returnUrl())->toBe(route('billing.index'));

    config()->set('mollie-billing.redirect_after_return', 'app.home');
    expect(Livewire::test('mollie-billing::return')->instance()->returnUrl())->toBe(route('app.home'));
});

// ── VAT ────────────────────────────────────────────────────────────────────────

it('config vat.seller_country: separates a domestic business customer from a cross-border one', function (): void {
    $m = BookingMatrix::boot();
    $billable = $m->business('DE', 'DE123456789');
    $vat = app(VatCalculationService::class);

    config()->set('mollie-billing.vat.seller_country', 'AT');
    expect($vat->calculate('DE', 10000, $billable)['vat'])->toBe(0)
        ->and($billable->usesReverseCharge())->toBeTrue();

    config()->set('mollie-billing.vat.seller_country', 'DE');
    expect($vat->calculate('DE', 10000, $billable)['vat'])->toBe(1900)
        ->and($billable->usesReverseCharge())->toBeFalse();
});

it('config vat.require_seller_country: refuses reverse charge while no seller country is known', function (): void {
    $m = BookingMatrix::boot();
    $billable = $m->business('DE', 'DE123456789');
    $vat = app(VatCalculationService::class);
    config()->set('mollie-billing.vat.seller_country', null);
    config()->set('mollie-billing.invoices.seller.address.country', null);

    config()->set('mollie-billing.vat.require_seller_country', false);
    expect($vat->calculate('DE', 10000, $billable)['vat'])->toBe(0);

    config()->set('mollie-billing.vat.require_seller_country', true);
    expect($vat->calculate('DE', 10000, $billable)['vat'])->toBe(1900);
});

it('config checkout_country_fallback: is the VAT country of a billable that has none', function (): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable('AT', ['billing_country' => null, 'tax_country_user' => null]);

    config()->set('mollie-billing.checkout_country_fallback', 'DE');
    app(StartOneTimeOrderCheckout::class)->handle($billable, ['product_code' => 'pack']);
    expect(BookingMatrix::minor($m->lastPayment()))->toBe(5891); // 49.50 + 19 %

    config()->set('mollie-billing.checkout_country_fallback', 'AT');
    app(StartOneTimeOrderCheckout::class)->handle($billable->fresh(), ['product_code' => 'pack']);
    expect(BookingMatrix::minor($m->lastPayment()))->toBe(5940); // 49.50 + 20 %
});

it('config vat_rate_overrides: outranks the rate the VAT library knows', function (): void {
    $vat = app(VatCalculationService::class);

    config()->set('mollie-billing.vat_rate_overrides', []);
    expect($vat->calculate('DE', 10000)['vat'])->toBe(1900);

    config()->set('mollie-billing.vat_rate_overrides', ['DE' => 7.0]);
    expect($vat->calculate('DE', 10000))->toMatchArray(['vat' => 700, 'gross' => 10700, 'rate' => 7.0]);
});

it('config additional_countries: makes a non-EU country invoiceable at its declared rate', function (): void {
    $vat = app(VatCalculationService::class);

    config()->set('mollie-billing.additional_countries', []);
    expect($vat->isInvoiceable('NO'))->toBeFalse()
        ->and(fn () => $vat->calculate('NO', 10000))->toThrow(NonEuCountryException::class)
        ->and(CountryResolver::resolve())->not->toHaveKey('NO');

    config()->set('mollie-billing.additional_countries', ['NO' => ['vat_rate' => 25.0, 'name' => 'Norway']]);
    expect($vat->isInvoiceable('NO'))->toBeTrue()
        ->and($vat->calculate('NO', 10000)['gross'])->toBe(12500)
        ->and(CountryResolver::resolve()['NO'])->toBe('Norway');
});

// ── currency ───────────────────────────────────────────────────────────────────

it('config currency: is the currency a payment is created in, at that currency\'s scale', function (): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable('AT');

    config()->set('mollie-billing.currency', 'EUR');
    app(StartOneTimeOrderCheckout::class)->handle($billable, ['product_code' => 'pack']);
    $amount = BookingMatrix::prop($m->lastPayment(), 'amount');
    expect($amount->currency)->toBe('EUR')->and($amount->value)->toBe('59.40');

    config()->set('mollie-billing.currency', 'JPY');
    app(StartOneTimeOrderCheckout::class)->handle($billable->fresh(), ['product_code' => 'pack']);
    $amount = BookingMatrix::prop($m->lastPayment(), 'amount');
    expect($amount->currency)->toBe('JPY')->and($amount->value)->toBe('5940');
});

it('config currency_symbol: replaces the symbol every amount is rendered with', function (): void {
    config()->set('mollie-billing.currency', 'EUR');

    config()->set('mollie-billing.currency_symbol', null);
    expect(BillingMoney::format(1234))->toContain('€');

    config()->set('mollie-billing.currency_symbol', 'Fr.');
    expect(BillingMoney::format(1234))->toContain('Fr.')->not->toContain('€')
        ->and(BillingMoney::symbol())->toBe('Fr.');
});

// ── Mollie ─────────────────────────────────────────────────────────────────────

it('config mollie_api_key: is handed to the Mollie SDK as its key', function (): void {
    config()->set('mollie.key', 'test_original');
    config()->set('mollie-billing.mollie_api_key', 'test_frombillingconfig');

    $provider = new \GraystackIT\MollieBilling\MollieBillingServiceProvider($this->app);
    $method = new ReflectionMethod($provider, 'propagateMollieApiKey');
    $method->setAccessible(true);
    $method->invoke($provider);

    expect(config('mollie.key'))->toBe('test_frombillingconfig');

    // Unset leaves the SDK's own key alone.
    config()->set('mollie.key', 'test_original');
    config()->set('mollie-billing.mollie_api_key', null);
    $method->invoke($provider);

    expect(config('mollie.key'))->toBe('test_original');
});

it('config mollie_locale: is the locale of the hosted payment page', function (): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable('AT');

    config()->set('mollie-billing.mollie_locale', null);
    app(StartOneTimeOrderCheckout::class)->handle($billable, ['product_code' => 'pack']);
    expect(BookingMatrix::prop($m->lastPayment(), 'locale'))->toBeNull();

    config()->set('mollie-billing.mollie_locale', 'de_AT');
    app(StartOneTimeOrderCheckout::class)->handle($billable->fresh(), ['product_code' => 'pack']);
    expect(BookingMatrix::prop($m->lastPayment(), 'locale'))->toBe('de_AT');
});

// ── IP geolocation and the country gate ────────────────────────────────────────

it('config ip_geolocation.driver: selects the driver that answers for an IP', function (): void {
    Cache::flush();
    config()->set('mollie-billing.default_billing_country', 'AT');

    config()->set('mollie-billing.ip_geolocation.driver', 'null');
    $manager = new IpGeolocationManager($this->app);
    expect($manager->driver())->toBeInstanceOf(NullDriver::class)
        ->and($manager->defaultCountryFor('8.8.8.8'))->toBe('AT');

    $manager = new IpGeolocationManager($this->app);
    $manager->extend('fixed', fn (): IpGeolocationDriver => new class implements IpGeolocationDriver
    {
        public function getCountry(string $ip): ?string
        {
            return 'NL';
        }
    });
    config()->set('mollie-billing.ip_geolocation.driver', 'fixed');
    Cache::flush();

    expect($manager->defaultCountryFor('8.8.8.8'))->toBe('NL');
});

it('config ip_geolocation.drivers: carries each driver\'s credentials', function (): void {
    config()->set('mollie-billing.ip_geolocation.drivers', [
        'ipinfo_lite' => ['token' => 'tok_123'],
        'db_ip' => ['api_key' => 'key_456'],
    ]);

    $manager = new IpGeolocationManager($this->app);
    $read = function (object $driver): array {
        return array_values(array_filter(array_map(function (ReflectionProperty $p) use ($driver) {
            $p->setAccessible(true);

            return $p->getValue($driver);
        }, (new ReflectionObject($driver))->getProperties()), 'is_string'));
    };

    expect($manager->createIpinfoLiteDriver())->toBeInstanceOf(IpInfoLiteDriver::class)
        ->and($read($manager->createIpinfoLiteDriver()))->toContain('tok_123')
        ->and($manager->createDbIpDriver())->toBeInstanceOf(DbIpDriver::class)
        ->and($read($manager->createDbIpDriver()))->toContain('key_456');
});

/** Pin the country the gate sees for the request's IP. */
function configKeysIpCountry(?string $country): void
{
    Cache::flush();
    app()->singleton(IpGeolocationManager::class, function ($app) use ($country): IpGeolocationManager {
        $manager = new IpGeolocationManager($app);
        $manager->extend('fixed', fn (): IpGeolocationDriver => new class($country) implements IpGeolocationDriver
        {
            public function __construct(private ?string $country)
            {
            }

            public function getCountry(string $ip): ?string
            {
                return $this->country;
            }
        });

        return $manager;
    });
    config()->set('mollie-billing.ip_geolocation.driver', 'fixed');
}

it('config ip_block.enabled: switches the country gate on', function (): void {
    BookingMatrix::boot();
    MollieBilling::resolveBillableUsing(fn () => null);
    configKeysIpCountry('RU');
    config()->set('mollie-billing.ip_block', ['enabled' => false, 'mode' => 'blocklist', 'countries' => ['RU'], 'block_unknown' => false]);

    $this->get(route('billing.promotion', ['token' => 'x']), ['REMOTE_ADDR' => '8.8.8.8'])->assertRedirect(route('billing.checkout'));

    config()->set('mollie-billing.ip_block.enabled', true);
    $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
        ->get(route('billing.promotion', ['token' => 'x']))
        ->assertRedirect(route('billing.blocked', ['country' => 'RU']));
});

it('config ip_block.mode: blocklist blocks the listed countries, allowlist everything else', function (): void {
    BookingMatrix::boot();
    configKeysIpCountry('DE');
    config()->set('mollie-billing.ip_block', ['enabled' => true, 'mode' => 'blocklist', 'countries' => ['DE'], 'block_unknown' => false]);

    $get = fn () => $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->get(route('billing.promotion', ['token' => 'x']));

    $get()->assertRedirect(route('billing.blocked', ['country' => 'DE']));

    config()->set('mollie-billing.ip_block.mode', 'allowlist');
    $get()->assertRedirect(route('billing.checkout'));

    config()->set('mollie-billing.ip_block.countries', ['AT']);
    $get()->assertRedirect(route('billing.blocked', ['country' => 'DE']));
});

it('config ip_block.countries: is the list the gate compares the visitor\'s country with', function (): void {
    BookingMatrix::boot();
    configKeysIpCountry('CN');
    config()->set('mollie-billing.ip_block', ['enabled' => true, 'mode' => 'blocklist', 'countries' => ['RU'], 'block_unknown' => false]);

    $get = fn () => $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->get(route('billing.promotion', ['token' => 'x']));

    $get()->assertRedirect(route('billing.checkout'));

    config()->set('mollie-billing.ip_block.countries', ['RU', 'cn']);
    $get()->assertRedirect(route('billing.blocked', ['country' => 'CN']));
});

it('config ip_block.block_unknown: decides what happens to a visitor whose country is unknown', function (): void {
    BookingMatrix::boot();
    configKeysIpCountry(null);
    config()->set('mollie-billing.ip_block', ['enabled' => true, 'mode' => 'blocklist', 'countries' => ['RU'], 'block_unknown' => false]);

    $get = fn () => $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->get(route('billing.promotion', ['token' => 'x']));

    $get()->assertRedirect(route('billing.checkout'));

    config()->set('mollie-billing.ip_block.block_unknown', true);
    $get()->assertRedirect(route('billing.blocked'));
});

// ── trial ──────────────────────────────────────────────────────────────────────

it('config trial_lifecycle_job_time: is when the trial job is scheduled', function (string $time): void {
    config()->set('mollie-billing.trial_lifecycle_job_time', $time);

    // A fresh schedule, so the provider's callback registers the job with this value.
    $this->app->forgetInstance(Schedule::class);
    $schedule = $this->app->make(Schedule::class);

    $event = collect($schedule->events())->first(fn ($e) => str_contains((string) $e->description, 'ProcessTrialLifecycleJob'));
    [$hour, $minute] = explode(':', $time);

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe(sprintf('%d %d * * *', (int) $minute, (int) $hour));
})->with(['02:05', '04:30']);

it('config trial_ending_soon_notice_days: is how many days ahead the trial reminder goes out', function (): void {
    $m = BookingMatrix::boot();
    Notification::fake();
    MollieBilling::notifyBillingAdminsUsing(fn ($b): array => [$b]);

    $inThree = $m->billable('AT', ['subscription_status' => SubscriptionStatus::Trial, 'subscription_plan_code' => 'team', 'trial_ends_at' => BillingTime::nowUtc()->addDays(3)]);
    $tomorrow = $m->billable('AT', ['subscription_status' => SubscriptionStatus::Trial, 'subscription_plan_code' => 'team', 'trial_ends_at' => BillingTime::nowUtc()->addDay()]);

    config()->set('mollie-billing.trial_ending_soon_notice_days', 1);
    ProcessTrialLifecycleJob::dispatchSync();

    Notification::assertSentTo($tomorrow, TrialEndingSoonNotification::class);
    Notification::assertNotSentTo($inThree, TrialEndingSoonNotification::class);

    config()->set('mollie-billing.trial_ending_soon_notice_days', 3);
    ProcessTrialLifecycleJob::dispatchSync();

    Notification::assertSentTo($inThree, TrialEndingSoonNotification::class);
});

// ── portal ─────────────────────────────────────────────────────────────────────

it('config show_yearly_savings: shows or hides the yearly saving on the plan screen', function (): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable('AT');
    app(ActivateLocalSubscription::class)->handle($billable, 'free', 'monthly');

    config()->set('mollie-billing.show_yearly_savings', true);
    expect(Livewire::test('mollie-billing::plan-change')->instance()->with()['showYearlySavings'])->toBeTrue();

    config()->set('mollie-billing.show_yearly_savings', false);
    expect(Livewire::test('mollie-billing::plan-change')->instance()->with()['showYearlySavings'])->toBeFalse();
});

it('config local_subscription.allow_one_time_orders: lets a customer on a free plan buy products, or not', function (): void {
    $m = BookingMatrix::boot();
    $billable = $m->billable('AT');
    app(ActivateLocalSubscription::class)->handle($billable, 'free', 'monthly');
    $billable = $billable->fresh();

    config()->set('mollie-billing.local_subscription.allow_one_time_orders', false);
    expect(fn () => app(StartOneTimeOrderCheckout::class)->handle($billable, ['product_code' => 'pack']))
        ->toThrow(LocalSubscriptionCannotPurchaseProductsException::class);
    expect($m->lastPayment())->toBeNull()
        ->and(Livewire::test('mollie-billing::products')->instance()->with()['localBlocksProducts'])->toBeTrue();

    config()->set('mollie-billing.local_subscription.allow_one_time_orders', true);
    app(StartOneTimeOrderCheckout::class)->handle($billable, ['product_code' => 'pack']);
    expect(BookingMatrix::minor($m->lastPayment()))->toBe(5940)
        ->and(Livewire::test('mollie-billing::products')->instance()->with()['localBlocksProducts'])->toBeFalse();
});

it('config billing_timezone: is the zone billing dates are shown in', function (): void {
    $instant = Carbon::parse('2026-03-31 23:30:00', 'UTC');

    config()->set('mollie-billing.billing_timezone', 'UTC');
    expect(BillingTime::display($instant)->format('Y-m-d H:i'))->toBe('2026-03-31 23:30');

    // The same instant is already 1 April in Vienna — which is the date a customer reads.
    config()->set('mollie-billing.billing_timezone', 'Europe/Vienna');
    expect(BillingTime::display($instant)->format('Y-m-d H:i'))->toBe('2026-04-01 01:30');

    $billable = new \GraystackIT\MollieBilling\Testing\TestBillable;
    expect($billable->getBillingTimezone())->toBe('Europe/Vienna');
});
