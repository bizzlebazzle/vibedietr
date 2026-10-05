#!/usr/bin/env bash
# Operator-only restic operations. See docs/BACKUP_RESTORE_RUNBOOK.md.
set -euo pipefail
umask 077
: "${RECOVERY_WORKSPACE:?restricted encrypted workspace required}"
: "${RECOVERY_ENV_FILE:?restic environment file required}"
: "${RECOVERY_SECRETS:?restricted secrets directory required}"
: "${RECOVERY_INSTALLATION:?installation namespace required}"
: "${RECOVERY_APP_IMAGE:?application image containing Node 22 required}"
recovery_root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
restic_image=restic/restic:0.19.1@sha256:136600b6ff6843d61d355f7f71f460a166429f35de6fd11b568fece3c9a4d510
network_args=()
if [[ -n ${RECOVERY_NETWORK:-} ]]; then network_args=(--network "$RECOVERY_NETWORK"); fi
restic_run() {
    docker run --rm --user "$(id -u):$(id -g)" "${network_args[@]}" \
        --env-file "$RECOVERY_ENV_FILE" -e TZ=UTC -e RESTIC_PASSWORD_FILE=/secrets/restic-password \
        -v "$RECOVERY_SECRETS:/secrets:ro" -v "$RECOVERY_WORKSPACE:/work" \
        "$restic_image" --no-cache "$@"
}
node_run() {
    docker run --rm -i --network none --user "$(id -u):$(id -g)" \
        -v "$recovery_root/scripts/recovery:/scripts:ro" -v "$RECOVERY_WORKSPACE:/work" \
        --entrypoint node "$RECOVERY_APP_IMAGE" "$@"
}
inventory() { restic_run snapshots --json > "$RECOVERY_WORKSPACE/snapshots.json"; }
case ${1:-} in
    capture)
        [[ ${RECOVERY_QUIESCED:-} == yes ]]
        cutoff=$(node_run /scripts/recovery-set.mjs time /work/set)
        node_run /scripts/recovery-set.mjs verify /work/set "$RECOVERY_INSTALLATION" "$cutoff"
        # The original manifest cutoff follows every copy. Never use --skip-if-unchanged.
        restic_time=$(date -u -d "$cutoff" '+%Y-%m-%d %H:%M:%S')
        restic_run backup --json --time "$restic_time" --tag vibedietr-paired /work/set > "$RECOVERY_WORKSPACE/capture.json"
        restic_run check > "$RECOVERY_WORKSPACE/check.log"
        ;;
    restore)
        [[ ${RECOVERY_QUARANTINED:-} == yes && ! -e $RECOVERY_WORKSPACE/quarantine ]]
        inventory
        node_run /scripts/snapshot-policy.mjs eligible "${2:?full snapshot ID required}" < "$RECOVERY_WORKSPACE/snapshots.json" > "$RECOVERY_WORKSPACE/selected.json"
        cutoff=$(node_run --input-type=module -e 'import{readFileSync}from"node:fs";process.stdout.write(JSON.parse(readFileSync("/work/selected.json")).time)')
        restic_run check --read-data > "$RECOVERY_WORKSPACE/check.log"
        restic_run restore "$2" --target /work/quarantine > "$RECOVERY_WORKSPACE/restore.log"
        node_run /scripts/recovery-set.mjs verify /work/quarantine/work/set "$RECOVERY_INSTALLATION" "$cutoff"
        ;;
    expire)
        [[ ${RECOVERY_MAINTENANCE:-} == yes ]]
        # Maintenance runs on the repository host, bypassing append-only capture access.
        : "${RECOVERY_REPOSITORY_PATH:?repository-host filesystem path required}"
        restic_run() {
            docker run --rm --network none --user "$(id -u):$(id -g)" \
                -e RESTIC_REPOSITORY=/repository -e RESTIC_PASSWORD_FILE=/secrets/restic-password \
                -v "$RECOVERY_REPOSITORY_PATH:/repository" -v "$RECOVERY_SECRETS:/secrets:ro" \
                "$restic_image" --no-cache "$@"
        }
        inventory
        node_run /scripts/snapshot-policy.mjs expire < "$RECOVERY_WORKSPACE/snapshots.json" > "$RECOVERY_WORKSPACE/expired.txt"
        while IFS= read -r snapshot || [[ -n $snapshot ]]; do
            [[ -z $snapshot ]] || restic_run forget "$snapshot" >> "$RECOVERY_WORKSPACE/expiry.log"
        done < "$RECOVERY_WORKSPACE/expired.txt"
        # Retry pruning even when a prior sweep already removed the snapshot metadata.
        restic_run prune --max-unused 0 >> "$RECOVERY_WORKSPACE/expiry.log"
        restic_run check --read-data >> "$RECOVERY_WORKSPACE/expiry.log"
        inventory
        node_run /scripts/snapshot-policy.mjs expire < "$RECOVERY_WORKSPACE/snapshots.json" > "$RECOVERY_WORKSPACE/remaining-expired.txt"
        [[ ! -s $RECOVERY_WORKSPACE/remaining-expired.txt ]]
        node_run --input-type=module -e 'import{writeFileSync}from"node:fs";writeFileSync("/work/expiry-success.json",JSON.stringify({installation:process.argv[1],verified_at:new Date().toISOString()}),{mode:0o600})' "$RECOVERY_INSTALLATION"
        ;;
    monitor)
        inventory
        node_run /scripts/snapshot-policy.mjs monitor /work/expiry-success.json "$RECOVERY_INSTALLATION" < "$RECOVERY_WORKSPACE/snapshots.json"
        ;;
    *) echo 'Usage: repository.sh capture | restore FULL_ID | expire | monitor' >&2; exit 1 ;;
esac
echo 'Recovery repository operation passed; targets remain quarantined.'
