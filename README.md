# Food-source contract

The contract between the applications of the ecosystem — **Merlin** (nutrition
counselling) and **Lori** (recipes) — and every **food source** they read from: a
country's food composition database behind a service, a recipe platform, a fixture.
Merlin decision D-32 put it into this neutral repository so neither application owns a
copy.

| Part | File |
|---|---|
| The contract in prose | [`CONTRACT.md`](CONTRACT.md) |
| The machine-readable spec (OpenAPI 3.1) | [`spec/openapi.yaml`](spec/openapi.yaml) |
| The canonical nutrients (138, from BLS 4.0) | [`registry/nutrients.json`](registry/nutrients.json) |
| An illustrative fixture a consumer's fixture driver serves | [`fixtures/bls.json`](fixtures/bls.json) |
| PHP access to the registry and paths | [`src/`](src) |
| Conformance suite (15 numbered requirements) | [`src/Conformance`](src/Conformance), CLI `bin/food-source-conformance` |
| Reference mock serving the fixture, with failure modes | [`src/Mock/FixtureSource.php`](src/Mock/FixtureSource.php), server `mock/router.php` |

## Consumers and implementers

- **Merlin** and **Lori** require this package (`rdkite/food-source-contract`) and read
  the registry and fixture from it.
- **The BLS service** (`food-service`, Merlin D-33) implements the contract and must pass
  the conformance suite.

A change here is a change for all three. Additions are a minor version (1.x); anything
else is a major version (see "Versioning" in `CONTRACT.md`). Record every change in
[`CHANGELOG.md`](CHANGELOG.md) and tell both application plans.

## Checking a service

```bash
vendor/bin/food-source-conformance https://food.example --query=Hafer
```

`--query` is a text that finds foods in that source (the suite discovers everything else).
Every requirement has an id (`CAP-1` … `BATCH-2`); exit code 0 only if none fails. A
service must pass before it may be configured as a data source.

## Using the mock in a consumer's tests

`FixtureSource` is a function from request to response, so it plugs into any HTTP fake —
consumers test against the contract instead of hand-written payloads. In Laravel:

```php
use FoodSourceContract\Mock\FixtureSource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

$mock = new FixtureSource;                       // or MODE_UNAVAILABLE, MODE_RATE_LIMITED

Http::fake(['food.test/*' => function (Request $request) use ($mock) {
    $url = parse_url($request->url());
    parse_str($url['query'] ?? '', $query);
    $response = $mock->handle($url['path'], $query);

    return Http::response($response->body, $response->status, $response->headers);
}]);
```

Over HTTP: `php -S 127.0.0.1:8200 mock/router.php` (with
`FOOD_SOURCE_MOCK_MODE=unavailable` or `rate_limited` to simulate failures).

## Licence, data and attribution

The code and the contract are **MIT** ([`LICENSE`](LICENSE)); the repository is public so
any country's food service can implement the contract (Merlin D-9, D-36).

The registry's component codes, names, units, groups and formulas come from the component
table of the **Bundeslebensmittelschlüssel 4.0**, published under **CC BY 4.0**:

> Max Rubner-Institut (2025): Bundeslebensmittelschlüssel (BLS), Version 4.0 — Deutsche
> Nährstoffdatenbank. Karlsruhe. DOI: 10.25826/Data20251217-134202-0

The fixture's food values are **illustrative**, not BLS data.

## Checks

```bash
composer install
./vendor/bin/phpunit                                  # registry, spec, fixture, suite
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --level 8 src tests bin mock
```
