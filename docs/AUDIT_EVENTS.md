# Audit-event developer guide

## Scope and policy status

FND-05 provides the ordinary application audit-event store. It implements the
technical model approved by DEC-013 and `AUDIT_RETENTION_SCHEDULE.md`. That
policy was security reviewed and received an owner-led legal-risk review; it
did not receive professional legal approval and must not be described as
legally approved. The review gates and material-change triggers in DEC-013
remain in force.

The store is deliberately not a copy of domain history. Recipe bodies,
ingredient or instruction text, nutrition values and targets, plan or diary
content, OCR/import content, request bodies, credentials, raw IP addresses,
full user agents, and legally protected evidence do not belong in it.

## Creating an event

Trusted application code resolves `App\Audit\AuditEventRecorder` from the
container and supplies an `AuditAction`, an `AuditActor`, an `AuditSubject`,
and the action's small typed payload:

```php
$event = app(AuditEventRecorder::class)->record(
    AuditAction::PlanSnapshotRecorded,
    AuditActor::system(),
    AuditSubject::resource(AuditSubjectType::PlanSnapshot, $snapshot->id),
    ['outcome' => 'recorded', 'snapshot_kind' => 'planned'],
    correlationId: $approvedCorrelationId,
);
```

There is no controller, Livewire action, public endpoint, or ordinary-user API
for creating arbitrary audit events. Callers cannot select a free-text action,
purpose, or retention policy: `AuditAction` derives the approved purpose,
retention class, actor types, subject types, and payload schema.

The recorder assigns a lowercase ULID and a server-authoritative UTC
`occurred_at` timestamp. It does not currently accept an imported or delayed
occurrence time, so there is no materially distinct recording timestamp. A
future delayed-event API must preserve a separate server recording time and
must not accept an ordinary request timestamp as authoritative.

## Approved classifications

Actor types are `authenticated_user`, `administrator`, `system`,
`external_operator`, and `deployment`. User and administrator actors use an
erasable identity mapping. External operator and deployment actors use only a
validated opaque reference in the same separately erasable mapping table.

Purposes are centralized in `AuditPurpose`: account security,
privileged-access accountability, moderation accountability, catalogue
provenance, product history, account-erasure evidence, and operational
accountability.

Retention classes are centralized in `AuditRetentionClass` and reproduce the
approved policy treatments: six-month security event, 12-month privileged
identity, 30-day moderation party, 12-month anonymous moderation decision,
active-version plus 12-month provenance, private content until final purge,
12-month purge receipt, and 12-month non-personal operational evidence. FND-05
records these classes but does not schedule or perform expiry deletion.

The initial action set is intentionally small: completed/refused administrator
bootstrap, catalogue proposal approval, recipe nutrition override, plan
snapshot recording, and account anonymization. Add a future action by adding
an enum case and, in the same reviewed change, defining its purpose, retention
class, permitted actor and subject types, strict payload schema, and focused
positive/negative tests. Because the MySQL classification columns use fixed
database enums, new stored values also require a new additive migration; never
edit the FND-05 historical migration. Do not add a generic action or metadata
escape hatch.

## Payload and reference restrictions

`AuditPayloadValidator` applies an action-specific key and value schema. It
also rejects commonly dangerous key families and raw IP values, limits depth,
field/list counts, individual strings, and encoded size, and rejects all
unknown keys. This combines typed schemas with defensive checks; key-name
matching is not the sole control.

Subject, correlation, external-actor, deployment, and evidence references are
non-secret opaque strings of at most 64 characters using a conservative
character set. Correlation is opt-in; framework request IDs are not copied
automatically. An evidence reference identifies a separately protected store
only. It must not contain evidence, a path containing identity, a secret, or
private content.

Arbitrary metadata is prohibited because it would let unrelated callers copy
credentials, private domain records, request bodies, or unbounded text into a
store with different access and retention rules.

## Append-only and integrity boundaries

`AuditEventRecorder` is the supported append API. `AuditEvent` is fully guarded
and rejects Eloquent update and delete operations. Corrections must be new
linked events. `AuditActorIdentity` also rejects ordinary update/delete;
`AuditActorIdentityEraser` is the narrow deletion path for identity mappings.

Each event has an HMAC-SHA-256 over its canonical stored fields using the
application key; configured previous application keys remain valid during key
rotation. This detects out-of-band mutation when an event is read and checked.
It does not make the database administrator or raw query builder incapable of
changing or deleting rows. Database privileges, monitored reads/exports,
retention deletion, key custody, and stronger infrastructure controls remain
deployment work.

## Identity erasure

`audit_actor_identities` stores only a random ULID, identity category, nullable
user ID, and optional bounded external reference. It stores no name, email,
username, credentials, or authentication material. Audit events retain only
the random mapping ULID. There is intentionally no foreign key from an event
back to the mapping: deleting the mapping must not mutate the append-only
event.

Deleting a user nulls `user_id`; a final purge should call
`AuditActorIdentityEraser` to remove the mapping entirely. The retained event
then contains a non-derived random reference with no mapping or derivation key,
so it cannot recover the former identity. It may still correlate anonymous
events for the remaining approved clock, as DEC-013 permits. User subjects use
the same erasable mapping and cannot be stored as a raw account ID.

## Access

There is no production audit browser. Guests and ordinary users cannot view or
browse the store. The policy permits an administrator to read only an
individual moderation/catalogue-purpose event; it denies generic browsing and
all mutation. Administrator status alone does not grant account-security or
privileged-lifecycle audit access. Future security, moderator, filtered user
activity, rights-response, and export views require their own reviewed,
purpose-specific authorization and monitoring.

## Persisted-field purposes

| Field | Reason retained |
| --- | --- |
| `audit_actor_identities.id` | Random erasable link between an event and an identity mapping. |
| `identity_type` | Distinguishes user, external-operator, and deployment mapping rules. |
| `user_id` | Temporary direct user lookup for approved accountability and erasure; nullable on account deletion. |
| `external_reference` | Minimal approved opaque operator/deployment reference; separately erasable. |
| `audit_events.id` | Immutable distributed event identity. |
| `action` | Stable allowlisted description of what occurred. |
| `purpose` | Approved reason for processing and access boundary. |
| `retention_class` | Policy selector for later expiry/anonymization work. |
| `actor_type` | Retained role/category after identity removal. |
| `actor_identity_id` | Optional erasable/pseudonymous actor link; never a copied name or email. |
| `subject_type` | Stable affected-resource category retained after deletion. |
| `subject_identity_id` | Optional erasable user-subject link. |
| `subject_identifier` | Optional bounded non-user resource/version reference without content. |
| `occurred_at` | Server-authoritative UTC event time. |
| `correlation_id` | Optional non-secret link to related approved operational evidence. |
| `evidence_reference` | Optional opaque pointer to separately controlled evidence, never the evidence itself. |
| `schema_version` | Allows future readers to interpret an older bounded payload. |
| `payload` | Minimal action-specific structured outcome metadata. |
| `integrity_hash` | Detects mutation of the canonical event fields. |

No backfill is required because both FND-05 tables are new and no earlier
ordinary audit store exists.

## Deliberately deferred

FND-05 does not instrument current actions, implement FND-14 administrator
bootstrap, create moderation/version/snapshot/account-deletion workflows, add a
retention scheduler or legal holds, build protected-evidence storage, expose a
production viewer or user activity projection, monitor reads/exports, or claim
database-level immutability. DEC-012 still governs the unresolved backup
lifecycle.
