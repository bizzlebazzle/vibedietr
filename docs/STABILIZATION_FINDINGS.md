# Stabilization findings

This register records current behavior found while stabilizing existing
features. A finding documents observed behavior; it does not redefine the
product requirements in `PRODUCT_SPEC.md`.

## Ingredient characterization summary

STB-01 characterizes all seven authenticated ingredient resource routes, the
controller create/update/delete methods, the Livewire form create/update
method, owner-scoped search, 12-item pagination, hard deletion, and the dormant
edit-modal entry point. There is no Livewire delete method.

| Finding | Area | Impact | Status/follow-up |
| --- | --- | --- | --- |
| STB-FIND-001 | Direct Livewire update | Cross-user mutation and ownership transfer | Resolved by STB-03 |
| STB-FIND-002 | Direct guest Livewire save | Database error instead of authorization denial | Resolved by STB-03 |
| STB-FIND-003 | Controller/Livewire unit aliases | Persisted-data inconsistency | Resolved by STB-04 |
| STB-FIND-004 | Explicit nutrition zero | Non-canonical JSON type and zero display loss | Resolved by STB-05 |
| STB-FIND-005 | Energy and supported-nutrient presentation | Independent conflicting energy and silently omitted nutrients | Resolved by STB-06 |
| STB-FIND-006 | Direct OpenFoodFacts access | Unbounded, unidentified provider coupling in Livewire | Resolved by STB-07 |
| STB-FIND-007 | Manual barcode persistence | User-controlled data could forge machine-import provenance | Resolved by STB-08 |

## STB-FIND-001 — Direct Livewire update bypasses owner authorization

- **Area/path:** `App\Livewire\Ingredients\Form::save()` update branch.
- **Observed behavior:** A second authenticated user can mount the form with
  another user's ingredient, update it, and reassign `user_id` to themselves.
  The conventional controller update denies the equivalent request with an
  exact 403 response.
- **Expected/documented behavior:** The current-ingredient row in
  `AUTHORIZATION_PRIVACY_MATRIX.md` requires owner-only update and denial of
  ownership transfer. STB-03 explicitly requires authorization at the Livewire
  mutation boundary.
- **Security/data-integrity impact:** High. A crafted Livewire request can alter
  another user's private record and transfer its ownership.
- **Whether STB-01 changed it:** No. STB-01 only records and tests the behavior.
- **Resolution:** Resolved by STB-03. The component keeps only an untrusted
  scalar identifier, re-resolves the authoritative record at save time, and
  invokes `IngredientPolicy::update` before persistence. Ownership is omitted
  from the update allowlist and from model mass assignment.
- **Regression tests:**
  `test_non_owner_direct_livewire_update_is_forbidden_and_leaves_both_users_records_unchanged`,
  `test_forged_livewire_ingredient_identifier_is_forbidden_and_changes_no_records`,
  and `test_stale_mounted_livewire_component_rechecks_current_ownership_before_update`.

## STB-FIND-002 — Direct guest Livewire save reaches the database

- **Area/path:** `App\Livewire\Ingredients\Form::save()` create and update
  branches when invoked without an authenticated user.
- **Observed behavior:** The action builds a payload with a null `user_id`.
  MySQL rejects both create and update with SQLSTATE 23000; create persists no
  row and update leaves the existing row unchanged. There is no Livewire-level
  redirect, 401, 403, or 404 response.
- **Expected/documented behavior:** Ingredient mutation is authenticated and
  directly invocable mutation actions enforce authorization at their action
  boundary.
- **Security/data-integrity impact:** Medium. The database prevents persistence,
  but unauthenticated crafted requests reach an internal constraint failure
  instead of a controlled denial.
- **Whether STB-01 changed it:** No. STB-01 only records and tests the behavior.
- **Resolution:** Resolved by STB-03. Direct guest create and update actions
  invoke the policy at the mutation boundary and return exact 403 denials
  without reaching persistence.
- **Regression tests:**
  `test_guest_direct_livewire_create_is_forbidden_and_creates_nothing`
  and
  `test_guest_direct_livewire_update_is_forbidden_and_leaves_record_unchanged`.

## STB-FIND-003 — Controller and Livewire normalize unit aliases differently

- **Area/path:** `IngredientController::update()` with
  `UpdateIngredientRequest` compared with `Ingredients\Form::save()`.
- **Observed behavior:** Both paths accept the valid alias `grams`. The
  controller persists `grams`; Livewire normalizes it to the shared storage
  symbol `g` before persistence. Required, negative, and unsafe-unit validation
  otherwise produce validation failures on both characterized paths.
- **Expected/documented behavior:** STB-04 requires one validation and
  normalization contract across retained ingredient write paths.
- **Security/data-integrity impact:** Low. Equivalent valid input produces
  inconsistent stored values and can complicate later catalogue migration.
- **Whether STB-01 changed it:** No. STB-01 only records and tests the behavior.
- **Recommended follow-up backlog item:** STB-04.
- **Capturing test:**
  `test_controller_and_livewire_currently_normalize_valid_unit_aliases_differently`.
- **Resolution:** Resolved by STB-04. Both Form Requests and Livewire consume
  `IngredientWriteContract`, then persist only values returned by
  `IngredientWriteNormalizer`. Standard aliases now use the FND-06 storage
  symbol, safe custom units retain their text, and ambiguous values such as
  `T` remain custom. The shared contract also converges serving pairs,
  barcode strings, nullable values, and the allowed nutrition shape.
- **Regression tests:**
  `test_controller_and_livewire_normalize_valid_unit_aliases_consistently`
  and the dataset-driven `IngredientWriteEquivalenceTest`.
- **Remaining follow-up:** Duplicate-barcode workflow behavior remains
  intentionally route-specific as recorded below.

## STB-FIND-004 — Explicit nutrition zero has inconsistent absence behavior

- **Area/path:** `IngredientWriteNormalizer::normalizeNutriments()`,
  Livewire flattened nutrient preparation, and the ingredient nutrition
  display.
- **Observed behavior:** STB-04's explicit absence check retained zero, but
  serialized it as the scale-18 JSON string `"0.000000000000000000"` rather
  than JSON numeric `0`. Whitespace-only HTTP nutrition input became missing
  through global request middleware while the equivalent flattened Livewire
  value failed validation. Integer-formatted energy zero became string `"0"`
  and a truthy display ternary then treated it as absent.
- **Expected/documented behavior:** STB-05 requires explicit zero, including
  accepted numeric-string zero, to remain distinct from null and blank in
  every normalized nutrient bucket and on both retained write paths.
- **Security/data-integrity impact:** Low. Stored zero was numerically
  recoverable, but its JSON type did not meet the strict normalized contract;
  equivalent whitespace input differed by transport, and known zero energy
  could be presented as not set.
- **Resolution:** Resolved by STB-05. Shared preparation trims normalized
  nutrient strings and maps blanks to missing, shared normalization emits JSON
  numeric `0` for exact zero without changing non-zero DEC-003 quantization,
  and the display uses explicit null checks. Missing normalized values omit
  their key; empty buckets and wholly empty nutrition remain absent.
- **Regression tests:** Dataset-driven controller/Livewire comparisons cover
  numeric, float, and string zero, null, empty, whitespace, and small non-zero
  values. `IngredientNutritionZeroTest` covers every FND-06 nutrient in both
  buckets, strict JSON zero/null/missing types, round trips, filtering, and
  zero-energy display.

## STB-FIND-005 — Energy values diverge and supported nutrients are hidden

- **Area/path:** Shared ingredient normalization, OpenFoodFacts mapping,
  flattened Livewire nutrition state, and ingredient detail presentation.
- **Observed behavior:** kcal and kJ were independently persisted with no
  derivation or conflict authority. Protein, carbohydrate, fibre, and sodium
  were imported but absent from the form and detail presentation. Display
  used PHP floating-point rounding and component-local precision.
- **Expected/documented behavior:** STB-06 requires kcal authority, exact
  `1 kcal = 4.184 kJ` derivation, DEC-003 storage precision, DEC-004 display
  formatting, and consistent exposure of every already-imported supported
  nutrient.
- **Resolution:** Resolved by STB-06. The shared write normalizer now derives
  the energy pair from canonical kcal with Brick Math decimal arithmetic and a
  single conversion constant. kJ-only input derives kcal once at the storage
  boundary; a conflict keeps kcal and replaces normalized kJ. Provider source
  observations remain in the existing `raw` bucket. Form inputs and ingredient
  detail rows come from the FND-06 registry, and detail values use the shared
  DEC-004 formatter.
- **Regression tests:** `EnergyNormalizerTest` covers direction, conflict,
  exact factor, zero, missing, and small non-zero energy.
  `IngredientNutrientHandlingTest` covers both retained write paths,
  OpenFoodFacts source preservation, protein, the complete supported set,
  persistence types, and exact display strings.
- **Remaining follow-up:** The JSON model has no per-normalized-value origin,
  status, policy-version, or conflict metadata. NUT-05 remains responsible for
  the versioned catalogue provenance model.

## STB-FIND-006 — OpenFoodFacts transport and mapping live in the UI

- **Area/path:** `App\Livewire\Ingredients\Form::fetchFromOff()`.
- **Observed behavior:** Livewire constructed a hard-coded deprecated v2 URL,
  supplied no application User-Agent, defined no timeout or retry, treated
  non-success responses generically, allowed connection and JSON exceptions to
  escape, and directly parsed every provider path. Product absence, provider
  failure, throttling, and schema drift were not stable application states.
- **Expected/documented behavior:** STB-07 requires one reusable client with
  explicit application identification, timeout, bounded retry, status and
  rate-limit handling, validated mapping, safe diagnostics, and stable results
  for UI callers.
- **Resolution:** Resolved by STB-07. `App\Integrations\OpenFoodFacts` now owns
  the configurable v3.4 compatibility endpoint, custom User-Agent, two/five-
  second connect/request limits, two total attempts, safe `Retry-After`
  handling, exact JSON validation, registry-driven nutrient mapping, typed
  result semantics, and correlated privacy-minimized final-failure logs.
  Livewire handles interaction and maps only those stable results to safe
  messages. Routine not-found creates no infrastructure-error log or audit
  event.
- **Regression tests:** `OpenFoodFactsClientTest` covers transport,
  classification, mapping, schema failures, precision, zero and bounded retry;
  `OpenFoodFactsLivewireTest` covers success and every user-visible failure
  state without provider-detail leakage.
- **Remaining follow-up:** The current ingredient JSON shape requires the
  provider's v3.4 flat nutrient compatibility profile. A v3.6 mapper migration
  must account for its breaking nutrition/tag schema before changing the
  configured profile. STB-09 separately owns the scanner CDN dependency.

## STB-FIND-007 — Manual writes can forge barcode import provenance

- **Area/path:** Shared ingredient write contract, model mass assignment,
  controller mutations, and `Ingredients\Form` public state/save.
- **Observed behavior:** Barcode was a normal validated/fillable field. The
  Livewire component assigned attempted lookup input before provider success,
  so manual, failed, or crafted requests could persist a barcode
  indistinguishably from an OpenFoodFacts import.
- **Expected/documented behavior:** STB-08 requires barcode and its source,
  import time, and classification to be machine-controlled and persisted only
  after a usable successful STB-07 result. Legacy barcodes must remain readable
  without being promoted.
- **Security/data-integrity impact:** High. Barcode presence could falsely imply
  trusted provider provenance and imported-data accuracy.
- **Resolution:** Resolved by STB-08. An additive allowlisted classification
  marks manual, verified machine import, and legacy unknown states. The
  migration preserves every legacy barcode/nutrition value and classifies each
  pre-existing non-empty barcode as unknown. Ordinary validation and
  `$fillable` exclude all machine fields. Successful provider DTOs are retained
  in short-lived server-side state bound to user and ingredient, and a narrow
  action verifies barcode consistency before assigning `openfoodfacts`,
  server UTC import time, and verified provenance. Failure clears pending
  success and never writes trusted metadata.
- **Regression tests:** `IngredientBarcodeProvenanceTest` covers controller,
  Livewire, mass-assignment and locked-state forgery; successful mapped import;
  every required failure; failed re-import preservation; authorization; and
  legacy reads. `IngredientBarcodeProvenanceMigrationTest` proves additive
  classification and rollback without barcode/nutrition loss. Factory tests
  distinguish manual, verified import, and legacy unknown states.
- **Remaining follow-up:** NUT-01/NUT-02 own shared-catalogue identity,
  de-duplication review, and migration mappings. NUT-05 owns versioned,
  per-nutrient provenance.

## Intentional controller/Livewire write differences

| Field/behavior | Controller | Livewire | Why intentional | Relevant test | Temporary |
| --- | --- | --- | --- | --- | --- |
| Duplicate non-empty lookup barcode | No provider lookup route; ordinary forged barcode is ignored | Redirects before the provider request | Scanner/lookup UX remains Livewire-only; ordinary mutation semantics agree | `test_fetch_from_off_redirects_to_existing_barcode_ingredient` | Yes; later catalogue/uniqueness work must converge it safely |
| Successful response | Redirects with a session status | Dispatches component events and may navigate | Transport-specific UX is preserved | Controller and Livewire characterization suites | No |
| Direct guest invocation | Auth middleware redirects before the action | Mutation-boundary policy returns 403 | STB-03 protects independently callable Livewire actions | STB-03 guest mutation tests | No |

There are no intentional differences in field validation or normalization.
The flattened Livewire nutrition properties are UI state only; they are
validated from the same contract and merged before the shared normalizer.

## Dormant edit-modal evidence

`Ingredients\Index::openEditModal()` and its conditional Blade branch still
exist. Repository route, view, JavaScript, and test searches found no rendered
control or event that calls the method. The add button opens a create form and
each list item opens only the details modal.

The method remains directly executable. Its owner call opens the modal; direct
non-owner and guest calls return exact 403 responses. The modal mounts the same
`Ingredients\Form` component as the dedicated edit page, so validation is the
same and the mutation uses STB-03's secured save boundary. Tests
`test_owner_can_open_the_currently_unused_edit_modal_path`,
`test_non_owner_direct_edit_modal_invocation_is_forbidden`, and
`test_guest_direct_edit_modal_invocation_is_forbidden` capture the executable
entry point without reactivating it in the interface.

## Characterized response map

| Path | Owner | Non-owner | Guest |
| --- | --- | --- | --- |
| Controller show/edit | 200 | 403 | 302 to login |
| Controller update | 302 after update | 403, unchanged | 302 to login, unchanged |
| Controller delete | 302 after hard delete | 403, intact | 302 to login, intact |
| Direct Livewire update | Updated | 403, unchanged | 403, unchanged |
| Direct edit-modal opener | Modal opens | 403 | 403 |

Search is partial across name and barcode, case-insensitive on the supported
MySQL baseline, owner-scoped, newest-first, and paginated at 12 records. Search
changes reset pagination to page one. Deletion exists only through the
controller and does not preserve search or later-page state in its redirect.
