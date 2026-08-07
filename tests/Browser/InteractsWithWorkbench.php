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
     * A billable mid-life: active Mollie subscription, mandate, filled wallets —
     * as if a checkout had happened long ago. Scenarios that test what comes
     * AFTER subscribing start here instead of clicking through checkout again;
     * the checkout itself has its own scenarios.
     */
    protected function makeActiveSubscriber(
        string $plan = 'starter',
        string $interval = 'monthly',
        array $overrides = [],
    ): TestBillable {
        $customerId = WorkbenchSetting::nextId('cst');
        DB::table('fake_mollie_customers')->insert([
            'id' => $customerId, 'name' => 'Acme GmbH', 'email' => 'acme@workbench.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $mandateId = FakeMollie::createMandate($customerId);

        $subscriptionId = WorkbenchSetting::nextId('sub');
        $catalog = app(\GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface::class);
        $net = (int) $catalog->basePriceNet($plan, $interval);
        DB::table('fake_mollie_subscriptions')->insert([
            'id' => $subscriptionId, 'customer_id' => $customerId, 'status' => 'active',
            'amount_value' => number_format($net * 1.19 / 100, 2, '.', ''), 'amount_currency' => 'EUR',
            'interval' => $interval === 'yearly' ? '12 months' : '1 month',
            'description' => 'Workbench subscription', 'mandate_id' => $mandateId,
            'metadata' => json_encode([]), 'start_date' => now()->toDateString(),
            'next_payment_date' => now()->addMonth()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $billable = $this->makeBillable(array_merge([
            'billing_street' => 'Musterweg 12',
            'billing_postal_code' => '10115',
            'billing_city' => 'Berlin',
            'billing_country' => 'DE',
            'tax_country_user' => 'DE',
            'tax_country_payment' => 'DE',
            'mollie_customer_id' => $customerId,
            'mollie_mandate_id' => $mandateId,
            'subscription_plan_code' => $plan,
            'subscription_interval' => $interval,
            'subscription_source' => \GraystackIT\MollieBilling\Enums\SubscriptionSource::Mollie,
            'subscription_status' => \GraystackIT\MollieBilling\Enums\SubscriptionStatus::Active,
            'subscription_period_starts_at' => now()->subDays(10),
            'subscription_meta' => ['mollie_subscription_id' => $subscriptionId],
        ], $overrides));

        foreach ($catalog->includedUsages($plan, $interval) as $type => $quantity) {
            if ((int) $quantity > 0) {
                app(\GraystackIT\MollieBilling\Services\Wallet\WalletUsageService::class)
                    ->credit($billable, (string) $type, (int) $quantity, 'workbench_seed');
            }
        }

        return $billable->refresh();
    }

    /**
     * Click that survives a Livewire rerender between find and click. wire:poll
     * and wire:model.live can swap the node out at exactly the wrong moment —
     * the retry re-finds the (new) element instead of failing on the stale one.
     */
    protected function clickWire(Browser $browser, string $selector): Browser
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $browser->click($selector);
            } catch (\Facebook\WebDriver\Exception\StaleElementReferenceException|\Facebook\WebDriver\Exception\ElementClickInterceptedException $e) {
                if ($attempt >= 4) {
                    throw $e;
                }

                $browser->pause(250);
            }
        }
    }

    /**
     * Click via JS — for inputs Flux renders visually hidden behind its own
     * control (checkboxes, radios), which WebDriver refuses to click natively.
     */
    protected function jsClick(Browser $browser, string $css): Browser
    {
        $browser->waitFor($css, 10);
        $browser->script(sprintf('document.querySelector(%s).click()', json_encode($css)));

        return $browser->pause(400);
    }

    /**
     * Open a Flux modal by name and click the action inside it.
     *
     * Confirm modals are the portal's pattern for anything that costs money
     * (enable addon, buy product, cancel subscription): the button carrying the
     * `wire:click` lives inside `<flux:modal>`, which is in the DOM from the
     * start but hidden — clicking it directly gets an ElementNotInteractable.
     * The trigger has to open the modal first.
     */
    protected function confirmInModal(Browser $browser, string $modal, string $action): Browser
    {
        // window.Flux.modal(name).show() rather than clicking the trigger: the
        // trigger is a Flux wrapper whose click handler sits on a child, so
        // targeting it by CSS is guesswork. This is Flux's own public API.
        //
        // Show FIRST, then wait: Dusk's waitFor() requires visibility, and the
        // action button is hidden until the modal opens. Waiting for it before
        // opening waits for something that is invisible by design.
        $browser->script(sprintf('window.Flux.modal(%s).show()', json_encode($modal)));
        $browser->waitFor($action, 10)->pause(400);

        return $this->clickWire($browser, $action);
    }

    /** How many nodes match — counts hidden ones, unlike Dusk's assertions. */
    protected function domCount(Browser $browser, string $css): int
    {
        return (int) $browser->script(
            sprintf('return document.querySelectorAll(%s).length', json_encode($css))
        )[0];
    }

    /**
     * Wait for a node to EXIST, visible or not.
     *
     * Dusk's waitFor() requires visibility, which makes it useless for anything
     * inside a Flux confirm modal: the markup is in the DOM from the start and
     * only becomes visible when the modal opens. "Is the disable-addon action
     * present?" is a question about the DOM, not about what is on screen.
     */
    protected function waitForPresence(Browser $browser, string $css, int $seconds = 10): Browser
    {
        return $browser->waitUsing($seconds, 100, fn (): bool => $this->domCount($browser, $css) > 0,
            "Waited {$seconds}s for [{$css}] to appear in the DOM.");
    }

    protected function waitForAbsence(Browser $browser, string $css, int $seconds = 10): Browser
    {
        return $browser->waitUsing($seconds, 100, fn (): bool => $this->domCount($browser, $css) === 0,
            "Waited {$seconds}s for [{$css}] to disappear from the DOM.");
    }

    /** Wait until the browser's URL path contains the fragment. */
    protected function waitForPathContains(Browser $browser, string $fragment, int $seconds = 15): Browser
    {
        return $browser->waitUsing($seconds, 100, function () use ($browser, $fragment): bool {
            return str_contains((string) parse_url($browser->driver->getCurrentURL(), PHP_URL_PATH), $fragment);
        }, "Waited {$seconds}s for path to contain [{$fragment}].");
    }

    /**
     * The checkout's billing-details step, filled the way the founding scenario
     * established. Only useful while the step is actually visible.
     */
    protected function fillBillingStep(Browser $browser, string $country = 'DE', ?string $vatNumber = null): Browser
    {
        $browser->waitFor('input[name="company_name"]', 10)
            ->type('company_name', 'Acme GmbH')
            ->type('billing_street', 'Musterweg 12')
            ->type('billing_postal_code', '10115')
            ->type('billing_city', 'Berlin')
            ->select('billing_country', $country);

        if ($vatNumber !== null) {
            $browser->type('vat_number', $vatNumber);
        }

        return $browser->pause(600);
    }

    /** Settle the fake-Mollie hosted page the browser is currently on. */
    protected function settleAtFakeMollie(Browser $browser, string $outcome = 'paid', string $country = 'DE'): Browser
    {
        $this->waitForPathContains($browser, '/fake-mollie/checkout/');
        $browser->waitFor('@fake-mollie-pay', 10)
            ->select('country', $country);

        $this->shot($browser, 'Fake-Mollie: '.$outcome);

        return $browser->click('@fake-mollie-'.match ($outcome) {
            'paid' => 'pay',
            'failed' => 'fail',
            'canceled' => 'cancel',
            'expired' => 'expire',
        });
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
