<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Commands;

use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Testing\LifecycleSimulator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

class WebhookReplayCommand extends Command
{
    protected $signature = 'billing:webhook-replay
        {paymentId : Mollie payment id (e.g. tr_xxxxx) to replay through the webhook controller}
        {--force-reset : Also delete the existing invoice so the handler reprocesses fully}';

    protected $description = 'Replay a Mollie payment through the billing webhook handler (non-production only).';

    public function handle(Application $app, LifecycleSimulator $sim): int
    {
        if ($app->environment('production')) {
            $this->error('billing:webhook-replay is disabled in production.');
            return self::FAILURE;
        }

        $paymentId = (string) $this->argument('paymentId');
        $forceReset = (bool) $this->option('force-reset');

        if ($forceReset) {
            $invoice = BillingInvoice::query()
                ->where('mollie_payment_id', $paymentId)
                ->first();
            if ($invoice !== null) {
                $this->warn("Force-reset: deleting invoice #{$invoice->id} for payment {$paymentId}.");
            }
        }

        try {
            $sim->replayWebhook($paymentId, $forceReset);
        } catch (Throwable $e) {
            $this->error("Replay failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        $invoice = BillingInvoice::query()
            ->where('mollie_payment_id', $paymentId)
            ->first();

        if ($invoice !== null) {
            $this->info("Webhook replayed. Invoice #{$invoice->id} present for payment {$paymentId}.");
        } else {
            $this->warn("Webhook replayed, but no invoice exists for payment {$paymentId} (handler may have short-circuited).");
        }

        return self::SUCCESS;
    }
}
