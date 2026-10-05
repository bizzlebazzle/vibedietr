# Backup erasure investigation — DEC-012

## Status and scope

Technical investigation recorded on 4 October 2026. The owner subsequently
approved seven-day backup retention, the protected erasure journal and its
disposal/failure handling, and technical selection of a Docker reference stack.
The owner also explicitly authorized changing DEC-012 to **Decided** after
validation, retaining **Technical investigation** as owner. The approved clocks
and restore requirements below are policy; this record is not an implemented
backup facility, production restore runbook, or professional legal approval.

The owner confirmed during this investigation that users should self-host the
application, ideally with Docker. The reference stack is self-hosted; it does
not require selecting a cloud hosting or managed-backup provider.
The existing production contract still requires MySQL, database-backed
sessions/cache/queues, and private S3-compatible storage. Self-hosting does not
authorize replacing that storage contract with local application files.
Each installation's operator must identify its actual storage, backup, and
infrastructure services; AWS variable names do not select Amazon services.

## Repository evidence at the start of investigation

The documented context command rejects DEC-012 because it accepts roadmap IDs
in `AAA-NN` format. Context was generated for DEP-06, DEP-08, and DEP-09 instead.
The investigation inspected their routed requirements and the following
implementation evidence without accessing production data or credentials.
These inventory findings predate the owner approval and reference-stack
validation and describe that investigation baseline. Subsequent DEP-06 tooling
and dependency changes are recorded in the [implemented runbook](BACKUP_RESTORE_RUNBOOK.md):

| Evidence | Finding and limitation |
| --- | --- |
| [Product deletion requirements](PRODUCT_SPEC.md#account-deletion), DEC-013 and DEC-014 in [the decision register](DECISIONS.md) | Preserve optional 30-day recovery, final private-data removal, public/shared anonymization, independent copies, immediate-purge exceptions, and the sole-administrator safeguard. Backup recovery must not extend account recovery. |
| [Production contract](PRODUCTION_CONFIGURATION.md#database-cache-queue-and-private-storage), [filesystem configuration](../config/filesystems.php) | Private `s3` disk with a configurable endpoint; no selected object-storage implementation, versioning, Object Lock, replication, or expiry configuration. |
| [Development Compose](../docker-compose.yml), [production worker Compose](../compose.production-operations.yml) | Development MySQL 8.0 uses a named volume. Production Compose supplies workers and scheduler only; it does not supply production database, object storage, backups, or restoration. Sail is development infrastructure. |
| [Composer requirements](../composer.json), `composer.lock` | The S3 filesystem adapter is not an installed package. Configuration validation is not proof that production object access or recovery works. Dependency provisioning and a real endpoint check belong to downstream deployment work. |
| [Current capabilities](CURRENT_STATE.md#current-gaps-and-constraints), [deletion component](../resources/views/livewire/profile/delete-user-form.blade.php), [account tests](../tests/Feature/ProfileTest.php), [administrator tests](../tests/Feature/AdministratorAccountDeletionTest.php) | Deletion remains immediate; the recoverable request, deadline, full purge, and restore gate are future DEP-08 work. Current UI promises permanent deletion without explaining backups; DEP-09 must replace it when the actual lifecycle exists. |
| [Import owner cleanup](../app/Domain/RecipeImports/RecipeImportOwnerCleaner.php), [input cleanup](../app/Domain/RecipeImports/RecipeImportInputCleaner.php), [transient store](../app/Security/Uploads/TransientInputStore.php) | Cleanup deletes source/canonical objects by disk/key and clears the mapping. It does not enumerate historical object versions, replicas, or backup copies. A successful ordinary object delete is insufficient evidence for those copies. |
| [Audit identity eraser](../app/Audit/AuditActorIdentityEraser.php), [audit tests](../tests/Feature/AuditEventStoreTest.php), [remix tests](../tests/Feature/Recipes/RecipeRemixTest.php) | Mapping destruction and remix survival have focused coverage. These tests do not establish full account purge, backup expiry, or safe restoration. |
| [Audit retention](AUDIT_RETENTION_SCHEDULE.md#backups-and-restoration), [privacy matrix](AUTHORIZATION_PRIVACY_MATRIX.md), [migration boundaries](DOMAIN_MIGRATION_PLAN.md#11-rollback-expectations) | No approved backup period. Completed purges must be replayed before service. A random non-derived purge receipt cannot identify records requiring replay in an old backup. |
| [Queue conventions](QUEUED_JOB_CONVENTIONS.md), [inventory](JOB_INVENTORY.md), [queue recovery](QUEUE_OPERATIONS.md#safe-replay-runbook), [operations](OPERATIONS_RUNBOOKS.md), [observability](OBSERVABILITY.md) | Existing operations do not implement backup monitoring or erasure replay. Database-backed locks, queues, and completion markers can themselves roll back; they cannot prove an erasure was never requested. |

No production backup configuration, retention-expiry observation, recovery
target, off-host copy inventory, or production restore-drill evidence was found.
Disposable tools and synthetic fixtures used for capability validation are
separate from the application. No production deployment was configured.

## Facilities that can support the design

Primary documentation establishes these capabilities, not their deployment in
VibeDietr. Source links and access date appear below.

| Facility | Confirmed capability | Consequence for this installation model |
| --- | --- | --- |
| Docker volumes [S1] | Volumes persist independently of containers and can be backed up/restored. Container export omits mounted-volume contents [S2]. | Docker supplies neither a backup schedule nor retention/erasure propagation. Inventory volumes and bind mounts. A container image/export is not a database backup. |
| MySQL 8.0 logical backup [S3] | `mysqldump` supports a consistent InnoDB transaction snapshot; concurrent DDL and nontransactional tables require additional controls. | Recommend application-database dumps with a schema/version manifest. An arbitrary tar of a running database volume does not establish a consistent backup. |
| MySQL point-in-time recovery [S4] | Recovery requires a base backup and subsequent binary logs. | PITR is optional additional infrastructure, not supplied by current Compose. Logs and replicas carry personal data and need the same bounded lifecycle and restore gate. |
| restic, selected reference tool [S5–S8] | Encrypted authenticated repositories, filesystem/SFTP/S3-compatible destinations, explicit restore targets and subsets, snapshot removal plus data pruning, and integrity checks. | Use a pinned, operator-scheduled backup container and a separate failure domain. Neither automatic application erasure nor immutable expiry is supplied by restic itself. Disposable validation does not install an application backup facility. |
| Amazon S3, conditional example [S9–S11] | Versioned deletes can leave prior versions; lifecycle deletion can lag eligibility. Locked versions cannot be deleted before their lock permits it. Replication has separate delete behavior. | These are Amazon-specific limits, not guarantees for every compatible endpoint. Inventory all versions and destinations. Never infer physical deletion from a missing current object or an expiry setting. |
| AWS Backup, conditional example [S12–S14] | S3 recovery can target another bucket; a current delete marker does not prevent restoration. Vault Lock can prevent early deletion. Failed lifecycle deletion can leave expired recovery points. | Provider backups are an additional lifecycle if an operator selects them. Provider restoration does not apply VibeDietr erasure decisions. A locked vault's retention setting is not erasure evidence. |

Amazon RDS was examined but is not the intended Docker database facility.
Its 0–35-day instance retention setting excludes stopped time [S15]; therefore
even a configured seven-day setting would not prove a seven-day wall-clock
limit. Selecting RDS or another managed database would require a separate
deployment choice and renewed capability investigation.

For a self-hosted S3-compatible server, confirm its documented version,
version-deletion APIs, lock modes, lifecycle timing, underlying disks/volumes,
snapshots, replication, and destruction behavior. Backing up that server's
volumes is an additional copy of object data. An application bucket and a
backup bucket on the same host are not independent disaster recovery.

## Selected Docker reference stack

The delegated technical selection is MySQL 8.4 LTS with consistent logical dumps,
Garage 2.3.0 for private application objects, restic 0.19.1 for encrypted paired
recovery sets, and rest-server 0.14.0 for the off-host repository. Pin immutable
image digests and revalidate supported security patches at deployment; these
versions identify the investigated baseline, not an indefinite update freeze.
No application dependency or production Compose file is changed here.

The repository's development baseline remains MySQL 8.0. Oracle moved 8.0 to
Sustaining Support in April 2026 and recommends an LTS upgrade [S27]. Use a
maintained 8.4 patch for the production reference, with same-version dump and
restore clients [S28]. DEP-02/DEP-06 must prove application/schema compatibility
before deployment; this decision does not upgrade an existing database.

The following boundaries are mandatory for this reference design:

- Garage provides SigV4/path-style object access but not AWS-style ACLs,
  bucket versioning or Object Lock [S18]. Use its own per-key/per-bucket access
  control, private buckets, no website publishing, and a separate read-only
  capture identity. The existing Laravel `s3` disk contract remains intact;
  dependency provisioning belongs to deployment. Do not call ACL visibility
  mutation/read APIs or infer privacy from an unsupported AWS control.
- Production Garage uses three-way replication across separate failure
  domains, following its cluster recommendation [S19]. Multiple containers on
  one machine do not satisfy this. The single-node disposable probe establishes
  API compatibility only; Garage's quick-start explicitly excludes that
  topology from production. Independent encrypted restic recovery is required
  even with replication.
- Garage's HTTP endpoint needs a TLS reverse proxy or encrypted transport;
  its ordinary data/metadata volumes need encrypted host storage [S20].
  S3 deletion does not prove local block disposal. Tagged implementation has
  a 600-second zero-reference block-deletion eligibility delay [S21], and the
  repair documentation describes orphaned references after node loss [S22].
  Treat unavailable nodes, overdue garbage collection, metadata snapshots,
  orphan blocks, and full-host snapshots as inventoried erasure risks; require
  reconciliation/retirement before dropping suppression. This is not a
  ten-minute deletion guarantee. Identical blocks still needed by separately
  owned content do not require destruction of that other content.
- Store restic packs on an independent mutable filesystem through an
  authenticated TLS rest-server [S23]. The capture client has append-only
  remote access. A separate repository-host maintenance identity has the
  password and filesystem access needed for wall-clock snapshot retirement,
  zero-unused pruning, and integrity verification. Append-only access protects
  against ordinary capture-client deletion; it is not immutable provider
  retention and does not prevent authorized expiry. No unmanaged filesystem
  snapshots or another backup of the repository may extend the policy.
- Capture application MySQL state and a logical S3 object inventory while
  application mutations/workers are quiesced, with DDL excluded. Pair both
  under one capture cutoff and manifest. Preserve opaque account-generation
  ownership in the restricted manifest so an old object absent from the live
  database can still be suppressed. Exclude transient/export prefixes. Do not
  use raw Garage volumes as the normal object-recovery format; recover objects
  through S3 into a fresh private target after filtering.
- The default reference does not offer PITR or archive binary logs. Disable
  unneeded binary/general/slow-query logging on its dedicated databases;
  inventory and bound any separately enabled logs, replicas and snapshots.
  Never accept an image's default logging/retention as the seven-day policy.
- Use dedicated MySQL/InnoDB journal stores on two independently recoverable
  hosts outside the application restore set. DEP-08 must require matching,
  durable acknowledgements of a bounded, authenticated operation/checkpoint
  from both stores before acknowledging lifecycle transitions. Partly written
  operations remain recoverable and reconcilable; disagreement or stale state
  blocks release. A database connection is a supported primitive, not an
  implemented multi-store protocol. Keep transactions flushed to disk and
  verify actual host durability [S24]. Do not substitute default asynchronous
  replication or semisynchronous replication with timeout fallback for this
  acknowledgement requirement [S25].
- Journal payloads contain only encrypted installation/account-generation
  references, operation sequence/state, original deadline, policy version, and
  necessary object/resource references. Integrity/checkpoint keys are separate
  from application recovery sets. Journal stores retain current replay truth,
  including pending recovery/cancellation transitions, and are excluded from
  ordinary seven-day archival backups. Both stores, journals/redo logs, and
  their storage layers must support verified disposal of linkable entries
  within the approved window. An unavailable mirror prevents disposal proof
  until reconciled or its affected media are verifiably retired. Content-free
  checkpoint/disposal evidence may survive under DEC-013.

Deleting a journal row is not proof that InnoDB pages, redo/undo logs or host
snapshots have lost it. DEP-08's disposal must remove all recoverable copies:
rebuild retained journal entries onto a fresh separately encrypted LUKS2 storage
generation, verify both stores/checkpoints, and retire the previous generation
and every recoverable encryption-key/header copy. The key inventory and
retirement drill must cover host backups and recovery credentials. Cryptsetup
supports keyslot erasure, but an old header backup plus its passphrase can still
decrypt the old generation [S29]. Plain SQL
deletion or shared host-disk encryption alone does not satisfy this gate.
Unproven disposal is an overdue-retention incident, not a successful nine-day
claim. This is a required operational design, not a tested key-retirement tool.

This uses conventional Laravel MySQL connections and its existing S3 filesystem
boundary. It selects infrastructure primitives and strict acceptance controls;
DEP-06/DEP-08 still implement the capture, journal, expiry and release workflows.
Operators using other infrastructure must demonstrate equivalent copy expiry,
independent intent durability and fail-closed restoration, or review DEC-012
before production use. No cloud-provider expiry or physical-media overwrite
guarantee is inherited from this reference stack.

MinIO Community is not selected: its upstream repository is archived and
explicitly marked unmaintained [S26]. Managed AWS backup remains an optional
alternative requiring fresh validation of its additional retention layers.

## Alternatives and technical recommendation

| Alternative | Assessment |
| --- | --- |
| Fixed wall-clock expiry alone | Small operational burden, but restoration before expiry resurrects erased data. Reject as a complete design; finite expiry still needs erasure replay. |
| Tiered daily/weekly/monthly retention plus replay | Technically viable with restic or supported provider recovery points. Longer corruption-detection coverage costs more, leaves personal data in recovery copies longer, and extends replay-record retention. No requirement or operator-approved period justifies monthly/yearly copies here. |
| One short retention tier plus replay | Selected and owner-approved for Docker self-hosting: daily consistent database/object recovery sets, seven-day eligibility, bounded pruning, and an independent erasure journal. Fewer copies and clocks are easier to verify; the owner accepted the shorter recovery horizon. |
| Per-account backup rewriting or cryptographic erasure | No demonstrated selective removal in shared MySQL dumps or deduplicated packs, nor per-account key architecture covering every domain and processor. Reject for this task; deleting the shared repository key would destroy other users' recovery. |
| Immediate destruction of all affected recovery sets | Possible on mutable storage but reduces recovery for everyone and cannot bypass a genuine immutable lock. Not a general replacement for scheduled expiry and replay. |

The approved seven-day policy is an exposure/recovery trade-off, not a legal
period. A 30-day recovery window operates on protected
live account state; it does not require keeping erased data in backups for 30
more days. A supported longer option is daily points for seven days and weekly
points through 30 days, with the same replay controls. The owner chose the
shorter horizon; adopting the longer option requires a new policy review.

The selected topology costs separate storage failure domains and journal
operations. Paired logical capture pauses mutations while capturing objects;
its duration must be measured. Requiring both journal acknowledgements reduces
deletion/recovery availability during a host outage in exchange for durable
erasure truth. Quarantine similarly favors privacy over immediate restoration.
These are operational trade-offs of the selected reference, not an HA promise.

## Approved clocks, scope, and expiry rules

The owner approved the short-tier policy and protected journal. Use elapsed UTC time for
backup ages; preserve the approved account recovery clock. Define `E` as the
account's irrevocable erasure deadline (normal recovery expiry or an approved
immediate-purge instruction), not the completion time of a delayed job.

| Information or operation | Approved period/control | Support and qualification |
| --- | --- | --- |
| Database and durable private-object recovery sets | At least one successful paired set per 24 hours; sets cease to be eligible at capture time plus seven days. | Daily schedule is operator-managed. Approximately 24-hour recovery-point objective requires monitored successful capture, not merely a cron entry. Actual restore-time objective must be measured and accepted by the operator. |
| Expired recovery data | Independent daily expiry sweep; finish snapshot removal and unreferenced-data pruning within 24 hours of eligibility. | Target recoverable-copy removal by `E + 8 days` if no dirty capture occurs after `E`. This is an operating target, not a provider physical-media guarantee. Failed deletion is an incident, never evidence of success. |
| Manual/pre-migration copies, replicas, off-site copies, host snapshots, PITR logs if enabled | Same seven-day maximum recovery eligibility and 24-hour removal target; original capture time follows copied data. | No indefinite keep-last, migration exception, or copy-age reset. Full-host/object-server snapshots need inspection or exclusion; a separate provider retention rule can invalidate this design. |
| Plaintext dump/object staging and restore workspaces | Encrypted restricted staging; remove promptly after use, at most 24 hours after completion or abandonment. | No backup of staging, restore workspace, temporary exports, application logs, or credentials as ordinary user-content recovery sets. Minimize host swap/temp-file leakage. |
| Transient import/OCR sources, abandoned uploads, export archives/download credentials | Exclude from ordinary recovery sets; live cleanup remains DEC-013's 24-hour/seven-day schedules, with earlier account purge. | Prefix/bucket separation must be proven. Restored database import/export metadata cannot restart expired processing or recreate an expired archive. Durable recipe drafts remain domain data and require backup/purge coverage. |
| Independent erasure journal and its replicas/backups | Until every potentially affected copy is verifiably retired; normal disposal target `E + 9 days` under the approved short tier. | Eight-day removal target plus one day for verification/journal disposal. Its own recoverable copies must support that disposal deadline without adding another seven-day tail. Extend only for a recorded unresolved recovery-copy incident or scoped hold; no deletion while a dirty copy remains restorable. The owner explicitly approved this narrow purpose, minimization, and qualified failure handling. |
| Anonymous purge/drill evidence | Existing DEC-013 twelve-month purge-evidence clock. | No account-identifying suppression data, object paths, or content in receipts, logs, tickets, or drill reports. |

Expiration is based on wall clock even if backups stop. Restic retention counts
or windows relative to the newest snapshot do not enforce that requirement
[S5]. DEP-06 would need explicit age selection across all snapshot groups,
future-timestamp rejection, and pruning that also retries after a previous
failure. A plain successful snapshot-removal result is insufficient.
Do not apply age-based lifecycle deletion to individual restic pack files:
still-retained snapshots may reference older packs.

The `E + 8 days` target assumes no post-deadline backup includes the account's
erasable data. Capture must coordinate with due purges or sanitize/quarantine
the affected recovery set. A delayed purge, stale restored source, hidden
object version, nested host backup, or new unsanitized copy invalidates that
bound. Record the incident, keep suppression effective, and correct the
inventory and user communication; do not silently restart the clock.

Pruning must remove unused data in partially referenced packs, not just whole
unreferenced files. Restic's default permits residual unused data [S5]; require
zero tolerated unused data and no repacking restriction that leaves it behind,
then verify removal. These are requirements for a future deployment runbook,
not a new runnable repository command. Reserve capacity for repacking.

If the selected destination keeps deleted versions or immutable copies longer,
either demonstrate a finite compatible lifecycle for all of them or propose a
different period for owner approval. Do not promise that blocks on physical
media are overwritten on the API deletion date. No provider in this
investigation guarantees that assertion.

## Required restore controls

These are design requirements for downstream work, not executable procedures.
They apply equally to full disaster recovery, schema rollback, partial record
imports, object-only recovery, and recovery into staging or another machine.

1. **Durable erasure intent before destructive work.** Maintain an encrypted,
   access-restricted journal outside the database/volume being restored and in
   a separate failure domain. Record deletion request, deadline, recovery
   cancellation, waiver, immediate-purge instruction, and purge outcome in
   ordered, integrity-protected entries. A request is not acknowledged until
   its external intent is durable. Recover/cancel updates must be equally
   durable so replay cannot erase a legitimately recovered account. A crash
   between journal write and database mutation must be safely reconcilable.
2. **Minimized identity for replay.** Keep only the installation namespace,
   non-reusable account-generation reference, operation/state/deadline, policy
   version, and required resource/object/version references captured before
   ownership mappings disappear. Current integer account IDs alone need
   protection against reuse after rollback. Exact field design belongs to
   DEP-08. Hashes/HMACs or opaque IDs remain personal data if they enable
   linkage; this journal is separate from ordinary audit and its anonymous
   receipt. Include its own backups in the disposal policy.
3. **Fail closed on incomplete evidence.** Verify journal integrity, sequence
   continuity, authoritative latest checkpoint, and coverage of the selected
   recovery sets through release time. A journal from the same old snapshot
   does not qualify. Missing, stale, tampered, or unavailable journal data
   blocks service, downloads, queue replay, and publication; never infer that
   no erasures occurred. Protect checkpoint freshness independently of the
   restored database/cache and prove journal recovery after host loss.
4. **Quarantined target.** Restore into a fresh isolated MySQL target and
   private bucket/prefix/volume. Start no ordinary web traffic, workers,
   scheduler, mail, OCR, export delivery, or externally reachable object URLs.
   Provide only the restricted remediation path. Do not overwrite production
   in place, use a real erased account as a drill fixture, or serve a raw backup
   to another purpose such as development or analytics.
   Authenticate the capture manifest and reject expired, future-dated or
   unknown recovery sets. A copied set keeps its original capture time; the
   additional 24-hour disposal interval does not extend recovery eligibility.
5. **Reconcile current state and deadlines.** Apply authoritative journal
   transitions, including requests absent from the old database. At current
   UTC time, purge every account whose irrevocable deadline has passed,
   including work that was never marked completed. Reapply inactivity and
   publication restrictions for still-pending requests without resetting the
   original recovery period. Completed erasures cannot be reversed through
   restored credentials or account-recovery UI.
6. **Replay domain purge and expiry idempotently.** Delete all specified
   private domain data, identity/profile state, activity, credentials, and
   ordinary audit identity mappings; anonymize approved public recipes and
   catalogue provenance. Apply DEC-014's retained-plan safety, bookmark,
   unlisting, attribution, and removal rules, plus confirmed under-13 public
   content removal. Preserve independently owned copies/remixes and genuinely
   scoped DEC-013 evidence. Reapply all elapsed retention clocks, suppression
   or removal instructions, and released/expired holds; restoration grants
   no new retention period or ownership.
7. **Sanitize object recovery independently.** Use the recorded resource
   inventory to suppress erased owners' objects even if their database rows
   no longer exist. Inspect all versions, delete markers, replication targets,
   and recovered or orphaned keys. A database-only purge does not sanitize a
   bucket. Never copy quarantined erased objects into the serving bucket.
   Object-only recovery must consult current erasure state, not merely its
   old object manifest. Verify durable survivors against database references
   and checksums; missing required objects keep the target unavailable.
8. **Invalidate restored operational state.** Remove restored sessions,
   password-reset/remembered-login state and expiring download links; require
   fresh authentication. Rebuild caches/locks and authorize any queued or
   failed work against current state and original expiry/idempotency rules.
   Do not replay restored import, export, notification, or purge jobs blindly.
   Administrator activation remains subject to FND-13/FND-14 live readiness.
9. **Verified release.** While writes remain frozen, reconcile through a final
   journal checkpoint, verify absence/anonymization and referential integrity,
   and record content-free operator sign-off bound to the target, recovery
   set, policy/release, and checkpoint. Release only that sanitized target.
   Invalidate approval after further restore writes or new erasure intent;
   regenerate safe recovery sets and expire temporary recovery material.

Ordinary queue idempotency markers restored from backup must not short-circuit
this remediation because an old completion marker is not evidence that the
restored private data has been erased. Physical deletion across SQL and object
storage is not one transaction; partial success must leave service closed and
be resumable. If all trustworthy journal replicas are lost, preserve
quarantine and investigate; no ordinary automatic restoration is safe.

## Disposable capability validation

On 4 October 2026, the technical investigation ran an isolated Docker probe
with synthetic accounts, an InnoDB private-row foreign key, public contribution,
owned S3 objects and excluded transient input. No repository dependency,
application database, production credential or production configuration was
changed. Fixtures used an internal network with no published ports; probe
containers and that network were removed afterwards. Temporary harness permission/entrypoint failures were
corrected before the complete successful run.

| Capability check | Observed result |
| --- | --- |
| Laravel storage boundary | Probe-only AWS SDK 3.399.1, Flysystem 3.36.0 and S3 adapter 3.35.3 performed private PUT/stream, GET, HEAD and LIST against Garage. Anonymous access failed; no versioning was reported; ACL read returned 501. The application still needs its adapter provisioned. |
| Retention and access separation | A simulated eight-day snapshot was selected by absolute UTC age while a current snapshot was retained. Append-only remote snapshot deletion failed with HTTP 403; repository-host maintenance successfully forgot/pruned it and checked every retained data pack. |
| Paired restoration | Consistent MySQL dump and logical object capture restored into a separate database and quarantine bucket. Transient input was absent and surviving object checksums matched. Raw restoration contained the erased fixture, proving why expiry alone is insufficient. |
| Replay feasibility | A newer intent in the separately stored journal survived app-database rollback. Synthetic remediation ran twice, removed the erased private records/object, anonymized public attribution and preserved another owner's private row/object. Journal intent survived its own container restart. |
| Recoverable-copy retirement | After a sanitized capture, the dirty snapshot was forgotten, pruned with zero tolerated unused data, and all retained data checked. Attempted ordinary restoration of its retired snapshot ID failed. |

The complete probe passed on both the existing cached MySQL 8.0.32 image and
the selected 8.4.10 LTS baseline. The following immutable image identities
record the latter run; they are evidence identifiers, not deployment commands:

| Probe image | Repository digest |
| --- | --- |
| `mysql:8.4.10` | `sha256:8dbcf531a03aade657e181b9cf2f1d1803ce621a1d55610cb44cb531ab7d7db6` |
| `dxflrs/garage:v2.3.0` | `sha256:866bd13ed2038ba7e7190e840482bc27234c4afaf77be8cfa439ae088c1e4690` |
| `restic/restic:0.19.1` | `sha256:136600b6ff6843d61d355f7f71f460a166429f35de6fd11b568fece3c9a4d510` |
| `restic/rest-server:0.14.0` | `sha256:d2aff06f47eb38637dff580c3e6bce4af98f386c396a25d32eb6727ec96214a5` |

MySQL release notes list newer patches, but the Docker `mysql:8.4.12` manifest
was unavailable during validation. Recheck the maintained patch/image at
deployment; 8.4.10 is the tested baseline, not a claim to be the newest patch.

This capability probe used one Garage node, two databases on one Docker host
and manually invoked fixture remediation. Test rest-server authentication/TLS
was disabled on the isolated network. It establishes tool/API feasibility,
not production topology, access hardening, a deployed two-store acknowledgement
protocol, power-loss durability, actual seven-day aging, journal-generation
key retirement, Garage block disposal, complete domain purge or the fail-closed
application release gate. Those require the evidence below before user data
exists in production. The temporary harness is not a production runbook.

## Verification evidence required before production

DEP-06 and DEP-08 must supply implementation/drill evidence; this research
provides no claim that the controls already operate. Required reference cadence is
daily freshness/expiry monitoring, a full drill before first production data,
quarterly thereafter, and another drill after backup, storage, schema, or
erasure changes. Each installation records its measured restore-time objective
and responsible operator before launch; daily capture targets a 24-hour RPO.

| Evidence | Required observation |
| --- | --- |
| Installation inventory/configuration | Exact tool/image versions, storage service/region, scopes, schedules, encryption and separately recoverable keys, access separation, locks, lifecycle settings, replicas, manual copies, and host-level snapshots. No secrets in the record. |
| Capture consistency and completeness | Fresh paired MySQL/object fixture recovery; no concurrent schema mutation; recorded cutoff, checksums/counts, every owned resource and durable object covered. Demonstrate treatment of transient prefixes and object versions. |
| Retention boundary | Synthetic copies just before/at/after the seven-day cutoff; missed backup days, renamed groups, future timestamps, copies, denied delete permission, full disk, lock conflicts, and interrupted pruning. Verify unused data in partly shared packs is removed. Observe actual removal in every backend, including noncurrent versions and host snapshots. An expiry flag or a dry run is insufficient. |
| Repository integrity | For restic, structural checks plus scheduled complete data reads, or rotating disjoint reads covering all packs [S8]. Demonstrate corrupted/unavailable data alerts. Successful integrity checks prove neither erasure nor application consistency. |
| Restore privacy matrix | Backup before deletion request; backup during recovery restored after `E`; immediate waiver; under-13 purge; legitimate cancellation; purge interrupted between database/object changes; journal newer than backup; journal host-loss recovery. Test full, database-only, object-only, and selective restoration. |
| Negative release controls | Missing/stale/truncated/tampered journal, lost key, dirty late capture, incomplete object sanitization, direct target access, stale sessions/jobs, repeated replay, clock boundary, and new erasure during release all deny unsafe service. |
| Domain invariants | Erased identity/private data absent; public provenance anonymized; unsafe or unqualified plans unavailable; independent copies intact; scoped evidence isolated; non-erased public/private access and foreign keys correct. |
| Disposal and operational evidence | Job outcome, capture/removal/verification timestamps, oldest eligible copy, journal coverage/cleanup, overdue alerts, and target release checkpoint. Prove plaintext workspace and journal-replica disposal. Retain only non-personal drill evidence under DEC-013. |

Self-hosted file/object deletion evidence establishes absence from inventoried
recoverable copies, not forensic proof about every physical disk block.
Provider-managed expiry needs its documented guarantees plus observed API
inventory, permission/lock failure handling, and any necessary provider
attestation. A generic successful MySQL restore or unit test cannot establish
those operational facts.

## Rights obligations and wording boundary

UK launch remains the DEC-013 assumption. ICO guidance [S16] requires a valid
erasure request to address live and backup systems, clear explanation of backup
treatment, and residual copies held beyond ordinary use pending scheduled
replacement. It describes prompt response, normally within one month, and
recipient notification obligations. It flags guidance review following the
Data (Use and Access) Act. DEP-09 must recheck the applicable rules at launch.

ICO storage-limitation guidance [S17] provides no universal retention period;
purpose and necessity must justify the chosen clocks. Technical inference:
finite copy expiry plus an independently verified restore gate can support
these obligations. That is not an assessment of a lawful basis, an exemption,
international transfer, processor terms, or legal compliance. Those matters
belong to the installation's responsible operator and its privacy/legal review.
Optional recovery must not automatically delay an applicable erasure right;
use DEC-013's waiver path and assess valid requests and scoped exceptions.

The protected journal is a new narrowly scoped retention purpose explicitly
approved by the owner in this session, including minimum replay references,
restricted access, verified disposal, incident overruns, and qualified wording.
This extends DEC-013 for this specific purpose; it does not authorize identifiable
ordinary audit mappings after purge. The anonymous twelve-month receipt remains
separate and cannot identify an account for restoration.
Anonymized attribution alone does not prove that free-text public content is
anonymous; handle identifying content and applicable rights through DEP-09.

The following qualified short-tier wording is approved as the policy template.
DEP-08/DEP-09 must make it true and review installation-specific details before
publishing it:

> Your account becomes inactive when deletion is accepted. You can securely
> recover it for 30 days unless you choose immediate permanent deletion or a
> documented exception applies. At the end of recovery, we remove the specified
> private account data from the live service and anonymize qualifying public
> and shared contributions. Independent copies owned by other people follow
> their own lifecycle. The deletion notice explains the separate public-plan
> rules and any narrowly required security or legal records.
>
> Earlier encrypted recovery copies may still contain removed data. They are
> restricted to disaster recovery, cease to be eligible after seven days from
> capture, and are scheduled for removal within a further 24 hours. We apply
> erasure instructions before a recovered system is made available. We retain
> minimal protected erasure references only while needed to prevent recovery of
> erased data, normally removing them within nine days of permanent erasure.
> If removal fails or a specific lawful hold applies, we investigate and
> explain the affected retention rather than treating it as successful deletion.

Publish actual operator-specific periods and contact/rights-request details,
not template placeholders. If a selected provider delays copy deletion,
describe its supported schedule and qualification explicitly. Avoid “every
copy erased at day 30”, “backups guarantee GDPR compliance”, “anonymous” for
linkable journal entries, or an unconditional eight-day physical-destruction
promise. Retained public-plan wording must also match DEC-014. Existing
DEC-013 review is owner-led, not professional legal approval; this research
does not upgrade it.

## Approval and downstream work

The owner approved the seven-day short tier, protected minimized journal,
qualified disposal/incident handling and delegated Docker reference selection.
The owner separately authorized **Decided** after validation, superseding the
original instruction to preserve **Research required**. Owner remains
**Technical investigation**. No product-policy or provider-selection question
remains open for this reference design. Installation-specific verification and
rights/legal launch review are implementation gates, not claims of compliance.

DEP-06 will implement consistent paired backups, wall-clock expiry/pruning,
isolated targets, off-host/key recovery, inventory, alerts, and drills. DEP-08
will implement durable erasure-intent replication, deadline reconciliation,
idempotent domain/object purge and anonymization, operational-state cleanup,
and the release gate. DEP-09 will approve installation-specific retention and
processor inventory, rights handling, notices, and legal qualifications.
DEP-07 must keep export files out of ordinary recovery sets and use its own
expiry/purge rules. FND-02 rollback must pass the same gate; FND-09 and DEP-04
must preserve operation/deadline truth across restore and inventory any new
jobs with DEP-05 monitoring before enablement.

DEC-012 is removed as an open-decision blocker for DEP-06, DEP-08 and DEP-09;
their other dependencies and acceptance criteria remain. No downstream item is
implemented by this record. No production
credentials, live restore, destructive cleanup, new dependency, or product
compliance claim is introduced.

## Primary research sources

All sources accessed on 4 October 2026. The disposable validation pins its
baseline above; deployment must recheck maintained patches/digests.
Amazon sources are conditional comparisons only; they do not select AWS.

| ID | Primary source | Use |
| --- | --- | --- |
| S1 | [Docker volumes](https://docs.docker.com/engine/storage/volumes/) | Persistent volumes and backup/restore facilities. |
| S2 | [Docker container export](https://docs.docker.com/reference/cli/docker/container/export/) | Mounted volume contents excluded from container export. |
| S3 | [MySQL 8.0 mysqldump](https://dev.mysql.com/doc/refman/8.0/en/mysqldump.html) | Logical backup consistency and DDL/table-engine restrictions. |
| S4 | [MySQL 8.0 PITR](https://dev.mysql.com/doc/refman/8.0/en/point-in-time-recovery.html) | Base backup and binary-log recovery requirements. |
| S5 | [restic snapshot removal](https://restic.readthedocs.io/en/stable/060_forget.html) | Forget/prune separation and retention-selection pitfalls. |
| S6 | [restic repository preparation](https://restic.readthedocs.io/en/stable/030_preparing_a_new_repo.html), [repository design](https://restic.readthedocs.io/en/stable/100_references.html) | Supported destinations and authenticated encryption. |
| S7 | [restic restoration](https://restic.readthedocs.io/en/stable/050_restore.html) | Explicit targets and selective recovery. |
| S8 | [restic integrity checks](https://restic.readthedocs.io/en/stable/045_working_with_repos.html) | Structural checking versus reading stored data. |
| S9 | [S3 expiration](https://docs.aws.amazon.com/AmazonS3/latest/userguide/lifecycle-expire-general-considerations.html), [lifecycle elements](https://docs.aws.amazon.com/AmazonS3/latest/userguide/intro-lifecycle-rules.html) | Asynchronous expiration and current/noncurrent version treatment. |
| S10 | [S3 Object Lock considerations](https://docs.aws.amazon.com/AmazonS3/latest/userguide/object-lock-managing.html) | Locked-version deletion constraints. |
| S11 | [S3 delete-marker replication](https://docs.aws.amazon.com/AmazonS3/latest/userguide/delete-marker-replication.html) | Replica deletion requires separate treatment. |
| S12 | [AWS Backup S3 restoration](https://docs.aws.amazon.com/aws-backup/latest/devguide/restoring-s3.html) | Restore targets and deleted-object resurrection risk. |
| S13 | [AWS Backup Vault Lock](https://docs.aws.amazon.com/aws-backup/latest/devguide/vault-lock.html) | Immutable recovery-point retention limits. |
| S14 | [AWS Backup deletion](https://docs.aws.amazon.com/aws-backup/latest/devguide/deleting-backups.html) | Lifecycle deletion failure and expired recovery points. |
| S15 | [RDS backup retention](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/USER_WorkingWithAutomatedBackups.BackupRetention.html) | Stopped-time exception to configured retention. |
| S16 | [ICO right to erasure](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/individual-rights/individual-rights/right-to-erasure/) | Backup handling, transparency, response and recipient obligations; guidance review notice. |
| S17 | [ICO storage limitation](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/data-protection-principles/a-guide-to-the-data-protection-principles/storage-limitation/) | Justified retention rather than a universal statutory period. |
| S18 | [Garage S3 compatibility](https://garagehq.deuxfleurs.fr/documentation/reference-manual/s3-compatibility/) | Supported object operations and unsupported ACL/versioning/lock APIs. |
| S19 | [Garage cluster deployment](https://garagehq.deuxfleurs.fr/documentation/cookbook/real-world/), [quick start](https://garagehq.deuxfleurs.fr/documentation/quick-start/) | Production replication and single-node demonstration limits. |
| S20 | [Garage encryption](https://garagehq.deuxfleurs.fr/documentation/cookbook/encryption/) | TLS/host encryption requirements and limits. |
| S21 | [Garage 2.3.0 block manager](https://github.com/deuxfleurs-org/garage/blob/v2.3.0/src/block/manager.rs), [reference counts](https://github.com/deuxfleurs-org/garage/blob/v2.3.0/src/block/rc.rs) | Tagged 600-second block-deletion eligibility delay, not a completion guarantee. |
| S22 | [Garage durability and repairs](https://garagehq.deuxfleurs.fr/documentation/operations/durability-repairs/) | Orphan references/blocks and reconciliation after node loss. |
| S23 | [rest-server](https://github.com/restic/rest-server), [releases](https://github.com/restic/rest-server/releases), [restic releases](https://github.com/restic/restic/releases) | TLS/authentication, append-only boundary, maintenance and release baseline. |
| S24 | [MySQL 8.4 InnoDB parameters](https://dev.mysql.com/doc/refman/8.4/en/innodb-parameters.html) | Commit flushing and actual-disk durability qualification. |
| S25 | [MySQL 8.4 semisynchronous replication](https://dev.mysql.com/doc/refman/8.4/en/replication-semisync.html) | Timeout fallback cannot provide unconditional dual acknowledgement. |
| S26 | [MinIO upstream](https://github.com/minio/minio) | Archived, unmaintained Community implementation rejected. |
| S27 | [MySQL support announcements](https://www.mysql.com/support/eol-notice.html) | MySQL 8.0 Sustaining Support and recommended LTS upgrade. |
| S28 | [MySQL 8.4 mysqldump](https://dev.mysql.com/doc/refman/8.4/en/mysqldump.html), [release notes](https://dev.mysql.com/doc/relnotes/mysql/8.4/en/) | Maintained logical-backup baseline and patch revalidation. |
| S29 | [Cryptsetup keyslot erasure](https://gitlab.com/cryptsetup/cryptsetup/-/raw/main/man/cryptsetup-erase.8.adoc), [header backups](https://gitlab.com/cryptsetup/cryptsetup/-/raw/main/man/cryptsetup-luksHeaderBackup.8.adoc) | Journal storage-generation key retirement and surviving-header limitation; not a physical overwrite guarantee. |
