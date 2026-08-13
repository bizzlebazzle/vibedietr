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

## Intentional controller/Livewire write differences

| Field/behavior | Controller | Livewire | Why intentional | Relevant test | Temporary |
| --- | --- | --- | --- | --- | --- |
| Duplicate non-empty barcode | Continues through the ordinary write after validation | Redirects to the existing record before saving | Existing provider-assisted Livewire workflow outside payload validation; the schema has no uniqueness constraint | `test_save_redirects_to_existing_barcode_ingredient_instead_of_creating_duplicate` | Yes; later catalogue/uniqueness work must converge it safely |
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
