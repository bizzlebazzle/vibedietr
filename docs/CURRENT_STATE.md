# Current state

This document records behavior visible in the working repository. Its
ingredient baseline is commit `1adc7bbf0b74c8bd1687fda81ed26e301f2eb9e4`.
It describes the code as it exists, not the intended end state in `AGENTS.md`.

## Application shape

- Laravel 12 is the application framework. The Composer manifest requires PHP
  8.2 or newer, Livewire 3.6, and Livewire Volt 1.7.
- Authentication and profile screens are based on Laravel Breeze and are
  implemented with Livewire/Volt.
- Tailwind CSS, Alpine.js, and Vite provide the browser UI and asset pipeline.
- The active local `.env` uses MySQL through the WSL/Docker-based Sail stack.
  Sessions, cache, and queues are database-backed.
- `.env.example` still defaults to SQLite.
- A Laravel Sail configuration also exists. It provisions PHP 8.4, MySQL,
  Redis, Meilisearch, Mailpit, and Selenium. The application code does not
  currently contain a search integration or code specific to Meilisearch.
- Larastan 3.10 with PHPStan 2.2 analyses application and test PHP at level 5.
  A 10-finding reviewed baseline isolates existing debt, and GitHub Actions
  runs analysis plus a deliberately invalid failure regression.
- GitHub Actions runs independent backend-test, PHP-formatting, static-analysis,
  and frontend-build quality gates for pull requests and pushes to `main`. The
  workflow uses PHP 8.4, Node 22.18, and a clean MySQL 8.0 service database.
  Composer and npm package-download caches are lock-file keyed; dependency
  installation still uses `composer install` and `npm ci` on every run.
- Documentation validation uses markdownlint-cli2 for structure and style,
  markdown-link-check for deterministic local links, and a small Node validator
  for decision IDs, fields, statuses, backlog references, stable identities,
  and deferred-product-decision coverage. The frontend CI job runs the combined
  check and its failure-regression fixtures after `npm ci`.
- The public landing page and repository README are still the Laravel defaults.
  The authenticated dashboard only confirms that the user is logged in.

## Implemented user and account behavior

The application currently provides:

- Registration, login, logout, password reset, and password confirmation.
- A profile screen where a user can change their name, email address, and
  password or permanently delete their account.
- Light, dark, and system theme choices. The preference is kept in browser
  local storage rather than in the user record.
- Email-verification routes and screens from the authentication scaffold.
- Persistent administrator status that defaults to false and is excluded from
  ordinary mass assignment, registration, and profile input.
- A central `access-admin` authorization gate.

The dashboard route has `auth` and `verified` middleware, but `App\Models\User`
does not implement `Illuminate\Contracts\Auth\MustVerifyEmail`. Laravel's
verification middleware therefore does not require these users to have a
verified email. The verification controller and direct verification tests can
still mark `email_verified_at`.

The owner has confirmed that email verification should remain optional for
now. A production-capable outbound email service has not been selected.

Administrator-only routes can combine `auth` with `can:access-admin`.
Controller and Livewire actions that can be invoked independently must call
`authorize('access-admin')` at their action boundary rather than relying only
on route or interface protection. Confirmed administrators pass the gate,
authenticated ordinary users receive a 403 response, and guests using the
documented route middleware combination are redirected to login.

FND-04 provides the persisted authorization flag while FND-14 now owns every
production assignment and revocation path. The normal seeder creates an
ordinary user, registration/profile input cannot change administrator status,
and the explicit administrator factory state is rejected in production.

Deleting a user permanently deletes that user. The ingredient foreign key uses
`cascadeOnDelete`, so their ingredients are deleted by the database at the same
time.

## Implemented audit-event foundation

FND-05 provides an ordinary application audit store with two additive tables:
`audit_events` and `audit_actor_identities`. Events use application-generated
ULIDs, a server-authoritative UTC occurrence timestamp, allowlisted action,
purpose, retention, actor and subject enums, a schema version, optional bounded
correlation and protected-evidence references, and a small action-specific JSON
payload. An HMAC-SHA-256 over the canonical event fields detects out-of-band
mutation.

`AuditEventRecorder` is the only supported creation API. It derives purpose,
retention, permitted actor/subject categories and payload rules from the action;
callers cannot supply arbitrary classifications or metadata. The normal model
API rejects event updates and deletes. This is application-layer append-only
enforcement, not a claim that raw SQL or database administrators cannot mutate
records.

Authenticated users and administrators are linked through a separate random
ULID identity mapping containing only the nullable user ID. External operators
and deployments use a bounded opaque reference in that same erasable store.
Events keep the random mapping reference without a foreign key, so erasing the
mapping leaves the event and its integrity hash intact but removes the route to
the former identity. User subjects must use the same mapping rather than a raw
account identifier.

There is no production audit browser or arbitrary creation endpoint. Guests
and ordinary users have no audit-store access. Administrators may read only an
individual moderation/catalogue-purpose record through policy; administrator
status alone does not grant security or privileged-lifecycle audit access.
Retention scheduling, user-visible activity, monitored exports, legal holds,
protected evidence storage, and production action instrumentation are not
implemented. DEC-013 remains owner-led rather than professional legal review,
and DEC-012 still leaves the backup lifecycle unresolved.

## Implemented administrator security foundations

FND-13 adds confirmed RFC 6238 TOTP enrollment for active email-verified
accounts, without granting administrator privilege. Enrollment requires an
immediate password proof, persists only an encrypted pending seed, presents QR
and manual setup paths, verifies possession, generates ten one-time recovery
codes, and activates only after their explicit acknowledgement.

Active verification centrally enforces six digits, a 30-second period, current
plus adjacent timesteps, durable atomic replay consumption, account/factor/
operation and privacy-safe source throttles, increasing delay, and a 30-minute
lock after ten consecutive failures. Recent primary authentication and a
single-use, operation-bound fresh TOTP proof are separate session concepts.

Mandatory administrator security events have typed queued-mail definitions,
recipient-specific durable intents, correlation and idempotency identifiers,
provider-acceptance evidence, bounded retry, safe permanent failure handling,
and channel health state. Production readiness rejects local/fake delivery and
requires the DEC-016 transport, destination, credential, queue, monitoring,
capacity, clock and audit conditions. See
[Administrator security foundations](ADMINISTRATOR_SECURITY_FOUNDATIONS.md).

## Implemented administrator bootstrap and lifecycle

See [Administrator lifecycle](ADMINISTRATOR_LIFECYCLE.md).
FND-14 centralizes production privilege changes behind application-owned
lifecycle services. `administrator:bootstrap` is a configured, target-bound,
operator-confirmed CLI command. A locked singleton lifecycle row records the
one-time completion marker separately from user rows, and the same global lock
serializes role mutations and sole-administrator deletion checks. Bootstrap
atomically grants the role, records the FND-05 event, persists every required
FND-13 notification intent, and sets the marker; it never reopens.

Routine promotion persists a target-bound ULID request with pending, accepted,
declined, cancelled, or expired state and an exact 24-hour window. Initiation,
acceptance, decline, cancellation, and revocation enforce server-side ownership
and the DEC-015 recent-password/fresh-TOTP proofs. Revocation is other-admin
only, rotates remembered login state, deletes database sessions, cancels the
revoked administrator's pending initiations, and uses current database role
state for subsequent authorization checks. Self-revocation, concurrent removal
of all administrators, and sole-administrator account deletion are denied.

`administrator:break-glass-replace` is a separately configured and confirmed
CLI operation available only after initial bootstrap when no administrator
other than the configured compromised account is technically usable. It
atomically activates an eligible replacement, optionally revokes the exact
compromised account, invalidates that account's privileged access, audits both
outcomes, and creates required notification intents without changing the
bootstrap marker. The normal seeder remains ordinary-user only; model-level
production role changes and bootstrap-marker mutation outside the lifecycle
services fail closed.

## Implemented ingredient catalogue

Ingredients are the only food-related records currently represented.
Authenticated users can:

- List their own ingredients, ordered newest first and paginated 12 per page.
- Search their ingredients by partial name or partial barcode. The search term
  is reflected in the `q` query-string parameter.
- Create, view, edit, and permanently delete an ingredient.
- Use dedicated create, show, and edit pages.
- Create and inspect ingredients in modals on the list page. The component
  contains edit-modal state and a handler, but the rendered list does not
  expose a control that calls that handler.

An ingredient stores:

- Its owning user.
- Name and optional barcode.
- Package or item quantity and a required quantity unit.
- Optional serving quantity, serving unit, and recommended serving count.
- Optional image URL.
- Optional keyword and category arrays.
- Optional nutrition data as JSON.
- Creation and update timestamps.

Ingredient lists and individual records are scoped through the authenticated
user or checked with `IngredientPolicy`. The policy allows any authenticated
user to list and create records, but only the owning user can view, update, or
delete a particular ingredient. Controller and Livewire writes invoke the
policy at the mutation boundary. Restore and force-delete policy operations
are disabled; the model does not use soft deletes.

Controller show, edit, update, and delete return 403 for a non-owner, while
guests are redirected to login. Search and pagination remain owner-scoped. The
controller is the only ingredient deletion path and redirects to the
unfiltered first index page after a hard delete.

STB-03 makes direct Livewire behavior equivalent at the access-decision
boundary. `Ingredients\Form::save()` keeps only an untrusted scalar ingredient
identifier, re-resolves the record, and authorizes the authoritative model
immediately before update. Crafted identifiers, stale ownership, non-owner
updates, and direct guest saves receive 403 without mutation. Creation assigns
the authenticated user through the relationship, ownership is not mass
assignable, and update payloads cannot reassign it. The dormant edit-modal
opener and its nested form use the same secured mutation path.

STB-04 gives both retained write paths one `IngredientWriteContract` and one
`IngredientWriteNormalizer`. The contract validates the complete persisted
field allowlist, FND-06 units, an all-or-none serving quantity/unit pair,
barcodes as optional strings up to 64 characters, and a bounded nutrition
shape. Normalization trims nullable strings, stores empty optionals as null,
preserves numeric zero, canonicalizes standard unit aliases, retains safe
custom/ambiguous unit text, and quantizes nutrient decimals once to DEC-003's
scale without display rounding. Ownership is not part of the contract.

STB-05 makes the nutrition distinction strict: explicit numeric or numeric-
string zero is stored as JSON numeric `0`, while null, empty, and whitespace-
only normalized values are represented by an omitted key. Both normalized
buckets and both write paths use that convention.

## OpenFoodFacts and barcode support

The Livewire ingredient form can make a synchronous server-side request through
the application-owned OpenFoodFacts client for a supplied barcode. The client
uses the configurable production product-read endpoint and defaults to the
OpenFoodFacts v3.4 compatibility profile. This moves the integration off
deprecated v2 while retaining the documented flat nutrient fields required by
the current ingredient JSON model; v3.5 and later need an explicit mapper
review because the provider changed its nutrition schema.

The client supplies a configurable custom User-Agent, a two-second connection
timeout, a five-second total timeout, and at most two attempts. It retries only
connection/timeouts, HTTP 408, ordinary 5xx responses, and 429/503 throttles
whose `Retry-After` fits the short interactive ceiling. It returns typed
success, not-found, unavailable, rate-limited, invalid-response, or permanent-
failure results and writes only privacy-minimized correlated failure logs.
A successful lookup
can populate:

- Product name.
- Barcode.
- Keywords and OpenFoodFacts category tags.
- Product quantity and a parsed unit where the text can be interpreted.
- Serving quantity and a unit inferred from serving text.
- Nutrition data.
- A remote product image URL.

The browser form also contains a camera barcode scanner. It:

- Loads ZXing 0.20.0 from `unpkg.com` at runtime.
- Requests camera permission and enumerates available cameras.
- Supports camera selection and prefers a rear-facing camera.
- Requires HTTPS or localhost, as required by browser camera APIs.
- Sends a successful scan to the Livewire form, which immediately starts an
  OpenFoodFacts lookup.

Before fetching or saving through the Livewire form, the application checks for
another ingredient with the same non-empty barcode owned by the current user.
If one is found, it redirects to the existing record. This is an application
check only: the database has a non-unique barcode index, and the conventional
controller store/update paths do not perform the same duplicate check.

For the current user-owned implementation, the owner has confirmed that barcode
uniqueness should be scoped per user. There is no agreed de-duplication rule for
manually entered products without a barcode.

## Nutrition handling

Nutrition is stored in the ingredient's nullable `nutriments` JSON column. The
component uses up to three buckets:

- `raw`: the response returned in OpenFoodFacts' `nutriments` object.
- `per_100g`: normalized values per 100 grams.
- `per_serving`: normalized values per serving.

OpenFoodFacts lookup maps energy, carbohydrate, fat, fibre, protein, salt,
saturated fat, sodium, and sugars into the normalized buckets when supplied.
The form and ingredient detail presentation expose that complete supported set
through the FND-06 registry rather than component-local nutrient lists. The
ingredient index intentionally remains a compact identity and quantity list.

Normalized nutrition values are validated as non-negative DEC-003 decimals,
stored as scale-18 decimal strings, and are not rounded to DEC-004 display
precision during writes. Null or blank nutrient values remain missing, while
numeric zero and string `"0"` remain explicit zero. The shared write normalizer
uses kcal as the canonical energy basis: kcal-only derives kJ, kJ-only derives
and stores canonical kcal before regenerating the normalized kJ pair, and kcal
wins when both supplied values conflict. Conversion uses exact decimal
arithmetic and one central `4.184` factor without intermediate display
rounding. The OpenFoodFacts response is decoded with numeric tokens retained as
exact decimal strings rather than PHP floats. Source observations, including a
conflicting supplied kJ value, remain identifiable in the existing `raw`
bucket.

The code does not:

- Calculate nutrition from a quantity or serving size.
- Calculate recipe or meal totals.
- Invoke the shared converter to recalculate an ingredient quantity in the UI.
- Record whether a value was imported, entered manually, calculated, or later
  edited.
- Attach an accuracy or estimate label to nutrition values.

## Shared nutrient and measurement definitions

FND-06 adds application-owned definitions under `app/Domain/Nutrition` and
`app/Domain/Measurements`:

- The supported nutrient catalogue covers kcal, kJ, fat, saturated fat,
  carbohydrates, sugars, fibre, protein, salt, and sodium with stable IDs,
  canonical/display units, all supported bases, DEC-003 storage/calculation
  precision, and DEC-004 display precision.
- Kcal is the authoritative canonical energy value; kJ is derived exactly with
  `1 kcal = 4.184 kJ`. Mass nutrients store canonically in grams, including
  sodium, whose preferred display unit is milligrams.
- Measurement definitions distinguish mass, volume, count, and custom units.
  Mass and volume standard units carry exact factors to grams or millilitres.
  Count units support identity conversion only.
- Teaspoons/tablespoons use exact modern UK recipe measures of 5/15 ml. Fluid
  ounces, cups, liquid pints/quarts/gallons use exact US customary measures.
- `UnitConverter` rejects cross-dimension, count-to-unrelated-count, and custom
  conversion. No density or food-dependent conversion exists.
- Safe aliases normalize through one registry. Unknown or ambiguous inputs,
  including `T` and `t`, remain custom and preserve their original text.

The ingredient Livewire form uses the shared unit catalogue for choices and
OpenFoodFacts unit inference. STB-04 makes controller and Livewire writes
consume the same unit/nutrient validation and normalization contract. STB-06
now derives form and detail nutrient rows from the FND-06 registry and formats
detail values with `NutrientDisplayFormatter`, including DEC-004 precision,
small-positive limits, explicit zero, and `Not available` for missing values.

The intended source rules are:

- An ingredient with a null barcode is considered manually entered.
- An ingredient with a barcode is intended to represent machine-imported
  OpenFoodFacts data.
- Calculated nutrition belongs to future recipe and diet models, not the
  current ingredient model. Those future tables may require an explicit
  nutrition-source column.
- Manual OpenFoodFacts search is planned as a future import route in addition
  to barcode lookup.

The current code does not fully enforce the barcode-based source distinction:
users can type and save a barcode without completing a successful
OpenFoodFacts lookup, and no import-success or provenance field is persisted.
The owner has confirmed that manual barcode entry is only a legacy testing
facility: in normal operation the barcode must not be manually fillable.

## Capabilities not represented

There are no routes, models, migrations, policies, components, views, or tests
for:

- Recipes or recipe organisation.
- Structured recipe ingredient lines.
- Preservation of the original text for a recipe ingredient line.
- Matching a recipe ingredient line to an ingredient or food record.
- Recipe instructions, yield, or portions.
- Recipe nutrition estimates.
- Meals, meal plans, calendars, or diet plans.
- Nutrition targets, dietary constraints, or progress tracking.

## Implemented queued-job foundation

FND-09 establishes conventions for future asynchronous work in
`QUEUED_JOB_CONVENTIONS.md`. Laravel 12.22.1 currently uses the database queue
and database cache locally, while PHPUnit uses the synchronous queue and array
cache. No product background workflow or production worker configuration has
been added.

`ProcessReferenceTask` is a harmless reference job with no route, command, or
scheduler entry. It demonstrates a stable logical operation reference,
generated or propagated ULID correlation, explicit `default` queue selection,
after-commit dispatch, three bounded attempts, 10/60-second backoff, a
60-second timeout, and a 24-hour reference idempotency window.

Duplicate protection is layered: Laravel unique-job locking suppresses normal
duplicate dispatch, overlap middleware protects concurrent execution, and an
atomic cache result keyed by the logical operation makes later reference-job
replays harmless. This is not an exactly-once guarantee. Future durable domain
effects must use an appropriate database uniqueness constraint or transactional
state at the effect boundary and must choose an idempotency lifetime for their
own replay window.

Final reference-job failures create a structured operational log with safe
identifiers, category, error code, attempt count, queue, exception class and UTC
time. Exception messages and serialized payloads are excluded. The reference
job emits no audit event because its synthetic outcome has no approved durable
audit purpose; future jobs use FND-05 only for an allowlisted domain event.
Laravel's current failed-job provider still stores the complete serialized job
and exception text, so future payloads must contain only safe identifiers and
expected exceptions must be sanitized. Failed-job pruning and production queue
operation remain deferred to DEP-04.

## Automated coverage

The `Quality gates` workflow exposes these branch-protection job/check names:
`Backend tests`, `PHP formatting`, `Static analysis`, and `Frontend build`.
Repository administrators must still configure all four as required checks on
`main`; the workflow does not prove that branch protection is currently
enabled.

The repository contains PHPUnit feature tests for the Breeze authentication and
profile flows. Ingredient feature tests cover:

- Creating an ingredient through Livewire.
- Rounding and persistence of exposed nutrition values.
- A successful mocked OpenFoodFacts lookup.
- Redirecting instead of fetching or saving when the current user already has
  the barcode.
- Rendering an owner's list, show page, and edit page.
- Quantity formatting on the list and show pages.
- OpenFoodFacts request path and identification, response mapping, timeouts,
  bounded retry, transient and permanent failures, throttling, not-found,
  malformed/schema-invalid payloads, safe logging context, and Livewire error
  messages without live provider dependencies.

Ingredient tests use a conventional model factory with automatic or explicit
ownership and named manual, barcode-imported, legacy-nutrition, and unusual-unit
states. Supported states can be composed for migration-era fixtures.

Ingredient characterization and authorization tests cover update persistence,
hard deletion, search, pagination, owner/non-owner/guest behavior, retained
controller mutations, dormant edit-modal invocation, forged Livewire
identifiers, stale ownership at save time, and ownership mass assignment.
OpenFoodFacts failure responses and representative quantity mapping are now
covered. Browser camera behavior remains uncovered.

Administrator authorization tests cover migration defaults for existing and
new users, persistence, mass-assignment and self-service input protection, the
central gate, and protected-route behavior for administrators, ordinary users,
and guests.

Audit-event feature tests cover creation, ULID uniqueness and persistence, UTC
time, user/system/external actors, identity erasure, correlation and evidence
references, allowlisted classifications and subjects, strict payload rejection,
append-only model behavior, HMAC tamper detection, authorization boundaries,
and additive migration rollback while preserving existing user/ingredient data.

## Incomplete areas and technical debt

- The repository name and project instructions describe a recipe and diet
  planner, but the current domain implementation stops at an ingredient
  catalogue.
- The Laravel default README, welcome page, application name, favicon, and
  dashboard remain in place, so the product identity and primary workflow are
  not established in the UI.
- Email verification is scaffolded and tested directly but is not enforced for
  `User` instances because the verification contract is not implemented.
- Administrator persistence and central authorization are implemented, but no
  production administrator can be assigned until the separately controlled
  FND-14 bootstrap and lifecycle work is delivered with its second-factor,
  audit, and notification dependencies.
- Ingredient writes retain Livewire and conventional controller paths. Their
  field validation and normalization now share one contract. Livewire alone
  retains the pre-existing duplicate-barcode redirect and provider-assisted
  UI behavior; this temporary workflow difference is documented in
  `STABILIZATION_FINDINGS.md`.
- Livewire is the preferred mutation path. The existing controller routes are
  to be retained until they are proven unused.
- Barcode uniqueness is not a database invariant. Concurrent requests or the
  controller path can create duplicates for one user, despite the intended
  per-user uniqueness rule.
- The current ownership model conflicts with the intended shared catalogue:
  access is owner-only and deleting the submitting user cascades deletion to
  their ingredients, whereas the future submitting-user relationship is
  intended only for logging.
- In the shared catalogue, barcode-imported records must not be editable or
  deletable by users. Their submitting-user foreign key must become nullable,
  and deleting that user must set the reference to null rather than delete the
  catalogue record.
- OpenFoodFacts transport and mapping are isolated behind a typed application
  client with bounded interactive failure behavior. It intentionally pins the
  v3.4 flat-nutrient compatibility profile; adopting the provider's latest
  v3.6 nutrition structure remains a mapper migration risk rather than an
  environment-only version change. No response cache is implemented.
- The scanner depends on a third-party CDN at runtime. Scanner behavior has no
  automated browser coverage.
- Nutrition remains JSON rather than a relational/versioned model. The shared
  write contract now restricts normalized buckets to registered nutrients and
  exact non-negative decimals, but the retained raw OpenFoodFacts bucket is
  intentionally provider-shaped and unversioned.
- Imported and manually edited nutrition values are blended in the same JSON
  record with no provenance. The UI cannot apply the accuracy distinction
  described in `AGENTS.md`.
- The current JSON nutrition model retains provider-shaped observations in
  `raw`, but normalized values still lack per-value origin, derivation,
  normalization-policy version, and conflict metadata. NUT-05 owns that broader
  provenance redesign.
- Measurement display formatting remains duplicated between the Livewire list
  and show components. Unit lists, validation, normalization, and inference
  now use FND-06.
- `Ingredient` has a `user()` relation, but `User` has no reciprocal
  `ingredients()` relation.
- The static-analysis baseline retains one optional-email-verification mismatch,
  four redundant ingredient-form expressions, and four assertions whose
  outcomes PHPStan knows in advance. These
  findings should be removed as the affected existing code is revised.
- The active local baseline is the MySQL-based Sail stack, but `.env.example`
  defaults to SQLite and the supported setup is not explained in the README.

## Confirmed owner direction

- Email verification is optional at present.
- Livewire is preferred for ingredient writes. Existing controller routes
  should remain until proven unused.
- Barcode uniqueness applies per user in the current implementation.
- The intended future model is a shared food/product catalogue. The submitting
  user should be retained for logging rather than ownership.
- Barcode-imported catalogue records must not be editable or deletable by
  users. Deleting the submitting user must leave the record in place with a
  null submitting-user reference.
- Null-barcode ingredients are intended to be manual entries; ingredients with
  barcodes are intended to be OpenFoodFacts machine imports. The barcode field
  must not be manually fillable during normal operation.
- The key nutrition set is calories in both kcal and kJ, fat, sugars, salt, and
  protein. When either energy unit is entered, the other should be derived
  automatically using `1 kcal = 4.184 kJ`; kcal is the preferred display value.
- Calculated nutrition is reserved for future recipe and diet models, which may
  carry an explicit nutrition-source column.
- The local development baseline is MySQL in the WSL/Docker Sail stack.

## Questions requiring owner input

- How should manually entered products without barcodes be de-duplicated?
- In the shared catalogue, who may correct or remove a barcode-imported record
  when OpenFoodFacts data is wrong or obsolete?
- What edit and delete permissions should apply to manually entered,
  null-barcode catalogue records?
