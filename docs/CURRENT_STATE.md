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
- Barcode provenance classified as manual, verified machine import, or legacy
  unknown.
- A nullable stable barcode source identifier and server import timestamp.
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
`IngredientWriteNormalizer`. The contract validates the persisted ordinary
field allowlist, FND-06 units, an all-or-none serving quantity/unit pair, a
bounded nutrition shape, and nullable values. Normalization trims nullable
strings, stores empty optionals as null, preserves numeric zero, canonicalizes
standard unit aliases, retains safe custom/ambiguous unit text, and quantizes
nutrient decimals once to DEC-003's scale without display rounding. Ownership
and barcode machine metadata are not part of the ordinary write contract.

STB-08 makes barcode, stable source, server import time, and provenance
machine-owned fields. Controller and ordinary Livewire saves ignore crafted
values and model mass assignment excludes them. A successful typed STB-07
result is retained briefly in server-side session state bound to the user and
ingredient; a locked random Livewire token only references it. The dedicated
import action revalidates requested/returned barcode consistency and assigns
`openfoodfacts`, a UTC server timestamp, and verified machine provenance.

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
A successful lookup is usable only when the mapped provider code exactly
matches the trimmed lookup input. It may populate the form, but barcode
provenance is not persisted until the subsequent trusted save consumes the
server-side success result. A successful lookup can populate:

- Product name.
- Barcode.
- Keywords and OpenFoodFacts category tags.
- Product quantity and a parsed unit where the text can be interpreted.
- Serving quantity and a unit inferred from serving text.
- Nutrition data.
- A remote product image URL.

The browser form also contains a camera barcode scanner. It:

- Imports the exactly pinned `@zxing/library` 0.20.0 package through the Vite
  application bundle and makes no runtime scanner CDN request.
- Decodes camera frames locally in the browser without sending them to ZXing or
  another scanner service.
- Enumerates cameras without prompting, prefers a rear-facing camera, and
  requests camera permission only after the user selects **Start scan**.
- Supports camera switching by releasing the active stream before starting the
  selected replacement.
- Requires HTTPS or localhost, as required by browser camera APIs.
- Validates and accepts one decoder result per scan, then stops before sending
  the barcode to the Livewire/OpenFoodFacts lookup flow.

`resources/js/barcode-scanner-adapter.js` owns initialization, start, restart,
switch, and idempotent stop/destroy behavior. Stop resets ZXing, stops remaining
video tracks, clears the video source, and runs on a successful scan, manual
stop, page hiding, Livewire navigation, or Alpine component destruction. The
Livewire event bridge also removes its listeners on destruction and suppresses
scan callbacks while an equivalent lookup is pending.

Permission denial, missing or unsupported camera APIs, no cameras, reader
initialization failure, an unavailable selected camera, and invalid scan data
produce small safe states with a manual-entry message rather than raw browser
errors. Manual product creation is available without initializing or starting
the camera. Local camera testing uses HTTPS or `localhost`; deterministic
coverage without a physical camera runs with
`./vendor/bin/sail npm run test:scanner`.

Before fetching through the Livewire form, the application checks for another
ingredient with the same non-empty barcode owned by the current user. If one is
found, it redirects to the existing record. This is an application check only:
the database has a non-unique barcode index, and the conventional controller
paths have no provider lookup action.

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
- Record per-nutrient origin, calculation, provider revision, or later manual
  divergence; STB-08 provenance applies to the barcode import as a whole.
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

- A manual ingredient has no barcode, source, or import timestamp and carries
  `manual` barcode provenance.
- A new barcode is persisted only after a successful trusted OpenFoodFacts
  lookup and carries `machine_imported` provenance, source `openfoodfacts`, and
  a server-generated UTC import timestamp.
- Every pre-STB-08 non-empty barcode is preserved with `legacy_unknown`
  provenance and null source/time; barcode presence alone never verifies it.
- Calculated nutrition belongs to future recipe and diet models, not the
  current ingredient model. Those future tables may require an explicit
  nutrition-source column.
- Manual OpenFoodFacts search is planned as a future import route in addition
  to barcode lookup.

The visible barcode input is lookup input, not an ordinary editable persisted
field. Failed, expired, malformed, rate-limited, unavailable, not-found, and
permanent-failure lookups cannot create verified provenance. A failed
re-import leaves existing verified metadata intact.

## Implemented recipe-draft identity

REC-01 adds a minimal `recipes` table and model with an integer identifier,
required creator `user_id`, title, optional positive `DECIMAL(10,2)` serving
count, string-backed lifecycle and intended-visibility enums, and timestamps.
New recipes default to lifecycle `draft` and intended visibility `public`.
Lifecycle and intended visibility are separate: every draft remains owner-only
even when its eventual visibility preference is public.

Authenticated users create and update drafts through one Livewire form hosted
by conventional authenticated create/edit pages. Ownership and draft lifecycle
are assigned server-side and excluded from mass assignment and Livewire state.
A centralized recipe policy permits create for authenticated users and permits
view/update only when `recipes.user_id` matches the current user. Guests follow
the existing login redirect, while authenticated non-owners receive 403 for
direct view/edit URLs and Livewire mutation. No public recipe route, publish or
share record, nutrition, versioning, or planning
integration is introduced.

## Implemented ordered recipe ingredient lines

REC-02 adds recipe-owned `RecipeIngredientLine` records. Every line has a
stable integer identifier, required `original_text`, a contiguous zero-based
position, optional `DECIMAL(38,18)` quantity, mutually exclusive standard or
custom unit storage, optional generic wording and notes, and timestamps.
Deleting a recipe cascades to its lines. The existing user-owned `Ingredient`
food/product record remains conceptually and relationally separate.

The creator-authored `original_text` is authoritative. It is stored from the
earliest reliable Livewire value and is never trimmed, normalized, parsed,
reconstructed, or changed when supplementary fields change. A narrow Laravel
trim-middleware exception covers the Livewire `originalText` update path so
leading and trailing whitespace reach the component. Structured quantities
use the shared exact decimal boundary. FND-06 aliases store a standard unit
identifier; safe custom-unit text is preserved separately. Missing or failed
structure never invalidates a non-blank line, including `salt to taste`.

The authenticated recipe edit page supplies add, edit, remove, and keyboard-
accessible up/down ordering controls. Appends are last, deletion compacts
positions, and full-set reorder persists atomically under a recipe lock.
Submitted duplicate, missing, or foreign identifiers are rejected. Every
mutation re-resolves and authorizes the owning recipe; `recipe_id` and
`position` are excluded from line mass assignment. The recipe show page always
uses the explicitly ordered relationship. Empty drafts remain valid; the
one-line minimum belongs to future finalization.

## Implemented ordered recipe instructions

REC-03 adds recipe-owned instruction sections and steps. Every step has a
stable integer identifier, required creator-authored text, a contiguous
zero-based recipe-global position, an optional section reference, and
timestamps. Sections have their own stable identifier, recipe relationship,
name, contiguous zero-based order, and timestamps. Recipes with no sections,
unsectioned steps, and mixed sectioned/unsectioned steps are all valid.

Instruction text is stored exactly as submitted. A narrow trim-middleware
exception lets leading and trailing whitespace reach the Livewire component;
validation uses a trimmed copy only to reject empty and whitespace-only text.
Persistence does not trim, collapse whitespace, rewrite punctuation or
capitalization, transform markup, or normalize Unicode. No legacy or imported
instruction store exists, so REC-03 performs no wording migration or import
backfill.

Steps use one global recipe order, with section membership as optional
organizational metadata. Appends are last, deletion compacts positions, and
complete-set reorder persists atomically under a recipe lock without changing
text or section membership. Sections use the same explicit ordering pattern.
Deleting a section makes its steps unsectioned without deleting or rewriting
them. Duplicate section names are allowed because no product rule requires
recipe-local uniqueness.

The existing recipe edit page now provides section and step add, edit, remove,
assignment, and keyboard-accessible up/down controls. Every mutation re-resolves
and authorizes the owning recipe; foreign nested identifiers and incomplete or
duplicate reorder sets are rejected. Recipe, section, position, and stable IDs
are not ordinary submitted attributes. The recipe show page renders steps in
their explicit global order with any section name as a label.

## Implemented draft recipe editor

REC-04 replaces the three independently saved controls on the recipe edit page
with one `Recipes\Form` editing workflow. Its explicit Livewire state contains
only recipe metadata and allowlisted ingredient, section, and step values;
ownership, lifecycle, parent assignment, positions, timestamps, and provenance
remain server-controlled. The REC-01 create path remains a metadata-only first
step and redirects to the draft after creation.

An existing draft save validates the complete in-memory graph, then re-resolves,
locks, and re-authorizes the recipe inside one database transaction. Persisted
child identifiers must belong to that locked recipe, duplicate identifiers are
rejected, and optional step section references use validated editor keys. The
save reconciles creates, updates, and deletions before rebuilding ingredient,
section, and global step positions as contiguous zero-based sequences.

A locked fingerprint covers the editable recipe fields and complete ordered
child graph. It is compared against locked database state before the first
write; recipe or child changes made after mount reject the whole save and retain
local input. Metadata edits, nested edits, additions, removals, and moves mark
the editor unsaved. Errors keep that indication active; a successful save
refreshes the fingerprint and clears it. A page-local navigation warning is
also active while the editor has unsaved changes.

## Implemented recipe finalization

REC-05 adds a one-time `draft` to `finalized` lifecycle transition without
using visibility as lifecycle state. Finalized recipes remain either `public`
or `private`; public is the server-side default and an explicitly selected
private value is preserved. Drafts remain owner-only even when their intended
visibility is public.

Finalization uses the visible validated REC-04 aggregate rather than silently
using an older save. One outer transaction locks and re-authorizes the recipe,
checks the aggregate fingerprint, saves the submitted metadata and ordered
children, validates a nonblank title, positive servings, at least one
ingredient line and at least one nonblank instruction step, creates immutable
version 1, updates lifecycle/current-version state, and records the FND-05
`recipe.finalized` event. Any failure rolls back the editor save, version,
lifecycle, visibility and event together.

`recipe_versions` gives each finalized version a ULID and recipe-local version
number. Its immutable JSON snapshot contains title, servings, visibility,
ordered ingredient-line content, ordered section labels, globally ordered
steps and section grouping, plus the server finalization timestamp.
`recipes.current_recipe_version_id` identifies the active stable version.
Repeated initial finalization returns that same version. REC-07 now uses the
same snapshot representation for replacement versions.

`Recipe::isFinalized()`, `scopeFinalized()` and
`canBeUsedInPlansFor()` are the reusable plan-eligibility boundary. Drafts
always fail. Finalized public recipes qualify; finalized private recipes
qualify only for their owner under the currently represented rules. REC-05
does not add meal-plan infrastructure.

## Implemented recipe visibility and public reads

REC-06 makes the single conventional `GET /recipes/{recipe}` show route
available without authentication. `Recipe::isPubliclyViewable()`,
`scopePubliclyViewable()`, and `scopeVisibleTo()` centralize eligibility:
only a finalized recipe with a current stable version and current `public`
visibility is readable by guests or authenticated non-owners. The creator may
also resolve their own draft and private recipes through that route.
Unauthorized draft/private identifiers return a non-disclosing 404 before any
recipe content reaches the view.

Finalized show pages use an explicit `PublicRecipe` projection built only from
the immutable `currentVersion` snapshot. The projection allowlists recipe ID,
title, servings, current visibility, version ID/number/finalization time,
ordered ingredient text and resizing structure, section labels, and ordered
instruction text. Ingredient notes are exposed only as recipe content when a
complete structured line is rendered. The projection does not load or serialize
the owner, email, user ID, administrator/security state, audit events, mutable
recipe children, or the wider Eloquent relationship graph. Draft owners
continue to see the live draft aggregate.

Only the owner may change a finalized recipe between public and private. The
transactional mutation re-resolves, locks, and authorizes the recipe, updates
only current visibility, and records a minimized `recipe.visibility_changed`
audit event containing the version reference and visibility transition.
Making a recipe private immediately removes public-read eligibility without
changing finalized lifecycle, its current-version reference, immutable version
records, ingredient lines, or instructions. Making it public again restores
read eligibility for the same stable version. Finalized content editing is
available only to the creator through the REC-07 private revision boundary;
public readability never grants edit permission.

## Implemented finalized-recipe draft revisions

REC-07 keeps `Recipe` as the durable recipe identity and `RecipeVersion` as an
application-immutable finalized snapshot. The additive `recipe_draft_revisions`
table gives one private working revision a ULID, its recipe ID, and an explicit
base-version ULID. A unique recipe constraint and recipe-row transaction lock
permit at most one active revision. The foreign key requires the base version
to exist, while the start/publish services verify that it belongs to the same
locked recipe and is still current.

Opening edit on a finalized recipe creates or resumes that revision. Creation
copies the current snapshot once into the existing REC-04 mutable aggregate:
title, servings, exact ingredient text and structured fields, ingredient order,
exact instruction text, global step order, and section grouping. Reopening does
not recopy or overwrite draft changes. The mutable aggregate is revision
working state while the active-revision row exists; public and finalized owner
reads continue to use only `current_recipe_version_id` and its immutable JSON
snapshot. Current visibility remains on the durable recipe, is not editable in
the revision form, and therefore cannot change merely because a revision is
created, saved, published, or abandoned.

Revision publication locks the recipe and revision, reauthorizes the creator,
checks the REC-04 fingerprint and REC-05 content preconditions, and requires the
explicit base ULID to equal the current version ULID. It assigns
`current.version_number + 1` under that lock, with the existing unique
recipe/version-number constraint as a second boundary. The new snapshot,
current-version switch, revision removal, and minimized
`recipe.revision_published` audit event commit atomically. Failure preserves the
old current version and active draft. A replay after success returns the current
version without another version or success event; an older-base revision is
rejected rather than merged.

Abandonment reauthorizes the creator, restores the mutable aggregate from the
unchanged current snapshot, deletes the active revision and draft-only child
state, and records `recipe.revision_abandoned`. It does not change visibility,
current or historical versions, or reader output. Creation records
`recipe.revision_created`. All three audit payloads contain only recipe/revision
and base/new version identifiers and numbers; recipe content is prohibited.
Finalized-version update and direct-delete model operations remain rejected.
Database-level immutability of snapshot JSON is not claimed; immutability is an
application boundary backed by regression tests.

## Implemented display-only recipe resizing

REC-08 adds a serving control to the existing recipe read page. Positive
serving requests with up to the recipe model's existing two decimal places
derive presentation state only. The saved recipe servings remain the
authoritative original amount, and every displayed quantity is calculated
directly from the original structured quantity and original servings. Changing
4 to 8 and then 6 therefore calculates 6 from the saved 4-serving source rather
than the prior 8-serving display.

`RecipeQuantityScaler` uses FND-06 Brick Math decimals, 24 division guard
digits, and half-up rounding without PHP floating point. The presentation
formatter applies the existing three-decimal measurement convention only at
the final boundary and preserves positive sub-resolution quantities as
`<0.001`. Standard mass, volume, and count identifiers retain their registered
symbols; custom-unit text remains unchanged and is never converted.

Complete structured lines render the derived quantity beside their unchanged
generic wording and notes. Null-quantity or incomplete structured lines render
their exact `original_text`; REC-08 does not parse or infer quantities.
Invalid requests display the original quantities with an error. Missing, zero,
negative, or invalid saved servings disable resizing rather than assuming a
denominator. Guests and non-owners may resize public current versions only. An
owner-only preview uses the saved REC-07 draft aggregate while ordinary
finalized reads continue to use the immutable current version. No resize
request writes a recipe, ingredient line, unit, original text, or version.

## Implemented public recipe discovery

REC-09 adds the read-only `GET /recipes` route for guests and authenticated
users. The conventional controller and Blade page keep title search and
pagination in ordinary query parameters without adding client-side discovery
state. Empty input browses normally; submitted title input is trimmed,
validated as a string no longer than 100 characters, and matched partially
with deterministic case-folding for the configured database. The database
expression uses a bound search pattern rather than interpolating submitted
text.

`PublicRecipeDiscovery` starts from the REC-06
`Recipe::scopePubliclyViewable()` boundary. It searches through only the
`currentVersion` relationship, eager-loads only that relationship, and never
joins historical versions as candidates. The query therefore selects drafts,
private recipes, and withdrawn versions zero times rather than hiding them
after retrieval. Visibility/lifecycle/tag-shaped query parameters are ignored
and cannot widen the public scope.

The length-aware paginator returns 12 recipes per page, preserves the normalized
title query, and orders by current publication time descending followed by
durable recipe ID descending. One durable recipe produces at most one result,
and the secondary ID makes equal-time page boundaries stable. Totals count only
matching current public finalized recipes.

Each row becomes an explicit `PublicRecipeSummary` containing only durable
recipe ID, current snapshot title and servings, and current-version finalization
time. The view links that ID to the independently authorized REC-06 public show
route. It does not load or serialize the owner, user/account/security data,
version or revision identifiers, mutable children, history, or audit/internal
metadata. Browse and search have distinct generic empty states that disclose no
hidden-match counts.

REC-07 active draft revisions leave discovery on the current immutable
snapshot. Publishing atomically changes the current version and therefore the
discoverable title; abandoning restores working state without changing
discovery. Making a recipe private removes it immediately while retaining its
version history outside discovery.

Public tag records and assignments do not yet exist: REC-13 still owns the
creator-authored versus managed public-tag model and REC-12 owns private
organizational tags. REC-09 therefore does not expose or accept a tag filter
until REC-13 provides that boundary. No index migration was added at the current
data scale. If discovery volume grows, likely candidates are a composite public
eligibility/order index and an indexed normalized current-title projection;
those should be justified with production query plans rather than a premature
search subsystem.

## Implemented private recipe bookmarks

REC-10 adds an authenticated, owner-only recipe bookmark list and a bookmark
toggle to the public recipe detail page. A bookmark stores only its owning user,
the durable integer `recipes.id` reference, and timestamps. It does not copy a
title, description, ingredient, instruction, nutrition value, version number,
version identifier, or creator profile field. The product specification does
not prohibit saving one's own public recipe, so the same public-eligibility rule
applies to self-bookmarks.

Creation resolves the submitted durable recipe identifier through REC-06's
central `Recipe::scopePubliclyViewable()` boundary. Drafts, private finalized
recipes, missing current versions, historical version identifiers, and deleted
recipes therefore cannot be newly bookmarked. Ownership comes only from the
authenticated user. A database unique constraint on `(user_id, recipe_id)` and
an insert-first unique-conflict recovery path make repeated and concurrent adds
idempotent.

The list is ordered by bookmark creation time and bookmark ID descending and
paginated 12 per page. It first retrieves only the current user's bookmarks,
then batch-resolves their opaque recipe identifiers through
`scopePubliclyViewable()` with the current immutable version. Available rows
become the same privacy-safe `PublicRecipeSummary` used by discovery. The
listing does not traverse an unrestricted bookmark-to-recipe relationship, so
active private draft revisions never appear and publishing a replacement
version changes the displayed title/content without updating the bookmark row.

If a source becomes private or is hard-deleted, the bookmark remains and
renders only `Recipe unavailable`, the generic statement
`This recipe is no longer publicly available.`, and its bookmark date. No
source or creator content is retained for that state. Making the same durable
recipe public again restores its live projection automatically. The recipe
reference intentionally has no foreign key because current recipe and account
deletion are hard deletes; this prevents source deletion from cascading into
another user's private bookmark while the owner foreign key still cascades
when the bookmarking user's private data is deleted. Tombstones remain
owner-removable.

## Capabilities not represented

There are no routes, models, migrations, policies, components, views, or tests
for:

- Recipe organisation.
- Matching a recipe ingredient line to an ingredient or food record.
- Recipe yield or portions.
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
- Redirecting instead of fetching when the current user already has the
  barcode.
- Rendering an owner's list, show page, and edit page.
- Quantity formatting on the list and show pages.
- OpenFoodFacts request path and identification, response mapping, timeouts,
  bounded retry, transient and permanent failures, throttling, not-found,
  malformed/schema-invalid payloads, safe logging context, and Livewire error
  messages without live provider dependencies.
- Manual controller/Livewire provenance forgery, guarded mass assignment,
  trusted scan/import persistence, server timestamp/source derivation,
  requested/returned barcode mismatch, failure outcomes, stale pending import
  invalidation, failed re-import preservation, and legacy compatibility.

Ingredient tests use a conventional model factory with automatic or explicit
ownership and named manual, verified barcode-imported, legacy-barcode,
legacy-nutrition, and unusual-unit states. Supported states can be composed for
migration-era fixtures.

Ingredient characterization and authorization tests cover update persistence,
hard deletion, search, pagination, owner/non-owner/guest behavior, retained
controller mutations, dormant edit-modal invocation, forged Livewire
identifiers, stale ownership at save time, and ownership mass assignment.
OpenFoodFacts failure responses and representative quantity mapping are now
covered. A focused Node suite covers the scanner package/lock contract,
permission and availability states, local result validation, duplicate
callbacks, start/restart/stop/destroy, track release, navigation cleanup, and
camera-switch recovery with deterministic browser and decoder doubles. A
physical-camera browser/end-to-end suite remains unavailable.

Recipe discovery feature tests cover guest/authenticated parity, authoritative
public-scope exclusion, privacy-safe summary serialization and HTML, exact and
partial current-title search, deterministic case behavior, empty search and
empty states, parameter validation, crafted-scope parameters, stable
pagination/counts, query persistence, historical/current selection, active
draft isolation, revision publication and abandonment, immediate unpublish,
retained history, and direct-route authorization.

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

- The repository now implements recipe drafting, finalization, public reads,
  revisions, resizing, and title-based public discovery, but meal planning and
  most nutrition workflows remain unimplemented. Public-tag discovery awaits
  the REC-13 tag model.
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
  ordinary field validation and normalization now share one contract. Livewire
  alone provides the provider lookup/scanner UI and its pre-fetch
  duplicate-barcode redirect; ordinary saves agree that barcode provenance is
  machine-controlled.
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
- The scanner is locally bundled and deterministically tested, but the
  repository still has no physical-camera browser/end-to-end suite. Secure
  context, permission, and hardware behavior therefore retain a manual check.
- Nutrition remains JSON rather than a relational/versioned model. The shared
  write contract now restricts normalized buckets to registered nutrients and
  exact non-negative decimals, but the retained raw OpenFoodFacts bucket is
  intentionally provider-shaped and unversioned.
- Imported and manually edited nutrition values remain blended in the same JSON
  record without per-value provenance. STB-08 identifies a verified barcode
  import but does not provide the versioned field-level provenance needed to
  apply the full accuracy distinction described in `AGENTS.md`.
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
