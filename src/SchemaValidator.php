<?php

declare(strict_types=1);

namespace FoodSourceContract;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;

/**
 * Validates data against a schema of spec/openapi.yaml (OpenAPI 3.1 schemas
 * are JSON Schema 2020-12). Used by the conformance suite and the tests.
 */
final class SchemaValidator
{
    private const string ID = 'https://food-source-contract.local/openapi.json';

    private Validator $validator;

    public function __construct()
    {
        $spec = Yaml::parseFile(Contract::specPath());
        $spec['$id'] = self::ID;
        $spec['$schema'] = 'https://json-schema.org/draft/2020-12/schema';

        $this->validator = new Validator;
        $this->validator->setMaxErrors(10);
        $this->validator->resolver()?->registerRaw(
            json_decode((string) json_encode($spec)),
            self::ID,
        );
    }

    /**
     * @return list<string> The errors; empty when the data conforms.
     */
    public function errors(mixed $data, string $schema): array
    {
        $result = $this->validator->validate(
            json_decode((string) json_encode($data)),
            self::ID.'#/components/schemas/'.$schema,
        );

        $error = $result->error();

        if ($error === null) {
            return [];
        }

        $errors = [];
        foreach ((new ErrorFormatter)->format($error) as $path => $messages) {
            foreach ($messages as $message) {
                $errors[] = "{$path}: {$message}";
            }
        }

        return $errors;
    }
}
