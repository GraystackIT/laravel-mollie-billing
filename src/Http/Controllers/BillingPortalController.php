<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class BillingPortalController extends Controller
{
    public function index(Request $request): View
    {
        return view('mollie-billing::livewire.billing.dashboard');
    }

    public function checkout(Request $request): View
    {
        return view('mollie-billing::livewire.billing.checkout');
    }

    public function plan(Request $request): View
    {
        return view('mollie-billing::livewire.billing.plan');
    }

    public function invoices(Request $request): View
    {
        return view('mollie-billing::livewire.billing.invoices');
    }

    public function return(Request $request): View
    {
        return view('mollie-billing::livewire.billing.return');
    }
}
