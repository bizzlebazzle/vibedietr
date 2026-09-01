# Additive domain migration plan

## 1. Purpose and scope

This document defines a safe future sequence for evolving the current
user-owned `ingredients` model toward the target product domain in
[`PRODUCT_SPEC.md`](PRODUCT_SPEC.md) and [`DOMAIN_MODEL.md`](DOMAIN_MODEL.md).
It completes roadmap item FND-02 as a planning artifact only.

The sequence is deliberately expand/backfill/validate/cut-over/contract. It
must preserve every existing ingredient row and keep it readable while new
structures are introduced. Proposed table and column names in this document
are illustrative. Each later implementation item must confirm its exact schema
in a separately reviewed change.

The governing migration principles are:

- Make additive schema changes before any destructive change.
- Keep existing data readable throughout the transition.
- Allow old and new read/write paths to coexist where the migration needs
  them.
- Make backfills idempotent or safely repeatable where practical.
- Validate data and behavior before cut-over.
- Require explicit manual approval before contract or destructive steps.
- Keep rollback possible until the contract phase begins.
- Create and verify a backup or restore point before any destructive
  production operation.
- Never silently reassign, merge, or delete user-owned ingredient data.

The catalogue transition is the part of the target domain that maps current
data. Recipes, recipe lines, plans, diary entries, targets, and snapshots have
no current rows to backfill; their future schemas can be added independently,
provided they refer only to stable target identities and preserve the rules in
the product specification.

## 2. Current schema

The inventory below is based on the repository migration, model, request
validation, controllers, Livewire components, Blade views, policy, routes, and
tests. It was also compared with the local MySQL table through the read-only
`artisan db:table ingredients` command. The live development definition
matches the migration.

### `ingredients` table

| Column | Database definition, default, and indexes | Current model, query, and UI use |
| --- | --- | --- |
| `id` | Unsigned `BIGINT`, non-null, auto-increment. Primary key. | Eloquent identity and implicit route-model key; passed to Livewire show/edit modal lookups. A future source mapping must retain this identifier without changing it. |
| `user_id` | Unsigned `BIGINT`, non-null. Indexed by `ingredients_user_id_foreign`. Foreign key to `users.id`; `ON UPDATE NO ACTION`, `ON DELETE CASCADE`. | Fillable and assigned from the authenticated user on create; currently means ownership. Lists and duplicate-barcode lookups filter by it, and the policy compares it with the authenticated user for view/update/delete. `Ingredient::user()` is the only declared relationship. |
| `name` | `VARCHAR(255)`, non-null, no database default. No separate index. | Fillable and required on both mutation paths. Shown and edited throughout the UI, searched with a partial `LIKE`, and replaced with an OFF product name when returned by lookup. It is not guaranteed to preserve original user wording. |
| `barcode` | `VARCHAR(64)`, nullable, default `NULL`. Non-unique B-tree index `ingredients_barcode_index`. | Searchable and used as untrusted scanner/lookup input. It is excluded from ordinary validation and mass assignment; the trusted import action assigns it only after a successful consistent provider result. Cross-user and database duplicates remain possible. |
| `barcode_provenance` | `ENUM('manual', 'machine_imported', 'legacy_unknown')`, non-null, default `manual`. Indexed. | Cast to `IngredientBarcodeProvenance`. Existing non-empty barcodes are backfilled `legacy_unknown`; only the trusted import action assigns `machine_imported`. |
| `barcode_source` | `VARCHAR(32)`, nullable, default `NULL`. | Stable machine-owned provider key; successful current imports store `openfoodfacts`. Legacy/manual rows retain null. |
| `barcode_imported_at` | `TIMESTAMP`, nullable, default `NULL`. | Immutable-datetime cast and machine-owned. Successful imports use server UTC time, never a browser/provider timestamp. |
| `keywords` | `JSON`, nullable, default `NULL`. No index. | Fillable and cast to an array. OFF keywords and manually added tags share the same array. Users can add/remove them in the form and see them on the detail view. |
| `categories` | `JSON`, nullable, default `NULL`. No index. | Fillable and cast to an array. OFF `categories_tags` and manual values share the same array. Users can add/remove them in the form and see them on the detail view. |
| `nutriments` | `JSON`, nullable, default `NULL`. No index. | Fillable and cast to an array. Livewire conventionally uses `raw`, `per_100g`, and `per_serving`; the controller accepts any array shape. OFF mapping can retain energy, carbohydrate, fat, fibre, protein, salt, saturated fat, sodium, and sugars. The form and detail view expose only energy, fat, saturated fat, sugars, and salt. There is no persisted basis/unit schema, provenance, source revision, or override state. |
| `quantity` | `DECIMAL(10,3)`, non-null, default `0.000`. No index. | Fillable and cast to a three-decimal string. Required and non-negative on both write paths, populated from some OFF quantity strings, edited manually, and displayed with `quantity_unit`. Its package/item meaning is ambiguous, and zero cannot be distinguished from an unknown value inherited from the default. |
| `quantity_unit` | `VARCHAR(32)`, non-null, no database default or index. | Fillable and required. Both paths validate through FND-06; Livewire normalizes safe standard aliases and preserves safe custom text. The database still stores only the label/symbol, not a dimension or conversion factor. |
| `serving_quantity` | `DECIMAL(10,3)`, nullable, default `NULL`. No index. | Fillable and cast to a three-decimal string. Optional and non-negative; manually editable or populated from numeric OFF `serving_quantity`; displayed as part of “Recommended serving.” It can exist without a serving unit. |
| `serving_quantity_unit` | `VARCHAR(32)`, nullable, default `NULL`. No index. | Fillable and optional. It accepts shared standard or safe custom units and may be inferred from OFF serving text; displayed with `serving_quantity`. It can exist without a serving quantity. |
| `recommended_servings` | `DECIMAL(10,2)`, nullable, default `NULL`. No index. | Fillable and cast to a two-decimal string. Optional, non-negative, manually editable, and displayed as “Recommended servings.” The code does not define whether this means servings per package or a dietary recommendation. |
| `image_url` | `VARCHAR(255)`, nullable, default `NULL`. No index. | Fillable and URL-validated. Entered manually or populated from an OFF image URL; shown in the form preview, ingredient list, and detail view. |
| `created_at` | `TIMESTAMP`, nullable, default `NULL`. No index. | Maintained by Eloquent. `Ingredient::latest()` uses it for the default newest-first list. It is not displayed. |
| `updated_at` | `TIMESTAMP`, nullable, default `NULL`. No index. | Maintained by Eloquent on updates. It is not displayed and is not a catalogue version or source-revision marker. |

The table has four indexes: `PRIMARY (id)`, the foreign-key index on `user_id`,
the non-unique index on `barcode`, and the classification index on
`barcode_provenance`. It has no barcode uniqueness constraint, soft-delete
column, moderation status, version reference, or legacy-to-target mapping.

### Related tables and relationships

- `users` is the only related domain table. `ingredients.user_id` references
  `users.id` with cascading deletion. `Ingredient::user()` is a `belongsTo`;
  `User` has no reciprocal `ingredients()` relationship.
- `sessions.user_id` is nullable and indexed but has no foreign-key constraint;
  it is authentication infrastructure, not a food-domain relationship.
- The cache, queue, failed-job, batch, password-reset, migration, and session
  tables are framework/operational tables and do not reference ingredients.
- There are no current catalogue, catalogue-version, nutrient, recipe,
  recipe-line, match, meal-plan, diary, target, moderation, snapshot, or pivot
  tables.
- FND-05 additively introduces `audit_actor_identities` and `audit_events`.
  Neither table changes or backfills an ingredient row. Identity mappings use
  ULID primary keys and a nullable `user_id` with `ON DELETE SET NULL`; events
  use ULID primary keys and retain mapping ULIDs without foreign keys so mapping
  erasure cannot mutate or delete an append-only event.
- Audit events store allowlisted classifications, bounded references, one UTC
  timestamp, schema-versioned action-specific JSON, and an integrity HMAC.
  Purpose/time, retention/time, identity, subject, occurrence, and correlation
  indexes support the approved access, erasure, and future retention queries.

### Current compatibility boundary

Existing routes and both current write paths expect an `Ingredient` row.
Ordinary controller and Livewire writes share the same allowlist and cannot
change barcode provenance. Livewire alone owns the scanner/provider lookup and
same-user duplicate redirect. Owner-only reads and mutations depend directly on
`ingredients.user_id`, and deleting a user currently deletes their ingredient
rows. Those behaviors must remain available until an explicitly approved
cut-over changes them.

## 3. Target domain model

The target model is conceptual here; this plan does not approve exact table or
column names.

### Shared catalogue

A stable shared food/product identity should distinguish barcode-imported
products from rare manual non-barcode submissions. The submitting user is
provenance, not owner: the reference is nullable, and deleting that user sets
the reference to null rather than deleting the catalogue record. Catalogue
lifecycle/moderation state, current version, source identity, import time, and
provenance must be representable.

Catalogue versions should retain the values used at a point in time so recipe
matches and historical snapshots can identify their inputs. Package structure
must be able to represent package count, internal item type, amount per item,
servings per item, and the basis of any reliably derived serving amount.
Unknown package and serving values are null, not zero.

Nutrition should support the product nutrient set with an explicit basis,
unit, useful source precision, provenance, and catalogue version. Raw source
data may be retained separately from application-owned normalized values, but
its retention and refresh treatment must be approved rather than inferred.

### Migration provenance

An additive migration ledger or mapping should associate every legacy
`ingredients.id` with its migration status and, only when safe, a target
catalogue identity/version. It must retain the legacy owner identifier and a
classification/review reason. A nullable target reference is necessary for
ambiguous or unsafe-to-backfill records. A uniqueness constraint on the legacy
ingredient identifier can make reruns idempotent without treating catalogue
candidates as duplicates that may be merged.

### Recipes and matching

Recipes have owner, lifecycle, visibility, and version concepts. Recipe
ingredient lines always preserve exact original text separately from optional
structured quantity, unit, generic wording, notes, and catalogue match.
Matches retain score, confidence, review state, provenance, and the selected
catalogue version. No current ingredient row is a recipe line, and no original
recipe-line text can be derived from it.

### Plans, diary, and targets

Plans belong to one user and can be reusable or dated. Entries may refer to a
recipe version, catalogue version, or private one-off item. Planned recipe
entries pin a version; consumed entries snapshot nutrition and item/version
inputs so history is not recalculated. Target profiles and dated phases are
separate owned concepts.

DEC-014 additionally requires public-plan bookmark and deletion lifecycle to
be representable without turning an independent copy into a bookmark. From an
account-deletion request, a bookmarked public plan can retain its stable public
identity and only proven public-safe pinned snapshots while becoming unlisted
and attributed to `Former VibeDietr user`. New bookmarks are then prohibited,
and removal of the final existing bookmark removes the retained plan. A
zero-bookmark or public-safety-invalid plan becomes unavailable immediately.
Original ownership, unavailable plans, and prior visibility may remain in a
protected recoverable state for up to 30 days, then must be removed at final
purge; an authenticated user may waive that recovery period. A confirmed
under-13 account is purged without recovery, subject to the sole-administrator
safeguard and a scoped incident, dispute, or statutory hold. A retained plan
must no longer be reclaimable or reattributable after final purge.

There is no current data to backfill into any recipe, planning, diary, target,
snapshot, plan-bookmark, recovery, or anonymized-plan structure.

## 4. Known differences between current and target state

| Area | Current state | Target requirement and migration consequence |
| --- | --- | --- |
| Identity | One `Ingredient` row combines a private record, food identity, package data, and nutrition. | Add separate stable catalogue identity/version structures. Preserve `ingredients` unchanged during transition. |
| User relationship | Required owner; user deletion cascades the row. | Nullable submitter/provenance; user deletion nulls attribution. Do not alter the current foreign key in place. |
| Access | Owner-only listing, viewing, editing, and deletion. | Approved catalogue data is shared; pending manual records remain restricted; ordinary users cannot edit/delete imported shared records. Cut-over requires the authorization matrix in FND-03. |
| Barcode | Optional and non-unique, but excluded from ordinary writes. Trusted imports record source/time and `machine_imported`; pre-STB-08 values are `legacy_unknown`. | A target barcode record represents a successful machine import and barcode identity is globally consistent. Legacy barcode rows need provenance classification before promotion. |
| De-duplication | Duplicate barcodes can exist within or across users; manual duplicate rules do not exist. | Candidate detection is allowed, but no reassignment or merge is allowed without an approved rule. DEC-011 remains unresolved. |
| Package data | One required quantity/unit pair; zero is the database default. | Separate nullable package and internal-item concepts; unknown is null. Legacy zero and multipack values are ambiguous and require review rather than reinterpretation. |
| Serving data | Independently nullable serving amount/unit plus ambiguously named recommended servings. | Explicit structure and derivation basis. Incomplete pairs and ambiguous recommended-servings values cannot be silently normalized. |
| Nutrition | Flexible JSON with an unenforced shape and mixed imported/manual values. | Versioned values with basis, units, source precision, and provenance. Raw JSON can be copied losslessly before any normalization. |
| Tags | OFF and user-authored keywords/categories share arrays. | Provider classifications and user organisation must remain distinguishable. Existing arrays cannot be assigned a source without evidence. |
| Versioning | `updated_at` is mutable record history only. | Stable catalogue and recipe versions plus immutable consumption snapshots. Do not use `updated_at` as a source or version identifier. |
| Original text | `name` may be overwritten by OFF and is not recipe-line text. | Recipe lines preserve exact original text. Do not populate recipe original text from ingredient names. |
| Recipes/plans | No tables, models, or relationships. | Add as new domains through their roadmap items; there is no legacy row backfill. |

## 5. Risks

- **Cascading data loss:** changing or reusing the current `user_id` foreign key
  could delete data when a user is removed. The target relationship must be a
  separate nullable foreign key with null-on-delete semantics.
- **False import provenance:** a legacy barcode may have been typed manually.
  Barcode presence alone must not mark a row as verified OFF data.
- **Duplicate identity:** globally duplicated barcodes may conflict with a
  target uniqueness rule. Preserve each source row as a candidate/mapping and
  quarantine conflicts rather than dropping or merging one.
- **Owner loss or reassignment:** grouping candidates could obscure which user
  supplied which legacy row. The source `ingredient_id` and `user_id` must be
  retained and reconciled.
- **Semantic corruption:** zero/unknown quantity, multipacks, independent
  serving fields, and `recommended_servings` cannot be transformed reliably
  without additional evidence.
- **Nutrition corruption:** normalizing arbitrary JSON before precision,
  units, basis, and provenance are settled can discard valid source data or
  turn missing values into zero.
- **Behavior drift during coexistence:** controller and Livewire paths already
  differ. Dual writes could widen divergence unless characterization and
  compatibility tests exist first.
- **Partial or non-idempotent backfill:** interruption could create duplicate
  target rows or inconsistent mappings. Source-key uniqueness, checkpoints,
  transactions of bounded size, and rerun tests are required.
- **Concurrent writes:** records created or updated during a long backfill may
  be missed. Use a recorded high-water mark plus repeat/catch-up passes or an
  equivalent reviewed strategy.
- **Premature cut-over:** unresolved/failed mappings could disappear from the
  UI. The old path must remain available for unmapped records until validation
  and approval are complete.
- **Rollback after new-only writes:** once target-only user data exists, simply
  switching back may hide it. Compatibility/dual-write or a reviewed reverse
  synchronization approach is required until contract begins.
- **Backup limitations:** a backup is not a rollback plan until restoration is
  tested. DEC-012 constrains retention and restore-time erasure handling.
- **Privacy/audit conflict:** provenance and migration evidence can contain
  user identifiers. Retention must be minimized and governed by DEC-013 and
  later privacy work.
- **Retained-plan disclosure:** a bookmarked public plan could retain private
  fields or follow a live reference into private content. Retention must copy
  or preserve only the authorized pinned presentation snapshot, validate the
  complete plan as public-safe, and make the entire plan unavailable when that
  cannot be proven.
- **Recovery and anonymization divergence:** public attribution is removed at
  the deletion request while protected reattribution remains possible only
  during an unwaived recovery period of up to 30 days. The recoverable owner
  link must not leak into public reads, and final or immediate purge must remove
  it without breaking a qualifying ownerless retained plan.

## 6. Expand phase

### Purpose

Introduce target structures and compatibility seams without changing,
renaming, or removing current data or behavior.

### Proposed changes

- Add new shared catalogue identity, version, package/serving, normalized
  nutrition/provenance, and legacy-mapping structures through later backlog
  items. Exact shapes belong to FND-06 and NUT-01/NUT-04/NUT-05.
- Give the target submitter reference nullable/null-on-delete behavior from its
  first migration. Do not repurpose `ingredients.user_id`.
- Add a migration ledger keyed uniquely by legacy `ingredient_id`, with legacy
  `user_id`, classification/status, target references when safe, error/review
  reason, and timestamps/checkpoint metadata.
- Permit an unresolved candidate state so duplicate or ambiguous rows do not
  need to satisfy final catalogue uniqueness constraints.
- Add read/write compatibility behind explicitly tested application seams or
  rollout controls in later implementation tasks. Existing routes continue to
  use `ingredients` initially.
- Add recipe, recipe-line, and planning tables only in their own roadmap items;
  point them at target version identities, never directly at mutable legacy
  ingredient fields.
- When public plans and their bookmarks are introduced, preserve a stable
  public identity separately from owner attribution and discovery state.
  Represent an optional protected recovery state of up to 30 days, ownerless
  anonymized retention, and explicit immediate-purge paths for a waiver or
  confirmed under-13 account. Also represent disabled post-request bookmarking,
  final-bookmark deletion, public-safety validation, and independent copies
  without repurposing a live owner foreign key or dereferencing live private
  recipe data.

### Preconditions

- FND-01 is complete and this plan is approved.
- STB-01 characterizes current behavior; STB-02 provides migration-era
  fixtures; STB-03 protects mutation boundaries before coexistence expands the
  attack surface.
- FND-03 defines authorization/privacy rules before shared reads are enabled.
- Exact schema review confirms MySQL types, indexes, foreign keys, nullability,
  and migration down behavior against populated fixtures.
- FND-06 and DEC-003/DEC-004 are resolved before normalized nutrient storage is
  finalized. Raw legacy JSON can be preserved without waiting.

### Expected application compatibility

Current application reads and writes remain unchanged. New nullable structures
may be empty. No current route, policy, model relationship, or user deletion
behavior is switched in this phase.

### Validation required

- Migration up/down tests on populated fixtures for each additive change.
- Schema assertions for new foreign-key deletion behavior and indexes.
- Existing ingredient characterization, authorization, and feature tests.
- Proof that applying the expand migrations does not change any current
  `ingredients` value or row count.

### Rollback expectations

Before target-only writes begin, additive structures may be rolled back by
their reviewed down migrations if doing so cannot remove newly entered data.
After they receive data, application code may stop using them, but dropping
them requires separate approval and evidence that no data would be lost.

### Approval required

Manual technical/data-safety review of every additive schema migration and its
rollback. Shared access or changed user-deletion behavior is not approved by
the expand migration itself.

### Follow-up implementation backlog items

FND-03, FND-05, FND-06, STB-01 through STB-04, STB-08, NUT-01, NUT-04,
NUT-05, REC-01 through REC-03, and PLAN-01 are the identifiable foundations.

## 7. Backfill phase

### Purpose

Copy each legacy ingredient into a lossless migration candidate and create a
target mapping only where the evidence and approved rules make that safe.

### NUT-02 implementation status

NUT-02 implements this phase with
`php artisan catalogue:backfill-legacy-ingredients`. The
`legacy_ingredient_catalogue_mappings` ledger is unique by legacy ID and by
nullable target ID, retains nullable submitter provenance, a lossless source
snapshot/checksum, bounded classification/reason, backfill version and time,
and permits a null target for ambiguous or duplicate evidence.

The command records an ID high-water mark, reads with `chunkById`, commits one
new mapping/candidate per short transaction, and uses both a command lock and
database uniqueness. `--dry-run` shares classification/reconciliation logic
and writes nothing; `--chunk=500` is the bounded default. Existing matching
mappings are reused. A changed source checksum or unexpected exception fails
the run visibly while preserving earlier commits for resume.

Manual and complete STB-08 imports create separate pending catalogue candidates.
Ambiguous and duplicate rows are fully mapped but keep a null candidate
reference. No catalogue version, promotion, merge, relationship redirect,
legacy mutation, or audit event is created. Production execution still
requires manual approval of the dry-run classification/exception report.
DEC-011 remains unresolved and continues to prohibit all merge/de-duplication
action.

### Proposed changes

- Record pre-backfill counts and a stable source range/high-water mark.
- Upsert one mapping/candidate per `ingredients.id`; use the source identifier
  as the idempotency key. Never update or delete the source row.
- Copy all 18 source-column values or a lossless snapshot sufficient to prove
  their preservation, including barcode provenance, JSON documents, and
  timestamps. Retain the original owner identifier in migration provenance.
- Classify null/blank-barcode rows as legacy manual candidates, not approved
  shared records. Do not merge them.
- Classify `machine_imported` rows separately from `legacy_unknown` rows.
  Treat legacy unknown as ambiguous even if its JSON resembles OFF data; do not
  infer verification from barcode or payload shape.
- Detect duplicate barcode and manual-food candidates and report them. Do not
  silently select a winner, merge rows, or redirect ownership.
- Preserve zero quantities, incomplete serving pairs, custom units, arbitrary
  JSON keys, and mixed tags exactly in the candidate snapshot. Add review
  reasons instead of guessing target meanings.
- Process bounded batches transactionally, checkpoint progress, and support a
  dry run plus safe resume. Run catch-up passes for rows created or changed
  after the initial high-water mark.
- Materialize normalized/versioned target data only when the applicable
  precision, provenance, package, and identity rules are approved. Otherwise
  leave the target reference nullable with an explicit status.

### Preconditions

- Expand schema and mapping constraints have passed migration tests.
- NUT-01 exists before target mappings are written; STB-08 exists before new
  barcode rows can be distinguished reliably.
- Backfill classification rules, batch size, locking/concurrency approach,
  and dry-run report are reviewed using production-representative data.
- DEC-011 remains a hard prohibition on automatic merge while unresolved.

### Expected application compatibility

The application continues reading and writing current `ingredients`. Backfill
is observational/copying work and must not change visible ownership, access,
editability, deletion, ordering, or search behavior. If dual writes are added,
failed target writes must be observable and retryable without losing the
successful legacy write until a separately approved atomic strategy exists.

### Validation required

- Source row count equals the distinct mapped/candidate source count for the
  processed range.
- No legacy source row changed during a controlled fixture backfill.
- Every candidate preserves source ownership and has exactly one status.
- Target references, where present, satisfy foreign keys and approved type/
  provenance rules.
- Ambiguous barcode rows, duplicate candidates, incomplete serving data,
  zero/unknown quantities, and unsupported JSON shapes appear in the review
  report rather than disappearing.
- Interrupted and repeated runs produce no duplicate mapping rows and converge
  to the same result.

### Rollback expectations

Disable or stop the backfill, keep `ingredients` authoritative, and delete only
backfill output that is proven derived and has no target-only user changes.
Prefer status reset/replay over deletion once moderation or user actions refer
to a candidate. Source rows remain the recovery source throughout this phase.

### Approval required

Manual approval of the dry-run classifications and exception report before a
production backfill; separate approval before promoting any candidate class to
shared catalogue data.

### Follow-up implementation backlog items

STB-08, NUT-01, NUT-02, NUT-04, and NUT-05. DEC-011 must be resolved before
any implementation introduces manual-food merge behavior.

## 8. Validation phase

### Purpose

Prove completeness, integrity, ownership preservation, semantic safety, and
application compatibility before any read authority changes.

### Proposed changes

- Run the reviewed queries in section 12 against a consistent snapshot or
  documented maintenance window.
- Reconcile counts by status, owner, barcode classification, and source range;
  retain exception reports as migration evidence without unnecessary personal
  data.
- Compare representative field values and JSON snapshots, including null,
  zero, unusual unit, multipack, duplicate, and incomplete-serving cases.
- Exercise shadow reads that compare legacy and target representations without
  changing the response users receive.
- Run authorization/privacy tests for owner, non-owner, administrator, guest,
  pending, approved, imported, and unmapped cases before shared reads.
- Repeat the validation after the final catch-up backfill.

### Preconditions

- Backfill has completed or reached a declared checkpoint with no unreported
  failures.
- Validation thresholds are explicit. The acceptable count of unexplained
  missing mappings, ownership mismatches, and orphaned references is zero.
- All intentionally unresolved records have a visible review status and a
  documented compatibility path.

### Expected application compatibility

Legacy reads remain authoritative. Shadow comparison must not expose pending
or another user's private data and must not add user-visible latency without a
reviewed limit.

### Validation required

- All checks in section 12, adapted to the implemented schema and reviewed
  before use.
- Full relevant backend test suite, migration tests, authorization matrix
  tests, backfill rerun/interruption tests, and query-plan/index review.
- Manual sampling tied back to source IDs; no sampling result can override a
  failed complete-count or referential-integrity check.

### Rollback expectations

Validation itself is read-only. On failure, keep legacy reads/writes active,
mark the affected target range invalid, correct the additive schema or
repeatable backfill, and rerun all affected checks. Do not proceed to cut-over.

### Approval required

Recorded technical and product/data-owner sign-off on the reconciliation and
exception report. Any unresolved record population must have an approved
legacy fallback; it may not be hidden by acceptance thresholds.

### Follow-up implementation backlog items

NUT-02 supplies backfill evidence; FND-03 and STB-01/STB-03 supply access and
behavior evidence; NUT-03 cannot begin its read cut-over until this gate passes.

## 9. Cut-over phase

### Purpose

Move eligible reads and then writes to the shared catalogue while maintaining
a tested route back to the legacy path and preserving access to unmapped data.

### Proposed changes

- Cut over in small, separately reviewable steps: internal/shadow reads,
  eligible approved catalogue reads, selected writes/imports, then legacy-route
  compatibility.
- Keep unmapped/ambiguous legacy ingredients available through the owner-only
  compatibility path. Do not present them as approved shared catalogue data.
- Enforce target authorization: approved shared records are readable as
  specified; pending manual records remain submitter/admin scoped; ordinary
  users cannot edit/delete imported shared records.
- Direct new successful barcode imports to the target model using globally
  consistent identity and idempotency. Do not retrofit unverified legacy
  barcodes into this trusted path.
- Maintain dual-write or forward/reverse synchronization only where it is
  demonstrably safe and observable. Define which store is authoritative for
  each operation at every rollout step.
- Stop creating new legacy-only records only after rollback synchronization is
  proven and all public routes have explicit compatibility behavior.

### Preconditions

- Validation gate has passed with zero unexplained integrity/ownership errors.
- FND-03 authorization/privacy matrix and NUT-03 policies/tests are approved.
- NUT-01 identity/lifecycle records and explicit NUT-02 mappings are available.
  During NUT-03, unmigrated factual fields may be projected read-only from the
  mapped NUT-02 snapshot. NUT-04/NUT-05 package and normalized-nutrition
  structures are deliberately not prerequisites for this identity/read
  cut-over and must not be introduced early.
- Monitoring, reconciliation, support procedure, and rollback trigger are
  documented. A restore point exists for any operation that could affect
  existing production data, even though cut-over should remain non-destructive.

### Expected application compatibility

Old and new paths coexist. Existing URLs either preserve their behavior or
redirect intentionally with authorization intact. Every legacy row remains
reachable by its owner until its handling is validated; no user loses a record
because it is unmapped or pending.

NUT-03 implements this phase with public canonical routes at
`/catalogue` and `/catalogue/{catalogueItem}`. `CATALOGUE_READ_CUTOVER=true`
is the default and is config-cache compatible. The shared identity, explicit
status, and submitter provenance determine access. A privacy-safe projection
reads factual display values only from the unique NUT-02 mapping snapshot;
the snapshot never determines visibility and is never returned raw. Missing,
ambiguous, and duplicate mappings remain in an authenticated owner-only legacy
fallback. Reads never create or guess mappings.

The legacy `ingredients.index` route redirects to the canonical catalogue
while preserving only `q`, `page`, and `legacyPage`. A mapped legacy detail
route resolves its explicit mapping, re-applies catalogue visibility, and
redirects to the canonical catalogue ID. An unmapped or null-target legacy
detail keeps the legacy owner policy. Legacy create/store remain temporarily;
mapped catalogue rows and verified imports are immutable to ordinary users at
both controller and Livewire mutation boundaries.

### Validation required

- Route, policy, serialization, search, duplicate, and user-deletion tests for
  both paths.
- Production reconciliation of new writes and mapping status during each
  rollout step.
- Confirmation that target user deletion nulls submitter provenance while the
  untouched legacy behavior remains understood and isolated.
- Confirmation that rollback does not hide or lose target-only writes.

### Rollback expectations

Until contract begins, disable the target read/write rollout, return authority
to the legacy path, and replay/reconcile any target-only writes through the
pre-approved compatibility process. Keep target tables and evidence intact for
diagnosis. A rollback trigger includes ownership mismatch, inaccessible legacy
records, unintended shared exposure, orphan creation, or irreconcilable write
divergence.

For the NUT-03 read increment, set `CATALOGUE_READ_CUTOVER=false`, clear and
rebuild the configuration cache, and smoke-test the authenticated legacy index
and detail routes. The public catalogue routes then return 404 and mapped
legacy detail routes stop redirecting. Do not delete catalogue identities,
mappings, snapshots, or legacy rows. The mutation denial for verified imports
and catalogue-managed legacy rows remains a shared-catalogue authorization
boundary rather than a read toggle.

### Approval required

Manual approval for each rollout increment, based on current validation and
rollback evidence. Shared visibility and mutation restrictions also require
product/security review against FND-03.

### Follow-up implementation backlog items

NUT-03, NUT-06, NUT-07, NUT-08 through NUT-11, and UX-01. Recipe matching and
nutrition work then builds on stable catalogue versions through NUT-12 to
NUT-18.

## 10. Contract phase

### Purpose

Retire obsolete compatibility structures only after the target paths have
been stable, complete, and independently recoverable. Contract is a new
project decision point, not an automatic continuation of cut-over.

### Proposed changes

- First stop legacy writes and observe a defined stabilization period while
  the table remains readable.
- Archive or retain a lossless legacy snapshot/mapping for the approved period
  before considering schema removal.
- Propose renames, column removals, foreign-key changes, or table removal only
  as separate, small backlog items with exact impact analysis.
- Resolve every unresolved mapping or explicitly approve a durable owner-only
  legacy archive path. “Could not map” is not permission to delete.
- Remove compatibility code only after no supported route, model, job, export,
  audit reference, snapshot, or rollback procedure depends on it.

### Preconditions

- A defined stabilization period has passed with reconciliations clean.
- All existing rows are accounted for, ownership is preserved, and target-only
  data has independent backup/restore coverage.
- DEP-06 backup/restore runbooks and a successful restore drill exist.
- DEC-012 is resolved for backup erasure/restoration handling; relevant
  controls from the approved DEC-013 schedule are implemented for migration
  provenance, purge evidence, access, deletion, and scoped holds.
- A current, verified production backup or restore point is captured before
  every destructive operation.
- The exact destructive diff, data disposition, rollback boundary, downtime/
  locking risk, and restore procedure receive explicit manual approval.

### Expected application compatibility

All supported application behavior uses target structures. A read-only legacy
compatibility window should precede physical removal. Contract must not begin
while an active client, job, route, or export still needs the legacy schema.

### Validation required

- Zero legacy writes during the agreed observation window.
- Zero unexplained unmapped rows, ownership mismatches, or orphaned target
  references.
- Dependency search across code, jobs, reports, exports, and operational
  tooling.
- Backup verification and restore rehearsal using representative public,
  private, pending, duplicate, and anonymized data.
- Pre/post-operation row counts, checksums or field reconciliation, and
  application smoke/authorization tests.

### Rollback expectations

Ordinary application rollback is guaranteed only until contract begins. Once
a destructive contract step starts, recovery may require restoring the
verified backup or applying an approved forward fix. Each contract item must
state its point of no simple rollback, restoration time/risk, and abort
criteria before execution.

### Approval required

Explicit manual product owner, technical/data owner, and operations approval
for each contract step. No earlier roadmap item, this document, or a successful
cut-over authorizes destructive schema work.

### Follow-up implementation backlog items

No existing roadmap item authorizes removal of the legacy table or columns. A
small, explicit cleanup item must be added only after the above gates are met.
DEP-06 provides the required migration and restore runbooks; DEP-08/DEP-09
govern later account-erasure and privacy behavior.

## 11. Rollback expectations

- **Expand:** keep current tables authoritative. Revert unused additive schema
  only when it contains no unique data; otherwise disable its use and retain
  it pending review.
- **Backfill:** stop safely between batches, retain checkpoints and errors, and
  rerun idempotently. Never “roll back” by editing or deleting source rows.
- **Validation:** failed checks block progression; correct and repeat. No user-
  visible authority has moved.
- **Cut-over:** switch traffic back to the legacy path and reconcile target-only
  writes with the pre-approved process. Target records remain available for
  diagnosis and replay.
- **Contract:** simple rollback is no longer assumed. A verified backup/restore
  point and rehearsed recovery procedure are mandatory before destruction.

Rollback decisions must be based on data accessibility and integrity, not only
whether an application deployment can be reverted. Ownership mismatch,
unintended disclosure, row loss, orphan creation, or inability to reconcile
new writes is an immediate stop/rollback condition before contract.

## 12. Validation queries

The following are **proposed read-only examples**. They must be reviewed and
adapted to the actual implemented schema, names, database engine, status values,
and rollout boundary before use. Illustrative target names below are
`legacy_ingredient_mappings`, `catalogue_items`, and
`catalogue_item_versions`; this document does not approve those names.

Record the results with a consistent snapshot/high-water mark so concurrent
writes do not produce misleading comparisons.

### Row counts before and after backfill

```sql
SELECT COUNT(*) AS source_rows
FROM ingredients;

SELECT COUNT(*) AS mapping_rows,
       COUNT(DISTINCT ingredient_id) AS distinct_source_rows
FROM legacy_ingredient_mappings;

SELECT i.user_id, COUNT(*) AS source_rows,
       COUNT(m.ingredient_id) AS mapped_or_classified_rows
FROM ingredients AS i
LEFT JOIN legacy_ingredient_mappings AS m
  ON m.ingredient_id = i.id
GROUP BY i.user_id
ORDER BY i.user_id;
```

For a bounded backfill, add the reviewed source-ID or timestamp boundary to
both sides rather than comparing a moving full table.

### Missing mappings and records that could not be backfilled safely

```sql
SELECT i.id, i.user_id, i.barcode, i.updated_at
FROM ingredients AS i
LEFT JOIN legacy_ingredient_mappings AS m
  ON m.ingredient_id = i.id
WHERE m.ingredient_id IS NULL;

SELECT ingredient_id, legacy_user_id, backfill_status, review_reason
FROM legacy_ingredient_mappings
WHERE catalogue_item_id IS NULL
   OR backfill_status IN ('ambiguous', 'failed', 'needs_review');
```

The status literals are placeholders and must match the implemented status
model. An empty result is required only for statuses expected to be complete;
review states may be intentionally non-empty but must remain visible.

### No orphaned records and foreign-key consistency

```sql
SELECT i.id, i.user_id
FROM ingredients AS i
LEFT JOIN users AS u ON u.id = i.user_id
WHERE u.id IS NULL;

SELECT m.ingredient_id
FROM legacy_ingredient_mappings AS m
LEFT JOIN ingredients AS i ON i.id = m.ingredient_id
WHERE i.id IS NULL;

SELECT m.ingredient_id, m.catalogue_item_id
FROM legacy_ingredient_mappings AS m
LEFT JOIN catalogue_items AS c ON c.id = m.catalogue_item_id
WHERE m.catalogue_item_id IS NOT NULL
  AND c.id IS NULL;

SELECT c.id, c.submitted_by_user_id
FROM catalogue_items AS c
LEFT JOIN users AS u ON u.id = c.submitted_by_user_id
WHERE c.submitted_by_user_id IS NOT NULL
  AND u.id IS NULL;

SELECT c.id, c.current_version_id
FROM catalogue_items AS c
LEFT JOIN catalogue_item_versions AS v
  ON v.id = c.current_version_id
 AND v.catalogue_item_id = c.id
WHERE c.current_version_id IS NOT NULL
  AND v.id IS NULL;
```

### No unexpected null mappings

```sql
SELECT ingredient_id, backfill_status, review_reason
FROM legacy_ingredient_mappings
WHERE catalogue_item_id IS NULL
  AND (backfill_status IS NULL OR review_reason IS NULL);

SELECT ingredient_id, catalogue_item_id, backfill_status
FROM legacy_ingredient_mappings
WHERE catalogue_item_id IS NOT NULL
  AND backfill_status NOT IN ('mapped', 'validated');
```

The allowed states must be reviewed. The purpose is to ensure every null target
mapping is intentional and explainable, not to force an unsafe mapping.

### Preservation of ownership/provenance

```sql
SELECT i.id, i.user_id AS source_owner, m.legacy_user_id AS recorded_owner
FROM ingredients AS i
JOIN legacy_ingredient_mappings AS m
  ON m.ingredient_id = i.id
WHERE NOT (i.user_id <=> m.legacy_user_id);

SELECT i.id, i.user_id AS source_owner,
       c.submitted_by_user_id AS target_submitter
FROM ingredients AS i
JOIN legacy_ingredient_mappings AS m ON m.ingredient_id = i.id
JOIN catalogue_items AS c ON c.id = m.catalogue_item_id
WHERE m.backfill_status IN ('mapped', 'validated')
  AND NOT (i.user_id <=> c.submitted_by_user_id);
```

MySQL's null-safe equality operator `<=>` is used here. Any intentional
anonymization must be excluded only under a separately approved deletion flow,
not treated as a migration mismatch to ignore.

### Duplicate catalogue candidates

```sql
SELECT barcode, COUNT(*) AS candidate_rows,
       COUNT(DISTINCT user_id) AS contributing_users
FROM ingredients
WHERE barcode IS NOT NULL
  AND TRIM(barcode) <> ''
GROUP BY barcode
HAVING COUNT(*) > 1
ORDER BY candidate_rows DESC, barcode;

SELECT LOWER(TRIM(name)) AS exact_normalized_name,
       quantity, quantity_unit, COUNT(*) AS candidate_rows
FROM ingredients
WHERE barcode IS NULL OR TRIM(barcode) = ''
GROUP BY LOWER(TRIM(name)), quantity, quantity_unit
HAVING COUNT(*) > 1
ORDER BY candidate_rows DESC;
```

The second query identifies only exact candidate groups; it is not an approved
identity, de-duplication, or merge rule. DEC-011 must be resolved before such
candidates are acted upon.

### Unsafe or ambiguous legacy shapes

```sql
SELECT id, user_id, barcode
FROM ingredients
WHERE barcode IS NOT NULL
  AND TRIM(barcode) <> '';

SELECT id, user_id, quantity, quantity_unit
FROM ingredients
WHERE quantity = 0;

SELECT id, user_id, serving_quantity, serving_quantity_unit
FROM ingredients
WHERE (serving_quantity IS NULL) <> (serving_quantity_unit IS NULL);

SELECT id, user_id, recommended_servings
FROM ingredients
WHERE recommended_servings IS NOT NULL;

SELECT id, user_id, JSON_KEYS(nutriments) AS top_level_keys
FROM ingredients
WHERE nutriments IS NOT NULL
  AND (
    JSON_TYPE(nutriments) <> 'OBJECT'
    OR JSON_CONTAINS_PATH(nutriments, 'one', '$.raw', '$.per_100g', '$.per_serving') = 0
  );
```

Every `legacy_unknown` barcode row is a provenance-review candidate.
`machine_imported` is reserved for post-STB-08 trusted imports with source and
server import time. Quantity zero, recommended servings, incomplete serving
pairs, and unusual JSON are reports for review, not permission to rewrite the
source.

### Laravel query-builder count reconciliation

```php
$sourceCount = DB::table('ingredients')->count();
$classifiedCount = DB::table('legacy_ingredient_mappings')
    ->distinct()
    ->count('ingredient_id');

$missingSourceIds = DB::table('ingredients as i')
    ->leftJoin('legacy_ingredient_mappings as m', 'm.ingredient_id', '=', 'i.id')
    ->whereNull('m.ingredient_id')
    ->pluck('i.id');
```

This is proposed validation logic, not migration implementation. It must use a
consistent boundary and the implemented mapping name.

## 13. Approval gates

1. **Plan gate:** product owner and technical reviewer accept this sequence and
   confirm that no unresolved decision has been selected.
2. **Expand-design gate:** exact additive schema, foreign keys, indexes,
   migration rollback, authorization boundary, and populated-fixture tests are
   approved before a Laravel migration is authored or run.
3. **Backfill gate:** dry-run counts, classifications, duplicate reports,
   concurrency strategy, idempotency evidence, and failure handling are
   approved before production copying.
4. **Validation gate:** zero unexplained missing mappings, ownership
   mismatches, or orphans; all exceptions have an explicit safe state; relevant
   automated tests pass.
5. **Cut-over gate:** each read/write rollout increment has current
   reconciliation evidence, authorization approval, monitoring, rollback
   triggers, and a tested legacy fallback.
6. **Contract-readiness gate:** stabilization is complete, no dependency uses
   the legacy schema, every row is accounted for, DEP-06 restore evidence
   exists, and relevant DEC-012/DEC-013 outcomes are recorded.
7. **Destructive-operation gate:** the exact operation receives fresh manual
   product, technical/data, and operations approval, with a verified backup or
   restore point. Approval of this plan does not satisfy this gate.

Any failed gate stops progression. An exception report cannot convert data
loss, hidden data, ownership change, or an unexplained orphan into an accepted
outcome.

## 14. Decision dependencies and blockers

No unresolved decision blocks creating this document. The decisions below
either constrain later implementation or identify a remaining blocker.
DEC-013 and DEC-014 are decided and now supply mandatory audit, deletion,
planning, recovery, anonymization, and retention constraints rather than
leaving those behaviours open.

| Decision | Relationship to this migration |
| --- | --- |
| DEC-001 — Food-matching confidence thresholds | Related to later recipe-line match data (NUT-12/NUT-13). It does not block catalogue copying or additive recipe-line storage, but no backfill may invent match scores or select catalogue matches for future recipe lines. |
| DEC-002 — Food-match review-warning treatment | Related to later display/review behavior. It does not block schema expansion; it constrains how review states are eventually exposed and must not be encoded as a chosen UI in this plan. |
| DEC-003 — Nutrient storage precision | Decided and implemented by FND-06's shared decimal/nutrient definitions. It constrains STB-06, NUT-02, NUT-05, and NUT-15; normalized values use exact DECIMAL(38,18) rules without destructive display rounding. |
| DEC-004 — Nutrient display precision | Decided and implemented by FND-06's nutrient metadata/formatter. It constrains STB-06 and later displays; cut-over must consume the shared table rather than invent local precision. |
| DEC-009 — Initial administrator assignment | Decided. Its resolution enabled FND-04 administrator persistence and central authorization. Operational bootstrap and lifecycle are implemented by FND-14 through the DEC-009 controls. |
| DEC-010 — Moderation escalation and service levels | Related to the eventual queue and constrained moderation operations. It does not block modelling basic states, but the migration cannot promise response times, escalation, or stale-item handling. |
| DEC-011 — Manual-food de-duplication and merge rules | Constrains FND-02/NUT-02 and blocks NUT-08. Candidate detection/reporting is safe; reassignment, merging, or deletion is blocked. |
| DEC-012 — Backup erasure timing | Explicitly constrains FND-02 and blocks DEP-06/DEP-08/DEP-09. It does not block additive planning, but destructive production contract work is blocked until backup retention and restore-time erasure handling are resolved. |
| DEC-013 — Security and legal audit retention | Decided. It unblocks FND-05 and removes the DEC-013 dependency from DEP-08 and DEP-09. Migration provenance must be purpose-classified, data-minimized, access-controlled, and deleted under `AUDIT_RETENTION_SCHEDULE.md`; relevant purge receipts, actor-mapping destruction, hold handling, and deletion verification must be implemented before destructive contract work. DEC-012 still blocks final backup behavior. |
| DEC-014 — Public meal plans after owner deletion | Decided. It does not affect current ingredient copying. Future planning structures must support automatic bookmark-qualified retention, immediate anonymization and unlisting at the deletion request, stable URLs, disabled new bookmarks, last-bookmark deletion, public-safe snapshot minimization, protected 30-day restoration, final removal of recovery attribution, non-reclaimable retained plans, independent-copy survival, and exceptional administrator removal without administrator restoration or transfer. |

Relevant roadmap sequence:

- FND-03/FND-04/FND-05/FND-06 define authorization, audit, nutrient, and measurement
  foundations.
- STB-01 through STB-04 and STB-08 stabilize and classify the current paths.
- NUT-01 adds target catalogue identity; NUT-02 performs the additive backfill;
  NUT-03 cuts reads over; NUT-04/NUT-05 model package and nutrition; NUT-06 and
  later items add target behavior.
- REC-01 through REC-03 introduce new recipe data without deriving recipe
  lines from ingredients. PLAN-01 and later plan items introduce new planning
  data and snapshots.
- PLAN-08 introduces the public-plan bookmark and sharing state required by
  DEC-014; PLAN-09 preserves independent copy behavior; DEP-08 applies the
  recoverable transition, anonymization, and final purge rules.
- DEP-06 provides backup, restore, and migration runbooks before destructive
  production work.

There is intentionally no existing roadmap item that authorizes contract-time
removal of `ingredients`. Such work would require a later, narrowly scoped
backlog item after the contract-readiness gate; creating that item now is not
necessary to execute the safe additive stages.

## 15. Out of scope

This documentation task does not:

- Create or run Laravel migrations.
- Rename or remove database tables.
- Rename or remove columns.
- Remove or alter existing foreign keys.
- Modify existing application data.
- Change model relationships in application code.
- Introduce recipes, meal plans, or shared catalogue behaviour.
- Select unresolved product behaviour.
- Perform a production deployment.
- Authorise any destructive schema step.

Later implementation tasks must be separately approved and completed as small,
reviewable changes. In particular, each schema addition, backfill command,
read/write cut-over, and eventual contract proposal requires its own evidence,
tests, rollback treatment, and approval.
