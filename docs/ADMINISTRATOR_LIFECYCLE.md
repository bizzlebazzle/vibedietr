# Administrator lifecycle

FND-14 implements DEC-009 using application-owned services. Ordinary
application code must not assign or revoke `users.is_administrator` directly.

## Initial bootstrap

Run the CLI-only command after creating, verifying, and enrolling TOTP for the
exact target account:

```bash
./vendor/bin/sail artisan administrator:bootstrap
```

Production requires all of these protected environment values:

- `ADMIN_BOOTSTRAP_ENABLED=true`
- `ADMIN_BOOTSTRAP_ENVIRONMENT=production`
- `ADMIN_BOOTSTRAP_TARGET_EMAIL` equal to the target's verified email
- `ADMIN_BOOTSTRAP_OPERATOR_REFERENCE` set to a bounded, traceable, non-secret
  deployment/host-operator reference
- `APP_INSTANCE` and `APP_VERSION` set to safe operational references

The command displays the environment and configured target and defaults its
interactive confirmation to no. Non-interactive production execution is
refused. Missing or mismatched configuration, an ineligible target, any
existing administrator, an already-set marker, unavailable audit persistence,
or failure to persist every notification intent leaves the role and marker
unchanged.

The migration-owned `administrator_lifecycle_states` singleton exists before
bootstrap. The command locks it and the relevant user/admin rows, then grants
the role, appends audit evidence, creates the durable notification intent, and
sets the marker in one database transaction. Queued provider delivery occurs
after commit. Provider acceptance is not atomic with the role change and is not
claimed as delivery or reading. The marker is independent of user records and
cannot be cleared through lifecycle code.

## Routine promotion and revocation

The minimal authenticated lifecycle screen is
`/security/administrator-lifecycle`. Each action first requires password
confirmation within five minutes and a fresh, operation-bound, single-use TOTP
proof within two minutes.

Promotion initiation validates the target's current verified email, confirmed
factor, ordinary role, and absence of another pending request. The target must
accept while authenticated with their own proof before the exact 24-hour
expiry. The target may decline; any active administrator may cancel. Terminal
states are idempotent and cannot be accepted later. The hourly
`administrator:expire-promotions` command finalizes due requests.

Revocation is other-administrator only. A locked count guarantees one active
administrator remains. It revokes the role, cancels pending promotions
initiated by the revoked administrator, removes database sessions, rotates the
remember token, and makes the central gate read current database state.
Revocation retains the account's general TOTP factor as DEC-015 permits.

The profile deletion flow takes the same lifecycle/admin-row lock. The sole
administrator is denied deletion; one of multiple administrators continues
through the existing account-deletion behavior.

## Break-glass replacement

Break-glass is never bootstrap. It requires a completed initial-bootstrap
marker and separate protected configuration:

- `ADMIN_BREAK_GLASS_ENABLED=true`
- `ADMIN_BREAK_GLASS_ENVIRONMENT=production`
- `ADMIN_BREAK_GLASS_REPLACEMENT_EMAIL` equal to an eligible ordinary account
- optional `ADMIN_BREAK_GLASS_COMPROMISED_EMAIL` equal to the exact
  administrator being replaced
- `ADMIN_BREAK_GLASS_OPERATOR_REFERENCE` set to a traceable non-secret
  operator reference

After normal account/factor recovery has been exhausted, run:

```bash
./vendor/bin/sail artisan administrator:break-glass-replace
```

The command is CLI-only and operator-confirmed. It refuses while another
administrator outside the configured compromised account remains technically
usable. Replacement activation, optional compromised-account revocation,
session invalidation, audit events, and all required durable notification
intents commit atomically. The bootstrap marker is only read and is never reset.

## Failure and environment behavior

Mandatory audit persistence fails every privilege mutation closed. DEC-016's
durable local boundary applies to privilege increases: bootstrap, promotion
initiation/acceptance, and break-glass fail if readiness or required intent
persistence is unavailable. Remote delivery is queued after commit.

Risk-reducing decline, cancellation, expiry, and revocation remain available
when notification persistence/delivery is unhealthy. Their notification failure
is best-effort and does not preserve dangerous access.

Production readiness continues to reject log, array, null, local catcher,
unmonitored sendmail, and unsafe failover transports. The normal seeder creates
no administrator. The test factory role state is accepted only in the testing
environment; production model-level role mutation and bootstrap-marker mutation
outside the lifecycle scope throw without persisting.
