# Current state

## Purpose and reading policy

This document is the concise index of behavior visible in the working
repository. It is not a product specification or a delivery history.

- **Application baseline:** `bab994f90b8532603d13514459da6f00b4972025`
  (NUT-11 merge, 2026-09-10).
- **Last reviewed:** 2026-09-10.
- This documentation restructure changes no application behavior after that
  baseline.
- Use [Product specification](PRODUCT_SPEC.md) for intended behavior,
  [Decisions](DECISIONS.md) for owner choices and unresolved blockers, and
  [Roadmap](ROADMAP.md) for task status and acceptance criteria.
- Use [Generated context index](CONTEXT_INDEX.md) for reverse task, decision,
  and domain-section lookup.
- The preserved [detailed implementation history](IMPLEMENTATION_HISTORY.md)
  contains milestone-level design and delivery evidence. Read only the
  relevant section; earlier phase statements may be superseded.

Code and tests remain the final evidence of implementation. This file should be
updated when application behavior or a canonical architecture boundary changes,
not after every internal refactor.

## Application shape

- Laravel 12, Livewire 3.6, Volt 1.7, Tailwind CSS, Alpine.js, and Vite form the
  application and browser stack.
- The supported local environment is Laravel Sail with PHP 8.4,
  container-supplied Node 22, MySQL 8.0, database-backed sessions/cache/queues,
  and local Mailpit.
- Authentication and profile screens derive from Laravel Breeze. Email
  verification routes exist, but `User` does not implement
  `MustVerifyEmail`, so verification is currently optional.
- The public landing page and authenticated dashboard remain minimal Laravel
  defaults. Product identity and primary navigation remain roadmap work.
- GitHub Actions exposes the required `Backend tests`, `PHP formatting`,
  `Static analysis`, and `Frontend build` checks. The frontend job also
  validates environment alignment, scanner behavior, documentation, and the
  production asset build.
- Larastan/PHPStan runs at level 5 with a reviewed baseline. Do not infer the
  count or meaning of remaining findings from this summary; inspect
  `phpstan-baseline.neon` when analysis work is relevant.
- Meilisearch, Selenium, and Redis are provisioned by Compose but are not the
  application's active search, browser-test, or default cache/queue drivers.

## Capability index

| Area | Current capability | Main entry points | Canonical detail |
| --- | --- | --- | --- |
| Accounts and profiles | Registration, authentication, password management, profile updates, immediate account deletion, theme selection, and optional public attribution profiles | `routes/auth.php`, `routes/web.php`, `app/Domain/Profiles` | REC-14; authorization/deletion rules in [authorization and privacy matrix](AUTHORIZATION_PRIVACY_MATRIX.md) |
| Administrator security | Central administrator gate, confirmed TOTP and recovery, production readiness checks, bootstrap, promotion, revocation, last-administrator protection, and break-glass replacement | `app/Administrator`, `app/Security/SecondFactor`, `routes/security.php` | [Security foundations](ADMINISTRATOR_SECURITY_FOUNDATIONS.md), [administrator lifecycle](ADMINISTRATOR_LIFECYCLE.md) |
| Audit events | Append-only application API, minimized allowlisted payloads, erasable actor identity mapping, integrity hashes, and scoped reads | `app/Audit`, `app/Models/AuditEvent.php` | [Audit events](AUDIT_EVENTS.md), [retention schedule](AUDIT_RETENTION_SCHEDULE.md) |
| Legacy ingredients | Owner-scoped CRUD compatibility path, shared validation/normalization, explicit-zero preservation, and machine-owned barcode provenance | `app/Domain/Ingredients`, `app/Livewire/Ingredients`, `IngredientController` | STB-01 through STB-09; retained migration behavior in [domain migration plan](DOMAIN_MIGRATION_PLAN.md) |
| Recipes | Owned drafts, ordered ingredients/instructions, finalization, immutable versions, revisions, visibility, resizing, discovery, bookmarks, remixes, collections, private/public tags, managed classifications, and attribution | `app/Domain/Recipes`, `RecipeController`, recipe routes and Livewire forms | REC-01 through REC-14; concepts in [domain model](DOMAIN_MODEL.md) |
| Recipe imports | Pasted text, validated webpage imports, and supported text-document/still-image uploads materialize into drafts through queued workflows | `app/Domain/RecipeImports`, `app/Integrations/RecipeWebpages`, `RecipeImportController` | REC-15 through REC-17; [security controls](SECURITY_CONTROLS.md) and queue documentation |
| Shared catalogue | Stable identities and immutable versions, package/serving structure, normalized nutrient observations/facts, legacy backfill evidence, public read cut-over, and verified barcode import | `app/Domain/Catalogue`, catalogue models, `CatalogueController` | NUT-01 through NUT-06; [domain model](DOMAIN_MODEL.md) and [OpenFoodFacts integration](OPENFOODFACTS_INTEGRATION.md) |
| Matching and submissions | Recipe-line manual catalogue matches and private pending manual-food submissions with deterministic reuse/duplicate evidence | `RecipeIngredientMatchManager`, `ManualCatalogueSubmissionCreator` | NUT-07 and NUT-08; catalogue sections of [domain model](DOMAIN_MODEL.md) |
| Catalogue moderation | Administrator queues, explicit duplicate decisions and bounded merges, immutable correction proposals, and moderated OpenFoodFacts refresh proposals | moderation controllers and `app/Domain/Catalogue` services | NUT-09 through NUT-11; [moderation](CATALOGUE_MODERATION.md), [corrections](CATALOGUE_CORRECTIONS.md), [provider refreshes](CATALOGUE_PROVIDER_REFRESHES.md) |
| Measurements and nutrition | Shared exact-decimal nutrient/unit definitions, same-dimension conversions, legacy ingredient normalization, and versioned catalogue nutrition | `app/Domain/Measurements`, `app/Domain/Nutrition` | FND-06, STB-05/STB-06, NUT-04/NUT-05; [measurement definitions](NUTRIENT_MEASUREMENT_DEFINITIONS.md) |
| Queues and scheduling | Idempotency, overlap protection, safe payload conventions, failure reporting/removal, worker configuration, schedule locking, and runbooks | `app/Queue`, `app/Jobs`, `routes/console.php` | [Job conventions](QUEUED_JOB_CONVENTIONS.md), [inventory](JOB_INVENTORY.md), [operations](QUEUE_OPERATIONS.md) |
| Security and operations | Shared headers, throttles, upload/transient-input controls, redaction, production configuration validation, health endpoints, telemetry, and monitoring | `app/Security`, `app/Configuration`, `app/Observability` | [Security controls](SECURITY_CONTROLS.md), [production configuration](PRODUCTION_CONFIGURATION.md), [observability](OBSERVABILITY.md), [runbooks](OPERATIONS_RUNBOOKS.md) |
| Development context | Validated task resolver plus generated reciprocal roadmap/decision/domain indexes | `scripts/docs/task-context.mjs`, `scripts/docs/context-index.mjs` | [README task workflow](../README.md#roadmap-task-context) |

## Canonical architecture boundaries

| Boundary | Current rule | Canonical code or documentation |
| --- | --- | --- |
| Authorization | Policies, gates, and action-level checks enforce access; UI visibility is never sufficient authorization | `app/Policies`, `AppServiceProvider`, [authorization/privacy matrix](AUTHORIZATION_PRIVACY_MATRIX.md) |
| Administrator privilege | Ordinary application code cannot assign or revoke administrator status; FND-13/FND-14 readiness and lifecycle services own privileged workflows | `app/Administrator`, `app/Security`, administrator guides |
| Recipe lifecycle | Draft, finalization, revision, visibility, and version mutation pass through application-owned recipe services; published snapshots remain immutable | `app/Domain/Recipes` |
| Catalogue reads | `CatalogueReadQuery` and `CatalogueVisibility` are the shared visibility/query boundary; public projections exclude private moderation and provenance data | `app/Domain/Catalogue/CatalogueReadQuery.php`, `CatalogueVisibility.php` |
| Catalogue writes | Submission, import, moderation, correction, refresh, and merge services own state transitions and version creation; callers do not silently edit shared facts | `app/Domain/Catalogue`, catalogue workflow guides |
| OpenFoodFacts | All provider HTTP and response mapping stays behind application-owned typed results | `app/Integrations/OpenFoodFacts`, [integration guide](OPENFOODFACTS_INTEGRATION.md) |
| Nutrition and measurements | Exact decimals, registered nutrients/units, explicit bases, source provenance, and immutable catalogue versions remain authoritative; unsupported conversion is not guessed | `app/Domain/Nutrition`, `app/Domain/Measurements` |
| Imports and uploads | Inputs are untrusted, bounded, transient where required, owner-scoped, and materialized only through the import domain boundary | `app/Domain/RecipeImports`, `app/Security/Uploads`, `app/Integrations/RecipeWebpages` |
| Audit | `AuditEventRecorder` and allowlisted enums own ordinary audit creation; secrets and unnecessary personal data are rejected | `app/Audit`, [audit guide](AUDIT_EVENTS.md) |
| Async work | Jobs carry safe identifiers, define idempotency and concurrency behavior, and require inventory plus operational coverage | `app/Jobs`, `app/Queue`, queue guides |
| Production readiness | Configuration, queue, notification, health, monitoring, and operational checks fail closed where their documented production boundary is unmet | production, observability, queue, and administrator-security guides |

## Current gaps and constraints

These are current implementation facts, not permission to invent the missing
behavior. Use the task resolver and decision register before starting related
work.

- NUT-15 stores policy-versioned whole-recipe and per-serving nutrition
  estimates in each immutable recipe-version snapshot. Each supported nutrient
  aggregates independently from structured quantities and the exact catalogue
  versions pinned by recipe-line matches. Calculation traces retain source
  quantities, normalized nutrient facts and policies, reliable food-conversion
  evidence, contributions, and explicit exclusions. Decimal calculation keeps
  guard precision until the persisted snapshot boundary; presentation uses the
  shared display formatter without changing stored values. Custom units,
  unsupported counts, missing or unapproved food conversion data, missing
  provenance, invalid dimensions, and ambiguous duplicate nutrient bases are
  never guessed.
- NUT-16 presents finalized whole-recipe and per-serving values as estimates
  with complete, partial, or unavailable status. Every supported nutrient stays
  visible; unavailable values say so rather than appearing as zero, while
  genuine calculated zero values retain numeric display. Partial estimates keep
  supported values and identify each affected original ingredient line,
  including unmatched lines, conversion exclusions, nutrient gaps, and
  review-needed automatic matches. Owners can open the corresponding line in
  the existing recipe revision and catalogue-match editor; readers see the
  limitation without edit controls.
- NUT-17 selects a recipe version's primary nutrition source in the fixed order
  creator override, imported-source nutrition, then ingredient estimate.
  Structured webpage nutrition is normalized to the shared per-serving
  nutrient representation and snapshotted with import, extractor, and parser
  provenance into the immutable recipe version. Lower-precedence data remains
  intact; ingredient estimates appear as a collapsed comparison when they are
  not primary. Owners may add, change, or remove an override only against the
  current finalized version, with stale-version rejection. Append-only override
  history retains prior and resulting values and sources, server timestamp,
  actor, optional note, and its corresponding FND-05 audit reference. Selection
  and history are version-scoped and are not copied into revisions.
  Recalculation and nutrition claims remain assigned to NUT-18. DEC-002 remains
  unresolved for broader review-warning UX under UX-04.
- Meal plans, consumption snapshots, targets, plan sharing, and plan
  comparisons are not represented (PLAN-01 through PLAN-12).
- Product identity, primary navigation, broader responsive/accessibility work,
  and general onboarding remain planned (UX-01 through UX-07). DEC-002 blocks
  UX-04.
- Backup/restore, self-service account export, delayed deletion/recovery,
  privacy/legal launch review, and release-readiness work remain incomplete
  (DEP-06 through DEP-10). DEC-008, DEC-010, and DEC-012 remain unresolved.
- Account deletion is still immediate. Legacy ingredient rows cascade with the
  account; shared catalogue submitter references are nullable provenance and
  survive account deletion.
- Production administrator workflows exist but remain unavailable until the
  documented notification/provider, worker, clock, audit, destination, and
  monitoring readiness checks pass.
- Email verification remains optional and a production outbound-email service
  has not been selected.
- OpenFoodFacts mapping intentionally uses the reviewed v3.4 compatibility
  profile. A later provider nutrition-schema version requires an explicit
  mapper review.
- The retained legacy ingredient path still stores provider-shaped,
  unversioned nutrition JSON and has no per-user database uniqueness invariant
  for barcodes. Native shared-catalogue imports use the global catalogue
  barcode constraint.
- There is no general browser/end-to-end, accessibility, or visual-regression
  suite. The barcode scanner has deterministic Node coverage but still needs
  physical-camera checks where relevant.
- Measurement presentation remains duplicated in retained ingredient views,
  and the static-analysis baseline contains reviewed existing findings.
- Direct identity-level repair/removal authority for a wrong or obsolete
  barcode-imported catalogue record is not yet recorded as a product decision.
  If work depends on that choice, register and resolve it rather than inferring
  administrator authority.

## Verification map

Use focused tests during development and the applicable final Definition of
Done gate before completion.

| Area | Focused test location or command |
| --- | --- |
| Accounts and administrator security | `tests/Feature/Auth`, `tests/Feature/Administrator*` |
| Audit | `tests/Feature/AuditEvent*` |
| Ingredients and OpenFoodFacts | `tests/Feature/Ingredients`, `tests/Feature/Integrations` |
| Recipes, organization, profiles, and imports | `tests/Feature/Recipes`, `tests/Feature/Recipes/RecipeImport*`, `tests/Feature/Profiles` |
| Catalogue, nutrition, moderation, and refresh | `tests/Feature/Catalogue` |
| Shared security | `SecurityControlsTest.php`, `TransientInputSecurityTest.php`, `RedactionPrivacyTest.php` |
| Queues and observability | `tests/Feature/Queue*`, `tests/Feature/ObservabilityTest.php` |
| Scanner | `./vendor/bin/sail npm run test:scanner` |
| Documentation/context tooling | `./vendor/bin/sail npm run docs:test`, then `docs:check` |

The complete quality requirements and canonical final commands remain in
[Definition of done](DEFINITION_OF_DONE.md) and the
[README](../README.md#tests-and-quality-checks).

## Maintenance rule

When application behavior changes:

1. Update the affected capability, boundary, or gap in this concise index.
2. Update the application baseline and review date after verifying the merged
   repository state.
3. Put durable product choices in `PRODUCT_SPEC.md` or `DECISIONS.md`, not
   in this file.
4. Put detailed milestone evidence in the relevant feature guide or append it
   to [implementation history](IMPLEMENTATION_HISTORY.md) when retaining that
   history is useful.
5. Regenerate [the context index](CONTEXT_INDEX.md) when roadmap, decision, or
   domain-model references change.

Do not expand this file back into a chronological implementation narrative.
