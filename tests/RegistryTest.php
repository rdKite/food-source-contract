<?php

declare(strict_types=1);

namespace FoodSourceContract\Tests;

use FoodSourceContract\Contract;
use FoodSourceContract\NutrientRegistry;
use PHPUnit\Framework\TestCase;

final class RegistryTest extends TestCase
{
    private NutrientRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = NutrientRegistry::load();
    }

    public function test_it_holds_the_138_components_of_bls_4_0(): void
    {
        $this->assertCount(138, $this->registry->all());
    }

    public function test_keys_are_unique_snake_case_and_codes_unique(): void
    {
        $keys = $this->registry->keys();
        $codes = array_map(fn ($n) => $n->eurofir, $this->registry->all());

        $this->assertSame($keys, array_unique($keys));
        $this->assertSame($codes, array_unique($codes));
        foreach ($keys as $key) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $key);
        }
    }

    public function test_the_draft_0_1_keys_keep_their_names_and_units(): void
    {
        // Consumers stored foods with these keys under draft 0.1.
        $expected = [
            'energy' => ['kcal', 'ENERCC'],
            'fat' => ['g', 'FAT'],
            'saturated_fat' => ['g', 'FASAT'],
            'carbohydrate' => ['g', 'CHO'],
            'sugars' => ['g', 'SUGAR'],
            'protein' => ['g', 'PROT625'],
            'salt' => ['g', 'NACL'],
        ];

        foreach ($expected as $key => [$unit, $code]) {
            $nutrient = $this->registry->get($key);
            $this->assertSame($unit, $nutrient->unit, $key);
            $this->assertSame($code, $nutrient->eurofir, $key);
        }
    }

    public function test_every_nutrient_has_a_known_group_unit_and_both_names(): void
    {
        $groups = $this->registry->groups();

        foreach ($this->registry->all() as $nutrient) {
            $this->assertContains($nutrient->group, $groups, $nutrient->key);
            $this->assertContains($nutrient->unit, ['kJ', 'kcal', 'g', 'mg', 'µg'], $nutrient->key);
            $this->assertNotSame('', $nutrient->names['de'] ?? '', $nutrient->key);
            $this->assertNotSame('', $nutrient->names['en'] ?? '', $nutrient->key);
        }

        foreach ($groups as $group) {
            $this->assertNotEmpty($this->registry->inGroup($group), "empty group {$group}");
        }
    }

    public function test_formulas_only_reference_components_of_the_registry(): void
    {
        foreach ($this->registry->all() as $nutrient) {
            if ($nutrient->formula === null) {
                continue;
            }

            preg_match_all('/([A-Z][A-Z0-9:]*)\[/', $nutrient->formula, $matches);
            foreach ($matches[1] as $code) {
                $this->assertNotNull(
                    $this->registry->byEurofir($code),
                    "{$nutrient->key}: formula names unknown component {$code}",
                );
            }
        }
    }

    public function test_the_registry_cites_its_source(): void
    {
        $data = json_decode((string) file_get_contents(Contract::registryPath()), true);

        $this->assertStringContainsString('DOI: 10.25826/Data20251217-134202-0', $data['source']['citation']);
        $this->assertSame('CC BY 4.0', $data['source']['licence']);
    }
}
