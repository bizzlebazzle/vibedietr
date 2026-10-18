# Account deletion implementation

## Status

DEP-08 remains P2 and incomplete. DEC-021 records the four approved resource
boundaries; the [pre-implementation audit](DEP_08_LIFECYCLE_AUDIT.md) inventories
owned domains and earlier conflicts. Journal-backed lifecycle services,
authenticated HTTP recovery/waiver, inactive-account authorization and scheduled
queued purge are implemented. Owned-domain cleanup and purpose-specific audit
expiry are being verified; protected holds, complete restore/release/disposal
verification and final Definition of Done gates remain required.

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

## HTTP, queue and operator integration

The profile action now requests recoverable deletion and offers an explicit
irreversible recovery waiver. It verifies the current password and an enrolled
factor, explains retained public content/copies, and logs out after durable
journal acknowledgement. The guest account-recovery form authenticates the
same credentials for recovery or waiver; it cannot create an ordinary session
until recovery succeeds. Sensitive codes are excluded from flashed input.
Expiry includes the exact deadline; a later waiver cannot move it forward.

The UTC scheduler dispatches original-clock lifecycle reconciliation and due
purges through the inventoried default worker. Prepared, recovering and waiving
states remain retryable after replication failure. Five-minute overdue counts
raise a privacy-safe critical alert.

A configured privacy operator can invoke `accounts:purge-under13 GENERATION
--confirmed` only after independently confirming the age finding. Set
`ERASURE_PRIVACY_OPERATOR_REFERENCE` and `ERASURE_OPERATOR_ENVIRONMENT` in the
protected operator environment. The command neither determines age nor bypasses
the replacement-first administrator safeguard. It also converts a previous
ordinary recovery request to no-recovery status and immediately hides already
retained source plans. Never put personal evidence in command arguments.

Administrators may use the retained-plan exceptional-action form with their
password and a fresh authenticator factor. Only suppression/removal and the
approved reason categories are accepted. Moderation has its own minimized
twelve-month accountability classification. Suppression is an access restriction,
not an ownership transfer or recovery extension; no administrator restore action
exists. Permanent removal uses the protected journal and DEC-022 private archive.

## Owned-resource and audit minimization

Protected intent now inventories stable account, recipe/plan, private snapshot
audit, import/export/recalculation and ordinary audit-mapping references.
Original plan visibility is recorded without content for original-clock restore
replay. Large manifests use authenticated chunks bounded below the journal
envelope byte limit. A stale/deleted account instance cannot become active
merely because its recovery row has already been purged.

Private override/recalculation history is removed at final purge. Only the
already-public current nutrition presentation is retained separately, preserving
its source label, exact facts and estimate. Notes, private prior values and
historical actor linkage do not survive. Application-owned catalogue facts,
accepted factual corrections and independent remixes retain their own lifecycle;
private pending proposals, reasons, submitter links and legacy copied identity
are removed. Notification delivery records and unnecessary owned workflow jobs
are also removed.

Ordinary audit identity mappings are destroyed. Privileged accountability uses
a separate encrypted operator reference (email only) until the original
event/decision's twelve-month deadline; ordinary audit lookup cannot resolve
that table. Its expiry removes the mapping and expired event. Anonymous random
purge receipts expire at twelve calendar months. Ordinary security events use
six calendar months. Superseded public-version provenance uses its original
supersession timestamp plus twelve months, protected by an integrity-checked
clock before the source version is removed. Invalid/missing evidence raises a
content-free critical alert rather than inventing a new date.

`PruneAccountRetention` performs bounded, idempotent scheduled sweeps under the
existing queue contract. The independent protected journal and scoped legal
evidence do not inherit ordinary audit expiry.

## Separately protected scoped evidence

`erasure:provision journal` adds the two dedicated journal schemas and verifies
their current chains. `erasure:provision evidence` adds the independently stored
case schema. Both require the configured privacy/security operator and exact
environment. Neither provisions into the application connection or changes
existing case data. Schema presence is not proof of failure-domain separation,
TLS, encryption at rest, durable flushes or complete copy retirement.

`ERASURE_EVIDENCE_URL` points to a dedicated MySQL store outside application
restores. Its `ERASURE_EVIDENCE_ENCRYPTION_KEY` is a separate base64-encoded
32-byte AES-GCM key, distinct from the app and both journal keys. Keep its
credentials/key and operator settings in the protected operator environment,
outside web processes and ordinary recovery sets. The similarly named reserved
application table is unused and contains no case evidence.

The owner selects exact records, validates the incident/dispute/proceeding or
legal authority, and records approval before opening a hold. The tool does not
determine legal applicability, age or the validity of a notice. It never reads
a whole private account automatically and gives ordinary administrators no case
access. Source timestamps/expiry are preserved; decimals use exact strings and
verbatim byte evidence uses explicitly selected encoded fields. Unclassified
record-envelope fields, model graphs, floats, excessive depth/size and missing
authority/approval are rejected.

Operator commands:

- `erasure:evidence open --request=/protected/case.json --owner-approved`.
  The operator-created, owner-only 0600 JSON file must be outside the app tree,
  no larger than 64 KiB. Its exact fields are `id`, `account_generation`,
  `category`, `authority_reference`, `owner_approval_reference`, `records`,
  and optional `expires_at`. Categories are `security_incident`, `proceedings`,
  `legal_requirement` and `defined_dispute`. Each record has `reference`,
  `source_timestamp`, nullable `source_expires_at`, and explicitly approved
  `data`. IDs are ULIDs; timestamps are original UTC instants. Register the
  input and every other case copy in the protected case inventory.
- `erasure:evidence review --id=HOLD_ULID` records the named operator review
  and next review no later than 90 days, without changing notice expiry,
  source clocks or account recovery.
- `erasure:evidence release --id=HOLD_ULID --owner-approved` releases only the
  scoped copy. A notice with a future exact end additionally requires a
  separately validated `--release-authority=REFERENCE`. Keep that underlying
  authority in the protected controller case register.
- `erasure:evidence maintain` applies original notice expiry and reports
  overdue reviews using only a count. The protected operator must run this
  through independently supervised maintenance, review exceptions no later
  than 90 days, and remediate an overdue review/expiry as an incident. This
  command is not an ordinary web-worker case reader.

The independently durable evidence copy precedes hold acknowledgement.
Protected journal transitions and minimized twelve-month operator audit record
opening/read/review/release; reads require the named case operator and current
journal truth. A restored released case cannot be read or reopened. Release
removes its protected copy promptly; source clocks are never restarted.
Account final purge proceeds for unrelated data, while only approved scoped
evidence remains separate. No hold changes the account recovery deadline.

Case files, private-store backups, replicas and keys have their own explicit
inventory and original case/notice clocks. Do not freeze ordinary whole-account
backups merely because selected evidence is held. Statutory reporting,
controller case records, recipient/provider propagation and installation legal
review remain the separate DEP-09 operator duties; this implementation claims
no legal compliance or automatic adjudication.
