# Product specification

## Status and purpose

This document defines the intended finished product for VibeDietr. It records
product decisions confirmed by the owner in July 2026 and distinguishes them
from the much smaller feature set currently implemented.

VibeDietr is a personal recipe, meal-planning, and nutrition-tracking web
application. It should let people:

- Create, import, organise, publish, bookmark, and remix recipes.
- Preserve recipe wording while structuring ingredients well enough to resize
  recipes and estimate nutrition.
- Match recipe ingredients to a shared food and product catalogue backed
  primarily by OpenFoodFacts.
- Plan future meals or record food after it is consumed.
- Compare planned and consumed nutrition with optional personal targets.
- Share recipes and meal plans without giving other users edit access to the
  originals.

Imported food nutrition may be presented as source data. Nutrition calculated
from recipe ingredients, substitutions, serving sizes, or incomplete matches
is an estimate and must be labelled as such.

## Product principles

- Preserve what the user entered. Parsing and normalization must not destroy
  the original ingredient or instruction wording.
- Prefer useful estimates with visible limitations over false precision.
- Never silently guess a conversion that depends on the particular food.
- Retain provenance and version history when data is imported, calculated, or
  manually corrected.
- Keep ownership, visibility, and edit permission separate.
- Default recipes toward public discovery and personal planning data toward
  privacy.
- Make common planning and tracking actions low-friction while allowing users
  to review and correct assumptions.

## Accounts, identity, and roles

### Users

An account owns its recipes, bookmarks, recipe organisation, meal plans,
diary entries, targets, and one-off custom items.

For public attribution, a user may choose either a username/display name or
their real name. Email addresses remain private. Attribution may link to an
optional public profile listing the user's public recipes and remixes. The
user may disable that profile page without making their public recipes
private.

The initial product tracks meal plans, consumption, and targets for the plan
owner only. Multi-person or family plans are future scope.

### Administrators

Administrators moderate shared catalogue changes. They can review proposed
manual foods, user corrections, and refreshed OpenFoodFacts data, then accept
or reject the proposed change. Ordinary users cannot directly edit or delete
barcode-imported shared catalogue records.

Administrator status requires verified email, a confirmed, locally
verified RFC 6238 TOTP factor, and acknowledged mandatory recovery codes
before activation. Any active, email-verified user may enroll their own
factor without gaining privilege. Initial scope is one TOTP factor per
account with no required provider, recurring verification charge, specific
authenticator application, or smartphone. DEC-009 privileged lifecycle
actions require a fresh TOTP, and an administrator cannot remove their final
factor while retaining administrator status. Recovery may use a single-use
recovery code, another active administrator using their own password and
fresh TOTP, or a traceable CLI-assisted host-access ceremony for total sole-
administrator factor loss; none permits password-only fallback. Multiple
factors and WebAuthn/passkeys are future enhancements.

Administrator security notifications use queued email. Resend is the preferred
zero-recurring-cost low-volume production provider, while self-hosted
deployments may select another qualifying authenticated SMTP relay. Successful
bootstrap, promotion and revocation transitions, break-glass and factor-recovery
events, factor changes and lockout notify the affected account and active
administrators according to DEC-016. A non-administrator's ordinary factor
enrollment is disclosed only to that account. Administrator destination changes
require a newly verified address and notification to both the old and new
addresses. Privilege-increasing and recovery transitions fail closed unless
their audit evidence and notification intents can be durably committed;
risk-reducing revocation, lockout and session invalidation remain available
during delivery failure. Provider acceptance is recorded but is not described
as recipient-server delivery or human reading. Local/test mail transports are
never production notification channels, and no administrator may disable these
mandatory security notifications.

## Recipes

### Ownership and visibility

- Every recipe has one creating user, who alone may edit it.
- A finalized recipe defaults to public visibility. Its creator may instead
  make it private.
- Public recipes are read-only to everyone except their creator and are
  viewable by logged-out visitors.
- Private recipes are visible only to their creator, except for the narrowly
  scoped access granted through a meal plan shared with selected users.
- Visibility is independent of lifecycle state: a recipe can be finalized and
  usable while remaining private.

### Lifecycle and versioning

- A recipe draft is private working state and cannot be used in meal plans.
- Imported recipes always begin as drafts and require review.
- Publishing finalizes the draft and makes that version usable; it does not by
  itself determine whether the recipe is public or private.
- Editing a finalized recipe creates a draft revision. The current finalized
  version stays active until the creator explicitly publishes the revision.
- Published versions and their relevant food matches and nutrition provenance
  must be identifiable so dependent meal-plan snapshots can remain stable.

### Required and optional recipe content

A finalized recipe has at least:

- A title.
- A suggested number of servings greater than zero.
- One or more ingredient lines.
- Instructions represented as ordered steps.

Instructions may be grouped into named sections. Imported wording is
preserved.

Recipes may also contain descriptive text, images, source attribution,
creator-defined public tags, and other presentation metadata.

### Ingredient lines

Each ingredient line always retains the exact original text. It may also have
structured fields for:

- Quantity.
- Unit.
- Generic food or ingredient wording.
- Notes or preparation qualifiers.
- A match to a shared catalogue food or product.
- Match score, confidence band, review state, and match provenance.

Incomplete and unparseable lines, such as `salt to taste`, remain valid.
Missing structure or a missing match may prevent that line from contributing
to the nutrition estimate, but must not prevent the recipe itself from being
saved.

### Quantities, units, and resizing

- Recognized recipe units include controlled mass, volume, and count units.
- The quantity and unit originally entered remain the stored and displayed
  recipe quantity. For example, `1 tbsp` is not rewritten and stored as grams.
- Custom units such as `bunch` may be structured and proportionally resized.
- Teaspoons and tablespoons use the modern UK recipe convention: exactly
  `5 ml` and `15 ml` respectively. Fluid ounces, cups, liquid pints, liquid
  quarts, and liquid gallons use US customary measures; their short aliases
  therefore never mean UK imperial volume. Mass ounces and pounds are
  international avoirdupois. Ambiguous single-letter spoon aliases such as
  `T` and `t` are not normalized automatically.
- Standard same-dimension conversions, such as kilograms to grams, may be
  used where required.
- Food-dependent conversions, such as tablespoons to grams, are used only
  when a reliable conversion exists for the matched food and unit.
- The app must not invent a food-specific conversion. A line without a
  reliable conversion is excluded from the applicable calculation and
  contributes to the completeness warning.

### Bookmarks and remixes

- A user may bookmark a public recipe. A bookmark points to the creator's live
  finalized recipe rather than copying it.
- A user who wants to edit another person's recipe creates a remix.
- A remix is an independent recipe owned by the remixer and retains
  attribution and a link to its source recipe.
- If an original recipe is later deleted or unpublished, existing remixes and
  meal-plan snapshots remain usable. Bookmarks show that the original is no
  longer available.

Before REC-14 provides explicitly selected public attribution identities,
DEC-018 permits remix attribution to display only the independently authorized
source recipe title and exact source version number. The application must not
treat the general account name as public or copy it, an email address, an
internal account identifier, or other profile data into lineage. If the source
is inaccessible, attribution becomes the non-linked wording `Remixed from an
unavailable recipe, version N`. Lineage retains the opaque recipe/version
identity, while its internal creator reference is nullable and removed on
creator erasure. This does not make historical source versions independently
browsable.

### Recipe organisation and tags

Users can organise their own and bookmarked recipes with private collections
or folders and private tags.

Recipes can also have public creator-defined tags. Common dietary, cuisine,
and meal-type tags come from a managed vocabulary and may be suggested
automatically where the suggestion is reliable. Creators may additionally use
free-form tags. Suggested claims such as `Low fat` must remain reviewable and
must not be presented as verified when the underlying nutrition is incomplete.

## Shared food and product catalogue

### Catalogue purpose

The shared catalogue supplies food identity, package and serving information,
and nutrition data used to estimate recipes and record direct food
consumption. It is not the recipe's displayed ingredient list: recipes display
their generic ingredient wording even when a branded or specific catalogue
product supplies the estimate.

The catalogue contains:

- Barcode products imported primarily from OpenFoodFacts.
- Rare manually submitted, non-barcode foods or products.

Approved catalogue records are public and read-only to everyone, including
logged-out visitors. Catalogue provenance does not expose the submitting
user's account identity or grant that user ownership.

Barcode should not be freely typed as a normal product-creation shortcut. A
barcode record represents a successful machine import. The catalogue retains
the submitting user only for provenance and moderation; the user does not own
the shared record.

### Product package and serving structure

Where source data is available, a multipack can represent all of:

- Package count, such as `4`.
- Internal item type, such as `can`.
- Net amount per internal item, such as `400 g`.
- Servings per internal item, such as `2`.
- A serving amount derived from the preceding values when that derivation is
  reliable.

Unknown package or serving values are null, not zero. These fields must not be
collapsed into one ambiguous `quantity` or `recommended servings` value.

### Food matching

- The matcher ranks catalogue candidates for a structured recipe line.
- It automatically selects the highest-scoring candidate above a minimum
  sensible threshold.
- A high-confidence selection may be accepted without interrupting the
  creator.
- A lower-confidence selection above the minimum threshold remains selected
  but is clearly flagged for review.
- A candidate below the minimum threshold is not selected, leaving the line
  unmatched.
- The recipe creator can easily replace any automatic match.
- The exact thresholds and final warning treatment are implementation and
  design decisions. An orange outline or tooltip is one possible treatment,
  not a specification requirement.

The creator's selected match supplies the default estimate for all viewers.
When adding the recipe to their own plan or diary, another user may substitute
a different catalogue product without changing the source recipe.

### Manual submissions

- A manually created non-barcode food enters a pending moderation state.
- Until approved, it is visible and usable only by its submitter.
- The submitter cannot edit, withdraw, delete, reassign, or approve it while
  pending.
- Once an administrator approves it, it becomes part of the shared catalogue.
- Rejection does not silently replace recipe lines that used the pending item;
  they must be left reviewable or unmatched.

### Corrections and source refreshes

- Users propose corrections to shared catalogue records rather than editing
  them directly.
- Proposals enter an administrator moderation queue.
- The administrator accepts the proposal or retains the current catalogue
  data.
- New OpenFoodFacts data for an existing product is also staged for
  administrator approval before it becomes current.
- Catalogue versions and their provenance are retained.
- After an approved catalogue update, recipes whose nutrition is calculated
  from ingredients recalculate automatically.
- Recipe nutrition supplied directly by a source or creator is not overwritten
  by a catalogue refresh.

## Nutrition

### Supported nutrients

The product supports at least:

- Energy in kcal and kJ.
- Fat.
- Saturated fat.
- Carbohydrates.
- Sugars.
- Fibre.
- Protein.
- Salt.
- Sodium.

Stored values retain useful source precision. Display values use one
product-wide nutrient-specific table rather than destructively rounding stored
data: kcal and kJ use whole numbers; protein, fat, saturated fat,
carbohydrate, sugars, and fibre use one decimal place in grams; salt uses two
decimal places in grams; and sodium uses whole milligrams. Final display values
use decimal round-half-up. Calculations and totals use full stored precision and
are rounded only after the final value has been calculated.

Known zero displays as numeric zero at the nutrient's normal precision. A
known positive amount that would otherwise round to zero displays as `<1 kcal`,
`<1 kJ`, `<0.1 g`, `<0.01 g` for salt, or `<1 mg` for sodium, as applicable.
A quantified source limit retains its limit, while an unquantified
source-declared trace value displays as `Trace`. Missing or unsupplied nutrition
displays as `Not available`, never as zero.

The same precision applies to ingredients, recipes, recipe servings, diary and
meal-plan totals, targets, comparisons, and human-readable reports. Machine-
readable exports preserve full stored precision. Locale affects decimal and
grouping separators only, and accessibility presentation does not change
precision. Full precision is not exposed as an advanced screen preference.

Kcal is the preferred energy display. When only one energy unit is supplied,
the other is derived using `1 kcal = 4.184 kJ`. If supplied kcal and kJ values
conflict, kcal is authoritative and kJ is recalculated.

Salt is the primary UK-facing salt-related display. Sodium is shown when it is
explicitly available, targeted, or requested rather than always being paired
with salt. Display rules alone do not authorize deriving a missing salt or
sodium value.

Shared catalogue nutrition belongs to an immutable catalogue version. Each
selected fact explicitly identifies its nutrient, canonical decimal value and
unit, basis (per 100 g, per 100 mL, or per serving), status, field-level
provenance, normalization policy, and any derivation or conflict warning.
Supplied observations remain separate from selected facts when needed to
preserve conflicting source evidence. Imported, manually submitted, derived,
and corrected values remain distinguishable.

Known zero is a stored known value, while an absent nutrient or explicit
missing status is not zero. Partial and empty nutrition panels remain valid and
the application does not calculate missing macronutrients from energy.
Per-serving nutrition belongs to the same catalogue version as its serving
definition; changing serving structure creates a later version rather than
changing the meaning of historical facts.

Provider field names and raw payloads are not canonical nutrient identity.
OpenFoodFacts fields map through application-owned nutrient definitions before
storage. Kcal remains the canonical energy calculation value; supplied kJ is
retained as source evidence and the application kJ display derives from kcal.
No salt/sodium conversion or per-serving derivation is implied by this schema.

### Recipe nutrition sources and precedence

Recipe nutrition values are expected on a per-serving basis. Whole-recipe
totals are derived from the declared serving count.

Possible nutrition sources include:

1. A creator's explicit manual override.
2. Nutrition supplied by an imported recipe source.
3. An ingredient-based estimate calculated from structured lines and their
   catalogue matches.

The primary display follows that precedence. When source-provided values are
primary, ingredient-based estimates remain available as a secondary,
collapsed comparison rather than replacing the source values.

Creators may override displayed nutrition values. The app retains the prior
value, its source, the change timestamp, and an optional correction note.

### Calculation and completeness

- Ingredient-based recipe nutrition is an estimate.
- The app calculates both whole-recipe and per-serving estimates.
- Unmatched lines and lines without a reliable quantity conversion are omitted
  from the total rather than guessed.
- A partial estimate is still displayed with a clear completeness warning and
  enough detail to identify excluded or review-needed lines.
- Accepted shared-catalogue changes cause ingredient-calculated recipes to
  recalculate, while source-provided and manually overridden recipe values
  remain stable.

## Meal plans and diary tracking

### Plan types and ownership

The same planning domain supports:

- Reusable undated schedules, such as a seven-day template.
- Plans tied to specific calendar dates.
- Ad-hoc diary use where entries are recorded after consumption.

Each plan has one owner and only that owner may edit it. A plan currently
tracks only its owner's consumption and targets.

### Visibility and sharing

A meal plan is private by default. Its owner may make it:

- Private to the owner.
- Read-only to selected registered users.
- Public and read-only to everyone, including logged-out visitors.

Any authenticated user with view access may create an independent copy owned
by themselves. A copy remains with its new owner even if access to the source
plan is later revoked.

A plan cannot be made public while it contains private recipes that would be
exposed by the plan. Sharing a plan with selected users may grant those users
read-only access to the private recipe snapshots needed to understand the
plan. Before sharing, the owner must explicitly acknowledge that consequence.

### Daily slots

Each day defaults to:

- Three meal slots, initially suitable for breakfast, lunch, and dinner and
  renameable by the user.
- A fixed-name `Drinks` slot.
- A fixed-name `Snacks` slot.

Users may add further slots and rename user-created or standard meal slots.
The `Drinks` and `Snacks` names remain fixed.

### Plan and diary entries

An entry may contain:

- A saved recipe.
- A shared catalogue food or product.
- A one-off custom item entered directly into the diary.

A one-off item is private to its diary entry by default. Submitting it as a
reusable catalogue food is a separate, explicit moderation workflow.

Dated entries distinguish planned and consumed state. For low-friction use,
the consumed amount initially assumes the planned serving quantity. The user
can easily record a different actual quantity and consumption time.

At consumption time, the entry snapshots the nutrition and relevant item
version used. Historical intake is never recalculated from current recipe or
catalogue data.

### Recipe changes after planning

- Adding a recipe to a plan stores a snapshot of the recipe version used.
- Publishing a newer recipe version does not silently change the planned
  entry.
- The plan owner receives a review notification.
- On review, the owner may update the planned entry to the newer recipe version
  or permanently retain the existing snapshot.

## Nutrition targets and diet plans

A diet plan is a meal plan with assigned nutrition targets, not a separate
higher-level product concept.

### Target profiles

- A user starts with one default daily target profile.
- Advanced use supports multiple named profiles, such as training and rest
  days.
- Any supported nutrient may be targeted independently; leaving a nutrient
  blank means no target exists for it.
- Each target supports an exact value, minimum, maximum, or range.
- Target profiles support calories, fat, saturated fat, carbohydrates, sugars,
  fibre, protein, salt, and sodium.

### Target phases

A meal plan can assign target profiles in dated phases, each having a start
date and an optional end date. Historical comparisons use the target phase
that applied on the date of consumption rather than today's current target.

The product compares planned and consumed nutrition with the applicable daily
targets without implying that user-entered targets constitute medical advice.

## Recipe import

The intended product supports importing from:

- A webpage URL.
- Pasted recipe text.
- An uploaded document.
- A photograph or scan.

All imported recipes begin as private drafts. The user reviews the title,
servings, ingredient parsing, food matches, instructions, source attribution,
and nutrition before publishing. Drafts cannot be placed in meal plans.

Imported recipes retain source provenance. When available, the finalized
recipe displays the original source and a link to it. A remix continues to
link back through its recipe lineage.

Documents and photographs uploaded for extraction are transient inputs and
are deleted after processing. They are not kept as recipe attachments.

DEC-005 fixes the non-OCR launch scope as local and deterministic. URL imports
support only public server-rendered HTML, try Schema.org Recipe JSON-LD first,
and fall back to safe visible-text parsing. Pasted plain text, Markdown, and
HTML are accepted as inert source text. Uploaded documents support only TXT,
Markdown, and HTML initially. PDF, DOCX, RTF, JavaScript-rendered pages,
authenticated pages, browser automation, external parsing providers, and
self-hosted language models are deferred; legacy, macro-enabled, and other
Office formats are unsupported initially.

DEC-006 fixes OCR as local-first through an application-owned extractor.
Tesseract 5 with pinned English data is primary. One JPEG/JPG, PNG, or
HEIC/HEIF still image is supported initially, up to 20 MiB compressed and
50 megapixels decoded; HEIC is converted locally to a metadata-free canonical
JPEG or PNG. WebP, TIFF, handwriting, supported non-English languages, and all
multi-page/PDF OCR are deferred. Google Document AI Enterprise OCR is the sole
optional managed fallback, disabled by default and restricted to EU processing.
It runs only after eligible local failure or no usable local text, never merely
for low confidence. Usable low-confidence or language-uncertain text creates a
warning-marked reviewable draft; no usable text explicitly fails extraction.

Text PDFs remain a normal document-extraction concern under DEC-005 and use
that route first when support is added. Image-only/scanned PDFs belong to
future DEC-006 OCR support. Mixed PDFs must avoid duplicate text by OCRing only
pages lacking usable normal extraction. Machine-readable documents are not OCR
formats. Terminal sources are deleted immediately where possible and within
24 hours; abandoned inputs expire after seven days.

The complete fetched webpage and uploaded file are transient. The import keeps
the exact pasted or extracted recipe source text and source provenance for
review, while parsed fields remain suggestions. Useful partial extraction may
create a warning-marked private draft; input with no credible recipe structure
does not create recipe content. Launch recipe source data is not transmitted to
an external parsing provider.

DEC-007 defines `reviewable`, `reviewable_with_strong_warnings`, and `failed`
as the only user-facing outcomes. A draft may be created when meaningful
recipe content and at least one reliable ingredient or instruction side are
recoverable; a missing core side requires strong warnings. Extraction fails
without a draft when neither side can be separated, no useful recipe content
exists, or multiple plausible recipes have no deterministic winner.

Provider confidence maps to `reliable`, `uncertain`, `unreliable`, or
`unavailable` through provider-specific, versioned rules. It is an attention
signal rather than verification and is not averaged across fields. Uncertain
quantity and unit best guesses may populate highlighted inputs while original
wording remains visible; unreliable suggestions stay blank. Low-confidence OCR
wording remains in a clearly labelled recovered-text area.

Missing title and servings never cause extraction failure. A missing title uses
a visibly marked replaceable placeholder. Missing, non-numeric, ranged, or
contradictory yield preserves original wording while structured servings remain
empty. Nutrition remains independent of import quality. Strong warnings and
field highlighting use accessible text and semantics and never rely on color
alone.

## Privacy, export, deletion, and retention

### Privacy defaults

- Finalized recipes default to public but can be private.
- Recipe drafts are private.
- Meal plans, diary data, targets, custom items, and personal organisation are
  private unless the owner explicitly shares the applicable plan.
- Public content is intentionally viewable without an account.
- Private recipe access granted through a selected-user plan share is scoped
  to that share and clearly disclosed to the plan owner.

### Data export

Users can request a self-service export of data belonging to their account,
including their recipes, meal plans, diary history, targets, and account data.
An export must not include another user's private account data merely because
the requester can view shared content.

### Account deletion

- The deletion flow clearly explains a 30-day recovery period.
- During that period, deletion can be reversed through an appropriately secure
  recovery mechanism.
- After 30 days, private recipes, non-public plans, diary entries, targets,
  personal organisation, one-off items, and pending submissions belonging to
  the account are permanently removed.
- Public recipes remain available under anonymized former-user attribution.
- Approved shared-catalogue contributions remain in the catalogue with the
  submitting-user reference anonymized or removed.
- Independent remixes and plan copies owned by other users are unaffected.

The implementation must support applicable GDPR rights and must document the
actual retention and erasure behavior. Product wording must not claim that
technical design alone guarantees legal compliance. Security, audit, backup,
and legally required retention details must be reviewed before production
launch.

## Moderation and audit requirements

The system needs an auditable history for:

- Catalogue imports and their source versions.
- Proposed manual catalogue foods.
- Proposed catalogue corrections.
- Administrator decisions.
- Recipe nutrition overrides.
- Recipe versions and remix lineage.
- Plan-entry nutrition snapshots.
- Account anonymization affecting public contributions.

Audit data must contain only the personal data necessary for its stated
purpose and follow the documented retention policy.

## Deferred design and policy decisions

The following details are intentionally not fixed by this specification:

- Exact match-score thresholds and the visual treatment of review warnings.
  Decision: DEC-001, DEC-002.
- The data-export file format.
  Decision: DEC-008.
- Administrator assignment, escalation, and moderation service-level rules.
  Decision: DEC-009, DEC-010.
- De-duplication and merge rules for rare manual non-barcode catalogue records.
  Decision: DEC-011.
- Backup erasure timing and any narrowly required security or legal audit
  retention within the GDPR-aligned deletion policy.
  Decision: DEC-012, DEC-013.
- Whether a public meal plan remains anonymized or is removed when its owner
  deletes their account.
  Decision: DEC-014.

These decisions must be made before implementing the affected behavior and
must not be silently inferred from the current ingredient catalogue.

## Future scope

- Multi-person and family meal plans with participant-specific servings and
  targets.
- Collaborative recipe or meal-plan editing. The current product is strictly
  single-writer.

## Relationship to the current repository

The current application implements authentication, profile management, theme
selection, and a user-owned ingredient catalogue with an OpenFoodFacts barcode
lookup. It does not yet implement recipes, recipe versions, structured recipe
lines, shared catalogue moderation, meal plans, diary snapshots, targets,
imports, bookmarks, remixes, or the privacy model in this specification.

The existing `Ingredient` record combines ownership, package information,
serving information, and embedded nutrition in ways that do not represent the
finished domain. Future implementation should evolve deliberately from the
documented current state; this specification does not authorize destructive
migrations or removal of existing user data.
