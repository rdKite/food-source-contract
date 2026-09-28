<?php

declare(strict_types=1);

namespace FoodSourceContract;

use InvalidArgumentException;

/**
 * The canonical nutrients (registry/nutrients.json), in registry order.
 */
final class NutrientRegistry
{
    /** @var array<string, Nutrient> */
    private array $byKey = [];

    /** @var list<string> */
    private array $groups;

    /**
     * @param  array{groups: list<string>, nutrients: list<array{key: string, eurofir: string, unit: string, group: string, names: array<string, string>, formula?: string}>}  $data
     */
    public function __construct(array $data)
    {
        $this->groups = $data['groups'];

        foreach ($data['nutrients'] as $row) {
            $this->byKey[$row['key']] = new Nutrient(
                $row['key'],
                $row['eurofir'],
                $row['unit'],
                $row['group'],
                $row['names'],
                $row['formula'] ?? null,
            );
        }
    }

    public static function load(?string $path = null): self
    {
        $json = file_get_contents($path ?? Contract::registryPath());

        if ($json === false) {
            throw new InvalidArgumentException('The nutrient registry cannot be read.');
        }

        /** @var array{groups: list<string>, nutrients: list<array{key: string, eurofir: string, unit: string, group: string, names: array<string, string>, formula?: string}>} $data */
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return new self($data);
    }

    /** @return list<Nutrient> */
    public function all(): array
    {
        return array_values($this->byKey);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->byKey);
    }

    public function has(string $key): bool
    {
        return isset($this->byKey[$key]);
    }

    public function get(string $key): Nutrient
    {
        return $this->byKey[$key] ?? throw new InvalidArgumentException("Unknown nutrient key: {$key}");
    }

    public function byEurofir(string $code): ?Nutrient
    {
        foreach ($this->byKey as $nutrient) {
            if ($nutrient->eurofir === $code) {
                return $nutrient;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function groups(): array
    {
        return $this->groups;
    }

    /** @return list<Nutrient> */
    public function inGroup(string $group): array
    {
        return array_values(array_filter($this->byKey, fn (Nutrient $n) => $n->group === $group));
    }
}
