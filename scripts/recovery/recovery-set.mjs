import { createHash } from 'node:crypto';
import { closeSync, lstatSync, openSync, readSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

function digestFile(path) {
    const file = openSync(path, 'r');
    const hash = createHash('sha256');
    const buffer = Buffer.alloc(1048576);
    try {
        let length;
        while ((length = readSync(file, buffer)) > 0) hash.update(buffer.subarray(0, length));
        return hash.digest('hex');
    } finally { closeSync(file); }
}
function files(directory, prefix = '') {
    const inventory = {};
    for (const name of readdirSync(join(directory, prefix)).sort()) {
        const relative = prefix ? `${prefix}/${name}` : name;
        if (relative === 'manifest.json') continue;
        const stat = lstatSync(join(directory, relative));
        if (stat.isDirectory()) Object.assign(inventory, files(directory, relative));
        else if (stat.isFile()) inventory[relative] = { bytes: stat.size, sha256: digestFile(join(directory, relative)) };
        else throw new Error('unsafe_file');
    }
    return inventory;
}

export function sealSet(directory, installation, release, capturedAt = new Date().toISOString()) {
    if (!/^[a-zA-Z0-9_-]{1,128}$/.test(installation) || !/^[a-zA-Z0-9_.-]{1,128}$/.test(release)) throw new Error('invalid_identity');
    const inventory = files(directory);
    if (!inventory['database.sql']?.bytes || !inventory['database.json']?.bytes || !inventory['objects/inventory.json']?.bytes) throw new Error('incomplete_pair');
    const manifest = { format: 1, policy: 'DEC-012-seven-day', installation, release, captured_at: capturedAt, files: inventory };
    writeFileSync(join(directory, 'manifest.json'), JSON.stringify(manifest), { mode: 0o600, flag: 'wx' });
    return manifest;
}

export function verifySet(directory, installation, snapshotTime, now = new Date()) {
    if (!lstatSync(join(directory, 'manifest.json')).isFile()) throw new Error('unsafe_manifest');
    const manifest = JSON.parse(readFileSync(join(directory, 'manifest.json'), 'utf8'));
    const captured = Date.parse(manifest.captured_at);
    if (manifest.format !== 1 || manifest.policy !== 'DEC-012-seven-day' || manifest.installation !== installation
        || !Number.isFinite(captured) || captured > now.getTime() || now.getTime() - captured >= 7 * 86400000
        || Math.abs(captured - Date.parse(snapshotTime)) >= 1000 || !Number.isFinite(Date.parse(snapshotTime))
        || JSON.stringify(manifest.files) !== JSON.stringify(files(directory))) throw new Error('invalid_recovery_set');
    if (!manifest.files['database.sql']?.bytes || !manifest.files['database.json']?.bytes || !manifest.files['objects/inventory.json']?.bytes) throw new Error('incomplete_pair');
    return manifest;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    try {
        const [action, directory, installation, extra, cutoff] = process.argv.slice(2);
        if (action === 'seal') sealSet(directory, installation, extra, cutoff);
        else if (action === 'verify') verifySet(directory, installation, extra);
        else if (action === 'time') process.stdout.write(JSON.parse(readFileSync(join(directory, 'manifest.json'))).captured_at);
        else throw new Error('invalid_action');
    } catch {
        process.stderr.write('Recovery set verification failed; keep targets closed.\n');
        process.exitCode = 1;
    }
}
