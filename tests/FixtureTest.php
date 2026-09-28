<?php

declare(strict_types=1);

namespace FoodSourceContract\Tests;

use FoodSourceContract\Contract;
use FoodSourceContract\NutrientRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FixtureTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(Contract::fixturePath()), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_its_capabilities_conform(): void
    {
        $fixture = self::fixture();
        unset($fixture['foods'], $fixture['_comment']);

        $this->assertSame([], (new SchemaValidator)->errors($fixture, 'Capabilities'));
        $this->assertSame(Contract::VERSION, $fixture['contract_version']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function foods(): iterable
    {
        foreach (self::fixture()['foods'] as $food) {
            yield $food['external_id'] => [$food];
        }
    }

    /** @param array<string, mixed> $food */
    #[DataProvider('foods')]
    public function test_each_food_conforms(array $food): void
    {
        $fixture = self::fixture();
        $registry = NutrientRegistry::load();

        $this->assertSame([], (new SchemaValidator)->errors($food, 'Food'));
        $this->assertSame($fixture['source']['version'], $food['version']);

        foreach (array_keys($food['nutrients']) as $key) {
            $this->assertTrue($registry->has((string) $key), "unknown key {$key}");
            $this->assertContains($key, $fixture['nutrients'], "{$key} not declared in capabilities");
        }

        $defaults = array_filter($food['portions'] ?? [], fn ($p) => ($p['default'] ?? false) === true);
        $this->assertLessThanOrEqual(1, count($defaults), 'at most one default portion');
    }

    /** @param array<string, mixed> $food */
    #[DataProvider('foods')]
    public function test_energy_follows_from_the_macronutrients(array $food): void
    {
        // Illustrative data stays internally consistent (Atwater 9/4/4).
        $n = $food['nutrients'];
        $known = fn (string $k) => isset($n[$k]['value']) && is_numeric($n[$k]['value']);

        if (! $known('energy') || ! $known('fat') || ! $known('carbohydrate') || ! $known('protein')) {
            $this->addToAssertionCount(1);

            return;
        }

        $atwater = 9 * $n['fat']['value'] + 4 * $n['carbohydrate']['value'] + 4 * $n['protein']['value'];
        $this->assertEqualsWithDelta($atwater, $n['energy']['value'], max(3, $atwater * 0.05), $food['name']);
    }
}
