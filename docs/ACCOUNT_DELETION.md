# Account deletion implementation

## Status

DEP-08 remains P2 and incomplete. DEC-021 records the four approved resource
boundaries; the [pre-implementation audit](DEP_08_LIFECYCLE_AUDIT.md) inventories
owned domains and earlier conflicts. The existing account-deletion UI still
performs immediate deletion pending the HTTP integration unit. Journal-backed
lifecycle services and inactive-account authorization are now implemented; the
complete purge and restore-release flow is not yet enabled.

## Independent journal foundation

`ProtectedErasureJournal` appends encrypted, HMAC-chained intent to two dedicated
stores before returning an acknowledgement. Each operation has a unique ULID;
the payload accepts only bounded installation/account-generation/correlation
references, original UTC deadline, policy, lifecycle state and opaque resource
references. It rejects extra fields and exposes only a constant failure code,
without SQL bindings or previous exceptions.

The primary serializes writers with a MySQL connection-scoped advisory lock.
Each store commits independently. If the mirror write fails, durable primary
intent remains unacknowledged; retry repairs its authenticated suffix before
acknowledging the same operation. Reusing an operation with different content,
a divergent history or a mirror-ahead state fails closed and needs reconciliation.
This is not a distributed transaction or proof of exactly-once domain mutation.

Read/replay requires an independently trusted checkpoint (generation, sequence,
digest), validates both complete chains, and denies mismatched, missing,
truncated or tampered evidence. The checkpoint must be current evidence outside
application restores. Matching old replicas and a checkpoint recovered alongside
the old application are insufficient. This primitive alone neither establishes
checkpoint freshness nor authorizes service release.

Configuration has no application-database fallback:

- `ERASURE_JOURNAL_PRIMARY_URL` and `ERASURE_JOURNAL_MIRROR_URL` configure
  `erasure_primary` and `erasure_mirror` MySQL connections.
- `ERASURE_JOURNAL_GENERATION` names a non-reusable protected storage generation.
- `ERASURE_JOURNAL_ENCRYPTION_KEY` and `ERASURE_JOURNAL_INTEGRITY_KEY` are
  separate base64-encoded 32-byte keys; neither may equal the application key.
- Outside tests, stores must be on differently configured hosts from each other
  and the app, use InnoDB and `innodb_flush_log_at_trx_commit=1`.
  Distinct host names do not prove distinct failure domains. Installation
  verification must cover actual hosts, disks, DNS aliases, disk flush behavior,
  TLS, access separation and key custody.
- `ErasureJournalSchema` provisions a dedicated store. It is not an application
  migration and no automatic provisioning or production lifecycle command is
  installed by this foundation.

These are secret deployment settings; do not copy connection URLs, keys, raw
journal content or linkable references into logs or ordinary audit payloads.
The independent stores are excluded from ordinary application recovery sets.
No row deletion/compaction API exists: deleting rows would break replay truth
without proving InnoDB page, redo/undo, host-copy or key disposal.

## Verification and remaining work

The focused journal protocol suite runs through Sail at
`tests/Feature/AccountDeletion`. It exercises separate SQLite stores and two
prefixed InnoDB stores in the disposable MySQL `testing` database. Fault
injection denies mirror insertion after primary commit, then verifies retry
repair. It covers encryption, immutable operation identity, original deadline
preservation, stale/truncated checkpoint evidence, tampering, missing replicas,
mirror-ahead history, invalid configuration and private-field rejection.

These tests establish protocol behavior, not independently durable hosts,
power-loss recovery, target release, generation/key retirement or completed
account deletion. DEP-08 still needs authenticated HTTP
request/recovery/waiver and confirmation flows,
retained-plan projection and recovery, every-domain purge and audit minimization,
idempotent scheduled queued work and operational coverage, scoped evidence/holds,
original-clock restore reconciliation, independent object suppression, frozen
release checkpoints, verified all-copy retirement, storage/key disposal and the
DEC-012/DEP-06 synthetic restore/privacy/disposal drill. Production installation
checks and remote CI remain separate required gates.

## Journal-unit check results: 18 October 2026

- Focused SQLite/MySQL protocol tests: 32 passed, 54 assertions, including
  rejection of raw application-key reuse and impossible calendar deadlines.
- Full backend suite: 1,091 passed, 12 failed (6,994 assertions). The run began
  before the two final edge-case tests were added; those four additional
  SQLite/MySQL cases passed in the focused run above. All 12 full-suite failures
  are existing uploaded-import/transient-input tests reporting permission denied
  on `storage/framework/testing/disks/transient/inputs`. That directory is
  owned by `root:root` with mode 0700 and was already inaccessible during the
  initial read-only audit. Journal tests do not use that filesystem directory.
- Static analysis, repository Pint check, environment alignment, production
  frontend build, documentation validation and the 20 documentation tests passed.
- The existing isolated DEP-06 drill passed in 71 seconds: 74 tables, 117
  foreign-key columns, three synthetic users, two recipes/versions, one shared
  plan and two durable objects. It verified paired restore, access boundaries,
  exclusions, negative integrity/eligibility, additive migration reconciliation
  and actual expired blob retirement/pruning. It does not exercise the unfinished
  DEP-08 account-purge/release/disposal workflow.
- No UI changes were made. Browser/accessibility checks are not applicable to
  this foundation. No application migration was added. Remote CI, actual
  independent-host durability, full lifecycle/privacy restore scenarios and
  storage/key-generation disposal remain unverified. CURRENT_STATE.md remains
  219 lines including blank lines.

AGENTS.md requires stopping when required tests fail for reasons outside the
task. Implementation stopped at this foundation boundary after recording the
unrelated test-fixture permission failure. Repair the test directory's ownership
for the Sail runtime and rerun the full backend suite before continuing the
remaining lifecycle/purge/release units. No permission change or fixture
deletion was performed as part of this task. Overall DEP-08 is incomplete,
not conditionally complete.

## Lifecycle services

The additive lifecycle migration gives accounts non-reusable generation ULIDs,
makes retained public recipe ownership nullable and adds protected deletion,
receipt, evidence-hold and retained-plan-removal state. Existing account data is
preserved. Rollback refuses populated lifecycle history.

A prepared deletion record fixes the original deadline and resource inventory
before independent journal acknowledgement. It denies ordinary processing
throughout preparation/recovery, including existing sessions, login, gates,
private plan sharing, exports and import materialization. Retrying a partial
transition uses its stored operation identity. Account recovery is permitted
strictly before the original deadline and restores detached public-plan
ownership/visibility; waiver shortens the deadline and under-13 state has no
recovery. Administrator counts exclude inactive accounts under the existing
FND-14 locking service, so a replacement must be active first. Public attribution
profiles are unavailable during recovery; ordinary public recipe retention is
unchanged until final purge, while confirmed under-13 public recipes are hidden.

## Purge and private-history unit

The next implementation unit adds non-reusable recipe/plan generations and
chunked protected journal manifests. Final purge services remove private source
graphs, detach qualifying public recipes/plans, null catalogue submission
links and erase ordinary audit identity mappings. Independently owned copies
keep their own generations and lifecycle.

Permanent retained-plan removal writes independently replicated intent before
the domain acknowledgement. During unwaived recovery, DEC-022 moves original
consumption chains, source entry facts, nutrition and historical target
snapshots into an encrypted owner-only archive before removing the public plan.
Recovery keeps that history without recreating the plan. Final purge deletes
the archive and its private audit history. Export schema 1.1.0 includes it through
explicit owner-scoped projections; released 1.0.0 files remain unchanged.
Retained-plan safety rejects unclassified fields across the entire plan, and
copies exclude schema-omitted private/recovery attribution.

This is service-level implementation, not production enablement. HTTP flows,
scheduler/queue integration, complete purpose-retention execution and the
DEC-012 restore/release/disposal gates remain required.
