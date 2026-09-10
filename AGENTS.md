# AGENTS.md

## Project and environment

VibeDietr is a personal recipe, meal-planning, and nutrition-tracking web
application built with Laravel and Livewire.

Run repository commands from the project root in WSL. Development and checks
use Laravel Sail with PHP 8.4, container-supplied Node 22, MySQL 8.0,
database-backed queues/cache/sessions, and local Mailpit. Do not rely on PHP,
Composer, or Node being installed directly in WSL. See [`README.md`](README.md)
for setup, normal development commands, and troubleshooting.

## Sources of truth

Load only the sections relevant to the current task. Document roles are:

- [`docs/PRODUCT_SPEC.md`](docs/PRODUCT_SPEC.md): intended product behaviour.
- [`docs/ROADMAP.md`](docs/ROADMAP.md): task scope, dependencies, risk, and
  acceptance criteria.
- [`docs/DECISIONS.md`](docs/DECISIONS.md): decided and unresolved product
  boundaries.
- [`docs/CURRENT_STATE.md`](docs/CURRENT_STATE.md): currently implemented
  behaviour.
- [`docs/DOMAIN_MODEL.md`](docs/DOMAIN_MODEL.md): current domain concepts,
  relationships, and invariants.
- [`docs/AUTHORIZATION_PRIVACY_MATRIX.md`](docs/AUTHORIZATION_PRIVACY_MATRIX.md):
  authorization, ownership, sharing, privacy, and deletion rules.
- [`docs/DEFINITION_OF_DONE.md`](docs/DEFINITION_OF_DONE.md): final completion
  and verification requirements.
- Existing code and tests: implementation evidence, not authority to silently
  override approved product requirements.

For a roadmap item, first run
`./vendor/bin/sail npm run context:task -- TASK-ID`. The generated
view extracts its exact roadmap entry, dependencies, relevant decisions, and
document references from the authoritative files; it is routing evidence, not a
new source of truth. Read only the returned sections relevant to the task. If an
unresolved decision explicitly blocks the item, stop and explain which decision
blocks it and why. Otherwise continue without requesting additional
product-owner input. Never resolve a product decision by inference.

## Universal working rules

- Inspect relevant existing code and tests before editing.
- Make the smallest coherent change that satisfies the task.
- Preserve existing behaviour outside the stated scope.
- Keep the application recognisably conventional Laravel and Livewire; prefer
  clear, maintainable code over clever abstractions.
- Do not silently invent or change product requirements.
- Never remove, overwrite, merge, or reclassify user data through a destructive
  migration or operation without explicit approval.
- Never commit credentials, tokens, secrets, production data, or unnecessary
  personal data.
- Enforce authorization at every changed read and mutation boundary. UI
  visibility is not authorization.
- Preserve ownership, privacy, provenance, immutable/versioned history, and
  original user-entered recipe or ingredient text wherever the affected domain
  requires them.
- Nutrition calculated from ingredients, recipes, quantities, servings,
  substitutions, or incomplete matches is an estimate and must be presented as
  such. Imported provider values retain their source provenance.
- Do not rewrite unrelated code or documentation.

## Context routing

Read these only when the task touches the named area:

- OpenFoodFacts or barcodes: [`docs/OPENFOODFACTS_INTEGRATION.md`](docs/OPENFOODFACTS_INTEGRATION.md).
  All provider HTTP access and response mapping must remain under
  `app/Integrations/OpenFoodFacts`; callers consume application-owned result
  types, not provider requests or raw JSON.
- Nutrients, measurements, or conversions:
  [`docs/NUTRIENT_MEASUREMENT_DEFINITIONS.md`](docs/NUTRIENT_MEASUREMENT_DEFINITIONS.md).
- Catalogue moderation, corrections, or refreshes:
  [`docs/CATALOGUE_MODERATION.md`](docs/CATALOGUE_MODERATION.md),
  [`docs/CATALOGUE_CORRECTIONS.md`](docs/CATALOGUE_CORRECTIONS.md), and
  [`docs/CATALOGUE_PROVIDER_REFRESHES.md`](docs/CATALOGUE_PROVIDER_REFRESHES.md)
  as applicable.
- Administrator factor verification, notifications, assignment, or revocation:
  [`docs/ADMINISTRATOR_SECURITY_FOUNDATIONS.md`](docs/ADMINISTRATOR_SECURITY_FOUNDATIONS.md)
  and [`docs/ADMINISTRATOR_LIFECYCLE.md`](docs/ADMINISTRATOR_LIFECYCLE.md).
  Use the centralized FND-13 verification/readiness services and FND-14
  lifecycle services; ordinary application code must never change administrator
  status directly.
- Audit events or retention: [`docs/AUDIT_EVENTS.md`](docs/AUDIT_EVENTS.md) and
  [`docs/AUDIT_RETENTION_SCHEDULE.md`](docs/AUDIT_RETENTION_SCHEDULE.md).
- Queued or scheduled work: [`docs/QUEUED_JOB_CONVENTIONS.md`](docs/QUEUED_JOB_CONVENTIONS.md),
  [`docs/JOB_INVENTORY.md`](docs/JOB_INVENTORY.md), and
  [`docs/QUEUE_OPERATIONS.md`](docs/QUEUE_OPERATIONS.md). New product workflows
  must declare DEP-04 and DEP-05, be inventoried in documentation and queue
  configuration, preserve the tested timeout/`retry_after` margin, define
  idempotency and concurrency behaviour, and include privacy-safe operational
  coverage.
- Imports, uploads, parsing, or other transient input:
  [`docs/SECURITY_CONTROLS.md`](docs/SECURITY_CONTROLS.md) plus the relevant
  import decisions and roadmap item.
- Production configuration, deployment, health, or observability:
  [`docs/PRODUCTION_CONFIGURATION.md`](docs/PRODUCTION_CONFIGURATION.md),
  [`docs/OBSERVABILITY.md`](docs/OBSERVABILITY.md), and
  [`docs/OPERATIONS_RUNBOOKS.md`](docs/OPERATIONS_RUNBOOKS.md) as applicable.
- Additive catalogue/schema migration work:
  [`docs/DOMAIN_MIGRATION_PLAN.md`](docs/DOMAIN_MIGRATION_PLAN.md).

## Git workflow

- Work on a dedicated branch or worktree.
- Split larger tasks into small, independently reviewable units.
- Before starting the next logical unit, run its relevant focused checks and
  commit it unless doing so would leave the repository knowingly broken.
- Stage and commit only files belonging to that unit. Do not amend, squash,
  reorder, force-push, or rewrite existing history unless explicitly requested.
- Do not commit generated dependencies such as `vendor` or `node_modules`.
- Ask before adding new ignore rules.
- At handoff, leave completed work committed and report each commit created.

## Verification

Use focused tests while developing. Before reporting completion, apply every
relevant requirement in [`docs/DEFINITION_OF_DONE.md`](docs/DEFINITION_OF_DONE.md),
including the applicable automated tests, formatting, static analysis,
frontend/documentation checks, and final diff review. The canonical commands and
CI equivalents are documented in [`README.md`](README.md#tests-and-quality-checks).

Never claim a check passed unless it was run successfully. Report checks that
failed, could not run, or were not applicable. Do not weaken the final quality
gate to reduce implementation time.

## Stop conditions

Stop and explain the issue when:

- authoritative requirements conflict;
- an unresolved decision blocks the requested behaviour;
- a destructive data change appears necessary;
- production credentials or unavailable external coordination are required;
- existing behaviour cannot be determined safely;
- the work would significantly alter the approved architecture; or
- required tests fail for reasons outside the task.
