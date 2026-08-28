# Observability

## Scope and provider decision

DEP-05 supplies provider-neutral instrumentation. DEP-04 selected native
database workers and no Horizon, dead-letter service, metrics SaaS, APM, or
tracing provider. Production sends structured Laravel logs to a
deployment-selected platform or hosted collector. The repository contains no
collector credential, provider SDK, session replay, analytics, or external
alert call.

Production validation rejects the local adapter and a development release.
Deployment must configure dashboards and role-based alert routing before the
DEP-04/DEP-05 gate is satisfied. Local/CI use logs, cache, database fixtures,
and a replaceable alert sink.

Audit events, operational logs, and metrics/health stay separate. FND-05 is
durable provenance. Logs diagnose runtime work. Metrics and health aggregate
state. Neither logs nor cache metrics use the audit store.

## Health surfaces and states

- `/up` is Laravel's conventional process route.
- `/health/live` returns only `{"status":"healthy"}` and calls no dependency.
- `/health/ready` returns only `healthy`, `degraded`, or `unhealthy`;
  unhealthy uses HTTP 503 and exposes no dependency detail.
- `php artisan app:health` prints safe dependency/state/reason diagnostics,
  never values, credentials, provider responses, traces, or payloads.

Healthy means every required dependency passed. Degraded means required work
is available while an optional dependency is unavailable. Unhealthy means a
required dependency or heartbeat failed.

Readiness uses non-destructive database `select 1`, cache round trip/removal,
queue metadata read, storage `exists` without a write, per-queue worker
freshness, scheduler/pruning freshness, and production FND-13 readiness.
Optional OpenFoodFacts/import/OCR providers do not fail global readiness.

## Correlation, logs, and redaction

Every HTTP request receives a non-secret correlation ID. A caller
`X-Correlation-ID` is accepted only when it passes the 64-character bounded
opaque-reference validator and is not a raw IP; otherwise a ULID is generated.
The response returns it. Request context propagates to FND-09 jobs, child jobs,
provider calls, safe failure reports, and approved audit correlation fields.

| Field | Meaning |
| --- | --- |
| `correlation_id` | Bounded workflow reference; never user content. |
| `operation`, `operation_type` | Stable operation name. |
| `job_identifier`, `job_type`, `job_class`, `queue`, `attempt_count` | Safe queue metadata. |
| `provider`, `http_status`, `duration_ms` | Provider/timing metadata. |
| `outcome`, `failure_category`, `safe_error_code`, `exception_class` | Bounded classifications. |
| `metric`, `value`, `count`, `state`, `window_seconds` | Aggregate state. |
| `environment`, `release` | Deployment dimensions. |

Unknown keys are discarded and unsafe strings become `[redacted]`. Metrics use
only low-cardinality job type, provider, outcome, queue, and environment-like
dimensions. User, recipe, ingredient, import, email, filename, URL, correlation,
resource, and raw-error values are never metric labels.

Laravel's do-not-flash list covers passwords, tokens, API keys, secrets,
authorization, cookies, sessions, recovery codes, TOTP/OTP, recipe original
and instruction text, diary/target data, import source, and OCR text.
Exception reporting adds only correlation, environment, release, exception
class, and safe HTTP/console operation; never bodies, sessions, users,
serialized jobs, raw messages, agents, IPs, or provider payloads.

## Queue, scheduler, providers, and workflows

Queue events record worker heartbeat, dispatch-to-start when bounded `pushedAt`
exists, execution duration, retry, and final-failure counters. `QueueMonitor`
reads only queue/timestamps/counts and never deserializes payloads. It reports
depth, oldest waiting age, failed count, and failed age. Repeated safe job UUID
failures increment a hashed 24-hour replay-anomaly counter. Successful pruning
records its own heartbeat; monitoring never deletes.

UTC `observability:scheduler-heartbeat` and `observability:monitor` run every
minute with one-server ten-minute overlap locks. Web traffic is not a scheduler
signal. Monitoring evaluates readiness, freshness, queue state, and rolling
failure/exception/provider counters through a replaceable `AlertSink`.

OpenFoodFacts and security mail record duration and failure with stable
dimensions. Slow calls increment `provider.slow`. Current critical workflows
include the FND-09 reference task, DEC-016 security delivery, pasted imports,
and webpage imports.

REC-16 emits `provider.request` fetch duration, `recipe_webpage.fetch`
success/failure and SSRF-denial counters, and `recipe_webpage.extraction`
duration/method outcomes. Labels are limited to stable provider, outcome,
operation, and failure category. URL, hostname, resolved address,
user/import/recipe identity, query string, HTML, and extracted text are
prohibited metric dimensions. Correlation appears only in sanitized events.

## Initial alert matrix

Defaults are tuning starting points, not contractual SLAs.

| Alert | Warning | Critical | Window | Recipient | Runbook |
| --- | --- | --- | --- | --- | --- |
| Readiness unhealthy | immediate | one failed probe | confirm twice externally | primary administrator | Application readiness failure |
| Worker unavailable | 120 s | 180 s | current | operations/security | Queue worker unavailable |
| Queue depth | 25 | 100 | current | operations/security | Queue backlog |
| Oldest waiting job | 300 s | 900 s | current | operations/security | Queue backlog |
| Final failure spike | 5 | 20 | 300 s | operations/security | Failure spike |
| Exception-rate spike | 5 | 20 | 300 s | operations/security | Failure spike |
| Scheduler stale | 120 s | 180 s | current | operations/security | Scheduler stale |
| Security notification | first final failure | channel unhealthy | immediate | operations/security and primary administrator | Security notification failure |
| Provider failure/latency | 5 or 3000 ms | 20/sustained | 300 s | operations/security | Provider outage |
| Failed-job pruning stale | 25 h | 26 h | current | operations/security | Failed-job pruning stale |
| Replay anomaly | second failure | continued growth | 24 h | operations/security | Failure spike |

Deployment maps roles to a verified primary administrator, operations/security
recipient, and secondary contact. Alerts contain safe environment, category,
queue/provider, aggregates, classification, and runbook; never private content,
payloads, credentials, or raw exceptions.

## Configuration and local testing

All `OBSERVABILITY_*` values are non-secret. `OBSERVABILITY_RELEASE` is an
immutable build identifier. Production selects `platform` or `hosted` only
after deploying collection, dashboards, retention, access, and alerts.

```bash
./vendor/bin/sail artisan app:health
./vendor/bin/sail artisan observability:scheduler-heartbeat
./vendor/bin/sail artisan observability:monitor
./vendor/bin/sail test tests/Feature/ObservabilityTest.php
```

Tests use synthetic dependencies, database rows, cache state, in-memory logs,
and a fake alert sink. They never contact a real provider or destination.
Run `app:production-check` before and after `config:cache` with safe injected
production values.
