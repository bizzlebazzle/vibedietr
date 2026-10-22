#!/usr/bin/env bash
# Sourced only by drill.sh: inherits its disposable containers, functions and cleanup.
drill_step=privacy-independent-stores
drill_primary="$drill_id-erasure-primary"
drill_mirror="$drill_id-erasure-mirror"
drill_erasure_containers=("$drill_primary" "$drill_mirror")
for container in "${drill_erasure_containers[@]}"; do
    docker run -d --name "$container" --network "$drill_network" --env-file "$drill_workspace/mysql.env" \
        --tmpfs /var/lib/mysql:rw,size=512m "$mysql_image" --skip-log-bin --general-log=OFF --slow-query-log=OFF >/dev/null
done
for container in "${drill_erasure_containers[@]}"; do
    ready=no
    for attempt in {1..60}; do
        if docker exec "$container" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N -e "SELECT 1"' >/dev/null 2>&1; then ready=yes; break; fi
        sleep 1
    done
    [[ $ready == yes ]]
done
drill_password=$(sed -n 's/^DB_PASSWORD=//p' "$drill_workspace/app.env")
cat >> "$drill_workspace/app.env" <<EOF
ERASURE_INSTALLATION=synthetic-dep06-privacy
ERASURE_PRIVACY_OPERATOR_REFERENCE=synthetic-privacy-operator
ERASURE_OPERATOR_ENVIRONMENT=testing
ERASURE_JOURNAL_PRIMARY_URL=mysql://root:$drill_password@$drill_primary:3306/testing
ERASURE_JOURNAL_MIRROR_URL=mysql://root:$drill_password@$drill_mirror:3306/testing
ERASURE_JOURNAL_GENERATION=synthetic-erasure-generation-one
ERASURE_JOURNAL_ENCRYPTION_KEY=base64:$(openssl rand -base64 32)
ERASURE_JOURNAL_INTEGRITY_KEY=base64:$(openssl rand -base64 32)
ERASURE_EVIDENCE_URL=mysql://root:$drill_password@$drill_primary:3306/evidence
ERASURE_EVIDENCE_ENCRYPTION_KEY=base64:$(openssl rand -base64 32)
OBSERVABILITY_RELEASE=synthetic-drill
EOF
unset drill_password
app_run "$drill_source" scripts/recovery/erasure-drill.php seed > "$drill_workspace/privacy-seed.log"
cat "$drill_workspace/signing.env" >> "$drill_workspace/app.env"
mkdir "$drill_workspace/privacy-set"
app_run "$drill_source" artisan operations:reconcile-database --quiesced --exclude-transient-input --write=/drill/privacy-set/database.json > "$drill_workspace/privacy-reconcile.log"
app_run "$drill_source" artisan operations:recovery-input-projection --quiesced --write=/drill/privacy-set/imports.sql > "$drill_workspace/input-projection.log"
docker exec "$drill_source" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -uroot --no-data --no-tablespaces --set-gtid-purged=OFF --routines --events --triggers testing' > "$drill_workspace/privacy-set/database.sql"
docker exec "$drill_source" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -uroot --single-transaction --quick --hex-blob --no-tablespaces --set-gtid-purged=OFF --no-create-info --skip-triggers --ignore-table=testing.recipe_imports testing' >> "$drill_workspace/privacy-set/database.sql"
cat "$drill_workspace/privacy-set/imports.sql" >> "$drill_workspace/privacy-set/database.sql"
rm "$drill_workspace/privacy-set/imports.sql"
app_run "$drill_source" artisan operations:recovery-objects capture /drill/privacy-set/objects --quiesced --ownership=/drill/ownership.json --exclude=exports/ > "$drill_workspace/privacy-objects.log"
if grep -q DEP08_TRANSIENT_SQL_MUST_NEVER_ENTER_BACKUP "$drill_workspace/privacy-set/database.sql"; then
    echo 'Transient SQL source leaked into recovery capture.' >&2
    exit 1
fi
privacy_cutoff=$(date -u +%Y-%m-%dT%H:%M:%SZ)
node_run /scripts/recovery-set.mjs seal /work/privacy-set synthetic-dep06-privacy synthetic-drill "$privacy_cutoff"
restic_run backup --json --tag synthetic-dep08-privacy /work/privacy-set > "$drill_workspace/privacy-capture.json"
restic_run snapshots --json > "$drill_workspace/privacy-snapshots.json"
drill_restore_set=$(node_run --input-type=module -e 'import{readFileSync}from"node:fs";process.stdout.write(JSON.parse(readFileSync("/work/privacy-snapshots.json")).find(s=>s.tags?.includes("synthetic-dep08-privacy")).id)')
drill_restore_manifest=$(sha256sum "$drill_workspace/privacy-set/manifest.json" | cut -d ' ' -f 1)
app_run "$drill_source" scripts/recovery/erasure-drill.php advance > "$drill_workspace/privacy-advance.log"
restic_run check --read-data > "$drill_workspace/privacy-restic-check.log"
restic_run restore "$drill_restore_set" --target /work/privacy-restored > "$drill_workspace/privacy-restore.log"
# A lost mirror is unavailable until current primary evidence repairs it.
docker rm -fv "$drill_mirror" >/dev/null
docker run -d --name "$drill_mirror" --network "$drill_network" --env-file "$drill_workspace/mysql.env" \
    --tmpfs /var/lib/mysql:rw,size=512m "$mysql_image" --skip-log-bin --general-log=OFF --slow-query-log=OFF >/dev/null
ready=no
for attempt in {1..60}; do
    if docker exec "$drill_mirror" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N -e "SELECT 1"' >/dev/null 2>&1; then ready=yes; break; fi
    sleep 1
done
[[ $ready == yes ]]
app_run "$drill_source" scripts/recovery/erasure-drill.php mirror-repair > "$drill_workspace/privacy-mirror-repair.log"
drill_step=privacy-release-matrix
drill_release_certificate=/drill/privacy-release.json
for privacy_round in 1 2; do
    drill_target_bucket="privacy-sanitized-$privacy_round"
    docker exec "$drill_garage" /garage bucket create "$drill_target_bucket" >/dev/null
    docker exec "$drill_garage" /garage bucket allow --read --write "$drill_target_bucket" --key "$garage_key" >/dev/null
    drill_restore_target=$(app_run "$drill_source" scripts/recovery/erasure-drill.php target-id)
    docker exec -i "$drill_target" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot testing' < "$drill_workspace/privacy-restored/work/privacy-set/database.sql"
    app_run "$drill_target" scripts/recovery/erasure-drill.php unreleased > "$drill_workspace/privacy-unreleased-$privacy_round.log"
    app_run "$drill_target" scripts/recovery/erasure-drill.php journal-negative > "$drill_workspace/privacy-journal-negative-$privacy_round.log"
    app_run "$drill_target" scripts/recovery/erasure-drill.php prepare > "$drill_workspace/privacy-prepare-$privacy_round.log"
    app_run "$drill_target" scripts/recovery/erasure-drill.php verify > "$drill_workspace/privacy-verify-$privacy_round.log"
    app_run "$drill_target" scripts/recovery/erasure-drill.php clock-negative > "$drill_workspace/privacy-clock-negative-$privacy_round.log"
    if [[ $privacy_round == 1 ]]; then
        app_run "$drill_target" scripts/recovery/erasure-drill.php write-negative > "$drill_workspace/privacy-write-negative.log"
    else
        app_run "$drill_target" scripts/recovery/erasure-drill.php release > "$drill_workspace/privacy-release.log"
        app_run "$drill_target" scripts/recovery/erasure-drill.php repeat > "$drill_workspace/privacy-repeat.log"
    fi
done
drill_step=privacy-luks2-disposal-primitive
docker exec "$drill_primary" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -uroot --single-transaction --quick --hex-blob --no-tablespaces --set-gtid-purged=OFF testing erasure_journal_entries' > "$drill_workspace/journal-disposal-probe.sql"
docker build -f scripts/recovery/cryptsetup.Dockerfile -t vibedietr-erasure-cryptsetup:synthetic scripts/recovery > "$drill_workspace/cryptsetup-build.log" 2>&1
docker run --rm --network none --privileged --ulimit memlock=-1:-1 \
    -e RECOVERY_DRILL=yes -v "$drill_workspace:/drill" -v "$drill_root/scripts/recovery:/scripts:ro" \
    --entrypoint bash vibedietr-erasure-cryptsetup:synthetic /scripts/luks-disposal-drill.sh "dep08-$drill_id" > "$drill_workspace/luks-disposal.log"
unset drill_restore_target drill_restore_set drill_restore_manifest drill_release_certificate drill_target_bucket
echo 'DEP-08 synthetic lifecycle replay, independent-object filtering, mirror host-loss repair and frozen-target release checks passed.'
