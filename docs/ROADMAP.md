# Product roadmap

This backlog is derived from `PRODUCT_SPEC.md` and the repository state
described in `CURRENT_STATE.md`, `DOMAIN_MODEL.md`, and the application code.
It is an implementation sequence, not a commitment to dates. Each item is
scoped to one reviewable change; where a larger capability needs several
changes, it is deliberately split across multiple items.

## How to use this roadmap

- Items are ordered by recommended implementation priority within each
  section. Cross-section dependencies, rather than section order alone,
  determine the delivery sequence.
- **P0** protects data, privacy, security, and the ability to change the
  system safely. **P1** builds the first complete recipe-to-plan journey.
  **P2** completes the broader product specification. **P3** is launch
  hardening or a lower-frequency workflow.
- Dependencies use backlog IDs and stable IDs from
  [`DECISIONS.md`](DECISIONS.md). `None` means the item can start from the
  current repository. A dependency on a decision record means implementation
  must wait for that question to be resolved.
- All schema changes must be additive and data-preserving until a separately
  reviewed cleanup is explicitly approved. No item authorizes destructive
  migration of the existing ingredient records.
- "Feature test" means a Laravel/Livewire test unless a browser test is
  specifically called out.

## 1. Foundation and safety

### FND-01 — P0 — Record unresolved product decisions

- **Outcome:** Maintain [`DECISIONS.md`](DECISIONS.md) as the decision register
  for every choice intentionally deferred by the product specification.
- **Dependencies:** None.
- **Acceptance criteria:** Each decision has an owner, status, alternatives,
  existing product constraints, a resolution condition, and backlog
  relationships classified as blocked, constrained, or related; no
  implementation item silently selects a deferred behavior.
- **Suggested automated tests:** Deferred to FND-10. Until then, use the manual
  validation checklist in `DECISIONS.md`.
- **Risk:** Low.
- **Estimated size:** Small.

### FND-02 — P0 — Define the additive domain migration plan

- **Outcome:** Establish a safe sequence for evolving user-owned ingredients
  into a shared catalogue while introducing recipes and plans without losing
  existing data.
- **Dependencies:** FND-01.
- **Acceptance criteria:** The plan documents expand/backfill/cut-over/contract
  stages, rollback expectations, validation queries, and explicit approval
  gates before any destructive step.
- **Suggested automated tests:** Documentation check for every current
  `ingredients` column and foreign-key behavior; migration dry-run tests are
  added with the first schema change, not in this documentation item.
- **Risk:** High.
- **Estimated size:** Small.

### FND-03 — P0 — Define the authorization and privacy matrix

- **Outcome:** Give every current and planned resource an explicit owner,
  viewer, editor, public-access, sharing, and deletion rule.
- **Dependencies:** FND-01.
- **Acceptance criteria:** The matrix covers accounts, recipe drafts and
  versions, catalogue records and proposals, bookmarks, organisation, plans,
  shares, diary entries, targets, imports, and audit records; logged-out access
  and private-recipe-through-plan access are explicit.
- **Suggested automated tests:** A policy-test checklist generated from the
  matrix; later policy changes must link to a corresponding matrix row.
- **Risk:** High.
- **Estimated size:** Small.

### FND-04 — P0 — Add administrator role authorization

- **Outcome:** Provide a conventional, centrally checked administrator
  capability for future catalogue moderation without exposing it through
  ordinary user permissions.
- **Status:** Complete (2026-07-31).
- **Dependencies:** FND-03.
- **Acceptance criteria:** Administrator status is stored safely; assignment
  is not available through self-service profile input; a gate or policy can
  protect admin-only routes; ordinary and logged-out users receive the correct
  denial response. Role persistence alone exposes no production assignment
  path; administrator lifecycle and bootstrap are delivered by FND-14.
- **Suggested automated tests:** Role persistence, mass-assignment protection,
  admin route access, ordinary-user denial, and guest redirect/denial tests.
- **Risk:** High.
- **Estimated size:** Small.

### FND-05 — P0 — Introduce a minimal audit-event store

- **Outcome:** Persist actor, action, subject, timestamp, and purpose metadata
  needed by later moderation, versioning, nutrition overrides, snapshots, and
  anonymization workflows.
- **Dependencies:** FND-03, FND-04.
- **Acceptance criteria:** Audit events use immutable identifiers and reliable
  UTC timestamps, are append-only through application APIs, have an allowlisted
  purpose/retention classification, and tolerate a removed actor through a
  separately erasable identity mapping. Payloads reject secrets, credentials,
  raw IP addresses, full user agents, private domain content, and unnecessary
  personal data. Events can represent external operator/deployment identity and
  correlation evidence for DEC-009 without recording credentials. Legally
  protected evidence is referenced from, but not stored in, this ordinary
  audit-event store.
- **Suggested automated tests:** Event creation, removable/nullable actor,
  immutable payload, authorization, retention classification, external actor,
  correlation, field allowlist, and sensitive-field rejection tests.
- **Risk:** High.
- **Estimated size:** Medium.

### FND-06 — P0 — Establish shared nutrient and measurement definitions

- **Outcome:** Replace component-local conventions with application-owned
  definitions for supported nutrients, nutrient units/bases, measurement
  dimensions, and canonical same-dimension conversions.
- **Status:** Complete (2026-07-31).
- **Dependencies:** None.
- **Acceptance criteria:** All nutrients in `PRODUCT_SPEC.md` are represented;
  mass, volume, and count units are distinguished; custom units remain valid;
  no food-dependent mass/volume conversion is included.
- **Suggested automated tests:** Definition completeness, aliases, reversible
  standard conversions, precision preservation, and rejection of
  cross-dimension conversion tests.
- **Risk:** Medium.
- **Estimated size:** Medium.

### FND-07 — P0 — Add static analysis

- **Outcome:** Detect type and relationship errors as the domain expands.
- **Status:** Complete (2026-08-07).
- **Dependencies:** None.
- **Acceptance criteria:** A Laravel-compatible static-analysis tool and
  configuration are committed, the current baseline passes or has a small
  reviewed baseline file, and the command is documented in `AGENTS.md`.
- **Suggested automated tests:** Run the static-analysis command in CI and
  include one fixture or regression proving failures produce a non-zero exit.
- **Risk:** Low.
- **Estimated size:** Medium.

### FND-08 — P0 — Add continuous-integration quality gates

- **Outcome:** Run repeatable backend tests, formatting checks, static
  analysis, and frontend builds for every proposed change.
- **Status:** Complete (2026-08-07).
- **Dependencies:** FND-07.
- **Acceptance criteria:** CI uses supported PHP/Node versions and a database
  matching the documented baseline; dependency caches do not hide lock-file
  changes; all four checks gate merging.
- **Suggested automated tests:** Exercise the workflow on a branch; verify a
  deliberately failing test, format check, analysis check, and asset build are
  each reported as failures before reverting the fixtures.
- **Risk:** Medium.
- **Estimated size:** Medium.

### FND-09 — P1 — Establish queued-job conventions

- **Outcome:** Provide idempotency, retry, timeout, failure reporting, and
  correlation conventions for imports, catalogue refreshes, recalculation,
  exports, and delayed deletion.
- **Status:** Complete (2026-08-07).
- **Dependencies:** FND-05, FND-08.
- **Acceptance criteria:** A small reference job demonstrates the conventions;
  duplicate dispatch is safe; failed jobs expose actionable context without
  leaking input data. DEP-04 and DEP-05 own the required production operation
  and monitoring follow-through; completing this foundation alone does not
  authorize a queued product workflow to be enabled in production.
- **Suggested automated tests:** Idempotent re-run, retry exhaustion, timeout
  configuration, failure-event, and correlation-ID tests.
- **Risk:** Medium.
- **Estimated size:** Medium.

### FND-10 — P2 — Add automated documentation validation

- **Outcome:** Add a conventional, repeatable documentation check without a
  repository-specific one-off validation system.
- **Status:** Complete (2026-08-07).
- **Dependencies:** FND-08.
- **Acceptance criteria:** A maintained tool checks Markdown structure and
  links; decision-register validation checks stable IDs, required fields,
  allowed statuses, and coverage of deferred product-specification decisions;
  its command is documented and runs in CI.
- **Suggested automated tests:** Exercise valid documentation plus temporary
  duplicate-ID, missing-field, broken-link, and unregistered-decision fixtures
  and confirm that each failure returns a non-zero status.
- **Risk:** Low.
- **Estimated size:** Small.

### FND-11 — P0 — Select the administrator second-factor mechanism

- **Outcome:** Research and approve the code-based second-factor mechanism,
  provider, enrollment, and recovery behavior required by DEC-009.
- **Status:** Complete (2026-08-09).
- **Dependencies:** DEC-009.
- **Acceptance criteria:** Representative mechanisms and providers are compared
  for security, replay/rate-limit controls, recovery, secrets handling,
  self-hosted and production operation, accessibility, cost, and testability;
  DEC-015 records the approved selection without allowing password-only
  fallback.
- **Suggested automated tests:** No application tests in the research item;
  validate any provider proof of concept in disposable fixtures and record
  reproducible security and failure-case evidence.
- **Risk:** High.
- **Estimated size:** Medium.

### FND-12 — P0 — Select administrator security-notification delivery

- **Outcome:** Research and approve a reliable production notification channel
  for the privilege-lifecycle events required by DEC-009.
- **Status:** Complete (2026-08-12).
- **Dependencies:** DEC-009.
- **Acceptance criteria:** Candidate channels/providers are compared for
  delivery evidence, retry/failure handling, privacy, destination verification,
  self-hosted operation, secrets handling, accessibility, cost, and local
  testing; DEC-016 records the approved channel and fail-closed production
  enablement rule.
- **Suggested automated tests:** No application tests in the research item;
  exercise provider proof-of-concept delivery, refusal, retry, and redaction in
  a disposable environment.
- **Risk:** High.
- **Estimated size:** Medium.

### FND-13 — P0 — Add administrator second-factor and notification foundations

- **Outcome:** Provide the approved second-factor enrollment/verification and
  reliable security-notification capabilities needed before administrator
  lifecycle actions can be enabled.
- **Dependencies:** FND-04, FND-08, DEC-015, DEC-016.
- **Acceptance criteria:** Administrator activation can require confirmed
  enrollment; privileged workflows can require recent re-authentication and a
  fresh valid code; factor recovery follows the approved rule; notification
  delivery covers every DEC-009 event and fails safely; codes, recovery
  material, and provider secrets are never logged; explicit local/test adapters
  cannot run in production.
- **Suggested automated tests:** Enrollment, verification, replay, expiry,
  rate-limit, recovery, missing/insecure production configuration, notification
  delivery/retry/failure, redaction, and local/test production-isolation tests.
- **Risk:** High.
- **Estimated size:** Large.

### FND-14 — P0 — Implement administrator bootstrap and lifecycle

- **Outcome:** Implement the initial bootstrap, routine promotion and revocation,
  last-administrator protection, security notifications, and break-glass
  recovery approved in DEC-009.
- **Dependencies:** FND-04, FND-05, FND-13.
- **Acceptance criteria:** Production bootstrap is CLI-only, explicitly
  configured, target-bound, operator-confirmed, auditable, atomic, and allowed
  only with zero administrators and an unset persistent completion marker;
  bootstrap never reopens. Routine promotion requires an existing
  administrator's recent re-authentication/code, a verified and second-factor-
  enrolled target, and target acceptance within 24 hours. Authorized
  cancellation/decline, audited revocation by another administrator, immediate
  session/credential invalidation, sole-administrator deletion/revocation
  denial, and separately configured CLI break-glass replacement all match
  DEC-009. Required audit or notification failure denies the privilege change.
  The normal seeder creates no administrator and local/test shortcuts fail in
  production.
- **Suggested automated tests:** First/second/concurrent/repeated bootstrap,
  wrong environment/config/target, unverified or unenrolled target, audit and
  notification failure, marker persistence, mass-assignment/self-promotion,
  promotion accept/decline/cancel/expire, re-authentication/code failures,
  multiple/last/self revocation, session invalidation, account deletion guard,
  ordinary recovery, break-glass replacement/revocation, evidence redaction,
  security notifications, and local/test production-isolation tests.
- **Risk:** High.
- **Estimated size:** Large.

## 2. Existing-feature stabilisation

### STB-01 — P0 — Add ingredient characterization and authorization coverage

- **Outcome:** Lock down the behavior that must remain safe while the catalogue
  is redesigned.
- **Dependencies:** FND-08.
- **Acceptance criteria:** Tests cover search, pagination, update, deletion,
  owner/non-owner access, guest access, controller mutations, and the unused
  edit-modal path; discrepancies are recorded rather than silently changed.
- **Suggested automated tests:** Feature tests for every route and Livewire
  mutation, including 403/404 behavior across two users.
- **Risk:** Medium.
- **Estimated size:** Medium.

### STB-02 — P0 — Add ingredient test factories

- **Outcome:** Make current and migration-era ingredient states easy to create
  consistently in tests.
- **Dependencies:** None.
- **Acceptance criteria:** The factory has valid manual, barcode-imported,
  nutrition, and unusual-unit states; existing tests use it without changing
  behavior.
- **Suggested automated tests:** Factory persistence and state-combination
  tests on the supported database.
- **Risk:** Low.
- **Estimated size:** Small.

### STB-03 — P0 — Enforce authorization at every ingredient mutation

- **Outcome:** Prevent a mounted or crafted Livewire request from updating a
  record when page-level authorization is bypassed.
- **Dependencies:** STB-01.
- **Acceptance criteria:** Create/update/delete checks occur at the mutation
  boundary; controller and Livewire paths have equivalent denials; ownership
  cannot be reassigned through submitted data.
- **Suggested automated tests:** Forged Livewire identifier, stale mounted
  component, controller update, and ownership mass-assignment tests.
- **Risk:** High.
- **Estimated size:** Small.

### STB-04 — P0 — Converge ingredient write validation

- **Outcome:** Give controller and Livewire writes one validation and
  normalization contract while routes are still retained.
- **Dependencies:** STB-01, FND-06.
- **Acceptance criteria:** Unit vocabulary, paired serving fields, barcode
  handling, nutrition shape, and nullable values behave consistently on both
  paths; any intentional route-specific differences are documented.
- **Suggested automated tests:** Data-provider tests submit the same valid and
  invalid payloads through both mutation paths and compare results.
- **Risk:** Medium.
- **Estimated size:** Medium.

### STB-05 — P0 — Preserve explicit zero nutrition values

- **Outcome:** Store a user-entered zero as zero rather than treating it as a
  missing value.
- **Dependencies:** STB-04.
- **Acceptance criteria:** Zero and blank remain distinct for every normalized
  nutrient bucket; existing non-zero rounding remains unchanged.
- **Suggested automated tests:** Strict JSON assertions for zero, `null`, empty
  string, and small rounded values in both per-100g and per-serving data.
- **Risk:** Low.
- **Estimated size:** Small.

### STB-06 — P0 — Stabilize current energy and protein handling

- **Outcome:** Expose the full supported nutrient set already imported,
  including protein, and consistently derive kJ/kcal without destructive
  source rounding.
- **Dependencies:** FND-06, STB-05.
- **Acceptance criteria:** kcal is authoritative on conflict; a missing energy
  unit is derived with `1 kcal = 4.184 kJ`; stored precision and display
  rounding follow the recorded decision; protein and the other specified
  nutrients render consistently.
- **Suggested automated tests:** kcal-only, kJ-only, conflicting pair, zero,
  protein, precision-preservation, and display-format tests.
- **Risk:** Medium.
- **Estimated size:** Medium.

### STB-07 — P0 — Isolate and harden OpenFoodFacts access

- **Outcome:** Move HTTP and response mapping out of the Livewire component
  into a testable client with explicit identification, timeout, retry, and
  error semantics.
- **Dependencies:** FND-09.
- **Acceptance criteria:** UI behavior is preserved; timeouts, non-success
  responses, missing products, invalid payloads, and rate limits produce safe
  actionable results; requests identify the application as required by the
  provider.
- **Suggested automated tests:** Mocked success, timeout, retryable failure,
  non-retryable failure, malformed JSON, not-found, and rate-limit tests.
- **Risk:** Medium.
- **Estimated size:** Medium.

### STB-08 — P0 — Enforce machine-imported barcode provenance

- **Outcome:** Ensure a stored barcode represents a successful import rather
  than arbitrary manual input.
- **Dependencies:** STB-07, FND-05.
- **Acceptance criteria:** Normal creation cannot freely type a barcode;
  scanner/import results carry source identifier and import timestamp; failed
  lookups cannot create barcode records; legacy records remain readable and
  are explicitly classified for migration.
- **Suggested automated tests:** Manual-barcode rejection, scan/import success,
  failed lookup, forged Livewire payload, and legacy-record compatibility
  tests.
- **Risk:** High.
- **Estimated size:** Medium.

### STB-09 — P1 — Remove the runtime barcode-scanner CDN dependency

- **Outcome:** Make scanner availability, versioning, privacy, and content
  security policy controllable by the application.
- **Dependencies:** STB-08.
- **Acceptance criteria:** The scanner library is lock-file managed and bundled
  locally; camera cleanup and permission errors are handled; manual product
  creation remains available when scanning is unavailable.
- **Suggested automated tests:** Frontend build, browser permission-denied,
  no-camera, successful scan, camera switch, duplicate scan, and teardown
  tests.
- **Risk:** Medium.
- **Estimated size:** Medium.

## 3. Core recipe functionality

### REC-01 — P1 — Add recipe draft identity and ownership

- **Outcome:** Let an authenticated user create a private recipe draft with a
  title, serving count, lifecycle state, and visibility preference.
- **Dependencies:** FND-02, FND-03.
- **Acceptance criteria:** Drafts belong to one creator, are private regardless
  of intended visibility, require a positive serving count when supplied, and
  cannot be edited or viewed by another user.
- **Suggested automated tests:** Migration/model factory, create validation,
  owner access, non-owner denial, guest denial, and default-state tests.
- **Risk:** High.
- **Estimated size:** Medium.

### REC-02 — P1 — Add ordered recipe ingredient lines

- **Outcome:** Save one or more ordered ingredient lines while preserving the
  exact text entered by the creator.
- **Dependencies:** REC-01, FND-06.
- **Acceptance criteria:** Original text is immutable by parsing/normalization;
  optional quantity, unit, generic wording, and notes are stored separately;
  incomplete lines such as `salt to taste` remain valid; lines can be
  reordered.
- **Suggested automated tests:** Exact whitespace/text preservation,
  unparseable line, structured line, custom unit, ordering, and ownership
  tests.
- **Risk:** High.
- **Estimated size:** Medium.

### REC-03 — P1 — Add ordered instruction steps and sections

- **Outcome:** Let creators author ordered instruction steps, optionally
  grouped under named sections, without losing imported wording.
- **Dependencies:** REC-01.
- **Acceptance criteria:** Steps preserve exact text, support stable ordering,
  may have no section, and a section name is not required for every recipe.
- **Suggested automated tests:** Create/edit/reorder, exact-text preservation,
  section grouping, blank-step validation, and authorization tests.
- **Risk:** Medium.
- **Estimated size:** Medium.

### REC-04 — P1 — Build the draft recipe editor

- **Outcome:** Provide one Livewire workflow for editing recipe metadata,
  ingredient lines, and instructions with clear validation and unsaved-state
  feedback.
- **Dependencies:** REC-01, REC-02, REC-03.
- **Acceptance criteria:** Creators can add, edit, remove, and reorder child
  records; validation errors do not discard inputs; another user cannot mutate
  the draft; no partial save corrupts ordering.
- **Suggested automated tests:** Livewire happy path, validation recovery,
  reorder, remove, concurrent/stale request, and forged-owner tests.
- **Risk:** High.
- **Estimated size:** Large.

### REC-05 — P1 — Finalize and publish a recipe draft

- **Outcome:** Turn a valid draft into a usable finalized version without
  conflating lifecycle with visibility.
- **Dependencies:** REC-04, FND-05.
- **Acceptance criteria:** Finalization requires title, positive servings, at
  least one ingredient line, and at least one instruction; it records a stable
  published version; finalized recipes default public unless explicitly
  private; drafts remain unavailable to plans.
- **Suggested automated tests:** Each finalization precondition, public
  default, explicit private visibility, audit event, and draft plan-exclusion
  tests.
- **Risk:** High.
- **Estimated size:** Medium.

### REC-06 — P1 — Enforce recipe visibility and public read routes

- **Outcome:** Allow logged-out and authenticated readers to view public
  finalized recipes while keeping private recipes owner-only.
- **Dependencies:** REC-05, FND-03.
- **Acceptance criteria:** Only the creator can edit; public routes expose no
  private account data; draft/private identifiers cannot be enumerated into
  content; unpublishing removes public access without deleting versions.
- **Suggested automated tests:** Guest/public, guest/private, user/public,
  non-owner edit, draft access, unpublish, and serialized-data privacy tests.
- **Risk:** High.
- **Estimated size:** Medium.

### REC-07 — P1 — Add draft revisions for finalized recipes

- **Outcome:** Keep the current finalized version stable while its creator
  prepares and publishes a replacement.
- **Dependencies:** REC-05, FND-05.
- **Acceptance criteria:** Editing finalized content creates or resumes one
  private draft revision; publishing assigns a new immutable version; the
  previous version remains identifiable; abandoned revisions do not affect
  readers.
- **Suggested automated tests:** Revision creation, repeat edit, publish,
  previous-version retrieval, authorization, and audit-history tests.
- **Risk:** High.
- **Estimated size:** Large.

### REC-08 — P1 — Resize recipe quantities for display

- **Outcome:** Show proportionally resized quantities without overwriting the
  stored original text or original structured amount.
- **Dependencies:** REC-02, FND-06.
- **Acceptance criteria:** Standard and custom quantities scale from original
  servings; unquantified lines remain unchanged; display explains that
  resizing does not change the saved recipe.
- **Suggested automated tests:** Integer, decimal, fraction, custom unit,
  unquantified line, zero/invalid serving request, and original-data integrity
  tests.
- **Risk:** Medium.
- **Estimated size:** Medium.

### REC-09 — P1 — Add public recipe discovery

- **Outcome:** Let visitors search and browse only current public finalized
  recipes.
- **Dependencies:** REC-06.
- **Acceptance criteria:** Search covers title and public tags; results are
  paginated and stable; drafts, private recipes, and withdrawn versions never
  appear.
- **Suggested automated tests:** Search/filter/pagination, guest access, data
  leakage, current-version selection, and empty-state tests.
- **Risk:** Medium.
- **Estimated size:** Medium.

### REC-10 — P2 — Add recipe bookmarks

- **Outcome:** Let users save a pointer to another creator's live public
  recipe without copying it.
- **Dependencies:** REC-06.
- **Acceptance criteria:** Bookmarking is idempotent; bookmarks are private to
  their owner; they follow the live finalized version; unavailable originals
  show a tombstone rather than exposing content or deleting the bookmark.
- **Suggested automated tests:** Add/remove/idempotency, owner privacy, version
  update, unpublished/deleted original, and guest tests.
- **Risk:** Medium.
- **Estimated size:** Medium.

### REC-11 — P2 — Add recipe remixes and lineage

- **Outcome:** Copy an accessible finalized recipe into an independently owned
  draft while retaining attribution and lineage.
- **Dependencies:** REC-07, FND-05.
- **Acceptance criteria:** The remix preserves source version and attribution,
  is editable only by the remixer, starts private, and remains usable if the
  source is later unavailable.
- **Suggested automated tests:** Public-source remix, inaccessible-source
  denial, independent editing, source removal, lineage, and audit tests.
- **Risk:** High.
- **Estimated size:** Medium.

### REC-12 — P2 — Add private recipe collections and tags

- **Outcome:** Let users organise owned and bookmarked recipes without making
  that organisation public.
- **Dependencies:** REC-10.
- **Acceptance criteria:** Collections and private tags belong to one user,
  can reference owned recipes and bookmarks, and are never included on public
  recipe/profile responses.
- **Suggested automated tests:** CRUD, membership, bookmark support,
  cross-user denial, deletion cleanup, and public serialization tests.
- **Risk:** Medium.
- **Estimated size:** Medium.

### REC-13 — P2 — Add public and managed recipe tags

- **Outcome:** Support creator-authored public tags and administrator-managed
  dietary, cuisine, and meal-type vocabularies.
- **Dependencies:** REC-06, FND-04, FND-14.
- **Acceptance criteria:** Managed and free-form tags remain distinguishable;
  suggestions require creator review; nutrition claims are not shown as
  verified when supporting nutrition is incomplete.
- **Suggested automated tests:** Admin vocabulary management, creator tagging,
  suggestion approval/rejection, public visibility, and incomplete-claim
  labeling tests.
- **Risk:** Medium.
- **Estimated size:** Medium.

### REC-14 — P2 — Add public attribution profiles

- **Outcome:** Let a user choose public attribution, optional profile details,
  and whether a profile page lists their public recipes and remixes.
- **Dependencies:** REC-06, REC-11, FND-03.
- **Acceptance criteria:** Email is never public; disabling a profile does not
  unpublish recipes; recipes retain selected attribution; private recipes and
  personal organisation are excluded.
- **Suggested automated tests:** Display-name modes, disabled profile, public
  recipe attribution, email leakage, remix listing, and privacy tests.
- **Risk:** High.
- **Estimated size:** Medium.

### REC-15 — P2 — Import pasted recipe text into a draft

- **Outcome:** Convert pasted text into a reviewable private draft while
  preserving the full source text and parser provenance.
- **Dependencies:** REC-04, FND-09, DEP-04, DEP-05.
- **Acceptance criteria:** Parsing runs as idempotent, correlated queued work
  under FND-09; import never publishes automatically; uncertain parsing is
  visibly reviewable; original ingredient and instruction wording is retained;
  failures leave no partial finalized content.
- **Suggested automated tests:** Representative formats, ambiguous lines,
  malformed input, idempotent retry, provenance, and private-draft tests.
- **Risk:** High.
- **Estimated size:** Medium.

### REC-16 — P3 — Import a webpage recipe into a draft

- **Outcome:** Fetch a user-supplied URL safely and create a source-attributed,
  reviewable private draft.
- **Dependencies:** REC-15, DEC-005, DEC-007, FND-09, DEP-04, DEP-05.
- **Acceptance criteria:** Fetch and extraction run as idempotent, correlated
  queued work under FND-09; network access is protected against SSRF and size/
  timeout abuse; structured data and fallback extraction preserve source URL
  and wording; redirects and unsupported pages fail safely.
- **Suggested automated tests:** Structured recipe, fallback page, redirects,
  private/local network denial, oversized response, timeout, and attribution
  tests.
- **Risk:** High.
- **Estimated size:** Large.

### REC-17 — P3 — Import document and photo recipes transiently

- **Outcome:** Extract an uploaded supported document or image into a private
  draft without retaining the source upload as an attachment.
- **Dependencies:** REC-15, DEC-006, DEC-007, FND-09, DEP-04, DEP-05.
- **Acceptance criteria:** Extraction runs as idempotent, correlated queued work
  under FND-09; type/size limits are enforced; uploads are private, malware-
  scanned where required, and deleted after success or failure; extracted
  wording and confidence are reviewable.
- **Suggested automated tests:** Supported/unsupported files, spoofed MIME,
  size limit, extraction failure, retry, storage privacy, and verified cleanup
  tests.
- **Risk:** High.
- **Estimated size:** Large.

## 4. Nutrition and food matching

### NUT-01 — P1 — Add shared catalogue identity and provenance schema

- **Outcome:** Introduce a shared food/product record whose submitting user is
  provenance rather than ownership.
- **Dependencies:** FND-02, FND-03, FND-05.
- **Acceptance criteria:** Barcode and manual records are distinguishable;
  submitter is nullable and nulls on user deletion; source identifier, import
  time, lifecycle/moderation state, and current version are represented;
  existing ingredient data is untouched.
- **Suggested automated tests:** Migration up/down on populated fixtures,
  nullable submitter, null-on-delete, barcode uniqueness rules, and model
  relationship tests.
- **Risk:** High.
- **Estimated size:** Medium.

### NUT-02 — P1 — Backfill existing ingredients into catalogue candidates

- **Outcome:** Copy and classify current records into the new catalogue model
  without removing or silently merging user data.
- **Dependencies:** NUT-01, STB-08.
- **Acceptance criteria:** The command is resumable and reports legacy manual,
  verified imported, ambiguous barcode, and duplicate records; source IDs map
  old to new; no old row is changed or deleted; ambiguous cases await review;
  no merge or de-duplication action is taken until DEC-011 is decided.
- **Suggested automated tests:** Empty database, mixed fixtures, duplicates,
  interruption/resume, dry run, row-count reconciliation, and rollback tests.
- **Risk:** High.
- **Estimated size:** Large.

### NUT-03 — P1 — Cut reads over to the shared catalogue

- **Outcome:** Make catalogue browsing global while protecting pending manual
  submissions and preventing ordinary-user edits of imported records.
- **Dependencies:** NUT-02, FND-03.
- **Acceptance criteria:** Approved records are shared; pending manual records
  are visible only to submitter/admin; barcode imports cannot be edited or
  deleted by ordinary users; legacy routes remain compatible or redirect
  intentionally.
- **Suggested automated tests:** Guest/user/admin visibility matrix, pending
  isolation, imported mutation denial, submitter deletion, and legacy-route
  tests.
- **Risk:** High.
- **Estimated size:** Large.

### NUT-04 — P1 — Model package and serving structure

- **Outcome:** Represent package count, internal item type, amount per item,
  servings per item, and reliably derived serving amount without overloading
  one quantity field.
- **Dependencies:** NUT-01, FND-06.
- **Acceptance criteria:** Unknown values are null rather than zero; paired
  values validate together; multipacks such as `4 cans × 400 g` retain every
  component; derivation records its basis.
- **Suggested automated tests:** Single item, multipack, partial source data,
  invalid pairs, null-vs-zero, and reliable serving derivation tests.
- **Risk:** High.
- **Estimated size:** Medium.

### NUT-05 — P1 — Normalize catalogue nutrition and provenance

- **Outcome:** Store all supported nutrients with basis, unit, source
  precision, catalogue version, and field-level provenance.
- **Dependencies:** NUT-01, FND-06.
- **Acceptance criteria:** Per-100g and per-serving values are unambiguous;
  source values are not destructively rounded; kcal/kJ rules are applied;
  imported, manually submitted, and corrected data remain distinguishable.
- **Suggested automated tests:** Full nutrient set, basis/unit validation,
  precision round-trip, energy derivation/conflict, source metadata, and
  incomplete dataset tests.
- **Risk:** High.
- **Estimated size:** Large.

### NUT-06 — P1 — Import barcodes into the shared catalogue

- **Outcome:** Scan a barcode, reuse an existing shared product, or create one
  versioned OpenFoodFacts-backed catalogue record.
- **Dependencies:** STB-07, STB-09, NUT-03, NUT-04, NUT-05.
- **Acceptance criteria:** Barcode identity is globally consistent; concurrent
  scans are idempotent; only successful provider results create products;
  package, serving, nutrition, image, and source data map to the new schema.
- **Suggested automated tests:** Existing barcode, new import, concurrent
  duplicate, missing product, partial product, provider failure, and mapping
  tests.
- **Risk:** High.
- **Estimated size:** Large.

### NUT-07 — P1 — Add catalogue search and manual match selection

- **Outcome:** Let recipe creators search approved catalogue records and
  explicitly attach or replace a line's food match.
- **Dependencies:** REC-02, NUT-03.
- **Acceptance criteria:** Search does not leak pending records; recipe text
  remains unchanged after matching; a match records actor, catalogue version,
  provenance, and review state; creators can clear it.
- **Suggested automated tests:** Search relevance/filtering, pending isolation,
  attach/replace/clear, non-owner denial, version reference, and original-text
  integrity tests.
- **Risk:** High.
- **Estimated size:** Medium.

### NUT-08 — P2 — Add pending manual catalogue submissions

- **Outcome:** Let a user submit a rare non-barcode food for private use while
  it awaits moderation.
- **Dependencies:** NUT-03, NUT-04, NUT-05, DEC-011.
- **Acceptance criteria:** Barcode is prohibited; status starts pending;
  submitter/admin visibility is enforced; recipes may reference it; rejection
  never silently substitutes another item.
- **Suggested automated tests:** Submission validation, privacy matrix,
  recipe use, barcode rejection, duplicate handling, and rejection behavior
  tests.
- **Risk:** High.
- **Estimated size:** Medium.

### NUT-09 — P2 — Add catalogue moderation queue and decisions

- **Outcome:** Allow administrators to approve or reject manual submissions
  with an auditable decision.
- **Dependencies:** NUT-08, FND-04, FND-05, FND-14.
- **Acceptance criteria:** Queue filters by state/type; decisions require an
  administrator and optional note; approval makes the item shared; rejection
  leaves dependent recipe lines reviewable or unmatched.
- **Suggested automated tests:** Queue authorization, approve/reject,
  double-decision race, audit event, visibility transition, and dependent-line
  behavior tests.
- **Risk:** High.
- **Estimated size:** Medium.

### NUT-10 — P2 — Add catalogue correction proposals

- **Outcome:** Let users propose changes without directly mutating shared
  catalogue data.
- **Dependencies:** NUT-09.
- **Acceptance criteria:** Proposals retain before/after values, reason,
  proposer, and base version; admin acceptance creates a new version;
  rejection preserves the current version; stale proposals require review.
- **Suggested automated tests:** Proposal validation, ordinary-user edit
  denial, accept/reject, stale base version, audit trail, and immutable prior
  version tests.
- **Risk:** High.
- **Estimated size:** Medium.

### NUT-11 — P2 — Stage OpenFoodFacts refreshes for moderation

- **Outcome:** Fetch newer provider data without silently changing current
  catalogue values.
- **Dependencies:** NUT-06, NUT-09, FND-09, DEP-04, DEP-05.
- **Acceptance criteria:** Refresh runs as idempotent, correlated queued work
  under FND-09; unchanged data creates no proposal; differences are reviewable
  by field; approval creates a sourced version; failures leave current data
  intact.
- **Suggested automated tests:** No-change, changed payload, concurrent refresh,
  provider failure, approve/reject, provenance, and version-history tests.
- **Risk:** High.
- **Estimated size:** Large.

### NUT-12 — P1 — Persist automatic match evidence

- **Outcome:** Store candidate score, confidence band, threshold version,
  review state, chosen catalogue version, and manual/automatic provenance for
  each recipe line match.
- **Dependencies:** REC-02, NUT-07, DEC-001.
- **Acceptance criteria:** No candidate below the minimum is selected; low-
  confidence selections require review; high-confidence selections can be
  accepted; manual choices remain distinguishable and replaceable.
- **Suggested automated tests:** Schema constraints, below/low/high confidence,
  manual replacement, clearing, and catalogue-version pinning tests.
- **Risk:** High.
- **Estimated size:** Medium.

### NUT-13 — P1 — Implement deterministic catalogue candidate ranking

- **Outcome:** Rank approved catalogue matches for structured recipe lines and
  apply the recorded selection thresholds.
- **Dependencies:** NUT-12, DEC-001.
- **Acceptance criteria:** Ranking is deterministic and explainable; pending or
  inaccessible records are excluded; highest qualifying candidate is selected;
  low confidence is flagged and sub-threshold results stay unmatched.
- **Suggested automated tests:** Fixed candidate fixtures around every
  threshold, ties, no results, inaccessible records, spelling variants, and
  repeatability tests.
- **Risk:** High.
- **Estimated size:** Large.

### NUT-14 — P1 — Add reliable quantity conversion for calculations

- **Outcome:** Convert same-dimension units and explicitly approved food-
  dependent conversions while refusing unsupported guesses.
- **Dependencies:** FND-06, NUT-07.
- **Acceptance criteria:** Standard mass/volume conversions are exact within
  defined tolerance; custom units can resize but do not imply nutrition
  conversion; food-specific conversion records source and reliability;
  unsupported conversion returns an exclusion reason.
- **Suggested automated tests:** Mass and volume conversions, custom/count
  units, reliable food conversion, missing conversion, invalid dimension, and
  precision tests.
- **Risk:** High.
- **Estimated size:** Medium.

### NUT-15 — P1 — Calculate recipe nutrition estimates

- **Outcome:** Calculate versioned whole-recipe and per-serving nutrient
  estimates from structured lines, catalogue versions, quantities, and
  reliable conversions.
- **Dependencies:** REC-05, NUT-05, NUT-12, NUT-14.
- **Acceptance criteria:** All supported nutrients calculate independently;
  excluded lines are not guessed; serving division is correct; inputs and
  catalogue versions are traceable; outputs are explicitly marked estimated.
- **Suggested automated tests:** Complete recipe, partial nutrients, mixed
  bases, serving scaling, catalog version pinning, rounding-at-display only,
  and unsupported conversion tests.
- **Risk:** High.
- **Estimated size:** Large.

### NUT-16 — P1 — Show estimate completeness and excluded lines

- **Outcome:** Present useful partial estimates with clear limitations and a
  path to review unmatched, low-confidence, or unconvertible lines.
- **Dependencies:** NUT-13, NUT-15.
- **Acceptance criteria:** Whole/per-serving estimates carry an estimate label;
  completeness identifies every excluded or review-needed line; missing data
  is not displayed as zero; creators can navigate to correct the issue.
- **Suggested automated tests:** Complete/partial/unavailable estimates,
  unmatched and conversion exclusions, low confidence, zero values, and
  accessible warning markup tests.
- **Risk:** High.
- **Estimated size:** Medium.

### NUT-17 — P2 — Add recipe nutrition source precedence and overrides

- **Outcome:** Choose creator override, imported-source nutrition, or
  ingredient estimate in the specified order while retaining comparison and
  change history.
- **Dependencies:** NUT-15, FND-05, REC-15.
- **Acceptance criteria:** Primary source follows the documented precedence;
  imported-source values preserve provenance; ingredient estimates remain
  available as a collapsed comparison; overrides keep prior value, source,
  timestamp, actor, and optional note.
- **Suggested automated tests:** Every source combination, precedence,
  override/add/remove, comparison visibility, audit event, and version
  isolation tests.
- **Risk:** High.
- **Estimated size:** Large.

### NUT-18 — P2 — Recalculate affected recipes after catalogue approval

- **Outcome:** Update ingredient-calculated recipe estimates after an approved
  catalogue version while leaving imported and manually overridden primary
  values stable.
- **Dependencies:** NUT-10 or NUT-11, NUT-17, FND-09, DEP-04, DEP-05.
- **Acceptance criteria:** Only affected recipe versions are queued;
  recalculation is idempotent and traceable; failures retry safely; primary
  source precedence is unchanged; historical plan/diary snapshots are never
  rewritten.
- **Suggested automated tests:** Dependency selection, idempotency, retry,
  calculated update, imported/override stability, and snapshot immutability
  tests.
- **Risk:** High.
- **Estimated size:** Large.

## 5. Meal and diet planning

### PLAN-01 — P1 — Add owned meal-plan identity and plan types

- **Outcome:** Let a user create a private reusable undated schedule or dated
  plan under one planning model.
- **Dependencies:** FND-03, REC-05.
- **Acceptance criteria:** Each plan has exactly one owner and type; only the
  owner edits it; privacy defaults to private; invalid type/date combinations
  are rejected.
- **Suggested automated tests:** Both plan types, validation, owner/non-owner/
  guest access, private default, and factory tests.
- **Risk:** High.
- **Estimated size:** Medium.

### PLAN-02 — P1 — Add plan days and default slots

- **Outcome:** Create plan days with Breakfast, Lunch, Dinner, Drinks, and
  Snacks slots and allow supported customization.
- **Dependencies:** PLAN-01.
- **Acceptance criteria:** Standard meal slots can be renamed; Drinks and
  Snacks names are fixed; extra slots can be added and ordered; reusable and
  dated plans use the same rules.
- **Suggested automated tests:** Default creation, rename permissions, fixed-
  name rejection, add/reorder, date/template indexing, and cross-owner tests.
- **Risk:** Medium.
- **Estimated size:** Medium.

### PLAN-03 — P1 — Add recipe entries with version snapshots

- **Outcome:** Place a finalized recipe in a slot with planned servings while
  pinning the recipe version and relevant nutrition inputs.
- **Dependencies:** PLAN-02, REC-07, NUT-15.
- **Acceptance criteria:** Drafts are rejected; entry records planned amount
  and immutable snapshot/version identity; later recipe publishing does not
  silently alter the entry; private recipe access remains owner-scoped.
- **Suggested automated tests:** Add/move/remove, draft rejection, serving
  validation, version pinning, later publish stability, and authorization
  tests.
- **Risk:** High.
- **Estimated size:** Large.

### PLAN-04 — P1 — Add catalogue and one-off plan entries

- **Outcome:** Record an approved catalogue product or a private one-off item
  without forcing it into a recipe or shared catalogue.
- **Dependencies:** PLAN-02, NUT-05.
- **Acceptance criteria:** Entry kinds are explicit and mutually valid;
  catalogue version/amount is pinned; one-off wording and nutrition are private
  to the entry; catalogue submission is a separate deliberate action.
- **Suggested automated tests:** Each entry kind, invalid mixed payload,
  catalogue version pinning, one-off privacy, and cross-owner tests.
- **Risk:** High.
- **Estimated size:** Medium.

### PLAN-05 — P1 — Record planned versus consumed state

- **Outcome:** Let users mark dated entries consumed with an actual quantity
  and time, defaulting actual amount to planned amount for low-friction entry.
- **Dependencies:** PLAN-03, PLAN-04.
- **Acceptance criteria:** Only dated/ad-hoc diary entries can be consumed;
  actual amount is editable; consumption time is captured; reversing or
  correcting consumption is audited.
- **Suggested automated tests:** Default amount, changed amount/time, undated
  rejection, consume/unconsume/correct, validation, authorization, and audit
  tests.
- **Risk:** High.
- **Estimated size:** Medium.

### PLAN-06 — P1 — Snapshot nutrition at consumption time

- **Outcome:** Preserve the exact nutrition and item/recipe versions used for
  historical intake.
- **Dependencies:** PLAN-05, NUT-17, FND-05.
- **Acceptance criteria:** Snapshot is created atomically with consumption,
  records source and estimate status, and never recalculates after recipe or
  catalogue changes; corrections create auditable replacement history.
- **Suggested automated tests:** Atomic creation, source/version capture,
  later source changes, failed transaction, correction history, and immutable
  snapshot tests.
- **Risk:** High.
- **Estimated size:** Large.

### PLAN-07 — P2 — Notify and review newer recipe versions

- **Outcome:** Tell a plan owner when a pinned planned recipe has a newer
  version and let them update or retain the snapshot explicitly.
- **Dependencies:** PLAN-03, REC-07, FND-09, DEP-04, DEP-05.
- **Acceptance criteria:** New-version fan-out runs as idempotent, correlated
  queued work under FND-09; one notification is created per affected entry/
  version; consumed entries are excluded; update and retain choices are
  explicit and audited; no silent mutation occurs.
- **Suggested automated tests:** Publish trigger, deduplication, consumed-entry
  exclusion, update, permanent retain, authorization, and notification cleanup
  tests.
- **Risk:** Medium.
- **Estimated size:** Medium.

### PLAN-08 — P2 — Add selected-user sharing, public sharing, and plan bookmarks

- **Outcome:** Share a plan without granting edit rights, let authenticated
  users privately bookmark an active-owner public plan, and protect private
  recipe content.
- **Dependencies:** PLAN-03, FND-03.
- **Acceptance criteria:** Selected users have read-only access; public plans
  are visible logged out; public sharing is blocked unless the complete
  presentation and every pinned snapshot are proven public-safe; selected-user
  sharing requires explicit acknowledgement of scoped private recipe snapshot
  access; revocation is immediate; bookmarks are private to their user,
  removable, distinct from independent copies, and can support the DEC-014
  retained-unlisted lifecycle without exposing bookmark-owner identity.
- **Suggested automated tests:** Full viewer/editor matrix, acknowledgement,
  whole-plan public-safety failure, guest view, revocation, bookmark add/remove/
  privacy, bookmark-versus-copy distinction, and data-minimization tests.
- **Risk:** High.
- **Estimated size:** Large.

### PLAN-09 — P2 — Copy an accessible meal plan

- **Outcome:** Let an authenticated viewer create an independent private copy
  they own.
- **Dependencies:** PLAN-08.
- **Acceptance criteria:** Copy includes slots and usable snapshots, excludes
  source ownership/shares/bookmarks/diary consumption, starts private, does not
  count as a source bookmark, can be created from a qualifying retained
  anonymized plan, and survives later source revocation or deletion.
- **Suggested automated tests:** Public, retained-anonymized, and selected-share
  copy; inaccessible denial; independence; private default; bookmark-count
  isolation; source revocation; and copied-content authorization tests.
- **Risk:** High.
- **Estimated size:** Medium.

### PLAN-10 — P2 — Add nutrition target profiles

- **Outcome:** Give each user a default daily target profile and optional named
  profiles for any supported nutrient.
- **Dependencies:** FND-06, PLAN-01.
- **Acceptance criteria:** Targets support exact, minimum, maximum, and range;
  blank means no target; range validation is consistent; profiles are private
  and one is designated default.
- **Suggested automated tests:** Default creation, every target type, blank
  nutrient, invalid range, default switching, cross-user denial, and full
  nutrient-set tests.
- **Risk:** Medium.
- **Estimated size:** Medium.

### PLAN-11 — P2 — Assign dated target phases

- **Outcome:** Apply named target profiles to a plan over dated phases while
  preserving the profile values that applied historically.
- **Dependencies:** PLAN-10.
- **Acceptance criteria:** Phases have a start and optional end; overlaps are
  rejected or resolved by an explicitly documented rule; historical dates use
  the applicable version rather than today's profile.
- **Suggested automated tests:** Open/closed phases, boundary dates, overlap,
  profile edit after assignment, no-target date, and authorization tests.
- **Risk:** High.
- **Estimated size:** Medium.

### PLAN-12 — P2 — Compare planned and consumed totals with targets

- **Outcome:** Show daily nutrient totals against the target phase that applies
  to each date without presenting targets as medical advice.
- **Dependencies:** PLAN-06, PLAN-11.
- **Acceptance criteria:** Planned and consumed values are separate; exact/min/
  max/range states are correctly classified; untargeted and unavailable
  nutrients are distinct from zero; estimated values retain their labels; the
  UI includes an appropriate non-medical disclaimer.
- **Suggested automated tests:** Each target type and status, phase boundary,
  planned/consumed split, missing/partial nutrition, estimate label, and
  historical stability tests.
- **Risk:** High.
- **Estimated size:** Large.

## 6. User experience and accessibility

### UX-01 — P1 — Establish VibeDietr identity and primary navigation

- **Outcome:** Replace Laravel placeholder content with a truthful product
  landing page, useful authenticated dashboard, product metadata, and
  navigation for implemented areas.
- **Dependencies:** REC-01, NUT-03, PLAN-01.
- **Acceptance criteria:** No default Laravel identity remains in user-facing
  pages; unavailable future features are not presented as working; dashboard
  surfaces useful next actions and respects privacy; navigation works in light,
  dark, desktop, and mobile layouts.
- **Suggested automated tests:** Route/content assertions, guest/auth variants,
  navigation active state, metadata, and visual smoke snapshots.
- **Risk:** Low.
- **Estimated size:** Medium.

### UX-02 — P1 — Add accessible application feedback patterns

- **Outcome:** Standardize validation summaries, success/error notices,
  confirmations, loading states, and destructive-action warnings for keyboard
  and assistive-technology users.
- **Dependencies:** None.
- **Acceptance criteria:** Notices use appropriate live regions; errors are
  associated with fields; focus moves predictably after modal and validation
  events; destructive actions describe consequences; color is not the only
  signal.
- **Suggested automated tests:** Component rendering assertions, keyboard
  browser tests, focus tests, axe checks, and reduced-motion checks.
- **Risk:** Medium.
- **Estimated size:** Medium.

### UX-03 — P1 — Make recipe authoring and resizing mobile-friendly

- **Outcome:** Provide a low-friction, keyboard-accessible workflow for adding,
  reordering, reviewing, and resizing recipe content on small and large
  screens.
- **Dependencies:** REC-04, REC-08, UX-02.
- **Acceptance criteria:** Every action works without drag-and-drop; controls
  have accessible names and usable touch targets; long original text and
  validation errors do not break layout; unsaved changes are clear.
- **Suggested automated tests:** Mobile/desktop browser flows, keyboard reorder,
  zoom/reflow, long-content visual snapshots, and axe checks.
- **Risk:** Medium.
- **Estimated size:** Medium.

### UX-04 — P1 — Make matching and estimate limitations understandable

- **Outcome:** Help users distinguish source nutrition from estimates and
  quickly review low-confidence, unmatched, or unconvertible recipe lines.
- **Dependencies:** NUT-16, UX-02, DEC-002.
- **Acceptance criteria:** Labels use plain language; warnings identify the
  affected line and remedy; imported data is not mislabeled estimated;
  confidence is communicated without relying on color or unsupported claims.
- **Suggested automated tests:** Copy/state matrix, keyboard navigation,
  screen-reader labels, color-contrast/axe checks, and visual snapshots for all
  completeness states.
- **Risk:** High.
- **Estimated size:** Medium.

### UX-05 — P2 — Build an accessible responsive planning interface

- **Outcome:** Make slot, entry, consumption, and target workflows efficient
  on touch, keyboard, and desktop interfaces.
- **Dependencies:** PLAN-05, PLAN-12, UX-02.
- **Acceptance criteria:** Moving entries has a non-drag alternative; planned
  and consumed states are unambiguous; date and quantity controls are labeled;
  dense nutrition information reflows at 200% zoom.
- **Suggested automated tests:** Keyboard and touch-equivalent flows, mobile
  visual snapshots, 200% zoom, screen-reader state labels, and axe checks.
- **Risk:** Medium.
- **Estimated size:** Large.

### UX-06 — P2 — Add onboarding, empty states, and recovery guidance

- **Outcome:** Guide a new or blocked user toward creating/importing a recipe,
  matching food, building a plan, and correcting incomplete nutrition.
- **Dependencies:** UX-01, REC-15, NUT-16, PLAN-03.
- **Acceptance criteria:** Empty states offer only permitted next actions;
  provider/import failures retain work and offer retry/manual alternatives;
  guidance never implies nutrition estimates are exact or medical advice.
- **Suggested automated tests:** New-account state, each empty collection,
  import/provider failures, retry, permission-specific actions, and content
  assertions.
- **Risk:** Low.
- **Estimated size:** Medium.

### UX-07 — P2 — Run a WCAG 2.2 AA remediation pass

- **Outcome:** Resolve accessibility defects across authentication, catalogue,
  recipe, planning, profile, sharing, moderation, and deletion/export flows.
- **Dependencies:** UX-01 through UX-06 and the corresponding feature pages.
- **Acceptance criteria:** Automated scans have no serious/critical findings;
  complete journeys work by keyboard and screen reader; focus order, contrast,
  landmarks, names, errors, zoom, and motion are manually verified and
  documented.
- **Suggested automated tests:** Axe browser suite on representative states,
  keyboard journey tests, HTML validation, contrast checks, and visual
  regression snapshots.
- **Risk:** High.
- **Estimated size:** Large.

## 7. Deployment readiness

### DEP-01 — P0 — Document and align the supported development environment

- **Outcome:** Replace the default README and conflicting environment defaults
  with accurate Sail/MySQL setup, test, format, analysis, build, queue, and
  troubleshooting instructions.
- **Dependencies:** FND-07.
- **Acceptance criteria:** A fresh checkout can follow the documentation
  without host PHP/Composer/Node; `.env.example` and Compose agree without
  credentials; destructive database commands are clearly warned against.
- **Suggested automated tests:** CI bootstrap from `.env.example`, configuration
  parse, migration/seed on a disposable database, and documented-command smoke
  tests.
- **Risk:** Medium.
- **Estimated size:** Medium.

### DEP-02 — P1 — Define production configuration and secret handling

- **Outcome:** Document required production variables and fail safely when
  keys, URLs, storage, database, cache, queue, mail, or provider settings are
  missing or insecure.
- **Dependencies:** DEP-01, FND-14, DEC-005, DEC-006.
- **Acceptance criteria:** No secret has a committed value; production debug is
  off; secure cookies, trusted proxies/hosts, cache, queue, mail, and provider
  identifiers are explicit; administrator bootstrap/recovery enablement and
  target constraints, second-factor configuration, and reliable security-
  notification delivery are explicit; startup validation fails safely with
  actionable errors when any required administrator control is unavailable.
- **Suggested automated tests:** Production-config test matrix, missing-secret
  failures, debug/cookie assertions, and configuration-cache smoke test.
- **Risk:** High.
- **Estimated size:** Medium.

### DEP-03 — P1 — Add security headers, throttles, and upload/request limits

- **Outcome:** Reduce browser and abuse risk around authentication, public
  search, barcode lookup, imports, sharing, and uploads.
- **Dependencies:** STB-09, REC-16, REC-17, DEP-02.
- **Acceptance criteria:** CSP supports locally bundled assets and required
  media; sensitive routes have documented throttles; request/upload limits are
  enforced; logs do not record secrets, raw passwords, or transient document
  contents.
- **Suggested automated tests:** Header assertions, throttle boundaries, CSP
  browser smoke test, oversized request/upload, and log-redaction tests.
- **Risk:** High.
- **Estimated size:** Medium.

### DEP-04 — P1 — Operate queues, scheduling, and failure recovery

- **Outcome:** Provide deployable, privacy-safe worker, scheduler, and failed-
  job lifecycle configuration for imports, refreshes, recalculation, exports,
  notifications, deletion, and other asynchronous work.
- **Dependencies:** FND-09, DEP-02.
- **Production-enablement gate:** Complete this item before any queued or
  scheduled product workflow is implemented or enabled in production,
  including REC-15, REC-16, REC-17, NUT-11, NUT-18, PLAN-07, DEP-07, and
  DEP-08. These product items remain blocked until this gate passes.
- **Acceptance criteria:** A maintained job inventory maps every enabled job to
  its queue, worker, concurrency, timeout, `retry_after`, attempts/backoff,
  idempotency scope and lifetime, failure alert, replay rule, and failed-record
  retention. The default-versus-named queue topology, process supervision,
  resource limits, scheduler locking, graceful deployment restarts, and safe
  replay/forget runbooks are documented and exercised. Failed-job metadata is
  pruned seven calendar days after final failure or resolution, and any record
  containing personal payload is removed as soon as retry is no longer
  possible, in accordance with `AUDIT_RETENTION_SCHEDULE.md`. The task records
  an explicit operational decision on whether native Laravel workers and the
  database failed-job store remain sufficient or whether Horizon and/or a
  separate dead-letter mechanism is justified; neither is added without a
  demonstrated operational need.
- **Suggested automated tests:** Queue-topology/configuration validation,
  container/process smoke tests, scheduler overlap prevention, graceful
  termination, worker timeout versus `retry_after`, failed-job alert and
  privacy fixtures, retention-boundary pruning, and idempotent replay/forget
  tests.
- **Risk:** High.
- **Estimated size:** Large.

### DEP-05 — P1 — Add observability and health checks

- **Outcome:** Detect failures and performance regressions without exposing
  private recipe, diary, target, or import data.
- **Dependencies:** DEP-02, DEP-04.
- **Production-enablement gate:** Complete this item before any queued or
  scheduled product workflow is enabled in production. Every later roadmap
  item that introduces queued or scheduled work must declare both DEP-04 and
  DEP-05 as dependencies.
- **Acceptance criteria:** Liveness and dependency readiness are separate;
  structured logs carry correlation IDs; exceptions, queue failures, provider
  latency, and critical workflow metrics are monitored with data redaction.
  Queue monitoring covers worker availability, queue depth, oldest-job age,
  processing latency, retry/final-failure rates, scheduler freshness, and
  failed-job pruning or replay anomalies. Alert thresholds, recipients, and
  response runbooks are documented without placing private job payloads in
  telemetry.
- **Suggested automated tests:** Health-state matrix, log structure/redaction,
  correlation propagation, unavailable-worker/backlog/failure-spike fixtures,
  pruning-staleness detection, simulated dependency failure, and alert smoke
  tests.
- **Risk:** High.
- **Estimated size:** Medium.

### DEP-06 — P1 — Add backup, restore, and migration runbooks

- **Outcome:** Make database and private storage recoverable and schema rollout
  safe before production data exists.
- **Dependencies:** FND-02, DEC-012, DEP-02.
- **Acceptance criteria:** Backup scope, encryption, retention, restore target,
  expand/contract rollout, and rollback are documented; a restore drill proves
  referential integrity and representative public/private data access.
- **Suggested automated tests:** Automated backup verification, disposable
  restore drill, migration preflight/postflight queries, and checksum/count
  reconciliation.
- **Risk:** High.
- **Estimated size:** Large.

### DEP-07 — P2 — Add self-service account data export

- **Outcome:** Produce a secure export of data owned by the requester without
  leaking another user's private data from shares, bookmarks, catalogue, or
  remixes.
- **Dependencies:** PLAN-12, REC-14, DEC-008, FND-09, DEP-04, DEP-05.
- **Acceptance criteria:** Export generation and scheduled file cleanup run as
  idempotent, correlated queued work under FND-09. Export includes account,
  owned recipes and versions, organisation, owned plans, diary, targets, one-
  off items, and owned proposals; shared references are minimized; download is
  authenticated, expiring, and audited; generated files are deleted on
  schedule.
- **Suggested automated tests:** Complete fixture export, cross-user leakage,
  shared/private references, expiry, repeat request, cleanup, and audit tests.
- **Risk:** High.
- **Estimated size:** Large.

### DEP-08 — P2 — Replace immediate account deletion with recovery and purge

- **Outcome:** Implement optional recovery for up to 30 days followed by
  privacy-aware purge/anonymization, with an authenticated immediate-purge path.
- **Dependencies:** DEC-012, FND-05, FND-09, FND-14, DEP-04, DEP-05,
  DEP-06, DEP-07, all owned domain models.
- **Acceptance criteria:** Recovery expiry and final purge run as idempotent,
  correlated scheduled queued work under FND-09. The request clearly explains
  consequences and makes
  the account inactive immediately only when DEC-009's sole-administrator rule
  permits it; a replacement administrator must be active first. An
  authenticated user may waive recovery and request immediate final purge
  unless that safeguard or a documented hold applies. A confirmed under-13
  account is disabled and purged without recovery; its public recipes and plans
  are hidden and deleted while independently owned copies keep their own
  lifecycle. Public plans
  with another-user bookmarks are immediately anonymized as non-linked `Former
  VibeDietr user`, unlisted,
  retained at their existing URLs, closed to new bookmarks, and available for
  independent private copying; zero-bookmark and public-safety-invalid plans
  become unavailable immediately. Existing bookmark removal deletes a retained
  plan after its final bookmark. Login/data processing is appropriately
  restricted during recovery; secure recovery during an unwaived recovery period restores
  ownership, attribution, unavailable plans, previous visibility, and normal
  bookmarking. Final purge removes specified private data, unavailable plans,
  and recovery-only attribution while leaving a qualifying retained plan
  non-reclaimable and independent remixes/copies intact. Retained snapshots are
  minimized to proven public-safe presentation data; administrators may
  exceptionally suppress or permanently remove retained plans but cannot
  restore, reattribute, transfer, or extend recovery. Backup/legal exceptions
  and the purpose-specific audit schedule match documented policy.
- **Suggested automated tests:** Request/cancel/recover, recovery waiver,
  immediate final purge, confirmed under-13 purge/public-content removal,
  sole-administrator denial, replacement-first enforcement, boundary times,
  immediate public-plan transition, zero/one/multiple bookmarks, disabled new
  bookmark, last-bookmark deletion before and after purge, unlisted stable URL,
  attribution/profile leakage, whole-plan fail-closed validation, retained-plan
  copying, administrator removal/restore denial, idempotent purge, every owned
  resource, public anonymization, catalogue submitter nulling, remix/copy
  survival, and audit-minimization tests.
- **Risk:** High.
- **Estimated size:** Large.

### DEP-09 — P2 — Complete privacy, retention, and legal launch review

- **Outcome:** Align product wording and operational policy with the system's
  actual privacy, retention, erasure, moderation, and nutrition-advice
  behavior without claiming automatic legal compliance.
- **Dependencies:** DEP-07, DEP-08, FND-05, DEC-010, DEC-012.
- **Acceptance criteria:** Privacy notice, terms, retention schedule, processor/
  provider inventory, cookie behavior, rights-request process, moderation
  policy, children's age-band and safety handling, and nutrition disclaimers
  are reviewed by the owner; UI wording and configured deletion match
  `AUDIT_RETENTION_SCHEDULE.md` and the implementation. The launch record states
  that DEC-013 received an owner-led legal-risk review rather than professional
  legal approval, and specialist review is added if the owner later identifies
  a high-risk request or material scope change.
- **Suggested automated tests:** Link/content presence, policy-version
  acceptance where required, cookie scan, and automated checks that prohibited
  compliance/accuracy claims are absent from key pages.
- **Risk:** High.
- **Estimated size:** Medium.

### DEP-10 — P2 — Run performance, security, and release-readiness checks

- **Outcome:** Prove the production candidate can safely serve core public and
  private workflows at an agreed load and can be rolled back.
- **Dependencies:** DEP-03 through DEP-09, UX-07.
- **Acceptance criteria:** Performance budgets and representative load are
  recorded; N+1 and slow-query findings are resolved; dependency/security
  scans are reviewed; authorization and upload/import threat models are
  exercised; release, rollback, smoke-test, and incident runbooks pass a
  rehearsal.
- **Suggested automated tests:** Load suite for discovery/catalogue/plans,
  query-count regression tests, dependency and secret scans, DAST against a
  staging environment, end-to-end smoke suite, and rollback drill.
- **Risk:** High.
- **Estimated size:** Large.

## Explicitly out of scope

The following remain future scope unless `PRODUCT_SPEC.md` changes:

- Multi-person or family plans with participant-specific servings or targets.
- Collaborative multi-writer recipe or meal-plan editing.
