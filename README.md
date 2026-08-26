# VibeDietr

VibeDietr is a personal recipe and diet-planning application built with
Laravel, Livewire, Vite, and Tailwind CSS. It currently supports recipe and
ingredient workflows; meal planning and broader nutrition features are being
implemented incrementally. See [`docs/CURRENT_STATE.md`](docs/CURRENT_STATE.md)
for the exact implemented scope.

## Supported development environment

The supported local environment is Laravel Sail running through Docker Compose:

- PHP 8.4 and Composer are supplied by the Sail application container.
- Node 22 and npm are supplied by the same container.
- MySQL 8.0 is the development and test database baseline.
- Local queues, cache, and sessions use MySQL-backed Laravel drivers.
- Redis is provisioned for future use but is not a default application driver.
- Mailpit receives local application mail at `http://localhost:8025`.

Windows with WSL2 and Docker Desktop integration is the actively verified host
setup. Run every command below inside the WSL distribution, from the repository
root. Linux and macOS can use the same standard Sail workflow and have no known
repository-specific blocker, but are not currently exercised by the project's
local verification or CI through Sail.

## Prerequisites

Install Git, Docker with the Compose plugin, and the minimum shell tools needed
to run the commands below. On Windows, install Docker Desktop, enable WSL2
integration for the chosen distribution, and clone the repository inside the
WSL filesystem for predictable permissions and performance.

Host-installed PHP, Composer, Node, and npm are not required.

## Fresh checkout setup

Clone the repository and enter it:

```bash
git clone <repository-url> vibedietr
cd vibedietr
```

Create the local environment file. This does not overwrite an existing one:

```bash
test -f .env || cp .env.example .env
```

Bootstrap the locked PHP dependencies with Docker. This step is necessary
because Sail itself is installed in `vendor`:

```bash
docker run --rm \
  -u "$(id -u):$(id -g)" \
  -v "$(pwd):/var/www/html" \
  -w /var/www/html \
  laravelsail/php84-composer:latest \
  composer install --ignore-platform-reqs
```

Validate the example environment and Compose configuration, then start Sail:

```bash
docker compose --env-file .env.example config --quiet
./vendor/bin/sail up -d
```

Generate the local application key, install the locked frontend dependencies,
and prepare the new development database:

```bash
./vendor/bin/sail artisan key:generate
./vendor/bin/sail npm ci
./vendor/bin/sail artisan migrate --seed
```

The seeder creates one ordinary local account, `test@example.com`. It grants no
administrator access and is intended only for a new, disposable database. It is
not idempotent, so do not rerun it routinely.

Build the frontend assets and open `http://localhost`:

```bash
./vendor/bin/sail npm run build
```

No external credentials are needed for the basic application or read-only
OpenFoodFacts access. AWS values and production administrator-security delivery
settings are intentionally blank; supply your own values only if later work
explicitly enables those external services. Never commit a populated `.env`.

## Starting and stopping

Start the application and supporting services:

```bash
./vendor/bin/sail up -d
```

Stop the containers without deleting database volumes:

```bash
./vendor/bin/sail stop
```

Use `./vendor/bin/sail down` when the Compose network also needs removal; named
database volumes are retained unless the destructive `-v` option is added.

The browser reaches the application through host port 80 by default. Set
`APP_PORT` in the uncommitted `.env` if port 80 is occupied. `DB_PORT=3306` is
the container-to-container MySQL port and normally stays unchanged; use
`FORWARD_DB_PORT` only to change the optional MySQL port exposed on the host.

## Frontend development

Install dependencies from `package-lock.json` after each relevant lock-file
change:

```bash
./vendor/bin/sail npm ci
```

Run Vite in a separate terminal while Sail remains up:

```bash
./vendor/bin/sail npm run dev -- --host 0.0.0.0
```

Vite is exposed on port 5173 by default. Set `VITE_PORT` in `.env` if that host
port is unavailable. Create production assets with:

```bash
./vendor/bin/sail npm run build
```

## Database, migrations, and test safety

Apply outstanding migrations to the normal development database without
deleting existing rows:

```bash
./vendor/bin/sail artisan migrate
```

**Destructive database warning:** `migrate:fresh`, `db:wipe`, and
`./vendor/bin/sail down -v` erase database contents. Do not use them against an
existing development environment. The documented `migrate --seed` setup command
is only for a newly created disposable database.

Sail's MySQL initialization creates a separate `testing` database and grants
the development user access to it. `phpunit.xml` forces tests to use that
database plus synchronous queues and array-backed cache/session drivers.
Laravel's test database refreshes are therefore isolated from the normal
`vibedietr` development database. The test database uses the same local-only
MySQL account, but a different database name.

## Queue worker

Local jobs use the database queue. Basic browsing and recipe editing do not
need a worker; queued administrator security notifications do. Run both current
queues through Sail in a separate terminal:

```bash
./vendor/bin/sail artisan queue:work --queue=security-notifications,default
```

Stop an interactive worker with Ctrl+C. After code or configuration changes,
request a graceful restart with the following command and then rerun the worker
command if it is not managed by a process supervisor:

```bash
./vendor/bin/sail artisan queue:restart
```

Tests do not need a worker because PHPUnit sets `QUEUE_CONNECTION=sync`.
Production worker topology and monitoring are outside this development setup.

## Tests and quality checks

Run the same commands used as local equivalents of the CI quality gates:

```bash
./vendor/bin/sail composer test
./vendor/bin/sail pint --test
./vendor/bin/sail composer analyse
./vendor/bin/sail npm run env:check
./vendor/bin/sail npm run docs:check
./vendor/bin/sail npm run build
```

Additional focused regression commands are:

```bash
./vendor/bin/sail npm run test:scanner
./vendor/bin/sail composer analyse:failure-regression
./vendor/bin/sail npm run docs:test
```

Run one backend test file by passing its path through the Composer script:

```bash
./vendor/bin/sail composer test -- tests/Feature/ExampleTest.php
```

## Troubleshooting

- If Docker or Sail reports that it cannot connect, start Docker Desktop or the
  Docker daemon, confirm `docker compose version`, and retry `sail up -d`.
- If MySQL is still starting, check `./vendor/bin/sail ps` and wait for the
  `mysql` service to become healthy before migrating.
- If a port is already in use, set `APP_PORT`, `VITE_PORT`, or the relevant
  `FORWARD_*_PORT` in `.env`, then recreate the containers with `sail up -d`.
- If WSL-created files have the wrong owner, confirm the repository is inside
  the WSL filesystem and rerun the Docker Composer bootstrap with the documented
  `id -u`/`id -g` flags.
- If Laravel still uses an old environment value, run
  `./vendor/bin/sail artisan optimize:clear`.
- If Vite cannot find packages or its manifest, run `sail npm ci`, then either
  start the separate Vite terminal or run the production build.
- If queued notifications remain in the `jobs` table, start the documented
  worker and confirm `.env` still has `QUEUE_CONNECTION=database`.
- If database connections fail, keep `DB_HOST=mysql` for code running inside
  Sail and distinguish `DB_PORT` from the optional host `FORWARD_DB_PORT`.

## Project documentation

- [`docs/PRODUCTION_CONFIGURATION.md`](docs/PRODUCTION_CONFIGURATION.md) defines
  the fail-closed production environment and secret-handling contract.
- [`docs/CURRENT_STATE.md`](docs/CURRENT_STATE.md) records implemented behavior.
- [`docs/ROADMAP.md`](docs/ROADMAP.md) contains the sequenced backlog.
- [`docs/DEFINITION_OF_DONE.md`](docs/DEFINITION_OF_DONE.md) defines required
  verification.
- [`docs/QUEUED_JOB_CONVENTIONS.md`](docs/QUEUED_JOB_CONVENTIONS.md) governs
  asynchronous work.
- [`AGENTS.md`](AGENTS.md) provides concise repository rules and canonical
  commands for coding agents.

Laravel and its included open-source dependencies retain their respective
licenses and attribution.
