<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use GraystackIT\MollieBilling\Notifications\TrialEndingSoonNotification;
use GraystackIT\MollieBilling\Tests\Support\NotificationFixtures as Fixture;

beforeEach(function (): void {
    app()->setLocale('en');

    // The reported symptom ran the daily job just after 02:00 UTC.
    Carbon::setTestNow('2026-03-04 02:05:00');
    CarbonImmutable::setTestNow('2026-03-04 02:05:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function trialEndingSoonDays(string $trialEndsAt): int
{
    $billable = Fixture::billable();
    $billable->forceFill(['trial_ends_at' => CarbonImmutable::parse($trialEndsAt, 'UTC')])->save();

    return (int) (new TrialEndingSoonNotification($billable))->toArray($billable)['days'];
}

it('counts a trial ending tomorrow morning as one day', function (): void {
    // Only 22h55m away — truncating the raw diff rendered "ends in 0 days".
    expect(trialEndingSoonDays('2026-03-05 01:00:00'))->toBe(1);
});

it('counts a trial ending later today as zero days', function (): void {
    expect(trialEndingSoonDays('2026-03-04 23:00:00'))->toBe(0);
});

it('counts calendar days, not rounded-up hours', function (): void {
    // 68h55m away: rounding the raw diff up would claim three days.
    expect(trialEndingSoonDays('2026-03-06 23:00:00'))->toBe(2);
});

it('clamps an already-expired trial to zero days', function (): void {
    expect(trialEndingSoonDays('2026-03-02 12:00:00'))->toBe(0);
});

it('puts the same day count in the mail subject', function (): void {
    $billable = Fixture::billable();
    $billable->forceFill(['trial_ends_at' => CarbonImmutable::parse('2026-03-05 01:00:00', 'UTC')])->save();

    expect((new TrialEndingSoonNotification($billable))->toMail($billable)->subject)
        ->toBe('Your trial ends in 1 days');
});
