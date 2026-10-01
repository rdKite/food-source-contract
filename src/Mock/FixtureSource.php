<?php

declare(strict_types=1);

namespace FoodSourceContract\Mock;

use FoodSourceContract\Contract;
use FoodSourceContract\Http\Response;

/**
 * The reference implementation of the contract, serving the fixture.
 *
 * A pure function from request to response: consumers plug it into their
 * HTTP fakes (Laravel: Http::fake), `mock/router.php` serves it over HTTP,
 * and the conformance suite checks itself against it. A mode simulates the
 * failures a consumer must survive.
 */
final class FixtureSource
{
    public const string MODE_OK = 'ok';

    public const string MODE_UNAVAILABLE = 'unavailable';

    public const string MODE_RATE_LIMITED = 'rate_limited';

    public const int SEARCH_DEFAULT_LIMIT = 20;

    public const int SEARCH_MAX_LIMIT = 50;

    public const int BATCH_MAX_IDS = 100;

    /** @var array<string, mixed> */
    private array $fixture;

    /** @var array<string, array<string, mixed>> */
    private array $foods = [];

    public function __construct(
        private string $mode = self::MODE_OK,
        ?string $fixturePath = null,
    ) {
        /** @var array<string, mixed> $fixture */
        $fixture = json_decode((string) file_get_contents($fixturePath ?? Contract::fixturePath()), true, flags: JSON_THROW_ON_ERROR);
        $this->fixture = $fixture;

        /** @var list<array<string, mixed>> $foods */
        $foods = $fixture['foods'];
        foreach ($foods as $food) {
            $this->foods[(string) $food['external_id']] = $food;
        }
    }

    /**
     * @param  array<string, string>  $query
     */
    public function __invoke(string $path, array $query = []): Response
    {
        return $this->handle($path, $query);
    }

    /**
     * @param  array<string, string>  $query
     */
    public function handle(string $path, array $query = []): Response
    {
        if ($this->mode === self::MODE_UNAVAILABLE) {
            return $this->error(503, 'unavailable', 'The source cannot answer right now.');
        }

        if ($this->mode === self::MODE_RATE_LIMITED) {
            return $this->error(429, 'rate_limited', 'Too many requests.', ['Retry-After' => '30']);
        }

        $path = '/'.trim($path, '/');
        $locale = $query['locale'] ?? null;

        return match (true) {
            $path === '/capabilities' => $this->ok($this->capabilities()),
            $path === '/foods/search' => $this->search($query['query'] ?? '', $query['limit'] ?? null, $locale),
            $path === '/foods' => $this->batch($query['ids'] ?? '', $locale),
            str_starts_with($path, '/foods/') => $this->food(rawurldecode(substr($path, 7)), $locale),
            default => $this->error(404, 'not_found', 'Unknown endpoint.'),
        };
    }

    /** @return array<string, mixed> */
    private function capabilities(): array
    {
        $capabilities = $this->fixture;
        unset($capabilities['foods'], $capabilities['_comment']);

        return $capabilities;
    }

    private function search(string $text, ?string $limit, ?string $locale): Response
    {
        if ($limit !== null && (! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > self::SEARCH_MAX_LIMIT)) {
            return $this->error(400, 'bad_request', 'limit must be between 1 and '.self::SEARCH_MAX_LIMIT.'.');
        }

        $needle = mb_strtolower(trim($text));
        $results = [];

        if (mb_strlen($needle) >= 2) {
            foreach ($this->foods as $food) {
                $localized = $this->localize($food, $locale);
                $haystack = mb_strtolower(implode(' ', [$localized['name'], ...array_values($food['names'] ?? [])]));
                if (str_contains($haystack, $needle)) {
                    // Best match first: a name that starts with the text wins.
                    $rank = str_starts_with(mb_strtolower((string) $localized['name']), $needle) ? 0 : 1;
                    $results[] = [$rank, [
                        'external_id' => $food['external_id'],
                        'name' => $localized['name'],
                        'locale' => $localized['locale'],
                        'kind' => $food['kind'] ?? 'food',
                    ]];
                }
            }
        }

        usort($results, fn ($a, $b) => $a[0] <=> $b[0]);

        return $this->ok([
            'version' => $this->version(),
            'results' => array_slice(array_column($results, 1), 0, (int) ($limit ?? self::SEARCH_DEFAULT_LIMIT)),
        ]);
    }

    private function food(string $id, ?string $locale): Response
    {
        $food = $this->foods[$id] ?? null;

        return $food === null
            ? $this->error(404, 'not_found', "Unknown food: {$id}")
            : $this->ok($this->localize($food, $locale));
    }

    private function batch(string $ids, ?string $locale): Response
    {
        $list = array_values(array_filter(array_map('trim', explode(',', $ids)), fn ($id) => $id !== ''));

        if ($list === [] || count($list) > self::BATCH_MAX_IDS) {
            return $this->error(400, 'bad_request', 'ids must name 1 to '.self::BATCH_MAX_IDS.' foods.');
        }

        $foods = [];
        $missing = [];
        foreach ($list as $id) {
            isset($this->foods[$id]) ? $foods[] = $this->localize($this->foods[$id], $locale) : $missing[] = $id;
        }

        return $this->ok(['version' => $this->version(), 'foods' => $foods, 'missing' => $missing]);
    }

    /**
     * The food with `name` and `locale` in the requested locale, if it has it.
     *
     * @param  array<string, mixed>  $food
     * @return array<string, mixed>
     */
    private function localize(array $food, ?string $locale): array
    {
        /** @var array<string, string> $names */
        $names = $food['names'] ?? [];

        if ($locale !== null && isset($names[$locale])) {
            $food['name'] = $names[$locale];
            $food['locale'] = $locale;
        }

        // 1.2: every record carries the source's curation revision, if it has one.
        /** @var array{revision?: string} $source */
        $source = $this->fixture['source'];
        if (isset($source['revision'])) {
            $food['revision'] = $source['revision'];
        }

        return $food;
    }

    private function version(): string
    {
        /** @var array{version: string} $source */
        $source = $this->fixture['source'];

        return $source['version'];
    }

    /** @param array<string, mixed> $data */
    private function ok(array $data): Response
    {
        return Response::ofJson(200, $data, ['Food-Source-Contract' => Contract::VERSION]);
    }

    /** @param array<string, string> $headers */
    private function error(int $status, string $code, string $message, array $headers = []): Response
    {
        return Response::ofJson(
            $status,
            ['error' => ['code' => $code, 'message' => $message]],
            ['Food-Source-Contract' => Contract::VERSION, ...$headers],
        );
    }
}
