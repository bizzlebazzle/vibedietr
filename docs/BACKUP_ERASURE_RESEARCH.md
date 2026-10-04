# Backup erasure investigation — DEC-012

## Status and scope

Technical investigation recorded on 4 October 2026. DEC-012 remains **Research
required**, owned by **Technical investigation**. This is a recommendation and
evidence record, not an approved retention policy, implemented backup facility,
restore runbook, or legal approval. DEP-06, DEP-08, and DEP-09 remain blocked.

The owner confirmed during this investigation that users should self-host the
application, ideally with Docker; no hosting or backup provider is selected.
The existing production contract still requires MySQL, database-backed
sessions/cache/queues, and private S3-compatible storage. Self-hosting does not
authorize replacing that storage contract with local application files.
Each installation's operator must identify its actual storage, backup, and
infrastructure services; AWS variable names do not select Amazon services.

## Repository evidence and capability boundary

The documented context command rejects DEC-012 because it accepts roadmap IDs
in `AAA-NN` format. Context was generated for DEP-06, DEP-08, and DEP-09 instead.
The investigation inspected their routed requirements and the following
implementation evidence without accessing production data or credentials:

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

No live backup configuration, retention-expiry observation, recovery target,
off-host copy inventory, or restore-drill evidence was found. No deployment or
backup tool was installed or configured during this documentation task.

## Facilities that can support the design

Primary documentation establishes these capabilities, not their deployment in
VibeDietr. Source links and access date appear below.

| Facility | Confirmed capability | Consequence for this installation model |
| --- | --- | --- |
| Docker volumes [S1] | Volumes persist independently of containers and can be backed up/restored. Container export omits mounted-volume contents [S2]. | Docker supplies neither a backup schedule nor retention/erasure propagation. Inventory volumes and bind mounts. A container image/export is not a database backup. |
| MySQL 8.0 logical backup [S3] | `mysqldump` supports a consistent InnoDB transaction snapshot; concurrent DDL and nontransactional tables require additional controls. | Recommend application-database dumps with a schema/version manifest. An arbitrary tar of a running database volume does not establish a consistent backup. |
| MySQL point-in-time recovery [S4] | Recovery requires a base backup and subsequent binary logs. | PITR is optional additional infrastructure, not supplied by current Compose. Logs and replicas carry personal data and need the same bounded lifecycle and restore gate. |
| restic, candidate tool [S5–S8] | Encrypted authenticated repositories, filesystem/SFTP/S3-compatible destinations, explicit restore targets and subsets, snapshot removal plus data pruning, and integrity checks. | Recommend a pinned, operator-scheduled backup container and a separate failure domain. Neither automatic application erasure nor immutable expiry is supplied by restic itself. It is not currently installed. |
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

## Alternatives and technical recommendation

| Alternative | Assessment |
| --- | --- |
| Fixed wall-clock expiry alone | Small operational burden, but restoration before expiry resurrects erased data. Reject as a complete design; finite expiry still needs erasure replay. |
| Tiered daily/weekly/monthly retention plus replay | Technically viable with restic or supported provider recovery points. Longer corruption-detection coverage costs more, leaves personal data in recovery copies longer, and extends replay-record retention. No requirement or operator-approved period justifies monthly/yearly copies here. |
| One short retention tier plus replay | Recommended starting design for Docker self-hosting: daily consistent database/object recovery sets, seven-day eligibility, bounded pruning, and an independent erasure journal. Fewer copies and clocks are easier to verify. Requires the operator to accept the recovery trade-off. |
| Per-account backup rewriting or cryptographic erasure | No demonstrated selective removal in shared MySQL dumps or deduplicated packs, nor per-account key architecture covering every domain and processor. Reject for this task; deleting the shared repository key would destroy other users' recovery. |
| Immediate destruction of all affected recovery sets | Possible on mutable storage but reduces recovery for everyone and cannot bypass a genuine immutable lock. Not a general replacement for scheduled expiry and replay. |

The seven-day proposal is an exposure/recovery trade-off, not a legal period or
an existing product decision. A 30-day recovery window operates on protected
live account state; it does not require keeping erased data in backups for 30
more days. A supported longer option is daily points for seven days and weekly
points through 30 days, with the same replay controls. The owner must justify
that longer horizon before it becomes policy.

## Proposed clocks, scope, and expiry rules

All periods below are **unapproved recommendations**. Use elapsed UTC time for
backup ages; preserve the approved account recovery clock. Define `E` as the
account's irrevocable erasure deadline (normal recovery expiry or an approved
immediate-purge instruction), not the completion time of a delayed job.

| Information or operation | Proposed period/control | Support and qualification |
| --- | --- | --- |
| Database and durable private-object recovery sets | At least one successful paired set per 24 hours; sets cease to be eligible at capture time plus seven days. | Daily schedule is operator-managed. Approximately 24-hour recovery-point objective requires monitored successful capture, not merely a cron entry. Actual restore-time objective must be measured and accepted by the operator. |
| Expired recovery data | Independent daily expiry sweep; finish snapshot removal and unreferenced-data pruning within 24 hours of eligibility. | Target recoverable-copy removal by `E + 8 days` if no dirty capture occurs after `E`. This is an operating target, not a provider physical-media guarantee. Failed deletion is an incident, never evidence of success. |
| Manual/pre-migration copies, replicas, off-site copies, host snapshots, PITR logs if enabled | Same seven-day maximum recovery eligibility and 24-hour removal target; original capture time follows copied data. | No indefinite keep-last, migration exception, or copy-age reset. Full-host/object-server snapshots need inspection or exclusion; a separate provider retention rule can invalidate the proposal. |
| Plaintext dump/object staging and restore workspaces | Encrypted restricted staging; remove promptly after use, at most 24 hours after completion or abandonment. | No backup of staging, restore workspace, temporary exports, application logs, or credentials as ordinary user-content recovery sets. Minimize host swap/temp-file leakage. |
| Transient import/OCR sources, abandoned uploads, export archives/download credentials | Exclude from ordinary recovery sets; live cleanup remains DEC-013's 24-hour/seven-day schedules, with earlier account purge. | Prefix/bucket separation must be proven. Restored database import/export metadata cannot restart expired processing or recreate an expired archive. Durable recipe drafts remain domain data and require backup/purge coverage. |
| Independent erasure journal and its replicas/backups | Until every potentially affected copy is verifiably retired; normal disposal target `E + 9 days` under the proposed short tier. | Eight-day removal target plus one day for verification/journal disposal. Its own recoverable copies must support that disposal deadline without adding another seven-day tail. Extend only for a recorded unresolved recovery-copy incident or scoped hold; no deletion while a dirty copy remains restorable. This needs explicit owner privacy-policy approval. |
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
then verify removal. These are requirements for a future pinned-tool runbook,
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

## Verification evidence required before production

DEP-06 and DEP-08 must supply implementation/drill evidence; this research
provides no claim that the controls already operate. Recommended cadence is
daily freshness/expiry monitoring, a full drill before first production data,
quarterly thereafter, and another drill after backup, storage, schema, or
erasure changes. Cadence and measured recovery objectives need operator
acceptance.

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

The recommended journal is a new narrowly scoped retention purpose. DEC-013
already requires purge replay, but does not approve identifiable journal fields
or their period after final purge. Obtain explicit owner review of necessity,
access, disposal, incident overruns, and notice wording. Do not silently treat
the anonymous twelve-month receipt as approval to retain account identifiers.
Anonymized attribution alone does not prove that free-text public content is
anonymous; handle identifying content and applicable rights through DEP-09.

Suggested user wording, **draft for the short-tier option only**, to be approved
and made true by DEP-08/DEP-09 before use:

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

## Precise remaining decisions and downstream work

The investigation establishes a supportable design direction, but cannot
approve a retention policy or assert capabilities of an unselected endpoint.
The following must be resolved before recording a final DEC-012 decision:

| Responsible owner | Remaining decision | Evidence-backed options and recommendation |
| --- | --- | --- |
| Product owner / responsible self-hosting operator | Accept recovery coverage, expiry periods, and drill cadence; identify a reference backup stack and private-storage implementation. | Recommend Docker-scheduled MySQL dumps plus restic to an independent mutable repository, daily sets eligible for seven days, 24-hour deletion target. Alternatively accept seven-day daily plus weekly points through 30 days with longer exposure and journal retention. Record the actual endpoint and its hidden-copy/lock behavior. |
| Product owner, with privacy/legal advice where needed | Approve the minimized post-purge journal purpose/fields, bounded disposal policy, incident/hold handling, rights response, and qualified notice. | Recommend a separate protected journal until all dirty recovery copies are verifiably retired, normally nine days for the short tier. If linkable retention is rejected, all dirty recovery copies must be verifiably destroyed before suppression identity is removed; immutable or unknown copies make that option unavailable. No safe ordinary restore without either approach. |
| Technical investigation | Validate the selected stack's documented expiry and recovery semantics against the chosen policy. | Pin source/tool versions and resolve any provider-managed, versioned, replicated, immutable, or full-host copies that exceed the period. An implementation drill belongs to DEP-06/DEP-08 after decision approval, not this task. |

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

No downstream item is implemented or unblocked by this record. No production
credentials, live restore, destructive cleanup, new dependency, or product
compliance claim is introduced.

## Primary research sources

All sources accessed on 4 October 2026. restic's stable documentation identified
itself as 0.19.1; that is research evidence, not an installed/pinned release.
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
