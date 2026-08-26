# Job inventory

## Operating contract

This is the authoritative DEP-04 inventory. Every new queued or scheduled job
must be added here and to `config/queue-operations.php` before production
enablement. Infrastructure schedules run in UTC. Queue failures retain the
serialized payload, so the privacy classification is an operational control.

## DeliverSecurityNotification

- **Class / owner:** `App\Jobs\DeliverSecurityNotification`; FND-14 and
  DEC-016 administrator-security lifecycle.
- **Purpose / enablement:** Deliver an already-persisted administrator security
  notification. Enabled when an approved lifecycle action creates an intent.
- **Queue / worker / concurrency:** `security-notifications`;
  `security-notifications` worker group; one process and one job per process.
- **Timeout / retry_after:** 30-second job timeout, 40-second worker timeout,
  90-second database `retry_after`. The 50-second job margin and 50-second
  worker margin cover termination and database cleanup.
- **Attempts / backoff:** Three total attempts; 10 seconds then 60 seconds.
  Sanitized provider/network failures retry. Invalid destination, missing
  recipient, and other known permanent failures stop immediately.
- **Idempotency:** The logical operation and unique key are the intent UUID.
  The unique/overlap locks live 24 hours; the persisted intent status and
  provider reference are the durable effect boundary and survive retry and
  operator replay. Concurrent execution is locked for 45 seconds. If a provider
  accepted delivery but the response was lost, replay requires a provider-side
  check before another send.
- **Duration / resources:** Normally under 10 seconds; maximum 30 seconds. Low
  CPU and memory, database plus one transactional-mail request. External
  dependency is the selected DEC-016 provider.
- **Failure / alert:** Final failure writes one error-level
  `queued_job_failed` safe event and marks notification health unhealthy.
  The production log collector alerts the on-call operator at high severity.
  Attempts do not alert. Use the correlation ID, job UUID, safe error code and
  the queue operations runbook.
- **Replay:** Safe only after verifying provider acceptance and the intent
  status. Confirm the 24-hour unique lock and durable intent state; retry by
  failed-job UUID. Do not replay a permanently rejected destination.
- **Failed record / privacy:** Retain metadata-only failed records for exactly
  168 hours from `failed_at`. Payload is a pseudonymous intent UUID only; no
  destination, notification body, session, credential, TOTP or recovery code.
  Correlation is reloaded from the intent and reported only as a bounded safe
  reference.
- **Scheduling:** Event driven; not scheduled.

## ProcessReferenceTask

- **Class / owner:** `App\Jobs\ProcessReferenceTask`; FND-09 reference
  implementation.
- **Purpose / enablement:** Harmless operations reference. Implemented and
  worker-compatible, but has no production dispatcher, route or schedule.
- **Queue / worker / concurrency:** `default`; `default` worker group; one
  process and one job per process.
- **Timeout / retry_after:** 60-second job timeout, 70-second worker timeout,
  90-second database `retry_after`. The enforced 20-second maximum-window
  margin covers termination and cleanup.
- **Attempts / backoff:** Three total attempts; 10 seconds then 60 seconds.
  Sanitized transient/unexpected failures retry; classified permanent failures
  stop immediately.
- **Idempotency:** SHA-256 of `reference_task.process|operationReference`.
  Unique dispatch, overlap lock and atomic cache result last 24 hours. The
  cache result survives queue retry and replay in that window, blocks
  concurrent effects, and makes a post-effect retry harmless.
- **Duration / resources:** Normally under one second; maximum 60 seconds.
  Low CPU/memory and database-cache access; no external dependency.
- **Failure / alert:** One error-level safe log on final failure. The log
  collector alerts at medium severity in production; attempts do not alert.
- **Replay:** Direct retry is safe only while the 24-hour result marker remains.
  After expiry, create a new logical operation reference instead of replaying.
- **Failed record / privacy:** Metadata-only safe references; retain exactly
  168 hours from `failed_at`. The correlation ID is generated or propagated
  and is stored in the payload as a bounded non-secret reference.
- **Scheduling:** None.

## administrator:expire-promotions

- **Owner / purpose / enablement:** FND-14; finalize expired administrator
  promotion requests. Enabled hourly in production.
- **Execution:** Scheduler command, not a queued job. UTC hourly, one scheduler
  instance, `withoutOverlapping(10)`, `onOneServer()`.
- **Concurrency / duration / resources:** One execution; normally under one
  second and must complete inside the 10-minute lock. Low CPU and database I/O.
- **Retry / idempotency / replay:** No automatic retry. The state transition is
  idempotent; a later hourly run safely catches missed expirations. Manual
  execution is safe after checking database availability.
- **Failure / alert / privacy:** Non-zero scheduler output is an operational
  high-severity scheduler failure. No queued or failed-job payload exists and
  no private source content is emitted.
- **Lock crash behavior:** A crashed execution can block the task for at most
  10 minutes; the next due run can then acquire the shared database-cache lock.

## queue:prune-operational-failures

- **Owner / purpose / enablement:** DEP-04 safety sweep; remove any legacy or
  missed terminal personal-payload failure and metadata-only failures at the
  exact 168-hour boundary. Enabled daily at 00:15 UTC.
- **Execution:** Scheduler command, not a queued job. One scheduler instance,
  `withoutOverlapping(10)`, `onOneServer()`.
- **Concurrency / duration / resources:** One execution, 200-row chunks, and a
  10-minute lock. Low CPU, bounded database reads/deletes.
- **Retry / idempotency / replay:** No automatic retry; deletion is idempotent
  and the next daily run safely repeats it. It must never be replayed against a
  different database without the deployment change procedure.
- **Failure / alert / privacy:** Non-zero scheduler output is a high-severity
  retention failure. Output contains counts only. The failed-job provider
  removes personal, unknown or malformed final failures immediately; this
  sweep catches any legacy or missed record. Known metadata-only classes use
  seven-day retention.
- **Lock crash behavior:** A crash releases naturally after at most 10 minutes.
  Alerting must precede the next daily run because personal payload must not
  wait for convenience.

## Defined future workloads

DEC-005 recipe import and DEC-006 OCR remain disabled and have no job classes.
Their defined bounds are three attempts, 10/60-second backoff, concurrency at
most two, private transient storage, and 24-hour transient cleanup; OCR has a
60-second job timeout and provider calls capped at 30 seconds. No worker is
authorized for them by this inventory. The implementing REC-15/16/17 pull
request must add concrete class names, payload classifications, idempotency
lifetimes, replay effects, worker resource measurements and any isolation
needed before changing either feature flag. Until then they cannot consume the
current `default` worker merely because DEP-02 contains placeholder queue
settings.
