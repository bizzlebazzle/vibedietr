# DEP-08 lifecycle pre-implementation audit

## Scope and status

Read-only policy/schema/code audit on 18 October 2026, followed by this
documentation record. DEP-08 remains P2, High risk, Large size and unimplemented.
This is planning evidence, not verification of a functioning deletion lifecycle.
Existing decisions and the documents linked below remain authoritative; the
unanswered questions in this record must not be implemented by inference.

## Owner-approved preferred implementation

The product owner approved the following recommendation in this chat on
18 October 2026:

- Delete unreferenced superseded recipe versions at final account purge.
- Preserve independently owned snapshots, remixes and copies.
- Retain source-version data only where an approved dependency requires it,
  with the minimum necessary content and anonymized attribution.

This preference addresses the explicitly unspecified version-retention boundary
in the [privacy matrix](AUTHORIZATION_PRIVACY_MATRIX.md#5-matrix). It does not
authorize retaining private source content merely because an opaque reference
exists, exposing historical versions through a new public route, or extending
audit/backup retention. The precise dependency/field mapping remains part of
implementation review; any additional policy choice must return to the owner.

Relevant implementation evidence:

- [Recipe versions](DOMAIN_MODEL.md#finalized-recipe-version) are immutable
  product content; current parent deletion cascades to them.
- [Remix lineage](DOMAIN_MODEL.md#recipe-remix-lineage) deliberately has no
  source recipe/version foreign keys. The independent remix contains its own
  content. Its attribution presenter currently looks up the historical source
  version and otherwise renders an unavailable-source tombstone.
- `meal_plan_recipe_entries` pins opaque source recipe/version references
  without source foreign keys and stores independent recipe/nutrition snapshots.
  Plan copying recreates those snapshots under the recipient's ownership.

Record the final reconciled version rule in the decision register and affected
domain/privacy documentation before implementing the dependent purge behavior.

## Further owner questions

### Q1: Bookmarks owned by an account in recovery

The public-plan bookmark row in the
[privacy matrix](AUTHORIZATION_PRIVACY_MATRIX.md#5-matrix) explicitly leaves
whether these bookmarks remain active unspecified.
[DEC-014](DECISIONS.md#dec-014--public-meal-plans-after-owner-deletion) requires
an active other-user bookmark to retain a plan, and deletion after the final
qualifying bookmark is removed. Current `MealPlanBookmarkManager` counts stored
bookmark rows and has no recovery-account state to consult.

**Question:** Does an existing bookmark continue qualifying throughout its
owner's unwaived recovery period, ending only on explicit removal or final
purge?

**Recommendation:** Yes. Preserve it during recovery; remove it at final purge
and apply last-bookmark cleanup then. This avoids a recoverable deletion of the
bookmark owner causing premature permanent loss of someone else's retained plan.
Waiver/under-13 final purge still removes that account's bookmarks without a
recovery extension.

### Q2: Pending manual shared-catalogue submissions

The [product specification](PRODUCT_SPEC.md#account-deletion) says pending
submissions belonging to the account are permanently removed. In contrast,
the pending-manual-addition row of the
[privacy matrix](AUTHORIZATION_PRIVACY_MATRIX.md#5-matrix) and the
[domain model](DOMAIN_MODEL.md#pending-manual-catalogue-submission) explicitly
preserve the pending shared identity and null submitter provenance.
[Catalogue moderation](CATALOGUE_MODERATION.md#schema-privacy-and-operations)
preserves nullable submitter behavior and uses restrictive history constraints.

**Question:** Does the deletion promise exclude application-owned pending
shared-catalogue identities, so their factual records survive without the
submitter link and retain their existing restricted visibility?

**Recommendation:** Yes; clarify the general product wording accordingly.
Remove account-identifying/private submission material unless independently
covered by the approved purpose-specific schedule or a scoped hold. Do not
publish, merge or transfer pending identities as a side effect of deletion.

### Q3: Pending catalogue correction proposals

The correction-proposal row of the
[privacy matrix](AUTHORIZATION_PRIVACY_MATRIX.md#5-matrix) requires pending
owner proposals to be removed after recovery. Conversely,
[the correction contract](CATALOGUE_CORRECTIONS.md#evidence-model-and-validation)
and the [domain model](DOMAIN_MODEL.md#nut-10-catalogue-correction-proposal)
say deletion nulls the proposer reference without deleting the proposal.
Accepted versions retain proposal/decision references, so a blanket proposal
cascade would also destroy independent shared-catalogue provenance.

**Question:** Should final purge remove pending user correction proposals,
while accepted catalogue facts and only the decision/provenance evidence
permitted by DEC-013 survive without the contributor identity?

**Recommendation:** Yes; align the correction contract/domain description with
the matrix. Do not retain private proposal reasons merely to preserve factual
catalogue provenance. Rejected/decided evidence follows the existing
[purpose-specific schedule](AUDIT_RETENTION_SCHEDULE.md#5-approved-retention-schedule),
not a new retention period introduced by DEP-08.

## Owned-domain and linked-resource inventory

Cross-checked the privacy matrix against all migrations' user references,
`app/Models`, the DEP-07 `AccountExportProjection`, and relevant lifecycle,
bookmark/copy, attribution, import-cleanup and audit-erasure code. Rows group
child models by their ownership boundary; shared evidence is not user-owned
merely because it contains an account reference.

| Domain or linked resource | Required lifecycle treatment / audit finding |
| --- | --- |
| Account, private profile and public profile | Disable for recovery; purge identity/profile, credentials, reset state, sessions and remembered login. Remove public profile linkage. |
| Administrator lifecycle, pending promotions, factors/enrollments/recovery material, notification destinations/intents | Preserve replacement-first locking and centralized privilege services; remove account security material, reconcile pending operations, and retain only scheduled independent evidence. Promotion target references currently restrict deletion and need explicit lifecycle cleanup. Bootstrap marker survives. |
| Recipe drafts, revisions and mutable children | Purge private drafts/revisions, ingredient lines/matches and instructions; never preserve mutable private revision content through a retained public recipe. |
| Finalized recipes, versions, overrides and recalculation history | Retain qualifying public content anonymized; remove private content. Apply approved historical-version preference above and verify referenced content fields. Under-13 source public content is removed. |
| Remix lineage and independently owned remixes | Source creator mapping is nullable; source identifiers do not confer ownership/access. Preserve other owners' content and minimized lineage. |
| Recipe bookmarks, collections, private tags and membership pivots | Purge deleting owner's private organization. Other owners' bookmarks remain pointers/tombstones; source cleanup cannot cascade into their organization. |
| Public tags, managed vocabularies and suggestions | Tags follow retained/deleted recipes. Application vocabulary survives. Purge owner-private submission material; surviving approved vocabulary/evidence loses unnecessary contributor attribution. |
| Legacy ingredients and migration mappings | Remove owner-private legacy rows; preserve independent shared catalogue/migration evidence while erasing identifying owner references, including any nested copied identity. |
| Shared catalogue identities/versions, packages, servings, observations/facts, aliases, duplicates, moderation, corrections, refreshes and reference moves | Shared facts are not source-user-owned. Null/anonymize submitter, proposer, selector and moderator references according to purpose. Pending identity/proposal conflicts require Q2/Q3. Private explanations and migration snapshots require field-level minimization. |
| Meal plans, days, slots, pinned recipe/catalogue snapshots, one-off entries and recipe-version reviews | Private/unavailable source plans purge; qualifying public plans transition immediately under DEC-014. Keep only proven public-safe presentation data; delete consumed state, private one-offs, targets and recovery attribution. Independent copies survive. |
| Plan shares, public links and bookmarks | Disable non-public access during recovery. Retained URLs remain stable/unlisted and closed to new bookmarks. Last qualifying bookmark removes the retained plan. Q1 defines recovery-account qualification. |
| Diary entries, consumption states/transitions and nutrition snapshots | Owner-private history purges, including ad-hoc and plan-derived content; no leakage through retained plans. |
| Nutrition targets, profiles, dated phases and phase-value snapshots | Owner-private data purges; cannot survive in retained public-plan graphs. |
| Recipe imports, retained private source provenance and transient source/canonical objects | Restrict/cancel processing; remove private provenance and objects under their existing clocks and final purge. Failed/interrupted work must not recreate them. Keep only permitted minimized operational metadata for its original clock. |
| Account export requests, archives, credentials and worker staging | Restrict delivery/processing; remove on final purge or earlier original expiry, with verified object/staging cleanup. Export references are nullable today so cleanup can survive missing owners. |
| Audit identities/events, activity projections, operational queue/cache/job state and scoped evidence | Destroy ordinary identity mappings and private activity; separately handle privileged accountability and scoped evidence under DEC-013. Sanitize queued/failed state and reauthorize restored work; technical IDs do not justify indefinite retention. |
| Independent journal, private-object inventories and all recoverable copies | DEC-012 two-store durable acknowledgements, original-clock replay, independent object filtering, verified all-copy retirement before journal references/generations/keys retire, and fail-closed target release. These are independent protection obligations, not ordinary account-owned rows. |

Weight/progress and dietary-constraint records are explicitly absent from the
current matrix/model scope. No deletion behavior is being invented for them.

## Dependencies and implementation gates

The required `./vendor/bin/sail npm run context:task -- DEP-08` succeeded.

- DEC-012 is Decided, with no unresolved reference-stack policy question.
- FND-05's audit store/erasable identity foundation exists in code and tests;
  roadmap completion status is unrecorded. General retention execution and
  protected evidence/hold mechanisms remain implementation work.
- FND-09, FND-14, DEP-04 and DEP-05 are recorded complete. New jobs must still
  satisfy conventions, inventory, correlation, replay and monitoring contracts.
- DEP-06 is conditionally complete; repository capture/quarantine/expiry tooling
  and synthetic drill evidence exist. Its verification record explicitly
  delegates journals, purge remediation, disposal and service release to DEP-08.
- DEP-07 is conditionally complete with implementation/local verification
  recorded. Its nullable export cleanup boundary must be integrated with purge.
- DEP-06/DEP-07 installation checks and remote CI remain release gates. They
  are not additional choices about account retention policy.

No additional policy question was identified in the remaining audited domains.
Secure recovery details, journal reconciliation, schema changes, public-safe
field projections, operator-only enforcement and idempotent queue coordination
still require design and tests against the existing approved constraints.
An implementation finding that changes those constraints must return to the
owner rather than use this audit as blanket approval.

## Verification for this record

No application behavior, schema or user data was changed. No lifecycle or
restore/purge verification is claimed. Documentation checks and final diff
review apply to this record; application tests, formatting/static analysis,
frontend/browser tests and the destructive synthetic restore drill are not
applicable to these Markdown-only changes. Remote CI remains required before
merge. `CURRENT_STATE.md` was checked at 219 lines including blank lines.
