<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

class FluxPro
{
    public static function isInstalled(): bool
    {
        return class_exists(\Flux\Flux::class)
            && class_exists(\Flux\FluxServiceProvider::class)
            && (
                class_exists(\Flux\ProBanner::class)
                || class_exists(\FluxPro\FluxProServiceProvider::class)
            );
    }
}
