# Account deletion implementation

## Status

DEP-08 remains P2. Repository implementation and local verification are complete;
production release is conditional on the installation/manual gates in the
[verification report](DEP_08_VERIFICATION.md). DEC-021 and DEC-022 record approved resource
boundaries; the [pre-implementation audit](DEP_08_LIFECYCLE_AUDIT.md) inventories
every owned domain and dependency. Lifecycle services, authenticated recovery
and waiver, scheduled queued purge, protected scoped evidence, purpose-specific
audit expiry, original-clock restore replay and the quarantined release protocol
are implemented. Installation verification is separate from repository tests.

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
  migration and provisioning is restricted to the
  configured privacy operator.

These are secret deployment settings; do not copy connection URLs, keys, raw
journal content or linkable references into logs or ordinary audit payloads.
The independent stores are excluded from ordinary application recovery sets.
No row deletion/compaction API exists: deleting rows would break replay truth
without proving InnoDB page, redo/undo, host-copy or key disposal.

## Verification scope

Focused tests in `tests/Feature/AccountDeletion` exercise replicated journal
durability and repair, authentication and administrator safeguards, retained
plans and private archives, every-domain purge, audit minimization, protected
evidence, original-clock replay, independent-object filtering and signed target
release. SQLite protocol tests are complemented by prefixed InnoDB journal
stores in the disposable MySQL testing database.

The isolated `scripts/recovery/drill.sh` extends DEP-06 with separately running
journal hosts, real MySQL/Garage/restic restoration and synthetic lifecycle
scenarios. Its PHP fixture failures propagate nonzero exit status. Final results
must distinguish actual restore/prune operations, simulated clock boundaries,
and production-only evidence. No test establishes physical overwrite, actual
seven-day aging, independent production failure domains or installation key
custody.

The final canonical backend suite passed 1,185 tests with 7,509 assertions.
Formatting, static analysis, build, tooling and both browser gates passed.
The real full/database-only/object-only/selective restore and disposal drill
passed in 140 seconds. Production all-layer retirement, actual aging, key
custody, live readiness, remote CI and manual assistive-technology checks remain
installation gates; local probes cannot attest them. The original upload and
security tests remain unchanged.

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

Production enablement still requires the complete DEC-012 restore/release/
disposal drill and installation verification.

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

## In-flight private-object boundaries

Final account purge waits for an existing import processing lease to drain;
it never clears a live lease to bypass a worker. This does not extend recovery
or change the original erasure clock. Canonical-image keys are reserved durably
before object writes, reused on retry, and written only while account/import
ownership locks still authorize the operation. A preprocessing result arriving
after purge cannot create an orphan canonical object. Inactive-owner job aborts
do not replace original terminal outcome clocks or erase recovery content.

Import and export cleanup verify object absence after a successful delete API
response. A surviving object leaves purge incomplete and retryable, without a
final purge acknowledgement. Focused tests interleave final purge with image
preprocessing, exercise live-lease draining and failed reservation writes, and
simulate delete APIs reporting success while an object remains.

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

## Quarantined restore and release

Every restore starts with a fresh non-reusable `ERASURE_RESTORE_TARGET` ULID,
an empty isolated database/bucket and externally fenced source and target
writers, HTTP ingress, scheduler, workers, mail and public object access.
`ERASURE_RESTORE_SET` identifies the complete restic snapshot;
`ERASURE_RESTORE_MANIFEST_SHA256` is its independently authenticated capture
manifest digest. The selected set retains its original seven-day eligibility.
Keep independent journal/evidence stores outside every application restore.
An old checkpoint copied with the set is not current evidence.

Set the target's `ERASURE_RELEASE_CERTIFICATE` to a new restricted path outside
the app tree. Serving processes receive only `ERASURE_RELEASE_PUBLIC_KEY`;
the separate privacy operator receives the corresponding base64 Ed25519
`ERASURE_RELEASE_SIGNING_KEY`. Until a matching release certificate exists,
HTTP, queue reservation/processing and scheduled task execution fail closed.
Network and object-storage isolation remain mandatory; middleware does not
configure ingress, storage ACLs or process supervision.

The operator first verifies the restic set and imports its SQL into the fresh
target. The restricted commands are:

1. `accounts:verify-restore checkpoint --fenced --write=/protected/head.json`
   repairs an authenticated primary suffix, reconciles prepared SQL lifecycle
   transitions without resuming work, and writes the current independent head.
   Obtain this from the live independent stores, never the old backup.
2. `accounts:verify-restore prepare --fenced --directory=/protected/set
   --checkpoint=/protected/head.json --write=/protected/prepared.json`
   checks capture identity/age and exact raw SQL reconciliation, filters objects
   before copying into the separate empty target bucket, replays original-clock
   lifecycle/removal intent including completed purges, invalidates restored
   login/download/queue state, reapplies audit clocks and checks protected holds.
   It signs content-free preparation evidence; the target is still unavailable.
3. `accounts:verify-restore release --fenced --approve
   --directory=/protected/set --prepared=/protected/prepared.json
   --write=/protected/release.json` verifies the frozen database, objects,
   original deadlines, protected evidence and final independent head again.
   Only this signed certificate authorizes the named sanitized target.

New journal intent, changed data/schema/objects, target SQL write counters
(including writes reverted to the same data), elapsed pending deadlines or
missing verification material deny release. Failed preparation requires a
fresh raw target and identity; never reuse old approval after another import,
replay, object write or cutover change. Keep all writers fenced through final
verification and atomic ingress/worker cutover. Replacing environment settings
or bypassing the restricted path is not an approved restore.

Snapshots predating stable account/resource generations or missing required
current public version/recovery context fail closed. An older plan body is
never assumed to be safe merely because its source was once public.
Completed purge replay creates no new per-account anonymous receipt or
retention clock. Independently owned copies retain their own generations.

Retire raw/quarantined sets, staging workspaces, rejected target databases,
object buckets and their replicas/backups through the protected copy inventory.
A release certificate does not establish their disposal or authorize journal
reference/key retirement. The installation must separately prove actual
failure-domain separation, key custody, storage-layer retirement and fenced
cutover. The synthetic drill exercises reference infrastructure; it cannot
establish production durability or physical-media guarantees.

## Restore-release unit verification: 18 October 2026

The focused lifecycle suite passed 87 tests with 316 assertions. Static analysis,
development-environment alignment and documentation validation passed. The
isolated DEP-06/DEP-08 drill passed in 112 seconds with actual MySQL, Garage and
restic. It verified full paired restoration, due/incomplete and completed
purges, cancellation recovery, under-13 deletion, preserved private archives,
independent plan copies/recipe remixes, object filtering without SQL ownership
lookup, journal mirror loss/repair, repeated replay and signed target release.
Missing credentials/certificates, elapsed pending deadlines and SQL writes
reverted to identical data denied release. Its DEP-06 stages also performed
actual expired-blob pruning and failed restoration after retirement.

Lifecycle time boundaries were simulated with synthetic fixtures. Actual
production aging, all-layer copy retirement, journal storage-generation/key
disposal, partial-restoration installation procedures and final Definition of
Done gates are not established by this unit.

## Copy inventory and journal storage generations

`RecoveryCopyCatalog` records original capture clocks, coverage checkpoints,
manifests and necessary opaque owner/resource scope in both protected journals.
Register every recovery set, database/object replica, host snapshot, staging
workspace, restore target, journal store/backup, key/header copy and recovery
credential. Operator request/evidence files, credentials, swap and temporary
material belong in this inventory too. Unknown or unmanaged copies prevent a
complete inventory attestation; absence from the app database proves nothing.

Copied sets inherit their parent's scope and cannot gain a later capture clock.
A post-deadline copy containing erased-owner scope is marked
`incident_dirty_capture`; it retains suppression truth under the original E,
rather than granting another recovery period. The protected controller incident
register must document unresolved copy disposal or a scoped lawful exception.
Content-free monitoring alerts on unretired recovery copies at capture + eight
days and surviving erasure references at E + nine days. Alerts never count
failed or unproven deletion as success.

The separate backend/retirement observer holds
`ERASURE_RETIREMENT_SIGNING_KEY`; remediation receives only
`ERASURE_RETIREMENT_PUBLIC_KEY`. Ed25519 proofs bind their purpose and
installation. A signed statement is trusted observer evidence, not a physical
deletion primitive. The observer must perform and retain restricted evidence of
the actual backend checks, including all layers, before signing. Ordinary app
administrators have no signing or inventory powers.

Restricted commands require the configured privacy operator, `--fenced` and
`--owner-approved`:

- `erasure:copies register --request=/protected/copy.json` accepts `id`
  (ULID), `kind`, `captured_at`, `manifest_sha256` and `references`.
  References use `owner_`, `recovering_`, `plan_`, `recipe_`, `hold_`,
  `parent_` or `generation_` prefixes. Pure journal/key/header copies use
  generation scope without copying the account inventory recursively.
- `erasure:copies retire --evidence=/protected/observation.json` accepts only
  a valid observer-signed `copy-retirement` proof: copy ID, original manifest
  digest, observation digest, exact verification instant, mechanism and every
  registered generation reference. A restic-only receipt cannot retire journal
  storage. Restic observations require actual forget, zero-unused pruning,
  read checking and failed access to the retired copy; generation observations
  require actual LUKS2/key/header/credential disposal. Synthetic volatile-store
  evidence is rejected outside testing.
- `erasure:copies monitor` reports only overdue counts. Missing independent
  journal truth and overdue disposal also raise critical operational alerts.
- `erasure:copies new-generation --write=/protected/next.json` generates a
  fresh ULID and independent random keys without printing them. Provision the
  fresh LUKS2-backed endpoint pair with `ERASURE_NEXT_JOURNAL_PRIMARY_URL`,
  `ERASURE_NEXT_JOURNAL_MIRROR_URL` and `erasure:provision next-journal`.
- `erasure:copies prepare-generation --request=/protected/next.json
  --evidence=/protected/inventory.json --storage=/protected/storage.json
  --write=/protected/rebuild.json` requires a current signed
  `complete-copy-inventory` and a `fresh-luks2-journal-storage` observation.
  The inventory names every active copy, binds the exact current checkpoint,
  and covers all data/journal/key/header/credential/host layers. Fresh storage
  evidence binds the new generation, two distinct LUKS2 UUIDs, connection
  scopes and backend observation digest. The rebuild carries still-needed
  lifecycle/resource/hold truth and active copies into both fresh stores.
  Original deadlines survive; fully retired erasure scope can be omitted.
- With all writers still fenced, physically retire the previous generation and
  every recoverable journal/redo/undo, host backup, key, header and credential
  copy. Record each verified retirement against the new stores. A SQL
  `DELETE`, empty table, hidden bucket or successful rebuild is insufficient.
- Point the restricted operator configuration at the new endpoints/keys, then
  run `erasure:copies complete-generation --prepared=/protected/rebuild.json
  --evidence=/protected/current-inventory.json --write=/protected/disposed.json`.
  The command rejects unrelated intervening intent and any unretired old-layer
  copy. It requires both old stores plus key/header/credential coverage before
  issuing content-free disposal evidence. Only then perform the fenced
  authoritative checkpoint/configuration cutover.

Proofs and generation requests stay outside app backups in restricted encrypted
storage. Protect and retire them under their own inventoried purpose; never
commit or log keys, URLs, source manifests or linkable account references.
The isolated cryptsetup probe uses synthetic images and volatile no-swap key
files. It verifies journal-byte encryption, current-slot erasure and the
old-header recovery limitation, then retires a separate header-custody image.
It does not prove installation-wide disposal, host snapshot absence, encrypted
swap policy, independently durable live MySQL mounts or physical overwrite.

Transient file cleanup uses the original completed/failed outcome, never the
last row update. Abandoned input processing expires at its original storage
time plus seven days; late cleanup does not restart that clock. Processing-only
file size/extension, canonical MIME, lease, cleanup marker bookkeeping
is minimized thirty days after the terminal outcome. DEC-005/006 accepted or
extracted review text, approved private provenance, import identity and durable
drafts and bounded-retry controls remain owner-private domain data until their own deletion/account purge.
They remain in ordinary encrypted recovery sets; they are not transient source
files. Restores cancel expired processing without deleting that private content.
Missing terminal clocks fail closed and emit only an invalid-clock count.
