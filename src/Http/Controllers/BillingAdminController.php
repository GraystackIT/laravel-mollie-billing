<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Http\Controllers;

use GraystackIT\MollieBilling\Support\FluxPro;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class BillingAdminController extends Controller
{
    public function show(Request $request, string $screen = 'dashboard'): Response|View
    {
        if (! FluxPro::isInstalled()) {
            return response()->view('mollie-billing::admin.flux-pro-missing', status: 503);
        }

        return view('mollie-billing::layouts.admin', [
            'livewireComponent' => 'mollie-billing::admin.'.$screen,
        ]);
    }
}
