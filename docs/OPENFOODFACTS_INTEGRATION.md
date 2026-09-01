# OpenFoodFacts integration

## Boundary

OpenFoodFacts access lives under `App\Integrations\OpenFoodFacts`.
`OpenFoodFactsClient` owns the HTTP endpoint, headers, timeouts, bounded retry,
HTTP classification, exact JSON decoding, safe operational logging, and the
stable lookup result. `OpenFoodFactsProductMapper` validates and maps the
provider product into `OpenFoodFactsProductData`.

Livewire components and other application callers must use this client. They
must not construct OpenFoodFacts URLs, interpret provider status codes, catch
network exceptions, read provider JSON paths, or inspect rate-limit headers.

## Endpoint and schema profile

The client uses the production product-read endpoint with a configurable API
profile. The default is:

```text
GET https://world.openfoodfacts.org/api/v3.4/product/{barcode}.json
```

OpenFoodFacts describes v3 as current, v3.6 as its latest recommended profile,
and v2 as deprecated. VibeDietr moved off v2 but pins v3.4 because it retains
the documented flat `nutriments` fields used by the current ingredient JSON
model. OpenFoodFacts v3.5 introduced an actively evolving breaking nutrition
schema, and v3.6 changed taxonomy tags. Moving this configuration beyond v3.4
therefore requires mapper fixtures and an explicit compatibility review; it is
not an environment-only upgrade.

The request limits fields to the existing product name, code, quantity,
serving, category, keyword, image, and nutrition behavior. The mapper ignores
unrecognized additive fields, requires the v3 success/result envelope, a
non-empty product code, and an object-shaped `nutriments` value, and validates
the types it consumes.

## Application identification and authentication

OpenFoodFacts asks every request to carry a custom User-Agent in the form
`app_name/app_version (URL or contact info)`. Read access requires no provider
account credential. The local default is deliberately non-personal:

```text
VibeDietr/development (http://localhost)
```

Production must configure a truthful application version and public contact
URL or role address through `OPENFOODFACTS_USER_AGENT`. Do not include an
account email, user ID, IP address, session identifier, credential, or secret.

## Configuration

`config/services.php` owns these values; application code does not call
`env()`:

| Environment value | Configuration key | Local default |
| --- | --- | --- |
| `OPENFOODFACTS_BASE_URL` | `services.openfoodfacts.base_url` | `https://world.openfoodfacts.org` |
| `OPENFOODFACTS_API_VERSION` | `services.openfoodfacts.api_version` | `v3.4` |
| `OPENFOODFACTS_USER_AGENT` | `services.openfoodfacts.user_agent` | local safe identifier above |
| `OPENFOODFACTS_CONNECT_TIMEOUT` | `services.openfoodfacts.connect_timeout` | 2 seconds |
| `OPENFOODFACTS_TIMEOUT` | `services.openfoodfacts.timeout` | 5 seconds |
| `OPENFOODFACTS_ATTEMPTS` | `services.openfoodfacts.attempts` | 2 total attempts |
| `OPENFOODFACTS_BACKOFF_MS` | `services.openfoodfacts.backoff_ms` | 100 ms |
| `OPENFOODFACTS_MAX_RETRY_AFTER` | `services.openfoodfacts.max_retry_after` | 1 second |

The two/five-second connection/request limits keep an interactive Livewire
lookup bounded. One short retry tolerates a transient connection, timeout,
HTTP 408, or ordinary 5xx failure without turning the synchronous request into
a long-running workflow.

HTTP 429 and OpenFoodFacts' documented global-limit HTTP 503 are rate-limited
results. They are retried only when a valid `Retry-After` is present, another
attempt remains, and the delay is within the configured interactive ceiling.
Other 4xx responses and malformed successful responses are not retried.

## Results, mapping, and logging

`OpenFoodFactsLookupStatus` distinguishes:

- `success`
- `not_found`
- `unavailable`
- `rate_limited`
- `invalid_response`
- `permanent_failure`

Not found is a routine result and is not logged as an infrastructure error.
Other final failures create one warning with provider, generated ULID
correlation ID, stable failure category, optional HTTP status, attempt count,
and a short SHA-256 barcode reference. Response bodies, exception messages,
headers, credentials, user/account data, and the barcode itself are excluded.
Ordinary lookup failures do not create audit events.

The mapper retains the existing raw nutrient object and discovers supported
provider nutrient keys through `NutrientRegistry`, rather than maintaining a
second alias list. It maps recognized `_100g` and `_serving` values into the
existing buckets. The shared ingredient normalizer remains responsible for
kcal authority, kJ derivation, DEC-003 storage precision, and explicit numeric
zero. Display remains governed by STB-06 and DEC-004.

NUT-06 also exposes conservative provider-independent package and nutrient
DTOs. Explicit single-package amounts and multipacks retain package count,
item type, amount per item, and standard FND-06 unit separately. Direct
serving amount/unit pairs remain source-backed. Uncertain partial pairs and
unsupported units are omitted rather than asserted.

For shared-catalogue persistence, supported 100 g and serving nutrient fields
retain their provider field identifier, lexical decimal value, provider unit,
and basis. The OpenFoodFacts catalogue adapter creates NUT-05 imported
observations; CatalogueNutritionNormalizer remains the sole selected-fact
writer and therefore owns exact precision, zero, energy, derivation, and
conflict behavior. The legacy normalized buckets remain available only for the
rollback-compatible user-owned ingredient path.

Only HTTPS image references on OpenFoodFacts-owned hosts are stored in native
catalogue versions. Unsupported or malformed image references become null and
do not make an otherwise usable product fail. The application does not
download or cache provider images.

The shared import first checks the globally unique canonical barcode and skips
the provider for an approved existing identity. An unknown successful result
is mapped completely before one transaction creates the identity, initial
version, package/serving fields, normalized nutrition, and current pointer.
The database unique key resolves concurrent inserts; the loser loads the
winner. Provider misses, transient failures, rate limits, permanent failures,
and invalid responses create no catalogue rows. Existing identities are not
refreshed by a scan; refresh staging remains a separate roadmap workflow.

## Testing

Use Laravel HTTP fakes; automated tests must never contact OpenFoodFacts:

```php
Http::fake([
    'https://world.openfoodfacts.org/api/v3.4/product/*' => Http::response([
        'status' => 'success',
        'result' => ['id' => 'product_found'],
        'product' => [
            'code' => '1234567890123',
            'nutriments' => ['proteins_100g' => '0'],
        ],
    ]),
]);
```

Set `services.openfoodfacts.backoff_ms` and
`services.openfoodfacts.max_retry_after` to zero in retry tests. Use response
sequences or `Http::failedConnection()` to prove classification and attempt
counts without sleeping.

## Provider research

Primary sources accessed 13 August 2026:

- [OpenFoodFacts API introduction](https://openfoodfacts.github.io/documentation/docs/Product-Opener/api/)
- [Current product-read reference](https://openfoodfacts.github.io/documentation/docs/Product-Opener/v3/products/get-api-v3-product-code/)
- [Product schema](https://openfoodfacts.github.io/documentation/docs/Product-Opener/schemas/schemas/product/)
- [API and product schema change log](https://openfoodfacts.github.io/documentation/docs/Product-Opener/api/ref-api-and-product-schema-change-log/)

The introduction documents 15 product-read requests per minute per IP and
HTTP 503 for a global API limit. The product reference documents 200, redirect,
and 404 outcomes. The schema/change log establishes versioned breaking changes
and advises clients to ignore added fields and avoid undocumented fields.

A disposable read-only call to the public product endpoint on 13 August 2026
confirmed the v3 envelope and the v3.4 flat nutrient compatibility profile. It
used a safe local application identifier, no credential, and performed no
write. This is manual evidence only and is not a CI dependency.
