<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

afterEach(function (): void {
    config()->set('app.timezone', 'UTC');
    date_default_timezone_set('UTC');
});

/**
 * The package must persist and rehydrate datetime columns as UTC regardless
 * of the consuming app's `app.timezone`. The custom UtcDatetime cast is the
 * mechanism that delivers this guarantee.
 */
it('rehydrates a stored datetime as UTC under app.timezone=Europe/Berlin', function (): void {
    config()->set('app.timezone', 'Europe/Berlin');
    date_default_timezone_set('Europe/Berlin');

    // Write directly to the DB so we know the raw stored string.
    $coupon = new Coupon();
    $coupon->name = 'Test Coupon';
    $coupon->code ='TZTEST-'.uniqid();
    $coupon->type = \GraystackIT\MollieBilling\Enums\CouponType::SinglePayment;
    $coupon->discount_type = \GraystackIT\MollieBilling\Enums\DiscountType::Percentage;
    $coupon->discount_value = 10;
    $coupon->valid_from = CarbonImmutable::create(2026, 5, 2, 23, 30, 0, 'UTC');
    $coupon->save();

    // Raw DB string: must be the UTC-formatted value, not Berlin local.
    $raw = DB::table('coupons')->where('id', $coupon->id)->value('valid_from');
    expect((string) $raw)->toBe('2026-05-02 23:30:00');

    // Rehydrate fresh — cast must return UTC, not Berlin.
    $reloaded = Coupon::find($coupon->id);
    expect($reloaded->valid_from->getTimezone()->getName())->toBe('UTC');
    expect($reloaded->valid_from->format('Y-m-d H:i:s'))->toBe('2026-05-02 23:30:00');
});

it('writes a Carbon in a non-UTC timezone as the UTC equivalent', function (): void {
    config()->set('app.timezone', 'Europe/Berlin');
    date_default_timezone_set('Europe/Berlin');

    // Caller hands in a Berlin-local Carbon — cast must still write UTC.
    $berlin = CarbonImmutable::create(2026, 5, 3, 1, 30, 0, 'Europe/Berlin');
    // 2026-05-03 01:30 Berlin = 2026-05-02 23:30 UTC.

    $coupon = new Coupon();
    $coupon->name = 'Test Coupon';
    $coupon->code ='TZWRITE-'.uniqid();
    $coupon->type = \GraystackIT\MollieBilling\Enums\CouponType::SinglePayment;
    $coupon->discount_type = \GraystackIT\MollieBilling\Enums\DiscountType::Percentage;
    $coupon->discount_value = 10;
    $coupon->valid_from = $berlin;
    $coupon->save();

    $raw = DB::table('coupons')->where('id', $coupon->id)->value('valid_from');
    expect((string) $raw)->toBe('2026-05-02 23:30:00');

    $reloaded = Coupon::find($coupon->id);
    expect($reloaded->valid_from->getTimezone()->getName())->toBe('UTC');
});

it('persists HasBilling datetime columns as UTC under app.timezone=Pacific/Auckland', function (): void {
    config()->set('app.timezone', 'Pacific/Auckland');
    date_default_timezone_set('Pacific/Auckland');

    // Pin a known UTC moment so we can compare against an independent expectation.
    $expected = CarbonImmutable::create(2026, 5, 2, 23, 30, 0, 'UTC');

    $billable = TestBillable::create([
        'name' => 'Tester',
        'email' => 'tester-tz@example.com',
        'trial_ends_at' => $expected,
    ]);

    // Raw DB read — must be the UTC-formatted string, not Auckland local
    // (Auckland local would be 2026-05-03 11:30 +12, not 2026-05-02 23:30).
    $raw = DB::table('test_billables')->where('id', $billable->id)->value('trial_ends_at');
    expect((string) $raw)->toBe('2026-05-02 23:30:00');

    // Reload through Eloquent — cast must rehydrate as UTC, exactly the original moment.
    $reloaded = TestBillable::find($billable->id);
    expect($reloaded->trial_ends_at->getTimezone()->getName())->toBe('UTC');
    expect($reloaded->trial_ends_at->format('Y-m-d H:i:s'))->toBe('2026-05-02 23:30:00');
});

it('returns null when the stored value is null', function (): void {
    config()->set('app.timezone', 'Europe/Berlin');
    date_default_timezone_set('Europe/Berlin');

    $coupon = new Coupon();
    $coupon->name = 'Test Coupon';
    $coupon->code ='TZNULL-'.uniqid();
    $coupon->type = \GraystackIT\MollieBilling\Enums\CouponType::SinglePayment;
    $coupon->discount_type = \GraystackIT\MollieBilling\Enums\DiscountType::Percentage;
    $coupon->discount_value = 10;
    $coupon->valid_from = null;
    $coupon->valid_until = null;
    $coupon->save();

    $reloaded = Coupon::find($coupon->id);
    expect($reloaded->valid_from)->toBeNull();
    expect($reloaded->valid_until)->toBeNull();
});
