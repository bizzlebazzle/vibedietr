#!/usr/bin/env bash
# Disposable privileged helper only. No production paths.
set -euo pipefail
umask 077
[[ ${RECOVERY_DRILL:-} == yes && ${1:-} =~ ^dep08-[a-z0-9-]{8,64}$ ]]
mapping="$1"
[[ -f /drill/journal-disposal-probe.sql && ! -L /drill/journal-disposal-probe.sql ]]
cleanup_luks() {
    cryptsetup close "$mapping-data" >/dev/null 2>&1 || true
    cryptsetup close "$mapping-header" >/dev/null 2>&1 || true
}
trap cleanup_luks EXIT
mkdir /keys
mount -t tmpfs -o rw,noswap,size=32m,mode=0700 tmpfs /keys
mkdir /drill/luks-probe
head -c 32 /dev/urandom > /keys/data-key
head -c 32 /dev/urandom > /keys/header-key
for part in data header; do
    truncate -s 64M "/drill/luks-probe/$part.img"
    cryptsetup luksFormat --batch-mode --type luks2 --pbkdf pbkdf2 --iter-time 10 --key-file "/keys/$part-key" "/drill/luks-probe/$part.img"
done
cryptsetup luksHeaderBackup /drill/luks-probe/data.img --header-backup-file /keys/old-header
cryptsetup open --key-file /keys/data-key /drill/luks-probe/data.img "$mapping-data"
expected=$(sha256sum /drill/journal-disposal-probe.sql | cut -d ' ' -f 1)
bytes=$(stat -c %s /drill/journal-disposal-probe.sql)
dd if=/drill/journal-disposal-probe.sql of="/dev/mapper/$mapping-data" bs=1M conv=fsync status=none
actual=$(head -c "$bytes" "/dev/mapper/$mapping-data" | sha256sum | cut -d ' ' -f 1)
[[ $actual == "$expected" ]]
cryptsetup close "$mapping-data"
cryptsetup luksErase --batch-mode /drill/luks-probe/data.img
if cryptsetup open --test-passphrase --key-file /keys/data-key /drill/luks-probe/data.img >/dev/null 2>&1; then exit 1; fi
# An old header plus its key still decrypts the payload after slot erasure.
cryptsetup open --test-passphrase --header /keys/old-header --key-file /keys/data-key /drill/luks-probe/data.img
# Retire the separately encrypted header-custody generation too.
cryptsetup open --key-file /keys/header-key /drill/luks-probe/header.img "$mapping-header"
dd if=/keys/old-header of="/dev/mapper/$mapping-header" bs=1M conv=fsync status=none
cryptsetup close "$mapping-header"
cryptsetup luksErase --batch-mode /drill/luks-probe/header.img
if cryptsetup open --test-passphrase --key-file /keys/header-key /drill/luks-probe/header.img >/dev/null 2>&1; then exit 1; fi
for part in data header; do
    cryptsetup luksDump --dump-json-metadata "/drill/luks-probe/$part.img" | tr -d '[:space:]' | grep -q '"keyslots":{}'
done
rm /keys/old-header /keys/data-key /keys/header-key
rm /drill/journal-disposal-probe.sql
echo 'Synthetic LUKS2 journal-byte and header keyslot checks passed; full host/key-copy disposal still needs inventory verification.'
