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
| Conformance suite and reference mock | *coming in Merlin Phase 3.B* |

## Consumers and implementers

- **Merlin** and **Lori** require this package (`rdkite/food-source-contract`) and read
  the registry and fixture from it.
- **The BLS service** (`food-service`, Merlin D-33) implements the contract and must pass
  the conformance suite.

A change here is a change for all three. Additions are a minor version (1.x); anything
else is a major version (see "Versioning" in `CONTRACT.md`). Record every change in
[`CHANGELOG.md`](CHANGELOG.md) and tell both application plans.

## Data and attribution

The registry's component codes, names, units, groups and formulas come from the component
table of the **Bundeslebensmittelschlüssel 4.0**, published under **CC BY 4.0**:

> Max Rubner-Institut (2025): Bundeslebensmittelschlüssel (BLS), Version 4.0 — Deutsche
> Nährstoffdatenbank. Karlsruhe. DOI: 10.25826/Data20251217-134202-0

The fixture's food values are **illustrative**, not BLS data.

## Checks

```bash
composer install
./vendor/bin/phpunit                                  # registry, spec, fixture
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --level 8 src tests
```
