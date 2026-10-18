# DEP-07 verification and release constraints

## Status on 2026-10-18

Implementation and local verification are complete; release is **conditionally
complete** pending the installation checks below. Priority remains P2.
PLAN-12, REC-14, FND-09, DEP-04 and DEP-05 were complete and DEC-008 was
decided when `context:task -- DEP-07` ran. No format/privacy decision was
reopened. The implementation follows the
[approved contract](ACCOUNT_EXPORT_RESEARCH.md#approved-version-1-contract)
and [export operation guide](ACCOUNT_EXPORTS.md).

The earlier Composer 300-second timeout is resolved. The full gate passed
with a per-run execution budget, without changing application limits, suite
selection or assertions:

```bash
./vendor/bin/sail exec -T -e COMPOSER_PROCESS_TIMEOUT=1800 laravel.test composer test
```

## Automated evidence

- Full backend suite: **1,075 tests, 7,051 assertions passed**, 293.39 seconds.
  All 13 export tests passed, including the final dispatch-recovery,
  private-tag, public-bookmark and exact imported-value regressions. The
  failure-path mock helper was corrected to use Mockery's underlying
  expectation before the successful full run.
- Coverage includes representative complete packaging, owned immutable
  versions and diary chains, cross-user minimization, recipient aliases,
  exact values and timestamps, authenticated stream bytes and expiry,
  duplicate/retried requests, upload-before-audit failure, deletion retries,
  crash staging, owner removal, metadata-only jobs and retention alerts.
- `./vendor/bin/sail pint --test`: passed, 749 files.
- `./vendor/bin/sail composer analyse`: passed after the final PHP/test edits;
  no baseline additions.
- `./vendor/bin/sail npm run test:accessibility`: **16 tests passed** with no
  axe violations across the scanned states. The export regression exercises
  empty/request/ready states, keyboard access to request and download,
  desktop/mobile reflow and both themes. Screenshots and reports are in the
  ignored `storage/app/testing/ux07` directory.
- `./vendor/bin/sail npm run test:planning`: **6 tests passed**.
- Sail `npm run test:scanner`, `npm run test:recovery` and `npm run docs:test`:
  **24, 5 and 20 tests passed**, respectively.
- Sail `npm run docs:check`, `npm run build` and `npm run env:check` passed;
  Docker Compose parsed `.env.example` successfully.
- [PR #95](https://github.com/bizzlebazzle/vibedietr/pull/95) publishes the
  commits and runs the required `Backend tests`, `PHP formatting`, `Static
  analysis` and `Frontend build` checks. Inspect its latest head checks before
  merge. Main-branch protection was not inspected; no enforcement claim is made.

## Production-relevant and manual evidence

- The checked-in synthetic production configuration passed
  `app:production-check`, isolated `config:cache`, and a second readiness
  configuration check. The temporary cache was cleared without changing the
  normal application cache. This proves static configuration, not live health.
- The additive migration ran successfully locally and in the disposable
  supported MySQL test database; the full suite preserved existing behavior.
- The final 1,500-entry fixture generated/read back in 0.402 seconds with
  96,993,280 allocated bytes and a 25,995-byte ZIP. Measurements are recorded
  in ignored `storage/app/testing/dep07/scale.json`; they are local evidence,
  not a production capacity claim.
- Manual local browser checks covered empty, queued, ready and expired states,
  mobile dark layout and keyboard focus reaching download. Synthetic accounts
  and archives were cleaned. Final automated desktop-light and mobile-dark
  screenshots were also visually reviewed.
- Final diff review checked the projection/ownership boundaries, identifier-only
  jobs, private storage, transactional publication/audit, expiry and cleanup.
- `CURRENT_STATE.md` passes the repository's 220-line limit regression,
  including blank lines and its trailing-line counting convention.

## Remaining release evidence

- Complete installation-specific private S3/encryption, worker/scheduler,
  storage deletion/version disposal, backup expiry, staging disposal/capacity,
  alert routing and actual delivery/expiry/deletion drills described in the
  operation guide. Production credentials/infrastructure were not used.
  Record these under DEP-09 before production activation.
- Human assistive-technology checks and broader real-device verification remain
  release evidence; manual mobile/keyboard checks do not substitute for them.

These operational constraints remain explicit. Local fakes, synthetic cached
configuration and CI are not proof of live encryption, object-version/backup
disposal, worker health or alert delivery.
