#!/usr/bin/env bash
# Disposable SYNTHETIC data only. No Sail/dev database mounts or published ports.
set -euo pipefail
umask 077
drill_root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$drill_root"
drill_workspace=$(mktemp -d /tmp/vibedietr-dep06-XXXXXXXX)
drill_id="dep06-$(basename "$drill_workspace" | tr '[:upper:]' '[:lower:]')"
drill_network="$drill_id"
drill_source="$drill_id-source"
drill_target="$drill_id-target"
drill_garage="$drill_id-objects"
drill_server="$drill_id-rest"
drill_app_image=${RECOVERY_DRILL_APP_IMAGE:-sail-8.4/app:latest}
mysql_image=mysql:8.4.10@sha256:8dbcf531a03aade657e181b9cf2f1d1803ce621a1d55610cb44cb531ab7d7db6
garage_image=dxflrs/garage:v2.3.0@sha256:866bd13ed2038ba7e7190e840482bc27234c4afaf77be8cfa439ae088c1e4690
restic_image=restic/restic:0.19.1@sha256:136600b6ff6843d61d355f7f71f460a166429f35de6fd11b568fece3c9a4d510
server_image=restic/rest-server:0.14.0@sha256:d2aff06f47eb38637dff580c3e6bce4af98f386c396a25d32eb6727ec96214a5
drill_started=$(date +%s)
drill_step=setup
cleanup() {
    status=$?
    docker rm -fv "$drill_source" "$drill_target" "$drill_garage" "$drill_server" >/dev/null 2>&1 || true
    docker network rm "$drill_network" >/dev/null 2>&1 || true
    if [[ $status -ne 0 ]]; then
        echo "Synthetic recovery drill failed during $drill_step." >&2
        if [[ ${RECOVERY_DRILL_KEEP_FAILURE:-no} == yes ]]; then
            echo "Synthetic diagnostics retained at $drill_workspace" >&2
            return
        fi
    fi
    # Container-created data is synthetic and restricted to this newly generated workspace.
    docker run --rm --network none -v "$drill_workspace:/drill" --entrypoint sh "$drill_app_image" -c 'rm -rf /drill/*' >/dev/null 2>&1 || true
    rm -rf -- "$drill_workspace"
}
trap cleanup EXIT
mkdir "$drill_workspace/secrets" "$drill_workspace/repository" "$drill_workspace/set"
mkdir -p "$drill_workspace/storage/framework/cache/data" "$drill_workspace/storage/framework/sessions" "$drill_workspace/storage/framework/views" "$drill_workspace/storage/logs"
openssl rand -hex 32 > "$drill_workspace/secrets/restic-password"
cat > "$drill_workspace/app.env" <<EOF
RECOVERY_DRILL=yes
APP_ENV=testing
APP_DEBUG=false
APP_KEY=base64:$(openssl rand -base64 32)
APP_CONFIG_CACHE=/drill/config.php
APP_MAINTENANCE_DRIVER=file
DB_CONNECTION=mysql
DB_URL=
DB_PORT=3306
DB_DATABASE=testing
DB_USERNAME=root
DB_PASSWORD=$(openssl rand -hex 24)
CACHE_STORE=array
SESSION_DRIVER=array
QUEUE_CONNECTION=sync
MAIL_MAILER=array
BCRYPT_ROUNDS=4
CATALOGUE_READ_CUTOVER=false
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=GK$(openssl rand -hex 16)
AWS_SECRET_ACCESS_KEY=$(openssl rand -hex 32)
AWS_DEFAULT_REGION=garage
AWS_ENDPOINT=http://$drill_garage:3900
AWS_USE_PATH_STYLE_ENDPOINT=true
EOF
# Credentials stay in restricted env files, never command arguments or committed output.
sed -n 's/^DB_PASSWORD=/MYSQL_ROOT_PASSWORD=/p' "$drill_workspace/app.env" > "$drill_workspace/mysql.env"
echo 'MYSQL_DATABASE=testing' >> "$drill_workspace/mysql.env"
sed -n 's/^AWS_ACCESS_KEY_ID=/GARAGE_DEFAULT_ACCESS_KEY=/p; s/^AWS_SECRET_ACCESS_KEY=/GARAGE_DEFAULT_SECRET_KEY=/p' "$drill_workspace/app.env" > "$drill_workspace/garage.env"
echo 'GARAGE_DEFAULT_BUCKET=source' >> "$drill_workspace/garage.env"
cat > "$drill_workspace/garage.toml" <<EOF
metadata_dir = "/tmp/meta"
data_dir = "/tmp/data"
db_engine = "sqlite"
replication_factor = 1
rpc_bind_addr = "[::]:3901"
rpc_public_addr = "$drill_garage:3901"
rpc_secret = "$(openssl rand -hex 32)"
[s3_api]
s3_region = "garage"
api_bind_addr = "[::]:3900"
EOF
docker network create --internal "$drill_network" >/dev/null
for container in "$drill_source" "$drill_target"; do
    docker run -d --name "$container" --network "$drill_network" --env-file "$drill_workspace/mysql.env" \
        --tmpfs /var/lib/mysql:rw,size=1g "$mysql_image" --skip-log-bin --general-log=OFF --slow-query-log=OFF >/dev/null
done
docker run -d --name "$drill_garage" --network "$drill_network" --env-file "$drill_workspace/garage.env" \
    -v "$drill_workspace/garage.toml:/etc/garage.toml:ro" --tmpfs /tmp --entrypoint /garage "$garage_image" server --single-node --default-bucket >/dev/null
docker run -d --name "$drill_server" --network "$drill_network" --user "$(id -u):$(id -g)" \
    -v "$drill_workspace/repository:/data" --entrypoint /usr/bin/rest-server "$server_image" --path /data --no-auth --append-only >/dev/null
drill_migration_mount=()
app_run() {
    local target=$1; shift
    local bucket=source
    [[ $target != "$drill_target" ]] || bucket=quarantine
    docker run --rm --network "$drill_network" --user "$(id -u):$(id -g)" --env-file "$drill_workspace/app.env" \
        -e "DB_HOST=$target" -e "AWS_BUCKET=$bucket" -v "$drill_root:/var/www/html:ro" -v "$drill_workspace:/drill" -v "$drill_workspace/storage:/var/www/html/storage" \
        "${drill_migration_mount[@]}" -w /var/www/html --entrypoint php "$drill_app_image" -d memory_limit=1G "$@"
}
node_run() {
    docker run --rm -i --network none --user "$(id -u):$(id -g)" \
        -v "$drill_root/scripts/recovery:/scripts:ro" -v "$drill_workspace:/work" \
        --entrypoint node "$drill_app_image" "$@"
}
restic_run() {
    docker run --rm --network "$drill_network" --user "$(id -u):$(id -g)" --env-file "$drill_workspace/restic.env" \
        -e TZ=UTC -e RESTIC_PASSWORD_FILE=/secrets/restic-password -v "$drill_workspace/secrets:/secrets:ro" \
        -v "$drill_workspace:/work" "$restic_image" --no-cache "$@"
}
expect_failure() {
    if "$@" >> "$drill_workspace/negative.log" 2>&1; then
        echo 'An unsafe recovery operation unexpectedly succeeded.' >&2; exit 1
    fi
}
for container in "$drill_source" "$drill_target"; do
    ready=no
    for attempt in {1..60}; do
        if docker exec "$container" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N -e "SELECT 1"' >/dev/null 2>&1; then ready=yes; break; fi
        sleep 1
    done
    [[ $ready == yes ]]
done
ready=no
for attempt in {1..30}; do
    if docker exec "$drill_garage" /garage bucket info source >/dev/null 2>&1; then ready=yes; break; fi
    sleep 1
done
[[ $ready == yes ]]
docker exec "$drill_garage" /garage bucket create quarantine >/dev/null
# The bootstrap key is synthetic; grant only the drill's two buckets.
garage_key=$(sed -n 's/^AWS_ACCESS_KEY_ID=//p' "$drill_workspace/app.env")
docker exec "$drill_garage" /garage bucket allow --read --write quarantine --key "$garage_key" >/dev/null
echo "RESTIC_REPOSITORY=rest:http://$drill_server:8000/repo" > "$drill_workspace/restic.env"
restic_run init > "$drill_workspace/init.log"
export RECOVERY_WORKSPACE="$drill_workspace" RECOVERY_ENV_FILE="$drill_workspace/restic.env"
export RECOVERY_SECRETS="$drill_workspace/secrets" RECOVERY_APP_IMAGE="$drill_app_image"
export RECOVERY_INSTALLATION=synthetic-dep06 RECOVERY_NETWORK="$drill_network"
export RECOVERY_QUIESCED=yes RECOVERY_QUARANTINED=yes
drill_step=paired-capture
app_run "$drill_source" artisan migrate --force > "$drill_workspace/migrate.log"
app_run "$drill_source" scripts/recovery/drill-fixtures.php > "$drill_workspace/fixtures.log"
cutoff=$(date -u +%Y-%m-%dT%H:%M:%SZ)
app_run "$drill_source" artisan operations:reconcile-database --quiesced --write=/drill/set/database.json > "$drill_workspace/reconcile.log"
docker exec "$drill_source" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -uroot --single-transaction --quick --hex-blob --no-tablespaces --set-gtid-purged=OFF --routines --events --triggers testing' > "$drill_workspace/set/database.sql"
app_run "$drill_source" artisan operations:recovery-objects capture /drill/set/objects --quiesced --ownership=/drill/ownership.json --exclude=exports/ > "$drill_workspace/objects.log"

# Inject a historical synthetic snapshot; ordinary capture refuses expired sets.
old_cutoff=$(date -u -d '8 days ago' +%Y-%m-%dT%H:%M:%SZ)
openssl rand 1048576 > "$drill_workspace/set/retired-only.bin"
node_run /scripts/recovery-set.mjs seal /work/set synthetic-dep06 drill "$old_cutoff"
restic_run backup --time "$(date -u -d "$old_cutoff" '+%Y-%m-%d %H:%M:%S')" --json /work/set > "$drill_workspace/old-capture.json"
rm "$drill_workspace/set/manifest.json" "$drill_workspace/set/retired-only.bin"
node_run /scripts/recovery-set.mjs seal /work/set synthetic-dep06 drill "$cutoff"
bash scripts/recovery/repository.sh capture > "$drill_workspace/capture.log"
restic_run snapshots --json > "$drill_workspace/all-snapshots.json"
node_run /scripts/snapshot-policy.mjs expire < "$drill_workspace/all-snapshots.json" > "$drill_workspace/old-id"
old_id=$(cat "$drill_workspace/old-id")
current_id=$(node_run --input-type=module -e 'import{readFileSync}from"node:fs";import{classifySnapshots}from"/scripts/snapshot-policy.mjs";process.stdout.write(classifySnapshots(JSON.parse(readFileSync("/work/all-snapshots.json"))).eligible[0])')
restic_run cat tree "$old_id:/work/set" > "$drill_workspace/old-tree.json"
retired_blob=$(node_run --input-type=module -e 'import{readFileSync}from"node:fs";process.stdout.write(JSON.parse(readFileSync("/work/old-tree.json")).nodes.find(node=>node.name==="retired-only.bin").content[0])')
restic_run cat blob "$retired_blob" > "$drill_workspace/retired-blob-proof.bin"
expect_failure bash scripts/recovery/repository.sh restore "$old_id"
expect_failure restic_run forget "$old_id"

drill_step=isolated-restore
docker restart "$drill_server" >/dev/null
bash scripts/recovery/repository.sh restore "$current_id" > "$drill_workspace/restore-operation.log"
restore_set="$drill_workspace/quarantine/work/set"
docker exec -i "$drill_target" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot testing' < "$restore_set/database.sql"
app_run "$drill_target" artisan operations:reconcile-database --quiesced --compare=/drill/quarantine/work/set/database.json >> "$drill_workspace/reconcile.log"
app_run "$drill_target" artisan operations:recovery-objects restore /drill/quarantine/work/set/objects --quiesced --isolated-empty-target >> "$drill_workspace/objects.log"
app_run "$drill_target" vendor/bin/phpunit tests/Recovery/RestoredAccessTest.php --do-not-cache-result > "$drill_workspace/access.log"
app_run "$drill_target" scripts/recovery/drill-object-checks.php > "$drill_workspace/object-negatives.log"
expect_failure app_run "$drill_target" artisan operations:recovery-objects restore /drill/quarantine/work/set/objects --quiesced --isolated-empty-target
cp "$restore_set/database.sql" "$drill_workspace/saved.sql"
echo 'corruption' >> "$restore_set/database.sql"
expect_failure node_run /scripts/recovery-set.mjs verify /work/quarantine/work/set synthetic-dep06 "$cutoff"
mv "$drill_workspace/saved.sql" "$restore_set/database.sql"

drill_step=migration-preflight-postflight-rollback
mkdir "$drill_workspace/migrations"
cp database/migrations/*.php "$drill_workspace/migrations/"
cat > "$drill_workspace/migrations/2099_01_01_000000_dep06_disposable_probe.php" <<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('users', fn (Blueprint $table) => $table->string('dep06_unused_probe')->nullable()); }
    public function down(): void { Schema::table('users', fn (Blueprint $table) => $table->dropColumn('dep06_unused_probe')); }
};
PHP
drill_migration_mount=(-v "$drill_workspace/migrations:/var/www/html/database/migrations:ro")
app_run "$drill_target" artisan operations:reconcile-database --quiesced --write=/drill/preflight.json >> "$drill_workspace/reconcile.log"
expect_failure app_run "$drill_target" artisan operations:reconcile-database --quiesced --compare=/drill/preflight.json --additive
app_run "$drill_target" artisan migrate --force >> "$drill_workspace/migrate.log"
app_run "$drill_target" artisan operations:reconcile-database --quiesced --compare=/drill/preflight.json --additive >> "$drill_workspace/reconcile.log"
app_run "$drill_target" artisan migrate:rollback --step=1 --force >> "$drill_workspace/migrate.log"
app_run "$drill_target" artisan operations:reconcile-database --quiesced --compare=/drill/preflight.json >> "$drill_workspace/reconcile.log"
drill_migration_mount=()
app_run "$drill_source" artisan operations:reconcile-database --quiesced --compare=/drill/set/database.json >> "$drill_workspace/reconcile.log"

drill_step=expiry-pruning-integrity
export RECOVERY_MAINTENANCE=yes RECOVERY_REPOSITORY_PATH="$drill_workspace/repository/repo"
bash scripts/recovery/repository.sh expire > "$drill_workspace/expiry-operation.log"
# Also prove retry after snapshot metadata removal, when no expired IDs remain.
bash scripts/recovery/repository.sh expire >> "$drill_workspace/expiry-operation.log"
bash scripts/recovery/repository.sh monitor > "$drill_workspace/monitor.log"
expect_failure restic_run restore "$old_id" --target /work/retired-attempt
expect_failure restic_run cat blob "$retired_blob"
restic_run check --read-data > "$drill_workspace/final-check.log"
# Wrong/lost keys and damaged packs must be observable failures.
mv "$drill_workspace/secrets/restic-password" "$drill_workspace/secrets/saved-password"
openssl rand -hex 32 > "$drill_workspace/secrets/restic-password"
expect_failure restic_run snapshots
mv "$drill_workspace/secrets/saved-password" "$drill_workspace/secrets/restic-password"
pack=$(find "$RECOVERY_REPOSITORY_PATH/data" -type f -print -quit)
chmod u+w "$pack"
printf 'damaged' | dd of="$pack" conv=notrunc status=none
expect_failure restic_run check --read-data
drill_recovery_elapsed=$(($(date +%s)-drill_started))
node_run --input-type=module -e 'import{readFileSync}from"node:fs";const d=JSON.parse(readFileSync("/work/set/database.json"));const o=JSON.parse(readFileSync("/work/set/objects/inventory.json"));console.log(JSON.stringify({tables:Object.keys(d.tables).length,foreign_key_columns:d.foreign_keys.length,users:d.tables.users.data.count,recipes:d.tables.recipes.data.count,versions:d.tables.recipe_versions.data.count,plans:d.tables.meal_plans.data.count,shares:d.tables.meal_plan_shares.data.count,durable_objects:Object.keys(o.objects).length}))'
if [[ ${RECOVERY_DRILL_FULL_SUITE:-no} == yes ]]; then
    drill_step=mysql84-application-compatibility
    app_run "$drill_target" artisan test > "$drill_workspace/mysql84-tests.log"
    tail -4 "$drill_workspace/mysql84-tests.log"
fi
echo "DEP-06 synthetic recovery checks passed in $drill_recovery_elapsed seconds ($(($(date +%s)-drill_started)) seconds including optional compatibility suite): paired restore, all-table reconciliation/FKs, public/private/share access, private objects/exclusions, negative integrity/eligibility checks, additive pre/postflight and unused-schema rollback, actual blob retirement/pruning and retry."
