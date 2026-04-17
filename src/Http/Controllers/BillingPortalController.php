<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Http\Controllers;

use GraystackIT\MollieBilling\Support\Sanitize;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class BillingPortalController extends Controller
{
    public function checkout(Request $request): View
    {
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

    public function return(Request $request): View
    {
        return $this->render('return');
    }

    private function render(string $screen): View
    {
        return view('mollie-billing::layouts.portal', [
            'livewireComponent' => 'mollie-billing::'.$screen,
        ]);
    }
}
