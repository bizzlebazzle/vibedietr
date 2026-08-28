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

## ProcessPastedRecipeImport

- **Class / owner:** `App\Jobs\ProcessPastedRecipeImport`; REC-15
  pasted-text recipe imports.
- **Purpose / enablement:** Parse one already-persisted private pasted source
  and atomically create or update its single private draft. Enabled only by an
  authenticated import submission or bounded owner retry.
- **Queue / worker / concurrency:** `default`; `default` worker group; one
  configured process. The job also uses one logical-operation overlap lock;
  DEC-005 permits at most two import jobs per application instance if measured
  capacity later adds an isolated worker.
- **Timeout / retry_after:** 60-second job timeout, 70-second worker timeout,
  90-second database `retry_after`; the enforced 20-second maximum-window
  margin covers termination and cleanup.
- **Attempts / backoff:** Three attempts total; 10 seconds then 60 seconds.
  Unexpected persistence failures are sanitized and retryable. No credible
  recipe structure is permanent and stops immediately.
- **Idempotency:** SHA-256 of `recipe_import.process|{import ULID}` for unique
  dispatch and 75-second overlap protection. The durable unique import
  idempotency key, unique import-to-draft relationship, locked import state,
  and one materialization transaction are the effect boundary. Replay resolves
  the same draft and replaces its import-owned children instead of appending
  duplicates. A new import ULID creates a separate draft for identical source.
- **Duration / resources:** Deterministic local text parsing and bounded
  database writes; normally under one second and at most 60 seconds. It makes
  no provider or network request and carries no source in its queue payload.
- **Failure / alert:** Final failure writes one privacy-safe
  `queued_job_failed` event and marks only safe category/code on the import.
  Existing queue final-failure, retry, execution, depth, age, worker, and
  pruning telemetry apply; the production collector uses DEP-05 thresholds.
- **Replay:** Retry the same failed import only through the owner-authorized
  bounded retry action or reviewed failed-job runbook. Confirm it is not
  already `review_ready`; never create a replacement for a technical retry.
- **Failed record / privacy:** Metadata-only import ULID and correlation ULID.
  No source, ingredient, instruction, account email, parser result, or model is
  serialized. Retain at most 168 hours under the native failed-job policy.
- **Scheduling:** Event driven; not scheduled. Missing imports complete as obsolete.

## ProcessWebpageRecipeImport

- **Class / owner:** `App\Jobs\ProcessWebpageRecipeImport`; REC-16 webpage
  recipe imports.
- **Purpose / enablement:** Safely fetch one submitted public HTML URL, extract
  recipe source locally, and atomically create or update its single private
  draft. Enabled only by authenticated submission or bounded owner retry.
- **Queue / worker / concurrency:** `default`; `default` worker group; one
  configured process and a global import overlap lock. This is stricter than
  DEC-005's maximum of two concurrent imports per application instance.
- **Timeout / retry_after:** 60-second job timeout, 70-second worker timeout,
  90-second database `retry_after`. Fetch connect/total timeouts are 3/15
  seconds, leaving cleanup and persistence margin.
- **Attempts / backoff:** Three total attempts; 10 seconds then 60 seconds.
  DNS/connect timeout, HTTP 408/429, and selected 5xx failures may retry.
  Validation, SSRF, redirect, type, size, and extraction failures are permanent.
- **Idempotency:** SHA-256 of
  `recipe_webpage_import.process|{import ULID}`; unique dispatch, per-import
  overlap protection, locked state, unique import-to-draft relationship, and
  transactional replacement of import-owned children form the durable boundary.
- **Duration / resources:** One manually redirected, DNS-pinned HTTP request
  chain plus bounded local DOM/JSON parsing and database writes; maximum 60
  seconds and two MiB decoded HTML.
- **Failure / alert:** Safe webpage outcome, latency, queue retry, and final
  failure telemetry use bounded categories and correlation. Follow provider
  outage, backlog, and failure-spike runbooks.
- **Replay:** Retry the same failed import through the owner-authorized action
  or reviewed failed-job runbook. Never create a replacement import for a
  technical retry.
- **Failed record / privacy:** Payload contains only import and correlation
  ULIDs. It excludes URL, HTML, extracted text, headers, user data, and parser
  results; retain metadata-only failure rows at most 168 hours.
- **Scheduling:** Event driven; not scheduled. Raw HTML is never durable.

## ProcessUploadedRecipeImport

- **Class / owner:** `App\Jobs\ProcessUploadedRecipeImport`; REC-17 document
  and still-image recipe imports.
- **Purpose / enablement:** Validate one private upload, extract locally, and
  atomically create or update one reviewable private draft. Images are locally
  canonicalized before OCR. Google Document AI is an optional disabled-by-
  default fallback and receives only the canonical PNG.
- **Queue / worker / concurrency:** `default`; `default` worker group; one
  configured process, a global import overlap lock, and a per-import lock.
- **Timeout / retry_after:** 60-second job timeout, 70-second worker timeout,
  90-second database `retry_after`; the 20-second safety margin is preserved.
  Tesseract is capped below the job timeout; Google calls are capped at 30
  seconds. Imagick has explicit time, memory, map, disk and thread limits.
- **Attempts / backoff:** Three attempts total; 10 seconds then 60 seconds.
  Invalid format/content, resource-limit, unusable-text and structure failures
  are permanent. Technical local OCR failure may use the managed fallback only
  on the final local attempt; no-usable-text may use it immediately.
- **Idempotency:** SHA-256 of `recipe_upload_import.process|{import ULID}`;
  unique dispatch, overlap locks, locked import state, unique import-to-draft
  relation and transactional child replacement form the durable boundary.
- **Duration / resources:** One TXT/Markdown/inert-HTML input up to two MiB or
  one JPEG/PNG/HEIC still up to 20 MiB and 50 megapixels. Queue payloads contain
  only import and correlation ULIDs. Original and canonical inputs remain in
  private non-executable storage and are never recipe attachments.
- **Failure / alert:** Safe category/code and aggregate duration/quality/
  cleanup counters only. Source bytes, extracted/OCR text, user filename,
  storage key, provider payload and account data are prohibited from telemetry,
  exceptions and failed records.
- **Replay:** Owner retry is intentionally unavailable after terminal upload
  cleanup; submit a new upload. Operator failed-job replay is allowed only
  while the same source still exists and durable state confirms no terminal
  result. Never reconstruct or copy source content from a failure record.
- **Cleanup:** Best-effort immediate deletion after every terminal outcome and
  owner deletion. The hourly safety sweep removes terminal inputs within 24
  hours and marks/removes abandoned non-terminal inputs after seven days.
- **Failed record / privacy:** Metadata-only import and correlation ULIDs;
  retain no longer than 168 hours under the failed-job policy.
- **Scheduling:** Upload-triggered; processing itself is not scheduled.

## recipe-imports:cleanup-transient

- **Owner / purpose / enablement:** REC-17 safety sweep for terminal and
  abandoned original/canonical import inputs. Enabled hourly in UTC.
- **Execution / locking:** Scheduler command, not a queued job; one server,
  `withoutOverlapping(10)`. It works in 100-row chunks and skips active future
  processing leases.
- **Retry / idempotency / replay:** No automatic retry. Deletion and cleared
  references are idempotent; the next hourly run safely retries failed cleanup.
- **Failure / alert / privacy:** Non-zero scheduler failure is a retention
  alert. Output and telemetry contain counts/outcomes only, never file content,
  OCR text, filenames or storage keys.
- **Lock crash behavior:** A crashed run can block this task for at most ten
  minutes; the next hourly run then recovers it.

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

## observability:scheduler-heartbeat

- **Owner / purpose / enablement:** DEP-05; prove the Laravel scheduler is
  invoked independently of web traffic. Enabled every minute in production.
- **Execution:** Scheduler command, not a queued job. UTC every minute,
  one-server execution and `withoutOverlapping(10)`.
- **Concurrency / duration / resources:** One execution, normally under one
  second, shared cache write only.
- **Retry / idempotency / replay:** No automatic retry. Rewrites one freshness
  timestamp idempotently; the next minute recovers a missed run.
- **Failure / alert / privacy:** Staleness alerts at the documented threshold.
  The cache value is a UTC timestamp and contains no user or payload data.
- **Lock crash behavior:** A lock may live ten minutes, so lock failures are
  visible as scheduler staleness rather than hidden by web traffic.

## observability:monitor

- **Owner / purpose / enablement:** DEP-05; evaluate readiness, queue,
  heartbeat, failure, provider, and pruning signals. Every minute in production.
- **Execution:** Scheduler command, not a queued job. UTC every minute,
  one-server execution and `withoutOverlapping(10)`.
- **Concurrency / duration / resources:** One execution; bounded database
  metadata reads and cache counters, normally under one second.
- **Retry / idempotency / replay:** No automatic retry. Read-only evaluation
  plus safe log alerts; rerun is operationally safe and the next minute
  recovers a miss. The deployment collector deduplicates repeated alert state.
- **Failure / alert / privacy:** Command failure is itself scheduler staleness.
  Events contain only allowlisted dimensions and aggregate counts; queue and
  failed-job payloads are never deserialized or exported.
- **Lock crash behavior:** A lock may live ten minutes. Investigate the
  scheduler/monitor process and shared-cache lock; do not clear all cache.

## Defined future workloads

REC-15 pasted-text, REC-16 webpage and REC-17 document/image work are
inventoried above and consume the bounded `default` worker. Any later PDF,
multi-page, handwriting, non-English or additional image-format workload must
add concrete resource measurements, payload classification and isolation here
before production enablement.
