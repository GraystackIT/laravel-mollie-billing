<?php

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    private function resolveBillable(): ?Billable
    {
        return MollieBilling::resolveBillable(request());
    }

    /**
     * Read gate for everything this page renders.
     *
     * The portal routes do carry `billing.portal`, and AuthorizeBillingPortal is
     * registered as Livewire persistent middleware so it re-runs on
     * `/livewire/update` too. This gate is defence-in-depth behind both, because a
     * component that answers questions about a tenant must not depend on its
     * mounting being right — it decides for itself who gets to read the tenant's invoice history and
     * coupon redemptions. Withholding the billable falls through to the same empty state
     * as "none resolved".
     */
    private function readableBillable(): ?Billable
    {
        $billable = $this->resolveBillable();

        return $billable !== null && MollieBilling::authorizes(request(), $billable)
            ? $billable
            : null;
    }

    public function with(): array
    {
        $billable = $this->readableBillable();

        if (! $billable) {
            return ['invoices' => null, 'stats' => null, 'billable' => null, 'redemptions' => collect()];
        }

        $invoices = $billable->billingInvoices()->latest()->paginate(20);

        $query = $billable->billingInvoices();

        // The headline figures are rendered with a single currency symbol, so they may
        // only add up rows that carry that currency. A billable charged in more than one
        // (a BILLING_CURRENCY change mid-life) otherwise had 20.00 GBP and 10.00 EUR
        // reported as "€30.00". Scoping to the configured currency rather than grouping
        // per currency keeps one set of numbers on the card row — the customer is billed
        // in the current currency from here on, and every historical row is still shown
        // with its own symbol in the table below.
        $currency = BillingMoney::normalizeCurrency(null);
        $inCurrency = fn () => $query->clone()->where('currency', $currency);

        // Brutto-Summen: Charge-Invoices (paid) tragen positives amount_gross,
        // Refund-Invoices (refunded) tragen negatives amount_gross.
        $paidGross = (int) $inCurrency()
            ->where('status', InvoiceStatus::Paid)
            ->where('amount_gross', '>', 0)
            ->sum('amount_gross');
        $refundGross = (int) $inCurrency()
            ->where('status', InvoiceStatus::Refunded)
            ->where('amount_gross', '<', 0)
            ->sum('amount_gross'); // negativ
        $spentGross = $paidGross + $refundGross; // Saldo = bezahlt − gutschriften

        $fmt = fn (int $cents): string => BillingMoney::format($cents, $currency);

        $stats = [
            // Counts every invoice regardless of currency — the table below lists them all.
            'total' => $query->count(),
            'spent' => $fmt($spentGross),
            'paid' => $fmt($paidGross),
            'credits' => $fmt(abs($refundGross)),
        ];

        /** @var Model $billableModel */
        $billableModel = $billable;
        $redemptions = CouponRedemption::query()
            ->with('coupon')
            ->where('billable_type', $billableModel->getMorphClass())
            ->where('billable_id', $billableModel->getKey())
            ->orderByDesc('applied_at')
            ->get();

        return [
            'invoices' => $invoices,
            'stats' => $stats,
            'billable' => $billable,
            'redemptions' => $redemptions,
        ];
    }
};

?>

<div class="space-y-6">
    <div>
        <flux:heading size="xl">{{ __('billing::portal.invoices') }}</flux:heading>
        <flux:subheading>{{ __('billing::portal.invoices_subtitle') }}</flux:subheading>
    </div>

    @if ((! $invoices || $invoices->isEmpty()) && $redemptions->isEmpty())
        <flux:callout variant="secondary" icon="document-text">
            {{ __('billing::portal.no_invoices') }}
        </flux:callout>
    @else
        <flux:tab.group>
            <flux:tabs>
                <flux:tab name="invoices" icon="document-text">{{ __('billing::portal.invoices') }}</flux:tab>
                @if ($redemptions->isNotEmpty())
                    <flux:tab name="redemptions" icon="ticket">{{ __('billing::portal.redemptions.tab') }}</flux:tab>
                @endif
            </flux:tabs>

            <flux:tab.panel name="invoices" class="space-y-6 pt-4">
                @if (! $invoices || $invoices->isEmpty())
                    <flux:callout variant="secondary" icon="document-text">
                        {{ __('billing::portal.no_invoices') }}
                    </flux:callout>
                @else
                    {{-- Summary stats --}}
                    @if ($stats)
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <flux:card class="p-5!">
                                <flux:subheading>{{ __('billing::portal.invoice.total_invoices') }}</flux:subheading>
                                <div class="mt-3">
                                    <span class="text-3xl font-bold tabular-nums tracking-tight">{{ $stats['total'] }}</span>
                                </div>
                            </flux:card>
                            <flux:card class="p-5!">
                                <flux:subheading>{{ __('billing::portal.invoice.total_spent') }}</flux:subheading>
                                <div class="mt-3">
                                    <span dusk="stats-spent" class="text-3xl font-bold tabular-nums tracking-tight">{{ $stats['spent'] }}</span>
                                </div>
                            </flux:card>
                            <flux:card class="p-5!">
                                <flux:subheading>{{ __('billing::portal.invoice.total_paid') }}</flux:subheading>
                                <div class="mt-3">
                                    <span dusk="stats-paid" class="text-3xl font-bold tabular-nums tracking-tight text-emerald-600 dark:text-emerald-400">{{ $stats['paid'] }}</span>
                                </div>
                            </flux:card>
                            <flux:card class="p-5!">
                                <flux:subheading>{{ __('billing::portal.invoice.total_credits') }}</flux:subheading>
                                <div class="mt-3">
                                    <span dusk="stats-credits" class="text-3xl font-bold tabular-nums tracking-tight">{{ $stats['credits'] }}</span>
                                </div>
                            </flux:card>
                        </div>
                    @endif

                    {{-- Invoice table --}}
                    <flux:card class="py-0! overflow-hidden">
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>{{ __('billing::portal.invoice.date') }}</flux:table.column>
                                <flux:table.column>{{ __('billing::portal.invoice.kind') }}</flux:table.column>
                                <flux:table.column align="end">{{ __('billing::portal.invoice.net') }}</flux:table.column>
                                <flux:table.column align="end">{{ __('billing::portal.invoice.vat') }}</flux:table.column>
                                <flux:table.column align="end">{{ __('billing::portal.invoice.gross') }}</flux:table.column>
                                <flux:table.column>{{ __('billing::portal.invoice.status') }}</flux:table.column>
                                <flux:table.column align="end"></flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @foreach ($invoices as $invoice)
                                    <flux:table.row dusk="invoice-row-{{ $invoice->id }}">
                                        <flux:table.cell class="tabular-nums">{{ BillingTime::display($invoice->created_at, $billable)->translatedFormat('d. M Y') }}</flux:table.cell>
                                        <flux:table.cell>
                                            <flux:badge size="sm" :color="$invoice->invoice_kind?->color() ?? 'zinc'">{{ $invoice->invoice_kind?->label() ?? '—' }}</flux:badge>
                                            @if (\GraystackIT\MollieBilling\Services\Billing\UndercollectionCharge::isBalanceInvoice($invoice))
                                                {{-- A balance collected after a renewal that was too low: named, and with the
                                                     reason its lines carry (one line per seat/addon component, each with the
                                                     same `reason_label`; the plan balance's single line states it as its label). --}}
                                                <div class="mt-1" dusk="invoice-{{ $invoice->id }}-balance">
                                                    <flux:text size="sm" class="font-medium">{{ __('billing::portal.balance_charge.invoice_label') }}</flux:text>
                                                    <flux:text size="xs" class="text-zinc-500">{{ $invoice->line_items[0]['reason_label'] ?? $invoice->line_items[0]['label'] ?? '' }}</flux:text>
                                                </div>
                                            @endif
                                        </flux:table.cell>
                                        <flux:table.cell class="text-right tabular-nums"><span dusk="invoice-{{ $invoice->id }}-net">{{ BillingMoney::format($invoice->amount_net, $invoice->currency) }}</span></flux:table.cell>
                                        <flux:table.cell class="text-right tabular-nums text-zinc-400"><span dusk="invoice-{{ $invoice->id }}-vat">{{ BillingMoney::format($invoice->amount_vat, $invoice->currency) }}</span></flux:table.cell>
                                        <flux:table.cell class="text-right tabular-nums font-medium"><span dusk="invoice-{{ $invoice->id }}-gross">{{ BillingMoney::format($invoice->amount_gross, $invoice->currency) }}</span></flux:table.cell>
                                        <flux:table.cell>
                                            <flux:badge size="sm" :color="$invoice->status->color()">{{ $invoice->status->label() }}</flux:badge>
                                        </flux:table.cell>
                                        <flux:table.cell class="text-right">
                                            @if ($invoice->hasPdf())
                                                <flux:button size="xs" variant="ghost" icon="arrow-down-tray" href="{{ $invoice->getDownloadUrl() }}" target="_blank">
                                                    {{ __('billing::portal.invoice.pdf') }}
                                                </flux:button>
                                            @endif
                                        </flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </flux:card>

                    @if ($invoices->hasPages())
                        <div>{{ $invoices->links() }}</div>
                    @endif
                @endif
            </flux:tab.panel>

            @if ($redemptions->isNotEmpty())
                <flux:tab.panel name="redemptions" class="pt-4">
                    <flux:card class="py-0! overflow-hidden">
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>{{ __('billing::portal.redemptions.applied_at') }}</flux:table.column>
                                <flux:table.column>{{ __('billing::portal.redemptions.code') }}</flux:table.column>
                                <flux:table.column>{{ __('billing::portal.redemptions.type') }}</flux:table.column>
                                <flux:table.column>{{ __('billing::portal.redemptions.benefit') }}</flux:table.column>
                                <flux:table.column>{{ __('billing::portal.redemptions.status') }}</flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @foreach ($redemptions as $redemption)
                                    @php
                                        $coupon = $redemption->coupon;
                                        $benefit = match (true) {
                                            $redemption->discount_amount_net > 0
                                                => BillingMoney::format($redemption->discount_amount_net, $coupon?->currency),
                                            ($redemption->trial_days_added ?? 0) > 0
                                                => trans_choice('billing::portal.redemptions.benefit_days', (int) $redemption->trial_days_added, ['days' => $redemption->trial_days_added]),
                                            ($redemption->grant_days_added ?? 0) > 0
                                                => trans_choice('billing::portal.redemptions.benefit_days', (int) $redemption->grant_days_added, ['days' => $redemption->grant_days_added]),
                                            ! empty($redemption->credits_applied)
                                                => __('billing::portal.redemptions.benefit_credits'),
                                            default => '—',
                                        };
                                    @endphp
                                    <flux:table.row>
                                        <flux:table.cell class="tabular-nums">{{ BillingTime::display($redemption->applied_at, $billable)->translatedFormat('d. M Y') }}</flux:table.cell>
                                        <flux:table.cell class="font-mono text-sm">{{ $coupon?->code ?? '—' }}</flux:table.cell>
                                        <flux:table.cell>
                                            @if ($coupon?->type)
                                                <flux:badge size="sm" :color="$coupon->type->color()">{{ $coupon->type->label() }}</flux:badge>
                                            @else
                                                —
                                            @endif
                                        </flux:table.cell>
                                        <flux:table.cell class="tabular-nums">{{ $benefit }}</flux:table.cell>
                                        <flux:table.cell>
                                            @if ($redemption->isRevoked())
                                                <flux:badge size="sm" color="red">{{ __('billing::portal.redemptions.status_revoked') }}</flux:badge>
                                            @else
                                                <flux:badge size="sm" color="lime">{{ __('billing::portal.redemptions.status_active') }}</flux:badge>
                                            @endif
                                        </flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </flux:card>
                </flux:tab.panel>
            @endif
        </flux:tab.group>
    @endif
</div>
