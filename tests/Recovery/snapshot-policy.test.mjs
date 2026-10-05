import assert from 'node:assert/strict';
import test from 'node:test';
import { classifySnapshots, requireEligible } from '../../scripts/recovery/snapshot-policy.mjs';

const now = new Date('2026-10-05T12:00:00Z');
const snapshot = (id, time, extra = {}) => ({ id: id.repeat(64), time, ...extra });

test('wall clock expires all groups at seven days, even when captures stop', () => {
    const inventory = [
        snapshot('a', '2026-09-28T12:00:00.001Z', { hostname: 'renamed' }),
        snapshot('b', '2026-09-28T12:00:00Z'),
        snapshot('c', '2026-09-27T12:00:00Z', { tags: ['manual-copy'] }),
    ];
    assert.deepEqual(classifySnapshots(inventory, now), { eligible: ['a'.repeat(64)], expired: ['b'.repeat(64), 'c'.repeat(64)] });
    assert.throws(() => requireEligible(inventory, 'b'.repeat(64), now));
    assert.throws(() => requireEligible(inventory, 'd'.repeat(64), now));
    assert.equal(classifySnapshots(inventory, new Date('2026-10-06T12:00:00Z')).eligible.length, 0);
});

test('future, non-UTC, malformed and duplicate snapshots fail closed', () => {
    for (const inventory of [
        [snapshot('a', '2026-10-05T12:00:01Z')],
        [snapshot('a', '2026-10-05T12:00:00')],
        [snapshot('a', 'invalid')],
        [snapshot('z', now.toISOString())],
        [snapshot('a', now.toISOString()), snapshot('a', now.toISOString())],
        {},
    ]) assert.throws(() => classifySnapshots(inventory, now));
});
