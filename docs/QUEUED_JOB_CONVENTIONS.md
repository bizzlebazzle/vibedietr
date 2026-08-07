# Queued-job conventions

## Scope

Use a queued job when work is slow, retryable, scheduled, or should not hold a
web request open. Do not queue a small database mutation merely to avoid
defining its transaction or authorization boundary. These conventions apply to
imports, catalogue refreshes, nutrition recalculation, exports, delayed
deletion, and other future asynchronous work.

`App\Jobs\ProcessReferenceTask` is a harmless reference. It records a
short-lived cache result and has no route, command, scheduler entry, or product
behavior.

## Required design

### Name and queue

- Name jobs with an action-oriented `VerbSubject` class in `App\Jobs`, such as
  `ProcessReferenceTask` or a future `GenerateAccountExport`.
- Select a queue explicitly. Use `default` until worker isolation, latency, or
  resource needs justify a small named queue such as `imports` or `exports`.
- Adding a queue name also requires deployment workers to consume it. FND-09
  does not add production worker configuration.

### Payload

Queue stable resource and operation identifiers, a correlation identifier, and
small enums or value objects. Reload mutable domain records during execution.
Do not serialize request bodies, recipe or diary text, OCR output, uploaded file
contents, arbitrary arrays, credentials, cookies, authorization headers, or
private model dumps.

Laravel's model serialization normally stores a model identifier and reloads
the model when the job runs, but loaded relationships can expand a payload and
the model may be missing later. Prefer an explicit scalar identifier when that
behavior is clearer. The current database failed-job provider retains the full
serialized payload and exception text, so payload minimization is a privacy
boundary, not only a performance choice.

### Correlation

- Correlation identifiers are non-secret opaque references, not user content.
- Use the parent request or operation's correlation ID for child jobs. Derive a
  new child ID only when the relationship is retained separately and useful.
- A root job generates a ULID when no correlation ID is supplied.
- Caller-supplied values use the same bounded safe-reference validation as the
  FND-05 audit store. Never put email, IP, credentials, or payload text in an ID.

### Idempotency and duplicates

Every job must define a stable idempotency key from the logical operation. A
Laravel delivery UUID is unsuitable because each delivery receives a new one.
Document the key's uniqueness scope and lifetime.

Use layered protection where appropriate:

1. `ShouldBeUnique` suppresses ordinary duplicate dispatch while an equivalent
   job owns the cache lock.
2. `WithoutOverlapping` prevents concurrent execution for the same logical
   operation when duplicate payloads nevertheless reach a worker.
3. The business effect itself is idempotent. Prefer a database unique key,
   compare-and-set state transition, or a transaction that records completion
   atomically with the effect. A cache `add` is suitable only for effects whose
   required replay window and durability match that cache.

`ShouldBeUnique` is dispatch coordination, not an exactly-once guarantee.
Locks can expire, queues can redeliver, and failures can occur after a side
effect but before completion is acknowledged.

The reference job hashes its stable operation reference, gives an orphaned
unique lock a 24-hour expiry, uses an overlap lock, and retains its atomic cache
result for 24 hours. Laravel normally releases the unique lock after processing;
the result marker is what makes a later post-success delivery harmless. Future
high-risk or long-lived operations must not inherit that cache lifetime
blindly.

### Missing or deleted resources

Reload resources deliberately and choose one documented outcome:

- complete successfully as obsolete;
- record a safe skipped result; or
- fail permanently when absence demonstrates corruption.

Do not retry every missing model. The reference job treats its absent optional
target as successfully obsolete and stores only `skipped_missing_target`.

### Transactions

Jobs that depend on newly written data must dispatch after the surrounding
database transaction commits, either through the job's after-commit setting or
an explicit `afterCommit()` dispatch. Do not let a worker observe uncommitted or
rolled-back state. Keep a job's own transaction narrow; external calls should
not normally remain inside a database transaction.

## Retry, backoff, and timeout

The starting convention for an ordinary transient job is:

- three attempts in total;
- backoff of 10 seconds, then 60 seconds;
- a job-specific timeout;
- failure on timeout when replay after an uncertain timeout is safe only
  through the job's idempotency design.

Immediate rapid retries can amplify provider rate limits or overload a failing
service. Jobs involving expensive providers, large exports, deletion, or other
high-risk effects must document and test any different attempt count, delay, or
deadline. Never configure an infinite retry loop.

The reference job timeout is 60 seconds. Its overlap lock expires after 75
seconds, and the database queue's current `retry_after` is 90 seconds. The job
timeout must remain below `retry_after`; otherwise a reserved job can become
available while its prior execution may still be running. A worker uses a
job-specific timeout in preference to its command default. External HTTP
connect/read/total timeouts must fit comfortably inside the job timeout and
leave time for cleanup.

Retryable failures throw a sanitized `RetryableJobException`. A known permanent
failure uses `NonRetryableJobException` and is failed immediately. Unexpected
reference exceptions are converted to a sanitized retryable exception without
retaining the original message. Future jobs must classify expected failures at
their integration boundary rather than expose provider or user input in an
exception.

## Failure reporting and observability

`JobFailureReporter` writes a structured `queued_job_failed` log after final
failure. Its allowlisted context is:

- job class and safe operation type;
- transient queue job UUID when available;
- idempotency fingerprint;
- correlation ID;
- failure category and safe error code;
- exception class, attempt count, queue, and UTC failure time.

It does not log the exception message, serialized job, input payload, model,
request data, or arbitrary exception context. Attempt logs, when a future job
needs them, should use the same safe identifiers and must remain separate from
durable audit.

Laravel currently stores failed jobs in the database with their full payload
and full exception text/trace. Reference-job fields are safe identifiers and
its expected exceptions have constant sanitized messages. Future jobs must do
the same and must not embed private input. DEC-013 limits minimized failed-job
metadata to seven days after final failure or resolution and requires personal
payload removal once retry is no longer possible. Automated pruning and replay
operations remain deferred to DEP-04.

## Audit versus logs

Queue attempts and ordinary technical failures are operational logs, not audit
events. Do not create one audit row per attempt.

Use `AuditEventRecorder` only for an allowlisted durable domain event with its
approved purpose and retention, such as a later account-anonymization outcome.
Pass the same correlation ID and only the action's bounded safe payload. If no
approved action matches, do not invent arbitrary audit metadata; add the
smallest reviewed taxonomy entry only when the domain event requires durable
evidence. The reference job emits no audit event because its synthetic result
has no approved durable purpose.

## Testing a job

Focused tests should cover, as applicable:

- dispatch and queue selection;
- duplicate dispatch suppression and execution idempotency;
- retry after partial success;
- concurrent overlap protection;
- correlation propagation, generation, and rejection;
- bounded attempts, backoff, timeout, and retryability classification;
- missing-resource behavior and after-commit dispatch;
- final failure callback and safe structured context;
- absence of private values from the payload, logs, failed-job row, and audit;
- the approved audit event, or proof that no event is expected.

Use queue fakes only for dispatch assertions. Call the handler or use a
deterministic test queue/worker when proving execution, idempotency, or failure
behavior. Failure simulation belongs in test doubles or an isolated reference
adapter, never a production endpoint.

## Reference example

```php
ProcessReferenceTask::dispatch(
    operationReference: 'maintenance:definition-check:2026-08-07',
    targetReference: 'definitions:v1',
    correlationId: $parentCorrelationId,
);
```

The operation reference, not the queue UUID, identifies the logical work. A
child job would pass `$parentCorrelationId` again. A real job would replace the
reference cache result with a durable idempotent domain transition.

## Author checklist

- [ ] Action-oriented class name and explicit queue.
- [ ] Small safe payload containing identifiers rather than content.
- [ ] Stable logical idempotency key, scope, storage, and lifetime.
- [ ] Duplicate dispatch and concurrent execution behavior.
- [ ] Partial-success replay safety at the business-effect boundary.
- [ ] Correlation propagation or root ULID generation.
- [ ] Missing/deleted-resource outcome.
- [ ] Explicit tries, bounded backoff, timeout, and external timeouts.
- [ ] Retryable versus permanent exception mapping with safe codes.
- [ ] Structured failure fields with no exception message or payload dump.
- [ ] Approved audit action, or an explicit decision that audit is not needed.
- [ ] After-commit dispatch when committed data is required.
- [ ] Focused tests for dispatch, execution, duplicates, failure, and privacy.
