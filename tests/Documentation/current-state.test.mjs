import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import test from 'node:test';

const repositoryRoot = path.resolve(import.meta.dirname, '..', '..');
const currentStatePath = path.join(repositoryRoot, 'docs/CURRENT_STATE.md');
const implementationHistoryPath = path.join(
    repositoryRoot,
    'docs/IMPLEMENTATION_HISTORY.md',
);

test('current state remains a concise present-tense routing index', async () => {
    const content = await readFile(currentStatePath, 'utf8');
    const lines = content.split(/\r?\n/u);

    assert.ok(
        lines.length <= 220,
        `CURRENT_STATE.md has ${lines.length} lines; move milestone detail to IMPLEMENTATION_HISTORY.md`,
    );
    assert.match(
        content,
        /\*\*Application baseline:\*\* `[0-9a-f]{40}`/u,
    );
    assert.match(content, /^## Capability index$/mu);
    assert.match(content, /^## Canonical architecture boundaries$/mu);
    assert.match(content, /^## Current gaps and constraints$/mu);
    assert.match(content, /^## Verification map$/mu);
    assert.match(content, /^## Maintenance rule$/mu);
    assert.match(content, /\]\(IMPLEMENTATION_HISTORY\.md\)/u);
    assert.doesNotMatch(content, /^## Implemented /mu);
    assert.doesNotMatch(content, /^## Confirmed owner direction$/mu);
    assert.doesNotMatch(content, /^## Questions requiring owner input$/mu);
    assert.doesNotMatch(content, /NUT-04\/NUT-05 structures are not introduced/u);
    assert.doesNotMatch(content, /only food-related records currently represented/iu);
});

test('detailed implementation history remains preserved and lookup-only', async () => {
    const content = await readFile(implementationHistoryPath, 'utf8');

    assert.match(content, /^# Detailed implementation history$/mu);
    assert.match(content, /lookup-only historical implementation evidence/u);
    assert.match(content, /earlier phase-boundary statement may be\s+superseded/u);
    assert.match(content, /\]\(CURRENT_STATE\.md\)/u);
    assert.ok(
        content.split(/\r?\n/u).length > 1_700,
        'Detailed implementation history appears to have lost retained content',
    );
});
