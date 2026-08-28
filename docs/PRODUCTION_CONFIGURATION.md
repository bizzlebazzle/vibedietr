# Production configuration

## Purpose and validation timing

Production uses an explicit fail-closed configuration contract. Before starting
web and queue processes, run the non-mutating check before and after caching:

```bash
./vendor/bin/sail artisan app:production-check
./vendor/bin/sail artisan config:cache
./vendor/bin/sail artisan app:production-check
```

Production web boot repeats static validation. Administrator lifecycle services
and security-notification jobs repeat it at their runtime boundaries and also
use FND-13 live readiness. A static pass cannot prove that a separate worker,
provider, clock, audit store, destination, capacity monitor, or failure monitor
will remain healthy.

## Application, network, and cookies

| Variable | Classification | Purpose/example | Requirement and failure |
| --- | --- | --- | --- |
| `APP_ENV` | Required non-secret | `production` | Must deliberately equal production; local/testing do not trigger production validation. |
| `APP_KEY` | Required secret | Laravel `base64:...` encryption key | Must be valid for the configured cipher. It is never generated or printed by the check. |
| `APP_DEBUG` | Required non-secret | `false` | Debug enabled fails. |
| `APP_URL` | Required non-secret | `https://app.example.com` | Must be valid HTTPS and not localhost, loopback, `.test`, or `.local`. |
| `TRUSTED_HOSTS` | Required non-secret | Exact comma-separated hostnames | Must include the canonical host; empty values and wildcards fail. |
| `TRUSTED_PROXIES` | Required non-secret | `none` or explicit IP/CIDR allow-list | Missing values and trust-all wildcards fail. |
| `TRUSTED_PROXY_HEADERS` | Required non-secret | `x-forwarded-for,x-forwarded-host,x-forwarded-port,x-forwarded-proto` | Must select the approved set. |
| `APP_INSTANCE` | Required non-secret | Opaque deployment reference | Must be non-empty for security correlation. |
| `SESSION_DRIVER` | Required non-secret | `database` | File, cookie, array, and process-local sessions fail. |
| `SESSION_SECURE_COOKIE` | Required non-secret | `true` | Any other effective value fails. |
| `SESSION_HTTP_ONLY` | Required non-secret | `true` | Disabling HttpOnly fails. |
| `SESSION_SAME_SITE` | Required non-secret | `lax` or `strict` | `none` and null fail. |
| `SESSION_DOMAIN` | Optional non-secret | Exact domain when deployment routing requires it | Leave unset for a host-only cookie. |

Direct TLS deployments use `TRUSTED_PROXIES=none`. A TLS-terminating proxy must
appear in the explicit allow-list and send only the approved forwarded headers.
The application never derives trusted hosts from request headers.

## Database, cache, queue, and private storage

| Variable | Classification | Purpose/example | Requirement and failure |
| --- | --- | --- | --- |
| `DB_CONNECTION` | Required non-secret | `mysql` | SQLite and unapproved connections fail. |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` | Required non-secret | Explicit MySQL endpoint and least-privilege identity | Every value must be non-empty. |
| `DB_PASSWORD` | Required secret | Injected database credential | Blank values fail; the value is never emitted. |
| `MYSQL_ATTR_SSL_CA` | Optional non-secret | Mounted CA path | Configure when the selected database requires TLS. |
| `CACHE_STORE` | Required non-secret | `database` | File, array, null, and process-local state fail. |
| `QUEUE_CONNECTION` | Required non-secret | `database` | Sync/null and unapproved backends fail. |
| `QUEUE_FAILED_DRIVER` | Required non-secret | `database-uuids` | Missing durable failure evidence fails. |
| `FILESYSTEM_DISK`, `PRODUCTION_DURABLE_DISK` | Required non-secret | Both `s3` | Local ephemeral or mismatched storage fails. |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` | Required secrets | Least-privilege object-storage identity | Blank values fail. |
| `AWS_DEFAULT_REGION`, `AWS_BUCKET` | Required non-secret | Explicit region and private bucket | Blank values fail. |
| `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT` | Optional non-secret | S3-compatible endpoint behavior | Configure only when the selected service needs it. |
| `DB_QUEUE_RETRY_AFTER` | Required non-secret | `90` | Must preserve at least the configured 20-second margin above every worker/job timeout. |
| `QUEUE_OPERATIONS_ENABLED` | Required non-secret | `true` | Assert only after the documented worker topology is deployed. |
| `QUEUE_SUPERVISION` | Required non-secret | `container` | Selects the sole approved DEP-04 process-supervision model. |
| `QUEUE_SCHEDULER_ENABLED` | Required non-secret | `true` | Assert only after the supervised UTC scheduler is deployed. |

The bucket remains private; DEP-02 does not authorize public visibility.
Workers process `security-notifications,default`. Production operations monitor
worker heartbeat and failed jobs; the command validates configuration, while
FND-13 validates stored recent operational evidence.

DEP-04 uses one worker for `security-notifications`, one for `default`, and
one UTC scheduler process. Static validation checks topology declarations and
timeout safety but cannot prove those processes are alive. Commands, resource
limits, graceful deployment, failure retention and recovery are defined in
[`QUEUE_OPERATIONS.md`](QUEUE_OPERATIONS.md).

## Observability and runtime health

DEP-05 is a hard production gate with DEP-04. Static production validation
requires a deployed collector category and immutable release; live readiness
then verifies runtime dependencies and heartbeats. `app:production-check`
validates configuration. `app:health` validates current runtime state.

| Variable | Classification | Purpose/example | Requirement and failure |
| --- | --- | --- | --- |
| `OBSERVABILITY_ADAPTER` | Required non-secret | `platform` or `hosted` | Select only after collector, dashboards, retention, access, and alerts are deployed; `local` fails production. |
| `OBSERVABILITY_RELEASE` | Required non-secret | Immutable build/git identifier | Blank or `development` fails. |
| `OBSERVABILITY_ALERT_RECIPIENT_ROLE` | Required non-secret | `primary_administrator` or `operations_security` | Maps alerts to deployment-owned contacts; no address belongs in source. |
| `OBSERVABILITY_WORKER_STALE_SECONDS` | Required non-secret | `180` | Per-queue worker critical freshness threshold. |
| `OBSERVABILITY_SCHEDULER_STALE_SECONDS` | Required non-secret | `180` | Scheduler critical freshness threshold. |
| `OBSERVABILITY_PRUNE_STALE_SECONDS` | Required non-secret | `93600` | 26-hour failed-job-pruning critical threshold. |
| `OBSERVABILITY_QUEUE_DEPTH_WARNING` / `CRITICAL` | Required non-secret | `25` / `100` | Current waiting depth thresholds. |
| `OBSERVABILITY_OLDEST_JOB_WARNING_SECONDS` / `CRITICAL_SECONDS` | Required non-secret | `300` / `900` | Oldest waiting age thresholds. |
| `OBSERVABILITY_PROVIDER_SLOW_WARNING_MS` | Required non-secret | `3000` | Initial tuning threshold, not an SLA. |
| `OBSERVABILITY_FAILURE_WINDOW_SECONDS` | Required non-secret | `300` | Rolling failure/exception/provider evaluation window. |
| `OBSERVABILITY_FAILURE_WARNING_COUNT` / `CRITICAL_COUNT` | Required non-secret | `5` / `20` | Aggregate spike thresholds. |

Health routes expose only state. Detailed reasons remain in `app:health` and
protected telemetry. Production collection must disable/minimize request-body,
session, user, cookie, IP, full-user-agent, and job-payload capture. Alert
bodies follow [Observability](OBSERVABILITY.md); response procedures follow
[Operations runbooks](OPERATIONS_RUNBOOKS.md).

Before enablement, start supervised workers/scheduler, invoke the scheduler
heartbeat and pruning once through the approved operation, run `app:health`,
and verify that synthetic safe alerts reach the configured roles. Do not send a
real security notification on routine readiness probes.

## Mail and administrator security notifications

`ADMIN_SECURITY_MAILER` selects one qualifying transactional transport. Resend
is preferred; Postmark, SES, or authenticated encrypted SMTP are allowed. Log,
array, null, sendmail, local catchers, round-robin, and failover are invalid.

| Variable | Classification | Purpose/example | Requirement and failure |
| --- | --- | --- | --- |
| `ADMIN_SECURITY_MAILER` | Required non-secret | Selected transactional mailer | Must resolve to Resend, Postmark, SES, or qualifying SMTP. |
| `ADMIN_SECURITY_PROVIDER` | Required non-secret | Stable provider identifier | Blank values fail. |
| `ADMIN_SECURITY_SENDER_VERIFIED` | Required non-secret | `true` after provider verification | False or missing fails. |
| `ADMIN_SECURITY_QUEUE` | Required non-secret | `security-notifications` | Must be non-empty and monitored. |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Required non-secret | Verified sender identity | Address must be valid; verification is asserted separately. |
| `RESEND_KEY` | Conditional required secret | Resend API key | Required only for Resend. |
| `POSTMARK_TOKEN` | Conditional required secret | Postmark server token | Required only for Postmark. |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME` | Conditional required non-secret | Non-local SMTP relay | Required for SMTP. |
| `MAIL_PASSWORD` | Conditional required secret | SMTP credential | Required for SMTP. |
| `MAIL_SCHEME` | Conditional required non-secret | `tls` or `smtps` | Unencrypted SMTP fails. |
| SES AWS credentials | Conditional required secrets | Least-privilege SES identity | Required when SES is selected. |

The check does not contact providers. Before privilege changes, FND-13 also
requires verified administrator destinations, recent provider acceptance and
capacity evidence, healthy audit persistence, synchronized clock evidence, and
worker/failure-monitor heartbeats. Missing live evidence denies the operation.

## Administrator bootstrap, recovery, and second factor

| Variable | Classification | Purpose/example | Requirement and failure |
| --- | --- | --- | --- |
| `ADMIN_SECURITY_FINGERPRINT_KEY` | Required secret | Dedicated HMAC key for source throttling | Blank fails; the key is never logged. |
| `ADMIN_TOTP_ENABLED` | Required non-secret | `true` | False fails. |
| `ADMIN_TOTP_ISSUER` | Required non-secret | Stable authenticator issuer | Blank fails; changing it changes future provisioning labels. |
| `ADMIN_PASSWORD_ONLY_FALLBACK` | Required non-secret | `false` | True fails; no runtime password-only mode exists. |
| `ADMIN_BOOTSTRAP_ENABLED` | Required non-secret | Normally `false` | Disabled is valid; enabled requires every constraint below. |
| `ADMIN_BOOTSTRAP_ENVIRONMENT` | Conditional required non-secret | `production` | Must match production. |
| `ADMIN_BOOTSTRAP_TARGET_EMAIL` | Conditional required non-secret | Existing verified, TOTP-enrolled target | Must be a valid explicit address; configuration alone never promotes it. |
| `ADMIN_BOOTSTRAP_OPERATOR_REFERENCE` | Conditional required non-secret | Non-secret change/operator reference | Blank fails. |
| `ADMIN_BREAK_GLASS_ENABLED` | Required non-secret | Normally `false` | Recovery remains a separate CLI ceremony. |
| `ADMIN_BREAK_GLASS_ENVIRONMENT`, `ADMIN_BREAK_GLASS_REPLACEMENT_EMAIL`, `ADMIN_BREAK_GLASS_OPERATOR_REFERENCE` | Conditional required non-secret | Production-bound recovery constraints | Missing or inconsistent enablement fails. |
| `ADMIN_BREAK_GLASS_COMPROMISED_EMAIL` | Optional non-secret | Explicit compromised administrator | If set, FND-14 validates account and revocation eligibility. |

Bootstrap and break-glass remain CLI-only, operator-confirmed, atomic,
correlated, audited, TOTP- and notification-gated. Bootstrap additionally
requires zero administrators and an unset persistent completion marker and
never reopens. Configuration never directly assigns administrator status.

TOTP seeds and recovery codes are encrypted persisted secrets. DEC-015 fixes
algorithm, period, digit count, replay prevention, shared durable throttles,
recovery ceremonies, recent-password proof, and single-use operation-bound
fresh TOTP. There is no generic factor-disable or password-only bypass.

## External providers and feature-scoped settings

OpenFoodFacts needs no API secret. `OPENFOODFACTS_USER_AGENT` is a required
non-secret production identifier/contact, for example
`VibeDietr/1.0 (https://app.example.com/contact)`. Base URL, pinned API
compatibility version, timeouts, attempts, backoff, and maximum Retry-After are
non-secret settings.

DEC-005 imports are local and disabled by default. When
`RECIPE_IMPORTS_ENABLED=true`, readiness requires
`RECIPE_IMPORT_TRANSIENT_DISK` to match the private durable disk,
`RECIPE_IMPORT_PARSER_VERSION`, `RECIPE_IMPORT_EXTRACTOR_VERSION`, an
identified `RECIPE_IMPORT_USER_AGENT`, and a queue. Webpage import allows only
HTTP/HTTPS ports 80/443, validates every redirect and A/AAAA response, and pins
the validated address to the connection. The maximum URL length is 2,048,
redirect count five, and decoded HTML is streamed through the
`RECIPE_IMPORT_MAX_RESPONSE_BYTES=2097152` limit. The User-Agent must contain
a production version and public contact but no account or recipe data.
Configuration also records `txt,md,html`, two-MiB upload limits,
3/15-second timeouts, three
attempts with 10/60-second backoff, concurrency two, ten imports per user per
hour, and 24-hour cleanup. There is no parser credential or silent fallback.

DEC-006 OCR is local and disabled by default. `OCR_ENABLED=true` requires
pinned `OCR_TESSERACT_VERSION=5`, `OCR_LANGUAGE=eng`, HEIC decoder and
preprocessing versions, queue/concurrency, 20-MiB/50-megapixel/single-image
limits, attempts/timeout, private durable transient storage, and cleanup.

Google Document AI is the only approved optional fallback. Enabling
`OCR_GOOGLE_FALLBACK_ENABLED` requires local OCR, project/processor IDs,
`OCR_GOOGLE_LOCATION=eu`, EU endpoint, pinned model, mounted credential path
in `GOOGLE_APPLICATION_CREDENTIALS`, timeout, and positive monthly page quota
and budget. The credential file is a required secret. Incomplete Google
configuration fails only that enabled feature and never causes a silent switch.

## Secret handling, rotation, and troubleshooting

Inject secrets from deployment environment/secret management. No cloud-specific
manager is selected. Use separate least-privilege credentials per environment.
Never commit, log, audit, queue, or include secret values in exceptions, and
never copy production secrets into local development.

Rotate compromised database, mail/provider, Google, and object-storage
credentials at their providers, update injection, and restart orderly.
`ADMIN_SECURITY_FINGERPRINT_KEY` rotation affects fingerprint continuity.
`APP_KEY` rotation is not trivial: persisted TOTP seeds and other encrypted
values depend on it. Use previous-key support and a reviewed re-encryption plan;
loss of every valid key can make encrypted data unrecoverable.

The command groups static failures and exits non-zero without printing configured
values. Correct the named setting, clear stale cache with `config:clear`, cache
again, and rerun. After a static pass, investigate secret-free FND-13 provider,
capacity, destination, clock, audit, worker, and failure-monitor health rather
than bypassing the control.
