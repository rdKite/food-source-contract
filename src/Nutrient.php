<?php

declare(strict_types=1);

namespace FoodSourceContract;

/**
 * One canonical nutrient of the registry.
 */
final readonly class Nutrient
{
    /**
     * @param  array<string, string>  $names  Locale => name
     */
    public function __construct(
        public string $key,
        public string $eurofir,
        public string $unit,
        public string $group,
        public array $names,
        public ?string $formula = null,
    ) {}

    public function name(string $locale): string
    {
        return $this->names[$locale] ?? $this->names['en'] ?? $this->key;
    }
}
