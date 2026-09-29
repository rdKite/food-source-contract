# Changelog

Contract versions (what services and consumers must agree on) and tooling changes (this
package's code) are listed separately; tooling never changes the contract.

## Tooling 1.0.1 — 2026-09-29

- Allows `symfony/yaml` 8 next to 7 (Merlin is on 8).

## Tooling — 2026-09-29

- **Conformance suite**: 15 numbered requirements (capabilities, search, food, errors,
  batch), runnable against a URL (`bin/food-source-conformance`) or in-process. It checks
  itself: the reference mock passes everything, and each planted defect fails exactly
  its requirement.
- **Reference mock** `FixtureSource`: serves the fixture as the contract says, with the
  failure modes `unavailable` (503) and `rate_limited` (429 with `Retry-After`); over HTTP
  via `mock/router.php`.
- `opis/json-schema` and `symfony/yaml` are runtime dependencies now (the suite validates
  against the OpenAPI schemas).

## 1.0 — 2026-09-28

First version in its own repository (Merlin D-32). Replaces Merlin's
`docs/food-source-contract.md`, draft 0.1.

- **Nutrient registry as data**: the 138 components of BLS 4.0 with EuroFIR codes, units,
  groups, German and English names and formulas. The seven 0.1 keys keep their names;
  their EuroFIR codes are corrected (`ENERC` → `ENERCC`, `PROT` → `PROT625`).
- **Status `partial`** for lower bounds (a recipe with an ingredient gap), with an
  optional `coverage`. Optional `origin` and `reference` per value.
- Foods: optional `names` per locale, `kind` (`food` / `recipe`), `portions` with at most
  one default (the standard portion).
- Batch lookup `GET /foods?ids=…` (feature `batch`).
- `/capabilities`: `citation`, `licence`, `identity` (`stable` / `per_version`), `features`.
- Response header `Food-Source-Contract`; versioning and deprecation rules.
- OpenAPI 3.1 spec; the fixture (`fixture-2`) validated against it.
