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

A barcode is an optional string of up to 64 characters. The code does not
validate its symbology, length for a particular standard, or check digit.

Within the Livewire workflow, barcode lookup serves two roles:

1. It identifies an existing ingredient belonging to the current user.
2. If no such record exists, it identifies a product requested from
   OpenFoodFacts.

OpenFoodFacts is not represented as a persisted source or product entity. Its
data is copied into the ingredient record. There is no source identifier,
import timestamp, source revision, or per-field provenance.

## Current relationships

```text
User 1 ????? owns ????? 0..* Ingredient
                              ?
                              ??? amount and serving fields
                              ??? keyword/category arrays
                              ??? embedded nutrition JSON
```

An audit actor identity optionally references one user with `ON DELETE SET NULL`.
Audit events store the random identity ULID as an opaque actor or user-subject
reference without a foreign key, allowing the mapping to be erased without
mutating the append-only event. Non-user subjects use a bounded identifier and
no hard domain foreign key. System actors have no identity mapping.

There are no represented relationships between ingredients and recipes,
recipe lines, meals, meal plans, diet plans, nutrition targets, or food-log
entries.

## Current rules and constraints

Database-enforced rules:

- User email is unique.
- Every ingredient belongs to an existing user.
- User administrator status is non-null and defaults to false.
- Deleting a user deletes their ingredients.
- Ingredient name, quantity, and quantity unit cannot be null.
- Barcode has a non-unique index.
- JSON fields and optional serving/image fields may be null.
- Audit event and identity identifiers are ULID primary keys.
- Audit classification columns and event time are non-null; the event payload
  and integrity hash are required.
- User identity mappings null their user reference on account deletion.
- Event identity references intentionally have no foreign key, so mapping
  erasure cannot cascade to or mutate the event.
- Audit purpose/time, retention/time, actor mapping, subject mapping, subject
  resource, occurrence time, and correlation fields have query indexes.

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
- Barcode is an optional string of at most 64 characters. Whitespace is
  trimmed, an empty value stores as null, and leading zeros are preserved.
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

Additional Livewire behavior:

- A duplicate non-empty barcode for the same user redirects to the existing
  record rather than saving.
- OpenFoodFacts data and its provider-shaped `raw` bucket are merged into the
  form's current nutrition document before shared normalization.

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

The same normalized fields can originate from OpenFoodFacts or manual editing,
and imported values can later be changed. The data model does not retain their
source. Consequently, it cannot implement the distinction in `AGENTS.md`
between accurate imported values and estimated calculated values.

The `raw` OpenFoodFacts object can coexist with manually changed normalized
values, so it is not necessarily the source of the current normalized fields.
There is no status indicating that divergence.

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

There is no recipe ingredient line and no dedicated raw/original-text field.
The ingredient name can be replaced by an OpenFoodFacts product name during a
lookup, so it is not a guaranteed record of the user's original text.

## Concepts not yet represented

The following concepts named in the project purpose have no current domain
representation:

- Recipe.
- Recipe collection, folder, tag, or other organisation.
- Recipe ingredient line.
- Original recipe ingredient text.
- Match between a recipe line and a food/ingredient record.
- Recipe instructions, yield, portion, or serving.
- Calculated or estimated recipe nutrition.
- Meal.
- Meal plan or schedule.
- Diet plan, nutrition target, or dietary constraint.

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
- Where should the original ingredient text be stored once recipe ingredient
  lines are introduced, and which normalization operations may alter it?
