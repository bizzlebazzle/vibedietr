# Schema rollout and rollback — DEP-06

Decision: DEC-012. This runbook applies [FND-02's approved stages](DOMAIN_MIGRATION_PLAN.md)
to the implemented MySQL schema and [paired recovery procedures](BACKUP_RESTORE_RUNBOOK.md).
It authorizes no destructive migration. No migration files or live schema are
changed by DEP-06; the drill's added column exists only in its disposable target.

## Preflight

1. Record installation, source database identity, current/next image and migration
   list/hashes, operator, rollback boundary, time/locking budget and exact planned
   schema/data changes. Confirm DEP-02 static/cached configuration and dependency
   readiness using the existing production/health commands. MySQL 8.0-to-8.4
   upgrade is a separately rehearsed logical migration, never an in-place upgrade
   inferred from this task. Matching 8.4 dump/restore clients are used by the drill.
2. Run the release's relevant focused schema/domain/authorization tests against
   populated fixtures. Read every `up` and `down`; classify expand, backfill,
   cut-over or contract. Inspect table sizes, free disk, active transactions,
   metadata locks and long-lived workers through restricted MySQL consoles:

   ```sql
   SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH
   FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE();
   SELECT COUNT(*) AS active_transactions FROM information_schema.INNODB_TRX;
   SELECT LOCK_STATUS, COUNT(*) AS lock_count
   FROM performance_schema.metadata_locks
   WHERE OBJECT_SCHEMA = DATABASE() GROUP BY LOCK_STATUS;
   SELECT @@version, @@foreign_key_checks, @@GLOBAL.event_scheduler;
   ```

   `TABLE_ROWS` is a capacity estimate, not reconciliation. Do not log transaction
   SQL, connection identities or row contents. Any unexpected active writer,
   pending lock, disabled constraint, non-InnoDB table or exceeded rehearsed
   capacity/lock budget blocks rollout. DDL may implicitly commit; a Laravel
   transaction does not make MySQL schema rollback atomic.
3. Freeze **all** writers/DDL using the backup runbook, capture a verified eligible
   paired restore point, and verify current independent erasure truth. A backup
   without a successful restore drill or available release gate is not contract
   rollback capability. First production enablement still requires DEP-08.
4. While frozen, use the **next release's** code/migration directory to write:

   ```bash
   php artisan migrate:status
   php artisan operations:reconcile-database --quiesced --write=/restricted/preflight.json
   php artisan migrate --pretend --force
   ```

   Run these inside the existing deployment application container, not host PHP.
   Review the exact pending set against the approved list. The reconciliation
   command rejects unknown applied migrations and records every release-file
   SHA-256, every column definition/index/FK deletion/update rule, exact row counts
   and deterministic duplicate-sensitive digests. `--pretend` output is restricted
   and only previews SQL; it neither proves lock time nor validates a PHP migration's
   arbitrary side effects. Source counts, ownership/provenance and immutable
   text/version/snapshot fields must match the task's approved expected results.

## Apply and postflight

1. **Expand:** add nullable/new structures first; preserve existing fields and data
   and old read/write paths. Apply only the reviewed release with
   `php artisan migrate --force`, through one authorized deployer while other
   migrators/writers remain stopped. Never use `--graceful` to disguise failure.
2. Before resuming any writes, run:

   ```bash
   php artisan migrate:status
   php artisan operations:reconcile-database --quiesced --compare=/restricted/preflight.json --additive
   ```

   Postflight requires all release migrations applied, unchanged applied-file
   hashes and approved release-file inventory, every original column definition,
   index and FK retained, and exact counts/digests of every old column's data.
   Only migration-ledger row additions and additive schema/new-table data are
   allowed by this generic check. Ordinary restore uses `--compare` **without**
   `--additive` for exact schema/data/ledger equality. Check stdout/exit status;
   failures never authorize partial rollout or service release.
3. For intentional transformations/backfills, define task-specific before/after
   reconciliation **before** execution; the generic strict additive gate will
   correctly reject changed source values/types. Do not switch off a failed check.
   Use current schema queries and the [existing legacy backfill procedure](OPERATIONS_RUNBOOKS.md#legacy-ingredient-catalogue-backfill):
   dry-run classification, zero failed/unprocessed/changed-source counts, explicit
   duplicate/ambiguous review, stable unique provenance, resumable batches and a
   second run with zero newly processed rows. Legacy source values stay unchanged.
4. **Validate, then cut over:** test direct-route ownership/privacy, current-version
   parent relationships, snapshot preservation, mappings, public/pending visibility,
   original text and shared/independent copies. All declared FKs are checked by
   anti-join queries, including composite nullable keys; imported SQL that disabled
   constraints is therefore not trusted merely because it enabled them afterward.
   Historical recipe/plan references that deliberately have no live FK need the
   affected domain's snapshot tests; never require deleted historical sources to
   reappear. Run the relevant existing test suites and private-object reconciliation.
5. Record pre/post aggregates and hashes in restricted evidence, the exact applied
   set and safe outcome; rebuild configuration caches and restart deployment
   processes gracefully per DEP-04. Confirm production-check/readiness and
   owner/public/private route behavior before enabling traffic. Compare only
   frozen snapshots; resumed writes invalidate generic exact baseline comparison.
6. **Contract:** wait for the approved stabilization window, zero legacy writes,
   no unexplained rows/ownership/orphans, no code/job/export/rollback dependency,
   independently protected target-only data, successful restore/release evidence,
   and explicit product/data/operations approval of the exact destructive diff.
   No existing roadmap task or this runbook authorizes removal of legacy columns.

Digests read all rows, sort only SHA-256 row hashes, distinguish null/binary values
and include duplicates. Budget memory for row hashes in the largest table and
rehearse total freeze duration; abort on resource failure. They do not prove
semantic ownership or privacy on their own. Reconciliation metadata itself is
restricted evidence; do not publish hashes or identifiers in telemetry.

## Abort and rollback

Stop progression on pending/unexpected migrations, edited applied code, row loss,
changed source text, ownership mismatch, orphaned references, failed checks,
unexpected locking, privacy disclosure or irreconcilable target-only writes.
Leave writers stopped, preserve evidence and select the approved response:

| Phase | Supported response |
| --- | --- |
| Expand, before new structures contain unique data | A reviewed down migration may remove only proven unused additive structures. Reconcile against the preflight baseline after rollback. `migrate:rollback` is not a universal safe deployment command. The drill exercises one unused nullable column only. |
| Expand after target-only writes | Disable use/revert compatible application code while retaining schema/data. Do not run down migrations that would delete new data; design the separately approved reconciliation/forward fix. |
| Backfill/validation | Stop safely, keep legacy authoritative, preserve ledger/candidates and resume idempotently after correction. Never delete or rewrite legacy source rows or guess duplicate identities. |
| NUT-03 read cut-over | Set `CATALOGUE_READ_CUTOVER=false`, clear/rebuild config cache and test legacy authenticated URLs plus public catalogue 404s. Follow the [implemented rollback](OPERATIONS_RUNBOOKS.md#shared-catalogue-read-cut-over-rollback); retain catalogue/mappings/versions and mutation-denial boundaries. |
| Contract/destructive change | No simple application/schema rollback is promised. Use the pre-approved forward fix or restore a verified paired set into a fresh quarantine target. Reconcile later writes explicitly; never overwrite live storage or resurrect erased data. DEP-08's current-journal/release gate applies even to schema rollback, partial imports and object-only restoration. If unavailable, remain closed. |

Deploying a previous image is safe only when it is compatible with the retained
schema, stored data and jobs. Restart the existing supervised worker/scheduler
topology using the selected image and the installation's web rollout procedure;
image reversal alone is not data recovery. Never blanket-roll back the latest
batch, use `migrate:fresh`, or equate an untested backup with rollback capability.
