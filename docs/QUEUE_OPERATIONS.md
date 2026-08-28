# Queue operations

## Architecture and decisions

Production keeps Laravel's database queue, database cache locks, native workers,
and native UUID failed-job store. Horizon is not justified: current volume is
low, the topology has only two queues, and Horizon would require Redis plus a
secured dashboard and another monitoring surface. A separate dead-letter queue
is also not justified; the native failed store, privacy wrapper, lifecycle
command and runbooks meet current recovery needs.

The stable queues are:

| Queue | Priority and isolation | Worker group | Processes |
| --- | --- | --- | ---: |
| `security-notifications` | High; isolated so general work cannot delay administrator security delivery | `security-notifications` | 1 |
| `default` | Low-volume general and reference work | `default` | 1 |

A job class selects an application-owned name from `App\Queue\QueueName`.
Do not add arbitrary strings or route future imports/OCR to `default` without
updating the measured topology and job inventory.

## Worker and resource configuration

The canonical deployment model is one container per worker group plus one
scheduler container in `compose.production-operations.yml`. The deployment
platform supplies the same immutable `APP_IMAGE` and secret-managed
`APP_ENV_FILE` to each process, starts them on boot, captures stdout/stderr,
and applies `restart: unless-stopped`.

| Group | Command options | Container limit | Stop grace |
| --- | --- | --- | --- |
| Security | `--queue=security-notifications --sleep=3 --timeout=40 --tries=3 --memory=256 --max-jobs=500 --max-time=3600` | 384 MiB, 0.50 CPU | 60 seconds |
| Default | `--queue=default --sleep=3 --timeout=70 --tries=3 --memory=256 --max-jobs=500 --max-time=3600` | 384 MiB, 0.50 CPU | 90 seconds |
| Scheduler | `php artisan schedule:work` | 192 MiB, 0.25 CPU | 30 seconds |

One process per group is deliberately conservative for MySQL connections,
external-provider capacity and a low-volume application. A worker handles one
job at a time. Recycling after 500 jobs, one hour, or 256 MiB bounds long-lived
PHP state. Increase concurrency only with database/provider capacity evidence
and an inventory/config/test update. Resource-heavy import, OCR, PDF or image
work requires a measured group rather than larger global limits.

## Timeouts, retry_after and retry policy

The database connection uses `retry_after=90` seconds. The largest job timeout
is 60 seconds and the largest worker timeout is 70 seconds, leaving the enforced
20-second margin for termination, transaction rollback and reservation cleanup.
Production validation and tests fail if:

`maximum(job timeout, worker timeout) + 20 > retry_after`

A job-specific timeout takes precedence over the worker ceiling. Every job has
finite attempts and backoff in [JOB_INVENTORY.md](JOB_INVENTORY.md). Current
jobs use three total attempts and 10/60-second backoff. Integration boundaries
classify safe transient errors separately from permanent errors; no infinite or
automatic failed-job replay is allowed.

## Scheduler and locking

The application and infrastructure timezone is UTC. A supervised
`schedule:work` process evaluates the checked-in schedule; user timezones do
not alter infrastructure execution.

- `administrator:expire-promotions`: hourly, 10-minute overlap lock, one
  server.
- `queue:prune-operational-failures`: daily at 00:15 UTC, 10-minute overlap
  lock, one server.
- `recipe-imports:cleanup-transient`: hourly UTC, 10-minute overlap lock, one
  server; active future processing leases are excluded.

The database cache provides shared locks across instances. A crashed task can
block its own next execution for no more than 10 minutes. Locks are applied
only to state-changing maintenance commands. A deterministic test acquires the
pruning mutex, proves a second acquisition fails, releases it and proves a
later acquisition succeeds.

## Failed-job lifecycle and privacy

Laravel's database failed provider stores the full serialized payload and full
exception/trace. Current production job payloads contain identifiers only and
expected errors use fixed safe messages. `PrivacyAwareFailedJobProvider`
retains native list/retry/forget/prune behavior but immediately forgets a final
record classified as personal. Unknown or malformed classes fail private and
are also removed immediately. This occurs after retry exhaustion when Laravel
writes the final failed record; attempts remain in the active queue and are not
prematurely deleted.

The daily `queue:prune-operational-failures` command is a safety sweep:

- personal/unknown/malformed payload: delete immediately when encountered;
- metadata-only payload younger than 168 hours: retain;
- metadata-only payload at or beyond exactly 168 hours from `failed_at):
  delete.

Laravel's native `queue:prune-failed --hours=168` uses a strict
older-than comparison, so it is not scheduled because it retains the exact
boundary. The project command is the smallest extension needed for DEC-013.
Output and alerts contain counts and allowlisted references, never payload or
exception messages.

Every final failure emits one `queued_job_failed` error event from the job's
`failed()` callback. The production log collector must alert on transition
into this state and deduplicate by job UUID/idempotency fingerprint. Security
delivery is high severity; reference/general work is medium unless its
inventory says otherwise. Alert fields are job class, operation type, UUID,
idempotency fingerprint, correlation ID, failure category, exception class,
safe error code, attempt count, queue and UTC time. The runbook never uses
`queue:failed` output as a payload-debugging shortcut.

## Safe replay runbook

Only an operator authorized for production operations may replay a failure.

1. Locate the alert by safe job UUID or correlation ID. Use
   `php artisan queue:failed` only in an access-controlled terminal and do not
   copy its exception/payload output into tickets or chat.
2. Identify the failure category from the safe alert. Do not deserialize or
   dump the payload.
3. Resolve the database, configuration or external-provider root cause.
4. Read [JOB_INVENTORY.md](JOB_INVENTORY.md) and confirm direct replay is
   allowed and its idempotency key has not expired.
5. Check the durable application state and external provider for any side
   effect that occurred before acknowledgement.
6. Retry exactly one record with `php artisan queue:retry <failed-job-uuid>`.
   Never use `all` in the normal runbook.
7. Observe the owning queue, verify the durable effect once, confirm no new
   failed row exists and correlate the success with the original operation.
8. Laravel removes the old failed row when retrying. If replay fails, treat the
   new final failure as a new unresolved record under the same logical
   correlation and retention policy.

A personal-payload record is not directly replayable because it is removed on
final failure. Correct the cause and create a new logical operation through the
owning product workflow after its future runbook authorizes that behavior.

## Safe forget runbook

Only an authorized production operator may intentionally discard a
metadata-only failure.

1. Confirm the inventory says it must not be replayed or the logical operation
   has been superseded.
2. Record the reason and safe correlation/job UUID in the approved deployment,
   change or incident system. Use a durable FND-05 event only when an existing
   allowlisted domain/security action requires it; do not invent audit payload.
3. Run `php artisan queue:forget <failed-job-uuid>`. Never use
   `queue:flush` in the normal runbook.
4. Run `php artisan queue:failed` in the controlled terminal and verify that
   UUID is absent while unrelated failures remain.
5. Preserve the external record according to its policy without copying the
   serialized payload or private content.

Native failed storage has no resolved/replayed/discarded status. Laravel removes
a row when it is retried or forgotten. That is sufficient at current scale
because durable significant operator rationale lives in the approved external
change/incident record rather than expanding `failed_jobs` into a workflow.

## Deployment and graceful restart

Workers are long-lived and do not notice changed PHP or cached configuration.

1. Build and migrate using the normal non-destructive deployment procedure.
2. Validate configuration before and after `php artisan config:cache`.
3. Run `php artisan queue:restart` against the shared database cache. Existing
   workers stop after their current job; they do not accept another.
4. Allow the security worker 60 seconds and default worker 90 seconds. The
   orchestrator sends normal termination and waits the configured grace period;
   `kill -9` is emergency-only.
5. Replace all worker and scheduler containers with the same immutable image.
   `schedule:interrupt` may be used before replacing a running scheduler
   during a deploy.
6. Confirm both worker containers and the scheduler are running, then dispatch
   or consume a harmless reference operation in a production-like smoke
   environment.
7. Verify queues, failed count and logs. Idempotency protects the logical effect
   if a crash occurs after a side effect but before acknowledgement; the design
   does not claim exactly-once execution.

A crashed process is restarted by the container runtime. Bounded memory,
max-jobs and max-time also trigger clean replacement instead of indefinite
worker lifetime.

## Health expectations

DEP-04 defines measurable signals; DEP-05 owns a monitoring implementation.

- Database queue and cache-lock queries succeed from every process.
- Both worker containers are running and restart loops are not increasing.
- The scheduler container is running and the 00:15 pruning task has a recent
  successful completion in captured logs.
- `jobs` backlog per queue remains below the deployed alert threshold; use
  `php artisan queue:monitor security-notifications:5,default:100` as a
  short-lived check.
- Any recent failed security notification is high severity. General failed-job
  count must be reviewed; retention command success and oldest timestamp must
  remain within policy.
- A static `app:production-check` pass proves configuration only. It cannot
  prove processes, provider availability or current backlog health.

## Smoke tests and troubleshooting

Before deployment, using synthetic configuration and a disposable database:

```bash
php artisan app:production-check
php artisan config:cache
php artisan app:production-check
php artisan schedule:list
php artisan queue:work database --queue=default --once --sleep=0 --tries=3 --timeout=70
php artisan queue:restart
docker compose -f compose.production-operations.yml config --quiet
```

The automated suite consumes a harmless reference job, exhausts a deterministic
failure, checks safe alert content and serialized rows, retries idempotently,
forgets exactly one failure, exercises the scheduler mutex, and verifies both
privacy and seven-day boundaries.

For a growing queue, verify that the correct isolated worker is running,
database connections are healthy and provider throttles are not rejecting work
before changing process count. For repeated crashes, inspect the safe error
category, container exit status and memory limit; never dump payloads. For a
stale scheduler lock after its 10-minute expiry, use
`php artisan schedule:clear-cache` only after proving no matching task is
running. For a stopped scheduler, restart its container and run the missed
idempotent maintenance command once under the same locks.
