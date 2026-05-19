<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Http\Controllers;

use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionGate;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Support\Sanitize;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class BillingPortalController extends Controller
{
    public function checkout(Request $request, MollieSubscriptionGate $mollieGate): View|RedirectResponse
    {
        $billable = MollieBilling::resolveBillable($request);

        // Local PastDue + still-live Mollie subscription can coexist (e.g. trial
        // expired before Mollie's first charge fell due). Re-entering checkout
        // would 422 on CreateSubscriptionRequest with "same description already
        // exists"; redirect to the dashboard instead so the user sees the
        // upcoming-charge state.
        if ($billable !== null && (
            $billable->hasAccessibleBillingSubscription()
            || $mollieGate->hasLiveMollieSubscription($billable)
        )) {
            return redirect()->route(
                BillingRoute::name('index'),
                MollieBilling::resolveUrlParameters($billable),
            );
        }

        return view('mollie-billing::layouts.checkout', [
            'livewireComponent' => 'mollie-billing::checkout',
            'backUrl' => Sanitize::backUrl($request->query('back')),
        ]);
    }

    public function index(Request $request): View
    {
        return $this->render('dashboard');
    }

    public function plan(Request $request): View
    {
        return $this->render('plan-change');
    }

    public function invoices(Request $request): View
    {
        return $this->render('invoices');
    }

    public function addons(Request $request): View
    {
        return $this->render('addons');
    }

    public function usage(Request $request): View
    {
        return $this->render('usage-history');
    }

    public function seats(Request $request): View
    {
        return $this->render('seats');
    }

    public function products(Request $request): View
    {
        return $this->render('products');
    }

    public function billingData(Request $request): View
    {
        return $this->render('billing-data');
    }

    public function return(Request $request): View
    {
        return view('mollie-billing::layouts.checkout', [
            'livewireComponent' => 'mollie-billing::return',
            'backUrl' => null,
        ]);
    }

    private function render(string $screen): View
    {
        return view('mollie-billing::layouts.portal', [
            'livewireComponent' => 'mollie-billing::'.$screen,
        ]);
    }
}
