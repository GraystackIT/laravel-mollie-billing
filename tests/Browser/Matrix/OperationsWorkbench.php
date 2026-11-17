<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser\Matrix;

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingVatValidation;
use GraystackIT\MollieBilling\Services\Billing\AdminExtensionService;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Workbench\App\FakeMollie\FakeMollie;
use Workbench\App\Support\WorkbenchSetting;

/**
 * Levers of the operations matrix (admin panel, usage, invoices): the worlds its
 * scenarios start from, and the few ways of reading a screen they share.
 */
trait OperationsWorkbench
{
    /**
     * Change a config value for BOTH processes. `config()` alone only reaches the test
     * process; the row is what the Dusk server reads on its next request
     * (WorkbenchServiceProvider::applyConfigOverrides()).
     */
    protected function serverConfig(string $key, mixed $value): void
    {
        WorkbenchSetting::set('config.'.$key, json_encode($value));
        config([$key => $value]);
    }

    /**
     * A subscriber in one of the VAT situations the matrix walks.
     *
     *  - `DE`: consumer in Germany, 19 % (OSS — the seller sits in AT)
     *  - `AT`: domestic, 20 %
     *  - `RC`: VIES-valid business in the Netherlands, reverse charge, 0 %
     *
     * @param  list<string>  $addons
     */
    protected function opsSubscriber(string $plan, int $seats = 0, array $addons = [], string $vatCase = 'DE'): TestBillable
    {
        $country = match ($vatCase) {
            'AT' => 'AT',
            'RC' => 'NL',
            default => 'DE',
        };

        $billable = $this->makeActiveSubscriber($plan, 'monthly', [
            'billing_country' => $country,
            'tax_country_user' => $country,
            'tax_country_payment' => $country,
            'tax_country_ip' => $country,
            'vat_number' => $vatCase === 'RC' ? 'NL863726392B01' : null,
            'active_addon_codes' => $addons,
        ]);

        $this->setIpCountry($country);

        if ($seats > 0) {
            $billable->forceFill(['subscription_meta' => array_merge(
                $billable->getBillingSubscriptionMeta(),
                ['seat_count' => $seats],
            )])->save();
        }

        if ($vatCase === 'RC') {
            BillingVatValidation::query()->create([
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => $billable->getKey(),
                'vat_number' => 'NL863726392B01',
                'country_code' => 'NL',
                'valid' => true,
                'checked_at' => now(),
            ]);
        }

        return $billable->refresh();
    }

    /** A 20 % recurring coupon, applied the way a redemption at checkout leaves it. */
    protected function opsApplyRecurringCoupon(TestBillable $billable, int $baseNet): void
    {
        $coupon = $this->coupons()->create([
            'code' => 'OPS20-'.strtoupper(substr(uniqid(), -6)),
            'name' => 'Operations matrix 20 %',
            'type' => CouponType::Recurring,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 20,
            'max_redemptions_per_billable' => 12,
        ]);

        $this->coupons()->applyRecurringMarker($coupon, $billable->fresh(), $baseNet, 'monthly');
    }

    /** The plan made free for three periods; extras stay paid. */
    protected function opsApplyPlanWaiver(TestBillable $billable): void
    {
        app(AdminExtensionService::class)->freePlanPeriods($billable->fresh(), 3);
    }

    /**
     * Mollie's scheduler charges the subscription `$gross` (a Mollie amount string) from
     * the billable's own country, and the webhook books it. Returns the renewal invoice.
     */
    protected function opsRenew(TestBillable $billable, string $gross): BillingInvoice
    {
        $billable = $billable->fresh();
        $subscriptionId = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');
        $payment = FakeMollie::chargeSubscription($subscriptionId, $gross);

        DB::table('fake_mollie_payments')->where('id', $payment['id'])
            ->update(['country_code' => (string) $billable->billing_country]);

        $this->deliverWebhook($payment['id']);

        return BillingInvoice::query()->where('mollie_payment_id', $payment['id'])->firstOrFail();
    }

    /**
     * `$units` of overage on one usage type, charged and paid: the overage invoice.
     */
    protected function opsOverageInvoice(TestBillable $billable, string $type, int $units): BillingInvoice
    {
        $billable = $billable->fresh();
        $balance = (int) ($billable->getWallet($type)?->balanceInt ?? 0);

        app(WalletUsageService::class)->debit($billable, $type, $balance + $units);

        $result = app(ChargeUsageOverageDirectly::class)->handle($billable->fresh());

        expect($result->wasCharged())->toBeTrue('the overage charge was not created');

        DB::table('fake_mollie_payments')->where('id', $result->paymentId)
            ->update(['country_code' => (string) $billable->billing_country]);

        $this->deliverWebhook((string) $result->paymentId);

        return BillingInvoice::query()->where('mollie_payment_id', $result->paymentId)->firstOrFail();
    }

    /** The admin detail page of a billable, on one of its tabs. */
    protected function opsOpenBillableTab(Browser $browser, TestBillable $billable, string $tab): Browser
    {
        $browser->visit('/billing/admin/billables/'.$billable->getKey())
            ->waitForText($billable->getBillingName(), 15)->pause(500);

        $label = $tab === 'audit'
            ? __('billing::audit.tab_label')
            : __('billing::admin.billables.show.tab.'.$tab);

        return $browser->press($label)->pause(900);
    }

    /** Everything a person can read on the page right now. */
    protected function opsPageText(Browser $browser): string
    {
        return (string) $browser->script('return document.body.innerText;')[0];
    }

    /** The figure on a dashboard-style tile, found by the tile's label. */
    protected function opsTileValue(Browser $browser, string $label): string
    {
        $script = <<<'JS'
            const label = arguments[0];
            const nodes = Array.from(document.querySelectorAll('[data-flux-card]'));
            for (const card of nodes) {
                const first = card.querySelector('[data-flux-text]');
                if (first && first.textContent.trim().toLowerCase() === label.trim().toLowerCase()) {
                    const value = card.querySelector('.text-2xl');
                    return value ? value.textContent.trim() : null;
                }
            }
            return null;
        JS;

        return (string) ($browser->driver->executeScript($script, [$label]) ?? '');
    }

    /** Money as the screen prints it, without the invisible spaces a locale adds. */
    protected function opsNormaliseMoney(string $value): string
    {
        return str_replace(['−', ' ', "\u{00a0}", "\u{202f}"], ['-', '', '', ''], trim($value));
    }

    /** Whether the page shows this amount (minor units), in the screen's own format. */
    protected function opsSeesMoney(Browser $browser, int $minor): bool
    {
        return str_contains(
            $this->opsNormaliseMoney($this->opsPageText($browser)),
            $this->opsNormaliseMoney(\GraystackIT\MollieBilling\Support\BillingMoney::format($minor)),
        );
    }

    /**
     * Fetch a URL with the browser's own session and return status, content type and the
     * first bytes — a download cannot be "seen", but it can be asked for by the very
     * session that sees the link.
     *
     * @return array{status:int, type:string, head:string, length:int}
     */
    protected function opsFetch(Browser $browser, string $url): array
    {
        $script = <<<'JS'
            const done = arguments[arguments.length - 1];
            fetch(arguments[0], {credentials: 'same-origin'})
                .then(async (response) => {
                    const buffer = new Uint8Array(await response.arrayBuffer());
                    done({
                        status: response.status,
                        type: response.headers.get('content-type') || '',
                        head: String.fromCharCode(...buffer.slice(0, 8)),
                        length: buffer.length,
                    });
                })
                .catch((error) => done({status: 0, type: String(error), head: '', length: 0}));
        JS;

        // Same origin as the page, whatever host `route()` printed in this process.
        $parts = parse_url($url);
        $url = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        $browser->driver->manage()->timeouts()->setScriptTimeout(20);

        /** @var array{status:int, type:string, head:string, length:int} $result */
        $result = $browser->driver->executeAsyncScript($script, [$url]);

        return $result;
    }
}
