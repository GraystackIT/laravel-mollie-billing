<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Workbench\App\FakeMollie\FakeMollie;
use Workbench\App\Models\AdminUser;

/*
 * Workbench-only routes. `/_wb/*` are the test rig's levers (switch the acting
 * billable, log the admin in, flip cross-process settings) — they are what a
 * seeded staging system would do by hand. `/fake-mollie/*` is the hosted-payment
 * page of the fake Mollie: the browser genuinely leaves the app, "pays", the
 * webhook fires, and the redirect brings it back.
 */

Route::get('/', function () {
    return view('wb::home', [
        'billables' => TestBillable::query()->orderBy('id')->get(),
        'actingAs' => session('wb_billable'),
        'admin' => Auth::user(),
    ]);
})->name('wb.home');

Route::get('/_wb/act-as/{billable}', function (int $billable) {
    abort_unless(TestBillable::whereKey($billable)->exists(), 404);
    session(['wb_billable' => $billable]);

    return redirect()->route('billing.index');
})->name('wb.act-as');

Route::get('/_wb/admin-login', function () {
    $admin = AdminUser::query()->firstOrCreate(
        ['email' => 'operator@workbench.test'],
        ['name' => 'Olga Operator', 'password' => bcrypt('secret')],
    );

    Auth::login($admin);

    return redirect()->route('billing.admin.dashboard');
})->name('wb.admin-login');

// What the SERVER believes about this browser session — the suite's first stop
// whenever "acting as" seems not to stick.
Route::get('/_wb/whoami', function (Request $request) {
    return response()->json([
        'session_billable' => session('wb_billable'),
        'resolved_billable' => \GraystackIT\MollieBilling\MollieBilling::resolveBillable($request)?->getKey(),
        'admin' => Auth::id(),
        'session_id' => session()->getId(),
    ]);
})->name('wb.whoami');

// What time the SERVER thinks it is — the shared clock (`SharedClock`) as the Dusk server
// applies it, and the suite's check that a test left it on the machine's time.
Route::get('/_wb/clock', fn () => response()->json([
    'now' => now('UTC')->toIso8601String(),
    'travelled' => \Illuminate\Support\Carbon::hasTestNow(),
]))->name('wb.clock');

Route::get('/_wb/logout', function (Request $request) {
    Auth::logout();
    $request->session()->flush();

    return redirect()->route('wb.home');
})->name('wb.logout');

// A page of "the application" behind the package's subscription gate — what a consuming
// app protects with `billing.active`. The portal is exempt from that middleware, so
// without this the suite had nothing to prove the gate against.
Route::get('/_wb/app', fn () => response('APP-AREA-OK'))->middleware('billing.active')->name('wb.app');

// ── Fake Mollie hosted checkout ─────────────────────────────────────────────

Route::get('/fake-mollie/checkout/{payment}', function (string $payment) {
    return view('wb::fake-mollie.checkout', ['payment' => FakeMollie::payment($payment)]);
})->name('fake-mollie.checkout');

Route::post('/fake-mollie/checkout/{payment}', function (Request $request, string $payment) {
    $outcome = (string) $request->input('outcome', 'paid');
    $country = strtoupper((string) $request->input('country', 'DE'));

    FakeMollie::settle($payment, $outcome, $country);

    // Real Mollie calls the webhook before (and independently of) the customer
    // returning. Single-process PHP server → deliver inline, then redirect the
    // browser wherever the checkout said customers should land.
    FakeMollie::deliverWebhook($payment);

    $redirect = FakeMollie::payment($payment)['redirect_url'] ?? route('wb.home');

    return redirect()->away($redirect ?: route('wb.home'));
})->name('fake-mollie.settle');
