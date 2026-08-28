# Security controls

## Scope

DEP-03 supplies reusable browser, abuse, input, transient-storage, resource,
cleanup and redaction controls. It does not authorize or implement a new import
format or provider. Feature code remains responsible for authorization and for
choosing a stricter limit or MIME allowlist where its approved policy requires
one.

REC-16 continues to own URL, redirect, SSRF, DNS-rebinding, fetch timeout,
HTTP response-size and webpage-extraction rules. REC-17 continues to own its
launch document/image formats, 20 MiB image rule, 50-megapixel decoded bound,
HEIC conversion, metadata stripping, decoder/OCR/provider constraints and
feature-specific cleanup verification.

## Response headers and CSP

`AddSecurityHeaders` runs centrally for web responses and supplies:

- `Content-Security-Policy`;
- `X-Content-Type-Options: nosniff`;
- `Referrer-Policy: strict-origin-when-cross-origin`;
- a permissions policy that permits this origin's camera for the barcode
  scanner and disables microphone, geolocation, payment and USB;
- `X-Frame-Options: DENY` as compatible defence in depth alongside CSP
  `frame-ancestors 'none'`;
- HSTS only in production on a request Laravel recognizes as HTTPS; and
- `no-store, private` plus legacy `Pragma: no-cache` on authentication,
  profile, administrator-security and recipe-import pages.

Production CSP defaults every resource to the same origin, denies objects and
frames, restricts forms and the base URL to the same origin, and permits only
same-origin/data/blob image, font, media and worker uses that the current
application needs. Vite and Livewire script/style elements receive a fresh
request nonce. Remote font loading was removed; server-side providers are not
browser CSP sources.

Livewire 3's bundled Alpine expression evaluator currently needs
`script-src 'unsafe-eval'`. Existing Alpine/Livewire behavior also needs inline
style attributes, isolated to `style-src-attr 'unsafe-inline'`. CSP does not
permit inline script elements, wildcard script hosts or arbitrary future
provider/CDN hosts. These concessions must be retested when Livewire changes.

Local development additionally permits the configured
`VITE_DEV_SERVER_URL` HTTP and WebSocket origins for HMR. Production never
includes those development origins and adds `upgrade-insecure-requests`.
There is no general browser/end-to-end suite; PHPUnit checks the policy and
nonces, and the production frontend build is the automated CSP asset smoke
check. A release smoke test should still inspect the browser console on the
welcome, login, dashboard, recipe and barcode-scanner pages.

## Throttle matrix

Identities are SHA-256 application keys. Raw email, IP, search, barcode, URL,
filename or import content is never a limiter key or telemetry label.

| Route or action | Identity/key | Default rate | Window | Reason | Configuration |
| --- | --- | --- | --- | --- | --- |
| Failed login | Normalized email plus IP, hashed | 5 failures | 1 minute | Credential guessing | Application config |
| Password reset request/reset | Normalized email plus IP, hashed | 5 | 1 minute | Reset abuse | Application config |
| Password reset IP ceiling | IP, hashed | 20 | 1 hour | Distributed-identifier abuse from one source | Application config |
| Password confirmation | Authenticated user plus IP, hashed | 10 | 1 minute | Re-authentication guessing | Application config |
| Email verification link | Laravel signed-link identity | 6 | 1 minute | Replay/verification abuse | Existing route policy |
| Second-factor and administrator-security writes | Authenticated user plus IP, hashed | 10 | 1 minute | Security-flow abuse | Application config |
| Public recipe search | Authenticated user plus IP, or guest IP, hashed | 60 | 1 minute | Scraping/query cost | Application config |
| Barcode lookup | Authenticated user plus IP, hashed | 30 | 1 minute | Upstream cost/proxy abuse | Application config |
| Barcode global ceiling | Explicit global key | 300 | 1 minute | Provider/application capacity | `SECURITY_BARCODE_GLOBAL_PER_MINUTE` |
| Recipe visibility mutation | Authenticated user plus IP, hashed | 30 | 1 minute | Current sharing-like write abuse | Application config |
| Import submit and retry | Authenticated user plus IP, hashed | 10 | 1 hour | DEC-005 submission policy | `RECIPE_IMPORT_PER_USER_PER_HOUR` |
| Import global ceiling | Explicit global key | 500 | 1 hour | Prevent one workload exhausting capacity | `RECIPE_IMPORT_GLOBAL_PER_HOUR` |

There are currently no invitation or share-link creation/regeneration routes.
Read-only recipe routes are intentionally not subject to the sharing-write
limiter. Submission throttles do not replace later job/provider concurrency or
quota controls.

## Request and upload limits

`RejectOversizedRequest` rejects a declared body above
`SECURITY_MAX_REQUEST_BYTES` before route/controller/Livewire work and returns a
safe 413. It also bounds an already-available non-multipart body when no usable
length was declared. The default is 26 MiB, leaving envelope headroom above
the generic 25 MiB `SECURITY_MAX_UPLOAD_BYTES`. A caller may always supply a
smaller upload bound before decoding, parsing, OCR or provider submission.

Laravel cannot reject a body before a reverse proxy, web server or PHP has
accepted it. Production must align all layers:

1. the edge/platform and web-server body ceiling rejects abusive traffic as
   early as possible;
2. PHP `post_max_size` is at least the intended request-envelope ceiling and
   `upload_max_filesize` is at least the intended generic/feature upload limit;
3. `SECURITY_MAX_REQUEST_BYTES` remains above the upload limit to cover
   multipart framing; and
4. every feature validates a stricter approved bound before expensive work.

If the edge/PHP limit is lower, its own safe 413 is authoritative and Laravel
cannot format that response. Production validation checks positive bounded
values and request headroom but cannot inspect external infrastructure.

## Private transient input and MIME validation

`TransientInputStore` uses `SECURITY_TRANSIENT_DISK`, which must be a private,
non-served disk and must equal durable production storage. Local development
uses `storage/app/private/transient`; its files/directories use 0600/0700
permissions. Keys are `inputs/<ULID>`. Original filenames never become object
keys and traversal-like names have no path influence. Handles are processing
objects, not queue payloads; jobs persist and enqueue an opaque database input
identifier instead.

`ContentTypeInspector` derives MIME from bytes with PHP Fileinfo, normalizes
the browser declaration, compares known extensions with maintained MIME
families, and rejects suspicious disagreement. Browser MIME or extension alone
is never sufficient. Callers may supply their approved MIME allowlist. DEP-03
does not define REC-17's final format matrix. HTML and other text remain bytes
on private storage; DEP-03 adds no rendering, preview or execution path.

## Bounded parsing and cleanup

`ParsingBudget` and `ResourceGuard` provide byte, character, item, nesting and
elapsed-time assertions with one safe resource-limit exception. Defaults are
central configuration, while feature code may construct a stricter budget.
The elapsed assertion is cooperative and is not an OS/process timeout.

`TransientInputStore::cleanup()` is idempotent. It reports `deleted`, `missing`
or `failed`; missing input is already clean. Failure telemetry contains only a
stable operation and outcome, never disk, key, filename, path or content.
Future feature workflows call cleanup after success and terminal failure and
may add an inventoried periodic abandoned-input schedule under DEP-04/DEP-05.
DEP-03 adds no scheduled job or retention period.

## Redaction and asynchronous privacy

Capture remains allowlist-first: operational telemetry accepts only stable,
low-cardinality fields; FND-05 validates each audit action schema; FND-09 jobs
carry identifiers; and expected job exceptions use constant safe messages.
`SensitiveDataRedactor` and the Monolog processor are secondary protection for
structured log context, covering credentials, cookies/sessions, import/OCR
content, filenames, paths and provider payloads. Do not interpolate those
values into a log message.

Exception input exclusions cover the same classes. An exception following a
request with a sensitive top-level field receives a generic 500 even in debug;
safe framework 4xx responses remain conventional. Default raw exception
logging stops after provider-neutral telemetry records environment, release,
correlation, exception class and safe operation/outcome.

Metrics may label only stable categories such as `route_category`, `limiter`,
`outcome` and `content_validation_result`. User IDs, filenames, URLs, resource
paths, arbitrary request paths and content are prohibited. Audit payload keys
for source/extraction text, filenames, paths and provider payloads are rejected.

The native failed-job store remains enabled. Known inventory jobs have
identifier-only serialized payloads; unknown or personal payload classes are
removed immediately by `PrivacyAwareFailedJobProvider`, with metadata-only
known failures pruned under DEP-04. Never serialize a transient handle, file
bytes, source text, filename or provider request/response into a job.
