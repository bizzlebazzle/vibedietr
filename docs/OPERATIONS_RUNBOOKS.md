# Operations runbooks

## Safety rules for every incident

Use safe identifiers, aggregates, and correlation IDs. Never paste production
payloads, recipe/ingredient/instruction text, diary/target data, import/OCR
content, email, credentials, bodies, or raw provider responses into logs or
support channels.

Never run `migrate:fresh`, `db:wipe`, `sail down -v`, delete queue tables,
purge failures blindly, replay every failure, disable security controls, or
switch to an unapproved provider. Confirm the environment and take
non-destructive observations before repair.

## Application readiness failure

1. Confirm liveness separately at `/health/live`.
2. Run `php artisan app:health` through the operations channel.
3. Check recent deployment/configuration and run `app:production-check`.
4. Verify dependencies through approved consoles without printing secrets.
5. Restore the dependency through its deployment procedure; never use a
   destructive migration as a connectivity repair.
6. Confirm readiness and recent critical-workflow success.

## Queue worker unavailable

1. Verify queue-backend readiness; reachability does not prove a worker.
2. Check the supervised queue process, deployment, limits, and heartbeat.
3. Use DEP-04's graceful `queue:restart` and supervised replacement.
4. Confirm heartbeat, depth, and oldest age after recovery.
5. Preserve DEC-016 fail-closed behavior for security notifications.

## Queue backlog or old job

1. Confirm worker and scheduler freshness.
2. Inspect queue, depth, age, and safe job categories only; never dump payloads.
3. Check provider latency/rate limits, database locks, and deployments.
4. Scale or recover only through the approved DEP-04 topology.
5. Confirm depth and age fall; investigate retries before adding capacity.

## Failure or exception spike

1. Identify the safe operation/job/provider category and deployment window.
2. Use correlation IDs to join logs without private-data searches.
3. Check code/configuration and provider health.
4. Establish root cause before retrying; never bulk replay.
5. Follow `QUEUE_OPERATIONS.md`, verify idempotency/provider acceptance, retry
   one UUID, and observe it.

## Scheduler stale

1. Check the supervised UTC `schedule:work` process and recent logs.
2. Confirm shared database cache and one-server locks.
3. Restart or reconfigure through the deployment supervisor.
4. Identify missed promotion expiry, monitoring, and pruning work.
5. Run a missed command only after reviewing its inventory entry.

## Provider outage or latency

1. Confirm official provider health and safe application aggregates.
2. Follow bounded timeout, retry, and rate-limit policy.
3. Never log content/raw responses or switch providers without approval.
4. Communicate degradation; optional provider loss does not fail liveness.
5. Confirm recovery using safe success/latency counters.

## Security notification failure

1. Treat this as a DEC-016/FND-13 fail-closed security incident.
2. Verify worker, failure monitor, provider acceptance/capacity, sender,
   destination, clock, and audit health through approved tools.
3. Never fall back to log/array/null/local catcher/sendmail or bypass TOTP.
4. Before one replay, confirm provider acceptance and durable intent state.
5. Restore live FND-13 readiness before administrator workflows resume.

## Failed-job pruning stale

1. Confirm the daily 00:15 UTC schedule and last prune heartbeat.
2. Review retention and immediate-personal-removal rules in
   `QUEUE_OPERATIONS.md` and `AUDIT_RETENTION_SCHEDULE.md`.
3. Find the lock, database, or scheduler cause.
4. Run `queue:prune-operational-failures` only against the confirmed database
   after reviewing counts.
5. Never mass-delete or flush failures. Audit history is a separate store.
