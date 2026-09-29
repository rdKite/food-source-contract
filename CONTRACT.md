# Food-source contract 1.1

What an application of the ecosystem (Merlin, Lori) expects from **any food source**: a
country's official food composition database behind a service, a recipe platform, or a
fixture. The applications are the consumers and own this contract; a service is built to
it, not the other way round.

The machine-readable form is [`spec/openapi.yaml`](spec/openapi.yaml). The canonical
nutrients are data: [`registry/nutrients.json`](registry/nutrients.json). Where this text
and the spec disagree, fix one of them — they must say the same.

## Principles

1. **Country-neutral.** Nothing here names a particular database. BLS, CIQUAL or USDA
   differ only in the data they return.
2. **Canonical nutrient keys, canonical units.** A service maps its own components onto
   the registry keys and converts to the registry unit. Consumers never convert units
   between sources.
3. **Every value says where it stands.** A value comes with a status. A gap is a gap;
   neither the service nor a consumer fills it from another food. Values a source's own
   compilers derived or borrowed are fine — with the matching status and, where the
   source gives one, the source's own origin label.
4. **Stable identity.** `external_id` is opaque and identifies the same food across data
   versions of the source. Each service **states** how far it guarantees this.

## Transport

HTTP, JSON, UTF-8, `GET` only. One base URL per source. Consumers send
`Accept: application/json` and the requested locale as `?locale=` (e.g. `de`). Every
response carries the header `Food-Source-Contract` with the version it speaks (`1.1`).

**Authentication** *(1.1)*: a service may require a key per consuming application, sent
as `Authorization: Bearer <key>`. Without a valid key it answers `401` with the error
code `unauthorized`. How keys are issued is up to the service.

## Versioning

- The contract is versioned `MAJOR.MINOR`. A **minor** version only adds: new optional
  fields, new nutrient keys, new endpoints. A consumer written for 1.0 works with any
  1.x service and ignores what it does not know.
- A **major** version may remove or change meaning. Consumers support the current and the
  previous major version for at least 12 months after a new major is published, so a
  country's service can lag a release.
- A service declares its version in `/capabilities` and in the response header.

## Endpoints

### `GET /capabilities`

What the source is and what it delivers.

```json
{
  "contract_version": "1.1",
  "source": {
    "name": "Bundeslebensmittelschlüssel",
    "version": "4.0",
    "default_locale": "de",
    "locales": ["de", "en"],
    "citation": "Max Rubner-Institut (2025): Bundeslebensmittelschlüssel (BLS), Version 4.0 — Deutsche Nährstoffdatenbank. Karlsruhe. DOI: 10.25826/Data20251217-134202-0",
    "licence": "CC BY 4.0",
    "identity": "stable"
  },
  "nutrients": ["energy", "fat", "protein", "…"],
  "features": ["batch", "portions"]
}
```

- `source.version` — the data version in the source's own naming. Every food carries it
  too; consumers store foods per version.
- `source.citation`, `source.licence` — what a consumer must show where it uses the data.
- `source.identity` — the identity guarantee across data versions:
  `stable` (an id always means the same food; retired ids are never reissued),
  `per_version` (ids are only valid within one data version). Consumers move logged
  entries to a new data version automatically **only** for `stable`.
- `nutrients` — the registry keys this source ever reports. A key **not** listed is
  `not_collected` for every food of this source.
- `features` — optional parts of this contract the service implements (see below).

### `GET /foods/search?query={text}&locale={locale}&limit={n}`

```json
{
  "version": "4.0",
  "results": [
    { "external_id": "C133000", "name": "Hafer Flocken", "locale": "de", "kind": "food" }
  ]
}
```

- Best match first. `limit` defaults to 20, maximum 50.
- `query` shorter than two characters → an empty result, not an error.
- `name` is in the requested locale if the source has it, else in `default_locale`;
  `locale` says which.

### `GET /foods/{external_id}?locale={locale}`

One food with all its values.

```json
{
  "external_id": "C133000",
  "version": "4.0",
  "kind": "food",
  "name": "Hafer Flocken",
  "locale": "de",
  "names": { "de": "Hafer Flocken", "en": "Oat flakes" },
  "base": "100g",
  "density_g_per_ml": null,
  "nutrients": {
    "energy": { "value": 348, "status": "calculated", "origin": "Formelberechnung" },
    "protein": { "value": 13.22, "status": "measured", "origin": "Analyse" },
    "vitamin_d": { "value": null, "status": "missing" },
    "salt": { "value": null, "status": "trace", "origin": "<LOQ" }
  },
  "portions": [
    { "id": "tablespoon", "names": { "de": "1 Esslöffel", "en": "1 tablespoon" }, "amount": 10 }
  ]
}
```

- `kind` — `food` (a single food) or `recipe` (a dish computed from ingredients, e.g. a
  recipe platform's revision). Optional; absent means `food`.
- `names` — optional; every locale the source has. `name` + `locale` stay mandatory.
- `base` — `100g` or `100ml`: what the values refer to.
- `density_g_per_ml` — optional; lets a consumer convert between g and ml. Without it,
  a consumer does not convert.
- `nutrients` — keyed by registry key, in the registry unit, per `base`. A key absent from
  the object but listed in `/capabilities` means `missing` for this food.
- `portions` — optional (feature `portions`): named amounts. `amount` is in the unit of
  the food's `base` (g for `100g`, ml for `100ml`). At most one may carry
  `"default": true`: the standard portion (a recipe's serving, a slice of bread).

### `GET /foods?ids={id},{id},…&locale={locale}` *(feature `batch`)*

Several foods in one request, up to 100 ids.

```json
{
  "version": "4.0",
  "foods": [ { "external_id": "C133000", "…": "as above" } ],
  "missing": ["X000000"]
}
```

Unknown ids are listed in `missing`, not an error. Used to re-sync or move many foods to
a new data version.

## Nutrient values

| Field | Required | Meaning |
|---|---|---|
| `value` | yes | number ≥ 0, or `null` exactly for `trace`, `missing`, `not_collected` |
| `status` | yes | see below |
| `origin` | no | the source's own origin category, as the source names it ("Rezeptberechnung", "Übernommener Wert", "<LOQ") — shown to users who ask *why* |
| `reference` | no | the source's literature or database reference for this value |
| `coverage` | no, only with `partial` | share (0–1) of the dish's mass whose value is known |

### Status

| Status | `value` | Meaning |
|---|---|---|
| `measured` | number ≥ 0 | Analysed for this food (by the compilers or taken from a cited analysis) |
| `calculated` | number ≥ 0 | Derived by the compilers from other values: recipe, formula, aggregation, rescaling, logical zero |
| `estimated` | number ≥ 0 | Imputed by the compilers, e.g. taken over from a similar food or a logical assumption |
| `unspecified` | number ≥ 0 | The source gives a value but no provenance |
| `partial` | number ≥ 0 | **New in 1.0.** A lower bound: computed from parts of which some have no value (a recipe with an ingredient gap). The true value can only be higher. `coverage` may say how much is known |
| `trace` | `null` | Present below the detection or quantification limit — neither zero nor missing |
| `missing` | `null` | The source tracks this nutrient, but has no value for this food |
| `not_collected` | `null` | The source does not track this nutrient |

Consumers count `trace` as 0 and report it as a trace; `missing` and `not_collected` are
gaps; a total that includes a `partial` value or a gap is a lower bound.

## Canonical nutrients

[`registry/nutrients.json`](registry/nutrients.json): per nutrient a `key` (the contract
name — `snake_case`, never a database's column name), the `eurofir` component code, the
`unit`, a `group` and names in German and English. Version 1.0 holds the 138 components of
BLS 4.0, whose component table (CC BY 4.0, Max Rubner-Institut) it is derived from. A
source with components outside the registry proposes them; new keys are a minor version.

## Errors

Error responses carry a JSON body:

```json
{ "error": { "code": "not_found", "message": "…" } }
```

| HTTP | `code` | When |
|---|---|---|
| 400 | `bad_request` | Invalid parameters (e.g. more than 100 ids) |
| 401 | `unauthorized` | The service requires a key and none or an invalid one was sent *(1.1)* |
| 404 | `not_found` | Unknown `external_id` |
| 429 | `rate_limited` | Too many requests; `Retry-After` header in seconds |
| 503 | `unavailable` | The source cannot answer right now |

## Changes from draft 0.1

- The registry: 138 nutrients instead of 7, as data. The seven 0.1 keys keep their names;
  their EuroFIR codes are corrected (`ENERC` → `ENERCC`, `PROT` → `PROT625`).
- Status `partial`; value fields `origin`, `reference`, `coverage`.
- `names`, `kind`, `portions`; batch lookup.
- `/capabilities`: `citation`, `licence`, `identity`, `features`.
- The response header `Food-Source-Contract`; versioning rules.

## Informative: mapping BLS 4.0

How the BLS service (the first implementation) maps its data. Not part of the contract —
each service documents its own mapping.

| BLS "Datenherkunft" / value | `status` |
|---|---|
| Analyse, Literatur, Nährstoffdatenbank | `measured` |
| Labelangabe (a manufacturer's declaration) | `unspecified` *(corrected in 1.1)* |
| Rezeptberechnung, Formelberechnung, Musterberechnung, Aggregation, Reskalierung, Logische Null | `calculated` |
| Übernommener Wert, Logische Annahme | `estimated` |
| Spuren (`TR`), or a value `<LOD`, `<LOQ`, `<LOD or <LOQ` | `trace` |
| value `-` | `missing` |
| a number with origin `-` | `unspecified` |

The BLS label goes into `origin` unchanged; the "Referenz" column into `reference`.
