# Backup and restore runbook — DEP-06

## Authority and launch boundary

Decision: DEC-012. The [approved investigation](BACKUP_ERASURE_RESEARCH.md),
[production contract](PRODUCTION_CONFIGURATION.md), [privacy matrix](AUTHORIZATION_PRIVACY_MATRIX.md)
and [audit schedule](AUDIT_RETENTION_SCHEDULE.md#backups-and-restoration) govern
these procedures. FND-02 is complete as the [migration-plan artifact](DOMAIN_MIGRATION_PLAN.md);
DEP-02 is recorded complete in [the roadmap](ROADMAP.md#dep-02--p1--define-production-configuration-and-secret-handling).
DEP-06 remains P1. See [migration rollout](MIGRATION_RUNBOOK.md) and
[drill evidence](DEP_06_VERIFICATION.md).

The repository implements paired-set integrity, logical private-object recovery,
database reconciliation, wall-clock expiry/pruning, freshness monitoring and a
repeatable synthetic drill. These tools do **not** implement DEP-08's independent
journal protocol, domain purge or release gate. No restored production target
may serve users until those controls exist and pass. Missing journals never mean
that no erasures occurred. Current account deletion is still immediate.
Until DEP-08 and installation gates pass, use these procedures with synthetic
data only; do not enable production user-data capture or release.

## Scope, clocks and encryption

| Component | Required treatment |
| --- | --- |
| Entire application MySQL schema | Capture every InnoDB table, migration ledger, indexes, foreign keys, triggers, routines and events with a consistent logical dump and all-table reconciliation manifest. Include private accounts/profiles/security state, legacy ingredients, catalogue versions/provenance/moderation, recipe drafts/text/versions/lineage/organisation, plans/shares/bookmarks/diary/targets/snapshots, audit events and identity maps. Do not omit a table because its feature is private or historical. |
| Database sessions, caches, jobs and failed jobs | Included in the database dump for completeness, but quarantined and invalidated/re-authorized before any release. Never start restored workers or reuse restored sessions or completion markers as erasure evidence. |
| Durable private S3 objects | Logical object bytes plus restricted key, size, SHA-256, installation/account-generation and resource inventory. Include orphaned durable objects or stop for investigation; require ownership even when the live row is missing. Never make a bucket public for a drill. |
| Transient input and export files | Exclude `inputs/` from the configured upload prefix and the current OCR `canonical/` prefix. Declare every additional transient/export prefix explicitly with `--exclude`; `exports/` is the synthetic drill's exclusion, not a newly implemented product storage path. Current production upload storage may share `s3`; inspect its prefix inventory. Durable recipe drafts remain database product data. |
| Independent erasure journals | Two protected, independently recoverable MySQL stores outside application recovery sets, with separate keys/checkpoints. DEP-08 owns acknowledgement, replay and storage-generation disposal. SQL row deletion alone cannot prove journal-page/redo/key disposal. |
| Recovery keys and release artifacts | Preserve restic password, application current/previous encryption keys, journal keys, encrypted-storage recovery material and the exact application image/release separately through the restricted secrets channel. Never place these in the content recovery set, logs, tickets or repository. A missing application key can make persisted encrypted data unrecoverable. |
| Other copies | Inventory replicas, copied/manual/pre-migration sets, host/volume snapshots, restic repository backups, staging, alternate buckets, logs/PITR if enabled and journal storage/key generations. Copying never resets original capture time. Unknown/unbounded copies prevent launch and retirement sign-off. |

Capture at least once per 24 hours. Approximately 24-hour RPO depends on successful
monitored captures; measure and accept each installation's RTO from the full
restore/remediation drill. Eligibility ends at **original capture + seven elapsed
UTC days**, including the exact boundary. Independent daily removal and zero-unused
pruning finish within the following 24 hours. Do not substitute keep-last counts,
newest-snapshot windows or a seven-day pack-file lifecycle.

The `E + 8 days` affected-copy retirement target requires no dirty capture after
irrevocable erasure deadline `E`. Delayed purges, unavailable copies and failed
expiry are incidents. Suppression persists until all affected copies are verified
retired; journal disposal normally targets `E + 9 days`. Do not extend live
30-day account recovery or reset original deadlines. Anonymous, content-free
drill/operations evidence follows DEC-013's twelve-month clock.

Restic 0.19.1 authenticates/encrypts paired sets. Use authenticated TLS rest-server
0.14.0 on an independent host/failure domain, append-only capture credentials and
separate repository-host maintenance access. Garage 2.3.0 uses private per-key/
per-bucket permissions, TLS transport and encrypted host storage; production
requires three replicas on separate failure domains. Do not use AWS ACL operations,
bucket versioning, Object Lock or managed expiry as Garage capabilities. MySQL
8.4 LTS uses matching dump/restore clients; development stays on Sail/MySQL 8.0.
The drill pins the investigation's immutable images. Revalidate maintained
patches/digests and application compatibility before production.

Use restricted encrypted staging (directories 0700, files 0600), without its own
backups or unbounded swap/temp copies. Dispose staging and restore workspaces
promptly, within 24 hours of completion/abandonment. Ordinary file deletion is
not proof of forensic physical-media overwrite. Garage node/block disposal and
all host-layer retention need independent installation evidence.

## Installation record and scheduling

Before execution, the authorized recovery operator records these non-secret
values in installation change control: exact images/releases, source DB and
bucket, independent repository host, every copy/layer and encryption/key custodian,
dedicated capture/maintenance identities, capacity/repacking budget, schedules,
measured RTO, alert recipient, and exact ingress/web/DB freeze and target isolation
commands. Deployment supplies `APP_IMAGE` under the existing
[container operations mechanism](../compose.production-operations.yml).
The repository does not define a production web supervisor or TLS proxy; absence
of installation-specific ingress controls is a stop condition.

Install the locked Composer dependencies, including the S3 adapter added for this
task. Exercise real PUT/GET/LIST and anonymous denial with synthetic objects before
launch. The production check validates configuration, not bucket privacy or I/O.
Store S3 and MySQL credentials in injected secret files; the capture S3 identity
has read/list only. Restore identity writes only to the fresh quarantine bucket.
The restic environment file contains `RESTIC_REPOSITORY` and, where needed,
`RESTIC_REST_USERNAME`/`RESTIC_REST_PASSWORD`; never put credentials in the URL.

The host-side [repository wrapper](../scripts/recovery/repository.sh) needs Bash,
GNU date, Docker and an application image containing Node 22. Set:

```bash
export RECOVERY_APP_IMAGE="$APP_IMAGE"
export RECOVERY_INSTALLATION=installation_namespace
export RECOVERY_WORKSPACE=/encrypted/recovery-workspace
export RECOVERY_ENV_FILE=/run/secrets/restic-capture.env
export RECOVERY_SECRETS=/run/secrets/recovery
# /run/secrets/recovery/restic-password is separately provisioned.
# RECOVERY_NETWORK is optional: use the installation's approved transport network.
```

These paths are operator-provisioned encrypted mounts, not new application disks.
Mount the workspace at `/recovery` in the application operations container.
Initialize the authenticated append-only repository once with the pinned restic
image and injected credentials/password file; never run `init` over an existing
repository or replace its key on failure. Test key recovery from the independent
secrets channel, without app-host access, before launch.

On the selected independent repository host, use this service command with an
encrypted repository mount, restricted htpasswd/certificate/key files, private
bind address and numeric UID/GID from the installation record. Select the image's
actual server binary explicitly:

```bash
docker run -d --name vibedietr-recovery --restart unless-stopped --read-only --user "$RECOVERY_SERVICE_UID_GID" -p "$RECOVERY_BIND_ADDRESS:8000:8000" -v "$ENCRYPTED_REPOSITORY_ROOT:/data" -v "$RECOVERY_SERVER_SECRETS:/secrets:ro" --entrypoint /usr/bin/rest-server restic/rest-server:0.14.0@sha256:d2aff06f47eb38637dff580c3e6bce4af98f386c396a25d32eb6727ec96214a5 --path /data --htpasswd-file /secrets/htpasswd --append-only --tls --tls-cert /secrets/tls.crt --tls-key /secrets/tls.key
```

Use trusted certificates and bounded Docker logging per installation controls.
Test authenticated capture, denied anonymous access and denied capture deletion;
maintenance uses restricted filesystem access on this independent host. No
ordinary backup client receives that access. Host mounts and networking remain
installation operations, never restore-time policy bypasses.

Schedule paired capture every day and expiry independently every day on the
repository host, never conditional on a successful new backup. Run `monitor`
after each and at least daily using the existing DEP-05 installation collector;
alert on any nonzero exit, stale/missing capture or expiry proof, future timestamp,
lock/permission/disk failure, corruption, dirty capture or unknown copy. Emit only
operation, installation/release, UTC time, aggregate counts, safe outcome and
correlation. Detailed restic output and inventories remain in restricted
workspaces, not application logs. These are operator infrastructure tasks,
not newly dispatched Laravel product jobs.

## Paired capture

1. Confirm the exact installation and release; inventory all layers and capacity.
   Verify current independent DEP-08 journals and that no due/incomplete erasure
   would enter a dirty post-deadline set. Stop on missing/stale evidence.
2. Block ingress and direct writers using the installation's recorded controls;
   let in-flight requests finish. Stop scheduler, then supervised workers gracefully:

   ```bash
   docker compose -f compose.production-operations.yml stop scheduler
   docker compose -f compose.production-operations.yml stop queue-security-notifications queue-default
   ```

   Maintenance UI alone is insufficient. Exclude DDL/backfills and external writers;
   verify no active writes or DDL. Keep this freeze through object inventory and
   final journal reconciliation. Record cutoff with `date -u +%Y-%m-%dT%H:%M:%SZ`.
3. Create a new restricted `set/` directory in the encrypted workspace. With
   `APP_CONTAINER`, `DB_CONTAINER` and `SOURCE_DATABASE` from the installation
   record, execute (secret client file must already exist inside MySQL):

   ```bash
   docker exec "$APP_CONTAINER" php artisan operations:reconcile-database --quiesced --write=/recovery/set/database.json
   docker exec "$DB_CONTAINER" mysqldump --defaults-extra-file=/run/secrets/backup.cnf --single-transaction --quick --hex-blob --no-tablespaces --set-gtid-purged=OFF --routines --events --triggers "$SOURCE_DATABASE" > "$RECOVERY_WORKSPACE/set/database.sql"
   docker exec "$APP_CONTAINER" php artisan operations:recovery-objects capture /recovery/set/objects --quiesced --ownership=/recovery/ownership.json
   ```

   Add reviewed `--exclude=prefix/` arguments where applicable. The operator
   inventory JSON maps each durable key to `owner_generation` and `resource`;
   generation is non-reusable and linked to the installation's independent journal,
   never merely a rolled-back integer user ID. The current app has no persistent
   durable-object workflow/account-generation protocol: DEP-08 must supply the
   live inventory before such production data is enabled. Empty durable scope
   must be demonstrated, never assumed from a missing table. Unknown or missing
   durable objects fail capture. No raw Garage-volume tar is a normal recovery set.
4. Reconcile the database again against `database.json`, review the final journal
   checkpoint and source/bucket identities, and seal using Node 22 in the deployment
   image (the `/scripts` and `/work` mounts are shown in the wrapper):

   ```bash
   node /scripts/recovery-set.mjs seal /work/set "$RECOVERY_INSTALLATION" "$RELEASE_ID" "$CAPTURE_CUTOFF"
   RECOVERY_QUIESCED=yes bash scripts/recovery/repository.sh capture
   ```

   `CAPTURE_CUTOFF` is the original freeze/capture time from step 2; sealing a
   copied set never grants a new cutoff. Any incomplete restic backup is failure
   even if it created snapshot metadata. Register its temporary/recovery copies
   for retirement; do not advertise it as a successful recovery point.
5. Record the full snapshot ID, cutoff, release, aggregate table/object counts,
   duration, final checkpoint and safe result. Keep manifests restricted and
   inside authenticated restic recovery. Resume the original service through the
   recorded deployment procedure only after its own integrity/readiness passes.
   Dispose staging; preserve separate content-free monitoring state.

## Expiry and integrity

On the repository host use its separately provisioned secrets and workspace, set
`RECOVERY_REPOSITORY_PATH` to the repository filesystem and run:

```bash
RECOVERY_MAINTENANCE=yes bash scripts/recovery/repository.sh expire
bash scripts/recovery/repository.sh monitor
```

The wrapper selects explicit expired IDs across **every** group by wall clock,
rejects future/malformed inventory, forgets those IDs, always retries
`prune --max-unused 0`, fully reads retained packs with `check --read-data`, and
verifies no expired IDs remain. It writes `expiry-success.json` only after success.
Share that restricted content-free proof with the capture host's monitor through
the approved operations channel. Missing/future/24-hour-old proof fails monitoring.
Monitoring also rejects a missing/24-hour-old paired capture and any eight-day-old
snapshot. Never filter away unknown groups or count an untagged/manual snapshot
as the scheduled paired capture.

Restic pruning needs free space and exclusive repository access. Do not bypass
locks or run arbitrary unlock commands; establish that no operation remains and
use the tool's reviewed stale-lock recovery. Permission, disk, lock or integrity
failure means no expiry success. Stop capture if retention cannot be honored;
raise the incident, preserve suppression, repair/prune/recheck and inventory
every additional layer. Snapshot disappearance alone proves neither unused-pack
removal nor host/replica disposal. Complete all-copy retirement before journal
disposal. A failed prune is retried even if the next inventory has no expired IDs.

## Restore into quarantine

1. Verify operator authority, original installation/capture/release and a recovery
   point less than seven days old. Provision a **fresh** isolated MySQL 8.4 target
   and empty private Garage bucket with matching clients and encryption/TLS.
   Supply a separately recovered restic key and exact app current/previous keys.
   No ordinary web, worker, scheduler, mail, OCR or export process may start;
   block external bucket access and direct DB access except restricted operators.
   Disable MySQL event scheduling and unneeded binary/general/slow logging.
2. In a new encrypted restricted workspace, run:

   ```bash
   RECOVERY_QUARANTINED=yes bash scripts/recovery/repository.sh restore "$FULL_SNAPSHOT_ID"
   ```

   It refuses unknown/expired/future snapshots and an existing quarantine path,
   reads every pack, restores through authenticated restic, and verifies the paired
   manifest, installation, original time and every file size/SHA-256. Failure
   leaves quarantine closed. The set is at `quarantine/work/set/`.
3. Verify the target database has zero application tables, and the target bucket
   is empty/private using its own credentials. Use the target client secret file,
   never the source connection. Import and reconcile with the **captured release**:

   ```bash
   docker exec -i "$RESTORE_DB_CONTAINER" mysql --defaults-extra-file=/run/secrets/restore.cnf "$RESTORE_DATABASE" < "$RECOVERY_WORKSPACE/quarantine/work/set/database.sql"
   docker exec "$RESTORE_APP_CONTAINER" php artisan operations:reconcile-database --quiesced --compare=/recovery/quarantine/work/set/database.json
   docker exec "$RESTORE_APP_CONTAINER" php artisan operations:recovery-objects restore /recovery/quarantine/work/set/objects --quiesced --isolated-empty-target
   docker exec "$RESTORE_APP_CONTAINER" php artisan operations:recovery-objects verify /recovery/quarantine/work/set/objects --quiesced
   ```

   `RESTORE_APP_CONTAINER` has only target DB/bucket settings and the encrypted
   workspace mount. The object command refuses a nonempty target; partial failures
   do not permit release or blind overwrite. Re-provision a fresh disposable target
   or use the separately reviewed resumable remediation procedure.
4. Test representative owner/public/private/share access with the existing
   application route/policy checks, including guest, non-owner and administrator
   denials; verify original text, immutable versions, independent copies, private
   object anonymous denial, and every required survivor's checksum. Do not expose
   the target to perform smoke tests. The drill uses PHPUnit HTTP requests internally.
5. **Keep quarantine.** DEP-08 must verify two current independent journals,
   integrity/sequence/freshness, all pending/cancelled/due/incomplete/completed
   transitions and original deadlines. Replay purge/anonymization and DEC-014,
   inspect scoped evidence, and suppress objects independently of restored DB rows.
   Full, DB-only, object-only and selective restores all require this gate.
   Restored completion markers never skip remediation. Invalidate credentials,
   sessions, download links, jobs, failed work, caches and locks; require fresh
   authentication and FND-13/FND-14 readiness. Reconcile a final current checkpoint
   under freeze, bind sign-off to target/set/release/checkpoint, and invalidate it
   after any new intent or restore write. No DEP-06 command releases traffic.
6. After the implemented DEP-08 gate and operator approval, switch only the verified
   target through installation deployment controls, run readiness/authorization
   checks, capture a new sanitized set, and dispose recovery material. If the gate
   is unavailable or any evidence fails, leave traffic closed and escalate.

## Drills and incidents

Run `bash scripts/recovery/drill.sh` from the WSL repository root after Sail
dependencies are installed. It creates synthetic data, fresh containers and an
internal network, with no existing DB/volume mounts or published ports, and
cleans its resources on exit. `RECOVERY_DRILL_APP_IMAGE` may select the verified
PHP 8.4/Node 22 application runtime. The default is the existing Sail-built image.
Set `RECOVERY_DRILL_FULL_SUITE=yes` to run the full application suite against the
disposable MySQL 8.4 target after recovery checks; those tests replace only its
synthetic fixtures. Do this before production and on database/schema changes.
Single-node Garage, one Docker host, HTTP and disabled rest-server authentication
are strictly synthetic drill accommodations, never a production topology.

Before first production data, quarterly, and after schema/storage/backup/erasure
changes, run the drill **and** installation-specific key/failure-domain/TLS/copy
retirement and DEP-08 privacy matrix checks from the investigation. The automated
drill exercises actual expiry of a synthetic aged snapshot; it cannot prove eight
days of real elapsed host-layer aging, block disposal or journal key retirement.
Record only non-personal aggregates and measured durations. Loss of all journal
truth, uncertain copies, corrupt packs, failed pruning or unexpected reconciliation
keeps recovery closed; never repair availability by weakening privacy controls.
