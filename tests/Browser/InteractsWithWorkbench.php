<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Testing\LifecycleSimulator;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Workbench\App\FakeMollie\FakeMollie;
use Workbench\App\Support\WorkbenchSetting;

/**
 * The suite's levers. Everything here runs in the TEST process but acts on the
 * shared world: billables, coupons, fake-Mollie state, webhook deliveries and
 * the lifecycle simulator all write the SQLite file the server reads.
 */
trait InteractsWithWorkbench
{
    /** Per-test screenshot counter, so gallery steps sort in visit order. */
    private int $screenshotStep = 0;

    protected function makeBillable(array $overrides = []): TestBillable
    {
        $billable = new TestBillable;

        $billable->forceFill(array_merge([
            'name' => 'Acme GmbH',
            'email' => 'owner-'.uniqid().'@acme.test',
            'billing_country' => 'DE',
        ], $overrides))->save();

        return $billable->refresh();
    }

    /**
     * Point the browser session at a billable and land wherever the scenario
     * starts. This also wipes cookies first — Dusk reuses one Chrome across
     * tests, and a stale admin login would bleed into customer scenarios.
     */
    protected function actAs(Browser $browser, TestBillable $billable, string $then = '/billing'): Browser
    {
        $browser->visit('/');
        $browser->driver->manage()->deleteAllCookies();

        return $browser->visit('/_wb/act-as/'.$billable->getKey())
            ->visit($then);
    }

    protected function loginAdmin(Browser $browser): Browser
    {
        $browser->visit('/');
        $browser->driver->manage()->deleteAllCookies();

        return $browser->visit('/_wb/admin-login');
    }

    /** @return array<string, mixed> newest fake payment row */
    protected function latestFakePayment(): array
    {
        $row = DB::table('fake_mollie_payments')->orderByDesc('created_at')->orderByDesc('id')->first();

        expect($row)->not->toBeNull();

        return (array) $row;
    }

    /**
     * Deliver the legacy webhook for a payment — in the test process, against
     * the shared database, exactly what the fake checkout page does server-side.
     */
    protected function deliverWebhook(string $paymentId): int
    {
        return FakeMollie::deliverWebhook($paymentId);
    }

    protected function setRecurringOutcome(string $outcome): void
    {
        WorkbenchSetting::set('recurring_outcome', $outcome);
    }

    protected function setIpCountry(?string $country): void
    {
        WorkbenchSetting::set('ip_country', $country);
    }

    protected function simulator(): LifecycleSimulator
    {
        return app(LifecycleSimulator::class);
    }

    protected function coupons(): CouponService
    {
        return app(CouponService::class);
    }

    /**
     * Full-height screenshot plus a manifest line for the HTML gallery. The
     * label reads as a step caption — write it for the human flipping through.
     */
    protected function shot(Browser $browser, string $label): Browser
    {
        $scenario = str(static::class)->classBasename()->replaceLast('Test', '')->kebab()
            .'/'.str($this->name())->kebab();

        $file = sprintf('%s/%02d-%s', $scenario, ++$this->screenshotStep, str($label)->kebab());

        $browser->fitContent()->screenshot($file);
        $browser->resize(1440, 1100);

        file_put_contents(__DIR__.'/screenshots/manifest.jsonl', json_encode([
            'scenario' => (string) str($scenario)->before('/'),
            'test' => $this->name(),
            'step' => $this->screenshotStep,
            'label' => $label,
            'file' => $file.'.png',
            'time' => now()->toIso8601String(),
        ]).PHP_EOL, FILE_APPEND);

        return $browser;
    }
}
