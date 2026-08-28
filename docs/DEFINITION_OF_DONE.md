# Definition of done

## Purpose and status

This document defines the minimum evidence required before a future task is
reported as complete. It applies to changes made in this repository and should
be read with `AGENTS.md`, `CURRENT_STATE.md`, `DOMAIN_MODEL.md`,
`PRODUCT_SPEC.md`, and `ROADMAP.md`.

Requirements are classified as follows:

- **Every task:** required regardless of the type of change.
- **When relevant:** required when the task affects the named area. The final
  report must say when a check was considered not applicable.
- **Desirable but not currently available:** useful checks for which this
  repository has no installed or configured tool. Their absence does not by
  itself block unrelated work, but they must not be claimed as performed.

A task is **complete** only when all acceptance criteria are met and every
applicable required check has passed. If a required check fails, the task is
incomplete. If a required check cannot run because of an environment or
external dependency, the task may be reported as **conditionally complete**,
but not complete; the reason, risk, and remaining check must be explicit.

## 1. Acceptance criteria

**Every task**

- The task has specific, observable acceptance criteria before implementation.
- Each acceptance criterion is satisfied and supported by a test, inspection,
  or other stated verification evidence.
- The result agrees with confirmed behavior in the product and domain
  documentation. An unresolved owner decision is not silently converted into
  a product requirement.
- Any ambiguity that materially changes behavior, privacy, data ownership,
  nutrition meaning, or architecture is resolved with the owner before the
  affected behavior is implemented.

## 2. Scope control

**Every task**

- The change is small enough for one focused review and contains only work
  needed for its acceptance criteria.
- Existing behavior is preserved unless the task explicitly changes it.
- Unrelated code, configuration, dependencies, documentation, and generated
  files are not changed.
- The implementation remains conventional Laravel and Livewire unless an
  architectural change was explicitly agreed.
- Newly discovered defects or product questions outside the task are reported
  separately rather than silently fixed or decided.

## 3. Automated tests

**When relevant**

- New or changed backend behavior has focused PHPUnit feature or unit coverage
  where practical. A regression fix includes a test that fails without the
  fix.
- Authorization and privacy behavior is tested for the relevant owner,
  non-owner, administrator, authenticated-user, and guest cases.
- Administrator privilege changes use the FND-14 lifecycle services and test
  recent primary authentication, fresh operation-bound TOTP, durable audit and
  notification intent boundaries, last-administrator locking, session and
  remembered-login invalidation, and production rejection of test shortcuts.
- Livewire changes are exercised through their public component behavior, and
  validation failures confirm that user input and stored data remain safe.
- External services such as OpenFoodFacts are mocked in automated tests; the
  normal test suite must not depend on a live provider response.
- For changes to executable PHP, Laravel or Livewire behavior, database
  behavior, configuration, or the test suite, the full existing suite passes:

  ```bash
  ./vendor/bin/sail composer test
  ```

- A documentation-only task may mark the PHPUnit suite not applicable when it
  does not alter executable behavior, commands, or configuration.

The repository currently has PHPUnit unit and feature tests, with most
application-specific coverage concentrated on authentication, profiles, and
parts of the ingredient workflow. Missing coverage in an affected area must be
added where practical; existing gaps are not evidence that new behavior can go
untested.

## 4. Formatting and static analysis

**When relevant**

- Changed PHP code conforms to Laravel Pint. Formatting may be applied with:

  ```bash
  ./vendor/bin/sail pint
  ```

- The non-mutating formatting check passes before completion:

  ```bash
  ./vendor/bin/sail pint --test
  ```

- Static analysis passes for changes to executable PHP, Laravel configuration,
  routes, database support code, or the test suite:

  ```bash
  ./vendor/bin/sail composer analyse
  ```

- New findings are fixed rather than added casually to the reviewed baseline.
  After correcting baselined debt, developers review and regenerate the
  baseline so it shrinks.

## 5. Frontend build checks

**When relevant**

- Changes to Blade or Volt views, Livewire browser behavior, JavaScript, CSS,
  frontend dependencies, or Vite configuration pass the production build:

  ```bash
  ./vendor/bin/sail npm run build
  ```

- The affected user flow is manually checked in the browser when its behavior
  or presentation cannot be established by the existing PHPUnit tests alone.
  Relevant states include validation errors, loading and failure states,
  light and dark themes, and mobile and desktop layouts.

**Desirable but not currently available**

- There is no configured general frontend linter, browser/end-to-end suite,
  automated accessibility scanner, or visual regression tool. The barcode
  scanner has a focused Node test suite with browser API and decoder doubles,
  but it is not a general browser-testing framework.
- These checks must not be reported as passing. Where the acceptance criteria
  depend on behavior they would normally cover, use the focused scanner or
  PHPUnit coverage where applicable and documented manual verification, or
  report the task as conditionally complete if adequate verification is not
  possible.

## 6. Continuous-integration quality gates

**Every proposed code or configuration change**

- The GitHub Actions `Quality gates` workflow must report all four established
  job/check names successfully: `Backend tests`, `PHP formatting`, `Static
  analysis`, and `Frontend build`.
- The local equivalents are, respectively, `./vendor/bin/sail composer test`,
  `./vendor/bin/sail pint --test`, `./vendor/bin/sail composer analyse`, and
  `./vendor/bin/sail npm run build`.
- A failure in any status blocks completion. A task cannot claim that merging
  is protected unless the repository's `main` branch protection has also been
  verified to require all four statuses.

**When relevant**

- Documentation-only changes may follow a documented repository policy that
  marks an application check not applicable locally, but the proposed change
  must still receive every configured remote CI status before merge.

**When development-environment configuration or setup documentation changes**

- Required `.env.example` values and important Compose service references
  remain aligned:

  ```bash
  ./vendor/bin/sail npm run env:check
  ```

- Docker Compose can parse the supported example configuration:

  ```bash
  docker compose --env-file .env.example config --quiet
  ```

## 7. Database migrations and data safety

**When relevant**

- Schema changes are additive and preserve existing records unless a separate,
  explicit approval authorizes a destructive migration.
- Migrations account for the current data shape, ownership, nullability,
  foreign keys, indexes, and deletion behavior described in the domain
  documentation.
- Migration tests or other repeatable checks cover populated data, constraints,
  and rollback behavior where practical. Relevant model and feature tests pass
  against the supported Sail/MySQL baseline.
- A fresh, disposable development database may be prepared with the documented
  command:

  ```bash
  ./vendor/bin/sail artisan migrate --seed
  ```

- `migrate:fresh`, `db:wipe`, and `sail down -v` are never run against an
  existing development environment without explicit approval.
- A migration that could delete, overwrite, merge, reclassify, or orphan user
  data blocks completion until its data-handling plan and approval are clear.

## 8. Security and secrets

**Every task**

- No credential, token, secret, private key, production data, or local database
  credential is committed.
- The final diff is checked for accidental sensitive data and unnecessary
  personal data.

**When relevant**

- User-controlled input is validated at the mutation boundary, and ownership
  cannot be reassigned through submitted data.
- Authorization is enforced for every changed read and write path, including
  direct requests and Livewire actions; hiding a control in the UI is not an
  authorization control.
- Public and shared responses expose only the data permitted by the product's
  privacy rules, with special care for email, recipes, plans, diary entries,
  targets, imports, and pending catalogue records.
- External requests, file uploads, logs, and queued work handle failure safely
  and do not expose secrets or private content. Provider calls use explicit
  timeouts and safe error handling when those concerns are within task scope.
- Security-sensitive changes include negative tests for unauthorized, forged,
  malformed, or cross-user input.

- Production-sensitive configuration changes include focused invalid-setting
  and secret-redaction tests, a representative `app:production-check` pass,
  successful `config:cache`, and a second readiness pass using the cache.
- Production administrator changes demonstrate both static DEP-02 readiness and
  live FND-13 destination, provider, queue-worker, failed-job monitor, clock,
  capacity, and audit-persistence readiness. Static configuration alone is not
  reported as operational health.

## 9. Documentation updates

**Every task**

- The author assesses whether the change makes existing documentation false or
  incomplete.

**When relevant**

- Setup, commands, current behavior, domain rules, product decisions, and
  roadmap status are updated in the appropriate document as part of the same
  task.
- Documentation-affecting changes pass:

  ```bash
  ./vendor/bin/sail npm run docs:check
  ```

- Changes to the documentation validator, its dependencies, or its
  configuration also pass `./vendor/bin/sail npm run docs:test`.
- Documentation describes implemented behavior truthfully and does not present
  roadmap items as available features.
- A new command is documented only after its tool is installed, configured,
  and verified in this repository.
- Decisions deferred in `PRODUCT_SPEC.md` or listed as requiring owner input
  remain deferred until the owner decides them and carry a
  `Decision: DEC-NNN.` marker that resolves to `DECISIONS.md`.

## 10. Git diff review

**Every task**

- The final status and diff are reviewed before handoff.
- Every changed file and line is intentional, relevant to the task, and free
  of debugging output, temporary artifacts, and accidental formatting churn.
- Generated dependencies such as `vendor` and `node_modules` are not included.
- Migration, lock-file, dependency, and configuration changes receive specific
  scrutiny because they can affect data safety and reproducibility.
- The task remains suitable for one reviewable commit or pull request on a
  dedicated branch or worktree.

## 11. Reporting checks that could not be run

**Every task**

- The final report lists each applicable check, its command where one exists,
  and whether it passed, failed, was not run, or was not applicable.
- A failed check includes the failure summary and whether it appears caused by
  the change or by an existing repository problem.
- A check that could not run includes the exact blocker, the verification gap
  and risk it leaves, and the action still needed.
- A required check that failed or could not run prevents the status
  **complete**. Report **incomplete** for unmet acceptance criteria or known
  failures, and **conditionally complete** only when the implementation is
  ready but environmental or external conditions prevent required
  verification.
- Checks that are not applicable are identified briefly; they are not reported
  as passed.

## 12. Additional requirements for user-facing features

**When relevant**

- The primary success path, validation path, empty state, and expected failure
  or recovery path work without losing user input.
- Loading, success, error, and destructive-action feedback is clear. Color is
  not the only means of communicating state.
- Forms have associated labels and errors; notices use appropriate accessible
  semantics; keyboard focus and modal behavior are predictable.
- The flow is usable by keyboard and at relevant mobile and desktop sizes.
  Touch actions such as drag-and-drop have an alternative when introduced.
- Light and dark themes remain readable, long or unusual content does not
  break the layout, and navigation exposes only implemented and authorized
  actions.
- Privacy, visibility, attribution, estimate, and destructive-action wording
  matches the actual behavior and does not make unsupported legal, medical, or
  accuracy claims.
- Automated Laravel/Livewire assertions and the production frontend build pass;
  focused manual browser checks are recorded because browser and accessibility
  automation are not currently available.

## 13. Additional requirements for nutrition calculations and food matching

**When relevant**

- The exact original recipe ingredient text is preserved. Parsing,
  normalization, matching, resizing, and nutrition calculation use separate
  structured data and never overwrite that text.
- Imported, manually entered, calculated, source-provided, and overridden
  nutrition remain distinguishable where the affected model supports those
  sources. Provenance and the relevant catalogue or recipe version are kept.
- OpenFoodFacts values are treated as imported source data. Values calculated
  from ingredients, quantities, servings, substitutions, or incomplete matches
  are explicitly presented as estimates.
- Nutrition basis and units are unambiguous. Missing values remain distinct
  from zero, and stored source precision is not destroyed merely to satisfy
  display rounding.
- Energy handling follows the confirmed rule `1 kcal = 4.184 kJ`; kcal is
  authoritative when supplied kcal and kJ conflict. Storage and display
  rounding follow the decided DEC-003 and DEC-004 policies.
- All affected supported nutrients are tested independently: kcal, kJ, fat,
  saturated fat, carbohydrates, sugars, fibre, protein, salt, and sodium.
- Same-dimension conversions use defined conversions. A food-dependent
  conversion is used only when reliable data exists for the matched food; the
  application does not guess one.
- Unmatched lines, sub-threshold candidates, and lines without reliable
  conversions are excluded rather than assigned invented values. Partial
  results identify excluded or review-needed lines and never display missing
  nutrition as zero.
- Matching behavior is deterministic and covered at decision boundaries.
  Match score, confidence, review state, selection provenance, and catalogue
  version are retained when the affected feature supports them. Thresholds or
  de-duplication rules that remain owner decisions are not invented.
- Catalogue and recipe updates respect versioning: current calculated results
  may be recalculated when specified, while imported or manually overridden
  primary values and historical plan or diary snapshots remain stable.
- Tests cover normal, zero, missing, partial, conflicting, low-confidence,
  unmatched, unsupported-conversion, and relevant authorization cases.

## 14. Additional requirements for queued jobs

**When relevant**

- Every job defines a stable logical operation identity, correlation behavior,
  explicit queue, bounded payload, and missing-resource outcome.
- Duplicate dispatch and concurrent execution are considered separately, and
  the business effect is idempotent across retry after partial success.
- The idempotency store, key scope, and lifetime cover the operation's replay
  window. High-risk or durable effects use database uniqueness or transactional
  state rather than relying only on a cache lock.
- Attempts and backoff are bounded. Each job declares an operation-specific
  timeout below the connection's `retry_after`, and external timeouts fit
  within that job timeout.
- Expected failures are classified as retryable or permanent at the integration
  boundary and expose a bounded safe error code.
- Structured logs and failure reports omit exception messages, serialized
  payloads, private model data, request bodies, user content, credentials, raw
  IP addresses, and full user agents.
- Failed-job serialization is inspected. Jobs enqueue identifiers and reload
  records instead of embedding private content.
- Audit events are emitted only for an approved durable purpose and use the
  existing allowlisted recorder. Ordinary attempts and technical failures are
  not copied into audit.
- Jobs depending on newly written records dispatch after commit.
- Tests exercise actual handler or deterministic test-worker behavior for
- Any new queued or scheduled product behavior declares both DEP-04 and DEP-05
  dependencies before production enablement. Local/test execution remains
  available without production monitoring credentials.
- The job/schedule is added to `docs/JOB_INVENTORY.md` and
  `config/queue-operations.php` with queue, timeout/`retry_after` margin,
  heartbeat, backlog/age threshold, failure/retry metric, privacy
  classification, alert, replay, pruning, and overlap behavior.
- Tests cover correlation propagation, structured allowlisted telemetry,
  worker/backend distinction, relevant backlog/latency/failure thresholds, and
  absence of private payload content from logs, metrics, health, and alerts.
  idempotency and failure; queue fakes are used only for dispatch assertions.
- The task follows `docs/QUEUED_JOB_CONVENTIONS.md` and reports any operational
  worker, scheduler, pruning, replay, or monitoring infrastructure still
  deferred.

## 15. Additional requirements for shared security controls

**When relevant**

- Public, authentication, authenticated, sensitive and safe error responses
  retain the central header/CSP policy; CSP changes are checked against current
  Vite, Livewire, Alpine, scanner and local-HMR behavior.
- New public queries, provider-backed actions, sharing writes, authentication
  security flows and import submissions select a documented named limiter with
  a content-free hashed or explicit global identity. Ordinary read behavior is
  not throttled without a stated abuse reason.
- Request and upload paths enforce central configurable bounds before costly
  parsing/decoding/provider work and document proxy, web-server, PHP and
  platform alignment where Laravel cannot reject first.
- Transient uploads use a private non-served disk, application-generated key,
  content-derived MIME inspection, feature-supplied allowlist and idempotent
  cleanup. Original filenames never become paths or telemetry.
- Parsers assert an approved byte/character/item/depth/time budget before or
  during expensive work. Feature-specific image, document and provider limits
  remain with their feature backlog item.
- Synthetic-secret tests inspect logs, exception responses/context, audit and
  metric metadata, queued serialization and failed-job behavior.
