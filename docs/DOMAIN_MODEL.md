# Domain model

This document describes concepts represented by the current schema and
application code. Its ingredient baseline is commit `1adc7bbf0b74c8bd1687fda81ed26e301f2eb9e4`.
It does not infer recipe or planning concepts that do not yet exist.

## Represented concepts

### User

`App\Models\User` is the authenticated account and the ownership boundary for
food data.

Persisted attributes are:

- Integer identifier.
- Name.
- Unique email address.
- Nullable email-verification timestamp.
- Hashed password and optional remember token.
- Non-null administrator status, defaulting to false.
- Creation and update timestamps.

A user can register, authenticate, reset and change their password, edit their
profile, and delete their account. A user's theme preference is browser-local
state and is not part of the persisted user concept.

Administrator status supplies the centrally defined `access-admin` ability.
The status is excluded from normal mass assignment, registration, and profile
component state. Production assignment and revocation are accepted only inside
the FND-14 lifecycle mutation scope; authorization re-reads the persisted role
so a revoked account cannot rely on stale session model state. The explicit
administrator factory state is test-only and is rejected when the application
environment is production. Administrator status does not override resource
policies or grant access to private user content unless a later resource rule
expressly uses the central ability.

The database relationship from `ingredients.user_id` means one user can own
many ingredients. The code exposes only the inverse
`Ingredient::user()` relationship; `User::ingredients()` is not defined.

### Account identity

The `User` row is private authentication and ownership state. Its name and
email may be edited through the authenticated private profile, but neither is
public merely because the account owns a public recipe or enables a public
profile. Email, internal user ID, credentials, administrator status,
second-factor/recovery state, sessions, notifications, and audit relationships
are never public attribution fields. Public code does not serialize `User`.

### Public attribution

Public attribution is a deliberately entered display value, separate from the
account name and email. One selected value supports the product-approved use of
a username/display name or real name without storing a privacy-irrelevant
classification of that string. It is optional until configured, at most 80
characters, trimmed, non-blank, non-HTML, and cannot be email-shaped.

At first finalization and every later revision publication, the selected value
is copied into nullable `recipe_versions.public_attribution_name`. The version
model's immutability makes attribution historical publication state: changing
private account identity or current public-profile settings does not rewrite an
already finalized version. Existing versions created before REC-14 remain null
and do not fall back to account data. Public recipe attribution exposes only
the snapshotted label and, while enabled, the related opaque profile ULID.

### Public profile

`App\Models\PublicProfile` is an optional one-to-one public projection owned by
one `User`, not a public form of the User model. It has:

- An application-generated stable ULID used by the public route.
- A unique private `user_id` ownership foreign key that cascades on current
  account deletion.
- The current selected public attribution name.
- Independent booleans for profile enablement, non-remix public-recipe
  listing, and public-remix listing, all defaulting false.
- Server timestamps.

Only the authenticated owner mutates the record, and client input contains no
target account/profile identifier. An enabled profile projects only its ULID,
attribution name, and enabled lists. Both lists start from
`Recipe::scopePubliclyViewable()` and use `PublicRecipeSummary`; recipe and
remix membership are separated by immutable `RecipeRemixLineage`. Disabled
profiles are 404 without altering any recipe. No email, bio, avatar, website,
account/security state, draft/private/historical recipe, active revision,
bookmark, collection, private tag/count, audit record, plan, diary, or target
is part of this model's public projection.

### Audit actor identity

`App\Models\AuditActorIdentity` is a separately erasable mapping used only by
the ordinary audit store. Its ULID is random and non-derived. A mapping is one
of user, external operator, or deployment and contains either a nullable
`user_id` or a bounded opaque `external_reference`; it never stores a name,
email address, username, credential, authentication material, command history,
or environment value. User deletion nulls `user_id`. The dedicated eraser can
remove the row independently while retained events keep only the now-unmapped
random reference.

### Audit event

`App\Models\AuditEvent` is an append-only ordinary application event created
through `AuditEventRecorder`. Its persisted fields are:

- ULID primary identifier.
- Allowlisted action, purpose, retention class, actor type, and subject type.
- Optional actor and user-subject mapping ULIDs.
- Optional bounded non-user subject identifier.
- Server-authoritative UTC occurrence timestamp.
- Optional bounded non-secret correlation identifier.
- Optional opaque reference to separately controlled protected evidence.
- Payload schema version and a small action-specific JSON payload.
- HMAC-SHA-256 integrity hash over the canonical event fields.

Purpose and retention are derived from the action rather than supplied by a
caller. The initial action set covers administrator bootstrap outcomes,
catalogue approval, recipe nutrition override, plan snapshot recording, and
account anonymization. Subjects use stable allowlisted categories without a
hard foreign key to removable domain records. User subjects use the erasable
identity mapping instead of copying a raw user ID into the event.

Model update/delete operations are rejected. Raw SQL, database privileges and
future privileged retention deletion remain outside that application-layer
boundary. No production audit browser or creation endpoint exists. Policy
denies guests and ordinary users, permits only individual moderation/catalogue
records to administrators, and does not treat administrator status as security
or privileged-lifecycle audit authorization.

### Administrator lifecycle state

`AdministratorLifecycleState` is a migration-owned singleton and the global
transaction lock for administrator-count invariants. Its nullable bootstrap
completion timestamp, bootstrap audit-event ULID, and correlation ID form the
persistent one-time marker. Production model mutation outside the bootstrap
marker service is rejected. Revocation, deletion, bootstrap, acceptance, and
break-glass take this lock before mutable eligibility/count checks.

`AdministratorPromotionRequest` is a target-bound ULID state machine. It stores
the target and nullable initiating administrator, pending or terminal status,
non-secret correlation ID, exact expiry, and the applicable terminal timestamp.
Pending requests grant no privilege; accepted, declined, cancelled, and expired
states are terminal. The target reference is the authenticated workflow
reference rather than a bearer elevation token.

Initial bootstrap and break-glass have no HTTP representation. Routine
promotion and revocation use a minimal authenticated lifecycle interface backed
by server-side services. The bootstrap and break-glass commands use distinct
configuration and audit event categories, and break-glass cannot write or clear
the bootstrap marker.

### Administrator second-factor state

A user may own one pending enrollment and one confirmed second factor. Pending
and confirmed records have separate ULID identities. Both keep only Laravel-
encrypted TOTP seeds and hide them from serialization. A pending enrollment
records its purpose, expiry, proved timestep and recovery-generation time. A
confirmed factor records confirmation, recovery acknowledgement, lock state and
the last atomically consumed timestep.

Recovery codes are child rows attached to either the pending enrollment or
confirmed factor. Each stores only a one-way hash and optional use timestamp.
Verification failures store account, optional factor, bounded operation, keyed
source fingerprint and occurrence time; no raw source IP is retained. Account
state separately records consecutive failures, next permitted attempt and lock
expiry.

### Administrator security-notification state

A security notification intent is one recipient-specific, correlated logical
email. It stores event and recipient categories, opaque destination version,
stable idempotency key, safe environment/instance references, delivery state,
and optional opaque provider acceptance or failure evidence. It stores neither
the destination nor message body. Provider acceptance is not delivery or read

A recovery authorization is target-bound and records its assisted-administrator
or deployment-CLI method, initiator or opaque operator reference, correlation,
expiry, and terminal consumed/cancelled timestamps. A CLI authorization stores
only a one-way hash. A pending recovery enrollment may reference one
authorization so its single successful use is consumed atomically with factor
replacement; secrets are hidden from model serialization.

evidence. Singleton health state records only readiness booleans, safe failure
code, and provider, capacity, worker and monitor timestamps.

### Recipe draft

`App\Models\Recipe` is the persisted identity for an authenticated user's
private recipe working state.

Identity and ownership:

- Auto-incrementing integer identifier.
- Required `user_id` foreign key; `Recipe::owner()` belongs to one user and
  `User::recipes()` exposes that user's recipes.
- User deletion cascades to owned recipe drafts.
- Creation and update timestamps.

Draft metadata:

- Required trimmed title, bounded to 255 characters.
- Optional serving count stored as decimal `(10, 2)` and validated as greater
  than zero when supplied; finalization requires it to be present.
- Lifecycle stored as the string-backed `RecipeLifecycle` enum with `draft`
  and `finalized`. It is assigned and transitioned only server-side.
- Intended visibility stored separately as the string-backed
  `RecipeVisibility` enum with `public` and `private`; the default is `public`
  and an explicit private choice survives finalization.
- Nullable current-version ULID and finalization timestamp, populated together
  only by successful finalization.

The recipe policy grants view to the owner and to every viewer when the recipe
is finalized, has a current stable version, and is currently public. Update
remains owner-only: initial drafts are editable, and finalized recipes are
editable only while their explicit private revision exists. Draft lifecycle
overrides intended
visibility, so neither preference grants public or cross-user access.
Unauthorized public-route lookups for drafts and private recipes resolve as
404. Finalized edit creates or resumes the creator's single private draft
revision; the finalized snapshot remains the read boundary.
Draft editing is one atomic aggregate mutation. The editable aggregate consists
of the recipe's title, servings and intended visibility plus its ordered
ingredient lines, optional instruction sections, and globally ordered steps.
The recipe creator and lifecycle are not editor inputs. All persisted nested
identifiers are interpreted only after the authoritative recipe is locked and
authorized, and they may identify records belonging to that recipe only.

The editor's array order is the ordering intent; stored positions are rebuilt as
contiguous zero-based sequences during the transaction. A baseline fingerprint
of recipe metadata and the complete nested graph prevents an editor opened
before another recipe or child mutation from overwriting the newer state. A
conflict changes nothing in the database and leaves the local aggregate dirty.

First finalization is a single atomic aggregate mutation. It persists the
validated visible editor state, rechecks the locked fingerprint and owner,
validates authoritative metadata and child records, creates version 1, assigns
the current-version reference and finalization time, changes lifecycle, and
records the allowlisted audit event in the same transaction. A failure leaves
the recipe a draft with no version or success event. A finalized recipe cannot
be reset to draft. REC-04 aggregate mutation is allowed for finalized content
only when REC-07 has created its active revision;
visibility remains outside that revision mutation.

Plan eligibility is a model/application rule rather than a UI convention.
`isFinalized()` requires both finalized lifecycle and a current stable version;
`scopeFinalized()` applies the same database boundary. A finalized public
recipe is eligible for planning. A finalized private recipe is eligible only
for its owner under the currently represented rules. Every draft is
ineligible, including one whose intended visibility is public.

Public-read eligibility is centralized by `isPubliclyViewable()` and the
corresponding query scopes. Finalized reads render the immutable current-version
snapshot through an explicit privacy-safe projection; they do not render the
mutable recipe children or serialize the owner relationship. Current visibility
controls access independently of the version's recorded visibility at
finalization.

Public discovery starts from the same publicly-viewable scope and selects at
most one result per durable recipe. Its title, servings, and finalization time
come only from the immutable version referenced by
`current_recipe_version_id`; historical versions and mutable active-revision
The minimized summary projection excludes owner and version identifiers and
includes only accepted free-form tag wording plus managed term category/name.
Private tags and pending/rejected suggestions are neither searched nor
rendered.

The owner may change only the current recipe visibility between public and
private. That mutation preserves finalized lifecycle, the current-version
reference, every immutable version, and all current child records. It emits a
minimized product-history audit event with the version reference and transition.

### Finalized recipe version

`App\Models\RecipeVersion` is the immutable stable identity produced by
finalization. It has:

- An application-generated ULID.
- Its owning recipe and a recipe-local positive version number, unique as a
  pair.
- Visibility at finalization and a server finalization timestamp.
- An immutable snapshot containing title, servings, visibility, ingredient
  lines with their complete stored supplementary fields and order, instruction
  sections with order, and globally ordered steps with stable snapshot-local
  section keys.
- A nullable selected public-attribution name copied at publication rather
  than resolved from mutable account data.

The recipe points to its current version while also exposing an ordered
one-to-many version relationship. REC-05 creates version 1; REC-07 creates
monotonic replacement versions while retaining earlier rows. No history browser
is exposed. Model update and direct delete are
rejected. Deleting the owning recipe still removes its versions through the
database relationship; broader retained-version rules remain later work.

### Recipe draft revision

`App\Models\RecipeDraftRevision` is the private editable-revision identity. It
has an application-generated ULID, a unique durable `recipe_id`, and an
explicit `base_recipe_version_id`. Its content is the recipe-owned mutable
aggregate used by the existing editor rather than a second set of child tables.
That aggregate is treated as revision-only working state whenever the revision
row exists; ordinary finalized reads never consume it. The creator may
explicitly preview its already-saved state through the owner-only REC-08 read
mode without changing the current finalized version.

Creation locks the recipe, authorizes its creator, and hydrates the aggregate
from the current immutable snapshot exactly once. Repeat edit returns the same
revision. Its fingerprint includes the current-version and active-revision
references as well as metadata and all ordered children. Publication rejects a
base that is no longer current, creates the next recipe-local version under the
same lock and unique-number constraint, switches the current pointer, and
removes the revision atomically. Abandon restores the current snapshot and
removes draft-only rows. Both operations preserve durable recipe visibility.
Conflict merging, branching, rollback, diffs, and historical browsing are not
represented.

### Bookmark

`App\Models\Bookmark` is a private pointer owned by exactly one user. Its
auto-incrementing identifier is stable within the application, and its
`recipe_id` stores the durable `recipes.id`, never a `RecipeVersion` or active
draft-revision identifier. The row also has creation and update timestamps and
contains no recipe or creator metadata.

The database enforces one row per `(user_id, recipe_id)`. Application creation
uses the authenticated user, REC-06 public eligibility, and insert-first
unique-conflict recovery. The owner foreign key cascades when that user's
private data is removed. The durable recipe reference is deliberately an
indexed unsigned integer without a foreign-key constraint: current recipes are
hard-deletable and a recipe foreign-key cascade would violate the required
tombstone lifecycle.

Bookmark reads begin with an owner-scoped bookmark query. Their source IDs are
then resolved in a separate batch through
`Recipe::scopePubliclyViewable()` and only the current-version
`PublicRecipeSummary` is projected. No Eloquent bookmark-to-recipe relationship
is used for display. Consequently:

- The pointer automatically follows every newly published current finalized
  version without changing the bookmark row.
- An active private revision never affects or leaks through the bookmark.
- A private, unpublished, missing-current-version, or deleted source becomes a
  generic content-free tombstone while the bookmark remains removable.
- Re-publication of an existing durable recipe restores the live projection.
- Hard deletion permanently leaves the opaque recipe identifier unresolved;
  title, owner, and version data are not retained to decorate the tombstone.

### Recipe collection and private recipe tag

RecipeCollection and PrivateRecipeTag are separate REC-12 organizational
concepts, each owned by exactly one User. Both use an auto-incrementing stable
integer identifier, a required display name of at most 100 characters, a
case-normalized lookup name, and timestamps. The database makes normalized
names unique per owner, not globally, so two users may independently create the
same name. No description, visibility flag, sharing state, nesting,
collaborator, cover, notes, or manual order is represented.

A private recipe tag is not recipe metadata and is not related to REC-13's
future public creator-defined or managed tags. It describes only one user's
personal organization, is never searched by public discovery, and cannot be
seen by the source recipe's creator unless that creator is also the organizing
user.

Each organization type has two explicit many-to-many relationships: a direct
membership to a durable Recipe owned by the same user and a membership to a
Bookmark owned by the same user. The distinction is persisted in four tables
rather than a polymorphic target. Membership rows contain only both foreign
keys and timestamps; they copy no content. Composite primary keys make repeat
attachment idempotent.

The application re-resolves the organization and target through the
authenticated user's relationships and authorizes the organization at every
mutation boundary. Owner IDs and target types are not accepted from submitted
input. Direct membership may reference an owned draft or finalized recipe and
changes neither ownership, lifecycle, visibility, content, nor publication
state. Another user's public recipe must be organized through the organizing
user's own Bookmark.

Deleting a collection or private tag cascades only its membership rows.
Deleting an owned recipe removes its direct memberships; deleting a bookmark
removes its organization memberships. Neither operation deletes an
organization record or unrelated target. A bookmark membership remains when
only its source becomes private or unavailable and renders the content-free
REC-10 tombstone. Organization never grants source-recipe access.

Owner-only pages provide CRUD, deterministic membership listing, attach/remove
controls, and private-tag filtering. There are no public organization routes.
Public recipe detail and discovery remain explicit PublicRecipe and
PublicRecipeSummary projections and include no organization relationships or
counts. The REC-14 public-profile projection preserves the same exclusions.

### Recipe remix lineage

`App\Models\RecipeRemixLineage` is the immutable one-to-one provenance
record for a remix recipe. A remix is otherwise an ordinary durable
`Recipe`: it is owned only by the remixer, begins as a private draft, has its
own mutable children, and may later create its own finalized versions.

Lineage contains:

- An application-generated ULID and server timestamps.
- A unique `remix_recipe_id` foreign key that cascades only with the remix.
- The opaque durable source recipe ID without a source foreign key.
- The exact immutable source-version ULID without a source foreign key.
- The source version's recipe-local number.
- A nullable internal source-creator user foreign key using `nullOnDelete`.
- A unique server-issued operation ULID for logical-request idempotency.

The absent source foreign keys are deliberate. Current recipe deletion
cascades finalized versions, so a restrictive, nulling, or cascading source
constraint would respectively block deletion, lose exact lineage, or delete
provenance. Opaque IDs remain non-identifying historical references after
source deletion and never authorize source retrieval. The nullable creator
reference supports REC-14 lookup of an independently selected source-version
attribution while ensuring current account erasure removes the personal link.
DEC-018 prohibits treating the general account name, email, internal ID, or
profile data as attribution.

Creation locks and independently authorizes the current finalized source,
rejects stale/historical version submissions, creates a new remixer-owned
private draft, and recreates every versioned ingredient, section, and step row
from the exact source snapshot. Recipe metadata, original and structured
ingredient values, custom units, ordering, exact instruction text, and grouping
are copied; record identities are not. The source and remix then evolve
independently.

Lineage presentation first authorizes the durable source recipe for the current
viewer and then internally resolves the recorded version. An authorized source
may expose its historical title and recorded version number as concise
attribution without adding a historical-version route. An inaccessible source
produces a content-free tombstone with only the recorded version number.
Changing source visibility, publishing a replacement source version, deleting
the source, or erasing its creator never changes or removes remix content.

The operation ULID makes replay of one logical creation return its existing
remix, while a new operation permits an intentional second remix. Recipe,
children, lineage, and minimized `recipe.remixed` audit evidence share one
transaction.

### Recipe ingredient line

`App\Models\RecipeIngredientLine` is one creator-authored line belonging to a
recipe. It is not an `Ingredient`, shared catalogue food, provider product,
parsed result, or food match.

Identity, ownership, and order:

- Auto-incrementing integer identifier and required `recipe_id` foreign key.
- Authorization derives from the recipe creator through `RecipePolicy`.
- Recipe deletion cascades to its lines; line deletion has no relationship to
  user-owned ingredient or catalogue records.
- Required zero-based `position`, unique within the recipe. The ordered recipe
  relationship always sorts by this field.
- Creation and update timestamps.

Creator text and supplementary structure:

- Required `original_text` stored as text. It is the authoritative text the
  creator submitted and may contain leading/trailing/repeated whitespace,
  punctuation, capitalization, and Unicode fractions.
- Optional non-negative quantity stored as `DECIMAL(38,18)` using the shared
  exact-decimal parsing and storage boundary.
- Optional standard FND-06 unit identifier or separately preserved custom-unit
  text up to 32 characters. These fields are mutually exclusive on writes.
- Optional generic ingredient wording up to 255 characters and optional notes
  up to 2,000 characters.

Original text is never derived from these supplementary fields. Unit
normalization, future parsing, matching, resizing, and nutrition work may read
the original text and populate or use separate structure, but cannot rewrite
it. A parser may fail without invalidating the line. Safe custom units and a
complete absence of quantity/unit structure remain valid.

REC-08 defines resized quantity as derived presentation state:

`original structured quantity × requested servings ÷ original saved servings`.

The original saved servings and each line's original scale-18 decimal remain
the calculation source on every request; a prior displayed result is never an
input. Calculation uses exact base-10 decimals with FND-06's 24 division guard
digits and half-up rounding. Three-decimal display rounding occurs once at the
presentation boundary, with a positive value below that resolution displayed
as `<0.001`.

Standard unit identifiers keep their registered symbol without optimization or
conversion. Numeric custom units and count units scale in the same way, while
custom text remains unchanged. A line is reconstructed for display only when
quantity, one valid unit, and generic wording are all present; its notes are
carried through unchanged. Otherwise the authoritative `original_text` is
shown exactly and no quantity is inferred. A null, zero, negative, or malformed
original serving count disables resizing rather than selecting a fallback.
Requested servings are untrusted GET presentation state and never enter model
fillable data or a save path.

Positions are contiguous. Append chooses the next last position while holding
the recipe lock; deletion compacts the remaining positions; reorder requires
the exact complete set of that recipe's line identifiers and writes the new
order transactionally. `recipe_id` and `position` are not mass assignable.
The current Livewire editor exposes add, edit, remove, and up/down reorder.

### Recipe instruction section

`App\Models\RecipeInstructionSection` is an optional recipe-owned label used
to organize instruction steps. It is not a mandatory container or a nested
subsection hierarchy.

Persisted fields and relationships are:

- Auto-incrementing integer identifier and required `recipe_id` foreign key.
- Required human-readable name up to 255 characters. Names are trimmed for
  section-name validation and storage; duplicate names within one recipe are
  allowed because no uniqueness rule is specified.
- Required zero-based `position`, unique within the recipe. The ordered recipe
  relationship always sorts by this field.
- Creation and update timestamps.

Authorization derives from the owning recipe through `RecipePolicy`. Appending
is last, deletion compacts section positions, and full-set reorder is
transactional under the recipe lock. Deleting a section sets its steps'
`section_id` to null; it never deletes or rewrites those steps.

### Recipe instruction step

`App\Models\RecipeInstructionStep` is one creator-authored instruction in a
recipe-global sequence.

Identity, ownership, and order:

- Auto-incrementing integer identifier and required `recipe_id` foreign key.
- Nullable `section_id`. When present, application writes require that section
  to belong to the same recipe. A section is optional even when the recipe has
  other sections.
- Required zero-based `position`, unique within the recipe. Ordering is global
  across the recipe rather than restarting within each section.
- Creation and update timestamps.

The required text column is creator-authored content. It may contain leading,
trailing, or repeated whitespace, punctuation, capitalization, Unicode, and
line breaks. Laravel's automatic string trimming is bypassed only for the
Livewire `instructionText` update path. Validation uses `trim()` only to decide
whether the input is blank; every accepted value is stored unchanged. Editing
replaces the text only with the creator's exact newly submitted value.

Global order makes section changes unambiguous: moving a step between sections
or making it unsectioned changes only its nullable metadata and never creates a
second section-local position. Append chooses the last global position,
deletion compacts remaining positions, and reorder requires the exact complete
set of the recipe's step IDs. Reordering is transactional and never rewrites
text or section membership. The authenticated Livewire editor exposes add,
edit, remove, optional assignment, and up/down reorder.

### Ingredient

`App\Models\Ingredient` is a user-owned food record. Its current fields make it
capable of representing both a packaged product and a manually entered food,
although the code does not assign either subtype.

Identity and ownership:

- Integer identifier.
- Required `user_id` foreign key.
- Creation and update timestamps.
- The owning user alone may view, update, or delete the record.
- Deleting the user cascades to the ingredient.
- Ingredient deletion is permanent; there is no soft-delete state.

Description and external identification:

- Required `name`.
- Optional `barcode`, indexed but not unique.
- Required `barcode_provenance` allowlist: `manual`, `machine_imported`, or
  `legacy_unknown`.
- Nullable stable `barcode_source` identifier.
- Nullable `barcode_imported_at` server timestamp.
- Optional `image_url`.
- Optional arrays of `keywords` and `categories`.

Amount and serving data:

- Required `quantity`, stored as decimal `(10, 3)`. The migration gives it a
  default of zero, while both mutation paths validate it as required and
  non-negative.
- Required `quantity_unit`, stored as a string up to 32 characters.
- Optional `serving_quantity`, stored as decimal `(10, 3)`.
- Optional `serving_quantity_unit`, stored as a string up to 32 characters.
- Optional `recommended_servings`, stored as decimal `(10, 2)`.

Nutrition data:

- Optional `nutriments` JSON.
- The Eloquent model casts the JSON fields to arrays and amount fields to
  fixed-scale decimal strings.

### Measurement unit

FND-06 represents measurement units in the application domain rather than as
interchangeable strings. `MeasurementDimension` distinguishes mass, volume,
count, and custom quantities. `StandardUnit` provides stable identifiers;
`StandardUnitDefinition` supplies symbols, labels, dimensions, exact canonical
factors, and unambiguous aliases.

Standard mass units are milligram, gram, kilogram, international avoirdupois
ounce, and international avoirdupois pound, with grams as the canonical base.
Standard volume units are millilitre, centilitre, litre, UK recipe teaspoon,
UK recipe tablespoon, US fluid ounce, US cup, US liquid pint, US liquid quart,
and US liquid gallon, with millilitres as the canonical base. UK spoons are
exactly 5/15 ml; US volume factors derive from the exact 231-cubic-inch US
gallon.

Item, piece, slice, clove, serving, portion, and the existing package/container
labels are count units. They have no universal mass, volume, or cross-label
equivalence and therefore support only identity conversion of the exact same
unit.

`CustomUnit` preserves an unknown user's original text, identifies it as
custom/non-convertible, and keeps it valid for recipe editing and proportional
resizing. Common values include `pinch`, `handful`, `bunch`, `sprig`, and
`to taste`. Alias normalization never coerces ambiguous `T` or `t` input.
`UnitConverter` uses exact decimal arithmetic for compatible standard units
and returns domain errors for cross-dimension, custom, and unrelated count
conversion. It contains no food-density conversion.

### Nutrient definition

`Nutrient` supplies stable identifiers for energy kcal/kJ, fat, saturated fat,
carbohydrates, sugars, fibre, protein, salt, and sodium. `NutrientRegistry`
owns labels, import/current-code aliases, canonical storage units, preferred
display units, supported bases, precision, rounding, authority, and derivation
metadata.

`NutrientBasis` explicitly represents per 100 g, per 100 ml, per serving,
whole recipe, ingredient quantity, and per item/count. Different bases are not
implicitly comparable. Kcal is authoritative canonical energy and kJ is its
derived display representation. Nutrients measured by mass are canonical
grams; sodium displays in milligrams.

`Decimal`, `NutrientUnitConverter`, and `NutrientDisplayFormatter` apply
DEC-003/DEC-004 without PHP floating point: storage is DECIMAL(38,18), working
precision is at least 50 digits, division retains 24 guard digits, and final
storage/display rounding is decimal half-up.

### Nutrition dataset

Nutrition is an embedded document rather than a separate entity. The Livewire
code recognizes this shape:

```text
nutriments
??? raw
??? per_100g
??? per_serving
```

`raw` preserves the OpenFoodFacts nutriments object available at the time of a
lookup. The two normalized buckets can contain:

- `energy_kj`
- `energy_kcal`
- `carbohydrates`
- `fat`
- `fiber`
- `proteins`
- `salt`
- `saturated_fat`
- `sodium`
- `sugars`

STB-04 makes the normalized bucket shape a shared controller/Livewire write
contract, while STB-06 exposes every registered nutrient in the form and
ingredient detail view. Form inputs use canonical units; detail output uses
the registry's DEC-004 preferred display unit and precision.

No unit metadata is stored alongside normalized values. Energy normalization
uses kcal as the canonical basis and derives the normalized kJ pair exactly.
When only kJ is supplied, kcal is calculated at guard precision and quantized
once at the storage boundary before normalized kJ is regenerated. When both
units conflict, kcal wins. The provider-shaped raw bucket retains the
available source observations, but normalized fields still have no independent
provenance metadata.

### Barcode and OpenFoodFacts product data

A barcode is an optional string of up to 64 characters. Lookup trims
surrounding whitespace and preserves leading zeros, but the code does not
validate a specific symbology or check digit.

Within the Livewire workflow, barcode lookup serves two roles:

1. It identifies an existing ingredient belonging to the current user.
2. If no such record exists, it identifies a product requested from
   OpenFoodFacts.

OpenFoodFacts is not represented as a separate persisted product entity. A
successful trusted import copies mapped data into the ingredient and records
`openfoodfacts` as its stable barcode source, a server-generated UTC import
timestamp, and `machine_imported` provenance. Manual records have null barcode
metadata and `manual` provenance. Pre-STB-08 non-empty barcodes are preserved
as `legacy_unknown` with null source/time. The record-level classification does
not provide source revision or per-field nutrition provenance.

## Current relationships

```text
User 1 ---- owns ---- 0..* Ingredient
  |
  +------ owns ---- 0..* Recipe
                            |
                            +-- contains 0..* RecipeIngredientLine
                            |                 +-- authoritative original text
                            |                 +-- optional structured fields
                            |                 +-- explicit recipe-local position
                            |
                            +-- contains 0..* RecipeInstructionStep
                            |                 +-- exact creator-authored text
                            |                 +-- optional section reference
                            |                 +-- explicit global recipe position
                            |
                            +-- contains 0..* RecipeInstructionSection
                                              +-- name
                                              +-- explicit recipe-local position
  |
  +------ owns ---- 0..* Bookmark
  |
  +------ owns ---- 0..* RecipeCollection
  |                         +-- contains owned Recipe or Bookmark memberships
  |
  +------ owns ---- 0..* PrivateRecipeTag
                            +-- applies to owned Recipe or Bookmark memberships
```

An audit actor identity optionally references one user with `ON DELETE SET NULL`.
Audit events store the random identity ULID as an opaque actor or user-subject
reference without a foreign key, allowing the mapping to be erased without
mutating the append-only event. Non-user subjects use a bounded identifier and
no hard domain foreign key. System actors have no identity mapping.

There is intentionally no represented relationship between the user-owned
`Ingredient` food/product record and a recipe ingredient line. Meals, meal
plans, diet plans, nutrition targets, and food-log entries are not represented.

## Current rules and constraints

Database-enforced rules:

- User email is unique.
- Every ingredient belongs to an existing user.
- User administrator status is non-null and defaults to false.
- Deleting a user deletes their ingredients.
- Every recipe ingredient line belongs to an existing recipe, and deleting the
  recipe deletes its lines.
- Original recipe ingredient text and a non-negative recipe-local position are
  required.
- Recipe and position are unique together; structured quantity, standard unit,
  custom unit, generic wording, and notes may be null.
- Every instruction section belongs to an existing recipe, and deleting the
  recipe deletes its sections.
- Every instruction step belongs to an existing recipe, and deleting the
  recipe deletes its steps.
- Instruction text and a non-negative global recipe position are required;
  recipe and step position are unique together.
- Instruction section membership is nullable. Deleting a section nulls that
  membership while retaining the step, and application writes require a
  selected section to belong to the step's recipe.
- Section name and a non-negative recipe-local position are required; recipe
  and section position are unique together. Section names are not unique.
- Ingredient name, quantity, and quantity unit cannot be null.
- Barcode has a non-unique index.
- Barcode provenance is restricted to the three stable enum values and defaults
  to `manual`; it has an index for migration classification.
- JSON fields and optional serving/image fields may be null.
- Audit event and identity identifiers are ULID primary keys.
- Audit classification columns and event time are non-null; the event payload
  and integrity hash are required.
- User identity mappings null their user reference on account deletion.
- Event identity references intentionally have no foreign key, so mapping
  erasure cannot cascade to or mutate the event.
- Audit purpose/time, retention/time, actor mapping, subject mapping, subject
  resource, occurrence time, and correlation fields have query indexes.
- Every recipe collection and private recipe tag belongs to an existing user;
  deleting that user deletes their private organization.
- Collection and private-tag names and normalized names are required and
  bounded to 100 characters. Normalized names are unique per owner.
- Direct recipe membership references an existing organization and recipe.
  Bookmark membership references an existing organization and bookmark.
- Each collection/recipe, collection/bookmark, private-tag/recipe, and
  private-tag/bookmark pair is unique through its composite primary key.
- Deleting an organization cascades only its membership rows. Deleting a direct
  recipe or bookmark target cascades only the corresponding membership rows.
  No organization foreign key can cascade deletion into a recipe or bookmark.

Application authorization also requires every membership target to have the
same authenticated owner as its collection or private tag.

Application-enforced audit rules:

- Only the trusted recorder appends an event and assigns its UTC time.
- Action definitions derive purpose, retention, actor/subject categories and
  payload shape; unknown action names, classifications and payload keys are not
  accepted.
- References, payload depth, counts and lengths are bounded; credentials, raw
  IP addresses, full user agents, private domain content, request bodies and
  evidence content are rejected.
- Eloquent update/delete operations are rejected for audit events; identity
  mapping deletion uses the dedicated eraser.
- The integrity HMAC can detect mutation but does not impose database-level
  write denial.
- The new tables require no backfill because no earlier audit store exists.

Rules shared by the Livewire and controller write paths:

- Name is required and at most 255 characters.
- Barcode, source, import time, and provenance are excluded from ordinary
  controller/Livewire validation and model mass assignment.
- Quantity is required, numeric, and non-negative.
- Quantity unit is required and at most 32 characters. Unambiguous FND-06
  aliases normalize to storage symbols; safe custom and ambiguous text is
  preserved without conversion.
- Serving quantity and unit are an all-or-none pair. The quantity is
  non-negative; its unit follows the same unit rules.
- Recommended servings is optional, numeric, and non-negative.
- Image URL is optional and must be a URL.
- Normalized nutrition has only `per_100g` and `per_serving` registered
  nutrient keys. Values are nullable non-negative DEC-003 decimals and persist
  as scale-18 strings without DEC-004 display rounding. Missing remains
  distinct from numeric or string zero.
- Ownership identifiers are excluded from the write allowlist.
- A narrow trusted import action accepts only a typed successful STB-07 result,
  requires requested/returned barcode equality, reapplies the shared
  validation/normalization rules to mapped data, and assigns machine metadata
  explicitly.

Additional Livewire behavior:

- A duplicate non-empty lookup barcode for the same user redirects to the
  existing record before a provider request.
- A successful result is held in short-lived server session state bound to the
  authenticated user and ingredient. A locked random component token references
  it; ordinary public state cannot supply source, time, or provenance.
- Failed lookup or replacement lookup attempts clear any earlier pending
  success. Failed re-import leaves persisted verified provenance unchanged.

Authorization is based on ingredient ownership. Listing queries explicitly
filter by the current user's identifier. Individual view, edit, and delete
entry points use `IngredientPolicy`.

## Unclear or inconsistent concepts

### Meaning of ingredient

The record is named `Ingredient`, but barcode, package quantity, product image,
OpenFoodFacts categories, and recommended servings make it resemble a packaged
food product. There is no separate concept for a generic food, branded product,
pantry item, or ingredient used in a particular recipe.

### Meaning of quantity

The schema comment describes `quantity` as how much the item contains, while
the shared unit definitions also allow count and custom labels. The code
does not define whether `4 can` means a four-can package, four inventory items,
or a recipe amount. Multi-pack OpenFoodFacts text such as `6 x 25 g` is parsed
as quantity `6` with no unit, and the `25 g` component is not represented in
separate fields.

`quantity` permits zero, although a zero-sized product may have different
meaning from an unknown amount. Unknown is not available through the form
because quantity and unit are required.

### Serving terminology

The stored fields are named `serving_quantity`,
`serving_quantity_unit`, and `recommended_servings`. The show page labels the
first two together as "Recommended serving" and the last as "Recommended
servings." The code does not define whether `recommended_servings` means
servings per package, a dietary recommendation, or another value.

The columns remain independently nullable for legacy compatibility, but all
new controller and Livewire writes require serving amount and unit together.
Both absent and both present are valid; either one alone is rejected.

### Food identity and barcode uniqueness

The UI treats a barcode as unique within one user's records, but the database
and controller do not enforce that rule. The same barcode may also be stored by
different users. It is unclear whether ingredients are intended as private
copies or whether a barcode should identify one shared product.

### Nutrition provenance and accuracy

The ingredient now records whether its barcode was verified through the
OpenFoodFacts import workflow, but the same normalized nutrition fields can
still originate from OpenFoodFacts or later manual editing. The record-level
barcode classification is not per-value provenance and therefore cannot fully
implement the accuracy distinction in `AGENTS.md`.

The `raw` OpenFoodFacts object can coexist with manually changed normalized
values, so it is not necessarily the source of each current normalized field.
There is no status indicating field-level divergence.

### Nutrition schema and units

STB-04 gives normalized JSON buckets a shared write schema backed by the
FND-06 nutrient registry. Unknown normalized keys are rejected, legacy
`fiber`/`proteins` aliases normalize to `fibre`/`protein`, and values
use DEC-003 scale. STB-05 stores known zero as JSON numeric `0`; null, empty,
and whitespace-only normalized inputs are missing values and therefore omit
the nutrient key. Empty normalized buckets are omitted, and an otherwise empty
nutrition object is stored as SQL `NULL`. JSON `null` remains structurally
distinguishable but is not the shared writer's missing-value convention. The
provider-shaped `raw` bucket remains unbounded.
Normalized values still do not carry provenance, locale, or explicit unit
metadata; their basis remains encoded by the bucket name.

The `per_100g` basis assumes mass. The model also supports products measured in
volume, pieces, and containers, but it contains no density or conversion data
that would connect those quantities to a 100-gram basis.

### Keywords and categories

OpenFoodFacts categories arrive as namespaced tags such as
`en:test-category`, while users can enter arbitrary category strings. Imported
and manual values share one array and there is no normalization, vocabulary,
hierarchy, or source marker. Keywords de-duplicate manual additions in the
browser; categories do not.

### Original user input

A recipe ingredient line has dedicated authoritative `original_text`. The
Livewire update path is exempted narrowly from Laravel's automatic string trim,
and application code does not normalize this value. The separate `Ingredient`
name can still be replaced by an OpenFoodFacts product name during lookup and
is not a record of recipe authoring text.

A recipe instruction step has dedicated exact `text`. Its narrow Livewire trim
exception and blank-only validation preserve every accepted nonblank value,
including meaningful leading, trailing, repeated, multiline, and Unicode text.
No current import workflow or legacy instruction store exists to migrate.

### Public recipe metadata

`PublicRecipeTag` is creator wording attached directly to one durable recipe.
Its display name is trimmed but otherwise preserved; a whitespace-collapsed,
case-folded identity prevents duplicate variants on that recipe. It is not
shared vocabulary and has no relationship to the user-owned
`PrivateRecipeTag` organisation model.

`ManagedRecipeTerm` is administrator-managed application vocabulary. Its ULID
is stable across renames; category is the `ManagedRecipeTermCategory` enum
(`dietary`, `cuisine`, or `meal_type`); and `is_active` controls future use.
The `managed_recipe_term_recipes` relationship is accepted durable recipe
metadata. Deactivated terms cannot be newly attached or approved but retained
associations remain meaningful and visible.

`ManagedRecipeTermSuggestion` separately records an administrator proposal,
its recipe and stable term, source, pending/accepted/rejected state, nullable
administrator identity, decision time, and a database-unique pending key.
Creation never attaches the term. Only the locked recipe owner may accept or
reject; acceptance also requires the term still be active and attaches it
idempotently in the decision transaction.

These metadata records do not modify `RecipeVersion.snapshot`. Public reads and
discovery combine them only with the durable recipe selected by the existing
current-public-finalized boundary. Public projections expose free-form wording
and accepted term ID/category/name only; they omit suggestions, private tags,
actors, internal states, and audits. There is no represented recipe-nutrition
completeness or claim-verification concept, so no tag is presented as verified.

## Concepts not yet represented

The following concepts named in the project purpose have no current domain
representation:

- Match between a recipe line and a food/ingredient record.
- Recipe yield, portion, or serving.
- Calculated or estimated recipe nutrition.
- Recipe import operation, preserved import source, extraction result,
  warnings, and parser provenance.
- Meal.
- Meal plan or schedule.
- Diet plan, nutrition target, or dietary constraint.

DEC-005 constrains the future recipe-import representation without claiming it
exists today. One private import operation will identify its owner and resulting
draft through server-authoritative references and retain its channel, source
format, exact pasted or locally extracted recipe source text, safe source URL
where applicable, parser/extractor identifiers and versions, per-stage
provenance, structured warnings, completion/failure classification, correlation
ID, and stable idempotency identity. Full fetched HTML and uploaded files are
transient rather than durable provenance.

DEC-006 further constrains the unimplemented OCR representation. A provider-
independent OCR result may contain ordered page/line text, normalized confidence
and bounding evidence, detected-language evidence, warnings, and pinned engine/
model, trained-data, and preprocessing versions. After transient-source
deletion, provenance retains the import ID, source type, sanitized basename,
validated media type and dimensions, page count, processing time, correlation
ID, fallback state, outcome, warnings, and preserved OCR wording. It excludes
client paths, source hashes, EXIF/GPS/device/capture metadata, full provider
responses, and original/canonical images.

OCR remains untrusted source text and enters the DEC-005 parser. It does not
itself represent recipe entities, catalogue matches, nutrition, or a finalized
recipe. No current model, migration, route, queue job, OCR adapter, or upload
store implements this contract.

The provider-independent extraction result permits absent fields and contains
title/description and yield candidates, ordered ingredient source lines with
optional structured suggestions, instruction source text with optional ordered
steps and sections, source channel/format/URL, parser identity/version,
warnings, and completion state. The preserved source remains authoritative;
the result never replaces exact ingredient or instruction wording. No current
model, migration, route, queue job, parser, or upload store implements this
contract.

## Questions requiring owner input

- Does `Ingredient` mean a reusable food/product catalogue entry, a pantry
  item, a recipe line, or one of several concepts that should be separated?
- What exactly do `quantity`, `serving_quantity`, and
  `recommended_servings` mean, particularly for multipacks and products
  measured by piece or container?
- Should an unknown quantity be valid, and is zero a real quantity or a stand-in
  for unknown?
- Should barcodes identify shared products globally, be unique only within a
  user's catalogue, or merely be optional lookup hints?
- Which fields need source, import time, confidence, accuracy, or
  user-override metadata?
- Should the raw OpenFoodFacts payload be retained indefinitely, refreshed, or
  treated only as transient import input?
- Should OpenFoodFacts categories and keywords be normalized separately from
  user-authored organisation?
