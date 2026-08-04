<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Support;

use Livewire\Component;

/**
 * Stand-in for an app-provided header component (e.g. a language switcher)
 * wired through config('mollie-billing.header_components').
 */
class HeaderProbeComponent extends Component
{
    public function render(): string
    {
        return '<div><span>header-probe-marker</span></div>';
    }
}
