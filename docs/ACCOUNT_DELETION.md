# Account deletion implementation

## Status

DEP-08 remains P2 and incomplete. DEC-021 records the four approved resource
boundaries; the [pre-implementation audit](DEP_08_LIFECYCLE_AUDIT.md) inventories
owned domains and earlier conflicts. The existing account-deletion UI still
performs immediate deletion. No recovery or new purge flow is enabled by the
journal foundation described here.

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
account deletion. DEP-08 still needs lifecycle schema/services, authenticated
request/recovery/waiver and confirmation flows, inactive-account authorization,
retained-plan projection and recovery, every-domain purge and audit minimization,
idempotent scheduled queued work and operational coverage, scoped evidence/holds,
original-clock restore reconciliation, independent object suppression, frozen
release checkpoints, verified all-copy retirement, storage/key disposal and the
DEC-012/DEP-06 synthetic restore/privacy/disposal drill. Production installation
checks and remote CI remain separate required gates.
