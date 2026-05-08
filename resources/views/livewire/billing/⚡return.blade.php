<?php

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Support\BillingRoute;
use Illuminate\Support\Facades\Log;
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
    public ?string $failureReason = null;
    public ?string $origin = null;
    public int $pollCount = 0;

    public function mount(): void
    {
        $this->origin = request()->query('origin');

        $billable = MollieBilling::resolveBillable(request());

        // One-time order returns don't need subscription polling — the payment
        // is a oneoff and the webhook handles everything asynchronously.
        if ($this->origin === 'products') {
            $this->activated = true;
            return;
        }

        if ($billable?->hasAccessibleBillingSubscription()) {
            $this->activated = true;
        }
    }

    public function checkStatus(): void
    {
        if ($this->activated || $this->failed) {
            return;
        }

        $this->pollCount++;

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
        return ! $this->activated && ! $this->failed && ! $this->timedOut;
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
            <flux:text>{{ __('billing::portal.return.body') }}</flux:text>
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
                <flux:button variant="primary" href="{{ $this->checkoutUrl() }}">
                    {{ __('billing::portal.return.try_again') }}
                </flux:button>
            </div>
        @elseif($timedOut)
            <flux:icon.clock class="mx-auto size-12 text-amber-500" />
            <flux:heading size="xl">{{ __('billing::portal.return.timeout_title') }}</flux:heading>
            <flux:text>{{ __('billing::portal.return.timeout_body') }}</flux:text>
            <div class="flex justify-center gap-2">
                <flux:button variant="primary" wire:click="retry">
                    {{ __('billing::portal.return.check_again') }}
                </flux:button>
                <flux:button variant="ghost" href="{{ $this->returnUrl() }}">
                    {{ __('billing::portal.return.to_dashboard') }}
                </flux:button>
            </div>
        @else
            <div class="flex justify-center">
                <flux:icon.arrow-path class="size-12 text-zinc-400 animate-spin" />
            </div>
            <flux:heading size="xl">{{ __('billing::portal.return.processing_title') }}</flux:heading>
            <flux:text>{{ __('billing::portal.return.processing_body') }}</flux:text>
        @endif
    </flux:card>
</div>
