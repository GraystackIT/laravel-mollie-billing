<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions\Concerns;

/**
 * The implementation of {@see \GraystackIT\MollieBilling\Exceptions\TranslatableException}.
 *
 * An exception whose sentence is always the same names it in {@see defaultTranslationKey()};
 * one that is thrown for different reasons (an invalid state, an invalid refund target) is
 * given its sentence at the throw site:
 *
 *     throw (new InvalidSubscriptionStateException('There is no subscription to cancel.'))
 *         ->withTranslation('billing::exceptions.state.no_subscription_to_cancel');
 */
trait CarriesTranslation
{
    private ?string $translationKey = null;

    /** @var array<string, string|int|float> */
    private array $translationParameters = [];

    /**
     * @param  array<string, string|int|float>  $parameters
     */
    public function withTranslation(string $key, array $parameters = []): static
    {
        $this->translationKey = $key;
        $this->translationParameters = $parameters;

        return $this;
    }

    public function translationKey(): ?string
    {
        return $this->translationKey ?? $this->defaultTranslationKey();
    }

    /** @return array<string, string|int|float> */
    public function translationParameters(): array
    {
        return $this->translationKey !== null ? $this->translationParameters : $this->defaultTranslationParameters();
    }

    protected function defaultTranslationKey(): ?string
    {
        return null;
    }

    /** @return array<string, string|int|float> */
    protected function defaultTranslationParameters(): array
    {
        return [];
    }
}
