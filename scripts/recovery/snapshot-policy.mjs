import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

const week = 7 * 24 * 60 * 60 * 1000;

export function classifySnapshots(snapshots, now = new Date()) {
    if (!Array.isArray(snapshots) || !Number.isFinite(now.getTime())) throw new Error('invalid_inventory');
    const seen = new Set();
    const expired = [];
    const eligible = [];
    for (const snapshot of snapshots) {
        const captured = Date.parse(snapshot.time);
        if (!/^[a-f0-9]{64}$/.test(snapshot.id) || seen.has(snapshot.id)
            || !Number.isFinite(captured) || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|\+00:00)$/.test(snapshot.time)
            || captured > now.getTime()) throw new Error('invalid_snapshot');
        seen.add(snapshot.id);
        (now.getTime() - captured >= week ? expired : eligible).push(snapshot.id);
    }
    return { expired, eligible };
}

export function requireEligible(snapshots, id, now = new Date()) {
    if (!classifySnapshots(snapshots, now).eligible.includes(id)) throw new Error('ineligible_snapshot');
    return snapshots.find(snapshot => snapshot.id === id);
}

export function monitorSnapshots(snapshots, expiryProof, installation, now = new Date()) {
    const classified = classifySnapshots(snapshots, now);
    const proofTime = Date.parse(expiryProof?.verified_at);
    if (expiryProof?.installation !== installation || !Number.isFinite(proofTime)
        || proofTime > now.getTime() || now.getTime() - proofTime >= 86400000) throw new Error('expiry_monitor_stale');
    const paired = snapshots.filter(snapshot => snapshot.tags?.includes('vibedietr-paired'));
    if (!paired.length || now.getTime() - Math.max(...paired.map(snapshot => Date.parse(snapshot.time))) >= 86400000) throw new Error('capture_stale');
    if (snapshots.some(snapshot => now.getTime() - Date.parse(snapshot.time) >= 8 * 86400000)) throw new Error('expiry_overdue');
    return { eligible_count: classified.eligible.length, expired_count: classified.expired.length };
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    try {
        const snapshots = JSON.parse(readFileSync(0, 'utf8'));
        if (process.argv[2] === 'eligible') {
            process.stdout.write(JSON.stringify(requireEligible(snapshots, process.argv[3])));
        } else if (process.argv[2] === 'expire') {
            process.stdout.write(classifySnapshots(snapshots).expired.join('\n'));
        } else if (process.argv[2] === 'monitor') {
            const proof = JSON.parse(readFileSync(process.argv[3], 'utf8'));
            process.stdout.write(JSON.stringify(monitorSnapshots(snapshots, proof, process.argv[4])));
        } else throw new Error('invalid_action');
    } catch {
        process.stderr.write('Recovery snapshot policy failed; keep targets closed.\n');
        process.exitCode = 1;
    }
}
