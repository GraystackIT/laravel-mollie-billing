<?php

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\StartOneTimeOrderCheckout;
use GraystackIT\MollieBilling\Support\BillingRoute;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Laravel\Facades\Mollie;

new class extends Component {
    /** Tick budget before we stop polling and show the timeout state. */
    private const MAX_POLL_TICKS = 22; // ~90s with the 2s/5s mix below

    /** First N ticks run at 2s; afterwards we slow down to 5s. */
    private const FAST_POLL_TICKS = 5;

    public bool $activated = false;
    public bool $failed = false;
    public bool $timedOut = false;
    /** No payment to report on (session lost, page opened directly). */
    public bool $unresolved = false;
    public ?string $failureReason = null;
    public ?string $origin = null;
    public int $pollCount = 0;

    /**
     * Which one-time-order checkout this page reports on. Captured on mount
     * because the poll requests hit Livewire's own endpoint, where the original
     * query string is gone. Locked so the browser cannot point the page at a
     * different entry of the session map.
     */
    #[Locked]
    public ?string $orderToken = null;

    public function mount(): void
    {
        $this->origin = request()->query('origin');
        $this->orderToken = request()->query(StartOneTimeOrderCheckout::ORDER_TOKEN_PARAM);

        // One-time orders: the order is settled by the webhook, but the return
        // page must still report the ACTUAL payment status — a cancelled payment
        // lands on this very URL and used to be greeted with "Payment received".
        if ($this->origin === 'products') {
            $this->evaluateOrderPayment();
            return;
        }

        $billable = MollieBilling::resolveBillable(request());

        if ($billable?->hasAccessibleBillingSubscription()) {
            $this->activated = true;
        }
    }

    public function checkStatus(): void
    {
        if ($this->activated || $this->failed || $this->unresolved) {
            return;
        }

        $this->pollCount++;

        if ($this->origin === 'products') {
            $this->evaluateOrderPayment();

            if (! $this->activated && ! $this->failed && $this->pollCount >= self::MAX_POLL_TICKS) {
                $this->timedOut = true;
            }

            return;
        }

        try {
            $billable = MollieBilling::resolveBillable(request());

            if ($billable === null) {
                if ($this->pollCount >= self::MAX_POLL_TICKS) {
                    $this->timedOut = true;
                }
                return;
            }

            /** @var \Illuminate\Database\Eloquent\Model&Billable $billable */
            $billable->refresh();

            if ($billable->hasAccessibleBillingSubscription()) {
                $this->activated = true;
                $this->timedOut = false;
                return;
            }

            // Subscription not active yet — ask Mollie directly whether the
            // first payment failed. The webhook may simply not have arrived
            // (Mollie can take seconds to minutes), but if the payment is
            // canceled/failed/expired we want to surface that immediately
            // instead of letting the user stare at a spinner.
            $this->probeMolliePaymentStatus($billable);

            if ($this->failed) {
                return;
            }

            if ($this->pollCount >= self::MAX_POLL_TICKS) {
                $this->timedOut = true;
            }
        } catch (\Throwable $e) {
            // Never let an exception break the polling cycle silently. We log
            // and let the next tick try again; if the timeout fires while
            // we're stuck in errors, the timed-out state at least gives the
            // user actionable choices.
            report($e);

            if ($this->pollCount >= self::MAX_POLL_TICKS) {
                $this->timedOut = true;
            }
        }
    }

    /**
     * Re-arm the poll loop after a timeout — useful if the user wants to give
     * the webhook another chance before contacting support.
     */
    public function retry(): void
    {
        $this->pollCount = 0;
        $this->timedOut = false;
        $this->failed = false;
        $this->failureReason = null;
    }

    /**
     * Resolve a one-time order's outcome from Mollie. Paid wins immediately (the
     * webhook writes invoice + credits right behind it), a terminal failure is
     * surfaced as such, and every non-terminal status keeps the poll loop running.
     *
     * The payment is resolved through this page's own token, so two checkouts
     * running in parallel each judge their own payment.
     */
    private function evaluateOrderPayment(): void
    {
        $paymentId = StartOneTimeOrderCheckout::pendingOrderPaymentId($this->orderToken);

        if ($paymentId === null) {
            // Nothing to check against — say so instead of claiming success.
            $this->unresolved = true;

            return;
        }

        try {
            $payment = Mollie::send(new GetPaymentRequest($paymentId));
        } catch (\Throwable $e) {
            Log::warning('Return-page one-time-order status probe failed', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $status = (string) ($payment->status ?? '');

        // Only `paid` counts. `authorized` means Mollie holds an authorisation it
        // has not captured yet, and the webhook creates the invoice and credits
        // the wallet on `paid` alone — announcing success here would promise
        // goods that nothing has booked. Treat it as pending and keep polling.
        if ($status === 'paid') {
            $this->activated = true;
            StartOneTimeOrderCheckout::forgetPendingOrder($this->orderToken);

            return;
        }

        if (in_array($status, ['failed', 'canceled', 'expired'], true)) {
            $this->failed = true;
            $this->failureReason = $status;
            StartOneTimeOrderCheckout::forgetPendingOrder($this->orderToken);
        }

        // open / pending / authorized → still in flight or the status lags. Poll.
    }

    private function probeMolliePaymentStatus(Billable $billable): void
    {
        $paymentId = $billable->getPendingFirstPaymentId();
        if ($paymentId === null) {
            return;
        }

        try {
            $payment = Mollie::send(new GetPaymentRequest($paymentId));
        } catch (\Throwable $e) {
            // Mollie API hiccup — don't fail the page over it; let the next
            // tick retry. The webhook will still resolve eventually.
            Log::warning('Return-page Mollie status probe failed', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $status = (string) ($payment->status ?? '');

        if (in_array($status, ['failed', 'canceled', 'expired'], true)) {
            $this->failed = true;
            $this->failureReason = $status;
            return;
        }

        // status === 'paid' but no active subscription yet means the webhook
        // is still en route. Keep polling.
    }

    public function returnUrl(): string
    {
        $billable = MollieBilling::resolveBillable(request());
        $params = $billable ? MollieBilling::resolveUrlParameters($billable) : [];

        if ($this->origin === 'products') {
            return route(BillingRoute::name('products'), $params);
        }

        $configured = config('mollie-billing.redirect_after_return');

        return $configured
            ? route($configured, $params)
            : route(BillingRoute::name('index'), $params);
    }

    public function checkoutUrl(): string
    {
        $billable = MollieBilling::resolveBillable(request());
        $params = $billable ? MollieBilling::resolveUrlParameters($billable) : [];

        return route(BillingRoute::name('checkout'), $params);
    }

    public function pollInterval(): string
    {
        return $this->pollCount < self::FAST_POLL_TICKS ? '2s' : '5s';
    }

    public function shouldPoll(): bool
    {
        return ! $this->activated && ! $this->failed && ! $this->timedOut && ! $this->unresolved;
    }

    /** Where "try again" leads: back to the product list, or into checkout. */
    public function retryUrl(): string
    {
        return $this->origin === 'products' ? $this->returnUrl() : $this->checkoutUrl();
    }
};

?>

<div class="space-y-6"
    @if($this->shouldPoll())
        wire:poll.{{ $this->pollInterval() }}="checkStatus"
    @endif
>
    <flux:card class="space-y-4 text-center">
        @if($activated)
            <flux:icon.check-circle class="mx-auto size-12 text-lime-500" />
            <flux:heading size="xl">{{ __('billing::portal.return.title') }}</flux:heading>
            <flux:text>{{ $origin === 'products' ? __('billing::portal.return.body_products') : __('billing::portal.return.body') }}</flux:text>
            <div class="flex justify-center gap-2">
                <flux:button variant="primary" href="{{ $this->returnUrl() }}">
                    {{ $origin === 'products' ? __('billing::portal.products.back_to_products') : __('billing::portal.return.to_dashboard') }}
                </flux:button>
            </div>
        @elseif($failed)
            <flux:icon.x-circle class="mx-auto size-12 text-red-500" />
            <flux:heading size="xl">{{ __('billing::portal.return.failed_title') }}</flux:heading>
            <flux:text>
                {{ __('billing::portal.return.failed_body_'.($failureReason ?? 'failed')) }}
            </flux:text>
            <div class="flex justify-center gap-2">
                <flux:button variant="primary" href="{{ $this->retryUrl() }}">
                    {{ $origin === 'products' ? __('billing::portal.products.back_to_products') : __('billing::portal.return.try_again') }}
                </flux:button>
            </div>
        @elseif($unresolved)
            <flux:icon.question-mark-circle class="mx-auto size-12 text-zinc-400" />
            <flux:heading size="xl">{{ __('billing::portal.return.unresolved_title') }}</flux:heading>
            <flux:text>{{ __('billing::portal.return.unresolved_body') }}</flux:text>
            <div class="flex justify-center gap-2">
                <flux:button variant="primary" href="{{ $this->returnUrl() }}">
                    {{ $origin === 'products' ? __('billing::portal.products.back_to_products') : __('billing::portal.return.to_dashboard') }}
                </flux:button>
            </div>
        @elseif($timedOut)
            <flux:icon.clock class="mx-auto size-12 text-amber-500" />
            <flux:heading size="xl">{{ __('billing::portal.return.timeout_title') }}</flux:heading>
            <flux:text>{{ $origin === 'products' ? __('billing::portal.return.timeout_body_products') : __('billing::portal.return.timeout_body') }}</flux:text>
            <div class="flex justify-center gap-2">
                <flux:button variant="primary" wire:click="retry">
                    {{ __('billing::portal.return.check_again') }}
                </flux:button>
                @if ($origin === 'products')
                    <flux:button variant="ghost" href="{{ $this->returnUrl() }}">
                        {{ __('billing::portal.products.back_to_products') }}
                    </flux:button>
                @elseif (Route::has('logout'))
                    <flux:button variant="ghost"
                        x-on:click.prevent="$refs.logoutForm.submit()"
                    >
                        {{ __('billing::portal.return.logout') }}
                    </flux:button>
                @endif
            </div>
            @if (Route::has('logout'))
                <form x-ref="logoutForm" method="POST" action="{{ route('logout') }}" class="hidden">
                    @csrf
                </form>
            @endif
        @else
            <div class="flex justify-center">
                <flux:icon.arrow-path class="size-12 text-zinc-400 animate-spin" />
            </div>
            <flux:heading size="xl">{{ $origin === 'products' ? __('billing::portal.return.processing_title_products') : __('billing::portal.return.processing_title') }}</flux:heading>
            <flux:text>{{ __('billing::portal.return.processing_body') }}</flux:text>
        @endif
    </flux:card>
</div>
