# Catalogue correction proposals

## Scope and eligibility

NUT-10 lets any authenticated user propose factual changes to an active,
approved shared catalogue item. Approved manual and OpenFoodFacts-backed items
are eligible. Pending, rejected, merged/redirected, and versionless records are
not. The current UI resolves catalogue redirects before offering the proposal
entry point.

Corrections may change the display name, brand, manufacturer, the complete
NUT-04 package/serving structure, and an allowlisted supported nutrient+basis
observation. Barcode identity, catalogue/provider IDs, origin/source identity,
submitter provenance, lifecycle/moderation state, current-version pointers,
aliases/redirects, image/keyword/category metadata, manual classification,
audit fields, and administrator notes are never proposal fields. Barcode,
duplicate, merge, image-upload, withdrawal, editing, voting, and partial
acceptance workflows are intentionally outside NUT-10.

## Evidence model and validation

A `CatalogueCorrectionProposal` identifies the target item, the exact base
version, a nullable proposer account reference, a required trimmed private
reason of at most 500 characters, submission/decision times, and one of
`pending|accepted|rejected`. Account deletion nulls the proposer reference
without deleting the proposal or making it unreviewable.

Each immutable `CatalogueCorrectionChange` is one allowlisted typed field.
It stores bounded JSON for that field's base value and proposed value, not an
arbitrary model snapshot or attribute name. Null payload means an intentional
clear; absence means unchanged; a decimal string of zero remains known zero.
Nutrition payloads retain nutrient, basis, value/threshold, unit, status and
source scale. Domain-aware decimal equality rejects numeric no-ops without
turning display rounding into storage rounding.

The target must still be active and approved, and the supplied base version must
belong to it. Name rules reuse `CatalogueName`; package/serving values reuse
`PackageStructure`; nutrition observations pass the same
`CatalogueNutritionNormalizer` validation used for canonical writes. Proposal
creation stores evidence and a minimized audit event atomically, and never
creates or selects a catalogue version.

## Moderation and staleness

The correction queue is a third work type under `/admin/catalogue`. It uses
the existing `moderate-catalogue` ability and NUT-09/FND-13 privileged
authorization boundary. Review displays base, current and proposed values for
every change and labels each field as unchanged or changed since base without
depending on color.

A proposal is stale exactly when its base-version ID differs from the locked
current-version ID. A field conflicts when the current typed value materially
differs from its stored base value. An unrelated current change therefore does
not create a false field conflict, while a removed or structurally changed fact
does. Every stale acceptance, including one with no conflicting proposed field,
requires an explicit server-validated stale-review confirmation. There is no
blind or automatic rebase.

Acceptance and rejection are whole-proposal decisions. Acceptance locks the
proposal, item and current version, rechecks pending/approved/current state,
creates one immutable version from the current version, applies only proposed
fields, normalizes package/nutrition again, selects the new version, appends the
decision/audit event and marks the proposal accepted in one transaction.
Unrelated current changes carry forward. The base and every historical
nutrition/package fact remain unchanged, so pinned recipe matches and published
recipe snapshots remain on their old versions.

After the new version commits as current, NUT-18 records and dispatches one
recalculation operation only for recipe versions whose current
ingredient-estimate trace depends on an older version of that catalogue item.
The recalculated live estimate may use the newly approved facts; the immutable
recipe snapshot and its pinned match remain unchanged.

Corrected versions retain proposal/decision references and an allowlisted
corrected-field list. Corrected nutrient observations/facts use `corrected`
provenance; energy counterparts remain `derived` under the kcal-authoritative
NUT-05 rule. Unchanged observations preserve imported, manually submitted,
corrected, source precision and provider provenance. Private reason text never
enters version provenance.

Rejection appends a bounded decision/audit event and marks the proposal rejected
without creating a version or moving the current pointer. Row locks, pending
revalidation, one unique decision per proposal and terminal-state reuse prevent
accept/accept, accept/reject and retry duplication. A concurrent catalogue
advance makes the proposal stale and requires a fresh explicit review.

## Privacy, serialization, and operations

Proposal reason, proposer ID, moderator identity/note, decision evidence and
internal proposal IDs are private. Public catalogue resources and search
serialization are unchanged. Accepted factual values appear only through the
normal current-version projection; rejected proposals change no public output.
Generic audit payloads contain only bounded proposal/item/base/current/new
version/decision references and outcome. They exclude reason, notes,
before/after payloads, nutrition panels, emails and names.

No cache or external search index currently exists. Acceptance therefore relies
on the existing current-version query path and needs no additional invalidation.
NUT-10 introduces no queued or scheduled work.
