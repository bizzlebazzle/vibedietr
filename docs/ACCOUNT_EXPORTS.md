# Account data exports

DEP-07 implements the [DEC-008 version 1 contract](ACCOUNT_EXPORT_RESEARCH.md#approved-version-1-contract).
The profile page links to `/account-exports`. Authenticated users can request,
inspect and download only their own export; administrator status grants no
content access. Requests use the existing `security-sensitive` hashed limiter
(10/minute). Repeated requests reuse the unexpired non-failed export.

## Content and consistency

DEP-08 adds schema 1.1.0. Its diary contains owner-only archived consumption
history when a retained public plan is permanently removed during recovery
(DEC-022). Original chains, nutrition, target snapshots and source entry facts
remain private after successful account recovery. They cannot restore the
removed plan. Final purge deletes the archive. The released 1.0.0 contract
remains unchanged for previously generated exports.

The archive contains eight domain JSON files, a manifest, released Draft
2020-12 schema/dictionary, README and static offline index. Fixed ASCII paths
contain only application-generated identifiers. The manifest inventories every
other file; it excludes itself to avoid a recursive byte-length definition.
Counting units and privacy omissions are documented inside the archive.

Explicit field projections preserve stored decimals, original wording, owned
versions, pinned snapshots and complete diary chains. Numeric JSON tokens are
decoded lexically before schema-directed conversion. Unknown nested fields
fail generation for review rather than leaking data or silently truncating.
SQL and nested source identifiers are typed strings. Source import IDs, URLs,
correlation IDs and raw provider payloads are
omitted; permitted factual/processor provenance remains. Legacy ingredients
and original catalogue submission facts are explicitly in `proposals.json`.
Foreign live account/content graphs are not expanded. Outgoing recipients use
consistent archive-local aliases without querying recipient records.

An InnoDB repeatable-read transaction reads committed content during generation,
not at request time. The locked export row serializes publication, cleanup and
opening downloads. Original snapshots are never rebuilt from current sources;
current effective nutrition is a separate projection of stored source/history.

## Generation, delivery and expiry

`GenerateAccountExport` runs on the existing single-process `default` worker.
Only export/correlation ULIDs enter queue and failed-job payloads. A 75-second
unique dispatch lease and shared 75-second overlap locks coordinate generation
and deletion. Durable row locks, terminal-state checks, deterministic object
keys and transactional publication/audit make retry after upload safe. A
failed generation is visible and permits a new request. Direct operator replay
uses the same export ID only while queued/failed and before expiry. Repeated
queued requests redispatch the same operation after an orphaned dispatch lease
expires, recovering a failed post-commit queue insert without a new export.

The private export resource is created at request time and expires seven UTC
calendar days later; retries and downloads never extend that deadline. A
completed ZIP is uploaded privately to the configured durable disk before
publication. Downloads stream through the owner-authenticated application
route, never a public object or signed storage URL. Expiry is checked at
download opening, including exactly the boundary. An already-open stream may
finish. Download audit records authorized stream opening, not proof the client
received every byte. Responses are private and `no-store`.

`account.export_event` uses the established allowlisted recorder, erasable
subject mapping, correlation and six-month security-event retention class.
Only request, generation, authorized download opening, expiry and deletion
are recorded, with export ID/outcome; attempts and technical failures remain
operational telemetry. General audit retention execution remains separately
governed by FND-05/DEP-08/DEP-09.

## Cleanup and recovery

The UTC scheduler runs `account-exports:cleanup` every minute with one-server
and ten-minute overlap protection. It dispatches `DeleteAccountExport` for
expired exports or missing owners. Download access ends independently at the
deadline; physical deletion follows the next scheduler/worker execution.
Deletion removes both the durable object and crash staging before recording
completion. A missing object is already deleted. Failed deletion retains a
replayable expired state and the next sweep retries without duplicate audit.
Nullable owner references retain cleanup coordinates after current immediate
account deletion; future DEP-08 must integrate this boundary with final purge
and scoped holds. This implementation adds no general-purpose hold store.

The existing DEP-05 monitor raises critical `account_export_retention` with
only a count if a non-deleted export is five minutes overdue, or its owner is
missing. Existing worker/scheduler freshness, default queue depth 25/100,
age 300/900 seconds, retry/final-failure and seven-day failed-job pruning
signals also apply. A functioning queue backend alone does not prove cleanup.

For a retention alert, confirm scheduler and default-worker health, storage
delete capability and audit persistence. Run `account-exports:cleanup` and
observe the count return to zero. Replay one failed deletion ID only after
repairing the fault. Never fetch an archive, expose a storage key or copy
private content into a ticket. Generation replay must not target completed,
expired or deleted exports; request a new export instead.

## Capacity and production verification

The existing limits remain: 60-second jobs, 70-second default worker,
90-second `retry_after`, 256 MiB recycle and 384 MiB container limit. Storage
calls use three-second connect/ten-second total timeouts. Projection checks
35-second/160 MiB allocation bounds between records; assembly additionally
checks 40 seconds, 192 MiB and 64 MiB uncompressed domain JSON. Exceeding a
bound fails the entire export without publishing partial content. No larger
queue/resource topology or unlimited account size is claimed.

The staging directory is worker-local `storage/app/private/account-export-staging`,
0700 with 0600 files and deterministic opaque names. Keep it on private,
encrypted, non-served storage; exclude it from backup capture. Default-worker
singleton topology makes its crash cleanup local to the same worker storage.
Replacing a worker must securely dispose of its writable staging layer;
scaling workers requires revisiting staging cleanup, limits and measurements.
ZIP64/multi-gigabyte output is outside the bounded generator; no large-reader
compatibility is claimed.

Focused verification is `./vendor/bin/sail artisan test tests/Feature/AccountExports`.
Its synthetic scale fixture reads back 1,500 one-off entries and records timing,
allocation and ZIP bytes in `storage/app/testing/dep07/scale.json`. It is local
capacity evidence, not a production benchmark.

Before production activation, verify PHP ZIP support, InnoDB repeatable-read
isolation, private encrypted S3 storage and staging, free staging capacity,
worker/scheduler health, retention alert routing and an actual private upload,
authenticated download, exact expiry and deletion drill. Verify storage object
version/lifecycle and backup disposal honor the original seven-day deadline;
ordinary delete markers are not proof that old object versions are gone.
Perform readiness before and after `config:cache`. Live provider encryption,
backup/version disposal and deployment alert delivery cannot be proved with
local fakes; record those installation checks under DEP-09 release evidence.
