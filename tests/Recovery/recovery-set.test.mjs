import assert from 'node:assert/strict';
import test from 'node:test';
import { mkdtempSync, mkdirSync, writeFileSync, rmSync, symlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { sealSet, verifySet } from '../../scripts/recovery/recovery-set.mjs';

const cutoff = '2026-10-05T12:00:00.000Z';
function fixture() {
    const dir = mkdtempSync(join(tmpdir(), 'recovery-test-'));
    mkdirSync(join(dir, 'objects'));
    for (const file of ['database.sql', 'database.json', 'objects/inventory.json']) writeFileSync(join(dir, file), 'synthetic');
    return dir;
}
test('paired set rejects corruption, extra files, changed installation, age and copied timestamps', () => {
    const dir = fixture();
    try {
        sealSet(dir, 'synthetic', 'release-1', cutoff);
        verifySet(dir, 'synthetic', cutoff, new Date(cutoff));
        assert.throws(() => verifySet(dir, 'other', cutoff, new Date(cutoff)));
        assert.throws(() => verifySet(dir, 'synthetic', cutoff, new Date('2026-10-12T12:00:00Z')));
        assert.throws(() => verifySet(dir, 'synthetic', '2026-10-06T12:00:00Z', new Date('2026-10-06T12:00:00Z')));
        assert.throws(() => verifySet(dir, 'synthetic', cutoff, new Date('2026-10-04T12:00:00Z')));
        writeFileSync(join(dir, 'unexpected'), 'extra');
        assert.throws(() => verifySet(dir, 'synthetic', cutoff, new Date(cutoff)));
        rmSync(join(dir, 'unexpected'));
        writeFileSync(join(dir, 'database.sql'), 'changed');
        assert.throws(() => verifySet(dir, 'synthetic', cutoff, new Date(cutoff)));
    } finally { rmSync(dir, { recursive: true, force: true }); }
});
test('incomplete pairs, symlinks and resealing are rejected', () => {
    const dir = fixture();
    try {
        symlinkSync('/etc/passwd', join(dir, 'link'));
        assert.throws(() => sealSet(dir, 'synthetic', 'release-1', cutoff));
        rmSync(join(dir, 'link'));
        rmSync(join(dir, 'database.sql'));
        assert.throws(() => sealSet(dir, 'synthetic', 'release-1', cutoff));
        writeFileSync(join(dir, 'database.sql'), 'synthetic');
        sealSet(dir, 'synthetic', 'release-1', cutoff);
        assert.throws(() => sealSet(dir, 'synthetic', 'release-1', cutoff));
    } finally { rmSync(dir, { recursive: true, force: true }); }
});
