<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Laravel\Dusk\Browser;

/**
 * The admin billable page: the header (name, status badge, plan) belongs to the page
 * component, every tab is a child component of its own. A tab action used to re-render
 * only the tab, so after a resume the tab said "active" and the badge above it still
 * said "Cancelled" until the operator reloaded — and the other way round after a cancel.
 */
class AdminHeaderRefreshTest extends BrowserTestCase
{
    public function test_resuming_a_cancelled_subscription_turns_the_header_badge_active_without_a_reload(): void
    {
        $billable = $this->makeActiveSubscriber('pro', 'monthly', [
            'subscription_status' => SubscriptionStatus::Cancelled,
            'subscription_ends_at' => now()->addDays(20),
        ]);

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->loginAdmin($browser);
            $this->openBillable($browser, $billable);
            $this->assertHeaderBadge($browser, SubscriptionStatus::Cancelled);
            $this->markPage($browser);

            $this->clickWire($browser, '[wire\\:click="resubscribe"]');
            $browser->acceptDialog();
            $browser->waitForText(__('billing::admin.billables.subscription.flash.resubscribed'), 15);

            $this->waitForHeaderBadge($browser, SubscriptionStatus::Active);
            $this->assertNotReloaded($browser);
            $this->shot($browser, 'Fortgesetzt, Kopf zeigt aktiv');
        });

        expect($billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active);
    }

    public function test_cancelling_turns_the_header_badge_cancelled_without_a_reload(): void
    {
        $billable = $this->makeActiveSubscriber('pro', 'monthly');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->loginAdmin($browser);
            $this->openBillable($browser, $billable);
            $this->assertHeaderBadge($browser, SubscriptionStatus::Active);
            $this->markPage($browser);

            $this->clickWire($browser, '[wire\\:click="openCancel(true)"]');
            $browser->waitFor('textarea[name="cancelNote"]', 10)->pause(400);
            $browser->type('cancelNote', 'Customer asked by phone')->pause(300);
            $this->clickWire($browser, 'form[wire\\:submit="confirmCancel"] button[type="submit"]');
            $browser->waitForText(__('billing::admin.billables.subscription.flash.cancelled_now'), 15);

            $this->waitForHeaderBadge($browser, SubscriptionStatus::Cancelled);
            $this->assertNotReloaded($browser);
            $this->shot($browser, 'Gekündigt, Kopf zeigt gekündigt');
        });

        expect($billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Cancelled);
    }

    private function openBillable(Browser $browser, TestBillable $billable): void
    {
        $browser->visit('/billing/admin/billables/'.$billable->getKey())
            ->waitFor('[data-billable-status]', 15)
            ->pause(700);
    }

    /** @return array{status: ?string, text: string} */
    private function headerBadge(Browser $browser): array
    {
        return (array) $browser->script(<<<'JS'
            const el = document.querySelector('[data-billable-status]');
            return el ? {status: el.getAttribute('data-billable-status'), text: el.textContent.trim()} : {status: null, text: ''};
            JS)[0];
    }

    private function assertHeaderBadge(Browser $browser, SubscriptionStatus $status): void
    {
        $badge = $this->headerBadge($browser);

        expect($badge['status'])->toBe($status->value)
            ->and($badge['text'])->toBe($status->label());
    }

    private function waitForHeaderBadge(Browser $browser, SubscriptionStatus $status): void
    {
        $browser->waitUsing(10, 200, fn (): bool => $this->headerBadge($browser)['status'] === $status->value,
            "The header badge never said {$status->value}; it says ".json_encode($this->headerBadge($browser)));

        $this->assertHeaderBadge($browser, $status);
    }

    /** A marker on window survives a Livewire round trip but not a page load. */
    private function markPage(Browser $browser): void
    {
        $browser->script('window.__headerRefreshMark = true;');
    }

    private function assertNotReloaded(Browser $browser): void
    {
        expect($browser->script('return window.__headerRefreshMark === true;')[0])->toBeTrue();
    }
}
