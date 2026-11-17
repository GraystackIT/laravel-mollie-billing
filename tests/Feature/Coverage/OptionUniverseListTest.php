<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Tests\Support\OptionUniverse;

it('lists the options by kind (run this file to see the universe)', function (): void {
    $byKind = [];

    foreach (array_keys(OptionUniverse::all()) as $key) {
        $byKind[explode(':', $key, 2)[0]][] = $key;
    }

    if (getenv('BILLING_PRINT_OPTIONS')) {
        foreach ($byKind as $kind => $keys) {
            fwrite(STDERR, "\n## {$kind} (".count($keys).")\n".implode("\n", $keys)."\n");
        }
    }

    expect(array_keys($byKind))->toContain('enum', 'config', 'plan_config', 'coupon_field', 'action', 'screen', 'command', 'event', 'notification', 'job');
});
