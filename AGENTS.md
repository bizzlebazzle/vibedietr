# AGENTS.md

## Project purpose

This is a personal recipe and diet-planning web application built with
Laravel and Livewire.

The application should allow users to:

- Save and organise recipes.
- Record structured recipe ingredients.
- Match ingredients to food and nutrition data - using the OpenFoodFacts API
  and bar code scanning, where available.
- Estimate calories and nutritional values for recipes based on input ingredients.
- Enable users to plan meals and diets using saved recipes.

Nutrition values calculated for meals from ingredient values are estimates and
must be presented as such. Nutritional values for ingredients that are imported
from the OFF API can be treated as accurate.

## Working rules

- Inspect existing code before making changes.
- Prefer small, focused changes.
- Do not rewrite unrelated code.
- Preserve existing behaviour unless the task explicitly changes it.
- Do not silently invent product requirements.
- Ask for clarification when product behaviour is genuinely ambiguous.
- Never remove user data or create destructive migrations without approval.
- Never commit credentials, tokens or secrets.
- Route all OpenFoodFacts HTTP access and provider response mapping through the
  application-owned client under `app/Integrations/OpenFoodFacts`; UI code must
  consume its stable result types rather than provider requests or raw JSON.
- Route administrator-factor verification through the centralized FND-13
  services. Never log, audit, serialize into queued jobs, or include in
  exceptions any submitted TOTP, seed, provisioning payload, recovery code,
  provider credential, session value, or notification body. Production
  administrator workflows must pass the FND-13 readiness boundary and must not
  use log, array, null, local-catcher, or unmonitored local delivery.
- Queued or scheduled product workflows are not production-ready until DEP-04
  and DEP-05 are complete. Every future roadmap item introducing them must
  declare both dependencies, inventory the work, and define privacy-safe
  health, metrics, alerts, and runbook coverage.
- Add every new queued or scheduled job to `docs/JOB_INVENTORY.md` and the
  queue operations configuration.
- Preserve the tested worker/job timeout plus safety margin below
  `retry_after`; document schedule locking and overlap behavior.
- Follow `docs/QUEUE_OPERATIONS.md` for failed-job replay and forgetting.
- Preserve the original ingredient text entered by the user.
- Keep the application recognisably conventional Laravel and Livewire.
- Prefer clear, maintainable code over clever abstractions.
- Follow `docs/QUEUED_JOB_CONVENTIONS.md` for asynchronous work.
- Administrator status must never be changed directly by ordinary application
  code. Production assignment and revocation must use the approved FND-14
  administrator lifecycle services.

When beginning any backlog item, first check whether it depends on unresolved
entries in docs/DECISIONS.md.

If it does not, continue without asking for additional product-owner input.

If it does, stop, explain exactly which decision blocks progress and why.

## Git rules

- Work on a dedicated branch or worktree.
- Keep each task suitable for one reviewable commit or pull request.
- Do not force-push.
- Do not rewrite existing Git history.
- Do not commit generated dependencies such as vendor or node_modules.
- If you believe a file should be added to .gitignore, ask for clarification.

## Development commands

Run commands from the repository root in WSL. This project uses Laravel Sail;
Docker Desktop with WSL integration must be running. Do not rely on PHP,
Composer or Node being installed directly in WSL.

The supported baseline is Sail PHP 8.4 with container-supplied Node 22, MySQL
8.0, database-backed queues/cache/sessions, and local Mailpit. `README.md`
contains the complete fresh-checkout workflow and host-platform notes.

### Install dependencies

On a fresh checkout, create `.env` and install Composer dependencies with
Sail's PHP 8.4 bootstrap image:

```bash
test -f .env || cp .env.example .env
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs
```

The checked-in `.env.example` is the non-secret MySQL/Sail development
baseline. Keep real local or external credentials only in the ignored `.env`.

Validate Compose, start Sail, generate an application key, and install the
locked frontend dependencies:

```bash
docker compose --env-file .env.example config --quiet
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail npm ci
```

Once `vendor` exists, PHP dependencies can be reinstalled with:

```bash
./vendor/bin/sail composer install
```

### Prepare a fresh development database

For a newly created disposable database only, run:

```bash
./vendor/bin/sail artisan migrate --seed
```

The seeder creates one ordinary `test@example.com` user and is not idempotent.
For an existing development database, use the safe normal command
`./vendor/bin/sail artisan migrate`.

Do not use `migrate:fresh`, `db:wipe`, or `sail down -v` against an existing
development environment without explicit approval; those commands erase data.

### Start the application

Start the application and supporting services:

```bash
./vendor/bin/sail up -d
```

The application is then available at `http://localhost`. For frontend hot
reloading, run this in a separate terminal:

```bash
./vendor/bin/sail npm run dev -- --host 0.0.0.0
```

### Run the queue worker

Basic application use does not require a worker. Administrator security
notifications use the database queue and require:

```bash
./vendor/bin/sail artisan queue:work --queue=security-notifications,default
```

Stop an interactive worker with Ctrl+C. Request a graceful restart after code
or configuration changes with `./vendor/bin/sail artisan queue:restart`.

### Run tests

```bash
./vendor/bin/sail composer test
```

Run the deterministic barcode-scanner adapter and integration tests without a
physical camera:

```bash
./vendor/bin/sail npm run test:scanner
```

### Format code

Apply the project's Laravel Pint formatting:

```bash
./vendor/bin/sail pint
```

Check formatting without changing files:

```bash
./vendor/bin/sail pint --test
```

### Static analysis

Run static analysis from the repository root:

```bash
./vendor/bin/sail composer analyse
```

`composer analyse` runs Larastan with PHPStan at level 5 against the
application, factories, seeders, routes, and tests. New findings must be fixed;
do not add them to the baseline casually.

The committed baseline contains 10 reviewed existing findings: one optional
email-verification contract mismatch, five redundant expressions in the legacy
ingredient form, and four intentional test assertions that PHPStan can prove are
tautological. The baseline keeps these findings visible
without preventing analysis from rejecting new defects.

After correcting one or more baselined findings, review the remaining output
and regenerate the baseline so it becomes smaller:

```bash
./vendor/bin/sail php -d memory_limit=1G vendor/bin/phpstan analyse --generate-baseline=phpstan-baseline.neon
```

Run the deliberately invalid fixture regression separately when changing the
analysis setup:

```bash
./vendor/bin/sail composer analyse:failure-regression
```

### Validate the development environment

Check required `.env.example` values and important Compose alignment:

```bash
./vendor/bin/sail npm run env:check
docker compose --env-file .env.example config --quiet
```

### Validate documentation

Run Markdown structure, deterministic local-link, and decision-register checks:

```bash
./vendor/bin/sail npm run docs:check
```

When changing the documentation validator or its configuration, also run the
failure-regression fixtures:

```bash
./vendor/bin/sail npm run docs:test
```

### Validate shared security controls

Run the focused DEP-03 header, throttle, input, transient-storage, MIME,
resource, cleanup and redaction checks with:

```bash
./vendor/bin/sail test tests/Feature/SecurityControlsTest.php tests/Feature/TransientInputSecurityTest.php tests/Feature/RedactionPrivacyTest.php
```

Decision-register entries use a `## DEC-NNN — Title` heading and the fields
`Question requiring resolution`, `Why it matters`, `Status`, `Owner`,
`Alternatives`, `Existing constraints from ...`, `Backlog relationships`,
`Resolution condition`, and `Final decision and rationale`. Allowed statuses
are `Open`, `Research required`, `Owner input required`, `Decided`, and
`Superseded`.

To register a deferred product decision:

1. Add the decision to `docs/DECISIONS.md` and its summary table.
2. Give it a new, never-reused `DEC-NNN` identity and add the same ID and title
   to `scripts/docs/decision-identities.json`.
3. Add `Decision: DEC-NNN.` to the corresponding bullet in the deferred
   section of `docs/PRODUCT_SPEC.md`. List multiple IDs with commas.
4. Use the decision ID in roadmap relationships; do not copy or repurpose an
   existing ID.

For link failures, resolve relative targets from the Markdown file containing
the link, then check the filename spelling and letter case. The normal command
does not contact external websites.

### Validate production configuration

Use synthetic or deployment-injected values; never place production secrets in
shell history, repository files, or command output. The check is non-mutating:

```bash
./vendor/bin/sail artisan app:production-check
```

Run it before and after `./vendor/bin/sail artisan config:cache`. See
`docs/PRODUCTION_CONFIGURATION.md` for required settings. Static readiness does
not replace live FND-13 provider, worker, clock, audit, and destination health.

### Validate health and observability

Public health routes are `/up`, `/health/live`, and `/health/ready`. Run safe
internal diagnostics and the provider-neutral monitor with:

```bash
./vendor/bin/sail artisan app:health
./vendor/bin/sail artisan observability:scheduler-heartbeat
./vendor/bin/sail artisan observability:monitor
./vendor/bin/sail test tests/Feature/ObservabilityTest.php
```

Production must select and deploy the `platform` or `hosted` observability
adapter, release identifier, collector/dashboard, and role-based alert routing.
Local and CI require no observability credentials.

### Build frontend assets

```bash
./vendor/bin/sail npm run build
```

## Continuous integration

GitHub Actions runs four independent quality gates for pull requests and pushes
to `main`. CI uses PHP 8.4, Node 22.18, and a clean MySQL 8.0 service database.
It installs dependencies from `composer.lock` and `package-lock.json`; caches
contain package downloads only and are keyed by the corresponding lock file.

The `Quality gates` workflow reports these required branch-protection job/check
names:

- `Backend tests`
- `PHP formatting`
- `Static analysis`
- `Frontend build`

The `Frontend build` job also runs `npm run docs:check` and
`npm run docs:test` after the lock-file installation and before building
assets, so documentation failures gate the same pull requests.

Run their local equivalents from the repository root:

```bash
./vendor/bin/sail composer test
./vendor/bin/sail pint --test
./vendor/bin/sail composer analyse
./vendor/bin/sail npm run build
```

Repository administrators must configure all four status checks as required in
the `main` branch protection rules. The workflow provides the statuses but does
not itself enforce repository branch protection.

## Verification

Before considering a coding task complete:

- Run the relevant automated tests.
- Add tests for new behaviour where practical.
- Run formatting and static-analysis tools that exist in the project.
- Report any checks that could not be run.
- Review the final diff for unrelated changes.

## Stop conditions

Stop and explain the issue when:

- Requirements conflict.
- A destructive database change appears necessary.
- Production credentials are required.
- Existing behaviour cannot be determined safely.
- A change would significantly alter the architecture.
- Tests fail for reasons outside the task.
