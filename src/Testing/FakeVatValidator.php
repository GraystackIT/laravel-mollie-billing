<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Testing;

class FakeVatValidator
{
    public function __construct(private array &$validNumbers)
    {
    }

    public function validates(string $vatNumber): self
    {
        $this->validNumbers[] = strtoupper($vatNumber);
        return $this;
    }

    public function isValid(string $vatNumber): bool
    {
        return in_array(strtoupper($vatNumber), $this->validNumbers, true);
    }
}
