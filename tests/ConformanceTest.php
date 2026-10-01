<?php

declare(strict_types=1);

namespace FoodSourceContract\Tests;

use Closure;
use FoodSourceContract\Conformance\Outcome;
use FoodSourceContract\Conformance\Result;
use FoodSourceContract\Conformance\Suite;
use FoodSourceContract\Contract;
use FoodSourceContract\Http\CallableTransport;
use FoodSourceContract\Http\Response;
use FoodSourceContract\Mock\FixtureSource;
use PHPUnit\Framework\TestCase;

/**
 * The suite checks itself: the reference mock passes every requirement, and a
 * mock broken in one way fails exactly the requirement that covers it.
 */
final class ConformanceTest extends TestCase
{
    /**
     * @param  Closure(string, array<string, string>): Response  $handler
     * @return array<string, Result>
     */
    private function check(Closure $handler, string $query = 'Hafer'): array
    {
        $results = (new Suite(new CallableTransport($handler), $query))->run();

        return array_column(array_map(fn (Result $r) => ['id' => $r->id, 'r' => $r], $results), 'r', 'id');
    }

    /**
     * @param  array<string, Result>  $results
     * @return list<string>
     */
    private function failed(array $results): array
    {
        return array_keys(array_filter($results, fn (Result $r) => $r->outcome === Outcome::Failed));
    }

    /**
     * The reference mock with one response altered.
     *
     * @param  Closure(Response, string, array<string, string>): Response  $alter
     */
    private function broken(Closure $alter): Closure
    {
        $mock = new FixtureSource;

        return fn (string $path, array $query) => $alter($mock->handle($path, $query), $path, $query);
    }

    /** @param array<mixed> $data */
    private function with(Response $original, array $data): Response
    {
        return new Response($original->status, $original->headers, (string) json_encode($data));
    }

    public function test_the_reference_mock_passes_every_requirement(): void
    {
        $results = $this->check((new FixtureSource)(...));

        $this->assertSame([], $this->failed($results), print_r(array_map(fn ($r) => $r->detail, $results), true));
        $this->assertSame(
            ['BATCH-1', 'BATCH-2', 'CAP-1', 'CAP-2', 'CAP-3', 'CAP-4', 'FOOD-1', 'FOOD-2', 'FOOD-3', 'FOOD-4', 'SRCH-1', 'SRCH-2', 'SRCH-3', 'SRCH-4', 'SRCH-5'],
            array_keys($results),
        );
    }

    public function test_a_missing_contract_header_fails_cap_2(): void
    {
        $results = $this->check($this->broken(fn (Response $r, string $path) => $path === '/foods/search'
            ? new Response($r->status, ['Content-Type' => 'application/json'], $r->body)
            : $r));

        $this->assertSame(['CAP-2'], $this->failed($results));
        $this->assertStringContainsString('/foods/search', $results['CAP-2']->detail);
    }

    public function test_an_unknown_nutrient_key_fails_cap_3(): void
    {
        $results = $this->check($this->broken(function (Response $r, string $path) {
            if ($path !== '/capabilities') {
                return $r;
            }
            $data = (array) $r->json();
            $data['nutrients'][] = 'vitamin_q';

            return $this->with($r, $data);
        }));

        $this->assertContains('CAP-3', $this->failed($results));
        $this->assertStringContainsString('vitamin_q', $results['CAP-3']->detail);
    }

    public function test_an_error_on_a_short_query_fails_srch_2(): void
    {
        $results = $this->check($this->broken(fn (Response $r, string $path, array $q) => $path === '/foods/search' && mb_strlen($q['query'] ?? '') < 2
            ? Response::ofJson(400, ['error' => ['code' => 'bad_request', 'message' => 'too short']], ['Food-Source-Contract' => '1.0'])
            : $r));

        $this->assertSame(['SRCH-2'], $this->failed($results));
    }

    public function test_a_food_with_a_value_on_a_gap_fails_food_1(): void
    {
        $results = $this->check($this->broken(function (Response $r, string $path) {
            if (! str_starts_with($path, '/foods/') || $path === '/foods/search' || $r->status !== 200) {
                return $r;
            }
            $food = (array) $r->json();
            $food['nutrients']['fat'] = ['value' => 3.2, 'status' => 'missing'];

            return $this->with($r, $food);
        }));

        $this->assertSame(['FOOD-1'], $this->failed($results));
    }

    public function test_a_source_with_a_revision_passes_when_every_record_carries_it(): void
    {
        $results = $this->check(new FixtureSource(fixturePath: $this->fixtureWithRevision('3'))->handle(...));

        $this->assertSame([], $this->failed($results));
    }

    public function test_a_record_without_the_declared_revision_fails_food_1_and_batch_1(): void
    {
        $mock = new FixtureSource(fixturePath: $this->fixtureWithRevision('3'));
        $results = $this->check(function (string $path, array $query) use ($mock) {
            $r = $mock->handle($path, $query);
            if ($path === '/capabilities' || $path === '/foods/search' || $r->status !== 200) {
                return $r;
            }
            $data = (array) $r->json();
            if ($path === '/foods') {
                $data['foods'] = array_map(fn ($food) => [...(array) $food, 'revision' => '2'], (array) $data['foods']);
            } else {
                unset($data['revision']);
            }

            return $this->with($r, $data);
        });

        $this->assertSame(['BATCH-1', 'FOOD-1'], $this->failed($results));
    }

    private function fixtureWithRevision(string $revision): string
    {
        $fixture = json_decode((string) file_get_contents(Contract::fixturePath()), true, flags: JSON_THROW_ON_ERROR);
        $fixture['source']['revision'] = $revision;
        $path = (string) tempnam(sys_get_temp_dir(), 'fixture');
        file_put_contents($path, (string) json_encode($fixture));

        return $path;
    }

    public function test_a_server_error_for_an_unknown_id_fails_food_3(): void
    {
        $results = $this->check($this->broken(fn (Response $r) => $r->status === 404
            ? Response::ofJson(500, ['error' => ['code' => 'unavailable', 'message' => 'boom']], ['Food-Source-Contract' => '1.0'])
            : $r));

        $this->assertContains('FOOD-3', $this->failed($results));
        $this->assertContains('FOOD-4', $this->failed($results));
    }

    public function test_a_batch_that_drops_the_unknown_id_fails_batch_1(): void
    {
        $results = $this->check($this->broken(function (Response $r, string $path) {
            if ($path !== '/foods' || $r->status !== 200) {
                return $r;
            }
            $data = (array) $r->json();
            $data['missing'] = [];

            return $this->with($r, $data);
        }));

        $this->assertSame(['BATCH-1'], $this->failed($results));
    }

    public function test_batch_requirements_are_skipped_without_the_feature(): void
    {
        $results = $this->check($this->broken(function (Response $r, string $path) {
            if ($path !== '/capabilities') {
                return $r;
            }
            $data = (array) $r->json();
            $data['features'] = ['portions'];

            return $this->with($r, $data);
        }));

        $this->assertSame([], $this->failed($results));
        $this->assertSame(Outcome::Skipped, $results['BATCH-1']->outcome);
    }

    public function test_a_query_without_hits_is_reported_as_the_suites_input_problem(): void
    {
        $results = $this->check((new FixtureSource)(...), 'zzzz-nothing');

        $this->assertContains('SRCH-1', $this->failed($results));
        $this->assertStringContainsString('pass a text that finds foods', $results['SRCH-1']->detail);
    }

    public function test_the_failure_modes_answer_with_typed_errors(): void
    {
        $unavailable = (new FixtureSource(FixtureSource::MODE_UNAVAILABLE))->handle('/capabilities');
        $limited = (new FixtureSource(FixtureSource::MODE_RATE_LIMITED))->handle('/foods/search', ['query' => 'Hafer']);

        $this->assertSame(503, $unavailable->status);
        $this->assertSame('unavailable', $unavailable->json()['error']['code']);
        $this->assertSame(429, $limited->status);
        $this->assertSame('30', $limited->header('Retry-After'));
    }
}
