<?php

declare(strict_types=1);

namespace FoodSourceContract\Tests;

use FoodSourceContract\Contract;
use FoodSourceContract\SchemaValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class SpecTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $spec;

    protected function setUp(): void
    {
        $this->spec = Yaml::parseFile(Contract::specPath());
    }

    public function test_the_spec_and_the_package_carry_the_same_version(): void
    {
        $this->assertSame(Contract::VERSION, $this->spec['info']['version']);
    }

    public function test_the_statuses_match_the_package(): void
    {
        $statuses = $this->spec['components']['schemas']['NutrientValue']['properties']['status']['enum'];

        $this->assertSame(Contract::STATUSES, $statuses);
    }

    public function test_the_prose_lists_every_status(): void
    {
        $prose = (string) file_get_contents(Contract::root().'/CONTRACT.md');

        foreach (Contract::STATUSES as $status) {
            $this->assertStringContainsString("| `{$status}` |", $prose, $status);
        }
    }

    public function test_a_value_is_null_exactly_for_the_valueless_statuses(): void
    {
        $validator = new SchemaValidator;

        foreach (Contract::STATUSES as $status) {
            $valueless = in_array($status, Contract::VALUELESS_STATUSES, true);

            $this->assertSame([], $validator->errors(
                ['value' => $valueless ? null : 1.5, 'status' => $status],
                'NutrientValue',
            ), $status);
            $this->assertNotSame([], $validator->errors(
                ['value' => $valueless ? 1.5 : null, 'status' => $status],
                'NutrientValue',
            ), "{$status} with the wrong value kind must fail");
        }
    }

    public function test_a_recipe_record_carries_its_own_revision(): void
    {
        $validator = new SchemaValidator;
        $food = [
            'external_id' => '0190c0de-0000-7000-8000-000000000001', 'version' => '1', 'kind' => 'recipe',
            'name' => 'Linsen-Dal', 'locale' => 'de', 'base' => '100g',
            'nutrients' => ['energy' => ['value' => 110, 'status' => 'calculated']],
        ];
        $recipe = [
            'revision' => 3, 'url' => 'https://lori.example/recipes/1/linsen-dal', 'author' => '@mira',
            'published_at' => '2026-10-02T09:15:00+02:00', 'yield_portions' => 4, 'portion_weight_g' => 350,
            'weight_source' => 'measured', 'citations' => ['BLS 4.0'],
        ];

        $this->assertSame([], $validator->errors([...$food, 'recipe' => $recipe], 'Food'));
        $this->assertSame([], $validator->errors([...$food, 'recipe' => ['revision' => 1, 'url' => 'https://lori.example/r/1']], 'Food'), 'only revision and url are required');
        $this->assertNotSame([], $validator->errors([...$food, 'recipe' => [...$recipe, 'revision' => 0]], 'Food'));
        $this->assertNotSame([], $validator->errors([...$food, 'recipe' => [...$recipe, 'weight_source' => 'guessed']], 'Food'));
        $this->assertNotSame([], $validator->errors([...$food, 'recipe' => ['url' => 'https://lori.example/r/1']], 'Food'), 'revision is required');
    }

    public function test_coverage_belongs_to_a_lower_bound_only(): void
    {
        $validator = new SchemaValidator;

        $this->assertSame([], $validator->errors(['value' => 2, 'status' => 'partial', 'coverage' => 0.9], 'NutrientValue'));
        $this->assertNotSame([], $validator->errors(['value' => 2, 'status' => 'measured', 'coverage' => 0.9], 'NutrientValue'));
    }
}
