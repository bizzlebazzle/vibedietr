# Catalogue moderation

## Scope and entry point

NUT-09 implements DEC-011 for manual non-barcode catalogue identities at
`/admin/catalogue`. It does not merge barcode identities, adopt source nutrition
facts, refresh providers, or implement NUT-10 factual correction proposals.

The queue offers two types. Manual submissions filter by `pending`, `approved`,
`rejected`, or `merged`. Duplicate candidates filter by `pending_review`,
`confirmed_distinct`, `confirmed_duplicate`, or `dismissed`. State/type validation
rejects invalid combinations. SQL applies filters and counts before stable
ascending-ID pagination of 25 rows. Eager loading supplies current names and
candidate identities. Candidate detail shows applied merge state separately
from the candidate outcome; per-record decision history includes corrections.
Administrator pages have private, no-store responses.

## Authorization

The central `moderate-catalogue` gate delegates to the existing current-database
`access-admin` capability. Every mutation invokes
`CatalogueModerationAuthorization` inside its transaction as well as before it.
The actor row is locked, authority is rechecked, and FND-13 verifies confirmed
factor, verified destination, recent password authentication and a fresh
single-use `catalogue-moderation` proof. Successful decisions consume the proof.
Production calls also pass `ProductionSecurityReadiness`; local/fake delivery
never qualifies as production readiness.

Use the review page's password-confirmation link and authenticator form before
each action. Verification goes through the existing second-factor controller;
moderation never receives, queues or audits submitted authenticator values.
Administrator authority grants no general edit access to another user's recipe.
Reference movement is the narrow DEC-011 identity operation only.

## Review and decisions

Inspect both current versions, identity attributes, nutrition basis, source and
private candidate explanation before deciding. Names, aliases and similar
nutrients alone do not establish identity. Stored material core contradictions
and disjoint known nutrition bases prevent duplicate confirmation/merge.
Missing facts require moderator review and cannot become automatic proof.

The service exposes separate operations:

- Approve a pending manual item as submitted. Identity, version and submitter
  provenance remain; the item becomes discoverable/selectable under NUT-03.
- Reject a pending item as a retained non-selectable tombstone. An optional
  approved replacement is a suggestion only; duplicate rejection requires one.
  Existing recipe references remain attached and require owner confirmation or
  clearing. Rejection does not merge anything.
- Mark a pending-review pair distinct. No identity or recipe changes.
- Dismiss a pending-review pair without asserting distinctness. No merge.
- Confirm two approved manual identities as duplicate and explicitly select one
  pair member as canonical. No default canonical radio choice is provided.
- Apply that confirmed merge after a separate confirmation. The canonical
  choice and both reviewed current versions must still agree with the earlier
  duplicate decision.
- Correct an eligible earlier decision by appending a new linked decision.

Pair ordering is centralized in `CatalogueCandidateRecorder`: lower identity ID
first, higher second. MySQL's unique pair index and order check reject reversed,
repeated and self-pairs. Locking current reads reuse the existing pair after
concurrent insertion; trusted candidate creation gets its own minimized event.
Fuzzy evidence cannot create a candidate through this recorder.

Reasons are selected from the bounded codes `reviewed`, `duplicate`, `distinct`,
`insufficient_evidence`, and `incorrect_decision`. Optional plain-text notes are
bounded to 500 characters, rendered escaped, admin-only, and excluded from audit
metadata. Candidate distinction explanations remain private.

## Merge transaction and limits

`CatalogueModeration::mergeApproved` locks the pair identities in ascending ID
order, reloads the candidate, checks its monotonic revision and version tokens,
and verifies the original canonical confirmation. Only distinct approved
manual non-barcode items with current named versions qualify. Merged, pending,
rejected, self and conflicting target selections fail visibly.

A merge is synchronous and atomic: its immutable decision, source lifecycle,
direct target, live-reference changes, reference evidence, approved alias
copies, incoming redirect flattening and audit events commit together. It adds
no queued or scheduled job and no half-applied state. The immutable decision
whose action is `merge` is the authoritative merge operation record; the current
source/candidate state and linked correction describe its resulting state.

The request refuses more than 500 source recipe-match rows or 500 incoming
redirects. This bounds current synchronous work and includes retained finalized
match projections in the scan limit. Larger operations require separately
reviewed maintenance work; do not bypass the bound with raw SQL. No large-merge
queue or maintenance command is claimed to exist. Any future asynchronous
implementation must declare DEP-04/DEP-05, job inventory and operational coverage.

Current-state locking reads avoid a MySQL repeatable-read snapshot missing a
newly committed match. Recipe locks serialize finalization/revision changes;
match locks and state rechecks identify whether a row is still editable. Other
recipe mutations can take locks in a different order, so MySQL deadlocks retry
at most three times. Exhausted lock/uniqueness conflicts return a safe review
error. Stale forms never silently select a different outcome.

## Identity, versions and aliases

A merged source retains its own current-version pointer, every historical
version, nutrition observations, selected values, package/serving facts and
submitter/provider provenance. None are reparented, averaged or renumbered. The
canonical current version does not change because of identity consolidation.
No historical calculation or recipe snapshot is recomputed.

The source stores a direct pointer to the approved canonical identity. Merging
A into B and later B into C changes current pointers to A-to-C and B-to-C in the
same transaction. Direct current catalogue reads of A resolve C. Historical
version references continue identifying A and its original version. Merged
sources are excluded from discovery and new selection.

The source's approved primary name becomes a canonical searchable alias by
default, unless excluded at merge confirmation. Every other source alias needs
explicit selection. Copies identify their originating merge decision; an
existing enabled canonical alias is reused. A disabled alias with prior
correction history requires explicit exclusion/separate review, avoiding silent
reuse of historical provenance. Correction disables copies created by that
merge; search and duplicate detection exclude disabled aliases.

## Concrete reference inventory

| Reference | Classification | NUT-09 behavior |
| --- | --- | --- |
| `recipe_ingredient_line_matches` on draft recipes | Live | Move to the canonical current version; ledger records both identity/version pairs and the fresh change marker. |
| Same table on finalized recipes with an active `recipe_draft_revisions` row | Live editable working revision | Move the editable match only; published/base version JSON remains unchanged. |
| Same table on finalized recipes without an active revision | Historical working projection | Keep the original version reference. |
| `recipe_versions.snapshot.ingredients[*].catalogue_match` | Historical immutable snapshot | Never rewrite identity/version, wording or stored provenance. |
| `recipe_draft_revisions.base_recipe_version_id` | Historical recipe-version pointer | Unchanged; a newly started editable revision resolves merged matches when copying the base snapshot. |
| `legacy_ingredient_catalogue_mappings.catalogue_item_id` and legacy snapshot | Historical NUT-02/import evidence | Never migrate. |
| `catalogue_item_versions.catalogue_item_id` | Historical identity/version ownership | Never reparent. |
| `catalogue_nutrient_observations` and `catalogue_nutrient_values` version references | Historical facts/provenance | Never reparent or recompute. |
| `catalogue_items.current_catalogue_item_version_id` | Identity-owned current factual version | Unchanged on both source and canonical identities. |
| `catalogue_items.suggested_replacement_catalogue_item_id` | Historical moderation recommendation | Retain original ID; canonicalize only authorized current presentation/explicit acceptance. |
| Duplicate-candidate first/second identities | Historical pair evidence | Never migrate or reorder after creation. |
| Moderation decisions, correction links and reference ledger | Historical evidence | Append new decisions/moves only. |
| Audit subject/correlation identifiers and actor mappings | Historical minimized accountability | Append new events; keep FND-05 identity-erasure behavior. |
| Catalogue aliases | Approved discovery metadata | Copy only approved selected names; retain source aliases and copy provenance. |
| `catalogue_items.canonical_catalogue_item_id` | Current redirect projection | Flatten with explicit old/new target evidence. |
| Legacy `ingredients` and recipe/ingredient pivots | No direct catalogue FK | Unchanged; compatibility routes use the preserved import mapping/current read resolver. |
| Existing recipe bookmarks and remix lineage | No direct live catalogue pointer | Unchanged; they reference recipe identities/versions. Remixes currently do not copy catalogue matches. |
| Catalogue bookmarks, future-plan and diary entries, saved nutrition calculations | Future/deferred reference classes | No concrete rows currently exist; future implementation must declare semantics before use. |

The owner's 2026-09-09 clarification is recorded in DEC-011: if rejected R
suggests B and B later merges into C, R still stores B. Display and explicit
owner-confirmed replacement resolve C and revalidate its approved/selectable
current version. No merge changes R's suggestion or an attached rejected match.
Each rejection decision also retains its own suggestion as immutable evidence.

## Correction and restoration

A decision can have one direct correction. The original decision, actor mapping,
reason, note, time and evidence remain unchanged. Correcting distinct/dismissed/
duplicate outcomes reopens the candidate only if no later decision superseded it.
An eligible rejection correction reopens pending review while retaining its
historical recommendation. Reversing approval refuses an identity already in use
or changed since approval; it does not silently make broadly used data private.

Merge correction requires the source, canonical identity, current versions and
moderation revisions to still match the recorded operation. Subsequent catalogue
merges/changes fail closed for separate moderator review. This is deliberately
not an unconditional unmerge button.

Each recipe move records its resource ID, exact previous/new identity and
version, and a fresh post-move marker. Every later match save replaces that
marker, even if the owner chooses the same version. Only unchanged, still
editable rows restore automatically. Removed matches, later choices, and rows
that became historical are preserved; the moderator must explicitly confirm
this conflict policy. The correction records restored/preserved counts and new
reverse-movement evidence. Later owner choices never disappear under an unmerge.

Recorded flattened redirects restore only if still consistent with the original
operation. The source returns to approved with no redirect. Alias copies from
that merge become disabled. Published history needs no restoration because it
was never changed.

## Schema, privacy and operations

New foreign keys restrict deletion of decisions, versions and migration evidence.
Existing identity/version, alias and candidate cascades are replaced with
restrictive foreign keys. Submitter deletion retains its existing nullable
provenance behavior. Moderator attribution stores only FND-05's erasable mapping
ID, with no identifying snapshots. Application model APIs reject decision and
movement updates/deletes and catalogue identity deletion. Raw database privileges
remain an operational trust boundary, as for FND-05.

The migration adds tables/columns and preserves existing rows. Rollback refuses
retained moderation decisions or merged identities; use a reviewed forward
migration once history exists. Empty-feature rollback retains widened audit
values and restrictive provenance FKs rather than weakening historical integrity.

Indexes cover origin/state/ID queue browsing, candidate state/ID, unique sorted
pairs, canonical FK lookup, decision subject/candidate/time, and unique movement
identity. Current reads are database-backed; no separate catalogue cache requires
invalidation. Audit failure rolls the entire decision transaction back.

For a conflict, reload the review page and inspect its current versions/history.
For authentication refusal, obtain fresh password/factor verification. For
production readiness refusal, follow the existing administrator/security runbook.
Never resolve a failure by editing lifecycle or foreign keys directly, deleting
history, disabling audit, or replaying a generic SQL merge.

## Verification

Focused tests are in `tests/Feature/Catalogue/CatalogueModerationFoundationTest.php`,
`CatalogueModerationTest.php`, `CatalogueModerationQueueTest.php`, and
`CatalogueModerationConcurrencyTest.php`. They cover schema/pair invariants,
central authorization and proof consumption, filters/counts/privacy, lifecycle
transitions, explicit canonical choice, source/version preservation, aliases,
historical suggestions, live movements, correction, audit rollback and stale
state conflicts. NUT-03/NUT-08/recipe matching regressions also run.

Real independently bootstrapped PHP/MySQL worker processes test opposite-order
candidate insertion, two administrators choosing conflicting canonical identities,
and a recipe match committed after a merge's repeatable-read snapshot. Test
workers refuse non-testing environments/databases. Sequential stale-decision
checks supplement these tests; no claim is made that every possible production
interleaving or load profile has been exhaustively tested.

The Codex browser-control runtime failed to initialize with a Windows sandbox
setup error during this task. HTTP view/form/privacy assertions and the frontend
build are automated; interactive visual/keyboard inspection remains a verification
gap. Deployment readiness and remote CI results must be recorded separately from
local functional checks.
