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
            || !Number.isFinite(captured) || !/Z$|\+00:00$/.test(snapshot.time)
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

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    try {
        const snapshots = JSON.parse(readFileSync(0, 'utf8'));
        if (process.argv[2] === 'eligible') {
            process.stdout.write(JSON.stringify(requireEligible(snapshots, process.argv[3])));
        } else if (process.argv[2] === 'expire') {
            process.stdout.write(classifySnapshots(snapshots).expired.join('\n'));
        } else throw new Error('invalid_action');
    } catch {
        process.stderr.write('Recovery snapshot policy failed; keep targets closed.\n');
        process.exitCode = 1;
    }
}
