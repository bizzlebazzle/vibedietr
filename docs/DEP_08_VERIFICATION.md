# DEP-08 verification

## Status and scope

DEP-08 remains **P2**, High risk and Large size. The repository implementation
and local verification are complete. Overall release status is conditionally
complete pending the installation, remote CI and manual checks below.

DEC-009, DEC-012, DEC-013, DEC-014, DEC-021 and DEC-022 govern the implemented
lifecycle. The [owned-domain/dependency audit](DEP_08_LIFECYCLE_AUDIT.md) was
completed before implementation using the documented task-context command.
FND-09, FND-14, DEP-04 and DEP-05 are recorded complete. FND-05's audit
foundation exists and is reused, although its roadmap has no formal completion
status. DEP-06 and DEP-07 remain conditional on their installation checks;
their implemented recovery/export boundaries are integrated here. DEC-010's
remaining legal-launch decision does not authorize additional DEP-08 behavior.

## Lifecycle and domain coverage

Focused tests are under `tests/Feature/AccountDeletion`; the full backend gate
also covers the existing administrator, catalogue, recipe, planning and export
workflows. The inventory includes child models within each ownership boundary.

| Domain | Implemented behavior and verification |
| --- | --- |
| Account/profile/authentication | Inactive immediately after permitted request; password/enrolled-factor recovery and irreversible waiver; original deadline boundaries; sessions/reset/remembered login invalidated; public profile links disappear. |
| Administrator lifecycle/security | Replacement already active before deletion; sole-administrator denial; centralized privilege/readiness services; promotion, factors, recovery material and notification cleanup; scoped accountability retention. |
| Private recipes/revisions/children | Private source-owned graphs deleted; mutable private revisions cannot survive through a public parent. |
| Public recipes/versions/nutrition | Anonymous current public presentation; private overrides/history removed; only approved minimal source-version lineage survives; under-13 public sources removed. |
| Remixes, copies and snapshots | Independently owned copies retain their own lifecycle, pinned content and nutrition; retained plans remain privately copyable. |
| Collections/tags/bookmarks/legacy ingredients | Owner-private organization removed; final-bookmark behavior applied when the bookmark owner purges; legacy copied identity fields minimized. |
| Shared catalogue/moderation/corrections | Shared factual identities/versions preserved without contributor links; pending corrections removed; accepted facts retain approved anonymous provenance; private explanations erased. |
| Plans/days/slots/shares/reviews | Safe bookmarked public plans become anonymous, unlisted, stable and closed to new bookmarks immediately; unsafe/zero-bookmark plans unavailable; final bookmark permanently removes retained plan. |
| Retained-plan administration | Credential/factor-authorized suppression/removal only; restore, reattribution, transfer and recovery extension denied. |
| Diary/consumption/nutrition history | Private graphs purge; DEC-022 owner-only encrypted archive survives recovery after permanent public-plan removal, without recreating that plan; export includes the archive. |
| Targets/profiles/phases | Private targets and historical snapshots removed at final purge; excluded from retained public presentation. |
| Imports/OCR/transient files | Inactive processing denied; original-clock file cleanup and processing-only minimization; recovery cannot requeue expired work. DEC-005/006 exact private review text, approved provenance and bounded retry controls survive until their own deletion/final purge. |
| Exports/staging/downloads | Inactive delivery/processing denied; original expiry preserved; verified archive/staging cleanup before SQL purge; stale restored downloads/work cannot resume. |
| Audit/operational state/scoped holds | Purpose-specific calendar clocks, ordinary identity erasure, protected operator mappings, private-event removal and anonymous receipts; held evidence copied outside app backups under named operator scope, without extending recovery. |
| Journal/copies/restore state | Two-store protected acknowledgements, partial-write repair, current independent checkpoint, completed/incomplete replay, independent object filtering, verified copy retirement, fresh journal generations and signed fail-closed release. |

Unknown snapshot fields fail closed. Public snapshots retain only approved
presentation data. Private review text is durable domain content rather than
a transient source file; it remains in encrypted database recovery sets.

## Actual restore, purge and disposal verification

`bash scripts/recovery/drill.sh` passed on 22 October 2026 in **140 seconds**
using disposable MySQL 8.4, Garage, restic and two separately running journal
stores. No production data or credentials were used.

| Restore scope | Actual verification |
| --- | --- |
| Full paired | Two fresh quarantined targets; all-table/FK reconciliation; due, incomplete and completed purge replay; recovery cancellation; under-13 removal; retained plans/private archives; independent copies/remixes; repeated replay. |
| Database-only | Re-imported the original SQL into a quarantined target with sanitized objects; replayed current journal truth and invalidated restored operational state. |
| Object-only | Restored filtered objects into a new empty private bucket after erased SQL owners were absent; verified checksums and that SQL contents were unchanged. |
| Selective | Reintroduced an erased account and private recipe only; replay removed both, preserved the independent remix/copy, and created no fresh purge receipt. |

All probes use the original capture and erasure clocks. Partial remediation
does not grant release: a new target identity invalidates the earlier
certificate, and coherent set verification plus frozen final sign-off remains
required. The full target was denied release for missing credentials or
certificates, a deadline crossed after preparation, and SQL writes reverted
to identical values. Positive signed release and journal mirror host-loss
repair also passed.

The drill verifies exact durable import review text survives capture and
sanitization. Its DEP-06 baseline reconciles 81 tables and 120 FK columns.
Actual retired restic blobs were forgotten/pruned with zero tolerated unused
data, retained data was read-checked, and restoring the retired ID failed.

The synthetic LUKS2 probe writes/reads real journal SQL through a mapped
encrypted image. Current keyslot erasure denies the old key; an old header
backup demonstrates why header copies must also retire. A separate encrypted
header generation and its keys are then retired. This proves the primitive,
not production host/swap/snapshot disposal or physical media overwrite.

Copy/generation tests separately verify signed complete inventory, inherited
original clocks, dirty-capture incidents, retained truth while any affected
copy remains, and denial of cutover until old stores, keys, headers and
recovery credentials are verified retired.

## In-flight object verification

Focused regression coverage additionally interleaves deletion with image
preprocessing: the delayed job cannot write a canonical object after its owner
and import disappear. Failed writes keep one durable canonical reservation
across retries. Final purge waits for live processing leases without extending
E, and claimed successful source/export deletion cannot acknowledge completion
while an object still exists. Terminal outcome clocks remain original.

## Local migration preservation

The six reviewed additive DEP-08 migrations were applied to the local
development database. Before/after comparisons verified every original row
value across **74 pre-existing tables** was unchanged. Seven new tables were
empty, and account/recipe/plan generations were unique and fully backfilled.
No local user account was purged. The verifier kept digests in memory and
was removed after completion.

## Final quality gates

| Check | Result |
| --- | --- |
| Full Sail backend suite | Passed: 1,185 tests, 7,509 assertions, 299.17 seconds via the canonical `./vendor/bin/sail composer test`. |
| `./vendor/bin/sail pint --test` | Passed, 829 PHP files. |
| `./vendor/bin/sail composer analyse` | Passed. |
| `./vendor/bin/sail composer analyse:failure-regression` | Passed; deliberately invalid fixture produced the expected nonzero analysis result. |
| `./vendor/bin/sail npm run build` | Passed. |
| `./vendor/bin/sail npm run env:check` and example Compose parsing | Passed. |
| `./vendor/bin/sail npm run docs:check` and regenerated context index | Passed; final task context resolves 87 tasks and 22 decisions. |
| `./vendor/bin/sail npm run docs:test` | Passed. |
| `./vendor/bin/sail npm run test:recovery` | Passed, 5 tests. |
| `./vendor/bin/sail npm run test:scanner` | Passed, 24 tests. |
| `./vendor/bin/sail npm run test:planning` | Passed, 6 real Chromium tests. |
| `./vendor/bin/sail npm run test:accessibility` | Passed, 16 real Chromium tests, including keyboard deletion/recovery, invalid credentials, mobile light/dark, axe and unchanged pixel baselines. |
| Representative production configuration | Ordinary and protected-operator uncached checks, `config:cache`, and cached readiness passed in network-isolated containers with synthetic settings; operator web bootstrap was rejected. |
| Final diff/secrets review and CURRENT_STATE line count | Passed; no generated/private artifacts or credential-pattern candidates added; protected tests and released 1.0 export schema unchanged; CURRENT_STATE is 219 lines including blanks. |

Browser screenshots/axe evidence are in the ignored local
`storage/app/testing/ux07` and `storage/app/testing/ux05` directories. Mobile
recovery in both themes and desktop validation feedback were visually
inspected. The original upload/security tests were left unchanged. The extended
backend runner uses the Sail user explicitly to avoid root-owned generated
fixtures.

The initial full backend run exposed seven affected legacy/fixture failures.
Focused correction verified 98 tests. A subsequent full run hit Composer's
300-second runner limit without a reported test failure. The canonical script
now disables only Composer's process-wrapper timeout; assertions, PHPUnit and
application/job limits remain unchanged. Final execution used Sail's normal
user and completed successfully.

## Remaining installation and manual checks

- Provision protected production journal/evidence stores and key custody;
  verify TLS, actual independent failure domains, fsync/power-loss behavior
  and operator/observer role separation with the installation's credentials.
- Inventory every data/journal/key/header/credential/host/swap/staging copy;
  verify actual seven-day aging, daily capture/expiry monitoring, affected-copy
  retirement by the approved target, and fresh encrypted-generation cutover.
  The local probes do not attest disposal of a production installation.
- Exercise installation-specific full/partial recovery assembly, original
  clocks, object ACLs, all-writer fencing and final ingress/worker cutover;
  accept the measured installation recovery objectives.
- Complete live administrator destination/provider/worker/clock/capacity/audit
  readiness and protected legal-case/controller approval processes. DEP-09
  remains the legal-launch review; no legal period or authority is invented.
- Run real assistive-technology/manual checks that headless Chromium cannot
  establish. Physical-camera verification is not part of this lifecycle change.
- Obtain the four remote CI statuses before merge. No branch protection,
  deployment or production release is claimed by local checks.

## Commits

The following implementation commits are on `codex/dep-08-lifecycle-audit`.
The final documentation commit is reported at handoff.

| Commit | Unit |
| --- | --- |
| `6c8ec6b` | docs: record DEP-08 lifecycle preference and policy audit |
| `2fd335e` | docs: approve DEP-08 account purge resource boundaries |
| `360bf29` | feat: add protected replicated erasure journal foundation |
| `201da45` | fix: validate erasure journal keys and calendar deadlines |
| `1032a41` | feat: add recoverable account lifecycle and inactive-account boundaries |
| `d00ec46` | docs: preserve private history after retained-plan removal |
| `7bcdfa6` | feat: purge owned account resources and preserve private consumption archives |
| `5b71419` | feat: integrate authenticated account recovery and scheduled purge workflows |
| `1c39f8b` | feat: minimize owned-domain evidence and enforce original audit retention clocks |
| `7eff7ff` | feat: isolate scoped erasure evidence behind the privacy operator role |
| `0c959ab` | feat: protect retained snapshots and validate complete replay intent |
| `27bfeb5` | feat: replay original-clock purges and filter independently owned objects |
| `4ced7cf` | feat: enforce verified quarantined restore release and exercise real privacy replay |
| `ebc1fe0` | feat: gate journal minimization on verified copy and storage-generation retirement |
| `e67eb65` | fix: enforce original-clock import source and metadata retention |
| `33b6e41` | fix: exclude transient SQL import sources from paired recovery sets |
| `d331f38` | test: exercise account deletion and recovery in accessible browser flows |
| `b989d0b` | fix: reconcile original import retention before restore release |
| `426aa6f` | feat: require protected erasure configuration in production readiness |
| `d8a7fcb` | test: align lifecycle regressions and isolate audit rollback fixtures |
| `030643a` | fix: preserve durable import review content across cleanup and recovery |
| `f68a580` | test: verify database object and selective erasure restore paths |
| `97800e5` | fix: fence in-flight canonical writes and verify physical purge cleanup |
| `4eee94f` | fix: confine case evidence and signing secrets to operator environments |
| `3441dd9` | chore: allow complete backend suite to finish in canonical runner |

Commit `030643a` supersedes the earlier SQL-source exclusion: final capture
preserves DEC-005/006 durable private review content.
