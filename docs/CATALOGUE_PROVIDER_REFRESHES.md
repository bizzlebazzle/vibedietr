# OpenFoodFacts provider refreshes

## Purpose and boundaries

NUT-11 refreshes an approved OpenFoodFacts-backed catalogue item without
silently changing its current facts. An administrator starts a refresh from the
catalogue detail page. The queued worker fetches through the application-owned
`app/Integrations/OpenFoodFacts` client, maps through the existing provider
DTO and catalogue mapper, and stages only material differences. Ordinary users
cannot start or review refreshes.

A refresh is eligible only when the active item is approved, is not redirected,
has a current version, has an explicit OpenFoodFacts source, and has matching
normalized barcode and provider source identity. The request pins the current
version and immutable source identity. One nullable unique active key permits
only one queued, processing, or staged run per item while retaining terminal
history.

## Fetch, comparison, and omission policy

`RefreshOpenFoodFactsCatalogueItem` carries only refresh and correlation ULIDs.
It claims work in a short transaction, performs the external request outside a
transaction, then stages the result in another short transaction. The worker
revalidates the pinned item, version, lifecycle, provider, and source identity
before writing.

The comparison covers provider-supplied name, keywords, categories, image,
structured package and serving facts, and NUT-05 nutrient observations.
Provider omissions do not clear existing values. Empty provider lists are
treated as omissions. Explicit provider removal is not supported until a future
provider contract can distinguish removal from incomplete data. Numeric
nutrition equality is decimal-aware, but a changed provider source precision is
material evidence and therefore reviewable. An explicit zero is data, not an
omission.

No material change completes the run as `no_change` and creates neither a
proposal nor a version. Not-found, permanent mapping failure, exhausted
transient failure, and lifecycle cancellation also preserve the current
catalogue version.

## Moderation and versioning

Material differences create one provider-refresh proposal using the NUT-10
proposal/change schema with a distinct proposal type. Each field records pinned
base value, proposed value, provider, stable source identity, provider field,
source scale where applicable, and observation time where available. Raw
provider responses are never stored in the proposal, queue payload, exception,
audit event, or telemetry.

The private provider-refresh queue displays base, current, proposed, provenance,
and same-field conflict state. Acceptance and rejection require the centralized
catalogue moderation authorization, FND-13 readiness, recent primary
authentication, and a consumed second-factor proof. Decisions apply to the
whole proposal. A stale base requires explicit acknowledgement; unrelated
current facts are carried forward.

Acceptance creates exactly one new immutable version and changes the current
pointer atomically. Refreshed fields link to the refresh run and retain imported
OpenFoodFacts provenance. Unchanged observations retain their prior imported,
manual, corrected, or derived provenance. Rejection changes no catalogue fact
and releases the active key. Repeated decisions return the original decision
and cannot create another version or audit event.

## Concurrency, retry, and privacy

The job is unique for 24 hours, has a 75-second overlap lock, a 60-second
timeout, three attempts, and 10/60-second backoff. The default worker timeout is
70 seconds and database `retry_after` is 90 seconds, preserving the required
20-second safety margin. The durable run state and unique proposal relationship
are the effect boundary for retry and failed-job replay.

Telemetry uses only bounded outcome, failure category, provider, duration,
attempt, job, refresh, item, and correlation references. It excludes raw JSON,
product text, image content, credentials, headers, sessions, administrator
factor material, and moderation notes. Final failure stores a safe category on
the run and emits the shared metadata-only queued-job failure event.

## Operator response

For an OpenFoodFacts outage, leave staged/current catalogue data untouched,
confirm provider health from the configured external monitor, and allow bounded
retries to finish. Alert on final-failure rate, oldest default-queue age, or
provider latency/error thresholds. Replay one failed job only after confirming
the run is still queued or processing and has no proposal or terminal result.
Do not replay a terminal run; request a new refresh after the cause is resolved.

See [Queue operations](QUEUE_OPERATIONS.md) and
[Job inventory](JOB_INVENTORY.md) for the shared worker, replay, forgetting,
retention, health, and deployment procedures.
