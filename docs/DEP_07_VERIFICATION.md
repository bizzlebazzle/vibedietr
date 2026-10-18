# DEP-07 verification and outstanding gates

## Status on 2026-10-18

Implementation is committed; verification is **incomplete**. Priority remains
P2. PLAN-12, REC-14, FND-09, DEP-04 and DEP-05 were complete and DEC-008 was
decided when `context:task -- DEP-07` ran. No format/privacy decision was
reopened. The implementation follows the
[approved contract](ACCOUNT_EXPORT_RESEARCH.md#approved-version-1-contract)
and [export operation guide](ACCOUNT_EXPORTS.md).

The required `./vendor/bin/sail composer test` gate did not complete. Composer
reported that its `artisan test` child exceeded the existing 300-second
process timeout. Unit-test output was present, but no full-suite result was
available. This is not a passing gate or evidence of a failed export assertion.
AGENTS.md requires stopping when required checks fail outside the task.
The orphaned test processes were terminated; no timeout setting or test
assertion was weakened. The next full run must use an agreed sufficient
execution budget and rebuild its disposable test schema normally.

## Completed evidence

- Focused export run: 13 tests, 210 assertions passed. It covered the package,
  owned history, privacy minimization, exact values, authenticated expiry,
  repeat requests, cleanup, safe queue payloads/locks, audit failure after
  upload, retryable deletion, owner removal and retention alerts.
- A later focused unclassified-field/permanent-failure test passed: one test,
  five assertions.
- `./vendor/bin/sail pint --test`: passed, 749 files. Subsequent changed-file
  formatting also passed.
- `./vendor/bin/sail composer analyse`: passed after the final PHP/test edits;
  no baseline additions.
- `./vendor/bin/sail npm run docs:check`, `npm run build` and `npm run env:check`
  through Sail passed. Compose parsed `.env.example` successfully.
- The checked-in synthetic production configuration passed
  `app:production-check`, isolated `config:cache`, and a second readiness
  configuration check. The temporary cache was cleared without changing the
  normal application cache. This proves static configuration, not live health.
- The additive migration ran successfully locally. Owner-removal cleanup and
  durable state behavior were exercised by focused tests.
- The synthetic 1,500-entry fixture generated/read back in 0.297 seconds with
  71,827,456 allocated bytes and a 25,915-byte ZIP in one local focused run.
  These are local measurements, not a production capacity claim.
- Manual local browser: empty, queued, ready and expired states; mobile dark
  layout and keyboard focus reaching download. The synthetic account and
  archive were cleaned afterward. A ready-state screenshot was saved locally.
- Final diff review checked the projection/ownership boundaries, identifier-only
  jobs, private storage, transactional publication/audit, expiry and cleanup.
- `CURRENT_STATE.md` was verified at 220 lines, including blank lines.

## Still required

- Rerun focused exports and the full backend gate on the final commit. Final
  review added a short orphaned-dispatch lease/re-dispatch safeguard, its
  regression, and more private-tag/public-bookmark/imported-value fixture
  assertions after the 13-test passing run. Those additions are statically
  checked but have not received a completed execution run.
- Run `./vendor/bin/sail npm run test:accessibility`. The added export browser
  regression covers keyboard, desktop/mobile and both themes; it has not run.
  Planning-browser tests were not run locally because planner behavior/UI was
  not changed; the established remote CI still runs them.
- Obtain all four remote Quality gates statuses after publication. No branch
  push or PR was made, so no remote success or branch-protection claim is made.
- Complete installation-specific private S3/encryption, worker/scheduler,
  storage deletion/version disposal, backup expiry, staging disposal/capacity,
  alert routing and actual delivery/expiry/deletion drills described in the
  operation guide. Production credentials/infrastructure were not used.
- Human assistive-technology checks and broader real-device verification remain
  release evidence; manual mobile/keyboard checks do not substitute for them.

The Composer timeout blocks completion. Live operational constraints and the
unexecuted final regressions must not be reported as passed.
