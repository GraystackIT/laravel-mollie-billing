<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Concerns;

/**
 * Flux Pro calls that survive Flux Pro being absent.
 *
 * `livewire/flux-pro` is a hard runtime dependency but ships under `suggest` because
 * it is commercial, so it is **not** installed in this package's own test environment.
 * A bare `\Flux::toast()` therefore fatals with `Class "Flux" not found` the moment a
 * test reaches it — which means every action that ends in a toast was unreachable
 * under test, and a regression in the work leading up to it went unnoticed because the
 * test died on the last line rather than on the assertion.
 *
 * Guarding at each of the 29 call sites was the alternative; one helper per operation
 * keeps the views readable and makes it impossible to add an unguarded call by
 * copying a neighbouring line.
 *
 * In production Flux is always present, so these are pass-throughs.
 */
trait InteractsWithFluxUi
{
    protected function toast(string $message, string $variant = 'success'): void
    {
        if (class_exists(\Flux::class)) {
            \Flux::toast($message, variant: $variant);
        }
    }

    protected function closeModal(string $name): void
    {
        if (class_exists(\Flux::class)) {
            \Flux::modal($name)->close();
        }
    }
}
