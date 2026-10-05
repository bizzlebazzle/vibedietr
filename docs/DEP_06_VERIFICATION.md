# DEP-06 recovery verification

## Scope and result

Repository recovery/runbook implementation was verified on 5 October 2026.
DEP-06 stays P1 and is conditionally complete pending remote CI and the
installation-specific launch gates below. DEC-012 is Decided; FND-02 explicitly
records completion in its planning artifact and DEP-02 is marked complete.
This evidence does not approve serving restored production data.

The repeatable [synthetic drill](../scripts/recovery/drill.sh) uses the immutable
MySQL 8.4.10, Garage 2.3.0, restic 0.19.1 and rest-server 0.14.0 images recorded
in the investigation. It creates two fresh MySQL containers, private source and
quarantine buckets, an encrypted append-only repository and an internal Docker
network. It mounts no existing application database/storage volumes and publishes
no ports. All users, content, keys and credentials are synthetic. Repository code
is read-only; Laravel runtime storage is a disposable mount. Exit cleanup removes
its containers/network/workspace, including keys and recovered content.

Commands exercised:

```bash
bash scripts/recovery/drill.sh
RECOVERY_DRILL_FULL_SUITE=yes bash scripts/recovery/drill.sh
./vendor/bin/sail artisan test tests/Feature/Operations/DatabaseReconciliationTest.php
./vendor/bin/sail npm run test:recovery
```

The full MySQL 8.4 compatibility run passed **1,062 tests / 6,827 assertions**
in 122.40 seconds; the combined drill took 197 seconds with warm local images.
The explicit restored-access test passed **31 assertions** without seeding or
refreshing the restored database. Measured local drill time includes setup,
migrations, capture, negative cases and expiry, so it is not a production RTO.
Installations must measure/accept their own full remediation/release duration.
The final recovery-only run passed in **72 seconds** and reconciled **73 tables
and 116 foreign-key columns**, with three accounts, two recipes/versions, one
private shared plan and two durable objects. Source and target counts/digests
matched; all three transient/export fixture keys were absent from restored storage.

## Recovery and rollout observations

| Check | Successful observation |
| --- | --- |
| Capture consistency and pair | Frozen synthetic database, matching logical dump client, complete all-table baseline, owned-object inventory and authenticated/encrypted paired restic capture. |
| Restore validity | Full eligible snapshot ID, installation and original timestamp verified; every staged file size/SHA-256 reconciled before SQL import. Unknown/expired/future/copy-reset cases are rejected by repeatable policy tests. |
| Database integrity | Every base table's exact row count and duplicate-sensitive SHA-256 reconciled; original column definitions/indexes/FK rules and migration code/ledger retained. All declared FK anti-joins passed, including nullable/composite-key handling. Focused tests create a real orphan with constraints temporarily disabled and verify rejection after re-enabling them. |
| Public/private data | Restored finalized public recipe readable by guest/owner/non-owner/admin through real Laravel HTTP requests. Private recipe/version/text readable by owner; guest/non-owner/admin denied content and unauthorized edits. Private plan owner and acknowledged recipient retain access; guest/admin denied. Immutable current-version parent linkage remains correct. |
| Private objects | Two independently owned durable objects survive with exact bytes/checksums. `inputs/`, `canonical/` and the synthetic export prefix are excluded; anonymous object GET is denied. Unknown ownership, changed bytes, missing survivors and nonempty-target restoration fail. |
| Independent repository/key boundary | rest-server restart retains the repository; capture-client deletion is denied; maintenance uses separate host filesystem access. Wrong repository password and deliberately damaged retained pack both fail. Keys are outside the content recovery set. Actual off-host loss/key recovery remains an installation test. |
| Expiry | A synthetic eight-day-old snapshot is ineligible, then actually forgotten/pruned with zero tolerated unused data. A recorded retired-only blob is readable before expiry and unreadable afterward, while every retained pack passes a full data read. Repeated expiry succeeds when no expired metadata remains, exercising prune retry. |
| Migration pre/postflight | An inspected temporary release adds one unused nullable column to the populated restored target. Pending-migration postflight fails; migration application passes strict additive preservation; the reviewed disposable down migration restores exact baseline schema/counts/digests. No repository migration or existing development data changes. |
| Monitoring | Missing/stale/foreign/future expiry evidence, missed paired captures, future snapshots and eight-day overdue removal fail the repeatable checks. A successful actual expiry writes fresh evidence and the live synthetic monitor passes. |
| Information handling | Restricted staging contains raw manifests/content; shared output contains safe results and aggregate counts only. No production credentials, real personal data, keys or recovery artifacts are committed. |

Focused PHP tests cover same-count changed content, orphaned references, unknown
or edited migration history, additive preservation/row loss, mandatory freeze and
non-overwriting baseline creation. Node tests cover complete pairs, checksums,
extra files, symlinks, original age/installation, exact seven-day boundary, all
groups/missed captures, malformed/future inventories and monitoring failures.
Node coverage runs in the existing `Frontend build` CI job. The isolated drill
is a host-side repeatable check; it is not an enabled production schedule.

## Required installation and downstream gates

- Remote `Backend tests`, `PHP formatting`, `Static analysis` and `Frontend build`
  statuses must pass before merge; branch protection has not been inspected.
- Supply maintained images and a real production app image, TLS/authenticated
  rest-server, encrypted staging/host storage and Garage replication across three
  failure domains. Confirm least-privilege capture/restore/maintenance identities,
  independent key recovery, capacity/repacking budget and actual ingress isolation.
- Deploy monitored daily paired capture and independent expiry. Demonstrate real
  wall-clock aging, every copied/host/replica layer's retirement, Garage node/block
  disposal, lock conflicts, interrupted pruning and full-disk/permission failure
  recovery. Synthetic backdating proves executable selection/removal, not elapsed
  installation aging or physical-media destruction.
- Implement DEP-08's two independently durable journal acknowledgements, fresh
  sequence/checkpoint truth, non-reusable generations, replay of due/incomplete/
  completed/cancelled erasures, scoped evidence and public/shared anonymization,
  object suppression, credential/session/job/cache invalidation and final release
  checkpoint. Test the investigation's entire negative/privacy matrix, including
  selective/DB-only/object-only recovery, journal host loss, key/storage-generation
  retirement and new intent during release. DEP-06 never opens restored traffic.
- Preserve original deadlines and suppressions through incidents; complete journal
  disposal only after all affected copies and key/header/storage generations retire.
  Record the operator/RPO/measured RTO and perform preproduction, quarterly and
  schema/storage/backup/erasure-change drills. DEP-09 owns installation-specific
  processor/rights/notices and legal qualifications.

No user-interface or product flow changes were made. Browser/accessibility checks
are not applicable to these operator commands. Production topology, legal review,
full erasure lifecycle and service-release approval are not claimed by this drill.
