<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class BillingAdminController extends Controller
{
    public function show(Request $request): View
    {
        $screen = $request->route()?->defaults['screen'] ?? 'dashboard';

        return view('mollie-billing::layouts.admin', [
            'livewireComponent' => 'mollie-billing::admin.'.$screen,
        ]);
    }
}
