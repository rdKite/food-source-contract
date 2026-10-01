<?php

declare(strict_types=1);

namespace FoodSourceContract\Conformance;

use FoodSourceContract\Contract;
use FoodSourceContract\Http\Response;
use FoodSourceContract\Http\Transport;
use FoodSourceContract\NutrientRegistry;
use FoodSourceContract\SchemaValidator;
use Throwable;

/**
 * Checks a food source against the contract, requirement by requirement.
 *
 * Every requirement has an id (CAP-1, FOOD-3, …) so a failing service can be
 * told exactly what to fix. The suite needs one search text that finds foods
 * in the source; everything else it discovers.
 */
final class Suite
{
    /** @var list<Result> */
    private array $results = [];

    /** @var array<string, mixed>|null */
    private ?array $capabilities = null;

    /** @var list<array<string, mixed>> */
    private array $hits = [];

    /** @var list<string> Responses without a valid Food-Source-Contract header. */
    private array $headerViolations = [];

    private SchemaValidator $schemas;

    private NutrientRegistry $registry;

    public function __construct(
        private readonly Transport $transport,
        private readonly string $query,
    ) {
        $this->schemas = new SchemaValidator;
        $this->registry = NutrientRegistry::load();
    }

    /** @return list<Result> */
    public function run(): array
    {
        $this->results = [];
        $this->headerViolations = [];

        $this->capabilitiesChecks();
        $this->searchChecks();
        $this->foodChecks();
        $this->batchChecks();

        if ($this->capabilities !== null) {
            $this->record('CAP-2', 'every response carries Food-Source-Contract with major version 1', array_values(array_unique($this->headerViolations)));
        }

        usort($this->results, fn (Result $a, Result $b) => strnatcmp($a->id, $b->id));

        return $this->results;
    }

    // ---- /capabilities -------------------------------------------------

    private function capabilitiesChecks(): void
    {
        $response = $this->call('CAP-1', '/capabilities');
        if ($response === null) {
            return;
        }

        $data = $response->json();
        $errors = $response->status === 200 ? $this->schemas->errors($data, 'Capabilities') : ["HTTP {$response->status}"];
        $this->record('CAP-1', '/capabilities answers 200 with a valid document', $errors);

        if ($errors !== [] || ! is_array($data)) {
            return;
        }
        $this->capabilities = $data;

        /** @var list<string> $nutrients */
        $nutrients = $data['nutrients'];
        $unknown = array_values(array_filter($nutrients, fn (string $key) => ! $this->registry->has($key)));
        $this->record('CAP-3', 'declared nutrients are keys of the registry', $unknown === [] ? [] : ['unknown: '.implode(', ', $unknown)]);

        $source = $data['source'];
        $this->record(
            'CAP-4',
            'the default locale is one of the declared locales',
            in_array($source['default_locale'], $source['locales'], true) ? [] : ["{$source['default_locale']} not in locales"],
        );
    }

    // ---- /foods/search -------------------------------------------------

    private function searchChecks(): void
    {
        if ($this->capabilities === null) {
            return;
        }

        $response = $this->call('SRCH-1', '/foods/search', ['query' => $this->query]);
        if ($response !== null) {
            $data = $response->json();
            $errors = $response->status === 200 ? $this->schemas->errors($data, 'SearchResult') : ["HTTP {$response->status}"];
            if ($errors === [] && is_array($data)) {
                $errors = [...$errors, ...$this->versionErrors($data['version'])];
                /** @var list<array<string, mixed>> $results */
                $results = $data['results'];
                $this->hits = $results;
                if ($results === []) {
                    $errors[] = "no hits for \"{$this->query}\": pass a text that finds foods in this source";
                }
            }
            $this->record('SRCH-1', 'a search answers with valid hits in the declared data version', $errors);
        }

        $short = $this->call('SRCH-2', '/foods/search', ['query' => mb_substr($this->query, 0, 1)]);
        if ($short !== null) {
            $data = $short->json();
            $this->record('SRCH-2', 'a query shorter than two characters is an empty result, not an error',
                $short->status === 200 && is_array($data) && ($data['results'] ?? null) === [] ? [] : ["HTTP {$short->status}, ".substr($short->body, 0, 80)]);
        }

        $limited = $this->call('SRCH-3', '/foods/search', ['query' => $this->query, 'limit' => '1']);
        if ($limited !== null) {
            $data = $limited->json();
            $this->record('SRCH-3', 'limit caps the number of hits',
                $limited->status === 200 && is_array($data) && count((array) ($data['results'] ?? [])) <= 1 ? [] : ["HTTP {$limited->status}"]);
        }

        $unicode = $this->call('SRCH-4', '/foods/search', ['query' => 'Möhre ÄÖÜß 食品']);
        if ($unicode !== null) {
            $this->record('SRCH-4', 'a non-ASCII query is answered, not rejected',
                $unicode->status === 200 && $this->schemas->errors($unicode->json(), 'SearchResult') === [] ? [] : ["HTTP {$unicode->status}"]);
        }

        $long = $this->call('SRCH-5', '/foods/search', ['query' => str_repeat('Vollkornbrot ', 100)]);
        if ($long !== null) {
            $errors = in_array($long->status, [200, 400], true) ? [] : ["HTTP {$long->status}"];
            if ($long->status === 400) {
                $errors = [...$errors, ...$this->errorBodyErrors($long, 'bad_request')];
            }
            $this->record('SRCH-5', 'a very long query is answered or rejected with 400, never a server error', $errors);
        }
    }

    // ---- /foods/{id} ---------------------------------------------------

    private function foodChecks(): void
    {
        $capabilities = $this->capabilities;
        if ($capabilities === null) {
            return;
        }

        if ($this->hits === []) {
            $this->skip('FOOD-1', 'the foods of the hits', 'no hits to look up (see SRCH-1)');
        } else {
            /** @var list<string> $declared */
            $declared = $capabilities['nutrients'];
            $errors = [];

            foreach (array_slice($this->hits, 0, 5) as $hit) {
                $id = (string) $hit['external_id'];
                $response = $this->get('/foods/'.rawurlencode($id));
                $food = $response->json();

                if ($response->status !== 200) {
                    $errors[] = "{$id}: HTTP {$response->status}";

                    continue;
                }
                foreach ($this->schemas->errors($food, 'Food') as $error) {
                    $errors[] = "{$id}: {$error}";
                }
                if (! is_array($food)) {
                    continue;
                }
                if ($food['external_id'] !== $id) {
                    $errors[] = "{$id}: answers for {$food['external_id']}";
                }
                foreach ([...$this->versionErrors($food['version']), ...$this->revisionErrors($food)] as $error) {
                    $errors[] = "{$id}: {$error}";
                }
                foreach (array_keys((array) $food['nutrients']) as $key) {
                    if (! in_array($key, $declared, true)) {
                        $errors[] = "{$id}: nutrient {$key} not declared in /capabilities";
                    }
                }
                $defaults = array_filter((array) ($food['portions'] ?? []), fn ($p) => is_array($p) && ($p['default'] ?? false) === true);
                if (count($defaults) > 1) {
                    $errors[] = "{$id}: more than one default portion";
                }
                if (isset($food['portions']) && ! in_array('portions', (array) ($capabilities['features'] ?? []), true)) {
                    $errors[] = "{$id}: portions without the feature \"portions\" in /capabilities";
                }
            }

            $this->record('FOOD-1', 'the foods of the hits are valid, in the declared version and nutrients', $errors);
        }

        // An unsupported locale falls back to the default.
        if ($this->hits !== []) {
            $id = (string) $this->hits[0]['external_id'];
            $response = $this->get('/foods/'.rawurlencode($id), ['locale' => 'zz']);
            $food = $response->json();
            /** @var list<string> $locales */
            $locales = $capabilities['source']['locales'];
            $this->record('FOOD-2', 'an unsupported locale falls back to a declared one',
                $response->status === 200 && is_array($food) && in_array($food['locale'] ?? null, $locales, true)
                    ? [] : ["HTTP {$response->status}, locale ".json_encode(is_array($food) ? ($food['locale'] ?? null) : null)]);
        }

        $unknown = $this->get('/foods/'.rawurlencode('conformance-unknown-0000'));
        $this->record('FOOD-3', 'an unknown id is 404 with the error code not_found',
            $unknown->status === 404 ? $this->errorBodyErrors($unknown, 'not_found') : ["HTTP {$unknown->status}"]);

        $odd = $this->get('/foods/'.rawurlencode('a/b c?ä'));
        $this->record('FOOD-4', 'an id with reserved characters is a 404, not a server error',
            $odd->status === 404 ? [] : ["HTTP {$odd->status}"]);
    }

    // ---- /foods?ids= (feature batch) -----------------------------------

    private function batchChecks(): void
    {
        if ($this->capabilities === null) {
            return;
        }

        if (! in_array('batch', (array) ($this->capabilities['features'] ?? []), true)) {
            $this->skip('BATCH-1', 'batch lookup', 'feature "batch" not declared');
            $this->skip('BATCH-2', 'batch limit', 'feature "batch" not declared');

            return;
        }

        $known = array_map(fn ($hit) => (string) $hit['external_id'], array_slice($this->hits, 0, 2));
        $ids = [...$known, 'conformance-unknown-0000'];
        $response = $this->get('/foods', ['ids' => implode(',', $ids)]);
        $data = $response->json();
        $errors = $response->status === 200 ? $this->schemas->errors($data, 'Batch') : ["HTTP {$response->status}"];

        if ($errors === [] && is_array($data)) {
            $found = array_map(fn ($food) => $food['external_id'], (array) $data['foods']);
            foreach ((array) $data['foods'] as $food) {
                foreach ($this->revisionErrors((array) $food) as $error) {
                    $errors[] = "{$food['external_id']}: {$error}";
                }
            }
            if (array_diff($known, $found) !== []) {
                $errors[] = 'known ids missing from foods: '.implode(', ', array_diff($known, $found));
            }
            if ($data['missing'] !== ['conformance-unknown-0000']) {
                $errors[] = 'missing should list exactly the unknown id, got '.json_encode($data['missing']);
            }
        }
        $this->record('BATCH-1', 'known ids come back as foods, unknown ones in missing', $errors);

        $tooMany = $this->get('/foods', ['ids' => implode(',', array_map(fn ($i) => "id{$i}", range(1, 101)))]);
        $this->record('BATCH-2', 'more than 100 ids is 400 bad_request',
            $tooMany->status === 400 ? $this->errorBodyErrors($tooMany, 'bad_request') : ["HTTP {$tooMany->status}"]);
    }

    // ---- helpers -------------------------------------------------------

    /**
     * @param  array<string, string>  $query
     */
    private function call(string $id, string $path, array $query = []): ?Response
    {
        try {
            return $this->get($path, $query);
        } catch (Throwable $e) {
            $this->record($id, "{$path} is reachable", [$e->getMessage()]);

            return null;
        }
    }

    /**
     * Every request goes through here, so CAP-2 sees every response.
     *
     * @param  array<string, string>  $query
     */
    private function get(string $path, array $query = []): Response
    {
        $response = $this->transport->get($path, $query);
        $version = $response->header('Food-Source-Contract');

        if ($version === null || preg_match('/^1\.\d+$/', $version) !== 1) {
            $this->headerViolations[] = "{$path}: ".json_encode($version);
        }

        return $response;
    }

    /** @return list<string> */
    private function versionErrors(mixed $version): array
    {
        $declared = $this->capabilities['source']['version'] ?? null;

        return $version === $declared ? [] : ['data version '.json_encode($version).' differs from /capabilities '.json_encode($declared)];
    }

    /**
     * 1.2: a record carries the curation revision of /capabilities, and none without one.
     *
     * @param  array<mixed>  $food
     * @return list<string>
     */
    private function revisionErrors(array $food): array
    {
        $declared = $this->capabilities['source']['revision'] ?? null;
        $revision = $food['revision'] ?? null;

        return $revision === $declared ? [] : ['revision '.json_encode($revision).' differs from /capabilities '.json_encode($declared)];
    }

    /** @return list<string> */
    private function errorBodyErrors(Response $response, string $code): array
    {
        $body = $response->json();
        $errors = $this->schemas->errors($body, 'Error');
        if ($errors === [] && is_array($body) && $body['error']['code'] !== $code) {
            $errors[] = "error code {$body['error']['code']}, expected {$code}";
        }

        return $errors;
    }

    /** @param list<string> $errors */
    private function record(string $id, string $requirement, array $errors): void
    {
        $this->results[] = new Result($id, $requirement, $errors === [] ? Outcome::Passed : Outcome::Failed, implode('; ', array_slice($errors, 0, 5)));
    }

    private function skip(string $id, string $requirement, string $why): void
    {
        $this->results[] = new Result($id, $requirement, Outcome::Skipped, $why);
    }
}
