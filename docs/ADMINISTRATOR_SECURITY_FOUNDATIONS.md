# Administrator security foundations

## Scope

FND-13 implements the reusable authentication and notification boundary required
by DEC-015 and DEC-016. It does not grant or revoke administrator status,
bootstrap an administrator, or implement pending promotions; those transitions
remain FND-14.

## Owner-approved implementation timings

The owner approved these implementation clarifications on 12 August 2026:

- recent primary authentication lasts five minutes;
- a fresh TOTP proof lasts two minutes, is operation-bound, and is single-use;
- pending TOTP enrollment expires after 30 minutes;
- lost-device recovery sessions and assisted recovery authorizations expire
  after 15 minutes;
- consecutive verification failures delay the next attempt by 1, 2, 4, 8,
  then 16 seconds;
- destination-verification challenges expire after 60 minutes;
- provider acceptance and capacity evidence must be no older than 24 hours;
- queue worker and failed-job monitoring evidence must be no older than five
  minutes; and
- capacity evidence must cover the complete current event recipient set.

## Second factor

`SecondFactorEnrollmentService` creates an encrypted pending RFC 6238 seed.
The UI displays its provisioning QR and manual Base32 value only during the
pending enrollment. A six-digit SHA-1 TOTP using a 30-second period and the
current timestep plus one adjacent timestep in either direction proves
possession. Ten recovery codes are then shown once. The factor becomes active
only after explicit acknowledgement that they were saved.

The active seed remains authenticated-encrypted through Laravel. Seed-bearing
model fields are guarded from ordinary mass assignment and hidden from arrays
and JSON. Recovery codes are independently one-way hashed. Submitted values,
plaintext seeds, QR payloads, and recovery values are not logged or audited.

The accepted factor/timestep is stored durably. Verification updates it with a
conditional database write, so only one concurrent request can consume a
timestep. Enrollment confirmation carries its consumed timestep into the
active factor. Replay state never resets.

Verification failures are scoped by account, factor, and operation and also by
a keyed source fingerprint; raw source IP addresses are not stored. Five
failures in ten minutes throttle the scope. Ten consecutive failures lock the
account verification boundary for 30 minutes. New TOTP timesteps do not clear
failure state.

Recovery requires the password plus one unused recovery code and creates only
a short-lived factor-replacement authorization; it does not create a privileged
TOTP proof. Regeneration requires immediate password confirmation and a fresh
TOTP, replaces all old recovery hashes atomically, and shows the new plaintext

`RecoveryAuthorizationService` also persists target-bound assisted-administrator
authorizations and one-use deployment-CLI authorizations. CLI plaintext has
128 bits of entropy, is displayed only by the issuing caller, and only its
one-way hash is stored. Assisted authorizations expire after 15 minutes and CLI
authorizations after ten minutes. They grant no administrator session or
privileged proof, restrict the target's privileged workflows while pending, and
are consumed only when the target completes replacement-factor confirmation.
Cancellation or expiry leaves the old factor requirement in force.

set once. There is no password-only or factor-disabled mode.

## Privileged authentication

`RecentAuthentication` represents recent primary authentication separately
from `auth.second_factor_proof`. A fresh proof identifies the account and exact
operation, expires after two minutes, and is consumed on successful guard use.
`PrivilegedWorkflowGuard` requires an authenticated administrator, a confirmed
factor, recent primary authentication, and the matching fresh proof. It does
not globally challenge ordinary application requests.

## Notifications

`SecurityEventType` represents all mandatory DEC-009, DEC-015, and DEC-016
events. `SecurityNotificationIntentService` resolves the affected account and
active-administrator recipients, suppresses duplicate recipients, and persists
one intent per logical event, recipient, mail channel, destination version, and
correlation identifier.

`DeliverSecurityNotification` queues only the intent identifier. It reloads the
recipient, attempts delivery at most three times with 10- and 60-second
backoff, has a 30-second timeout, and records provider acceptance separately
from delivery or reading. Retryable failures are deferred; permanent failures
and retry exhaustion mark the channel unhealthy. A duplicate execution exits
after provider acceptance.

Messages contain a safe event description, UTC time, environment, application
instance, correlation reference, and recommended response. Laravel generates
plain-text and semantic HTML forms. The messages contain no authentication
material, private application content, audit payload, raw destination, or raw
request context.

## Production readiness

`ProductionSecurityReadiness` fails closed when production selects log, array,
null, local catcher, unmonitored sendmail, unsafe failover, sync queue, or
another non-delivery configuration. It also requires HTTPS, secure cookies, an
application encryption key, an identified permitted provider, verified sender
and administrator destinations, provider credentials, authenticated encrypted
non-local SMTP where selected, a durable asynchronous queue, synchronized-clock
and audit-persistence health, capacity headroom, a recent controlled provider
acceptance, and recent worker/failure-monitor heartbeats.

Local manual inspection uses Mailpit. Automated tests use fakes, array delivery,
or application-owned test doubles and never a production provider. These modes
cannot satisfy production readiness.
